<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimiter;
use App\Utils\AuditLogger;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Token;
use PDO;

final class AuthController
{
    public function __construct(private PDO $db) {}

    /** POST /auth/login */
    public function login(): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->enforce('auth_login');

        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $pass  = (string) Request::input('password', '');

        if (!$email || $pass === '') {
            $limiter->registerFailure('auth_login');
            Response::error('Email and password are required', 422);
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :e AND is_active = 1 LIMIT 1');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            $limiter->registerFailure('auth_login');
            Response::error('Invalid credentials', 401);
        }

        $limiter->reset('auth_login');

        // Record last successful login (silently no-op if the column doesn't
        // exist yet, so older installs without migration_v3 still log in).
        try {
            $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                     ->execute([':id' => $user['id']]);
        } catch (\Throwable $e) { /* column missing on pre-v3 schema */ }

        // Rehash if the algorithm/cost changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($pass, PASSWORD_BCRYPT);
            $this->db->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                     ->execute([':h' => $newHash, ':id' => $user['id']]);
        }

        $token = Token::issueAuth($user);

        // Stateless token over HttpOnly secure cookie (no script-side access).
        setcookie('access_token', $token, [
            'expires'  => time() + 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => false, // set true behind HTTPS in production
        ]);

        AuditLogger::log($this->db, (int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        Response::ok([
            'token' => $token,
            'user'  => [
                'id'    => (int) $user['id'],
                'name'  => $user['full_name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ], 'Login successful');
    }

    /** POST /auth/logout */
    public function logout(): void
    {
        setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/']);
        Response::ok([], 'Logged out');
    }

    /** GET /auth/me */
    public function me(): void
    {
        $claims = AuthMiddleware::authenticate();
        $stmt = $this->db->prepare('SELECT id, full_name, email, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $claims['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            Response::error('User not found', 404);
        }
        Response::ok(['user' => $user]);
    }

    /** POST /auth/users  (super_admin only) — create staff/manager/scanner accounts */
    public function createUser(): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);

        $name  = Request::clean(Request::input('full_name', ''));
        $email = filter_var(Request::input('email', ''), FILTER_VALIDATE_EMAIL);
        $pass  = (string) Request::input('password', '');
        $role  = Request::input('role', 'staff');

        $allowedRoles = ['super_admin', 'event_manager', 'staff', 'scanner'];
        if (!$name || !$email || strlen($pass) < 8 || !in_array($role, $allowedRoles, true)) {
            Response::error('Invalid user payload (password min 8 chars, valid role required)', 422);
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (full_name, email, password_hash, role)
                 VALUES (:n, :e, :p, :r)'
            );
            $stmt->execute([
                ':n' => $name,
                ':e' => $email,
                ':p' => password_hash($pass, PASSWORD_BCRYPT),
                ':r' => $role,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Email already exists', 409);
            }
            throw $e;
        }

        $id = (int) $this->db->lastInsertId();
        AuditLogger::log($this->db, (int) $claims['sub'], 'user.create', 'user', $id, "role=$role");
        Response::created(['id' => $id], 'User created');
    }
}
