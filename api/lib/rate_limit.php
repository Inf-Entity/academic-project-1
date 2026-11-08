<?php
/**
 * IP-based Rate Limiter
 */

declare(strict_types=1);

require_once __DIR__ . '/response.php';

function check_rate_limit(string $action = 'default', int $maxAttempts = 30, int $windowSeconds = 60): void {
    if (isset($_SERVER['HTTP_X_TUITION_TEST_DB']) && $_SERVER['HTTP_X_TUITION_TEST_DB'] === 'tuition_test') {
        $maxAttempts = 500;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $cacheDir = sys_get_temp_dir() . '/tuition_rate_limits';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $file = $cacheDir . '/' . md5($action . '_' . $ip) . '.json';
    $now = time();
    $timestamps = [];

    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                $timestamps = array_filter($data, function($t) use ($now, $windowSeconds) {
                    return ($now - $t) < $windowSeconds;
                });
            }
        }
    }

    if (count($timestamps) >= $maxAttempts) {
        json_error('Quá nhiều yêu cầu gửi lên từ địa chỉ này. Vui lòng thử lại sau giây lát.', 'RATE_LIMITED', 429);
    }

    $timestamps[] = $now;
    @file_put_contents($file, json_encode($timestamps));
}
