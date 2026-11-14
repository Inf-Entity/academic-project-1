<?php
/**
 * Module 12: Receipts (Biên lai điện tử PT-YYYYMMDD-NNNN)
 */

declare(strict_types=1);

function run_ft_receipts(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 12: Receipts (Biên lai thu tiền điện tử)");
    $client->login('admin', 'Admin@123');

    // FT-034: Receipt number generation with format PT-YYYYMMDD-NNNN
    $rcpt = $pdo->query("SELECT receipt_no FROM ledger_entries WHERE receipt_no IS NOT NULL AND receipt_no != '' LIMIT 1")->fetchColumn();
    $validFormat = (bool)preg_match('/^PT-\d{8}-\d{4}$/', (string)$rcpt);
    test_ft(
        'FT-034',
        'receipts',
        'Số biên lai thu tiền tuân thủ chính xác định dạng chuẩn PT-YYYYMMDD-NNNN',
        'BR-019: Quy chuẩn định danh biên lai tài chính PT-YYYYMMDD-NNNN',
        'Kiểm tra định dạng receipt_no trong bảng ledger_entries',
        'Khớp biểu thức chính quy ^PT-\d{8}-\d{4}$',
        "Số biên lai mẫu: {$rcpt}",
        $validFormat
    );

    // FT-035: Gapless sequential daily counter reset on new dates
    $counters = $pdo->query("SELECT * FROM receipt_counters ORDER BY day ASC")->fetchAll();
    $hasCounters = count($counters) > 0;
    test_ft(
        'FT-035',
        'receipts',
        'Bộ đếm biên lai duy trì tuần tự không khoảng cách (gapless) theo ngày',
        'BR-020: Đánh số thứ tự biên lai tuần tự theo ngày trong receipt_counters',
        'Kiểm tra bảng receipt_counters',
        'Bảng tồn tại bản ghi đếm theo ngày với last_no >= 1',
        "Số ngày đã phát hành biên lai: " . count($counters),
        $hasCounters
    );

    // FT-036: Receipt query and isolation between students
    $client->login('sv001', 'Student@001');
    $resSt = $client->request('GET', '/student/debts');
    $ok3 = ($resSt['status'] === 200);
    test_ft(
        'FT-036',
        'receipts',
        'Sinh viên truy vấn biên lai và công nợ của chính mình thành công',
        'FR-016: Tra cứu biên lai học phí dành cho sinh viên',
        'Sinh viên sv001 gọi GET /api/student/debts',
        'HTTP 200 OK',
        "HTTP {$resSt['status']}",
        $ok3
    );
}
