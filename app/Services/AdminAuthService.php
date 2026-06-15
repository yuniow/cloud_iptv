<?php
declare(strict_types=1);

namespace App\Services;

class AdminAuthService
{
    private const TOKEN_TTL = 86400;

    public static function login(string $username, string $password): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return null;
        }

        $isDefault = password_verify('admin', $admin['password_hash']);
        $forceChange = false;
        if ($isDefault) {
            $rows = $pdo->query("SELECT value FROM system_config WHERE key='admin_password_changed'")->fetch();
            if (!$rows || $rows['value'] !== '1') {
                $forceChange = true;
            }
        }

        $token = bin2hex(random_bytes(32));
        $expires = time() + self::TOKEN_TTL;
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute(['admin_token_' . $admin['id'], json_encode(['token' => $token, 'expires' => $expires])]);

        return ['token' => $token, 'forceChangePassword' => $forceChange];
    }

    public static function verify(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $pdo = Database::getInstance();
        $rows = $pdo->query("SELECT key, value FROM system_config WHERE key LIKE 'admin_token_%'")->fetchAll();

        foreach ($rows as $row) {
            $data = json_decode($row['value'], true);
            if ($data && ($data['token'] ?? '') === $token && ($data['expires'] ?? 0) > time()) {
                $adminId = str_replace('admin_token_', '', $row['key']);
                $stmt = $pdo->prepare("SELECT id, username FROM admins WHERE id = ?");
                $stmt->execute([(int)$adminId]);
                return $stmt->fetch() ?: null;
            }
        }

        return null;
    }

    public static function logout(string $token): void
    {
        if ($token === '') {
            return;
        }
        $pdo = Database::getInstance();
        $rows = $pdo->query("SELECT key, value FROM system_config WHERE key LIKE 'admin_token_%'")->fetchAll();
        foreach ($rows as $row) {
            $data = json_decode($row['value'], true);
            if ($data && ($data['token'] ?? '') === $token) {
                $pdo->prepare("DELETE FROM system_config WHERE key = ?")->execute([$row['key']]);
                return;
            }
        }
    }

    public static function changePassword(int $adminId, string $oldPassword, string $newPassword): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($oldPassword, $admin['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE admins SET password_hash = ?, updated_at = datetime('now') WHERE id = ?")->execute([$hash, $adminId]);
        $pdo->prepare("INSERT OR REPLACE INTO system_config (key, value, updated_at) VALUES ('admin_password_changed', '1', datetime('now'))")->execute();
        return ['success' => true, 'message' => '密码修改成功'];
    }

    public static function changeUsername(int $adminId, string $newUsername): array
    {
        if (strlen($newUsername) < 2 || strlen($newUsername) > 32) {
            return ['success' => false, 'message' => '用户名长度 2-32 个字符'];
        }

        $pdo = Database::getInstance();
        $existing = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
        $existing->execute([$newUsername, $adminId]);
        if ($existing->fetch()) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        $pdo->prepare("UPDATE admins SET username = ?, updated_at = datetime('now') WHERE id = ?")->execute([$newUsername, $adminId]);
        return ['success' => true, 'message' => '用户名修改成功'];
    }

    public static function getTokenFromRequest(): string
    {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
        if ($token === '') {
            $token = $_COOKIE['admin_token'] ?? '';
        }
        return $token;
    }
}
