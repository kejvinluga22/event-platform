<?php
declare(strict_types=1);

namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use Database;

/**
 * MailService
 * Multi-provider SMTP sender with priority: DB-backed settings first
 * (configured in the admin UI), then the .env failover chain (Gmail ->
 * Outlook -> SendGrid -> Mailgun -> Brevo).
 */
final class MailService
{
    /** @return array{provider:?string, sent:bool, error:?string} */
    public static function send(string $toEmail, string $subject, string $bodyHtml, ?string $attachmentPath = null): array
    {
        $lastError = null;
        $providers = self::resolveProviders();

        foreach ($providers as $name => $p) {
            if (empty($p['user']) || empty($p['pass'])) {
                continue;
            }
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host          = $p['host'];
                $mail->Port          = (int) $p['port'];
                // Default PHPMailer connect timeout is 300s, which leaves the
                // caller (a "send test" click, or a guest's registration
                // request) hanging for up to 5 minutes if the SMTP port is
                // blocked or unreachable. Fail fast instead.
                $mail->Timeout       = 8;
                $mail->SMTPKeepAlive = false;
                $mail->SMTPAuth   = true;
                $mail->Username   = $p['user'];
                $mail->Password   = $p['pass'];
                $mail->SMTPSecure = ($p['enc'] ?? 'tls') === 'tls'
                    ? PHPMailer::ENCRYPTION_STARTTLS
                    : PHPMailer::ENCRYPTION_SMTPS;

                $mail->setFrom($p['from_email'], $p['from_name']);
                $mail->addAddress($toEmail);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $bodyHtml;
                $mail->AltBody = strip_tags($bodyHtml);

                if ($attachmentPath && is_file($attachmentPath)) {
                    $mail->addAttachment($attachmentPath);
                }

                $mail->send();
                return ['provider' => $name, 'sent' => true, 'error' => null];
            } catch (MailException $e) {
                $lastError = $e->getMessage();
                continue;
            }
        }

        return ['provider' => null, 'sent' => false, 'error' => $lastError ?? 'No mail provider configured'];
    }

    /**
     * Build the ordered list of provider configs. DB-configured SMTP wins;
     * .env providers come after as a fallback chain.
     */
    private static function resolveProviders(): array
    {
        $cfg = require __DIR__ . '/../../config/credentials.php';
        $providers = [];

        // 1) DB-backed SMTP (set via the admin Settings page).
        try {
            $db = Database::connection();
            $s  = Settings::all($db);
            if (!empty($s['smtp.user']) && !empty($s['smtp.pass'])) {
                $providers['db'] = [
                    'host'       => $s['smtp.host']       ?? 'smtp.gmail.com',
                    'port'       => (int) ($s['smtp.port'] ?? 587),
                    'user'       => $s['smtp.user'],
                    'pass'       => $s['smtp.pass'],
                    'enc'        => $s['smtp.encryption'] ?? 'tls',
                    'from_email' => $s['smtp.from_email'] ?: $cfg['mail']['from_email'],
                    'from_name'  => $s['smtp.from_name']  ?: $cfg['mail']['from_name'],
                ];
            }
        } catch (\Throwable $e) {
            // DB unreachable — fall through to .env.
        }

        // 2) Environment-configured failover providers.
        foreach ($cfg['mail']['providers'] as $name => $p) {
            $providers[$name] = $p + [
                'from_email' => $cfg['mail']['from_email'],
                'from_name'  => $cfg['mail']['from_name'],
            ];
        }

        return $providers;
    }
}
