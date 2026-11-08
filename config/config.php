<?php
/**
 * Application Configuration - Student Tuition Management System
 * DO NOT expose this file via web server. Protected by .htaccess.
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tuition_system',
        'user'    => 'tuition_user',
        'pass'    => 'TuitionApp2026!Sec',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name'         => 'TUITION_SESSID',
        'idle_timeout' => 1800,   // 30 phút không hoạt động
        'max_lifetime' => 28800,  // 8 giờ tối đa
        'cookie_path'  => '/',
    ],
    'security' => [
        'timezone'           => 'Asia/Ho_Chi_Minh',
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
        'rate_limit_window'  => 60,   // 60 giây
        'rate_limit_max'     => 30,   // tối đa 30 requests/phút cho login
    ],
    'app' => [
        'school_name' => '[Tên trường]',
    ]
];
