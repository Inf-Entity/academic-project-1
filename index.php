<?php
/**
 * Student Tuition Management System - Application Entry Point
 * PHP 8.1+ & MariaDB Backend with Vanilla Frontend
 */

declare(strict_types=1);

// Gửi tiêu đề Content-Type chuẩn UTF-8
header('Content-Type: text/html; charset=utf-8');

// Chuyển tiếp tới giao diện người dùng chính
require_once __DIR__ . '/frontend/index.html';
