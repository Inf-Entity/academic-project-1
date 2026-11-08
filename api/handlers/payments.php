<?php
/**
 * Payments and Reversals API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_payments_routes(string $method, string $path, array $matches): void {
    require_admin();

    // 1. POST /api/fee-records/{id}/payments
    if (preg_match('#^/api/fee-records/(\d+)/payments$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['amount', 'method', 'entry_date', 'note', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
            json_error('Mã yêu cầu (request_id) không hợp lệ (yêu cầu chuẩn UUID).', 'INVALID_REQUEST_ID', 400, [
                'request_id' => 'Yêu cầu mã định danh UUID hợp lệ.'
            ]);
        }

        // Idempotency check: nếu request_id đã tồn tại, trả lại bản ghi cũ
        $db = get_db_connection();
        $chkReq = $db->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqId");
        $chkReq->execute(['reqId' => $requestId]);
        $existingEntry = $chkReq->fetch();
        if ($existingEntry) {
            json_success($existingEntry, 200);
        }

        $amount = parse_currency($input['amount'] ?? null);
        $payMethod = trim((string)($input['method'] ?? ''));
        $note = isset($input['note']) ? trim((string)$input['note']) : null;
        if ($note === '') $note = null;

        $today = date('Y-m-d');
        $hasCustomDate = isset($input['entry_date']) && trim((string)$input['entry_date']) !== '';
        $entryDate = $hasCustomDate ? trim((string)$input['entry_date']) : $today;

        $errors = [];
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Số tiền thanh toán phải là số nguyên dương hợp lệ.';
        }
        if (!in_array($payMethod, ['Tiền mặt', 'Chuyển khoản', 'Thẻ'], true)) {
            $errors['method'] = "Phương thức thanh toán phải là 'Tiền mặt', 'Chuyển khoản' hoặc 'Thẻ'.";
        }
        if ($hasCustomDate) {
            $d = DateTime::createFromFormat('Y-m-d', $entryDate);
            if (!$d || $d->format('Y-m-d') !== $entryDate) {
                $errors['entry_date'] = 'Ngày thu không đúng định dạng YYYY-MM-DD.';
            } elseif ($entryDate > $today) {
                $errors['entry_date'] = 'Ngày thu không được lớn hơn ngày hiện tại của hệ thống.';
            }
        }
        if ($note !== null && !validate_string_length($note, 0, 255)) {
            $errors['note'] = 'Ghi chú tối đa 255 ký tự.';
        }

        if (!empty($errors)) {
            json_error('Dữ liệu thanh toán không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);

        // Transaction xử lý thanh toán với lock dòng và deadlock retry
        $result = db_transaction(function(PDO $pdo) use ($feeRecordId, $amount, $payMethod, $entryDate, $note, $requestId, $userId) {
            // 1. Lock dòng hồ sơ học phí
            $feeStmt = $pdo->prepare("SELECT id FROM fee_records WHERE id = :id FOR UPDATE");
            $feeStmt->execute(['id' => $feeRecordId]);
            if (!$feeStmt->fetch()) {
                json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
            }

            // 2. Đọc tổng số nợ và số đã nộp thực tế sau khi đã lock
            $summary = fetch_fee_record_summary($pdo, $feeRecordId);
            $netDue = (int)($summary['net_due'] ?? 0);
            $totalPaid = (int)($summary['total_paid'] ?? 0);

            // 3. Quy tắc từ chối nộp thừa
            if (($totalPaid + $amount) > $netDue) {
                json_error('Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.', 'OVERPAYMENT_REJECTED', 422);
            }

            // 4. Sinh số biên lai nguyên tử (PT-YYYYMMDD-NNNN)
            $pdo->prepare("INSERT INTO receipt_counters (day, last_no) VALUES (:today, 0) ON DUPLICATE KEY UPDATE day = day")
                ->execute(['today' => $entryDate]);

            $cntStmt = $pdo->prepare("SELECT last_no FROM receipt_counters WHERE day = :today FOR UPDATE");
            $cntStmt->execute(['today' => $entryDate]);
            $lastNo = (int)$cntStmt->fetchColumn();

            $nextNo = $lastNo + 1;
            $pdo->prepare("UPDATE receipt_counters SET last_no = :nextNo WHERE day = :today")
                ->execute(['nextNo' => $nextNo, 'today' => $entryDate]);

            $receiptNo = sprintf('PT-%s-%04d', str_replace('-', '', $entryDate), $nextNo);

            // 5. Ghi vào sổ cái ledger_entries
            $insStmt = $pdo->prepare(
                "INSERT INTO ledger_entries (
                    fee_record_id, entry_type, amount, method, entry_date, 
                    receipt_no, reverses_entry_id, reason, note, request_id, created_by, created_at
                ) VALUES (
                    :frId, 'payment', :amount, :method, :entryDate,
                    :receiptNo, NULL, NULL, :note, :reqId, :userId, NOW()
                )"
            );

            try {
                $insStmt->execute([
                    'frId'      => $feeRecordId,
                    'amount'    => $amount,
                    'method'    => $payMethod,
                    'entryDate' => $entryDate,
                    'receiptNo' => $receiptNo,
                    'note'      => $note,
                    'reqId'     => $requestId,
                    'userId'    => $userId
                ]);
            } catch (PDOException $pe) {
                // Nếu trùng request_id do concurrency race, trả lại bản ghi đã có
                if ($pe->getCode() === '23000') {
                    $chk = $pdo->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqId");
                    $chk->execute(['reqId' => $requestId]);
                    $existing = $chk->fetch();
                    if ($existing) {
                        return $existing;
                    }
                }
                throw $pe;
            }

            $newEntryId = (int)$pdo->lastInsertId();

            log_audit($userId, 'payment', 'ledger_entries', (string)$newEntryId, [
                'fee_record_id' => $feeRecordId,
                'amount'        => $amount,
                'method'        => $payMethod,
                'receipt_no'    => $receiptNo,
                'request_id'    => $requestId
            ], $pdo);

            $resStmt = $pdo->prepare("SELECT * FROM ledger_entries WHERE id = :id");
            $resStmt->execute(['id' => $newEntryId]);
            return $resStmt->fetch();
        }, $db);

        json_success($result, 201);
    }

    // 2. POST /api/ledger/{entryId}/reversal
    if (preg_match('#^/api/ledger/(\d+)/reversal$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $targetEntryId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['reason', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
            json_error('Mã yêu cầu (request_id) không hợp lệ (yêu cầu chuẩn UUID).', 'INVALID_REQUEST_ID', 400);
        }

        $db = get_db_connection();
        // Idempotency check: nếu request_id đã tồn tại, trả lại bản ghi cũ
        $chkReq = $db->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqId");
        $chkReq->execute(['reqId' => $requestId]);
        $existingEntry = $chkReq->fetch();
        if ($existingEntry) {
            json_success($existingEntry, 200);
        }

        $reason = trim((string)($input['reason'] ?? ''));
        if ($reason === '' || !validate_string_length($reason, 1, 200)) {
            json_error('Lý do đảo mục là bắt buộc và tối đa 200 ký tự.', 'VALIDATION_ERROR', 400, [
                'reason' => 'Lý do đảo mục không được để trống và tối đa 200 ký tự.'
            ]);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);

        $result = db_transaction(function(PDO $pdo) use ($targetEntryId, $reason, $requestId, $userId) {
            // Khóa bản ghi gốc cần đảo mục
            $sel = $pdo->prepare("SELECT * FROM ledger_entries WHERE id = :id FOR UPDATE");
            $sel->execute(['id' => $targetEntryId]);
            $target = $sel->fetch();

            if (!$target) {
                json_error('Không tìm thấy giao dịch sổ cái cần đảo mục.', 'NOT_FOUND', 404);
            }

            if ($target['entry_type'] !== 'payment') {
                json_error('Chỉ giao dịch thanh toán (payment) mới có thể thực hiện đảo mục.', 'INVALID_ENTRY_TYPE', 422);
            }

            // Kiểm tra xem giao dịch này đã bị đảo mục trước đó chưa
            $chkRev = $pdo->prepare("SELECT id FROM ledger_entries WHERE reverses_entry_id = :targetId FOR UPDATE");
            $chkRev->execute(['targetId' => $targetEntryId]);
            if ($chkRev->fetch()) {
                json_error('Giao dịch thanh toán này đã được đảo mục trước đó. Không thể đảo mục lần 2.', 'ALREADY_REVERSED', 409);
            }

            // Lock fee_record
            $feeRecordId = (int)$target['fee_record_id'];
            $pdo->prepare("SELECT id FROM fee_records WHERE id = :id FOR UPDATE")->execute(['id' => $feeRecordId]);

            // Ghi bản ghi đảo mục (Reversals receive no receipt number)
            $today = date('Y-m-d');
            $note = 'Đảo mục phiếu thu ' . ($target['receipt_no'] ?? '#' . $targetEntryId);

            $insStmt = $pdo->prepare(
                "INSERT INTO ledger_entries (
                    fee_record_id, entry_type, amount, method, entry_date,
                    receipt_no, reverses_entry_id, reason, note, request_id, created_by, created_at
                ) VALUES (
                    :frId, 'reversal', :amount, :method, :entryDate,
                    NULL, :targetId, :reason, :note, :reqId, :userId, NOW()
                )"
            );

            try {
                $insStmt->execute([
                    'frId'      => $feeRecordId,
                    'amount'    => $target['amount'],
                    'method'    => $target['method'],
                    'entryDate' => $today,
                    'targetId'  => $targetEntryId,
                    'reason'    => $reason,
                    'note'      => $note,
                    'reqId'     => $requestId,
                    'userId'    => $userId
                ]);
            } catch (PDOException $pe) {
                if ($pe->getCode() === '23000') {
                    $chk = $pdo->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqId");
                    $chk->execute(['reqId' => $requestId]);
                    $existing = $chk->fetch();
                    if ($existing) {
                        return $existing;
                    }
                }
                throw $pe;
            }

            $newId = (int)$pdo->lastInsertId();

            log_audit($userId, 'reversal', 'ledger_entries', (string)$newId, [
                'target_entry_id' => $targetEntryId,
                'amount'          => $target['amount'],
                'reason'          => $reason,
                'request_id'      => $requestId
            ], $pdo);

            $resStmt = $pdo->prepare("SELECT * FROM ledger_entries WHERE id = :id");
            $resStmt->execute(['id' => $newId]);
            return $resStmt->fetch();
        }, $db);

        json_success($result, 201);
    }
}
