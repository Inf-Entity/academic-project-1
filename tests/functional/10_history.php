<?php
/**
 * Module 10: History (Lịch sử giao dịch & Tra cứu)
 */

declare(strict_types=1);

function run_ft_history(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 10: History (Tra cứu lịch sử giao dịch)");
    $client->login('admin', 'Admin@123');

    // FT-028: History query returns chronologically sorted ledger entries
    $res1 = $client->request('GET', '/history');
    $entries = $res1['body']['data'] ?? [];
    $ok1 = ($res1['status'] === 200) && is_array($entries) && !empty($entries);
    test_ft(
        'FT-028',
        'history',
        'Truy vấn toàn bộ lịch sử giao dịch sổ cái hệ thống',
        'FR-013: Xem lịch sử giao dịch tài chính',
        'Gửi GET /api/history không truyền bộ lọc',
        'HTTP 200 và danh sách bản ghi giao dịch kèm số biên lai',
        "HTTP {$res1['status']}, count: " . count($entries),
        $ok1
    );

    // FT-029: Filtering history by student code and semester
    $res2 = $client->request('GET', '/history?q=SV001&semester=3');
    $filtered = $res2['body']['data'] ?? [];
    $allMatch = true;
    foreach ($filtered as $e) {
        if (($e['student_code'] ?? '') !== 'SV001' || (int)($e['semester_id'] ?? 0) !== 3) {
            $allMatch = false;
            break;
        }
    }
    test_ft(
        'FT-029',
        'history',
        'Lọc lịch sử giao dịch theo mã sinh viên và học kỳ',
        'FR-014: Bộ lọc tìm kiếm lịch sử thu học phí',
        'Gửi GET /api/history?q=SV001&semester=3',
        'Tất cả bản ghi khớp đúng SV001 và học kỳ 3',
        "Số bản ghi: " . count($filtered) . ", allMatch: " . ($allMatch ? 'true' : 'false'),
        $allMatch && !empty($filtered)
    );

    // FT-030: Date range filtering (from and to) validation and boundary checks
    $res3 = $client->request('GET', '/history?from=2026-12-31&to=2026-01-01');
    $ok3 = ($res3['status'] === 400);
    test_ft(
        'FT-030',
        'history',
        'Xác thực khoảng thời gian: Từ chối khi đến ngày nhỏ hơn từ ngày',
        'BR-016: Ràng buộc to_date >= from_date trên bộ lọc ngày',
        'Gửi GET /api/history?from=2026-12-31&to=2026-01-01',
        'HTTP 400 Bad Request kèm thông báo lỗi trường to',
        "HTTP {$res3['status']}, field to: " . ($res3['body']['error']['fields']['to'] ?? 'N/A'),
        $ok3
    );
}
