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
require_once dirname(__DIR__) . '/lib/payment.php';

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

        // Payment method mapping:
        // "Mã QR" stored as 'Mã QR'
        // "Thẻ Visa/Mastercard" stored as 'Thẻ Visa/Mastercard'
        // Legacy: 'Chuyển khoản' and 'Thẻ' kept for backward compatibility with existing tests
        $method = trim((string)($input['method'] ?? ''));
        if (!in_array($method, ['Chuyển khoản', 'Thẻ', 'Mã QR', 'Thẻ Visa/Mastercard'], true)) {
            json_error("Phương thức thanh toán phải là 'Chuyển khoản', 'Thẻ', 'Mã QR' hoặc 'Thẻ Visa/Mastercard'.", 'VALIDATION_ERROR', 400, [
                'method' => "Phương thức thanh toán không hợp lệ."
            ]);
        }

        $entry = db_transaction(function(PDO $pdo) use ($feeRecordId, $studentId, $amount, $method, $requestId, $user) {
            // Lock dòng và xác thực chủ quyền sinh viên trong cùng một truy vấn
            $feeStmt = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id AND student_id = :stId FOR UPDATE");
            $feeStmt->execute(['id' => $feeRecordId, 'stId' => $studentId]);
            if (!$feeStmt->fetch()) {
                json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
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

            return execute_payment_transaction(
                $pdo,
                $feeRecordId,
                $amount,
                $method,
                date('Y-m-d'),
                'Sinh viên thanh toán trực tuyến',
                $requestId,
                (int)$user['id'],
                $studentId,
                $gatewayRef,
                'student_payment'
            );
        });
        json_success($entry, 201);
    }

    // 5a. POST /api/student/fee-records/{id}/payment-sessions (Khởi tạo phiên thanh toán Mã QR)
    if (preg_match('#^/api/student/fee-records/(\d+)/payment-sessions$#', $path, $m) && $method === 'POST') {
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

        // Mapping: "Mã QR" stored as 'Mã QR'
        $methodVal = trim((string)($input['method'] ?? ''));
        if ($methodVal !== 'Mã QR') {
            json_error("Phương thức thanh toán phải là 'Mã QR'.", 'VALIDATION_ERROR', 400, [
                'method' => "Phương thức thanh toán phải là 'Mã QR'."
            ]);
        }

        $feeStmt = $db->prepare("SELECT id, student_id FROM fee_records WHERE id = :id AND student_id = :stId");
        $feeStmt->execute(['id' => $feeRecordId, 'stId' => $studentId]);
        if (!$feeStmt->fetch()) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }

        $summary = fetch_fee_record_summary($db, $feeRecordId);
        $netDue = (int)($summary['net_due'] ?? 0);
        $totalPaid = (int)($summary['total_paid'] ?? 0);
        if ($totalPaid > $netDue) {
            json_error('Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.', 'OVERPAID_BLOCKED', 422);
        }
        $outstanding = max(0, $netDue - $totalPaid);
        if ($amount > $outstanding) {
            json_error('Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.', 'OVERPAYMENT_REJECTED', 422);
        }

        // Idempotency check trên request_id
        $chkSess = $db->prepare("SELECT * FROM payment_sessions WHERE request_id = :reqId");
        $chkSess->execute(['reqId' => $requestId]);
        $existingSess = $chkSess->fetch();
        if ($existingSess) {
            if ((int)$existingSess['student_id'] !== $studentId) {
                json_error('Không tìm thấy phiên thanh toán.', 'NOT_FOUND', 404);
            }
            if ($existingSess['status'] === 'pending' && strtotime($existingSess['expires_at']) <= time()) {
                $db->prepare("UPDATE payment_sessions SET status = 'expired' WHERE id = :id")->execute(['id' => $existingSess['id']]);
                $existingSess['status'] = 'expired';
            }
            $payload = sprintf(
                "TUITION|SESSION=%d|FEE=%d|AMT=%d|EXP=%s",
                (int)$existingSess['id'],
                (int)$existingSess['fee_record_id'],
                (int)$existingSess['amount'],
                $existingSess['expires_at']
            );

            json_success([
                'session_id'         => (int)$existingSess['id'],
                'fee_record_id'      => (int)$existingSess['fee_record_id'],
                'amount'             => (int)$existingSess['amount'],
                'method'             => $existingSess['method'],
                'status'             => $existingSess['status'],
                'payload'            => $payload,
                'qr_data'            => $payload,
                'expires_at'         => $existingSess['expires_at'],
                'simulation_enabled' => is_simulation_enabled()
            ], 200);
        }

        // Hủy tất cả các phiên pending trước đó của cùng sinh viên và hồ sơ học phí
        $cancelPrev = $db->prepare(
            "UPDATE payment_sessions 
             SET status = 'cancelled' 
             WHERE student_id = :stId AND fee_record_id = :frId AND status = 'pending'"
        );
        $cancelPrev->execute(['stId' => $studentId, 'frId' => $feeRecordId]);

        // Thời hạn 5 phút theo giờ server
        $expiresAt = date('Y-m-d H:i:s', time() + 5 * 60);
        $ins = $db->prepare(
            "INSERT INTO payment_sessions (student_id, fee_record_id, status, amount, method, request_id, expires_at, created_at)
             VALUES (:stId, :frId, 'pending', :amount, 'Mã QR', :reqId, :expiresAt, NOW())"
        );
        $ins->execute([
            'stId'      => $studentId,
            'frId'      => $feeRecordId,
            'amount'    => $amount,
            'reqId'     => $requestId,
            'expiresAt' => $expiresAt
        ]);
        $newSessId = (int)$db->lastInsertId();

        $payload = sprintf(
            "TUITION|SESSION=%d|FEE=%d|AMT=%d|EXP=%s",
            $newSessId,
            $feeRecordId,
            $amount,
            $expiresAt
        );

        json_success([
            'session_id'         => $newSessId,
            'fee_record_id'      => $feeRecordId,
            'amount'             => $amount,
            'method'             => 'Mã QR',
            'status'             => 'pending',
            'payload'            => $payload,
            'qr_data'            => $payload,
            'expires_at'         => $expiresAt,
            'simulation_enabled' => is_simulation_enabled()
        ], 201);
    }

    // 5b. GET /api/student/payment-sessions/{id} (Kiểm tra trạng thái phiên thanh toán)
    if (preg_match('#^/api/student/payment-sessions/(\d+)$#', $path, $m) && $method === 'GET') {
        $sessId = (int)$m[1];
        $stmt = $db->prepare("SELECT * FROM payment_sessions WHERE id = :id AND student_id = :stId");
        $stmt->execute(['id' => $sessId, 'stId' => $studentId]);
        $sess = $stmt->fetch();
        if (!$sess) {
            json_error('Không tìm thấy phiên thanh toán.', 'NOT_FOUND', 404);
        }

        $now = time();
        $expTime = strtotime($sess['expires_at']);
        if ($sess['status'] === 'pending' && $expTime <= $now) {
            $db->prepare("UPDATE payment_sessions SET status = 'expired' WHERE id = :id")->execute(['id' => $sessId]);
            $sess['status'] = 'expired';
        }
        $remainingSeconds = max(0, $expTime - $now);

        json_success([
            'session_id'         => (int)$sess['id'],
            'fee_record_id'      => (int)$sess['fee_record_id'],
            'status'             => $sess['status'],
            'amount'             => (int)$sess['amount'],
            'method'             => $sess['method'],
            'expires_at'         => $sess['expires_at'],
            'confirmed_at'       => $sess['confirmed_at'],
            'remaining_seconds'  => $remainingSeconds,
            'simulation_enabled' => is_simulation_enabled()
        ], 200);
    }

    // 5c. POST /api/student/payment-sessions/{id}/simulate-confirm (Mô phỏng xác nhận thanh toán QR)
    if (preg_match('#^/api/student/payment-sessions/(\d+)/simulate-confirm$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $sessId = (int)$m[1];

        if (!is_simulation_enabled()) {
            json_error('Chức năng mô phỏng thanh toán đã bị vô hiệu hóa.', 'NOT_FOUND', 404);
        }

        $confirmRes = db_transaction(function(PDO $pdo) use ($sessId, $studentId, $user) {
            $stmt = $pdo->prepare("SELECT * FROM payment_sessions WHERE id = :id AND student_id = :stId FOR UPDATE");
            $stmt->execute(['id' => $sessId, 'stId' => $studentId]);
            $sess = $stmt->fetch();
            if (!$sess) {
                json_error('Không tìm thấy phiên thanh toán.', 'NOT_FOUND', 404);
            }

            if ($sess['status'] === 'confirmed') {
                json_error('Phiên thanh toán đã được xác nhận trước đó.', 'ALREADY_CONFIRMED', 409);
            }

            if ($sess['status'] === 'cancelled') {
                json_error('Phiên thanh toán đã bị hủy.', 'SESSION_CANCELLED', 422);
            }

            if ($sess['status'] === 'expired' || strtotime($sess['expires_at']) <= time()) {
                $pdo->prepare("UPDATE payment_sessions SET status = 'expired' WHERE id = :id")->execute(['id' => $sessId]);
                $pdo->commit();
                json_error('Mã QR đã hết hạn. Vui lòng tạo mã mới.', 'SESSION_EXPIRED', 422);
            }

            // Kiểm tra số nợ thực tế của hồ sơ
            $summary = fetch_fee_record_summary($pdo, (int)$sess['fee_record_id']);
            $netDue = (int)($summary['net_due'] ?? 0);
            $totalPaid = (int)($summary['total_paid'] ?? 0);
            $outstanding = max(0, $netDue - $totalPaid);
            $amount = (int)$sess['amount'];

            if ($totalPaid > $netDue || $amount > $outstanding) {
                $pdo->prepare("UPDATE payment_sessions SET status = 'cancelled' WHERE id = :id")->execute(['id' => $sessId]);
                $pdo->commit();
                json_error('Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.', 'OVERPAYMENT_REJECTED', 422);
            }

            // "Mã QR" stored as 'Mã QR'
            $entry = execute_payment_transaction(
                $pdo,
                (int)$sess['fee_record_id'],
                $amount,
                'Mã QR',
                date('Y-m-d'),
                'Sinh viên thanh toán quét mã QR (mô phỏng)',
                $sess['request_id'],
                (int)$user['id'],
                $studentId,
                'QR-SIM-' . $sess['id'],
                'student_payment'
            );

            $pdo->prepare("UPDATE payment_sessions SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id")->execute(['id' => $sessId]);

            $sess['status'] = 'confirmed';
            $sess['confirmed_at'] = date('Y-m-d H:i:s');

            return ['session' => $sess, 'ledger_entry' => $entry];
        });

        json_success($confirmRes, 200);
    }

    // 5d. POST /api/student/payment-sessions/{id}/cancel (Hủy phiên thanh toán QR)
    if (preg_match('#^/api/student/payment-sessions/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $sessId = (int)$m[1];

        $cancelRes = db_transaction(function(PDO $pdo) use ($sessId, $studentId) {
            $stmt = $pdo->prepare("SELECT * FROM payment_sessions WHERE id = :id AND student_id = :stId FOR UPDATE");
            $stmt->execute(['id' => $sessId, 'stId' => $studentId]);
            $sess = $stmt->fetch();
            if (!$sess) {
                json_error('Không tìm thấy phiên thanh toán.', 'NOT_FOUND', 404);
            }

            if ($sess['status'] === 'confirmed') {
                json_error('Không thể hủy phiên thanh toán đã hoàn thành.', 'CANNOT_CANCEL', 422);
            }

            if ($sess['status'] !== 'cancelled') {
                $pdo->prepare("UPDATE payment_sessions SET status = 'cancelled' WHERE id = :id")->execute(['id' => $sessId]);
            }

            return ['message' => 'Phiên thanh toán đã được hủy thành công.'];
        });

        json_success($cancelRes, 200);
    }

    // 5e. POST /api/student/fee-records/{id}/card-payments (Thanh toán Thẻ Visa/Mastercard trực tuyến)
    if (preg_match('#^/api/student/fee-records/(\d+)/card-payments$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['amount', 'card_number', 'cardholder_name', 'expiry', 'cvv', 'request_id']);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '') {
            json_error('Vui lòng cung cấp mã yêu cầu (request_id).', 'INVALID_REQUEST_ID', 400, [
                'request_id' => 'Mã yêu cầu không được để trống.'
            ]);
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

        // Kiểm tra hợp lệ các trường thẻ
        $errors = [];
        $rawCard = (string)($input['card_number'] ?? '');
        $cleanCard = preg_replace('/[\s-]+/', '', $rawCard);
        if ($cleanCard === '' || !ctype_digit($cleanCard) || strlen($cleanCard) < 13 || strlen($cleanCard) > 19 || !validate_luhn($cleanCard)) {
            $errors['card_number'] = 'Số thẻ không hợp lệ.';
        }

        $name = (string)($input['cardholder_name'] ?? '');
        if (!validate_cardholder_name($name)) {
            $errors['cardholder_name'] = 'Tên chủ thẻ không hợp lệ.';
        }

        $expiry = (string)($input['expiry'] ?? '');
        if (!validate_card_expiry($expiry)) {
            $errors['expiry'] = 'Ngày hết hạn không hợp lệ hoặc thẻ đã hết hạn.';
        }

        $cvv = (string)($input['cvv'] ?? '');
        if (!validate_card_cvv($cvv)) {
            $errors['cvv'] = 'Mã CVV phải gồm 3 hoặc 4 chữ số.';
        }

        if (!empty($errors)) {
            unset($rawCard, $cleanCard, $cvv, $expiry, $input['card_number'], $input['cvv'], $input['expiry']);
            json_error('Thông tin thẻ không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra quyền sở hữu hồ sơ học phí
        $feeStmt = $db->prepare("SELECT id, student_id FROM fee_records WHERE id = :id AND student_id = :stId");
        $feeStmt->execute(['id' => $feeRecordId, 'stId' => $studentId]);
        if (!$feeStmt->fetch()) {
            unset($rawCard, $cleanCard, $cvv, $expiry, $input);
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }

        // Idempotency check trước khi gọi cổng thanh toán
        $chkReq = $db->prepare(
            "SELECT l.*, fr.student_id 
             FROM ledger_entries l 
             JOIN fee_records fr ON l.fee_record_id = fr.id 
             WHERE l.request_id = :reqId"
        );
        $chkReq->execute(['reqId' => $requestId]);
        $existingEntry = $chkReq->fetch();
        if ($existingEntry) {
            unset($rawCard, $cleanCard, $cvv, $expiry, $input);
            if ((int)$existingEntry['student_id'] !== $studentId) {
                json_error('Không tìm thấy giao dịch.', 'NOT_FOUND', 404);
            }
            json_success($existingEntry, 200);
        }

        $summary = fetch_fee_record_summary($db, $feeRecordId);
        $netDue = (int)($summary['net_due'] ?? 0);
        $totalPaid = (int)($summary['total_paid'] ?? 0);
        if ($totalPaid > $netDue) {
            unset($rawCard, $cleanCard, $cvv, $expiry, $input);
            json_error('Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.', 'OVERPAID_BLOCKED', 422);
        }
        $outstanding = max(0, $netDue - $totalPaid);
        if ($amount > $outstanding) {
            unset($rawCard, $cleanCard, $cvv, $expiry, $input);
            json_error('Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.', 'OVERPAYMENT_REJECTED', 422);
        }

        // Gọi cổng thanh toán thẻ giả lập (SimulatedCardGateway)
        // "Thẻ Visa/Mastercard" stored as 'Thẻ Visa/Mastercard'
        $gateway = new SimulatedCardGateway();
        $gwRes = $gateway->charge($amount, 'Thẻ Visa/Mastercard', ['card_number' => $cleanCard]);

        // Xóa thông tin nhạy cảm khỏi bộ nhớ ngay lập tức
        unset($rawCard, $cleanCard, $cvv, $expiry, $input['card_number'], $input['cvv'], $input['expiry']);

        if ($gwRes['status'] === 'timeout') {
            json_error('Thanh toán chưa được xử lý. Vui lòng thử lại sau.', 'GATEWAY_TIMEOUT', 502);
        }
        if ($gwRes['status'] === 'declined') {
            json_error('Thẻ bị từ chối. Vui lòng kiểm tra thông tin hoặc dùng thẻ khác.', 'CARD_DECLINED', 422);
        }
        if ($gwRes['status'] !== 'success') {
            json_error('Giao dịch thẻ thất bại.', 'GATEWAY_ERROR', 502);
        }

        $gatewayRef = $gwRes['transaction_ref'];
        $brand = $gwRes['brand'] ?? 'Visa/Mastercard';
        $last4 = $gwRes['last4'] ?? '0000';
        $cardNote = sprintf('Thanh toán thẻ %s (*%s)', $brand, $last4);

        $entry = db_transaction(function(PDO $pdo) use ($feeRecordId, $studentId, $amount, $cardNote, $requestId, $user, $gatewayRef, $brand, $last4) {
            return execute_payment_transaction(
                $pdo,
                $feeRecordId,
                $amount,
                'Thẻ Visa/Mastercard',
                date('Y-m-d'),
                $cardNote,
                $requestId,
                (int)$user['id'],
                $studentId,
                $gatewayRef,
                'student_payment',
                $brand,
                $last4
            );
        }, $db);

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

    // 9. GET /api/student/bills/{ledgerEntryId} và /api/student/bills/{ledgerEntryId}/download
    if (preg_match('#^/api/student/bills/(\d+)(/download)?$#', $path, $matches) && $method === 'GET') {
        $user = require_student();
        $studentId = (int)$user['student_id'];
        $entryId = (int)$matches[1];
        $isDownload = !empty($matches[2]);

        $bStmt = $db->prepare(
            "SELECT b.*, l.entry_date, l.amount, l.method, l.receipt_no, fr.student_id
             FROM bills b
             JOIN ledger_entries l ON b.ledger_entry_id = l.id
             JOIN fee_records fr ON l.fee_record_id = fr.id
             WHERE b.ledger_entry_id = :lId AND fr.student_id = :stId"
        );
        $bStmt->execute(['lId' => $entryId, 'stId' => $studentId]);
        $bill = $bStmt->fetch();

        if (!$bill) {
            json_error('Không tìm thấy hóa đơn.', 'NOT_FOUND', 404);
        }

        $snapshot = json_decode((string)$bill['snapshot'], true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        if ($isDownload) {
            // Self-contained HTML document
            $billNo = $bill['bill_no'];
            $filename = sprintf('bill-%s.html', $billNo);

            header('Content-Type: text/html; charset=utf-8');
            header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));

            $esc = function($val) {
                return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
            };
            $fmtVND = function($num) {
                return number_format((float)($num ?? 0), 0, ',', '.') . ' ₫';
            };

            $schoolName = $esc($snapshot['school_name'] ?? '[Tên trường]');
            $studentCode = $esc($snapshot['student_code'] ?? '');
            $fullName = $esc($snapshot['full_name'] ?? '');
            $className = $esc($snapshot['class'] ?? $snapshot['class_name'] ?? '');
            $semesterLabel = $esc($snapshot['semester_label'] ?? '');
            $credits = (int)($snapshot['registered_credits'] ?? 0);
            $gross = $fmtVND($snapshot['gross_tuition'] ?? 0);
            $exAmt = $fmtVND($snapshot['exemption_amount'] ?? 0);
            $exReason = $esc($snapshot['exemption_reason'] ?? '');
            $netDue = $fmtVND($snapshot['net_due'] ?? 0);
            $paidBefore = $fmtVND($snapshot['total_paid_before'] ?? 0);
            $paymentAmount = $fmtVND($snapshot['this_payment_amount'] ?? $snapshot['amount'] ?? 0);
            $paidAfter = $fmtVND($snapshot['total_paid_after'] ?? 0);
            $remBal = $fmtVND($snapshot['remaining_balance'] ?? 0);
            $methodLabel = $esc($snapshot['method_label'] ?? $snapshot['method'] ?? '');
            $receiptNo = $esc($snapshot['receipt_number'] ?? $snapshot['receipt_no'] ?? '');
            $timestamp = $esc($snapshot['payment_timestamp'] ?? $bill['created_at']);

            $cardBrand = $snapshot['gateway_brand'] ?? null;
            $cardLast4 = $snapshot['gateway_last4'] ?? null;
            $cardInfoHtml = '';
            if (!empty($cardBrand) || !empty($cardLast4)) {
                $cardInfoHtml = sprintf(
                    '<p style="margin: 0.5rem 0; font-size: 13px;"><strong>Thông tin thẻ:</strong> %s (kết thúc bằng *%s)</p>',
                    $esc($cardBrand ?? 'Thẻ'),
                    $esc($cardLast4 ?? '0000')
                );
            }

            echo '<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Hóa đơn ' . $esc($billNo) . '</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; padding: 24px; color: #1e293b; background: #fff; line-height: 1.5; font-size: 14px; }
  .bill-container { max-width: 680px; margin: 0 auto; border: 1px solid #cbd5e1; border-radius: 8px; padding: 28px; background: #fff; }
  .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 16px; margin-bottom: 20px; }
  .header h1 { margin: 0 0 6px 0; font-size: 20px; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; }
  .header .bill-title { font-size: 16px; font-weight: 700; color: #2563eb; margin: 4px 0; }
  .header .meta { font-size: 12px; color: #64748b; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
  .field-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; }
  .field-value { font-size: 14px; font-weight: 500; color: #0f172a; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; margin-bottom: 18px; }
  th, td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
  th { background-color: #f8fafc; font-weight: 600; color: #475569; }
  td.num, th.num { text-align: right; }
  .highlight-row { background-color: #f0fdf4; font-weight: 700; color: #166534; }
  .footer { margin-top: 24px; padding-top: 16px; border-top: 1px dashed #cbd5e1; font-size: 12px; color: #64748b; text-align: center; }
  @media print {
    body { padding: 0; background: #fff; }
    .bill-container { border: none; padding: 0; width: 100%; max-width: 100%; }
  }
</style>
</head>
<body>
<div class="bill-container">
  <div class="header">
    <h1>' . $schoolName . '</h1>
    <div class="bill-title">HÓA ĐƠN THANH TOÁN HỌC PHÍ</div>
    <div class="meta">Số hóa đơn: <strong>' . $esc($billNo) . '</strong> | Ngày: ' . $timestamp . '</div>
  </div>

  <div class="grid">
    <div>
      <div class="field-label">Mã sinh viên</div>
      <div class="field-value">' . $studentCode . '</div>
    </div>
    <div>
      <div class="field-label">Họ và tên</div>
      <div class="field-value">' . $fullName . '</div>
    </div>
    <div>
      <div class="field-label">Lớp sinh viên</div>
      <div class="field-value">' . $className . '</div>
    </div>
    <div>
      <div class="field-label">Học kỳ</div>
      <div class="field-value">' . $semesterLabel . ' (' . $credits . ' tín chỉ)</div>
    </div>
    <div>
      <div class="field-label">Phương thức thanh toán</div>
      <div class="field-value">' . $methodLabel . '</div>
    </div>
    <div>
      <div class="field-label">Số biên lai</div>
      <div class="field-value">' . $receiptNo . '</div>
    </div>
  </div>

  ' . $cardInfoHtml . '

  <table>
    <thead>
      <tr>
        <th>Nội dung thanh toán</th>
        <th class="num">Số tiền</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Học phí gốc (Gross)</td>
        <td class="num">' . $gross . '</td>
      </tr>
      <tr>
        <td>Miễn giảm học phí' . ($exReason !== '' ? ' (' . $exReason . ')' : '') . '</td>
        <td class="num">' . $exAmt . '</td>
      </tr>
      <tr>
        <td>Học phí thực nộp (Net Due)</td>
        <td class="num"><strong>' . $netDue . '</strong></td>
      </tr>
      <tr>
        <td>Tổng số tiền đã nộp trước đợt này</td>
        <td class="num">' . $paidBefore . '</td>
      </tr>
      <tr class="highlight-row">
        <td>Số tiền thanh toán lần này</td>
        <td class="num">' . $paymentAmount . '</td>
      </tr>
      <tr>
        <td>Tổng số tiền đã nộp sau đợt này</td>
        <td class="num">' . $paidAfter . '</td>
      </tr>
      <tr>
        <td>Số dư nợ còn lại</td>
        <td class="num"><strong>' . $remBal . '</strong></td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    <p style="margin: 0;">Hóa đơn điện tử được khởi tạo tự động từ hệ thống quản lý học phí trực tuyến.</p>
    <p style="margin: 4px 0 0 0;">Cảm ơn bạn đã hoàn thành nghĩa vụ học phí.</p>
  </div>
</div>
</body>
</html>';
            exit;
        }

        $bill['snapshot'] = $snapshot;
        $bill['bill'] = [
            'id'              => (int)$bill['id'],
            'bill_no'         => $bill['bill_no'],
            'ledger_entry_id' => (int)$bill['ledger_entry_id'],
            'created_at'      => $bill['created_at'],
            'snapshot'        => $snapshot
        ];
        json_success($bill);
    }

    json_error('Không tìm thấy tài nguyên yêu cầu.', 'ENDPOINT_NOT_FOUND', 404);
}
