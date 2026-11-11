<?php
/**
 * Student Tuition Management System - Application Entry Point
 * PHP 8.1+ & MariaDB Backend with Vanilla Frontend
 */

declare(strict_types=1);

// Chống lưu cache trình duyệt cho trang điều hướng chính
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Nếu người dùng submit form nhầm theo phương thức GET qua URL query, xóa query param để bảo mật mật khẩu
if (isset($_GET['username']) || isset($_GET['password'])) {
    $cleanUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if ($cleanUrl) {
        header('Location: ' . $cleanUrl, true, 302);
        exit;
    }
}

// Chuyển tiếp tới giao diện người dùng chính
require_once __DIR__ . '/frontend/index.html';
