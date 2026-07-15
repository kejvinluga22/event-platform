<?php
declare(strict_types=1);

namespace App\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use Database;

/**
 * MailService
 * Priority: Brevo's HTTP API (if BREVO_API_KEY is set) first — it sends over
 * plain HTTPS, so it isn't affected by hosts (e.g. Railway) that block
 * outbound SMTP ports. Falls back to the SMTP chain: DB-backed settings
 * (configured in the admin UI), then the .env failover chain (Gmail ->
 * Outlook -> SendGrid -> Mailgun -> Brevo SMTP).
 */
final class MailService
{
    /** @return array{provider:?string, sent:bool, error:?string} */
    public static function send(string $toEmail, string $subject, string $bodyHtml, ?string $attachmentPath = null): array
    {
        $lastError = null;

        $apiKey = getenv('BREVO_API_KEY') ?: null;
        if ($apiKey) {
            [$fromEmail, $fromName] = self::resolveFromIdentity();
            $result = self::sendViaBrevoApi($apiKey, $toEmail, $subject, $bodyHtml, $attachmentPath, $fromEmail, $fromName);
            if ($result['sent']) {
                return ['provider' => 'brevo_api', 'sent' => true, 'error' => null];
            }
            $lastError = 'Brevo API: ' . $result['error'];
        } else {
            $lastError = 'BREVO_API_KEY not set in this environment';
        }

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
                $lastError .= " | SMTP [$name]: " . $e->getMessage();
                continue;
            }
        }

        return ['provider' => null, 'sent' => false, 'error' => $lastError ?: 'No mail provider configured'];
    }

    /** Send via Brevo's transactional email HTTP API. @return array{sent:bool, error:?string} */
    private static function sendViaBrevoApi(
        string $apiKey,
        string $toEmail,
        string $subject,
        string $bodyHtml,
        ?string $attachmentPath,
        string $fromEmail,
        string $fromName
    ): array {
        $payload = [
            'sender'      => ['email' => $fromEmail, 'name' => $fromName],
            'to'          => [['email' => $toEmail]],
            'subject'     => $subject,
            'htmlContent' => $bodyHtml,
        ];

        if ($attachmentPath && is_file($attachmentPath)) {
            $payload['attachment'] = [[
                'content' => base64_encode((string) file_get_contents($attachmentPath)),
                'name'    => basename($attachmentPath),
            ]];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['sent' => false, 'error' => 'Brevo request failed: ' . $curlErr];
        }
        if ($status >= 200 && $status < 300) {
            return ['sent' => true, 'error' => null];
        }
        $decoded = json_decode((string) $response, true);
        return ['sent' => false, 'error' => 'Brevo API error (' . $status . '): ' . ($decoded['message'] ?? $response)];
    }

    /** Resolve the "From" identity: DB-configured value wins, else .env default. @return array{0:string,1:string} */
    private static function resolveFromIdentity(): array
    {
        $cfg = require __DIR__ . '/../../config/credentials.php';
        try {
            $db = Database::connection();
            $s  = Settings::all($db);
            return [
                $s['smtp.from_email'] ?: $cfg['mail']['from_email'],
                $s['smtp.from_name']  ?: $cfg['mail']['from_name'],
            ];
        } catch (\Throwable $e) {
            return [$cfg['mail']['from_email'], $cfg['mail']['from_name']];
        }
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
