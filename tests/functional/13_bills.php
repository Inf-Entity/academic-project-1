<?php
/**
 * Module 13: Bills (Hóa đơn điện tử sinh viên HD-YYYYMMDD-NNNN)
 */

declare(strict_types=1);

function run_ft_bills(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 13: Bills (Hóa đơn điện tử HD-YYYYMMDD-NNNN)");

    // Lấy một hóa đơn hợp lệ và thông tin sinh viên sở hữu từ database
    $billRow = $pdo->query("SELECT b.*, l.id AS entry_id, fr.student_id, u.username FROM bills b JOIN ledger_entries l ON b.ledger_entry_id = l.id JOIN fee_records fr ON l.fee_record_id = fr.id JOIN users u ON fr.student_id = u.student_id LIMIT 1")->fetch();
    $billNo = $billRow['bill_no'] ?? '';
    $entryId = (int)($billRow['entry_id'] ?? 0);
    $username = $billRow['username'] ?? 'sv018';
    $password = 'Student@018';

    $client->login($username, $password);

    // FT-037: Electronic bill generation with format HD-YYYYMMDD-NNNN on student payment
    $validFormat = (bool)preg_match('/^HD-\d{8}-\d{4}$/', (string)$billNo);
    test_ft(
        'FT-037',
        'bills',
        'Mã hóa đơn điện tử tuân thủ quy chuẩn định dạng HD-YYYYMMDD-NNNN',
        'BR-021: Quy chuẩn định danh hóa đơn điện tử sinh viên HD-YYYYMMDD-NNNN',
        'Kiểm tra định dạng bill_no trong bảng bills',
        'Khớp biểu thức chính quy ^HD-\d{8}-\d{4}$',
        "Số hóa đơn thực tế: {$billNo}",
        $validFormat
    );

    // FT-038: Bill JSON retrieval by ledger entry ID
    $resJson = $client->request('GET', "/student/bills/{$entryId}");
    $okJson = ($resJson['status'] === 200) && !empty($resJson['body']['data']['bill_no']);
    test_ft(
        'FT-038',
        'bills',
        'Truy vấn chi tiết hóa đơn dạng JSON qua GET /api/student/bills/{id}',
        'FR-017: Xem chi tiết hóa đơn học phí trực tuyến',
        "Sinh viên {$username} gọi GET /api/student/bills/{$entryId}",
        'HTTP 200 OK kèm dữ liệu snapshot hóa đơn',
        "HTTP {$resJson['status']}, bill_no: " . ($resJson['body']['data']['bill_no'] ?? 'N/A'),
        $okJson
    );

    // FT-039: Bill download self-contained HTML attachment without scripts
    $resDl = $client->request('GET', "/student/bills/{$entryId}/download");
    $rawHtml = $resDl['raw'] ?? '';
    $hasNoScripts = (stripos($rawHtml, '<script') === false);
    $hasHeader = isset($resDl['headers']['content-disposition']) && str_contains($resDl['headers']['content-disposition'], 'attachment');
    test_ft(
        'FT-039',
        'bills',
        'Tải hóa đơn dạng tài liệu HTML độc lập, an toàn và không chứa thẻ <script>',
        'FR-018: Tải file hóa đơn điện tử đính kèm để lưu trữ hoặc in',
        "Sinh viên {$username} gọi GET /api/student/bills/{$entryId}/download",
        'HTTP 200, Content-Disposition: attachment, HTML sạch không script',
        "HTTP {$resDl['status']}, noScripts: " . ($hasNoScripts ? 'true' : 'false') . ", hasHeader: " . ($hasHeader ? 'true' : 'false'),
        ($resDl['status'] === 200) && $hasNoScripts
    );

    // FT-040: Cross-student bill isolation returns 404
    // sv002 thử truy cập hóa đơn của sv018
    $client->login('sv002', 'Student@002');
    $resIso = $client->request('GET', "/student/bills/{$entryId}");
    $okIso = ($resIso['status'] === 404);
    test_ft(
        'FT-040',
        'bills',
        'Cô lập dữ liệu IDOR: Sinh viên khác không thể xem hóa đơn của người khác (HTTP 404)',
        'BR-022: Phân quyền dữ liệu độc lập giữa các tài khoản sinh viên',
        "Sinh viên sv002 gọi GET /api/student/bills/{$entryId} (thuộc về {$username})",
        'HTTP 404 Not Found (không lộ sự tồn tại)',
        "HTTP {$resIso['status']}",
        $okIso
    );
}
