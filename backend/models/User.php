<?php
/**
 * User Model - Student Tuition Management System
 * Handles user authentication records, account lockouts, and credentials
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

class User {
    /**
     * Find a user record by username
     */
    public static function findByUsername(string $username, ?string $dbName = null): ?array {
        $db = Database::getConnection($dbName);
        $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until, created_at FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find a user record by primary key ID
     */
    public static function findById(int $id, ?string $dbName = null): ?array {
        $db = Database::getConnection($dbName);
        $stmt = $db->prepare("SELECT id, username, full_name, role, student_id, must_change_password, failed_logins, locked_until, created_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get all active users
     */
    public static function getAll(?string $dbName = null): array {
        $db = Database::getConnection($dbName);
        $stmt = $db->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    /**
     * Reset failed login counter and unlock account
     */
    public static function resetFailedLogins(int $userId, ?string $dbName = null): void {
        $db = Database::getConnection($dbName);
        $stmt = $db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Record a failed login attempt and lock account if threshold reached
     */
    public static function recordFailedLogin(int $userId, int $currentFailed, int $lockoutMinutes = 15, ?string $dbName = null): array {
        $db = Database::getConnection($dbName);
        $failedCount = $currentFailed + 1;
        $lockSql = null;
        if ($failedCount >= 5) {
            $lockSql = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
        }

        $stmt = $db->prepare("UPDATE users SET failed_logins = :failed, locked_until = :locked WHERE id = :id");
        $stmt->execute([
            'failed' => $failedCount,
            'locked' => $lockSql,
            'id'     => $userId
        ]);

        return [
            'failed_count' => $failedCount,
            'locked_until' => $lockSql,
            'is_locked'    => ($lockSql !== null)
        ];
    }

    /**
     * Constant-time password verification with dummy fallback
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    /**
     * Run a dummy hash verification to prevent timing attacks
     */
    public static function dummyVerify(string $password): void {
        // Valid bcrypt hash to ensure constant-time verification without PHP runtime warnings
        $dummyHash = '$2y$10$Az4CwfdVPUtzW6zMM1VXROTxUL9xKQkEKe45FflKg8BGhn1VukJSO';
        password_verify($password, $dummyHash);
    }
}
