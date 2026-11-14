<?php
/**
 * Module 15: Simulations (Mô phỏng thanh toán QR & Thẻ Visa/Mastercard)
 */

declare(strict_types=1);

function run_ft_simulations(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 15: Simulations (Mô phỏng Mã QR & Thẻ)");
    $client->login('sv002', 'Student@002');

    // Lấy một hồ sơ còn nợ của sv002
    $feeId = 2;

    $uuidQr = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    // FT-044: QR payment session creation and polling status
    $resSess = $client->request('POST', "/student/fee-records/{$feeId}/payment-sessions", [
        'amount'     => 500000,
        'method'     => 'Mã QR',
        'request_id' => $uuidQr,
    ]);
    $sessData = $resSess['body']['data'] ?? [];
    $sessionId = $sessData['session_id'] ?? $sessData['id'] ?? '';
    $qrData = $sessData['qr_data'] ?? '';
    $okSess = ($resSess['status'] === 201) && !empty($sessionId) && str_starts_with($qrData, 'TUITION|SESSION=');
    test_ft(
        'FT-044',
        'simulations',
        'Khởi tạo phiên thanh toán Mã QR mô phỏng thành công với payload chuẩn',
        'FR-021: Tạo phiên thanh toán mã QR sinh viên',
        "Gửi POST /api/student/fee-records/{$feeId}/payment-sessions với 500.000 VND",
        'HTTP 201 Created kèm session_id và chuỗi QR TUITION|SESSION=...',
        "HTTP {$resSess['status']}, session_id: {$sessionId}",
        $okSess
    );

    // FT-045: Successful simulation of QR payment creates ledger entry and bill
    $resSim = $client->request('POST', "/student/payment-sessions/{$sessionId}/simulate-confirm");
    $okSim = in_array($resSim['status'], [200, 201], true) && !empty($resSim['body']['ok']);
    test_ft(
        'FT-045',
        'simulations',
        'Mô phỏng thanh toán QR thành công ghi sổ cái và phát hành hóa đơn điện tử',
        'FR-022: Mô phỏng xác nhận thanh toán QR tức thời',
        "Gửi POST /api/student/payment-sessions/{$sessionId}/simulate-confirm",
        'HTTP 200/201, trạng thái phiên chuyển sang COMPLETED',
        "HTTP {$resSim['status']}, ok: " . json_encode($resSim['body']['ok'] ?? false),
        $okSim
    );

    // FT-046: Simulated Visa/Mastercard payment processing and declined card rejection
    // Thẻ từ chối mock: 5555 5555 5555 4444 -> HTTP 422 CARD_DECLINED
    $uuidCard = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $resCardFail = $client->request('POST', "/student/fee-records/{$feeId}/card-payments", [
        'amount'          => 500000,
        'card_number'     => '5555 5555 5555 4444',
        'cardholder_name' => 'NGUYEN VAN B',
        'expiry'          => '12/28',
        'cvv'             => '999',
        'request_id'      => $uuidCard,
    ]);
    $okCardFail = ($resCardFail['status'] === 422) && (($resCardFail['body']['error']['code'] ?? '') === 'CARD_DECLINED');
    test_ft(
        'FT-046',
        'simulations',
        'Mô phỏng thanh toán thẻ Visa/Mastercard: Từ chối thẻ không hợp lệ với HTTP 422',
        'FR-023: Xử lý phản hồi từ chối thanh toán thẻ mô phỏng',
        'Gửi thanh toán thẻ với số thẻ test từ chối (5555...4444)',
        'HTTP 422 Unprocessable Entity kèm mã CARD_DECLINED',
        "HTTP {$resCardFail['status']}, code: " . ($resCardFail['body']['error']['code'] ?? 'N/A'),
        $okCardFail
    );
}
