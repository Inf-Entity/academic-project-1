<?php
/**
 * Module 21: Validation (Bản đồ lỗi, Định dạng tiền tệ & Whitelist)
 */

declare(strict_types=1);

function run_ft_validation(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 21: Validation (Chuẩn hóa dữ liệu & Bản đồ lỗi)");
    $client->login('admin', 'Admin@123');

    // FT-059: Currency parser accepts dot, comma, spaces, and VND symbol
    $valDot = parse_currency('1.500.000');
    $valComma = parse_currency('1,500,000');
    $valSym = parse_currency('1500000 đ');
    $okFmt = ($valDot === 1500000) && ($valComma === 1500000) && ($valSym === 1500000);
    test_ft(
        'FT-059',
        'validation',
        'Bộ phân tích tiền tệ chấp nhận dấu chấm, dấu phẩy, khoảng trắng và ký hiệu đ/₫/VND',
        'BR-027: Chuẩn hóa linh hoạt các định dạng tiền tệ Việt Nam',
        'Phân tích chuỗi "1.500.000", "1,500,000", "1500000 đ"',
        'Đều chuẩn hóa chính xác về số nguyên 1500000',
        "valDot: {$valDot}, valComma: {$valComma}, valSym: {$valSym}",
        $okFmt
    );

    // FT-060: Malformed currency (letters, negative, zero, decimal) rejected with field error
    $uuidBad = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $resBad = $client->request('POST', '/fee-records/2/payments', [
        'amount'     => '1.5abc',
        'method'     => 'Tiền mặt',
        'entry_date' => date('Y-m-d'),
        'request_id' => $uuidBad,
    ]);
    $okBad = ($resBad['status'] === 400) && isset($resBad['body']['error']['fields']['amount']);
    test_ft(
        'FT-060',
        'validation',
        'Từ chối số tiền sai định dạng và trả về bản đồ lỗi đúng trường error.fields.amount',
        'FR-033: Bản đồ lỗi chi tiết theo từng trường (Field Error Map)',
        'Gửi thanh toán với amount="1.5abc"',
        'HTTP 400 Bad Request kèm error.fields.amount',
        "HTTP {$resBad['status']}, field amount: " . ($resBad['body']['error']['fields']['amount'] ?? 'N/A'),
        $okBad
    );

    // FT-061: Whitelist validation rejects unknown JSON fields (mass assignment)
    $resExtra = $client->request('POST', '/students', [
        'student_code'       => 'SVTEST99',
        'full_name'          => 'Test Mass Assignment',
        'class_name'         => 'CNTT1',
        'malicious_injected' => 'admin_role',
    ]);
    $okExtra = ($resExtra['status'] === 400) && (($resExtra['body']['error']['code'] ?? '') === 'INVALID_FIELDS');
    test_ft(
        'FT-061',
        'validation',
        'Chống gán hàng loạt (Mass Assignment): Từ chối trường JSON không nằm trong danh mục cho phép',
        'BR-028: Danh sách trắng trường dữ liệu (Whitelist fields validation)',
        'Gửi POST /api/students kèm trường lạ malicious_injected',
        'HTTP 400 Bad Request kèm mã INVALID_FIELDS',
        "HTTP {$resExtra['status']}, code: " . ($resExtra['body']['error']['code'] ?? 'N/A'),
        $okExtra
    );
}
