<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Utils\AuditLogger;
use App\Utils\Request;
use App\Utils\Response;
use PDO;

final class EventController
{
    public function __construct(private PDO $db) {}

    private const STATUSES = ['draft', 'published', 'live', 'paused', 'archived'];

    /** GET /events  — list events visible to the caller */
    public function index(): void
    {
        $claims = AuthMiddleware::authenticate();
        $status = Request::input('status', '');
        $includeDeleted = (bool) Request::input('include_deleted', false);

        $where = [];
        $params = [];

        if (!$includeDeleted) {
            $where[] = 'deleted_at IS NULL';
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = :st';
            $params[':st'] = $status;
        }
        // Role-based event visibility:
        //   • super_admin     → all events
        //   • event_manager   → only events they own
        //   • staff/scanner   → all *active* (non-archived) events, so they
        //                       can pick which one to check guests in at
        $role = $claims['role'] ?? '';
        if ($role === 'event_manager') {
            $where[] = 'owner_id = :o';
            $params[':o'] = $claims['sub'];
        } elseif ($role === 'staff' || $role === 'scanner') {
            $where[] = "status IN ('published','live','paused')";
        }

        $sql = 'SELECT * FROM events';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Response::ok(['events' => $stmt->fetchAll()]);
    }

    /** GET /events/stats — dashboard overview counters */
    public function stats(): void
    {
        $claims = AuthMiddleware::authenticate();
        $isAdmin = ($claims['role'] ?? '') === 'super_admin';
        $ownerClause = $isAdmin ? '' : ' AND owner_id = :o';
        $ownerParams = $isAdmin ? [] : [':o' => $claims['sub']];

        $count = function (string $sql) use ($ownerClause, $ownerParams): int {
            // sql already has WHERE; we just append owner filter
            $stmt = $this->db->prepare($sql . $ownerClause);
            $stmt->execute($ownerParams);
            return (int) $stmt->fetchColumn();
        };

        $totalEvents     = $count('SELECT COUNT(*) FROM events WHERE deleted_at IS NULL');
        $activeEvents    = $count("SELECT COUNT(*) FROM events WHERE deleted_at IS NULL AND status IN ('published','live')");
        $draftEvents     = $count("SELECT COUNT(*) FROM events WHERE deleted_at IS NULL AND status = 'draft'");
        $archivedEvents  = $count("SELECT COUNT(*) FROM events WHERE deleted_at IS NULL AND status = 'archived'");

        // Registrations + check-ins (joined to events for owner filter).
        $regStmt = $this->db->prepare(
            'SELECT
                 COUNT(*)                                          AS total,
                 SUM(r.checkin_status = "CheckedIn")              AS checked_in,
                 SUM(r.checkin_status = "Pending")                AS pending
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             WHERE e.deleted_at IS NULL' . ($isAdmin ? '' : ' AND e.owner_id = :o')
        );
        $regStmt->execute($ownerParams);
        $reg = $regStmt->fetch() ?: ['total' => 0, 'checked_in' => 0, 'pending' => 0];

        $total = (int) $reg['total'];
        $checked = (int) $reg['checked_in'];

        // Recent registrations (across all the user's events).
        $recentStmt = $this->db->prepare(
            'SELECT r.id, r.attendee_name, r.attendee_email, r.created_at,
                    e.id AS event_id, e.title AS event_title
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             WHERE e.deleted_at IS NULL' . ($isAdmin ? '' : ' AND e.owner_id = :o') .
            ' ORDER BY r.created_at DESC LIMIT 10'
        );
        $recentStmt->execute($ownerParams);

        // Upcoming events (next 5 within 30 days).
        $upStmt = $this->db->prepare(
            "SELECT id, title, slug, starts_at_utc, venue, status
             FROM events
             WHERE deleted_at IS NULL
               AND status IN ('published','live')
               AND starts_at_utc >= NOW()" . ($isAdmin ? '' : ' AND owner_id = :o') .
            ' ORDER BY starts_at_utc ASC LIMIT 5'
        );
        $upStmt->execute($ownerParams);

        Response::ok([
            'totals' => [
                'events'        => $totalEvents,
                'active'        => $activeEvents,
                'draft'         => $draftEvents,
                'archived'      => $archivedEvents,
                'registrations' => $total,
                'checked_in'    => $checked,
                'pending'       => (int) $reg['pending'],
                'attendance_rate' => $total > 0 ? round($checked / $total * 100, 1) : 0,
            ],
            'upcoming_events'      => $upStmt->fetchAll(),
            'recent_registrations' => $recentStmt->fetchAll(),
        ]);
    }

    /** DELETE /events/{id} — soft-delete (sets deleted_at, hides from lists) */
    public function destroy(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $event);

        $this->db->prepare('UPDATE events SET deleted_at = NOW() WHERE id = :id')
                 ->execute([':id' => $id]);

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.delete', 'event', $id);
        Response::ok(['deleted' => true], 'Event deleted');
    }

    /** POST /events/{id}/restore — undo soft-delete */
    public function restore(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);

        // Bypass find() because that filters out deleted rows.
        $stmt = $this->db->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $event = $stmt->fetch();
        if (!$event) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $event);

        $this->db->prepare('UPDATE events SET deleted_at = NULL WHERE id = :id')
                 ->execute([':id' => $id]);

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.restore', 'event', $id);
        Response::ok(['restored' => true], 'Event restored');
    }

    /** GET /events/{id} */
    public function show(int $id): void
    {
        AuthMiddleware::authenticate();
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }
        // attach active form config
        $fc = $this->db->prepare('SELECT field_json FROM form_configs WHERE event_id = :e AND is_active = 1 ORDER BY version DESC LIMIT 1');
        $fc->execute([':e' => $id]);
        $form = $fc->fetch();
        $event['form_config'] = $form ? json_decode($form['field_json'], true) : [];
        Response::ok(['event' => $event]);
    }

    /** GET /events/public/{slug} — no auth, used by the public portal */
    public function publicShow(string $slug): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, slug, description, venue, timezone, starts_at_utc, ends_at_utc,
                    capacity, price, currency, minimum_age, organizer_name, branding_json, status
             FROM events WHERE slug = :s AND status IN ('published','live') LIMIT 1"
        );
        $stmt->execute([':s' => $slug]);
        $event = $stmt->fetch();
        if (!$event) {
            Response::error('Event not available', 404);
        }
        $fc = $this->db->prepare('SELECT field_json FROM form_configs WHERE event_id = :e AND is_active = 1 ORDER BY version DESC LIMIT 1');
        $fc->execute([':e' => $event['id']]);
        $form = $fc->fetch();
        $event['form_config']  = $form ? json_decode($form['field_json'], true) : [];
        $event['branding']     = $event['branding_json'] ? json_decode($event['branding_json'], true) : null;
        $event['seats_left']   = $this->seatsLeft((int) $event['id'], (int) $event['capacity']);
        unset($event['branding_json']);
        Response::ok(['event' => $event]);
    }

    /** POST /events */
    public function store(): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $b = Request::clean(Request::body());

        $title = $b['title'] ?? '';
        if (trim($title) === '') {
            Response::error('Title is required', 422);
        }
        $slug = $this->uniqueSlug($b['slug'] ?? $title);

        $stmt = $this->db->prepare(
            'INSERT INTO events
                (owner_id, title, slug, description, venue, venue_lat, venue_lng,
                 timezone, starts_at_utc, ends_at_utc, capacity, price, currency,
                 minimum_age, organizer_name, email_subject, waitlist_enabled, branding_json, status)
             VALUES
                (:owner, :title, :slug, :desc, :venue, :lat, :lng, :tz, :start, :end,
                 :cap, :price, :cur, :age, :org, :esub, :wait, :brand, :status)'
        );
        $stmt->execute([
            ':owner'  => $claims['sub'],
            ':title'  => $title,
            ':slug'   => $slug,
            ':desc'   => $b['description'] ?? null,
            ':venue'  => $b['venue'] ?? null,
            ':lat'    => $b['venue_lat'] ?? null,
            ':lng'    => $b['venue_lng'] ?? null,
            ':tz'     => $b['timezone'] ?? 'UTC',
            ':start'  => $b['starts_at_utc'] ?? null,
            ':end'    => $b['ends_at_utc'] ?? null,
            ':cap'    => (int) ($b['capacity'] ?? 0),
            ':price'  => isset($b['price']) && $b['price'] !== '' ? (float) $b['price'] : null,
            ':cur'    => $b['currency'] ?? 'ALL',
            ':age'    => (int) ($b['minimum_age'] ?? 0),
            ':org'    => $b['organizer_name'] ?? null,
            ':esub'   => Request::clean($b['email_subject'] ?? '') ?: null,
            ':wait'   => !empty($b['waitlist_enabled']) ? 1 : 0,
            ':brand'  => isset($b['branding']) ? json_encode($b['branding']) : null,
            ':status' => in_array($b['status'] ?? 'draft', self::STATUSES, true) ? $b['status'] : 'draft',
        ]);

        $id = (int) $this->db->lastInsertId();

        // Save initial form config if provided.
        if (!empty($b['form_config']) && is_array($b['form_config'])) {
            $this->saveFormConfig($id, $b['form_config']);
        }

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.create', 'event', $id);
        Response::created(['id' => $id, 'slug' => $slug], 'Event created');
    }

    /** PUT /events/{id} */
    public function update(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $event);

        $b = Request::clean(Request::body());
        $fields = [
            'title', 'description', 'venue', 'venue_lat', 'venue_lng',
            'timezone', 'starts_at_utc', 'ends_at_utc', 'capacity', 'waitlist_enabled',
            'price', 'currency', 'minimum_age', 'organizer_name', 'email_subject',
        ];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $b[$f];
            }
        }
        if (isset($b['branding'])) {
            $sets[] = 'branding_json = :brand';
            $params[':brand'] = json_encode($b['branding']);
        }
        if ($sets) {
            $sql = 'UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->db->prepare($sql)->execute($params);
        }

        if (!empty($b['form_config']) && is_array($b['form_config'])) {
            $this->saveFormConfig($id, $b['form_config']);
        }

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.update', 'event', $id);
        Response::ok([], 'Event updated');
    }

    /** PATCH /events/{id}/status  — drive the lifecycle state machine */
    public function setStatus(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $event);

        $status = Request::input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            Response::error('Invalid status', 422);
        }
        $this->db->prepare('UPDATE events SET status = :s WHERE id = :id')
                 ->execute([':s' => $status, ':id' => $id]);

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.status', 'event', $id, "status=$status");
        Response::ok(['status' => $status], 'Status updated');
    }

    /** POST /events/{id}/clone — deep copy template (layout forms + workflows) */
    public function clone(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $src = $this->find($id);
        if (!$src) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $src);

        $newSlug = $this->uniqueSlug($src['slug'] . '-copy');
        $stmt = $this->db->prepare(
            'INSERT INTO events
                (owner_id, title, slug, description, venue, venue_lat, venue_lng, timezone,
                 capacity, waitlist_enabled, branding_json, status)
             VALUES
                (:owner, :title, :slug, :desc, :venue, :lat, :lng, :tz,
                 :cap, :wait, :brand, "draft")'
        );
        $stmt->execute([
            ':owner' => $claims['sub'],
            ':title' => $src['title'] . ' (Copy)',
            ':slug'  => $newSlug,
            ':desc'  => $src['description'],
            ':venue' => $src['venue'],
            ':lat'   => $src['venue_lat'],
            ':lng'   => $src['venue_lng'],
            ':tz'    => $src['timezone'],
            ':cap'   => $src['capacity'],
            ':wait'  => $src['waitlist_enabled'],
            ':brand' => $src['branding_json'],
        ]);
        $newId = (int) $this->db->lastInsertId();

        // Deep-copy the active form config.
        $fc = $this->db->prepare('SELECT field_json FROM form_configs WHERE event_id = :e AND is_active = 1 ORDER BY version DESC LIMIT 1');
        $fc->execute([':e' => $id]);
        if ($form = $fc->fetch()) {
            $this->saveFormConfig($newId, json_decode($form['field_json'], true));
        }

        AuditLogger::log($this->db, (int) $claims['sub'], 'event.clone', 'event', $newId, "from=$id");
        Response::created(['id' => $newId, 'slug' => $newSlug], 'Event cloned');
    }

    /** GET /events/{id}/analytics — live telemetry summary */
    public function analytics(int $id): void
    {
        AuthMiddleware::authenticate();
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }

        $reg = $this->db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :e');
        $reg->execute([':e' => $id]);
        $totalReg = (int) $reg->fetchColumn();

        $chk = $this->db->prepare("SELECT COUNT(*) c FROM registrations WHERE event_id = :e AND checkin_status = 'CheckedIn'");
        $chk->execute([':e' => $id]);
        $checkedIn = (int) $chk->fetchColumn();

        // Hourly entry velocity from attendance_log.
        $vel = $this->db->prepare(
            "SELECT DATE_FORMAT(a.scanned_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS count
             FROM attendance_log a
             JOIN registrations r ON r.id = a.registration_id
             WHERE r.event_id = :e AND a.scan_result = 'success'
             GROUP BY hour ORDER BY hour"
        );
        $vel->execute([':e' => $id]);

        // Recent registrations (latest 20) for the inline drawer panel.
        $recent = $this->db->prepare(
            "SELECT id, attendee_name, attendee_email, access_level, status, checkin_status,
                    source, created_at
             FROM registrations
             WHERE event_id = :e
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $recent->execute([':e' => $id]);

        $capacity = (int) $event['capacity'];
        Response::ok([
            'total_registrations' => $totalReg,
            'checked_in'          => $checkedIn,
            'capacity'            => $capacity,
            'capacity_rate'       => $capacity > 0 ? round($totalReg / $capacity * 100, 1) : null,
            'attendance_rate'     => $totalReg > 0 ? round($checkedIn / $totalReg * 100, 1) : 0,
            'hourly_velocity'     => $vel->fetchAll(),
            'recent_registrations' => $recent->fetchAll(),
        ]);
    }

    /** PUT /events/{id}/form — save/replace dynamic form config */
    public function saveForm(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $event = $this->find($id);
        if (!$event) {
            Response::error('Event not found', 404);
        }
        $this->assertOwner($claims, $event);

        $config = Request::input('form_config', []);
        if (!is_array($config)) {
            Response::error('form_config must be an array of fields', 422);
        }
        $this->saveFormConfig($id, $config);
        AuditLogger::log($this->db, (int) $claims['sub'], 'event.form.save', 'event', $id);
        Response::ok([], 'Form configuration saved');
    }

    // ---------------- helpers ----------------

    private function saveFormConfig(int $eventId, array $config): void
    {
        $this->db->prepare('UPDATE form_configs SET is_active = 0 WHERE event_id = :e')
                 ->execute([':e' => $eventId]);
        $ver = $this->db->prepare('SELECT COALESCE(MAX(version),0)+1 FROM form_configs WHERE event_id = :e');
        $ver->execute([':e' => $eventId]);
        $next = (int) $ver->fetchColumn();
        $this->db->prepare(
            'INSERT INTO form_configs (event_id, version, field_json, is_active) VALUES (:e, :v, :j, 1)'
        )->execute([':e' => $eventId, ':v' => $next, ':j' => json_encode($config)]);
    }

    private function seatsLeft(int $eventId, int $capacity): ?int
    {
        if ($capacity <= 0) {
            return null; // unlimited
        }
        $s = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = :e AND status IN ('pending','confirmed')");
        $s->execute([':e' => $eventId]);
        return max(0, $capacity - (int) $s->fetchColumn());
    }

    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM events WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function assertOwner(array $claims, array $event): void
    {
        if (($claims['role'] ?? '') === 'super_admin') {
            return;
        }
        if ((int) $event['owner_id'] !== (int) $claims['sub']) {
            Response::error('You do not own this event', 403);
        }
    }

    private function uniqueSlug(string $base): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base)));
        $slug = trim($slug, '-') ?: 'event';
        $candidate = $slug;
        $i = 1;
        while (true) {
            $s = $this->db->prepare('SELECT 1 FROM events WHERE slug = :s LIMIT 1');
            $s->execute([':s' => $candidate]);
            if (!$s->fetch()) {
                return $candidate;
            }
            $candidate = $slug . '-' . (++$i);
        }
    }
}
