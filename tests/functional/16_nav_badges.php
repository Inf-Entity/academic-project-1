<?php
/**
 * Module 16: Nav Badges (Huy hiệu điều hướng & Bộ đếm công nợ)
 */

declare(strict_types=1);

function run_ft_nav_badges(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 16: Nav Badges (Huy hiệu thông báo điều hướng)");
    $client->login('admin', 'Admin@123');

    // FT-047: Dynamic badge counter reflects pending refunds and unpaid debts
    $res1 = $client->request('GET', '/nav/badges');
    $badges = $res1['body']['data'] ?? [];
    $hasKeys = isset($badges['refunds']) && isset($badges['new_transactions']) && isset($badges['pending_profiles']) && isset($badges['overpaid_profiles']);
    test_ft(
        'FT-047',
        'nav_badges',
        'Truy vấn số lượng huy hiệu thông báo điều hướng hệ thống qua GET /api/nav/badges',
        'FR-024: Hiển thị huy hiệu số lượng công việc cần xử lý',
        'Gửi GET /api/nav/badges',
        'HTTP 200 kèm các chỉ số đếm số nguyên không âm {refunds, new_transactions, pending_profiles, overpaid_profiles}',
        "HTTP {$res1['status']}, data: " . json_encode($badges),
        ($res1['status'] === 200) && $hasKeys
    );

    // FT-048: User navigation state persistence (POST /api/nav/seen)
    $uuidNav = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res2 = $client->request('POST', '/nav/seen', [
        'nav_key'    => 'history',
        'request_id' => $uuidNav,
    ]);
    $ok2 = in_array($res2['status'], [200, 204], true) && !empty($res2['body']['ok']);
    test_ft(
        'FT-048',
        'nav_badges',
        'Ghi nhận trạng thái đã xem của người dùng đối với mục điều hướng (nav_key=history)',
        'FR-025: Đánh dấu đã xem thông báo điều hướng',
        'Gửi POST /api/nav/seen với nav_key=history và request_id',
        'HTTP 200 Success kèm last_seen_entry_id',
        "HTTP {$res2['status']}, ok: " . json_encode($res2['body']['ok'] ?? false),
        $ok2
    );
}
