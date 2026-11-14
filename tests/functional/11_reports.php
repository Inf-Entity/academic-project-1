<?php
/**
 * Module 11: Reports (Báo cáo tài chính & Phương trình đối soát)
 */

declare(strict_types=1);

function run_ft_reports(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 11: Reports (Báo cáo tài chính & Đối soát)");
    $client->login('admin', 'Admin@123');

    // FT-031: Financial report reconciliation (receivable = collected + outstanding)
    $res1 = $client->request('GET', '/reports/summary?semester=ALL');
    $data = $res1['body']['data'] ?? [];
    $rec = (int)($data['receivable'] ?? 0);
    $col = (int)($data['collected'] ?? 0);
    $out = (int)($data['outstanding'] ?? 0);
    $reconciles = ($rec === ($col + $out));
    test_ft(
        'FT-031',
        'reports',
        'Phương trình đối soát tài chính cân bằng tuyệt đối: receivable = collected + outstanding',
        'BR-017: Phương trình bảo toàn tài chính trong báo cáo tổng quan',
        'Gửi GET /api/reports/summary?semester=ALL',
        "receivable ({$rec}) = collected ({$col}) + outstanding ({$out})",
        "Thực tế: {$rec} = {$col} + {$out} (reconciles: " . ($reconciles ? 'true' : 'false') . ")",
        $reconciles && ($res1['status'] === 200)
    );

    // FT-032: Overpaid records tracked separately from standard status counts
    $statusCounts = $data['status_counts'] ?? [];
    $unpaid = (int)($statusCounts['unpaid'] ?? 0);
    $partial = (int)($statusCounts['partial'] ?? 0);
    $paid = (int)($statusCounts['paid'] ?? 0);
    $overpaidCount = (int)($data['overpaid']['count'] ?? 0);
    $totalRecs = (int)($data['total_students_with_records'] ?? 0);
    $sumMatches = (($unpaid + $partial + $paid + $overpaidCount) === $totalRecs);
    test_ft(
        'FT-032',
        'reports',
        'Khoản nộp thừa được phân loại riêng biệt, tổng 3 trạng thái + overpaid = tổng hồ sơ',
        'BR-018: Phân tách riêng biệt hồ sơ nộp thừa trong bảng tổng hợp',
        'Kiểm tra unpaid + partial + paid + overpaid so với total_records',
        "{$unpaid} + {$partial} + {$paid} + {$overpaidCount} = {$totalRecs}",
        "Tổng tính toán: " . ($unpaid + $partial + $paid + $overpaidCount) . " = {$totalRecs}",
        $sumMatches
    );

    // FT-033: Scope ALL and per-semester reporting consistency
    $resSem3 = $client->request('GET', '/reports/summary?semester=3');
    $d3 = $resSem3['body']['data'] ?? [];
    $rec3 = (int)($d3['receivable'] ?? 0);
    $col3 = (int)($d3['collected'] ?? 0);
    $out3 = (int)($d3['outstanding'] ?? 0);
    $reconciles3 = ($rec3 === ($col3 + $out3));
    test_ft(
        'FT-033',
        'reports',
        'Đối soát tài chính từng học kỳ độc lập cân bằng tuyệt đối',
        'FR-015: Báo cáo tài chính chi tiết theo từng học kỳ',
        'Gửi GET /api/reports/summary?semester=3',
        "Kỳ 3: receivable ({$rec3}) = collected ({$col3}) + outstanding ({$out3})",
        "Thực tế: {$rec3} = {$col3} + {$out3}",
        $reconciles3 && ($resSem3['status'] === 200)
    );
}
