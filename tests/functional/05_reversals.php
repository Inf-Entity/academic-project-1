<?php
/**
 * Module 05: Reversals (Bút toán đảo mục / Hủy giao dịch)
 */

declare(strict_types=1);

function run_ft_reversals(ApiTestClient $client, PDO $pdo): void {
    suite_header("Functional Module 05: Reversals (Bút toán đảo mục)");
    $client->login('admin', 'Admin@123');

    // Tìm một giao dịch payment chưa bị đảo mục
    $entry = $pdo->query("SELECT id FROM ledger_entries WHERE entry_type = 'payment' AND reverses_entry_id IS NULL AND id NOT IN (SELECT reverses_entry_id FROM ledger_entries WHERE reverses_entry_id IS NOT NULL) ORDER BY id DESC LIMIT 1")->fetch();
    $targetId = (int)($entry['id'] ?? 0);

    $uuid1 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $uuid2 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    // FT-013: Admin reverses a valid ledger payment entry
    $res1 = $client->request('POST', "/ledger/{$targetId}/reversal", [
        'reason'     => 'Thu nhầm tài khoản, thực hiện đảo mục',
        'request_id' => $uuid1,
    ]);
    $ok1 = in_array($res1['status'], [200, 201], true) && !empty($res1['body']['ok']);
    test_ft(
        'FT-013',
        'reversals',
        'Admin thực hiện bút toán đảo mục cho giao dịch nộp tiền hợp lệ',
        'FR-005: Đảo mục giao dịch nộp tiền sai sót',
        "Gửi POST /api/ledger/{$targetId}/reversal kèm lý do và request_id",
        'HTTP 200/201, tạo thành công dòng reversal liên kết reverses_entry_id',
        "HTTP {$res1['status']}, ok: " . json_encode($res1['body']['ok'] ?? false),
        $ok1
    );

    // FT-014: Rejection of duplicate reversal on the same entry
    $res2 = $client->request('POST', "/ledger/{$targetId}/reversal", [
        'reason'     => 'Đảo mục lần 2',
        'request_id' => $uuid2,
    ]);
    $ok2 = ($res2['status'] === 409);
    test_ft(
        'FT-014',
        'reversals',
        'Từ chối đảo mục lần thứ 2 trên cùng một giao dịch với HTTP 409 Conflict',
        'BR-009: Mỗi giao dịch chỉ được đảo mục tối đa 1 lần duy nhất',
        "Gửi lại POST /api/ledger/{$targetId}/reversal với request_id mới",
        'HTTP 409 Conflict',
        "HTTP {$res2['status']}, error: " . ($res2['body']['error']['message'] ?? 'N/A'),
        $ok2
    );

    // FT-015: Rejection of reversing a reversal entry
    $uuid3 = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $revEntry = $pdo->query("SELECT id FROM ledger_entries WHERE entry_type = 'reversal' ORDER BY id DESC LIMIT 1")->fetch();
    $revId = (int)($revEntry['id'] ?? 0);
    $res3 = $client->request('POST', "/ledger/{$revId}/reversal", [
        'reason'     => 'Thử đảo mục của bút toán đảo mục',
        'request_id' => $uuid3,
    ]);
    $ok3 = in_array($res3['status'], [400, 409, 422], true);
    test_ft(
        'FT-015',
        'reversals',
        'Từ chối đảo mục một bút toán đảo mục (reversal of reversal)',
        'BR-010: Không cho phép đảo mục một bút toán đảo mục',
        "Gửi POST /api/ledger/{$revId}/reversal",
        'HTTP 400/409/422 Rejection',
        "HTTP {$res3['status']}",
        $ok3
    );
}
