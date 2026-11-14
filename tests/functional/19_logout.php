<?php
/**
 * Module 19: Logout (Đăng xuất & Logout-Beacon)
 */

declare(strict_types=1);

function run_ft_logout(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 19: Logout (Đăng xuất & Logout Beacon)");

    // FT-054: Standard logout invalidates session and requires CSRF
    $client->login('admin', 'Admin@123');
    $resOut = $client->request('POST', '/logout');
    $resAfter = $client->request('GET', '/me');
    $okOut = ($resOut['status'] === 200) && ($resAfter['status'] === 401);
    test_ft(
        'FT-054',
        'logout',
        'Đăng xuất tiêu chuẩn hủy phiên an toàn và yêu cầu CSRF Token',
        'FR-029: Đăng xuất người dùng khỏi hệ thống',
        'Đăng nhập admin, gọi POST /api/logout, sau đó gọi GET /api/me',
        'Logout trả về HTTP 200, GET /api/me trả về HTTP 401 Unauthorized',
        "HTTP logout: {$resOut['status']}, HTTP me sau logout: {$resAfter['status']}",
        $okOut
    );

    // FT-055: Logout beacon (POST /api/logout-beacon) terminates session on tab close
    $client->login('admin', 'Admin@123');
    $me = $client->request('GET', '/me');
    $beaconToken = $me['body']['data']['logout_token'] ?? '';

    $resBeacon = $client->request('POST', '/logout-beacon', [
        'logout_token' => $beaconToken,
    ]);
    $resAfterBeacon = $client->request('GET', '/me');
    $okBeacon = ($resBeacon['status'] === 204) && ($resAfterBeacon['status'] === 401);
    test_ft(
        'FT-055',
        'logout',
        'Cơ chế Logout Beacon hủy phiên làm việc ngay khi đóng toàn bộ tab trình duyệt',
        'FR-030: Đăng xuất tự động khi đóng tab trình duyệt qua beacon',
        'Lấy logout_token từ GET /api/me, gửi POST /api/logout-beacon, sau đó kiểm tra /api/me',
        'Beacon trả về HTTP 204 No Content, phiên bị hủy ngay lập tức (GET /api/me nhận 401)',
        "HTTP beacon: {$resBeacon['status']}, HTTP me: {$resAfterBeacon['status']}",
        $okBeacon
    );
}
