<?php
/**
 * Fee Records and Exemptions API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_fees_routes(string $method, string $path, array $matches): void {
    require_admin();
    $db = get_db_connection();

    // 1. GET /api/fee-records (List & Filter)
    if ($path === '/api/fee-records' && $method === 'GET') {
        $studentFilter = $_GET['student'] ?? ($_GET['student_id'] ?? null);
        $semesterFilter = $_GET['semester'] ?? ($_GET['semester_id'] ?? null);
        $statusFilter = $_GET['status'] ?? null;

        $sql = "SELECT 
                    v.*,
                    s.student_code, s.full_name, s.class_name,
                    sem.term_no, sem.academic_year_start,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
                FROM v_fee_record_summaries v
                JOIN students s ON v.student_id = s.id
                JOIN semesters sem ON v.semester_id = sem.id
                WHERE 1=1";
        $params = [];

        if (!empty($studentFilter) && $studentFilter !== 'ALL') {
            if (is_numeric($studentFilter)) {
                $sql .= " AND v.student_id = :stId";
                $params['stId'] = (int)$studentFilter;
            } else {
                $sql .= " AND s.student_code = :stCode";
                $params['stCode'] = strtoupper(trim($studentFilter));
            }
        }

        if (!empty($semesterFilter) && $semesterFilter !== 'ALL') {
            $sql .= " AND v.semester_id = :semId";
            $params['semId'] = (int)$semesterFilter;
        }

        $sql .= " ORDER BY sem.academic_year_start DESC, sem.term_no DESC, s.student_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $r) {
            $calc = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
            $merged = array_merge($r, $calc);

            // Filter by status if requested
            if (!empty($statusFilter) && $statusFilter !== 'ALL') {
                if (strcasecmp($statusFilter, 'OVERPAID') === 0) {
                    if (!$calc['is_overpaid']) continue;
                } elseif (strcasecmp($statusFilter, $calc['status']) !== 0 && strcasecmp($statusFilter, $calc['status_label']) !== 0) {
                    continue;
                }
            }
            $results[] = $merged;
        }

        json_success($results);
    }

    // 2. POST /api/fee-records (Create Fee Record)
    if ($path === '/api/fee-records' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['student_id', 'semester_id', 'registered_credits']);

        $studentId = isset($input['student_id']) ? (int)$input['student_id'] : 0;
        $semesterId = isset($input['semester_id']) ? (int)$input['semester_id'] : 0;
        $credits = isset($input['registered_credits']) ? (int)$input['registered_credits'] : 0;

        $errors = [];
        if ($studentId <= 0) {
            $errors['student_id'] = 'Vui lòng chọn sinh viên.';
        }
        if ($semesterId <= 0) {
            $errors['semester_id'] = 'Vui lòng chọn học kỳ.';
        }
        if ($credits <= 0 || $credits > 60) {
            $errors['registered_credits'] = 'Số tín chỉ đăng ký phải là số nguyên dương từ 1 đến 60.';
        }
        if (!empty($errors)) {
            json_error('Dữ liệu hồ sơ học phí không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra sinh viên và học kỳ tồn tại
        $st = $db->prepare("SELECT id FROM students WHERE id = :id");
        $st->execute(['id' => $studentId]);
        if (!$st->fetch()) {
            json_error('Sinh viên không tồn tại.', 'NOT_FOUND', 404);
        }

        // Lấy định mức hiện tại của học kỳ
        $rt = $db->prepare("SELECT price_per_credit FROM credit_rates WHERE semester_id = :semId");
        $rt->execute(['semId' => $semesterId]);
        $rateRow = $rt->fetch();
        if (!$rateRow) {
            json_error('Chưa thiết lập định mức học phí cho học kỳ này. Vui lòng tạo định mức trước.', 'RATE_NOT_FOUND', 422);
        }

        $rateSnapshot = (int)$rateRow['price_per_credit'];
        $gross = calc_gross($credits, $rateSnapshot);

        // Kiểm tra trùng hồ sơ (1 hồ sơ duy nhất trên 1 sinh viên trong 1 học kỳ)
        $chkDup = $db->prepare("SELECT id FROM fee_records WHERE student_id = :stId AND semester_id = :semId");
        $chkDup->execute(['stId' => $studentId, 'semId' => $semesterId]);
        if ($chkDup->fetch()) {
            json_error('Sinh viên này đã có hồ sơ học phí trong học kỳ đã chọn.', 'DUPLICATE_FEE_RECORD', 409);
        }

        $ins = $db->prepare(
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
        $newId = (int)$db->lastInsertId();

        log_audit(null, 'create', 'fee_records', (string)$newId, [
            'student_id'         => $studentId,
            'semester_id'        => $semesterId,
            'registered_credits' => $credits,
            'rate_snapshot'      => $rateSnapshot,
            'gross_tuition'      => $gross
        ]);

        // Trả về dữ liệu chi tiết
        $res = $db->prepare("SELECT * FROM v_fee_record_summaries WHERE fee_record_id = :id");
        $res->execute(['id' => $newId]);
        $r = $res->fetch();
        $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
        json_success(array_merge($r, $statusInfo), 201);
    }

    // 3. PUT /api/fee-records/{id}/exemption (Upsert Exemption)
    if (preg_match('#^/api/fee-records/(\d+)/exemption$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['type', 'value', 'reason']);

        $type = (string)($input['type'] ?? '');
        $rawVal = $input['value'] ?? null;
        $reason = trim((string)($input['reason'] ?? ''));

        $errors = [];
        if (!in_array($type, ['percent', 'fixed'], true)) {
            $errors['type'] = "Loại miễn giảm phải là 'percent' hoặc 'fixed'.";
        }
        if ($reason === '' || !validate_string_length($reason, 1, 200)) {
            $errors['reason'] = 'Lý do miễn giảm là bắt buộc và tối đa 200 ký tự.';
        }

        // Lấy fee_record và lock
        $feeStmt = $db->prepare("SELECT id, gross_tuition FROM fee_records WHERE id = :id");
        $feeStmt->execute(['id' => $feeRecordId]);
        $fee = $feeStmt->fetch();
        if (!$fee) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }

        $gross = (int)$fee['gross_tuition'];
        $valNum = 0;

        if ($type === 'percent') {
            if (!is_numeric($rawVal) || (int)$rawVal != $rawVal || (int)$rawVal < 1 || (int)$rawVal > 100) {
                $errors['value'] = 'Phần trăm miễn giảm phải là số nguyên từ 1 đến 100.';
            } else {
                $valNum = (int)$rawVal;
            }
        } elseif ($type === 'fixed') {
            $f = parse_currency($rawVal);
            if ($f === null || $f <= 0) {
                $errors['value'] = 'Số tiền miễn giảm cố định phải là số nguyên dương lớn hơn 0.';
            } elseif ($f > $gross) {
                $errors['value'] = 'Số tiền miễn giảm cố định không được vượt quá học phí gốc (' . number_format($gross, 0, ',', '.') . ' ₫).';
            } else {
                $valNum = $f;
            }
        }

        if (!empty($errors)) {
            json_error('Dữ liệu miễn giảm không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);

        $upsert = $db->prepare(
            "INSERT INTO exemptions (fee_record_id, type, value, reason, created_by, updated_at)
             VALUES (:frId, :type, :val, :reason, :userId, NOW())
             ON DUPLICATE KEY UPDATE 
                type = VALUES(type),
                value = VALUES(value),
                reason = VALUES(reason),
                updated_at = NOW()"
        );
        $upsert->execute([
            'frId'   => $feeRecordId,
            'type'   => $type,
            'val'    => $valNum,
            'reason' => $reason,
            'userId' => $userId
        ]);

        log_audit(null, 'upsert_exemption', 'exemptions', (string)$feeRecordId, [
            'type'   => $type,
            'value'  => $valNum,
            'reason' => $reason
        ]);

        $res = $db->prepare("SELECT * FROM v_fee_record_summaries WHERE fee_record_id = :id");
        $res->execute(['id' => $feeRecordId]);
        $r = $res->fetch();
        $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
        json_success(array_merge($r, $statusInfo));
    }

    // 4. DELETE /api/fee-records/{id}/exemption (Remove Exemption)
    if (preg_match('#^/api/fee-records/(\d+)/exemption$#', $path, $m) && $method === 'DELETE') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];

        $del = $db->prepare("DELETE FROM exemptions WHERE fee_record_id = :frId");
        $del->execute(['frId' => $feeRecordId]);

        log_audit(null, 'delete_exemption', 'exemptions', (string)$feeRecordId);

        $res = $db->prepare("SELECT * FROM v_fee_record_summaries WHERE fee_record_id = :id");
        $res->execute(['id' => $feeRecordId]);
        $r = $res->fetch();
        $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
        json_success(array_merge($r, $statusInfo));
    }

    // 5. PUT /api/fee-records/{id} (Update Credits)
    if (preg_match('#^/api/fee-records/(\d+)$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $feeRecordId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['registered_credits']);

        $credits = isset($input['registered_credits']) ? (int)$input['registered_credits'] : 0;
        if ($credits <= 0 || $credits > 60) {
            json_error('Số tín chỉ đăng ký phải là số nguyên dương từ 1 đến 60.', 'VALIDATION_ERROR', 400, [
                'registered_credits' => 'Số tín chỉ không hợp lệ.'
            ]);
        }

        $feeStmt = $db->prepare("SELECT * FROM fee_records WHERE id = :id FOR UPDATE");
        $feeStmt->execute(['id' => $feeRecordId]);
        $fee = $feeStmt->fetch();
        if (!$fee) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }

        $rateSnapshot = (int)$fee['rate_snapshot'];
        $newGross = calc_gross($credits, $rateSnapshot);

        // Kiểm tra nếu có miễn giảm cố định vượt quá gross mới
        $exStmt = $db->prepare("SELECT * FROM exemptions WHERE fee_record_id = :id");
        $exStmt->execute(['id' => $feeRecordId]);
        $ex = $exStmt->fetch();
        if ($ex && $ex['type'] === 'fixed' && (int)$ex['value'] > $newGross) {
            // Giảm mức miễn giảm cố định xuống mức gross mới
            $upEx = $db->prepare("UPDATE exemptions SET value = :val, updated_at = NOW() WHERE fee_record_id = :id");
            $upEx->execute(['val' => $newGross, 'id' => $feeRecordId]);
        }

        $up = $db->prepare("UPDATE fee_records SET registered_credits = :credits, gross_tuition = :gross, updated_at = NOW() WHERE id = :id");
        $up->execute(['credits' => $credits, 'gross' => $newGross, 'id' => $feeRecordId]);

        log_audit(null, 'update', 'fee_records', (string)$feeRecordId, [
            'old_credits' => $fee['registered_credits'],
            'new_credits' => $credits,
            'new_gross'   => $newGross
        ]);

        $res = $db->prepare("SELECT * FROM v_fee_record_summaries WHERE fee_record_id = :id");
        $res->execute(['id' => $feeRecordId]);
        $r = $res->fetch();
        $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
        json_success(array_merge($r, $statusInfo));
    }

    // 6. GET /api/fee-records/{id} (Full Details: Exemption, Ledger, Refunds, Calculated Balances)
    if (preg_match('#^/api/fee-records/(\d+)$#', $path, $m) && $method === 'GET') {
        $feeRecordId = (int)$m[1];

        $stmt = $db->prepare(
            "SELECT 
                v.*,
                s.student_code, s.full_name, s.class_name,
                sem.term_no, sem.academic_year_start,
                CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM v_fee_record_summaries v
             JOIN students s ON v.student_id = s.id
             JOIN semesters sem ON v.semester_id = sem.id
             WHERE v.fee_record_id = :id"
        );
        $stmt->execute(['id' => $feeRecordId]);
        $fee = $stmt->fetch();
        if (!$fee) {
            json_error('Không tìm thấy hồ sơ học phí.', 'NOT_FOUND', 404);
        }

        // Lấy danh sách giao dịch sổ cái
        $lStmt = $db->prepare(
            "SELECT l.*, u.full_name AS created_by_name
             FROM ledger_entries l
             LEFT JOIN users u ON l.created_by = u.id
             WHERE l.fee_record_id = :id
             ORDER BY l.created_at DESC, l.id DESC"
        );
        $lStmt->execute(['id' => $feeRecordId]);
        $ledger = $lStmt->fetchAll();

        // Lấy danh sách yêu cầu hoàn tiền
        $rfStmt = $db->prepare(
            "SELECT r.*, 
                    u1.full_name AS created_by_name,
                    u2.full_name AS completed_by_name,
                    u3.full_name AS cancelled_by_name
             FROM refund_requests r
             LEFT JOIN users u1 ON r.created_by = u1.id
             LEFT JOIN users u2 ON r.completed_by = u2.id
             LEFT JOIN users u3 ON r.cancelled_by = u3.id
             WHERE r.fee_record_id = :id
             ORDER BY r.created_at DESC, r.id DESC"
        );
        $rfStmt->execute(['id' => $feeRecordId]);
        $refunds = $rfStmt->fetchAll();

        $calc = derive_payment_status((int)$fee['total_paid'], (int)$fee['net_due']);

        json_success(array_merge($fee, $calc, [
            'ledger_entries'  => $ledger,
            'refund_requests' => $refunds
        ]));
    }
}
