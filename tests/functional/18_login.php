<?php
/**
 * Module 18: Login (Xác thực người dùng & Phân quyền)
 */

declare(strict_types=1);

function run_ft_login(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 18: Login (Đăng nhập & Phân quyền)");

    // FT-051: Admin login returns HTTP 200, session token, and admin role
    $resAdmin = $client->login('admin', 'Admin@123');
    $okAdmin = ($resAdmin['status'] === 200) &&
               (($resAdmin['body']['data']['role'] ?? '') === 'admin') &&
               !empty($resAdmin['body']['data']['csrf_token']);
    test_ft(
        'FT-051',
        'login',
        'Đăng nhập quản trị viên thành công nhận CSRF Token và vai trò admin',
        'FR-027: Xác thực tài khoản quản trị viên',
        'Gửi POST /api/login với admin / Admin@123',
        'HTTP 200 OK, role = admin, có csrf_token',
        "HTTP {$resAdmin['status']}, role: " . ($resAdmin['body']['data']['role'] ?? 'N/A'),
        $okAdmin
    );

    // FT-052: Student login returns HTTP 200, session token, and student role
    $resSt = $client->login('sv001', 'Student@001');
    $okSt = ($resSt['status'] === 200) &&
            (($resSt['body']['data']['role'] ?? '') === 'student') &&
            isset($resSt['body']['data']['student_id']);
    test_ft(
        'FT-052',
        'login',
        'Đăng nhập sinh viên thành công nhận vai trò student và student_id',
        'FR-028: Xác thực tài khoản sinh viên',
        'Gửi POST /api/login với sv001 / Student@001',
        'HTTP 200 OK, role = student',
        "HTTP {$resSt['status']}, role: " . ($resSt['body']['data']['role'] ?? 'N/A'),
        $okSt
    );

    // FT-053: Invalid credentials return 401 without user enumeration
    $resWrongPass = $client->request('POST', '/login', [
        'username' => 'admin',
        'password' => 'WrongPassword!999',
    ]);
    $resNonExist = $client->request('POST', '/login', [
        'username' => 'nonexistent_user_xyz',
        'password' => 'SomePass@123',
    ]);
    $sameStatus = ($resWrongPass['status'] === 401) && ($resNonExist['status'] === 401);
    $sameMsg = (($resWrongPass['body']['error']['message'] ?? '') === ($resNonExist['body']['error']['message'] ?? ''));
    test_ft(
        'FT-053',
        'login',
        'Chống lộ danh tính (User Enumeration): Tên không tồn tại và sai mật khẩu trả về phản hồi đồng nhất',
        'BR-025: Bảo mật thông báo đăng nhập chống liệt kê tài khoản',
        'So sánh kết quả khi sai mật khẩu vs khi tên đăng nhập không tồn tại',
        'Cả hai đều nhận HTTP 401 với thông điệp: "Sai tên đăng nhập hoặc mật khẩu."',
        "WrongPass: {$resWrongPass['status']}, NonExist: {$resNonExist['status']}, sameMsg: " . ($sameMsg ? 'true' : 'false'),
        $sameStatus && $sameMsg
    );
}
