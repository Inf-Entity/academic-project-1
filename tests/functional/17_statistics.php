<?php
/**
 * Module 17: Statistics (Thống kê số lượng & Số tiền 3 cột)
 */

declare(strict_types=1);

function run_ft_statistics(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 17: Statistics (Thống kê 3 cột số tiền)");
    $client->login('admin', 'Admin@123');

    // FT-049: Statistics board 3 amount columns (receivable, collected, outstanding) return integer VND
    $res1 = $client->request('GET', '/reports/summary?semester=ALL');
    $data = $res1['body']['data'] ?? [];
    $rec = $data['receivable'] ?? null;
    $col = $data['collected'] ?? null;
    $out = $data['outstanding'] ?? null;
    $allInt = is_int($rec) && is_int($col) && is_int($out);
    test_ft(
        'FT-049',
        'statistics',
        '3 cột số tiền (Tổng phải thu, Đã thu, Còn nợ) trả về số nguyên VND hợp lệ (không null)',
        'FR-026: Bảng thống kê tài chính trực quan với số tiền định dạng chuẩn',
        'Gửi GET /api/reports/summary?semester=ALL',
        'receivable, collected, outstanding đều là số nguyên VND >= 0',
        "rec: " . var_export($rec, true) . ", col: " . var_export($col, true) . ", out: " . var_export($out, true),
        $allInt && ($rec >= 0) && ($col >= 0) && ($out >= 0)
    );

    // FT-050: Amounts match financial reconciliation formula across all rows
    $reconciles = ((int)$rec === ((int)$col + (int)$out));
    test_ft(
        'FT-050',
        'statistics',
        'Số tiền trên bảng thống kê thỏa mãn phương trình cân đối thu chi',
        'BR-024: Đối soát tài chính trên bảng điều khiển quản trị',
        'Kiểm tra: receivable == collected + outstanding',
        "{$rec} == {$col} + {$out}",
        "Kết quả: " . ($reconciles ? 'Cân bằng tuyệt đối' : 'Lệch số liệu'),
        $reconciles
    );
}
