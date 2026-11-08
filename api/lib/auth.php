<?php
/**
 * Authentication and Session Management
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rate_limit.php';

function start_session_safe(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_name('TUITION_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();

    // Kiểm tra timeout không hoạt động (30 phút)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
        session_unset();
        session_destroy();
        json_error('Phiên làm việc đã hết hạn sau 30 phút không hoạt động. Vui lòng đăng nhập lại.', 'SESSION_EXPIRED', 401);
    }

    // Kiểm tra thời gian tối đa phiên (8 giờ)
    if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > 28800) {
        session_unset();
        session_destroy();
        json_error('Phiên làm việc đã hết hạn sau 8 giờ tối đa. Vui lòng đăng nhập lại.', 'SESSION_MAX_LIFETIME', 401);
    }

    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

function require_admin(): void {
    start_session_safe();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
}

function get_session_user(): ?array {
    start_session_safe();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'username'  => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role'] ?? 'admin',
    ];
}

function login_user(string $username, string $password): array {
    check_rate_limit('login', 30, 60);

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, failed_logins, locked_until FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    if (!$user) {
        // Chạy password_verify giả lập để đảm bảo constant-time
        password_verify($password, $dummyHash);
        log_audit(null, 'login_failed', 'users', null, ['username' => $username, 'reason' => 'user_not_found']);
        json_error('Sai tên đăng nhập hoặc mật khẩu.', 'INVALID_CREDENTIALS', 401);
    }

    // Kiểm tra khóa tài khoản
    if (!empty($user['locked_until'])) {
        $lockedTime = strtotime($user['locked_until']);
        if ($lockedTime > time()) {
            log_audit((int)$user['id'], 'login_failed', 'users', (string)$user['id'], ['username' => $username, 'reason' => 'account_locked']);
            json_error('Tài khoản đã bị tạm khóa 15 phút do nhập sai quá 5 lần. Vui lòng thử lại sau.', 'ACCOUNT_LOCKED', 409);
        } else {
            // Hết thời gian khóa -> Reset số lần thất bại
            $db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id")->execute(['id' => $user['id']]);
            $user['failed_logins'] = 0;
            $user['locked_until'] = null;
        }
    }

    if (!password_verify($password, $user['password_hash'])) {
        $failedCount = (int)$user['failed_logins'] + 1;
        $lockSql = null;
        if ($failedCount >= 5) {
            $lockSql = date('Y-m-d H:i:s', time() + (15 * 60));
        }

        $upStmt = $db->prepare("UPDATE users SET failed_logins = :failed, locked_until = :locked WHERE id = :id");
        $upStmt->execute([
            'failed' => $failedCount,
            'locked' => $lockSql,
            'id'     => $user['id']
        ]);

        log_audit((int)$user['id'], 'login_failed', 'users', (string)$user['id'], ['username' => $username, 'failed_count' => $failedCount]);
        json_error('Sai tên đăng nhập hoặc mật khẩu.', 'INVALID_CREDENTIALS', 401);
    }

    // Đăng nhập thành công -> Reset số lần thất bại
    $upStmt = $db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id");
    $upStmt->execute(['id' => $user['id']]);

    // Tái tạo Session ID chống Session Fixation
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    } else {
        start_session_safe();
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    log_audit((int)$user['id'], 'login_success', 'users', (string)$user['id'], ['username' => $username]);

    return [
        'id'         => (int)$user['id'],
        'username'   => $user['username'],
        'full_name'  => $user['full_name'],
        'role'       => $user['role'],
        'csrf_token' => $_SESSION['csrf_token']
    ];
}

function logout_user(): void {
    start_session_safe();
    if (!empty($_SESSION['user_id'])) {
        log_audit((int)$_SESSION['user_id'], 'logout', 'users', (string)$_SESSION['user_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
