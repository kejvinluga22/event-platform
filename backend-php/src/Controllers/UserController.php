<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Utils\AuditLogger;
use App\Utils\Request;
use App\Utils\Response;
use PDO;

/**
 * UserController — full CRUD for the user management page in Settings.
 * super_admin only. Passwords are bcrypt; the API never echoes them back.
 */
final class UserController
{
    public const ROLES = ['super_admin', 'event_manager', 'staff', 'scanner'];

    public function __construct(private PDO $db) {}

    /** GET /users — list everyone (super_admin only) */
    public function index(): void
    {
        AuthMiddleware::authenticate(['super_admin']);

        // Check whether the v3 column exists; older installs may not have it.
        $hasLastLogin = false;
        try {
            $this->db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
            $hasLastLogin = (bool) $this->db->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetch();
        } catch (\Throwable $e) { /* ignore */ }

        $loginCol = $hasLastLogin ? 'last_login_at' : 'NULL AS last_login_at';
        $rows = $this->db->query(
            "SELECT id, email, full_name, role, is_active, $loginCol, created_at
             FROM users ORDER BY created_at DESC"
        )->fetchAll();
        Response::ok(['users' => $rows]);
    }

    /** POST /users — create a new user */
    public function store(): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);
        $b = Request::body();

        $email = filter_var($b['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass  = (string) ($b['password'] ?? '');
        $role  = $b['role'] ?? 'staff';
        $name  = Request::clean($b['full_name'] ?? '');

        if (!$email) Response::error('Valid email is required', 422);
        if (strlen($pass) < 8) Response::error('Password must be at least 8 characters', 422);
        if (!in_array($role, self::ROLES, true)) Response::error('Invalid role', 422);

        // Uniqueness check.
        $exists = $this->db->prepare('SELECT 1 FROM users WHERE email = :e LIMIT 1');
        $exists->execute([':e' => $email]);
        if ($exists->fetchColumn()) Response::error('A user with that email already exists', 409);

        $this->db->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, is_active)
             VALUES (:e, :p, :n, :r, 1)'
        )->execute([
            ':e' => $email,
            ':p' => password_hash($pass, PASSWORD_BCRYPT),
            ':n' => $name ?: null,
            ':r' => $role,
        ]);
        $newId = (int) $this->db->lastInsertId();

        AuditLogger::log($this->db, (int) $claims['sub'], 'user.create', 'user', $newId, "role=$role");
        Response::created(['id' => $newId, 'email' => $email, 'role' => $role], 'User created');
    }

    /** PUT /users/{id} — update name/role/active flag */
    public function update(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);
        if ((int) $claims['sub'] === $id) {
            Response::error('You cannot modify your own account from here', 422);
        }

        $stmt = $this->db->prepare('SELECT id, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        if (!$user) Response::error('User not found', 404);

        $b = Request::body();
        $sets = [];
        $params = [':id' => $id];

        if (isset($b['full_name'])) {
            $sets[] = 'full_name = :n';
            $params[':n'] = Request::clean($b['full_name']);
        }
        if (isset($b['role'])) {
            if (!in_array($b['role'], self::ROLES, true)) Response::error('Invalid role', 422);
            $sets[] = 'role = :r';
            $params[':r'] = $b['role'];
        }
        if (isset($b['is_active'])) {
            $sets[] = 'is_active = :a';
            $params[':a'] = $b['is_active'] ? 1 : 0;
        }
        if (!$sets) Response::error('No changes provided', 422);

        $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        AuditLogger::log($this->db, (int) $claims['sub'], 'user.update', 'user', $id);
        Response::ok(['updated' => true], 'User updated');
    }

    /** POST /users/{id}/reset-password — admin sets a new password */
    public function resetPassword(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);
        $b = Request::body();
        $pass = (string) ($b['password'] ?? '');
        if (strlen($pass) < 8) Response::error('Password must be at least 8 characters', 422);

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) Response::error('User not found', 404);

        $this->db->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                 ->execute([':p' => password_hash($pass, PASSWORD_BCRYPT), ':id' => $id]);

        AuditLogger::log($this->db, (int) $claims['sub'], 'user.password_reset', 'user', $id);
        Response::ok(['reset' => true], 'Password reset');
    }

    /** DELETE /users/{id} */
    public function destroy(int $id): void
    {
        $claims = AuthMiddleware::authenticate(['super_admin']);
        if ((int) $claims['sub'] === $id) {
            Response::error('You cannot delete your own account', 422);
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) Response::error('User not found', 404);

        $this->db->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
        AuditLogger::log($this->db, (int) $claims['sub'], 'user.delete', 'user', $id);
        Response::ok(['deleted' => true], 'User deleted');
    }
}
