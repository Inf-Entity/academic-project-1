<?php
/**
 * Module 06: Refunds (Quy trình hoàn tiền & Kiểm soát Maker-Checker)
 */

declare(strict_types=1);

function run_ft_refunds(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 06: Refunds (Hoàn trả học phí & Maker-Checker)");
    $client->login('admin', 'Admin@123');

    // Đảm bảo hồ sơ #1 có số dư nộp thừa sẵn sàng cho kiểm thử hoàn tiền
    $pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, created_by, created_at) VALUES (1, 'payment', 2000000, 'Tiền mặt', CURDATE(), 'PT-REFUND-INIT', 1, NOW())")->execute();

    $uuid1 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    // FT-016: Creation of refund request for overpaid tuition
    $res1 = $client->request('POST', '/fee-records/1/refunds', [
        'amount'     => 500000,
        'reason'     => 'Hoàn trả một phần tiền thừa',
        'request_id' => $uuid1,
    ]);
    $ok1 = ($res1['status'] === 201) && !empty($res1['body']['ok']);
    $refundId = (int)($res1['body']['data']['id'] ?? 0);
    test_ft(
        'FT-016',
        'refunds',
        'Tạo yêu cầu hoàn trả số tiền nộp thừa thành công',
        'FR-006: Lập yêu cầu hoàn tiền cho sinh viên nộp thừa',
        'Gửi POST /api/fee-records/1/refunds với số tiền 500.000 VND',
        'HTTP 201 Created và trạng thái pending',
        "HTTP {$res1['status']}, refundId: {$refundId}",
        $ok1
    );

    // FT-017: Maker-checker restriction: creator cannot complete own refund
    $res2 = $client->request('POST', "/refunds/{$refundId}/complete");
    $ok2 = ($res2['status'] === 403) && (($res2['body']['error']['code'] ?? '') === 'MAKER_CHECKER_VIOLATION');
    test_ft(
        'FT-017',
        'refunds',
        'Maker-Checker: Người tạo không thể tự duyệt hoàn tất yêu cầu hoàn tiền',
        'BR-011: Nguyên tắc kiểm soát kép Maker-Checker cho quy trình hoàn tiền',
        "Gửi POST /api/refunds/{$refundId}/complete bằng chính tài khoản tạo (admin)",
        'HTTP 403 Forbidden kèm mã MAKER_CHECKER_VIOLATION',
        "HTTP {$res2['status']}, code: " . ($res2['body']['error']['code'] ?? 'N/A'),
        $ok2
    );

    // FT-018: Completion of refund request by another authorized admin
    // Cập nhật người tạo tạm sang user khác để admin duyệt hoàn tất
    $pdo->prepare("UPDATE refund_requests SET created_by = 2 WHERE id = :id")->execute(['id' => $refundId]);
    $res3 = $client->request('POST', "/refunds/{$refundId}/complete");
    $ok3 = ($res3['status'] === 200) && (($res3['body']['data']['status'] ?? '') === 'completed');
    test_ft(
        'FT-018',
        'refunds',
        'Người có thẩm quyền khác duyệt hoàn tất yêu cầu hoàn tiền',
        'FR-007: Phê duyệt hoàn tất yêu cầu hoàn tiền',
        "Gửi POST /api/refunds/{$refundId}/complete sau khi người tạo là user khác",
        'HTTP 200 OK và trạng thái chuyển sang completed',
        "HTTP {$res3['status']}, status: " . ($res3['body']['data']['status'] ?? 'N/A'),
        $ok3
    );
}
