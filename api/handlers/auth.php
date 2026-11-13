<?php
/**
 * Auth API Handlers: Login, Logout, Current User, CSRF Token
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';

function handle_auth_routes(string $method, string $path): void {
    if ($path === '/api/csrf' && $method === 'GET') {
        start_session_safe();
        $token = init_csrf_token();
        json_success(['csrf_token' => $token]);
    }

    if ($path === '/api/login' && $method === 'POST') {
        $input = get_json_input();
        validate_allowed_fields($input, ['username', 'password']);

        $username = trim((string)($input['username'] ?? ''));
        // Password with leading or trailing spaces preserved (do not trim passwords)
        $password = (string)($input['password'] ?? '');

        $errors = [];
        if ($username === '') {
            $errors['username'] = 'Tên đăng nhập không được để trống.';
        } elseif (mb_strlen($username, 'UTF-8') > 50) {
            $errors['username'] = 'Tên đăng nhập không được vượt quá 50 ký tự.';
        }
        if ($password === '') {
            $errors['password'] = 'Mật khẩu không được để trống.';
        }
        if (!empty($errors)) {
            json_error('Vui lòng nhập đầy đủ thông tin đăng nhập.', 'VALIDATION_ERROR', 400, $errors);
        }

        $user = login_user($username, $password);
        json_success($user, 200);
    }

    if ($path === '/api/logout' && $method === 'POST') {
        verify_csrf_token();
        logout_user();
        json_success(['message' => 'Đã đăng xuất thành công.']);
    }

    if ($path === '/api/me' && $method === 'GET') {
        start_session_safe();
        $user = get_session_user();
        if (!$user) {
            json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
        }
        $user['csrf_token'] = init_csrf_token();
        json_success($user);
    }
}
