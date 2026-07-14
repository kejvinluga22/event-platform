<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Utils\AuditLogger;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Token;
use PDO;

final class ScannerController
{
    public function __construct(private PDO $db) {}

    /**
     * POST /scan
     * Body: { token: "<signed-jwt-from-qr>", gate?: "Main" }
     * Verifies signature, then performs an idempotent check-in. Concurrent
     * double-scans are rejected via a conditional UPDATE (row-count guard).
     */
    public function scan(): void
    {
        $claims  = AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff', 'scanner']);
        $token   = (string) Request::input('token', '');
        $gate    = Request::clean(Request::input('gate', 'Main'));
        $scanner = (int) $claims['sub'];

        if ($token === '') {
            Response::error('Missing QR token', 422);
        }

        // 1. Cryptographic signature verification (anti-counterfeit).
        $payload = Token::verifyQr($token);
        if (!$payload || empty($payload['rid'])) {
            $this->logScan(null, $scanner, 'invalid', $gate);
            Response::error('Invalid or forged QR code', 400, ['scan_result' => 'invalid']);
        }

        $regId   = (int) $payload['rid'];
        $eventId = (int) ($payload['eid'] ?? 0);

        // 2. Load registration.
        $stmt = $this->db->prepare(
            'SELECT r.*, e.title AS event_title
             FROM registrations r JOIN events e ON e.id = r.event_id
             WHERE r.id = :rid LIMIT 1'
        );
        $stmt->execute([':rid' => $regId]);
        $reg = $stmt->fetch();

        if (!$reg || ($eventId && (int) $reg['event_id'] !== $eventId)) {
            $this->logScan($regId, $scanner, 'invalid', $gate);
            Response::error('Registration not found for this event', 404, ['scan_result' => 'invalid']);
        }

        if ($reg['status'] === 'cancelled') {
            $this->logScan($regId, $scanner, 'invalid', $gate);
            Response::error('Registration cancelled', 409, ['scan_result' => 'invalid']);
        }

        // 3. Idempotent check-in: only flips Pending -> CheckedIn.
        $upd = $this->db->prepare(
            "UPDATE registrations
                SET checkin_status = 'CheckedIn'
              WHERE id = :rid AND checkin_status = 'Pending'"
        );
        $upd->execute([':rid' => $regId]);

        if ($upd->rowCount() === 0) {
            // Already checked in -> duplicate scan.
            $this->logScan($regId, $scanner, 'duplicate', $gate);
            Response::error('Already checked in (duplicate scan)', 409, [
                'scan_result' => 'duplicate',
                'attendee'    => [
                    'name'         => $reg['attendee_name'],
                    'access_level' => $reg['access_level'],
                    'event'        => $reg['event_title'],
                ],
            ]);
        }

        $this->logScan($regId, $scanner, 'success', $gate);
        AuditLogger::log($this->db, $scanner, 'scan.checkin', 'registration', $regId, "gate=$gate");

        Response::ok([
            'scan_result' => 'success',
            'attendee'    => [
                'name'         => $reg['attendee_name'],
                'email'        => $reg['attendee_email'],
                'access_level' => $reg['access_level'],
                'event'        => $reg['event_title'],
                'event_id'     => (int) $reg['event_id'],
            ],
        ], 'Check-in confirmed');
    }

    /**
     * POST /scan/sync
     * Offline batch reconciliation: PWA pushes queued local check-ins once it
     * regains connectivity. Each entry is processed idempotently.
     * Body: { scans: [ { token, scanned_at, gate } ] }
     */
    public function syncBatch(): void
    {
        $claims  = AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff', 'scanner']);
        $scanner = (int) $claims['sub'];
        $scans   = Request::input('scans', []);

        if (!is_array($scans)) {
            Response::error('scans must be an array', 422);
        }

        $results = [];
        foreach ($scans as $entry) {
            $payload = Token::verifyQr((string) ($entry['token'] ?? ''));
            if (!$payload || empty($payload['rid'])) {
                $results[] = ['rid' => null, 'result' => 'invalid'];
                continue;
            }
            $regId = (int) $payload['rid'];
            $upd = $this->db->prepare(
                "UPDATE registrations SET checkin_status = 'CheckedIn'
                 WHERE id = :rid AND checkin_status = 'Pending'"
            );
            $upd->execute([':rid' => $regId]);
            $result = $upd->rowCount() > 0 ? 'success' : 'duplicate';
            $this->logScan($regId, $scanner, $result, Request::clean($entry['gate'] ?? 'Offline'), $entry['scanned_at'] ?? null);
            $results[] = ['rid' => $regId, 'result' => $result];
        }

        AuditLogger::log($this->db, $scanner, 'scan.sync', null, null, count($scans) . ' offline scans');
        Response::ok(['processed' => $results], 'Offline batch synced');
    }

    /** GET /scan/lookup?q=email-or-name — quick attendee lookup panel for staff. */
    public function lookup(): void
    {
        AuthMiddleware::authenticate(['super_admin', 'event_manager', 'staff', 'scanner']);
        $q = Request::clean(Request::input('q', ''));
        $eventId = (int) Request::input('event_id', 0);
        if (strlen($q) < 2) {
            Response::error('Query too short', 422);
        }
        $sql = 'SELECT id, attendee_name, attendee_email, access_level, checkin_status, status
                FROM registrations
                WHERE (attendee_name LIKE :q OR attendee_email LIKE :q)';
        $params = [':q' => '%' . $q . '%'];
        if ($eventId) {
            $sql .= ' AND event_id = :e';
            $params[':e'] = $eventId;
        }
        $sql .= ' ORDER BY attendee_name LIMIT 25';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        Response::ok(['results' => $stmt->fetchAll()]);
    }

    private function logScan(?int $regId, int $scanner, string $result, ?string $gate, ?string $at = null): void
    {
        if ($regId === null) {
            return; // nothing to reference (FK requires a registration)
        }
        $stmt = $this->db->prepare(
            'INSERT INTO attendance_log (registration_id, scanned_by, scan_result, gate, scanned_at)
             VALUES (:rid, :by, :res, :gate, COALESCE(:at, NOW()))'
        );
        $stmt->execute([
            ':rid'  => $regId,
            ':by'   => $scanner,
            ':res'  => $result,
            ':gate' => $gate,
            ':at'   => $at,
        ]);
    }
}
