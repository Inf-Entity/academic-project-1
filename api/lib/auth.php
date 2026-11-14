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
    ini_set('session.gc_maxlifetime', '28800');
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
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
    if ($_SESSION['role'] !== 'admin') {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }
}

function require_student(bool $allowMustChangePassword = false): array {
    start_session_safe();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }
    if ($_SESSION['role'] !== 'student') {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, student_id, must_change_password FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['role'] !== 'student' || empty($user['student_id'])) {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }

    if (!$allowMustChangePassword && !empty($user['must_change_password'])) {
        json_error('Vui lòng đổi mật khẩu trước khi tiếp tục.', 'PASSWORD_CHANGE_REQUIRED', 403);
    }

    return $user;
}

function get_session_user(): ?array {
    start_session_safe();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, student_id, must_change_password FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user) {
        return [
            'id'                   => (int)$user['id'],
            'username'             => $user['username'],
            'full_name'            => $user['full_name'],
            'role'                 => $user['role'],
            'student_id'           => $user['student_id'] !== null ? (int)$user['student_id'] : null,
            'must_change_password' => (int)$user['must_change_password'],
        ];
    }
    return [
        'id'                   => (int)$_SESSION['user_id'],
        'username'             => $_SESSION['username'] ?? '',
        'full_name'            => $_SESSION['full_name'] ?? '',
        'role'                 => $_SESSION['role'] ?? 'admin',
        'student_id'           => isset($_SESSION['student_id']) ? (int)$_SESSION['student_id'] : null,
        'must_change_password' => (int)($_SESSION['must_change_password'] ?? 0),
    ];
}

function login_user(string $username, string $password): array {
    check_rate_limit('login', 30, 60);

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    // Valid bcrypt hash to ensure constant-time verification without PHP runtime warnings
    $dummyHash = '$2y$10$Az4CwfdVPUtzW6zMM1VXROTxUL9xKQkEKe45FflKg8BGhn1VukJSO';

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
    $_SESSION['student_id'] = $user['student_id'] !== null ? (int)$user['student_id'] : null;
    $_SESSION['must_change_password'] = (int)$user['must_change_password'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['logout_token'] = bin2hex(random_bytes(32));

    log_audit((int)$user['id'], 'login_success', 'users', (string)$user['id'], ['username' => $username]);

    // Thiết lập baseline cho Giao dịch mới trên lần đăng nhập đầu tiên (đối với admin)
    if ($user['role'] === 'admin') {
        try {
            $chkNav = $db->prepare("SELECT user_id FROM user_nav_state WHERE user_id = :uid AND nav_key = 'history'");
            $chkNav->execute(['uid' => $user['id']]);
            if (!$chkNav->fetch()) {
                $maxEntryStmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM ledger_entries");
                $maxEntryId = (int)$maxEntryStmt->fetchColumn();
                $insNav = $db->prepare("INSERT INTO user_nav_state (user_id, nav_key, last_seen_entry_id, updated_at) VALUES (:uid, 'history', :maxId, NOW())");
                $insNav->execute(['uid' => $user['id'], 'maxId' => $maxEntryId]);
            }
        } catch (Throwable $e) {
            // Không làm gián đoạn đăng nhập nếu có lỗi
        }
    }

    return [
        'id'                   => (int)$user['id'],
        'username'             => $user['username'],
        'full_name'            => $user['full_name'],
        'role'                 => $user['role'],
        'student_id'           => $user['student_id'] !== null ? (int)$user['student_id'] : null,
        'must_change_password' => (int)$user['must_change_password'],
        'csrf_token'           => $_SESSION['csrf_token'],
        'logout_token'         => $_SESSION['logout_token'] ?? null
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
