<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimiter;
use App\Utils\AuditLogger;
use App\Utils\MailService;
use App\Utils\PdfService;
use App\Utils\QrService;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Token;
use App\Utils\XlsxWriter;
use PDO;

final class RegistrationController
{
    public function __construct(private PDO $db) {}

    /**
     * POST /register/{slug}  (public, no auth)
     * Validates against the event's dynamic form config, enforces capacity /
     * waitlist, mints a signed QR token, and queues the confirmation email.
     */
    public function register(string $slug): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->enforce('public_register');

        $event = $this->eventBySlug($slug);
        if (!$event || !in_array($event['status'], ['published', 'live'], true)) {
            Response::error('Event not open for registration', 404);
        }

        $name  = Request::clean(Request::input('attendee_name', ''));
        $email = filter_var(Request::input('attendee_email', ''), FILTER_VALIDATE_EMAIL);
        $phone = Request::clean(Request::input('phone', ''));
        $form  = Request::clean(Request::input('form_data', []));
        $access = Request::clean(Request::input('access_level', 'general'));

        // Accept first/last name explicitly, or split from a combined "name".
        $firstName = Request::clean(Request::input('first_name', ''));
        $lastName  = Request::clean(Request::input('last_name', ''));
        if ($firstName === '' && $lastName === '' && $name !== '') {
            $parts = preg_split('/\s+/', $name, 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }
        if ($name === '' && ($firstName !== '' || $lastName !== '')) {
            $name = trim($firstName . ' ' . $lastName);
        }

        if (trim($name) === '' || !$email) {
            Response::error('Name and a valid email are required', 422);
        }

        // Validate dynamic fields server-side against the active schema.
        $schema = $this->activeFormSchema((int) $event['id']);
        $errors = $this->validateAgainstSchema($schema, is_array($form) ? $form : []);
        if ($errors) {
            Response::error('Validation failed', 422, ['fields' => $errors]);
        }

        // Capacity check / waitlist fallback.
        $status = 'confirmed';
        if ((int) $event['capacity'] > 0) {
            $count = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = :e AND status IN ('pending','confirmed')");
            $count->execute([':e' => $event['id']]);
            if ((int) $count->fetchColumn() >= (int) $event['capacity']) {
                if (!$event['waitlist_enabled']) {
                    Response::error('Event is at full capacity', 409);
                }
                $status = 'waitlisted';
            }
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO registrations
                    (event_id, attendee_name, first_name, last_name, attendee_email, phone,
                     access_level, form_data, status, source)
                 VALUES (:e, :n, :fn, :ln, :em, :ph, :acl, :form, :status, "public")'
            );
            $stmt->execute([
                ':e'      => $event['id'],
                ':n'      => $name,
                ':fn'     => $firstName ?: null,
                ':ln'     => $lastName ?: null,
                ':em'     => $email,
                ':ph'     => $phone ?: null,
                ':acl'    => $access,
                ':form'   => json_encode($form),
                ':status' => $status,
            ]);
            $regId = (int) $this->db->lastInsertId();

            // Sign QR only for confirmed attendees.
            $qrToken = null;
            if ($status === 'confirmed') {
                $qrToken = Token::signQr($regId, (int) $event['id'], $access);
                $this->db->prepare('UPDATE registrations SET qr_token = :t WHERE id = :id')
                         ->execute([':t' => $qrToken, ':id' => $regId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Send the confirmation email after the transaction is committed, not
        // inside it — an SMTP attempt (even with a short timeout) shouldn't
        // hold the registrations row lock, and a mail failure shouldn't roll
        // back an otherwise-successful registration.
        if ($status === 'confirmed') {
            $this->queueConfirmation($event, $regId, $name, $email, $qrToken, $access);
        }

        $limiter->reset('public_register');
        Response::created([
            'registration_id' => $regId,
            'status'          => $status,
            'ticket_url'      => $status === 'confirmed' ? "/api/ticket/$regId" : null,
            'pdf_url'         => $status === 'confirmed' ? "/api/registrations/$regId/ticket.pdf" : null,
        ], $status === 'waitlisted' ? 'Added to waitlist' : 'Registration confirmed');
    }

    /** POST /events/{id}/walkin  (staff) — on-site manual entry */
    public function walkIn(int $eventId): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff']);
        $name   = Request::clean(Request::input('attendee_name', ''));
        $email  = filter_var(Request::input('attendee_email', ''), FILTER_VALIDATE_EMAIL) ?: 'walkin@local';
        $access = Request::clean(Request::input('access_level', 'general'));

        if (trim($name) === '') {
            Response::error('Attendee name is required', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO registrations
                (event_id, attendee_name, attendee_email, access_level, status, checkin_status, source)
             VALUES (:e, :n, :em, :acl, "confirmed", "CheckedIn", "walkin")'
        );
        $stmt->execute([':e' => $eventId, ':n' => $name, ':em' => $email, ':acl' => $access]);
        $regId = (int) $this->db->lastInsertId();

        $qrToken = Token::signQr($regId, $eventId, $access);
        $this->db->prepare('UPDATE registrations SET qr_token = :t WHERE id = :id')
                 ->execute([':t' => $qrToken, ':id' => $regId]);

        AuditLogger::log($this->db, (int) $claims['sub'], 'registration.walkin', 'registration', $regId);
        Response::created(['registration_id' => $regId, 'badge_url' => "/registrations/$regId/badge"], 'Walk-in registered');
    }

    /** POST /registrations/{id}/resend — re-queue the e-ticket email */
    public function resendTicket(int $regId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $reg = $this->regWithEvent($regId);
        if (!$reg) {
            Response::error('Registration not found', 404);
        }
        if ($reg['status'] !== 'confirmed') {
            Response::error('Only confirmed registrations can be re-sent', 422);
        }
        $qrToken = $reg['qr_token'] ?? Token::signQr($regId, (int) $reg['event_id'], $reg['access_level']);
        $event = [
            'title'         => $reg['event_title'],
            'venue'         => $reg['venue'] ?? '',
            'starts_at_utc' => $reg['starts_at_utc'] ?? null,
            'ends_at_utc'   => $reg['ends_at_utc']   ?? null,
            'price'         => $reg['price']         ?? null,
            'currency'      => $reg['currency']      ?? 'ALL',
            'minimum_age'   => $reg['minimum_age']   ?? 0,
            'email_subject' => $reg['email_subject'] ?? null,
        ];
        $this->queueConfirmation($event, $regId, $reg['attendee_name'], $reg['attendee_email'], $qrToken, $reg['access_level']);
        Response::ok(['queued' => true], 'Ticket queued for resend');
    }

    /** GET /registrations/{id}/ticket.pdf — streams the full e-ticket PDF */
    public function ticketPdf(int $regId): void
    {
        $reg = $this->regWithEvent($regId);
        if (!$reg || $reg['status'] === 'cancelled') {
            Response::error('Ticket not found', 404);
        }
        $qrToken = $reg['qr_token'] ?? Token::signQr($regId, (int) $reg['event_id'], $reg['access_level']);
        $event = [
            'title'         => $reg['event_title'],
            'venue'         => $reg['venue'] ?? '',
            'starts_at_utc' => $reg['starts_at_utc'] ?? null,
            'ends_at_utc'   => $reg['ends_at_utc'] ?? null,
            'price'         => $reg['price'] ?? null,
            'currency'      => $reg['currency'] ?? 'ALL',
            'minimum_age'   => $reg['minimum_age'] ?? 0,
            'email_subject' => $reg['email_subject'] ?? null,
        ];

        $pdfPath = PdfService::renderTicket(
            $this->ticketVars($event, $regId, $reg['attendee_name'], $reg['access_level'], $qrToken),
            $qrToken
        );

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $this->ticketFilename($reg['event_title'], $regId) . '"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }

    /** GET /registrations/{id}/badge?format=cr80 — streams a PDF badge */
    public function badge(int $regId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff']);
        $reg = $this->regWithEvent($regId);
        if (!$reg) {
            Response::error('Registration not found', 404);
        }
        $format = Request::input('format', 'cr80');
        $parts  = preg_split('/\s+/', trim($reg['attendee_name']), 2);

        $pdfPath = PdfService::renderBadge([
            'First_Name'  => $parts[0] ?? '',
            'Last_Name'   => $parts[1] ?? '',
            'Company'     => $this->formValue($reg, 'company'),
            'Ticket_Type' => $reg['access_level'],
            'Event_Title' => $reg['event_title'],
            'REG_ID'      => $regId,
        ], $reg['qr_token'] ?? Token::signQr($regId, (int) $reg['event_id'], $reg['access_level']), (string) $format);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="badge_' . $regId . '.pdf"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }

    /** GET /ticket/{id} — public responsive web ticket fallback page */
    public function ticket(int $regId): void
    {
        $reg = $this->regWithEvent($regId);
        if (!$reg || $reg['status'] === 'cancelled') {
            http_response_code(404);
            echo '<h1>Ticket not found</h1>';
            exit;
        }
        $qr = QrService::dataUri($reg['qr_token'] ?? Token::signQr($regId, (int) $reg['event_id'], $reg['access_level']));
        header('Content-Type: text/html; charset=utf-8');
        echo $this->ticketHtml($reg, $qr);
        exit;
    }

    /** GET /events/{id}/export — filtered registrations as CSV */
    public function exportCsv(int $eventId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        [$headers, $rows] = $this->reportData($eventId);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="registrations_event_' . $eventId . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /** GET /events/{id}/export/xlsx — registrations as a native Excel file */
    public function exportXlsx(int $eventId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $title = (string) ($this->eventTitle($eventId) ?? ('Event #' . $eventId));
        [$headers, $rows] = $this->reportData($eventId);
        $sum = $this->reportSummary($eventId);

        // Prepend a summary block + a blank spacer row.
        $summaryRows = [
            ['Event', $title],
            ['Generated', date('Y-m-d H:i')],
            ['Total registrations', $sum['total']],
            ['Checked in',          $sum['checked_in']],
            ['Pending',             $sum['pending']],
            ['Attendance %',        $sum['attendance']],
            ['Capacity',            $sum['capacity'] > 0 ? $sum['capacity'] : 'Unlimited'],
            [], // blank spacer
        ];
        $allRows = array_merge($summaryRows, [$headers], $rows);
        // headers are already inside allRows now; pass empty first arg so writer
        // doesn't add a second header row.
        XlsxWriter::stream('registrations_event_' . $eventId . '.xlsx', [], $allRows);
    }

    /** GET /events/{id}/export/pdf — registrations as a formatted PDF report */
    public function exportPdf(int $eventId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $title = (string) ($this->eventTitle($eventId) ?? ('Event #' . $eventId));
        [$headers, $rows] = $this->reportData($eventId);
        $sum = $this->reportSummary($eventId);

        $bin = PdfService::renderReport($title, $headers, $rows, $sum);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="registrations_event_' . $eventId . '.pdf"');
        echo $bin;
        exit;
    }

    /** GET /registrations/{id}/certificate — streams a certificate of attendance PDF */
    public function certificate(int $regId): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff']);
        $reg = $this->regWithEvent($regId);
        if (!$reg) {
            Response::error('Registration not found', 404);
        }

        $venueClause = !empty($reg['venue']) ? ' held at ' . $reg['venue'] : '';
        $pdfPath = PdfService::renderCertificate([
            'Attendee_Name' => $reg['attendee_name'],
            'Event_Title'   => $reg['event_title'],
            'Venue_Clause'  => $venueClause,
            'Ticket_Type'   => $reg['access_level'],
            'Issue_Date'    => date('F j, Y'),
            'REG_ID'        => $regId,
        ]);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate_' . $regId . '.pdf"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }

    // ---------------- helpers ----------------

    /**
     * Send the confirmation email. Tries SMTP immediately for instant delivery;
     * if that fails (network blip, SMTP timeout, misconfiguration), the email
     * is parked in email_queue and the cron worker retries it later.
     */
    private function queueConfirmation(array $event, int $regId, string $name, string $email, string $qrToken, string $access): void
    {
        $ticket = PdfService::renderTicket(
            $this->ticketVars($event, $regId, $name, $access, $qrToken),
            $qrToken,
            sys_get_temp_dir() . '/tickets',
            $this->ticketFilename($event['title'], $regId)
        );

        $subject = trim((string) ($event['email_subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Your ticket for ' . $event['title'];
        }
        // $name and $event['title'] are already HTML-escaped by Request::clean() at
        // input time, so they're safe to interpolate directly — re-escaping here
        // would double-encode entities (e.g. "O'Brien" -> "O&amp;#039;Brien").
        $body = '<p>Hi ' . $name . ',</p>'
              . '<p>Your registration for <strong>' . $event['title'] . '</strong> is confirmed.</p>'
              . '<p>Your e-ticket is attached as a PDF. Show the QR code to staff at the entrance — '
              . 'no need to print it. You can also view it any time here: '
              // The ticket page is server-rendered by this API (RegistrationController::ticket),
              // not a frontend SPA route, so the link must point at the API's own base URL.
              . '<a href="' . $this->apiBaseUrl() . '/ticket/' . $regId . '">View ticket</a></p>';

        // Attempt immediate delivery.
        $result = MailService::send($email, $subject, $body, $ticket);

        if ($result['sent']) {
            // Log the successful send so the admin still has an audit trail.
            $this->db->prepare(
                'INSERT INTO email_queue
                    (registration_id, to_email, subject, body_html, attachment_path, provider, status, attempts, sent_at)
                 VALUES (:rid, :to, :sub, :body, :att, :prov, "Sent", 1, NOW())'
            )->execute([
                ':rid'  => $regId,
                ':to'   => $email,
                ':sub'  => $subject,
                ':body' => $body,
                ':att'  => $ticket,
                ':prov' => $result['provider'],
            ]);
            return;
        }

        // Delivery failed (SMTP not configured, network blip, auth error, etc).
        // Park the email in the queue with the failure reason — the cron worker
        // will retry it once the user fixes their SMTP settings or the network.
        $this->db->prepare(
            'INSERT INTO email_queue
                (registration_id, to_email, subject, body_html, attachment_path, status, bounce_reason)
             VALUES (:rid, :to, :sub, :body, :att, "Queued", :reason)'
        )->execute([
            ':rid'    => $regId,
            ':to'     => $email,
            ':sub'    => $subject,
            ':body'   => $body,
            ':att'    => $ticket,
            ':reason' => substr($result['error'] ?? 'Initial send failed', 0, 255),
        ]);
    }

    /**
     * Build a friendly PDF filename based on the event title. This name is
     * what guests see when downloading the ticket OR when their email client
     * shows the attachment (PHPMailer derives the attachment name from the
     * file's basename on disk).
     */
    private function ticketFilename(string $eventTitle, int $regId): string
    {
        // Strip everything but letters, digits, spaces — then collapse spaces
        // to hyphens. Multibyte-safe for accented characters via iconv.
        $clean = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $eventTitle);
        $clean = preg_replace('/[^A-Za-z0-9]+/', '-', $clean);
        $clean = trim((string) $clean, '-');
        if ($clean === '') {
            $clean = 'Event';
        }
        // Cap length so we don't generate filesystem-hostile names.
        $clean = substr($clean, 0, 60);
        return $clean . '-Ticket.pdf';
    }

    /** Deterministic GoWild-style order number, e.g. GW0A1928810636. */
    private function orderNumber(int $regId): string
    {
        $suffix = strtoupper(substr(hash('crc32b', 'order' . $regId), 0, 2))
                . str_pad((string) (abs(crc32('se' . $regId)) % 10000000000), 10, '0', STR_PAD_LEFT);
        return 'GW' . $suffix;
    }

    /** Short verification hash printed under the QR (mirrors the sample ticket). */
    private function orderHash(int $regId, string $qrToken): string
    {
        return substr(hash('sha256', $regId . '|' . $qrToken), 0, 32);
    }

    /** Assembles the placeholder map for the e-ticket template. */
    private function ticketVars(array $event, int $regId, string $name, string $access, string $qrToken): array
    {
        $venue = $event['venue'] ?? '';
        $start = !empty($event['starts_at_utc']) ? date('d M @ H:i', strtotime($event['starts_at_utc'])) : 'TBA';
        $end   = !empty($event['ends_at_utc'])   ? date('d M @ H:i', strtotime($event['ends_at_utc']))   : '';
        $timing = $end ? ('From: ' . $start . "\nTo: " . $end) : ('From: ' . $start);

        // Build price label from event price + currency.
        $priceVal = $event['price'] ?? null;
        $currency = $event['currency'] ?? 'ALL';
        if ($priceVal === null || (float) $priceVal <= 0) {
            $priceLabel = 'FREE';
        } else {
            $priceLabel = rtrim(rtrim(number_format((float) $priceVal, 2, '.', ''), '0'), '.') . ' ' . $currency;
        }

        // Age notice.
        $minAge = (int) ($event['minimum_age'] ?? 0);
        $ageNotice = $minAge > 0
            ? "To enter the event, you must be at least $minAge years old."
            : 'No minimum age requirement.';

        return [
            'Event_Title'   => strtoupper($event['title']),
            'Venue'         => $venue !== '' ? $venue : 'TBA',
            'Timing'        => $timing,
            'Attendee_Name' => $name,
            'Ticket_Type'   => $access,
            'Price'         => $priceLabel,
            'Age_Notice'    => $ageNotice,
            'Status_Label'  => strtoupper($access),
            'Order_Time'    => date('d.m.Y H:i'),
            'Order_Number'  => $this->orderNumber($regId),
            'Order_Hash'    => $this->orderHash($regId, $qrToken),
            'REG_ID'        => $regId,
        ];
    }

    private function validateAgainstSchema(array $schema, array $data): array
    {
        $errors = [];
        foreach ($schema as $field) {
            $key = $field['name'] ?? null;
            if (!$key) {
                continue;
            }
            $val = $data[$key] ?? null;

            // Conditional visibility: skip validation if the controlling rule is unmet.
            if (!empty($field['visible_if'])) {
                $cond = $field['visible_if'];
                if (($data[$cond['field']] ?? null) !== $cond['equals']) {
                    continue;
                }
            }
            if (!empty($field['required']) && ($val === null || $val === '')) {
                $errors[$key] = 'This field is required';
                continue;
            }
            if ($val !== null && $val !== '' && !empty($field['maxLength']) && mb_strlen((string) $val) > $field['maxLength']) {
                $errors[$key] = 'Exceeds maximum length';
            }
            if ($val !== null && $val !== '' && !empty($field['regex'])) {
                $matched = @preg_match('/' . $field['regex'] . '/', (string) $val);
                // $matched === false means the configured regex itself is malformed -> ignore it.
                if ($matched === 0) {
                    $errors[$key] = 'Invalid format';
                }
            }
        }
        return $errors;
    }

    private function activeFormSchema(int $eventId): array
    {
        $fc = $this->db->prepare('SELECT field_json FROM form_configs WHERE event_id = :e AND is_active = 1 ORDER BY version DESC LIMIT 1');
        $fc->execute([':e' => $eventId]);
        $row = $fc->fetch();
        return $row ? (json_decode($row['field_json'], true) ?: []) : [];
    }

    private function eventBySlug(string $slug): ?array
    {
        $s = $this->db->prepare('SELECT * FROM events WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        return $s->fetch() ?: null;
    }

    /** Builds the shared header row + data rows used by CSV / XLSX / PDF export. */
    private function reportData(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.first_name, r.last_name, r.attendee_name, r.attendee_email, r.phone,
                    r.access_level, r.status, r.checkin_status,
                    r.source, r.created_at,
                    (SELECT MIN(scanned_at) FROM attendance_log a
                       WHERE a.registration_id = r.id AND a.scan_result = "success") AS checkin_time
             FROM registrations r
             WHERE r.event_id = :e
             ORDER BY r.created_at'
        );
        $stmt->execute([':e' => $eventId]);
        $headers = [
            'ID', 'First Name', 'Last Name', 'Email', 'Phone',
            'Access Level', 'Status', 'Check-in', 'Check-in Time', 'Source', 'Registered At',
        ];
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $first = $row['first_name'];
            $last  = $row['last_name'];
            // Fall back to splitting the combined name if first/last empty (older rows).
            if (($first === null || $first === '') && !empty($row['attendee_name'])) {
                $parts = preg_split('/\s+/', $row['attendee_name'], 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            }
            $rows[] = [
                $row['id'],
                $first ?? '',
                $last ?? '',
                $row['attendee_email'],
                $row['phone'] ?? '',
                $row['access_level'],
                $row['status'],
                $row['checkin_status'],
                $row['checkin_time'] ?? '',
                $row['source'],
                $row['created_at'],
            ];
        }
        return [$headers, $rows];
    }

    /** Summary stats used in the report headers. */
    private function reportSummary(int $eventId): array
    {
        $s = $this->db->prepare(
            'SELECT
                COUNT(*)                              AS total,
                SUM(checkin_status = "CheckedIn")    AS checked_in,
                SUM(checkin_status = "Pending")      AS pending
             FROM registrations WHERE event_id = :e'
        );
        $s->execute([':e' => $eventId]);
        $r = $s->fetch() ?: [];
        $total = (int) ($r['total'] ?? 0);
        $checked = (int) ($r['checked_in'] ?? 0);

        $capStmt = $this->db->prepare('SELECT capacity FROM events WHERE id = :e');
        $capStmt->execute([':e' => $eventId]);
        $capacity = (int) ($capStmt->fetchColumn() ?: 0);

        return [
            'total'      => $total,
            'checked_in' => $checked,
            'pending'    => (int) ($r['pending'] ?? 0),
            'attendance' => $total > 0 ? round($checked / $total * 100, 1) : 0,
            'capacity'   => $capacity,
        ];
    }

    private function eventTitle(int $eventId): ?string
    {
        $s = $this->db->prepare('SELECT title FROM events WHERE id = :id');
        $s->execute([':id' => $eventId]);
        $t = $s->fetchColumn();
        return $t === false ? null : (string) $t;
    }

    private function regWithEvent(int $regId): ?array
    {
        $s = $this->db->prepare(
            'SELECT r.*, e.title AS event_title, e.venue, e.starts_at_utc, e.ends_at_utc,
                    e.price, e.currency, e.minimum_age, e.email_subject
             FROM registrations r JOIN events e ON e.id = r.event_id
             WHERE r.id = :id LIMIT 1'
        );
        $s->execute([':id' => $regId]);
        return $s->fetch() ?: null;
    }

    private function formValue(array $reg, string $key): string
    {
        $data = $reg['form_data'] ? json_decode($reg['form_data'], true) : [];
        return (string) ($data[$key] ?? '');
    }

    private function apiBaseUrl(): string
    {
        return (require __DIR__ . '/../../config/credentials.php')['app']['base_url'];
    }

    private function ticketHtml(array $reg, string $qrDataUri): string
    {
        // These fields were already HTML-escaped by Request::clean() when the
        // registration was created, so no further escaping is applied here —
        // doing so would double-encode entities (e.g. "O'Brien" -> "O&amp;#039;Brien").
        $name  = $reg['attendee_name'];
        $event = $reg['event_title'];
        $venue = (string) ($reg['venue'] ?? '');
        $tier  = $reg['access_level'];
        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ticket · $event</title>
<style>
  body{margin:0;font-family:system-ui,Segoe UI,sans-serif;background:#0f1115;color:#e8eaed;
       display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
  .card{background:#fff;color:#111;border-radius:18px;max-width:360px;width:100%;
        overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .top{background:#EC2024;color:#fff;padding:20px 24px}
  .top h1{margin:0;font-size:18px}
  .top p{margin:4px 0 0;font-size:12px;opacity:.85}
  .body{padding:24px;text-align:center}
  .name{font-size:22px;font-weight:700;margin:0 0 4px}
  .tier{display:inline-block;background:#111;color:#fff;border-radius:999px;
        padding:4px 14px;font-size:12px;text-transform:uppercase;letter-spacing:1px}
  img{width:220px;height:220px;margin:18px 0}
  .rid{font-size:12px;color:#888}
</style></head><body>
  <div class="card">
    <div class="top"><h1>$event</h1><p>$venue</p></div>
    <div class="body">
      <p class="name">$name</p>
      <span class="tier">$tier</span>
      <div><img src="$qrDataUri" alt="QR"></div>
      <div class="rid">Registration #{$reg['id']}</div>
    </div>
  </div>
</body></html>
HTML;
    }
}
