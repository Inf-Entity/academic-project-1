<?php
/**
 * Refunds API Handler: Request, Complete, and Cancel
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_refunds_routes(string $method, string $path, array $matches): void {
    require_admin();

    // 1. POST /api/fee-records/{id}/refunds (Create Refund Request)
    if (preg_match('#^/api/fee-records/(\d+)/refunds$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['amount', 'reason', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
            json_error('Mã yêu cầu (request_id) không hợp lệ (yêu cầu chuẩn UUID).', 'INVALID_REQUEST_ID', 400);
        }

        $db = get_db_connection();
        $chkReq = $db->prepare("SELECT * FROM refund_requests WHERE request_id = :reqId");
        $chkReq->execute(['reqId' => $requestId]);
        $existing = $chkReq->fetch();
        if ($existing) {
            json_success($existing, 200);
        }

        $amount = parse_currency($input['amount'] ?? null);
        $reason = trim((string)($input['reason'] ?? ''));

        $errors = [];
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Số tiền yêu cầu hoàn phải là số nguyên dương hợp lệ.';
        }
        if ($reason === '' || !validate_string_length($reason, 1, 200)) {
            $errors['reason'] = 'Lý do hoàn tiền là bắt buộc và tối đa 200 ký tự.';
        }
        if (!empty($errors)) {
            json_error('Dữ liệu yêu cầu hoàn tiền không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);

        $result = db_transaction(function(PDO $pdo) use ($feeRecordId, $amount, $reason, $requestId, $userId) {
            $pdo->prepare("SELECT id FROM fee_records WHERE id = :id FOR UPDATE")->execute(['id' => $feeRecordId]);

            $sum = fetch_fee_record_summary($pdo, $feeRecordId);
            if (!$sum) {
                json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
            }

            $netDue = (int)$sum['net_due'];
            $totalPaid = (int)$sum['total_paid'];
            $pendingSum = (int)$sum['pending_refunds_sum'];

            $excess = max(0, $totalPaid - $netDue);
            $availableRefund = max(0, $excess - $pendingSum);

            if ($excess <= 0 || $amount > $availableRefund) {
                json_error('Số tiền yêu cầu hoàn vượt quá số tiền nộp thừa khả dụng (Khả dụng: ' . number_format($availableRefund, 0, ',', '.') . ' ₫).', 'EXCESS_EXCEEDED', 422);
            }

            $insStmt = $pdo->prepare(
                "INSERT INTO refund_requests (fee_record_id, amount, reason, status, request_id, created_by, created_at)
                 VALUES (:frId, :amount, :reason, 'pending', :reqId, :userId, NOW())"
            );

            try {
                $insStmt->execute([
                    'frId'    => $feeRecordId,
                    'amount'  => $amount,
                    'reason'  => $reason,
                    'reqId'   => $requestId,
                    'userId'  => $userId
                ]);
            } catch (PDOException $pe) {
                if ($pe->getCode() === '23000') {
                    $chk = $pdo->prepare("SELECT * FROM refund_requests WHERE request_id = :reqId");
                    $chk->execute(['reqId' => $requestId]);
                    $ex = $chk->fetch();
                    if ($ex) return $ex;
                }
                throw $pe;
            }

            $newId = (int)$pdo->lastInsertId();

            log_audit($userId, 'create_refund_request', 'refund_requests', (string)$newId, [
                'fee_record_id' => $feeRecordId,
                'amount'        => $amount,
                'reason'        => $reason,
                'request_id'    => $requestId
            ], $pdo);

            $res = $pdo->prepare("SELECT * FROM refund_requests WHERE id = :id");
            $res->execute(['id' => $newId]);
            return $res->fetch();
        }, $db);

        json_success($result, 201);
    }

    // 2. POST /api/refunds/{id}/complete (Complete Refund)
    if (preg_match('#^/api/refunds/(\d+)/complete$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $refundId = (int)$m[1];
        $userId = (int)($_SESSION['user_id'] ?? 1);
        $db = get_db_connection();

        $result = db_transaction(function(PDO $pdo) use ($refundId, $userId) {
            $rfStmt = $pdo->prepare("SELECT * FROM refund_requests WHERE id = :id FOR UPDATE");
            $rfStmt->execute(['id' => $refundId]);
            $refund = $rfStmt->fetch();

            if (!$refund) {
                json_error('Không tìm thấy yêu cầu hoàn tiền.', 'NOT_FOUND', 404);
            }

            if ($refund['status'] !== 'pending') {
                json_error("Chỉ yêu cầu đang ở trạng thái 'pending' mới có thể hoàn tất (Trạng thái hiện tại: '{$refund['status']}').", 'INVALID_STATUS', 409);
            }

            $feeRecordId = (int)$refund['fee_record_id'];
            $pdo->prepare("SELECT id FROM fee_records WHERE id = :id FOR UPDATE")->execute(['id' => $feeRecordId]);

            // Re-check total_paid to ensure refund does not make total_paid negative
            $sum = fetch_fee_record_summary($pdo, $feeRecordId);
            $currentTotalPaid = (int)($sum['total_paid'] ?? 0);

            if (($currentTotalPaid - (int)$refund['amount']) < 0) {
                json_error('Không thể hoàn tất hoàn tiền vì số dư đã nộp không đủ.', 'NEGATIVE_TOTAL_PAID', 422);
            }

            $upStmt = $pdo->prepare(
                "UPDATE refund_requests 
                 SET status = 'completed', completed_by = :userId, completed_at = NOW()
                 WHERE id = :id"
            );
            $upStmt->execute(['userId' => $userId, 'id' => $refundId]);

            log_audit($userId, 'complete_refund', 'refund_requests', (string)$refundId, [
                'fee_record_id' => $feeRecordId,
                'amount'        => $refund['amount']
            ], $pdo);

            $res = $pdo->prepare("SELECT * FROM refund_requests WHERE id = :id");
            $res->execute(['id' => $refundId]);
            return $res->fetch();
        }, $db);

        json_success($result);
    }

    // 3. POST /api/refunds/{id}/cancel (Cancel Refund)
    if (preg_match('#^/api/refunds/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $refundId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['cancel_reason']);

        $cancelReason = isset($input['cancel_reason']) ? trim((string)$input['cancel_reason']) : null;
        if ($cancelReason !== null && !validate_string_length($cancelReason, 0, 200)) {
            json_error('Lý do hủy tối đa 200 ký tự.', 'VALIDATION_ERROR', 400);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);
        $db = get_db_connection();

        $result = db_transaction(function(PDO $pdo) use ($refundId, $cancelReason, $userId) {
            $rfStmt = $pdo->prepare("SELECT * FROM refund_requests WHERE id = :id FOR UPDATE");
            $rfStmt->execute(['id' => $refundId]);
            $refund = $rfStmt->fetch();

            if (!$refund) {
                json_error('Không tìm thấy yêu cầu hoàn tiền.', 'NOT_FOUND', 404);
            }

            if ($refund['status'] !== 'pending') {
                json_error("Chỉ yêu cầu đang ở trạng thái 'pending' mới có thể hủy (Trạng thái hiện tại: '{$refund['status']}').", 'INVALID_STATUS', 409);
            }

            $upStmt = $pdo->prepare(
                "UPDATE refund_requests 
                 SET status = 'cancelled', cancelled_by = :userId, cancelled_at = NOW(), cancel_reason = :reason
                 WHERE id = :id"
            );
            $upStmt->execute([
                'userId' => $userId,
                'reason' => $cancelReason,
                'id'     => $refundId
            ]);

            log_audit($userId, 'cancel_refund', 'refund_requests', (string)$refundId, [
                'cancel_reason' => $cancelReason
            ], $pdo);

            $res = $pdo->prepare("SELECT * FROM refund_requests WHERE id = :id");
            $res->execute(['id' => $refundId]);
            return $res->fetch();
        }, $db);

        json_success($result);
    }
}
