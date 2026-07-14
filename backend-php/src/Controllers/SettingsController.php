<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Utils\AuditLogger;
use App\Utils\MailService;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Settings;
use PDO;

/**
 * SettingsController
 * Admin endpoints for editing runtime configuration (currently SMTP).
 * Only super_admin can read or write.
 */
final class SettingsController
{
    public function __construct(private PDO $db) {}

    /** GET /settings — return current values (password masked) */
    public function index(): void
    {
        AuthMiddleware::authenticate(['super_admin']);
        $all = Settings::all($this->db);

        // Mask the password — never echo it back.
        $passSet = !empty($all['smtp.pass'] ?? '');

        Response::ok([
            'smtp' => [
                'provider'   => $all['smtp.provider']   ?? 'gmail',
                'host'       => $all['smtp.host']       ?? 'smtp.gmail.com',
                'port'       => (int) ($all['smtp.port'] ?? 587),
                'encryption' => $all['smtp.encryption'] ?? 'tls',
                'user'       => $all['smtp.user']       ?? '',
                'pass_set'   => $passSet,
                'from_email' => $all['smtp.from_email'] ?? '',
                'from_name'  => $all['smtp.from_name']  ?? '',
            ],
            'providers' => self::PRESETS,
        ]);
    }

    /** PUT /settings/smtp — save SMTP credentials */
    public function saveSmtp(): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);
        $b = Request::body();

        $provider = Request::clean($b['provider'] ?? 'custom');
        $preset   = self::PRESETS[$provider] ?? null;

        $pairs = [
            'smtp.provider'   => $provider,
            'smtp.host'       => Request::clean($b['host']       ?? $preset['host']       ?? ''),
            'smtp.port'       => (string) ((int) ($b['port']     ?? $preset['port']       ?? 587)),
            'smtp.encryption' => Request::clean($b['encryption'] ?? $preset['encryption'] ?? 'tls'),
            'smtp.user'       => Request::clean($b['user']       ?? ''),
            'smtp.from_email' => filter_var($b['from_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'smtp.from_name'  => Request::clean($b['from_name']  ?? 'Smart Event'),
        ];

        // Only update the password if a non-empty one was supplied (keeps existing on blank input).
        if (!empty($b['pass'])) {
            $pairs['smtp.pass'] = (string) $b['pass'];
        }

        Settings::setMany($this->db, $pairs);
        AuditLogger::log($this->db, (int) $claims['sub'], 'settings.smtp.update', 'settings', null);

        Response::ok(['saved' => true], 'SMTP settings saved');
    }

    /** POST /settings/smtp/test — send a test email to the from_email address */
    public function testSmtp(): void
    {
        AuthMiddleware::authenticate(['super_admin']);
        $b = Request::body();
        $to = filter_var($b['to'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$to) {
            Response::error('Provide a valid "to" address for the test email', 422);
        }

        $result = MailService::send(
            $to,
            'SMTP test from Smart Event',
            '<p>If you can read this, your SMTP configuration works.</p>'
            . '<p>Sent ' . date('Y-m-d H:i:s') . ' from your Smart Event Platform.</p>'
        );

        if (!$result['sent']) {
            Response::error($result['error'] ?? 'SMTP test failed', 502);
        }
        Response::ok(['provider' => $result['provider']], 'Test email sent');
    }

    /** Built-in SMTP presets (host/port/encryption) for common providers. */
    public const PRESETS = [
        'gmail'    => ['host' => 'smtp.gmail.com',       'port' => 587, 'encryption' => 'tls', 'label' => 'Gmail / Google Workspace'],
        'outlook'  => ['host' => 'smtp.office365.com',   'port' => 587, 'encryption' => 'tls', 'label' => 'Outlook / Office 365'],
        'sendgrid' => ['host' => 'smtp.sendgrid.net',    'port' => 587, 'encryption' => 'tls', 'label' => 'SendGrid'],
        'mailgun'  => ['host' => 'smtp.mailgun.org',     'port' => 587, 'encryption' => 'tls', 'label' => 'Mailgun'],
        'brevo'    => ['host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls', 'label' => 'Brevo (Sendinblue)'],
        'custom'   => ['host' => '',                     'port' => 587, 'encryption' => 'tls', 'label' => 'Custom SMTP'],
    ];
}
