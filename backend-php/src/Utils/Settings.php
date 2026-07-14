<?php
declare(strict_types=1);

namespace App\Utils;

use PDO;

/**
 * Settings
 * Tiny repository over the `settings` key/value table. Used to read and write
 * runtime configuration (currently SMTP credentials) edited from the admin UI.
 * Reads are cached per-request.
 */
final class Settings
{
    /** @var array<string,string|null>|null */
    private static ?array $cache = null;

    /** Force-reset the in-memory cache (after writes). */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /** Read a single value (null if not set). */
    public static function get(PDO $db, string $key, ?string $default = null): ?string
    {
        $all = self::all($db);
        return $all[$key] ?? $default;
    }

    /** Read all settings as an associative array. */
    public static function all(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = $db->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            // Table missing — caller falls back to defaults.
            $rows = [];
        }
        return self::$cache = $rows;
    }

    /**
     * Upsert a batch of settings. Keys not present are left untouched.
     * @param array<string,string|null> $pairs
     */
    public static function setMany(PDO $db, array $pairs): void
    {
        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($pairs as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
        self::reset();
    }
}
