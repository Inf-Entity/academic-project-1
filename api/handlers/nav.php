<?php
/**
 * Navigation State and Notification Badges API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_nav_routes(string $method, string $path): void {
    require_admin();
    $userId = (int)($_SESSION['user_id'] ?? 1);
    $db = get_db_connection();

    // 1. GET /api/nav/badges (Read-only single request count)
    if ($path === '/api/nav/badges' && $method === 'GET') {
        // Lấy last_seen_entry_id của người dùng hiện tại
        $stmt = $db->prepare("SELECT last_seen_entry_id FROM user_nav_state WHERE user_id = :uid AND nav_key = 'history'");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();

        if ($row !== false && $row['last_seen_entry_id'] !== null) {
            $lastSeenId = (int)$row['last_seen_entry_id'];
        } else {
            // Nếu chưa có trạng thái, baseline là MAX(id) hiện tại (không ghi vào DB trên GET)
            $maxStmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM ledger_entries");
            $lastSeenId = (int)$maxStmt->fetchColumn();
        }

        $feeSub = get_fee_summary_subquery();
        $sql = "SELECT 
                    (SELECT COUNT(*) FROM refund_requests WHERE status = 'pending') AS refunds,
                    (SELECT COUNT(*) FROM ledger_entries WHERE id > :last_seen) AS new_transactions,
                    COUNT(CASE WHEN v.total_paid > v.net_due AND COALESCE(v.pending_refunds_count, 0) > 0 THEN 1 END) AS pending_profiles,
                    COUNT(CASE WHEN v.total_paid > v.net_due AND (v.total_paid - v.net_due) > COALESCE(v.pending_refunds_sum, 0) THEN 1 END) AS overpaid_profiles
                FROM {$feeSub} v";

        $stmtCounts = $db->prepare($sql);
        $stmtCounts->execute(['last_seen' => $lastSeenId]);
        $counts = $stmtCounts->fetch();

        json_success([
            'refunds'           => (int)($counts['refunds'] ?? 0),
            'new_transactions'  => (int)($counts['new_transactions'] ?? 0),
            'pending_profiles'  => (int)($counts['pending_profiles'] ?? 0),
            'overpaid_profiles' => (int)($counts['overpaid_profiles'] ?? 0),
        ]);
    }

    // 2. POST /api/nav/seen (Mark nav_key as seen)
    if ($path === '/api/nav/seen' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['nav_key', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
            json_error('Mã yêu cầu (request_id) không hợp lệ (yêu cầu chuẩn UUID).', 'INVALID_REQUEST_ID', 400);
        }

        $navKey = trim((string)($input['nav_key'] ?? ''));
        if ($navKey === '') {
            json_error('nav_key không được để trống.', 'VALIDATION_ERROR', 400, [
                'nav_key' => 'nav_key không được để trống.'
            ]);
        }
        if ($navKey !== 'history') {
            json_error('nav_key không hợp lệ (hỗ trợ "history").', 'VALIDATION_ERROR', 400, [
                'nav_key' => 'nav_key không hợp lệ.'
            ]);
        }

        // Đặt last_seen_entry_id = MAX(id) của ledger_entries
        $maxStmt = $db->query("SELECT COALESCE(MAX(id), 0) FROM ledger_entries");
        $maxId = (int)$maxStmt->fetchColumn();

        $upsertStmt = $db->prepare(
            "INSERT INTO user_nav_state (user_id, nav_key, last_seen_entry_id, updated_at)
             VALUES (:uid, :navKey, :maxId, NOW())
             ON DUPLICATE KEY UPDATE 
                last_seen_entry_id = :maxIdUpdate,
                updated_at = NOW()"
        );
        $upsertStmt->execute([
            'uid'         => $userId,
            'navKey'      => $navKey,
            'maxId'       => $maxId,
            'maxIdUpdate' => $maxId,
        ]);

        json_success([
            'nav_key'            => $navKey,
            'last_seen_entry_id' => $maxId,
        ]);
    }

    json_error('Không tìm thấy tài nguyên yêu cầu.', 'ENDPOINT_NOT_FOUND', 404);
}
