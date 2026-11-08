<?php
/**
 * CSRF Protection Token Handling
 */

declare(strict_types=1);

require_once __DIR__ . '/response.php';

function init_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    // Only check write methods: POST, PUT, DELETE, PATCH
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (!$headerToken || !$sessionToken || !hash_equals($sessionToken, $headerToken)) {
        json_error('Mã xác thực CSRF không hợp lệ hoặc đã hết hạn.', 'CSRF_FORBIDDEN', 403);
    }
}
