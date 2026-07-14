<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Token;

/**
 * AuthMiddleware
 * Stateless JWT verification + role-based access gating. Tokens arrive either
 * as a Bearer header or an HttpOnly secure cookie (no script-side exposure).
 */
final class AuthMiddleware
{
    /**
     * Require a valid token. Optionally restrict to a set of roles.
     * Returns the decoded claims (sub, role, name).
     *
     * @param string[] $roles  Allowed roles. Empty = any authenticated user.
     */
    public static function authenticate(array $roles = []): array
    {
        $token = Request::bearerToken();
        if (!$token) {
            Response::error('Authentication required', 401);
        }

        $claims = Token::verifyAuth($token);
        if (!$claims || empty($claims['sub'])) {
            Response::error('Invalid or expired token', 401);
        }

        if ($roles && !in_array($claims['role'] ?? '', $roles, true)) {
            Response::error('Insufficient permissions', 403);
        }

        return $claims;
    }

    /** Convenience role matrix helpers. */
    public static function isAdmin(array $claims): bool
    {
        return in_array($claims['role'] ?? '', ['super_admin', 'event_manager'], true);
    }
}
