<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Request;
use App\Utils\Response;
use PDO;

/**
 * RateLimiter
 * Tracks request origins per route. After N failed/sensitive hits inside the
 * window, the matching client IP is locked for a 15-minute cool-down block.
 */
final class RateLimiter
{
    public function __construct(private PDO $db) {}

    private function cfg(): array
    {
        return (require __DIR__ . '/../../config/credentials.php')['security'];
    }

    /** Abort with 429 if the IP is currently locked for this route. */
    public function enforce(string $routeKey): void
    {
        $ip = Request::ip();
        $row = $this->fetch($ip, $routeKey);

        if ($row && $row['locked_until'] !== null && strtotime($row['locked_until']) > time()) {
            $retry = strtotime($row['locked_until']) - time();
            Response::error('Too many attempts. Try again later.', 429, ['retry_after' => $retry]);
        }
    }

    /** Record a failed/sensitive attempt; lock if threshold exceeded. */
    public function registerFailure(string $routeKey): void
    {
        $cfg = $this->cfg();
        $ip  = Request::ip();
        $row = $this->fetch($ip, $routeKey);

        if (!$row) {
            $this->db->prepare(
                'INSERT INTO rate_limits (ip_address, route_key, hits, window_start)
                 VALUES (:ip, :rk, 1, NOW())'
            )->execute([':ip' => $ip, ':rk' => $routeKey]);
            return;
        }

        $windowExpired = (time() - strtotime($row['window_start'])) > $cfg['rate_limit_window'];
        $hits = $windowExpired ? 1 : ((int) $row['hits'] + 1);
        $lockedUntil = null;

        if ($hits >= $cfg['rate_limit_max']) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $cfg['rate_limit_lock']);
            $hits = 0; // reset counter after locking
        }

        $this->db->prepare(
            'UPDATE rate_limits
                SET hits = :hits,
                    locked_until = :locked,
                    window_start = IF(:reset, NOW(), window_start)
              WHERE ip_address = :ip AND route_key = :rk'
        )->execute([
            ':hits'   => $hits,
            ':locked' => $lockedUntil,
            ':reset'  => $windowExpired ? 1 : 0,
            ':ip'     => $ip,
            ':rk'     => $routeKey,
        ]);
    }

    /** Clear counters after a successful, legitimate action. */
    public function reset(string $routeKey): void
    {
        $this->db->prepare(
            'DELETE FROM rate_limits WHERE ip_address = :ip AND route_key = :rk'
        )->execute([':ip' => Request::ip(), ':rk' => $routeKey]);
    }

    private function fetch(string $ip, string $routeKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rate_limits WHERE ip_address = :ip AND route_key = :rk LIMIT 1'
        );
        $stmt->execute([':ip' => $ip, ':rk' => $routeKey]);
        return $stmt->fetch() ?: null;
    }
}
