<?php
/**
 * Student API Handlers: Semesters, Credit Registration, Debts, Payments, History, Password Change
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';
require_once dirname(__DIR__) . '/lib/calc.php';
require_once dirname(__DIR__) . '/lib/payment_gateway.php';

function handle_student_routes(string $method, string $path, array $matches): void {
    $db = get_db_connection();

    // 1. POST /api/student/password (Đổi mật khẩu - Cho phép khi must_change_password = 1)
    if ($path === '/api/student/password' && $method === 'POST') {
        $user = require_student(true);
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['current_password', 'new_password', 'new_password_confirmation', 'confirm_password']);

        $currentPw = (string)($input['current_password'] ?? '');
        $newPw = (string)($input['new_password'] ?? '');
        $confirmPw = (string)($input['new_password_confirmation'] ?? ($input['confirm_password'] ?? ''));

        $errors = [];
        if ($currentPw === '') {
            $errors['current_password'] = 'Vui lòng nhập mật khẩu hiện tại.';
        }
        if ($newPw === '') {
            $errors['new_password'] = 'Vui lòng nhập mật khẩu mới.';
        } elseif (strlen($newPw) < 8 || !preg_match('/[a-zA-Z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
            $errors['new_password'] = 'Mật khẩu mới phải có ít nhất 8 ký tự, bao gồm chữ cái và chữ số.';
        }
        if ($confirmPw === '') {
            $errors['new_password_confirmation'] = 'Vui lòng xác nhận mật khẩu mới.';
        } elseif ($newPw !== $confirmPw) {
            $errors['new_password_confirmation'] = 'Xác nhận mật khẩu mới không khớp.';
        }

        if (!empty($errors)) {
            json_error('Dữ liệu đổi mật khẩu không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra mật khẩu hiện tại
        $pwHash = (string)($user['password_hash'] ?? '');
        if ($pwHash === '' || !password_verify($currentPw, $pwHash)) {
            json_error('Mật khẩu hiện tại không chính xác.', 'INVALID_PASSWORD', 400, [
                'current_password' => 'Mật khẩu hiện tại không chính xác.'
            ]);
        }

        // Không cho phép tái sử dụng mật khẩu hiện tại / tạm thời
        if (password_verify($newPw, $pwHash)) {
            json_error('Mật khẩu mới không được trùng với mật khẩu hiện tại/tạm thời.', 'PASSWORD_REUSE', 400, [
                'new_password' => 'Mật khẩu mới không được trùng với mật khẩu hiện tại/tạm thời.'
            ]);
        }

        $newHash = password_hash($newPw, PASSWORD_DEFAULT);
        $up = $db->prepare("UPDATE users SET password_hash = :hash, must_change_password = 0, failed_logins = 0, locked_until = NULL WHERE id = :id");
        $up->execute(['hash' => $newHash, 'id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['must_change_password'] = 0;

        log_audit((int)$user['id'], 'change_password', 'users', (string)$user['id'], ['username' => $user['username']]);

        json_success(['message' => 'Đổi mật khẩu thành công.']);
    }

    // Các endpoint sinh viên còn lại đều kiểm tra must_change_password = 1 (trả về 403 PASSWORD_CHANGE_REQUIRED)
    $user = require_student(false);
    $studentId = (int)$user['student_id'];

    // 2. GET /api/student/semesters (Danh sách học kỳ mở đăng ký)
    if ($path === '/api/student/semesters' && $method === 'GET') {
        $settingsStmt = $db->query("SELECT min_credits_per_semester, max_credits_per_semester FROM student_settings WHERE id = 1 LIMIT 1");
        $settings = $settingsStmt->fetch() ?: ['min_credits_per_semester' => 1, 'max_credits_per_semester' => 30];
        $minCredits = (int)$settings['min_credits_per_semester'];
        $maxCredits = (int)$settings['max_credits_per_semester'];

        $today = date('Y-m-d');
        $semStmt = $db->prepare(
            "SELECT id, term_no, academic_year_start, registration_open, registration_close,
                    CONCAT('Học kỳ ', term_no, ' - Năm học ', academic_year_start, '-', academic_year_start + 1) AS label
             FROM semesters
             WHERE registration_open = 1 AND (registration_close IS NULL OR registration_close >= :today)
             ORDER BY academic_year_start DESC, term_no DESC"
        );
        $semStmt->execute(['today' => $today]);
        $semesters = $semStmt->fetchAll();

        $feeSub = get_fee_summary_subquery();
        $rateStmt = $db->prepare("SELECT price_per_credit FROM credit_rates WHERE semester_id = :semId LIMIT 1");
        $regStmt = $db->prepare(
            "SELECT v.*, fr.id, fr.registered_credits, fr.rate_snapshot, fr.gross_tuition
             FROM fee_records fr
             JOIN {$feeSub} v ON fr.id = v.fee_record_id
             WHERE fr.student_id = :stId AND fr.semester_id = :semId LIMIT 1"
        );

        $results = [];
        foreach ($semesters as $sem) {
            $semId = (int)$sem['id'];
            $rateStmt->execute(['semId' => $semId]);
            $rateRow = $rateStmt->fetch();
            $pricePerCredit = $rateRow ? (int)$rateRow['price_per_credit'] : null;

            $regStmt->execute(['stId' => $studentId, 'semId' => $semId]);
            $regRow = $regStmt->fetch();

            $currentReg = null;
            if ($regRow) {
                $statusInfo = derive_payment_status((int)$regRow['total_paid'], (int)$regRow['net_due']);
                $currentReg = [
                    'id'                 => (int)$regRow['id'],
                    'registered_credits' => (int)$regRow['registered_credits'],
                    'rate_snapshot'      => (int)$regRow['rate_snapshot'],
                    'gross_tuition'      => (int)$regRow['gross_tuition'],
                    'exemption_amount'   => (int)$regRow['exemption_amount'],
                    'net_due'            => (int)$regRow['net_due'],
                    'total_paid'         => (int)$regRow['total_paid'],
                    'outstanding'        => $statusInfo['outstanding'],
                    'status'             => $statusInfo['status_label'],
                    'is_overpaid'        => $statusInfo['is_overpaid'],
                    'can_edit'           => ((int)$regRow['total_paid'] === 0)
                ];
            }

            $results[] = [
                'id'                   => $semId,
                'term_no'              => (int)$sem['term_no'],
                'academic_year_start'  => (int)$sem['academic_year_start'],
                'label'                => $sem['label'],
                'registration_open'    => (int)$sem['registration_open'],
                'registration_close'   => $sem['registration_close'],
                'price_per_credit'     => $pricePerCredit,
                'min_credits'          => $minCredits,
                'max_credits'          => $maxCredits,
                'current_registration' => $currentReg
            ];
        }

        json_success($results);
    }

    // 3. POST /api/student/registrations (Đăng ký tín chỉ mới)
    if ($path === '/api/student/registrations' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['semester_id', 'credits', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '') {
            json_error('Vui lòng cung cấp mã yêu cầu (request_id).', 'INVALID_REQUEST_ID', 400, [
                'request_id' => 'Mã yêu cầu không được để trống.'
            ]);
        }

        $settingsStmt = $db->query("SELECT min_credits_per_semester, max_credits_per_semester FROM student_settings WHERE id = 1 LIMIT 1");
        $settings = $settingsStmt->fetch() ?: ['min_credits_per_semester' => 1, 'max_credits_per_semester' => 30];
        $minCredits = (int)$settings['min_credits_per_semester'];
        $maxCredits = (int)$settings['max_credits_per_semester'];

        $rawCredits = $input['credits'] ?? null;
        if (!is_int($rawCredits) && (!is_string($rawCredits) || !preg_match('/^-?\d+$/', trim($rawCredits)))) {
            json_error("Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}.", 'VALIDATION_ERROR', 400, [
                'credits' => "Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}."
            ]);
        }

        $credits = (int)$rawCredits;
        if ($credits < $minCredits || $credits > $maxCredits) {
            json_error("Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}.", 'VALIDATION_ERROR', 400, [
                'credits' => "Số tín chỉ phải từ {$minCredits} đến {$maxCredits}."
            ]);
        }

        $semesterId = filter_var($input['semester_id'] ?? null, FILTER_VALIDATE_INT);
        if ($semesterId === false || $semesterId <= 0) {
            json_error('Vui lòng chọn học kỳ hợp lệ.', 'VALIDATION_ERROR', 400, [
                'semester_id' => 'Học kỳ không hợp lệ.'
            ]);
        }

        // Kiểm tra học kỳ mở đăng ký
        $today = date('Y-m-d');
        $semStmt = $db->prepare("SELECT id, registration_open, registration_close FROM semesters WHERE id = :id");
        $semStmt->execute(['id' => $semesterId]);
        $sem = $semStmt->fetch();
        if (!$sem || (int)$sem['registration_open'] !== 1 || (!empty($sem['registration_close']) && $sem['registration_close'] < $today)) {
            json_error('Học kỳ này đã đóng đăng ký.', 'REGISTRATION_CLOSED', 422);
        }

        // Lấy định mức học phí
        $rateStmt = $db->prepare("SELECT price_per_credit FROM credit_rates WHERE semester_id = :semId");
        $rateStmt->execute(['semId' => $semesterId]);
        $rateRow = $rateStmt->fetch();
        if (!$rateRow) {
            json_error('Học kỳ này chưa có mức học phí.', 'RATE_NOT_FOUND', 422);
        }
        $rateSnapshot = (int)$rateRow['price_per_credit'];

        // Xử lý trong transaction nguyên tử
        $regResult = db_transaction(function(PDO $pdo) use ($studentId, $semesterId, $credits, $rateSnapshot, $requestId, $user) {
            $chk = $pdo->prepare("SELECT id FROM fee_records WHERE student_id = :stId AND semester_id = :semId FOR UPDATE");
            $chk->execute(['stId' => $studentId, 'semId' => $semesterId]);
            $existing = $chk->fetch();
            if ($existing) {
                // Idempotency: Kiểm tra request_id trùng lặp
                $chkAudit = $pdo->prepare("SELECT entity_id FROM audit_log WHERE user_id = :uid AND action = 'student_register' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.request_id')) = :reqId LIMIT 1");
                $chkAudit->execute(['uid' => $user['id'], 'reqId' => $requestId]);
                $matchedId = $chkAudit->fetchColumn();
                if ($matchedId && (int)$matchedId === (int)$existing['id']) {
                    $summary = fetch_fee_record_summary($pdo, (int)$existing['id']);
                    return ['status' => 200, 'data' => $summary];
                }
                json_error('Sinh viên này đã có hồ sơ học phí cho học kỳ này.', 'DUPLICATE_FEE_RECORD', 409, null, ['existing_id' => (int)$existing['id']]);
            }

            $gross = calc_gross($credits, $rateSnapshot);
            $ins = $pdo->prepare(
                "INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at)
                 VALUES (:stId, :semId, :credits, :snapshot, :gross, NOW(), NOW())"
            );
            $ins->execute([
                'stId'     => $studentId,
                'semId'    => $semesterId,
                'credits'  => $credits,
                'snapshot' => $rateSnapshot,
                'gross'    => $gross
            ]);
            $newId = (int)$pdo->lastInsertId();

            log_audit((int)$user['id'], 'student_register', 'fee_records', (string)$newId, [
                'request_id'         => $requestId,
                'semester_id'        => $semesterId,
                'registered_credits' => $credits,
                'rate_snapshot'      => $rateSnapshot,
                'gross_tuition'      => $gross
            ]);

            $summary = fetch_fee_record_summary($pdo, $newId);
            return ['status' => 201, 'data' => $summary];
        });
        json_success($regResult['data'], $regResult['status']);
    }

    // 4. PUT /api/student/registrations/{semesterId} (Điều chỉnh số tín chỉ đã đăng ký)
    if (preg_match('#^/api/student/registrations/(\d+)$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $semesterId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['credits', 'request_id']);

        $settingsStmt = $db->query("SELECT min_credits_per_semester, max_credits_per_semester FROM student_settings WHERE id = 1 LIMIT 1");
        $settings = $settingsStmt->fetch() ?: ['min_credits_per_semester' => 1, 'max_credits_per_semester' => 30];
        $minCredits = (int)$settings['min_credits_per_semester'];
        $maxCredits = (int)$settings['max_credits_per_semester'];

        $rawCredits = $input['credits'] ?? null;
        if (!is_int($rawCredits) && (!is_string($rawCredits) || !preg_match('/^-?\d+$/', trim($rawCredits)))) {
            json_error("Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}.", 'VALIDATION_ERROR', 400, [
                'credits' => "Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}."
            ]);
        }

        $credits = (int)$rawCredits;
        if ($credits < $minCredits || $credits > $maxCredits) {
            json_error("Số tín chỉ phải là số nguyên từ {$minCredits} đến {$maxCredits}.", 'VALIDATION_ERROR', 400, [
                'credits' => "Số tín chỉ phải từ {$minCredits} đến {$maxCredits}."
            ]);
        }

        $today = date('Y-m-d');
        $semStmt = $db->prepare("SELECT id, registration_open, registration_close FROM semesters WHERE id = :id");
        $semStmt->execute(['id' => $semesterId]);
        $sem = $semStmt->fetch();
        if (!$sem || (int)$sem['registration_open'] !== 1 || (!empty($sem['registration_close']) && $sem['registration_close'] < $today)) {
            json_error('Học kỳ này đã đóng đăng ký.', 'REGISTRATION_CLOSED', 422);
        }

        $updated = db_transaction(function(PDO $pdo) use ($studentId, $semesterId, $credits, $user) {
            $stmt = $pdo->prepare("SELECT * FROM fee_records WHERE semester_id = :semId AND student_id = :stId FOR UPDATE");
            $stmt->execute(['semId' => $semesterId, 'stId' => $studentId]);
            $rec = $stmt->fetch();
            if (!$rec) {
                json_error('Không tìm thấy đăng ký học phần cho học kỳ này.', 'NOT_FOUND', 404);
            }

            $summary = fetch_fee_record_summary($pdo, (int)$rec['id']);
            if ((int)$summary['total_paid'] > 0) {
                json_error('Đã có thanh toán, vui lòng liên hệ phòng đào tạo để điều chỉnh.', 'PAYMENT_EXISTS', 422);
            }

            $rateSnapshot = (int)$rec['rate_snapshot'];
            $newGross = calc_gross($credits, $rateSnapshot);

            $up = $pdo->prepare("UPDATE fee_records SET registered_credits = :credits, gross_tuition = :gross, updated_at = NOW() WHERE id = :id");
            $up->execute([
                'credits' => $credits,
                'gross'   => $newGross,
                'id'      => $rec['id']
            ]);

            log_audit((int)$user['id'], 'student_update_credits', 'fee_records', (string)$rec['id'], [
                'registered_credits' => $credits,
                'gross_tuition'      => $newGross
            ]);

            return fetch_fee_record_summary($pdo, (int)$rec['id']);
        });
        json_success($updated, 200);
    }

    // 5. POST /api/student/fee-records/{id}/payments (Thanh toán học phí trực tuyến)
    if (preg_match('#^/api/student/fee-records/(\d+)/payments$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['amount', 'method', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '') {
            json_error('Vui lòng cung cấp mã yêu cầu (request_id).', 'INVALID_REQUEST_ID', 400, [
                'request_id' => 'Mã yêu cầu không được để trống.'
            ]);
        }

        // Idempotency check trước khi gọi gateway
        $chkReq = $db->prepare(
            "SELECT l.*, fr.student_id 
             FROM ledger_entries l 
             JOIN fee_records fr ON l.fee_record_id = fr.id 
             WHERE l.request_id = :reqId"
        );
        $chkReq->execute(['reqId' => $requestId]);
        $existingEntry = $chkReq->fetch();
        if ($existingEntry) {
            if ((int)$existingEntry['student_id'] !== $studentId) {
                json_error('Không tìm thấy giao dịch.', 'NOT_FOUND', 404);
            }
            json_success($existingEntry, 200);
        }

        $rawAmount = $input['amount'] ?? null;
        if (!is_int($rawAmount) && (!is_string($rawAmount) || !preg_match('/^-?\d+$/', trim($rawAmount)))) {
            json_error('Số tiền phải là số nguyên dương.', 'VALIDATION_ERROR', 400, [
                'amount' => 'Số tiền phải là số nguyên dương.'
            ]);
        }
        $amount = (int)$rawAmount;
        if ($amount <= 0) {
            json_error('Số tiền phải là số nguyên dương.', 'VALIDATION_ERROR', 400, [
                'amount' => 'Số tiền phải là số nguyên dương.'
            ]);
        }

        $method = trim((string)($input['method'] ?? ''));
        if (!in_array($method, ['Chuyển khoản', 'Thẻ'], true)) {
            json_error("Phương thức thanh toán phải là 'Chuyển khoản' hoặc 'Thẻ'.", 'VALIDATION_ERROR', 400, [
                'method' => "Phương thức thanh toán phải là 'Chuyển khoản' hoặc 'Thẻ'."
            ]);
        }

        $entry = db_transaction(function(PDO $pdo) use ($feeRecordId, $studentId, $amount, $method, $requestId, $user) {
            // Lock dòng và xác thực chủ quyền sinh viên trong cùng một truy vấn
            $feeStmt = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id AND student_id = :stId FOR UPDATE");
            $feeStmt->execute(['id' => $feeRecordId, 'stId' => $studentId]);
            if (!$feeStmt->fetch()) {
                json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
            }

            // Kiểm tra lại request_id trong transaction nguyên tử (chống gọi trùng cổng khi có 2 request đồng thời)
            $chkReqInside = $pdo->prepare(
                "SELECT l.*, fr.student_id 
                 FROM ledger_entries l 
                 JOIN fee_records fr ON l.fee_record_id = fr.id 
                 WHERE l.request_id = :reqId"
            );
            $chkReqInside->execute(['reqId' => $requestId]);
            $existingEntryInside = $chkReqInside->fetch();
            if ($existingEntryInside) {
                if ((int)$existingEntryInside['student_id'] !== $studentId) {
                    json_error('Không tìm thấy giao dịch.', 'NOT_FOUND', 404);
                }
                return $existingEntryInside;
            }

            $summary = fetch_fee_record_summary($pdo, $feeRecordId);
            $netDue = (int)$summary['net_due'];
            $totalPaid = (int)$summary['total_paid'];

            // Hồ sơ nộp thừa -> chặn thanh toán
            if ($totalPaid > $netDue) {
                json_error('Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.', 'OVERPAID_BLOCKED', 422);
            }

            $outstanding = max(0, $netDue - $totalPaid);
            if ($amount > $outstanding) {
                json_error('Số tiền vượt quá số còn nợ.', 'OVERPAYMENT_REJECTED', 422);
            }

            // Gọi cổng thanh toán giả lập
            $gateway = new SimulatedGateway();
            $gwRes = $gateway->charge($amount, $method, [
                'fee_record_id' => $feeRecordId,
                'student_id'    => $studentId,
                'request_id'    => $requestId
            ]);

            if ($gwRes['status'] !== 'success') {
                error_log(sprintf("[%s] Payment gateway error: fee_record_id=%d, student_id=%d, request_id=%s", date('Y-m-d H:i:s'), $feeRecordId, $studentId, $requestId));
                json_error('Thanh toán chưa được xử lý. Vui lòng thử lại sau.', 'GATEWAY_ERROR', 502);
            }

            $gatewayRef = $gwRes['transaction_ref'];

            // Sinh số biên lai nguyên tử PT-YYYYMMDD-NNNN
            $today = date('Y-m-d');
            $pdo->prepare("INSERT INTO receipt_counters (day, last_no) VALUES (:today, 0) ON DUPLICATE KEY UPDATE day = day")
                ->execute(['today' => $today]);
            $pdo->prepare("UPDATE receipt_counters SET last_no = last_no + 1 WHERE day = :today")
                ->execute(['today' => $today]);
            $cntStmt = $pdo->prepare("SELECT last_no FROM receipt_counters WHERE day = :today");
            $cntStmt->execute(['today' => $today]);
            $lastNo = (int)$cntStmt->fetchColumn();
            $receiptNo = sprintf("PT-%s-%04d", date('Ymd'), $lastNo);

            $ins = $pdo->prepare(
                "INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, gateway_ref, request_id, created_by, note, created_at)
                 VALUES (:frId, 'payment', :amount, :method, :entryDate, :receiptNo, :gwRef, :reqId, :userId, 'Sinh viên thanh toán trực tuyến', NOW())"
            );
            $ins->execute([
                'frId'      => $feeRecordId,
                'amount'    => $amount,
                'method'    => $method,
                'entryDate' => $today,
                'receiptNo' => $receiptNo,
                'gwRef'     => $gatewayRef,
                'reqId'     => $requestId,
                'userId'    => $user['id'],
            ]);
            $entryId = (int)$pdo->lastInsertId();

            log_audit((int)$user['id'], 'student_payment', 'ledger_entries', (string)$entryId, [
                'receipt_no'  => $receiptNo,
                'amount'      => $amount,
                'method'      => $method,
                'gateway_ref' => $gatewayRef,
                'request_id'  => $requestId
            ]);

            return $pdo->query("SELECT * FROM ledger_entries WHERE id = {$entryId}")->fetch();
        });
        json_success($entry, 201);
    }

    // 6. GET /api/student/debts (Học phí chưa thanh toán & toàn bộ hồ sơ)
    if ($path === '/api/student/debts' && $method === 'GET') {
        $feeSub = get_fee_summary_subquery();
        $sql = "SELECT 
                    fr.id,
                    fr.semester_id,
                    fr.registered_credits,
                    fr.rate_snapshot,
                    fr.gross_tuition,
                    sem.term_no,
                    sem.academic_year_start,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label,
                    ex.type AS exemption_type,
                    ex.value AS exemption_value,
                    ex.reason AS exemption_reason,
                    v.net_due,
                    v.total_paid,
                    v.exemption_amount
                FROM fee_records fr
                JOIN semesters sem ON fr.semester_id = sem.id
                JOIN {$feeSub} v ON fr.id = v.fee_record_id
                LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id
                WHERE fr.student_id = :stId
                ORDER BY sem.academic_year_start DESC, sem.term_no DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute(['stId' => $studentId]);
        $rows = $stmt->fetchAll();

        $allRecords = [];
        $outstandingRecords = [];
        $totalOutstanding = 0;

        foreach ($rows as $r) {
            $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
            $outstanding = $statusInfo['outstanding'];
            $totalOutstanding += $outstanding;

            $item = [
                'id'                 => (int)$r['id'],
                'semester_id'        => (int)$r['semester_id'],
                'semester_label'     => $r['semester_label'],
                'registered_credits' => (int)$r['registered_credits'],
                'rate_snapshot'      => (int)$r['rate_snapshot'],
                'gross_tuition'      => (int)$r['gross_tuition'],
                'exemption_amount'   => (int)$r['exemption_amount'],
                'exemption_reason'   => $r['exemption_reason'] ?? '',
                'net_due'            => (int)$r['net_due'],
                'total_paid'         => (int)$r['total_paid'],
                'outstanding'        => $outstanding,
                'status'             => $statusInfo['status_label'],
                'is_overpaid'        => $statusInfo['is_overpaid'],
                'overpaid_amount'    => $statusInfo['overpaid_amount'],
                'warning'            => $statusInfo['is_overpaid'] ? 'Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.' : null
            ];

            $allRecords[] = $item;
            if ($outstanding > 0) {
                $outstandingRecords[] = $item;
            }
        }

        json_success([
            'total_outstanding'   => $totalOutstanding,
            'outstanding_records' => $outstandingRecords,
            'all_records'         => $allRecords
        ]);
    }

    // 7. GET /api/student/history (Lịch sử thanh toán & biến động số dư)
    if ($path === '/api/student/history' && $method === 'GET') {
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $typeFilter = trim((string)($_GET['type'] ?? ''));

        $parsedFrom = null;
        if ($from !== '') {
            $parsedFrom = parse_and_validate_date($from);
            if ($parsedFrom === false) {
                json_error('Từ ngày không hợp lệ.', 'VALIDATION_ERROR', 400, ['from' => 'Từ ngày không hợp lệ.']);
            }
        }

        $parsedTo = null;
        if ($to !== '') {
            $parsedTo = parse_and_validate_date($to);
            if ($parsedTo === false) {
                json_error('Đến ngày không hợp lệ.', 'VALIDATION_ERROR', 400, ['to' => 'Đến ngày không hợp lệ.']);
            }
        }

        if ($parsedFrom !== null && $parsedTo !== null) {
            if ($parsedTo < $parsedFrom) {
                json_error('Đến ngày không được nhỏ hơn từ ngày.', 'VALIDATION_ERROR', 400, [
                    'to' => 'Đến ngày không được nhỏ hơn từ ngày.'
                ]);
            }
            $diffSec = strtotime($parsedTo) - strtotime($parsedFrom);
            if ($diffSec > (5 * 365.25 * 86400)) {
                json_error('Khoảng thời gian tra cứu không được vượt quá 5 năm.', 'VALIDATION_ERROR', 400, [
                    'to' => 'Khoảng thời gian tra cứu không được vượt quá 5 năm.'
                ]);
            }
        }

        $feeSub = get_fee_summary_subquery();

        // 1. Lấy tất cả ledger_entries của sinh viên
        $ledgerSql = "SELECT 
                        l.id,
                        l.fee_record_id,
                        l.entry_type,
                        l.amount,
                        l.method,
                        l.entry_date,
                        l.receipt_no,
                        l.gateway_ref,
                        l.reason,
                        l.note,
                        l.created_at,
                        sem.id AS semester_id,
                        CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label,
                        v.net_due
                      FROM ledger_entries l
                      JOIN fee_records fr ON l.fee_record_id = fr.id
                      JOIN semesters sem ON fr.semester_id = sem.id
                      JOIN {$feeSub} v ON fr.id = v.fee_record_id
                      WHERE fr.student_id = :stId";
        $lStmt = $db->prepare($ledgerSql);
        $lStmt->execute(['stId' => $studentId]);
        $rawLedger = $lStmt->fetchAll();

        // 2. Lấy các yêu cầu hoàn tiền đã hoàn tất (completed)
        $refundSql = "SELECT 
                        rf.id,
                        rf.fee_record_id,
                        'refund' AS entry_type,
                        rf.amount,
                        'Chuyển khoản' AS method,
                        DATE(rf.completed_at) AS entry_date,
                        rf.request_id AS receipt_no,
                        NULL AS gateway_ref,
                        rf.reason,
                        'Hoàn trả tiền thừa' AS note,
                        rf.completed_at AS created_at,
                        sem.id AS semester_id,
                        CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label,
                        v.net_due
                      FROM refund_requests rf
                      JOIN fee_records fr ON rf.fee_record_id = fr.id
                      JOIN semesters sem ON fr.semester_id = sem.id
                      JOIN {$feeSub} v ON fr.id = v.fee_record_id
                      WHERE fr.student_id = :stId AND rf.status = 'completed'";
        $rStmt = $db->prepare($refundSql);
        $rStmt->execute(['stId' => $studentId]);
        $rawRefunds = $rStmt->fetchAll();

        // Gom tất cả giao dịch hoàn tất để tính running balance theo từng học kỳ
        $combined = array_merge($rawLedger, $rawRefunds);

        // Nhóm theo fee_record_id để tính running balance tuần tự từ đầu đến cuối
        $grouped = [];
        foreach ($combined as $item) {
            $frId = (int)$item['fee_record_id'];
            $grouped[$frId][] = $item;
        }

        $typeLabels = [
            'payment'      => 'Thanh toán',
            'reversal'     => 'Đảo bút toán',
            'transfer_in'  => 'Nhận chuyển khoản',
            'transfer_out' => 'Chuyển đi',
            'refund'       => 'Hoàn tiền',
        ];

        $calculatedEntries = [];
        foreach ($grouped as $frId => $txList) {
            // Sắp xếp thứ tự thời gian tăng dần để tích lũy
            usort($txList, function($a, $b) {
                $c = strcmp($a['entry_date'], $b['entry_date']);
                if ($c !== 0) return $c;
                $c2 = strcmp((string)$a['created_at'], (string)$b['created_at']);
                if ($c2 !== 0) return $c2;
                return $a['id'] <=> $b['id'];
            });

            $netDue = (int)($txList[0]['net_due'] ?? 0);
            $cumPaid = 0;

            foreach ($txList as $tx) {
                $type = $tx['entry_type'];
                $amt = (int)$tx['amount'];

                if ($type === 'payment' || $type === 'transfer_in') {
                    $cumPaid += $amt;
                } elseif ($type === 'reversal' || $type === 'transfer_out' || $type === 'refund') {
                    $cumPaid -= $amt;
                }

                $balance = max(0, $netDue - $cumPaid);

                $tx['amount'] = $amt;
                $tx['type'] = $type;
                $tx['type_label'] = $typeLabels[$type] ?? $type;
                $tx['entry_type_label'] = $typeLabels[$type] ?? $type;
                $tx['running_balance'] = $balance;
                $tx['remaining_balance'] = $balance;
                $calculatedEntries[] = $tx;
            }
        }

        // Lọc theo khoảng ngày và loại nếu có
        $filtered = array_filter($calculatedEntries, function($entry) use ($parsedFrom, $parsedTo, $typeFilter) {
            if ($parsedFrom !== null && $entry['entry_date'] < $parsedFrom) {
                return false;
            }
            if ($parsedTo !== null && $entry['entry_date'] > $parsedTo) {
                return false;
            }
            if ($typeFilter !== '' && $typeFilter !== 'ALL') {
                $tLower = mb_strtolower($typeFilter, 'UTF-8');
                if (mb_strtolower($entry['entry_type'], 'UTF-8') !== $tLower && mb_strtolower($entry['entry_type_label'], 'UTF-8') !== $tLower) {
                    return false;
                }
            }
            return true;
        });

        // Sắp xếp giảm dần theo ngày và thời gian tạo để hiển thị mới nhất lên đầu
        usort($filtered, function($a, $b) {
            $c = strcmp($b['entry_date'], $a['entry_date']);
            if ($c !== 0) return $c;
            $c2 = strcmp((string)$b['created_at'], (string)$a['created_at']);
            if ($c2 !== 0) return $c2;
            return $b['id'] <=> $a['id'];
        });

        // 3. Lấy danh sách yêu cầu hoàn tiền đang chờ duyệt (pending)
        $pendingSql = "SELECT 
                         rf.id,
                         rf.fee_record_id,
                         rf.amount,
                         rf.reason,
                         rf.status,
                         'Yêu cầu hoàn tiền đang chờ duyệt' AS status_label,
                         rf.created_at,
                         CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
                       FROM refund_requests rf
                       JOIN fee_records fr ON rf.fee_record_id = fr.id
                       JOIN semesters sem ON fr.semester_id = sem.id
                       WHERE fr.student_id = :stId AND rf.status = 'pending'
                       ORDER BY rf.created_at DESC";
        $pStmt = $db->prepare($pendingSql);
        $pStmt->execute(['stId' => $studentId]);
        $pendingRefunds = $pStmt->fetchAll();

        json_success([
            'entries'         => array_values($filtered),
            'pending_refunds' => $pendingRefunds
        ]);
    }

    json_error('Không tìm thấy tài nguyên yêu cầu.', 'ENDPOINT_NOT_FOUND', 404);
}
