<?php
/**
 * API Front Controller and Router - Student Tuition Management System
 */

declare(strict_types=1);

// Cấu hình báo lỗi an toàn: Không in lỗi ra client, chỉ ghi log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Thiết lập múi giờ chuẩn
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Log errors vào thư mục logs
$logsDir = dirname(__DIR__) . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
ini_set('error_log', $logsDir . '/app_error.log');

// Nạp các thư viện dùng chung
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/rate_limit.php';

// Global Exception Handler
set_exception_handler(function (Throwable $e) {
    error_log(sprintf(
        "[%s] Exception: %s in %s on line %d\nStack Trace:\n%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    // Luôn trả về thông báo tiếng Việt chung cho client, tuyệt đối không lộ thông tin nội bộ
    json_error('Đã xảy ra lỗi hệ thống. Vui lòng thử lại.', 'INTERNAL_SERVER_ERROR', 500);
});

// Nạp các module handlers
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/students.php';
require_once __DIR__ . '/handlers/semesters.php';
require_once __DIR__ . '/handlers/rates.php';
require_once __DIR__ . '/handlers/fees.php';
require_once __DIR__ . '/handlers/payments.php';
require_once __DIR__ . '/handlers/refunds.php';
require_once __DIR__ . '/handlers/history.php';
require_once __DIR__ . '/handlers/reports.php';
require_once __DIR__ . '/handlers/receipts.php';

// Xác định phương thức và đường dẫn yêu cầu
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Bỏ query string
$path = parse_url($uri, PHP_URL_PATH);

// Chuẩn hóa đường dẫn tương đối (hoạt động tốt trong bất kỳ subfolder nào của htdocs)
$pos = strpos($path, '/api');
if ($pos !== false) {
    $path = substr($path, $pos);
} else {
    $path = '/api';
}

// Bỏ dấu gạch chéo cuối nếu có (trừ '/')
if (strlen($path) > 1 && substr($path, -1) === '/') {
    $path = rtrim($path, '/');
}

// Điều hướng các Module
if ($path === '/api/csrf' || $path === '/api/login' || $path === '/api/logout' || $path === '/api/me') {
    handle_auth_routes($method, $path);
    exit;
}

if ($path === '/api/students' || preg_match('#^/api/students/(\d+)(/account)?$#', $path, $m)) {
    handle_students_routes($method, $path, $m ?? []);
    exit;
}

if ($path === '/api/semesters' || preg_match('#^/api/semesters/(\d+)$#', $path, $m)) {
    handle_semesters_routes($method, $path, $m ?? []);
    exit;
}

if ($path === '/api/rates' || preg_match('#^/api/rates/(\d+)$#', $path, $m)) {
    handle_rates_routes($method, $path, $m ?? []);
    exit;
}

if ($path === '/api/fee-records' || preg_match('#^/api/fee-records/(\d+)(/(exemption|transfers))?$#', $path, $m) || $path === '/api/transfers') {
    handle_fees_routes($method, $path, $m ?? []);
    exit;
}

if (preg_match('#^/api/fee-records/(\d+)/payments$#', $path, $m) || preg_match('#^/api/ledger/(\d+)/reversal$#', $path, $m)) {
    handle_payments_routes($method, $path, $m ?? []);
    exit;
}

if ($path === '/api/refunds' || preg_match('#^/api/fee-records/(\d+)/refunds$#', $path, $m) || preg_match('#^/api/refunds/(\d+)/(complete|cancel)$#', $path, $m)) {
    handle_refunds_routes($method, $path, $m ?? []);
    exit;
}

if ($path === '/api/history') {
    handle_history_routes($method, $path);
    exit;
}

if ($path === '/api/reports/summary') {
    handle_reports_routes($method, $path);
    exit;
}

if (preg_match('#^/api/receipts/(\d+)$#', $path, $m)) {
    handle_receipts_routes($method, $path, $m ?? []);
    exit;
}

// Nếu không khớp với bất kỳ endpoint nào
json_error('Không tìm thấy tài nguyên yêu cầu.', 'ENDPOINT_NOT_FOUND', 404);
