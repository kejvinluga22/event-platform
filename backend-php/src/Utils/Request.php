<?php
declare(strict_types=1);

namespace App\Utils;

final class Request
{
    private static ?array $body = null;

    /** Parsed JSON body (cached). */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        self::$body = is_array($decoded) ? $decoded : [];
        return self::$body;
    }

    public static function input(string $key, $default = null)
    {
        $body = self::body();
        return $body[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * XSS sanitization for incoming text. Escapes HTML special chars so that
     * stored values cannot break out of front-end template execution states.
     */
    public static function clean($value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }
        if (is_string($value)) {
            return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $value;
    }

    public static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return trim(explode(',', $_SERVER[$h])[0]);
            }
        }
        return '0.0.0.0';
    }

    public static function bearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        // Fallback to HttpOnly cookie (stateless token over secure cookie)
        return $_COOKIE['access_token'] ?? null;
    }
}
