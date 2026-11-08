<?php
/**
 * Users API Endpoint - Student Tuition Management System
 * Routes incoming HTTP requests for authentication, session verification, and user management
 */

declare(strict_types=1);

// Cấu hình báo lỗi an toàn
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once dirname(__DIR__) . '/controllers/UserController.php';

$controller = new UserController();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';

// Cho phép định tuyến linh hoạt: qua query param (?action=login) hoặc qua URI
if ($action === 'login' || ($method === 'POST' && isset($_GET['login']))) {
    $controller->login();
    exit;
}

if ($action === 'logout' || ($method === 'POST' && isset($_GET['logout']))) {
    $controller->logout();
    exit;
}

if ($action === 'csrf' || ($method === 'GET' && isset($_GET['csrf']))) {
    $controller->csrf();
    exit;
}

if ($action === 'me' || ($method === 'GET' && isset($_GET['me']))) {
    $controller->me();
    exit;
}

// Mặc định:
if ($method === 'GET') {
    // Nếu có đăng nhập admin, trả về danh sách users hoặc me
    $controller->index();
} elseif ($method === 'POST') {
    // POST mặc định là login
    $controller->login();
} else {
    json_error('Phương thức không được hỗ trợ.', 'METHOD_NOT_ALLOWED', 405);
}
