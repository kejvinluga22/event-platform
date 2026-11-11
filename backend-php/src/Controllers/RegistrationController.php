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
        $form   = is_array($form) ? $form : [];
        $errors = $this->validateAgainstSchema($schema, $form);

        // Phone: use the built-in field, or pick it up from a custom
        // "phone"/"tel" field so it lands in the phone column (and exports).
        if ($phone === '') {
            $phone = $this->phoneFromForm($schema, $form);
        }

        // Ticket type question (only in ticket mode, only when switched on).
        $issueTickets = (int) ($event['issue_tickets'] ?? 1) === 1;
        if ($issueTickets && (int) ($event['ask_ticket_type'] ?? 0) === 1) {
            $choices = EventController::ticketTypeList($event['ticket_type_options'] ?? null);
            $match = null;
            foreach ($choices as $c) {
                $dec = static fn ($v) => mb_strtolower(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($dec($c) === $dec($access)) {
                    $match = $c;
                    break;
                }
            }
            if ($match === null) {
                $errors['access_level'] = 'Please choose an option';
            } else {
                $access = $match;
            }
        } else {
            $access = 'general';
        }

        // Age field (only when the organizer switched it on).
        $age = null;
        if ((int) ($event['ask_age'] ?? 0) === 1) {
            $rawAge = trim((string) Request::input('attendee_age', ''));
            $minAge = (int) ($event['minimum_age'] ?? 0);
            if ($rawAge === '' || !ctype_digit($rawAge) || (int) $rawAge < 1 || (int) $rawAge > 120) {
                $errors['attendee_age'] = 'Please enter a valid age';
            } elseif ($minAge > 0 && (int) $rawAge < $minAge) {
                $errors['attendee_age'] = "You must be at least $minAge years old";
            } else {
                $age = (int) $rawAge;
            }
        }

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
                     attendee_age, access_level, form_data, status, source)
                 VALUES (:e, :n, :fn, :ln, :em, :ph, :age, :acl, :form, :status, "public")'
            );
            $stmt->execute([
                ':e'      => $event['id'],
                ':n'      => $name,
                ':fn'     => $firstName ?: null,
                ':ln'     => $lastName ?: null,
                ':em'     => $email,
                ':ph'     => $phone ?: null,
                ':age'    => $age,
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
            $this->queueConfirmation($event, $regId, $name, $email, $qrToken, $access, $issueTickets);
        }

        $withTicket = $status === 'confirmed' && $issueTickets;
        $limiter->reset('public_register');
        Response::created([
            'registration_id' => $regId,
            'status'          => $status,
            'issue_tickets'   => $issueTickets,
            'ticket_url'      => $withTicket ? "/api/ticket/$regId" : null,
            'pdf_url'         => $withTicket ? "/api/registrations/$regId/ticket.pdf" : null,
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
            'show_price'    => $reg['show_price'] ?? 1,
        ];
        $issueTickets = (int) ($reg['issue_tickets'] ?? 1) === 1;
        $this->queueConfirmation($event, $regId, $reg['attendee_name'], $reg['attendee_email'], $qrToken, $reg['access_level'], $issueTickets);
        Response::ok(['queued' => true], $issueTickets ? 'Ticket queued for resend' : 'Confirmation re-sent');
    }

    /**
     * DELETE /registrations/{id} — permanently removes a registered person,
     * whether or not they've checked in. Their scan history goes with them
     * (attendance_log cascades); sent-email log rows are kept but unlinked.
     */
    public function destroy(int $regId): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin', 'event_manager']);
        $reg = $this->regWithEvent($regId);
        if (!$reg) {
            Response::error('Registration not found', 404);
        }
        if (($claims['role'] ?? '') !== 'super_admin' && (int) $reg['owner_id'] !== (int) $claims['sub']) {
            Response::error('You do not own this event', 403);
        }

        $this->db->prepare('DELETE FROM registrations WHERE id = :id')->execute([':id' => $regId]);

        AuditLogger::log(
            $this->db, (int) $claims['sub'], 'registration.delete', 'registration', $regId,
            'event=' . $reg['event_id'] . '; name=' . $reg['attendee_name'] . '; email=' . $reg['attendee_email']
            . '; checkin=' . $reg['checkin_status']
        );
        Response::ok(['deleted' => true], 'Registration deleted');
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
            'show_price'    => $reg['show_price'] ?? 1,
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
        header('Content-Disposition: attachment; filename="' . $this->exportFilename($eventId, 'csv') . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ë, ç, etc. correctly
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
        XlsxWriter::stream($this->exportFilename($eventId, 'xlsx'), [], $allRows);
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
        header('Content-Disposition: attachment; filename="' . $this->exportFilename($eventId, 'pdf') . '"');
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
    private function queueConfirmation(array $event, int $regId, string $name, string $email, string $qrToken, string $access, bool $issueTicket = true): void
    {
        if (!$issueTicket) {
            // Registration-only mode (e.g. a ceremony): plain confirmation,
            // no ticket PDF and no "ticket" wording.
            $subject = trim((string) ($event['email_subject'] ?? ''));
            if ($subject === '') {
                $subject = 'Registration confirmed: ' . $event['title'];
            }
            $body = '<p>Hi ' . $name . ',</p>'
                  . '<p>Your registration for <strong>' . $event['title'] . '</strong> is confirmed.</p>'
                  . (!empty($event['venue']) ? '<p>Venue: ' . $event['venue'] . '</p>' : '')
                  . (!empty($event['starts_at_utc']) ? '<p>Date: ' . date('d M Y, H:i', strtotime((string) $event['starts_at_utc'])) . '</p>' : '')
                  . '<p>We look forward to seeing you.</p>';
            $this->deliverOrQueue($regId, $email, $subject, $body, null);
            return;
        }

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

        $this->deliverOrQueue($regId, $email, $subject, $body, $ticket);
    }

    /** Try to send now; on failure park the email in the queue for the cron worker. */
    private function deliverOrQueue(int $regId, string $email, string $subject, string $body, ?string $attachment): void
    {
        $ticket = $attachment;
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

    /** e.g. "Gala-Night-registrations.xlsx" instead of "registrations_event_3.xlsx". */
    private function exportFilename(int $eventId, string $ext): string
    {
        $title = html_entity_decode((string) ($this->eventTitle($eventId) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $clean = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $clean), '-');
        $clean = substr($clean !== '' ? $clean : 'Event-' . $eventId, 0, 60);
        return $clean . '-registrations.' . $ext;
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
            // Hides the "Price:" row on the ticket when the organizer turned it off.
            'Price_Style'   => (int) ($event['show_price'] ?? 1) === 0 ? 'display:none' : '',
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
        $ev = $this->db->prepare('SELECT * FROM events WHERE id = :e');
        $ev->execute([':e' => $eventId]);
        $event = $ev->fetch() ?: [];
        $issueTickets = (int) ($event['issue_tickets'] ?? 1) === 1;

        $stmt = $this->db->prepare(
            'SELECT r.id, r.first_name, r.last_name, r.attendee_name, r.attendee_email, r.phone,
                    r.attendee_age, r.form_data, r.access_level, r.status, r.checkin_status,
                    r.source, r.created_at,
                    (SELECT MIN(scanned_at) FROM attendance_log a
                       WHERE a.registration_id = r.id AND a.scan_result = "success") AS checkin_time
             FROM registrations r
             WHERE r.event_id = :e
             ORDER BY r.created_at, r.id'
        );
        $stmt->execute([':e' => $eventId]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Custom form fields become extra columns. The field used as the
        // phone number is skipped (it's already in the Phone column).
        $schema     = $this->activeFormSchema($eventId);
        $phoneKey   = $this->phoneFieldKey($schema);
        $customCols = [];
        foreach ($schema as $f) {
            $key = $f['name'] ?? null;
            if ($key && $key !== $phoneKey) {
                $customCols[$key] = html_entity_decode((string) ($f['label'] ?? $key), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $showAge = (int) ($event['ask_age'] ?? 0) === 1;
        if (!$showAge) {
            foreach ($records as $r) {
                if ($r['attendee_age'] !== null) { $showAge = true; break; }
            }
        }

        // "No." is the person's position in this event (1, 2, 3...), so every
        // event starts at 1 and there are no gaps after someone is deleted.
        $headers = ['No.', 'First Name', 'Last Name', 'Email', 'Phone'];
        if ($showAge) {
            $headers[] = 'Age';
        }
        if ($issueTickets) {
            $headers[] = 'Ticket Type';
        }
        array_push($headers, 'Status', 'Check-in', 'Check-in Time', 'Source', 'Registered At');
        foreach ($customCols as $label) {
            $headers[] = $label;
        }

        $rows = [];
        $no = 0;
        foreach ($records as $row) {
            $no++;
            $first = $row['first_name'];
            $last  = $row['last_name'];
            // Fall back to splitting the combined name if first/last empty (older rows).
            if (($first === null || $first === '') && !empty($row['attendee_name'])) {
                $parts = preg_split('/\s+/', $row['attendee_name'], 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            }
            $form  = $row['form_data'] ? (json_decode($row['form_data'], true) ?: []) : [];
            $phone = (string) ($row['phone'] ?? '');
            if ($phone === '') {
                // Older registrations kept the phone only inside form_data.
                $phone = $this->phoneFromForm($schema, $form);
            }

            $line = [$no, $first ?? '', $last ?? '', $row['attendee_email'], $phone];
            if ($showAge) {
                $line[] = $row['attendee_age'] ?? '';
            }
            if ($issueTickets) {
                $line[] = $row['access_level'];
            }
            array_push($line, $row['status'], $row['checkin_status'], $row['checkin_time'] ?? '', $row['source'], $row['created_at']);
            foreach (array_keys($customCols) as $key) {
                $v = $form[$key] ?? '';
                $line[] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
            // Stored text is HTML-escaped at input time; exports want plain text.
            $rows[] = array_map(
                static fn ($c) => is_string($c) ? html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $c,
                $line
            );
        }
        return [$headers, $rows];
    }

    /** Key of the custom form field that holds a phone number, if any. */
    private function phoneFieldKey(array $schema): ?string
    {
        foreach ($schema as $f) {
            if (($f['type'] ?? '') === 'tel' && !empty($f['name'])) {
                return $f['name'];
            }
        }
        $pattern = '/phone|telefon|\btel\b|mobile|celular/i';
        foreach ($schema as $f) {
            if (empty($f['name'])) {
                continue;
            }
            if (preg_match($pattern, (string) $f['name']) || preg_match($pattern, (string) ($f['label'] ?? ''))) {
                return $f['name'];
            }
        }
        return null;
    }

    /** Phone number typed into a custom form field ('' when none). */
    private function phoneFromForm(array $schema, array $form): string
    {
        $key = $this->phoneFieldKey($schema);
        if ($key !== null && isset($form[$key]) && !is_array($form[$key])) {
            return trim((string) $form[$key]);
        }
        foreach (['phone', 'telefon', 'tel', 'mobile', 'phone_number'] as $k) {
            if (!empty($form[$k]) && !is_array($form[$k])) {
                return trim((string) $form[$k]);
            }
        }
        return '';
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
                    e.price, e.currency, e.minimum_age, e.email_subject, e.issue_tickets,
                    e.show_price, e.owner_id
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
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,sans-serif;background:#0f1115;color:#e8eaed;
       display:flex;min-height:100vh;align-items:center;justify-content:center;padding:32px 20px}
  .card{background:#fff;color:#111;border-radius:22px;max-width:480px;width:100%;
        overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .top{background:#EC2024;color:#fff;padding:26px 30px}
  .top h1{margin:0;font-size:26px;line-height:1.2}
  .top p{margin:6px 0 0;font-size:16px;opacity:.9}
  .body{padding:30px;text-align:center}
  .name{font-size:28px;font-weight:700;margin:0 0 10px}
  .tier{display:inline-block;background:#111;color:#fff;border-radius:999px;
        padding:6px 18px;font-size:14px;text-transform:uppercase;letter-spacing:1px}
  img{width:300px;height:300px;max-width:100%;margin:22px 0 14px;image-rendering:pixelated}
  .rid{font-size:15px;color:#777}
  @media (max-width:600px){
    body{padding:0;align-items:stretch;background:#fff}
    .card{max-width:none;border-radius:0;box-shadow:none;min-height:100vh}
    .top h1{font-size:24px}
    img{width:82vw;height:82vw}
  }
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
<script>
  // Phones in "desktop site" mode lay the page out ~980px wide and shrink it;
  // zoom back to the phone's real width so the ticket and QR stay big.
  (function () {
    var r = window.innerWidth / screen.width;
    if (matchMedia('(pointer: coarse)').matches && screen.width < 700 && r > 1.3) {
      document.documentElement.style.zoom = r;
      document.body.style.padding = '0';
      document.body.style.alignItems = 'stretch';
      document.body.style.background = '#fff';
      var c = document.querySelector('.card');
      c.style.maxWidth = 'none'; c.style.borderRadius = '0'; c.style.boxShadow = 'none';
      document.querySelector('.card img').style.width = document.querySelector('.card img').style.height = Math.round(screen.width * 0.82) + 'px';
    }
  })();
</script>
</body></html>
HTML;
    }
}
