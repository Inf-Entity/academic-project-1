<?php
/**
 * Module 07: Transfers (Chuyển tiền thừa giữa các kỳ)
 */

declare(strict_types=1);

function run_ft_transfers(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 07: Transfers (Chuyển tiền thừa giữa các kỳ)");
    $client->login('admin', 'Admin@123');

    // Đảm bảo hồ sơ nguồn #1 của SV001 có số dư nộp thừa sẵn sàng với request_id duy nhất
    $initUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, request_id, created_by, created_at) VALUES (1, 'payment', 3000000, 'Tiền mặt', CURDATE(), 'PT-TRANS-INIT', :reqId, 1, NOW())")->execute(['reqId' => $initUuid]);

    // Tạo hoặc tìm hồ sơ đích còn nợ của SV001 trong học kỳ 4
    $chkTarget = $pdo->query("SELECT id FROM fee_records WHERE student_id = 1 AND semester_id = 4")->fetch();
    if ($chkTarget) {
        $targetId = (int)$chkTarget['id'];
    } else {
        $pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (1, 4, 10, 850000, 8500000, NOW(), NOW())")->execute();
        $targetId = (int)$pdo->lastInsertId();
    }

    $uuid1 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    // FT-019: Transfer of excess payment from one semester to another for same student
    $res1 = $client->request('POST', '/fee-records/1/transfers', [
        'source_fee_record_id' => 1,
        'target_fee_record_id' => $targetId,
        'amount'               => 500000,
        'reason'               => 'Chuyển tiền thừa sang kỳ mới',
        'request_id'           => $uuid1,
    ]);
    $ok1 = in_array($res1['status'], [200, 201], true) && !empty($res1['body']['ok']);
    test_ft(
        'FT-019',
        'transfers',
        'Chuyển số tiền nộp thừa sang học kỳ khác của cùng sinh viên',
        'FR-008: Chuyển tiền thừa giữa các học kỳ',
        "Gửi POST /api/fee-records/1/transfers sang hồ sơ #{$targetId} với 500.000 VND",
        'HTTP 200/201, tạo cặp bút toán transfer_out và transfer_in',
        "HTTP {$res1['status']}, ok: " . json_encode($res1['body']['ok'] ?? false),
        $ok1
    );

    // FT-020: Rejection of transfer exceeding available overpayment
    $uuid2 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res2 = $client->request('POST', '/fee-records/1/transfers', [
        'source_fee_record_id' => 1,
        'target_fee_record_id' => $targetId,
        'amount'               => 999999999, // Exceeds excess
        'reason'               => 'Chuyển vượt quá tiền thừa',
        'request_id'           => $uuid2,
    ]);
    $ok2 = in_array($res2['status'], [400, 422], true);
    test_ft(
        'FT-020',
        'transfers',
        'Từ chối chuyển tiền khi số tiền chuyển vượt quá số nộp thừa khả dụng',
        'BR-012: Số tiền chuyển không vượt quá available overpaid excess',
        'Gửi yêu cầu chuyển 999.999.999 VND từ hồ sơ #1',
        'HTTP 400 hoặc 422 Rejection',
        "HTTP {$res2['status']}",
        $ok2
    );

    // FT-021: Rejection of transfer between two different students
    $uuid3 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $res3 = $client->request('POST', '/fee-records/1/transfers', [
        'source_fee_record_id' => 1, // Student 1
        'target_fee_record_id' => 2, // Student 2
        'amount'               => 100000,
        'reason'               => 'Chuyển tiền sang sinh viên khác',
        'request_id'           => $uuid3,
    ]);
    $ok3 = ($res3['status'] === 422) && (($res3['body']['error']['code'] ?? '') === 'DIFFERENT_STUDENTS');
    test_ft(
        'FT-021',
        'transfers',
        'Từ chối chuyển tiền giữa hai hồ sơ thuộc hai sinh viên khác nhau',
        'BR-013: Chỉ được chuyển tiền giữa các học kỳ của cùng một sinh viên',
        'Gửi chuyển tiền từ hồ sơ #1 (SV1) sang hồ sơ #2 (SV2)',
        'HTTP 422 Unprocessable Entity kèm mã DIFFERENT_STUDENTS',
        "HTTP {$res3['status']}, code: " . ($res3['body']['error']['code'] ?? 'N/A'),
        $ok3
    );
}
