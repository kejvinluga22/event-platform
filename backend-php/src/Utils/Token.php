<?php
declare(strict_types=1);

namespace App\Utils;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Token
{
    private static function cfg(): array
    {
        return (require __DIR__ . '/../../config/credentials.php')['jwt'];
    }

    /** Issue an auth access token for a logged-in user. */
    public static function issueAuth(array $user): string
    {
        $cfg = self::cfg();
        $now = time();
        $payload = [
            'iss'  => $cfg['issuer'],
            'iat'  => $now,
            'exp'  => $now + $cfg['ttl'],
            'sub'  => (int) $user['id'],
            'role' => $user['role'],
            'name' => $user['full_name'] ?? '',
        ];
        return JWT::encode($payload, $cfg['secret'], $cfg['algo']);
    }

    /** Decode + verify an auth token. Returns claims array or null. */
    public static function verifyAuth(string $token): ?array
    {
        $cfg = self::cfg();
        try {
            $decoded = JWT::decode($token, new Key($cfg['secret'], $cfg['algo']));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sign a QR payload: JWT({Registration ID, Event ID, Timestamp}, QR_SECRET).
     * Uses a dedicated secret so leaking the QR secret can't mint auth sessions.
     */
    public static function signQr(int $registrationId, int $eventId, string $accessLevel = 'general'): string
    {
        $cfg = self::cfg();
        $payload = [
            'rid' => $registrationId,
            'eid' => $eventId,
            'acl' => $accessLevel,
            'ts'  => time(),
        ];
        return JWT::encode($payload, $cfg['qr_secret'], $cfg['algo']);
    }

    /** Verify a scanned QR token. Returns payload array or null on tamper/forgery. */
    public static function verifyQr(string $token): ?array
    {
        $cfg = self::cfg();
        try {
            return (array) JWT::decode($token, new Key($cfg['qr_secret'], $cfg['algo']));
        } catch (\Throwable) {
            return null;
        }
    }
}
