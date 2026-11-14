<?php
/**
 * Module 03: Exemptions (Chính sách miễn giảm học phí)
 */

declare(strict_types=1);

function run_ft_exemptions(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 03: Exemptions (Miễn giảm học phí)");
    $client->login('admin', 'Admin@123');

    // FT-007: Percentage exemption calculation and application
    $res1 = $client->request('PUT', '/fee-records/3/exemption', [
        'type'   => 'percent',
        'value'  => 50,
        'reason' => 'Chế độ chính sách con thương binh',
    ]);
    $ok1 = in_array($res1['status'], [200, 201], true) && !empty($res1['body']['ok']);
    test_ft(
        'FT-007',
        'exemptions',
        'Áp dụng miễn giảm theo tỷ lệ phần trăm (50%)',
        'FR-003: Thiết lập miễn giảm học phí theo phần trăm',
        'Gửi PUT /api/fee-records/3/exemption với type=percent, value=50',
        'HTTP 200/201, áp dụng miễn giảm thành công',
        "HTTP {$res1['status']}, ok: " . json_encode($res1['body']['ok'] ?? false),
        $ok1
    );

    // FT-008: Fixed amount exemption capped at gross tuition
    $calcFixed = calc_exemption(5000000, 'fixed', 6000000);
    $ok2 = ($calcFixed === 5000000);
    test_ft(
        'FT-008',
        'exemptions',
        'Miễn giảm cố định vượt học phí gốc được chặn ở mức học phí gốc',
        'BR-005: Miễn giảm cố định không vượt quá gross_tuition',
        'Gọi calc_exemption(5000000, "fixed", 6000000)',
        '5.000.000 VND',
        "{$calcFixed} VND",
        $ok2
    );

    // FT-009: Rejection of exemption exceeding gross or negative exemption
    $res3 = $client->request('PUT', '/fee-records/3/exemption', [
        'type'   => 'fixed',
        'value'  => 999999999, // Exceeds gross
        'reason' => 'Vượt mức',
    ]);
    $ok3 = ($res3['status'] === 400);
    test_ft(
        'FT-009',
        'exemptions',
        'API từ chối miễn giảm cố định vượt học phí gốc với HTTP 400',
        'BR-006: Xác thực nghiệp vụ miễn giảm',
        'Gửi PUT /api/fee-records/3/exemption với value vượt gross',
        'HTTP 400 Bad Request kèm mã lỗi VALIDATION_ERROR',
        "HTTP {$res3['status']}, code: " . ($res3['body']['error']['code'] ?? 'N/A'),
        $ok3
    );
}
