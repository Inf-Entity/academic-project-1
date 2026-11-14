<?php
/**
 * UserController - Student Tuition Management System
 * Handles login, logout, profile session, CSRF token generation, and account lockout
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/api/lib/auth.php';
require_once dirname(__DIR__, 2) . '/api/lib/csrf.php';
require_once dirname(__DIR__, 2) . '/api/lib/validate.php';
require_once dirname(__DIR__, 2) . '/api/lib/response.php';
require_once dirname(__DIR__, 2) . '/api/lib/rate_limit.php';
require_once dirname(__DIR__, 2) . '/api/lib/audit.php';

class UserController {
    /**
     * Issue a new CSRF token for the current session
     */
    public function csrf(): void {
        start_session_safe();
        $token = init_csrf_token();
        json_success(['csrf_token' => $token]);
    }

    /**
     * Authenticate user credentials
     */
    public function login(): void {
        check_rate_limit('login', 30, 60);

        $input = get_json_input();
        validate_allowed_fields($input, ['username', 'password']);

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        $errors = [];
        if ($username === '') {
            $errors['username'] = 'Tên đăng nhập không được để trống.';
        }
        if ($password === '') {
            $errors['password'] = 'Mật khẩu không được để trống.';
        }
        if (!empty($errors)) {
            json_error('Vui lòng nhập đầy đủ thông tin đăng nhập.', 'VALIDATION_ERROR', 400, $errors);
        }

        $user = User::findByUsername($username);

        if (!$user) {
            User::dummyVerify($password);
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
                User::resetFailedLogins((int)$user['id']);
                $user['failed_logins'] = 0;
                $user['locked_until'] = null;
            }
        }

        // Kiểm tra mật khẩu
        if (!User::verifyPassword($password, $user['password_hash'])) {
            $record = User::recordFailedLogin((int)$user['id'], (int)$user['failed_logins'], 15);
            log_audit((int)$user['id'], 'login_failed', 'users', (string)$user['id'], [
                'username' => $username,
                'failed_count' => $record['failed_count']
            ]);
            json_error('Sai tên đăng nhập hoặc mật khẩu.', 'INVALID_CREDENTIALS', 401);
        }

        // Đăng nhập thành công -> Reset số lần thất bại
        User::resetFailedLogins((int)$user['id']);

        // Khởi tạo phiên an toàn
        start_session_safe();
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['student_id']    = isset($user['student_id']) && $user['student_id'] !== null ? (int)$user['student_id'] : null;
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
        $_SESSION['created_at']    = time();
        $_SESSION['last_activity'] = time();

        $csrfToken = init_csrf_token();

        log_audit((int)$user['id'], 'login_success', 'users', (string)$user['id'], ['username' => $username]);

        json_success([
            'id'                   => (int)$user['id'],
            'username'             => $user['username'],
            'full_name'            => $user['full_name'],
            'role'                 => $user['role'],
            'student_id'           => isset($user['student_id']) && $user['student_id'] !== null ? (int)$user['student_id'] : null,
            'must_change_password' => (int)($user['must_change_password'] ?? 0),
            'csrf_token'           => $csrfToken
        ], 200);
    }

    /**
     * Terminate current session
     */
    public function logout(): void {
        verify_csrf_token();
        logout_user();
        json_success(['message' => 'Đã đăng xuất thành công.']);
    }

    /**
     * Return current authenticated user profile
     */
    public function me(): void {
        require_admin();
        $user = get_session_user();
        $user['csrf_token'] = init_csrf_token();
        json_success($user);
    }

    /**
     * List all system users
     */
    public function index(): void {
        require_admin();
        $users = User::getAll();
        json_success($users);
    }
}
