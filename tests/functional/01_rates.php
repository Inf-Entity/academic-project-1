<?php
/**
 * Module 01: Rates (Định mức tín chỉ)
 */

declare(strict_types=1);

function run_ft_rates(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 01: Rates (Định mức tín chỉ)");
    $client->login('admin', 'Admin@123');

    // FT-001: Admin creates or updates credit rate for a semester
    $res = $client->request('PUT', '/rates/4', [
        'price_per_credit' => 880000,
    ]);
    $ok1 = in_array($res['status'], [200, 201], true) && !empty($res['body']['ok']);
    test_ft(
        'FT-001',
        'rates',
        'Admin thiết lập/cập nhật định mức tín chỉ cho học kỳ',
        'FR-001: Quản lý định mức tín chỉ theo học kỳ',
        'Gửi PUT /api/rates/4 với price_per_credit=880000',
        'HTTP 200/201, ok: true và đơn giá được lưu',
        "HTTP {$res['status']}, ok: " . json_encode($res['body']['ok'] ?? false),
        $ok1
    );

    // FT-002: Snapshot preservation on existing fee records when credit rate is updated
    $feeBefore = $pdo->query("SELECT rate_snapshot, gross_tuition FROM fee_records WHERE id = 1")->fetch();
    $res2 = $client->request('PUT', '/rates/3', [
        'price_per_credit' => 950000,
    ]);
    $feeAfter = $pdo->query("SELECT rate_snapshot, gross_tuition FROM fee_records WHERE id = 1")->fetch();
    $ok2 = ((int)$feeBefore['rate_snapshot'] === (int)$feeAfter['rate_snapshot']) &&
           ((int)$feeBefore['gross_tuition'] === (int)$feeAfter['gross_tuition']);
    test_ft(
        'FT-002',
        'rates',
        'Bảo toàn Rate Snapshot trên hồ sơ đã tạo khi thay đổi định mức',
        'BR-001: Đơn giá tín chỉ được chụp (snapshot) bất biến tại thời điểm tạo hồ sơ',
        'Cập nhật định mức học kỳ 3 lên 950.000 và kiểm tra rate_snapshot của hồ sơ #1',
        'rate_snapshot của hồ sơ #1 không đổi',
        "Trước: {$feeBefore['rate_snapshot']}, Sau: {$feeAfter['rate_snapshot']}",
        $ok2
    );

    // FT-003: Rate retrieval with formatted VND output
    $res3 = $client->request('GET', '/rates');
    $rates = $res3['body']['data'] ?? [];
    $hasRates = !empty($rates) && is_array($rates);
    test_ft(
        'FT-003',
        'rates',
        'Truy vấn danh sách định mức học phí các kỳ',
        'FR-002: Tra cứu bảng định mức tín chỉ',
        'Gửi GET /api/rates',
        'HTTP 200, danh sách định mức hợp lệ',
        "HTTP {$res3['status']}, số lượng định mức: " . count($rates),
        $hasRates
    );
}
