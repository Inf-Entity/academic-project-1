<?php
/**
 * Module 04: Payments (Nộp học phí & Kiểm soát nộp thừa)
 */

declare(strict_types=1);

function run_ft_payments(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 04: Payments (Nộp học phí)");
    $client->login('admin', 'Admin@123');

    // FT-010: Admin records standard cash payment for tuition
    $uuid1 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res1 = $client->request('POST', '/fee-records/2/payments', [
        'amount'     => 1000000,
        'method'     => 'Tiền mặt',
        'entry_date' => date('Y-m-d'),
        'request_id' => $uuid1,
        'note'       => 'Nộp đợt 1',
    ]);
    $ok1 = ($res1['status'] === 201) && !empty($res1['body']['ok']);
    test_ft(
        'FT-010',
        'payments',
        'Admin ghi nhận thanh toán học phí thành công bằng Tiền mặt',
        'FR-004: Thu học phí trực tiếp tại quầy bằng tiền mặt',
        'Gửi POST /api/fee-records/2/payments với 1.000.000 VND tiền mặt',
        'HTTP 201 Created, tạo thành công dòng sổ cái và số biên lai',
        "HTTP {$res1['status']}, ok: " . json_encode($res1['body']['ok'] ?? false),
        $ok1
    );

    // FT-011: Overpayment rejection when payment exceeds outstanding balance
    $uuid2 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res2 = $client->request('POST', '/fee-records/2/payments', [
        'amount'     => 999999999, // Exceeds balance
        'method'     => 'Tiền mặt',
        'entry_date' => date('Y-m-d'),
        'request_id' => $uuid2,
    ]);
    $ok2 = ($res2['status'] === 422) && in_array($res2['body']['error']['code'] ?? '', ['OVERPAYMENT_NOT_ALLOWED', 'OVERPAYMENT_REJECTED'], true);
    test_ft(
        'FT-011',
        'payments',
        'Chặn nộp thừa: từ chối số tiền thanh toán vượt quá số nợ còn lại với HTTP 422',
        'BR-007: Không cho phép nộp vượt quá net_due (chống nộp thừa trực tiếp)',
        'Gửi POST /api/fee-records/2/payments với số tiền 999.999.999 VND',
        'HTTP 422 Unprocessable Entity kèm mã OVERPAYMENT_REJECTED',
        "HTTP {$res2['status']}, code: " . ($res2['body']['error']['code'] ?? 'N/A'),
        $ok2
    );

    // FT-012: Payment status transition (Unpaid -> Partially Paid -> Fully Paid)
    $stUnpaid = derive_payment_status(0, 10000000);
    $stPart = derive_payment_status(5000000, 10000000);
    $stFull = derive_payment_status(10000000, 10000000);
    $ok3 = ($stUnpaid['status'] === 'Unpaid') &&
           ($stPart['status'] === 'Partially Paid') &&
           ($stFull['status'] === 'Fully Paid');
    test_ft(
        'FT-012',
        'payments',
        'Chuyển đổi trạng thái thanh toán chuẩn xác tại các ngưỡng',
        'BR-008: Chuyển đổi trạng thái công nợ theo công thức xác định',
        'Kiểm tra derive_payment_status tại 0 VND, 5.000.000 VND và 10.000.000 VND',
        'Unpaid -> Partially Paid -> Fully Paid',
        "{$stUnpaid['status']} -> {$stPart['status']} -> {$stFull['status']}",
        $ok3
    );
}
