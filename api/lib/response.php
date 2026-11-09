<?php
/**
 * HTTP Response and Security Headers Utilities
 */

declare(strict_types=1);

function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none';");
}

function json_success($data = null, int $statusCode = 200): void {
    send_security_headers();
    http_response_code($statusCode);
    echo json_encode([
        'ok'   => true,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, string $code = 'ERROR', int $statusCode = 400, ?array $fields = null, $data = null): void {
    send_security_headers();
    http_response_code($statusCode);
    $payload = [
        'ok'    => false,
        'error' => [
            'code'    => $code,
            'message' => $message,
            'fields'  => $fields !== null ? $fields : (object)[]
        ]
    ];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
