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
        start_session_safe();
        verify_csrf_token();
        logout_user();
        json_success(['message' => 'Đã đăng xuất thành công.']);
    }

    if ($path === '/api/logout-beacon' && $method === 'POST') {
        start_session_safe();

        // Check content-type: accept only application/json or text/plain
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $isJson = str_contains(strtolower($contentType), 'application/json');
        $isText = str_contains(strtolower($contentType), 'text/plain');

        if (!$isJson && !$isText) {
            json_error('Chỉ chấp nhận Content-Type application/json hoặc text/plain.', 'INVALID_CONTENT_TYPE', 400);
        }

        $rawBody = file_get_contents('php://input');
        // Reject oversized bodies (max 1024 bytes)
        if (strlen($rawBody) > 1024) {
            json_error('Dữ liệu yêu cầu vượt quá kích thước cho phép.', 'PAYLOAD_TOO_LARGE', 400);
        }

        $providedToken = '';
        if ($isJson) {
            $parsed = json_decode($rawBody, true);
            if (!is_array($parsed)) {
                json_error('Định dạng JSON không hợp lệ.', 'INVALID_JSON', 400);
            }
            // Reject unknown fields: only 'token' or 'logout_token' allowed
            foreach (array_keys($parsed) as $k) {
                if ($k !== 'token' && $k !== 'logout_token') {
                    json_error('Chứa trường không hợp lệ.', 'UNKNOWN_FIELDS', 400);
                }
            }
            $providedToken = (string)($parsed['token'] ?? $parsed['logout_token'] ?? '');
        } else {
            $providedToken = trim($rawBody);
            if (strlen($providedToken) > 128) {
                json_error('Kích thước token không hợp lệ.', 'INVALID_TOKEN_SIZE', 400);
            }
        }

        $expectedToken = $_SESSION['logout_token'] ?? '';
        if ($expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            logout_user();
        }

        http_response_code(204);
        exit;
    }

    if ($path === '/api/me' && $method === 'GET') {
        start_session_safe();
        $user = get_session_user();
        if (!$user) {
            json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
        }
        $user['csrf_token'] = init_csrf_token();
        if (empty($_SESSION['logout_token'])) {
            $_SESSION['logout_token'] = bin2hex(random_bytes(32));
        }
        $user['logout_token'] = $_SESSION['logout_token'];
        json_success($user);
    }
}
