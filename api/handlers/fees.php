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

        if ($studentFilter !== null && is_string($studentFilter)) {
            $studentFilterTrimmed = trim($studentFilter);
            if (mb_strlen($studentFilterTrimmed, 'UTF-8') > 100) {
                json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, [
                    'student' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.'
                ]);
            }
            if (is_search_query_empty_or_diacritics_only($studentFilterTrimmed)) {
                $studentFilter = null;
            } else {
                $studentFilter = $studentFilterTrimmed;
            }
        }

        if (!empty($semesterFilter) && $semesterFilter !== 'ALL') {
            if (!is_numeric($semesterFilter) || (int)$semesterFilter <= 0) {
                json_error('Vui lòng chọn học kỳ hợp lệ.', 'VALIDATION_ERROR', 400, [
                    'semester' => 'Vui lòng chọn học kỳ.'
                ]);
            }
        }

        $feeSub = get_fee_summary_subquery();
        $sql = "SELECT 
                    v.*,
                    v.fee_record_id AS id,
                    s.student_code, s.full_name, s.class_name,
                    sem.term_no, sem.academic_year_start,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
                FROM {$feeSub} v
                JOIN students s ON v.student_id = s.id
                JOIN semesters sem ON v.semester_id = sem.id
                WHERE 1=1";
        $params = [];

        if (!empty($studentFilter) && $studentFilter !== 'ALL') {
            if (is_numeric($studentFilter)) {
                $sql .= " AND v.student_id = :stId";
                $params['stId'] = (int)$studentFilter;
            } else {
                $normSt = normalize_vietnamese($studentFilter);
                $sql .= " AND (s.student_code LIKE :stCode OR s.name_search LIKE :stName)";
                $params['stCode'] = '%' . strtoupper(trim($studentFilter)) . '%';
                $params['stName'] = '%' . $normSt . '%';
            }
        }

        if (!empty($semesterFilter) && $semesterFilter !== 'ALL') {
            $sql .= " AND v.semester_id = :semId";
            $params['semId'] = (int)$semesterFilter;
        }

        if (!empty($statusFilter) && $statusFilter !== 'ALL') {
            $sfLower = mb_strtolower(trim((string)$statusFilter), 'UTF-8');
            if ($sfLower === 'pending' || $sfLower === 'chờ duyệt') {
                $sql .= " AND (v.total_paid > v.net_due AND COALESCE(v.pending_refunds_count, 0) > 0)";
            } elseif ($sfLower === 'overpaid' || $sfLower === 'nộp thừa') {
                $sql .= " AND v.derived_status = 'Overpaid'";
            } elseif ($sfLower === 'unpaid' || $sfLower === 'chưa thanh toán') {
                $sql .= " AND v.derived_status = 'Unpaid'";
            } elseif ($sfLower === 'partially paid' || $sfLower === 'thanh toán một phần' || $sfLower === 'partial') {
                $sql .= " AND v.derived_status = 'Partially Paid'";
            } elseif ($sfLower === 'fully paid' || $sfLower === 'đã thanh toán đủ' || $sfLower === 'paid') {
                $sql .= " AND v.derived_status = 'Fully Paid'";
            }
        }

        $sql .= " ORDER BY sem.academic_year_start DESC, sem.term_no DESC, s.student_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $r) {
            $calc = derive_payment_status(
                (int)$r['total_paid'],
                (int)$r['net_due'],
                (int)($r['pending_refunds_count'] ?? 0),
                (int)($r['pending_refunds_sum'] ?? 0)
            );
            $merged = array_merge($r, $calc);

            // Filter by status if requested
            if (!empty($statusFilter) && $statusFilter !== 'ALL') {
                $sf = mb_strtolower(trim((string)$statusFilter), 'UTF-8');
                if ($sf === 'pending' || $sf === 'chờ duyệt') {
                    if (!$calc['is_pending']) continue;
                } elseif ($sf === 'overpaid' || $sf === 'nộp thừa') {
                    if (!$calc['is_overpaid']) continue;
                } else {
                    $stLower = mb_strtolower($calc['status'], 'UTF-8');
                    $stLblLower = mb_strtolower($calc['status_label'], 'UTF-8');
                    if ($sf !== $stLower && $sf !== $stLblLower) {
                        continue;
                    }
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
            json_error('Sinh viên không tồn tại.', 'NOT_FOUND', 404, ['student_id' => 'Sinh viên không tồn tại.']);
        }

        $semChk = $db->prepare("SELECT id FROM semesters WHERE id = :id");
        $semChk->execute(['id' => $semesterId]);
        if (!$semChk->fetch()) {
            json_error('Học kỳ không tồn tại.', 'NOT_FOUND', 404, ['semester_id' => 'Học kỳ không tồn tại.']);
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

        // Kiểm tra trùng hồ sơ trước khi INSERT
        $chkDup = $db->prepare("SELECT id FROM fee_records WHERE student_id = :stId AND semester_id = :semId");
        $chkDup->execute(['stId' => $studentId, 'semId' => $semesterId]);
        $existingRecord = $chkDup->fetch();
        if ($existingRecord) {
            error_log(sprintf("[%s] Duplicate fee record attempt: student_id=%d, semester_id=%d", date('Y-m-d H:i:s'), $studentId, $semesterId));
            json_error('Sinh viên này đã có hồ sơ học phí cho học kỳ này.', 'DUPLICATE_FEE_RECORD', 409, null, ['existing_id' => (int)$existingRecord['id']]);
        }

        try {
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
        } catch (PDOException $pe) {
            $isDuplicate = ($pe->getCode() === '23000') || (isset($pe->errorInfo[1]) && $pe->errorInfo[1] === 1062);
            if ($isDuplicate) {
                error_log(sprintf("[%s] Duplicate fee record race on INSERT: student_id=%d, semester_id=%d", date('Y-m-d H:i:s'), $studentId, $semesterId));
                $chk = $db->prepare("SELECT id FROM fee_records WHERE student_id = :stId AND semester_id = :semId");
                $chk->execute(['stId' => $studentId, 'semId' => $semesterId]);
                $exRow = $chk->fetch();
                $existingId = $exRow ? (int)$exRow['id'] : null;
                json_error('Sinh viên này đã có hồ sơ học phí cho học kỳ này.', 'DUPLICATE_FEE_RECORD', 409, null, ['existing_id' => $existingId]);
            }
            throw $pe;
        }

        log_audit(null, 'create', 'fee_records', (string)$newId, [
            'student_id'         => $studentId,
            'semester_id'        => $semesterId,
            'registered_credits' => $credits,
            'rate_snapshot'      => $rateSnapshot,
            'gross_tuition'      => $gross
        ]);

        // Trả về dữ liệu chi tiết
        $r = fetch_fee_record_summary($db, $newId);
        json_success(array_merge($r, ['id' => $newId]), 201);
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
        if ($reason === '') {
            $errors['reason'] = 'Lý do không được để trống.';
        } elseif (mb_strlen($reason, 'UTF-8') > 200) {
            $errors['reason'] = 'Lý do không được vượt quá 200 ký tự.';
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
            $sVal = is_string($rawVal) ? trim($rawVal) : (string)$rawVal;
            if (is_float($rawVal) || !preg_match('/^[0-9]+$/', $sVal) || (int)$sVal < 1 || (int)$sVal > 100) {
                $errors['value'] = 'Phần trăm miễn giảm phải là số nguyên từ 1 đến 100.';
            } else {
                $valNum = (int)$sVal;
            }
        } elseif ($type === 'fixed') {
            $f = parse_currency($rawVal);
            if ($f === null || $f <= 0) {
                $errors['value'] = 'Số tiền phải là số nguyên dương.';
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

        $r = fetch_fee_record_summary($db, $feeRecordId);
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

        $r = fetch_fee_record_summary($db, $feeRecordId);
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

        $r = fetch_fee_record_summary($db, $feeRecordId);
        $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
        json_success(array_merge($r, $statusInfo));
    }

    // 6. GET /api/fee-records/{id} (Full Details: Exemption, Ledger, Refunds, Calculated Balances)
    if (preg_match('#^/api/fee-records/(\d+)$#', $path, $m) && $method === 'GET') {
        $feeRecordId = (int)$m[1];

        $feeSub = get_fee_summary_subquery();
        $stmt = $db->prepare(
            "SELECT 
                v.*,
                v.fee_record_id AS id,
                s.student_code, s.full_name, s.class_name,
                sem.term_no, sem.academic_year_start,
                CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM {$feeSub} v
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

        $calc = derive_payment_status(
            (int)$fee['total_paid'],
            (int)$fee['net_due'],
            (int)($fee['pending_refunds_count'] ?? 0),
            (int)($fee['pending_refunds_sum'] ?? 0)
        );

        json_success(array_merge($fee, $calc, [
            'id'              => $feeRecordId,
            'ledger_entries'  => $ledger,
            'refunds'         => $refunds,
            'refund_requests' => $refunds
        ]));
    }

    // 7. POST /api/fee-records/{id}/transfers (Transfer Overpaid Excess)
    if ((preg_match('#^/api/fee-records/(\d+)/transfers$#', $path, $m) || $path === '/api/transfers') && $method === 'POST') {
        verify_csrf_token();
        $sourceId = !empty($m[1]) ? (int)$m[1] : 0;
        $input = get_json_input();
        validate_allowed_fields($input, ['source_fee_record_id', 'target_fee_record_id', 'target_id', 'amount', 'reason', 'request_id']);

        if ($sourceId <= 0 && !empty($input['source_fee_record_id'])) {
            $sourceId = (int)$input['source_fee_record_id'];
        }
        $targetId = !empty($input['target_fee_record_id']) ? (int)$input['target_fee_record_id'] : (!empty($input['target_id']) ? (int)$input['target_id'] : 0);

        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId === '' || !preg_match('/^[a-f0-9\-]{36}$/i', $requestId)) {
            json_error('Mã yêu cầu (request_id) không hợp lệ (yêu cầu chuẩn UUID).', 'INVALID_REQUEST_ID', 400);
        }

        $amount = parse_currency($input['amount'] ?? null);
        $reason = trim((string)($input['reason'] ?? ''));

        $errors = [];
        if ($sourceId <= 0) {
            $errors['source_fee_record_id'] = 'Vui lòng xác định hồ sơ nguồn.';
        }
        if ($targetId <= 0) {
            $errors['target_fee_record_id'] = 'Vui lòng chọn hồ sơ đích.';
        }
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'Số tiền phải là số nguyên dương.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Lý do không được để trống.';
        } elseif (mb_strlen($reason, 'UTF-8') > 200) {
            $errors['reason'] = 'Lý do không được vượt quá 200 ký tự.';
        }
        if (!empty($errors)) {
            json_error('Dữ liệu chuyển tiền không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        if ($sourceId === $targetId) {
            json_error('Không thể chuyển tiền cho chính hồ sơ này.', 'INVALID_TARGET_SELF', 422);
        }

        // Idempotency check: if transfer with this request_id already exists, return existing rows
        $reqOut = $requestId . '-out';
        $reqIn = $requestId . '-in';
        $chkReq = $db->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqOut");
        $chkReq->execute(['reqOut' => $reqOut]);
        $existingOut = $chkReq->fetch();
        if ($existingOut) {
            $grpId = $existingOut['transfer_group_id'];
            $chkGroup = $db->prepare("SELECT * FROM ledger_entries WHERE transfer_group_id = :grpId ORDER BY id ASC");
            $chkGroup->execute(['grpId' => $grpId]);
            $entries = $chkGroup->fetchAll();
            $sourceSummary = fetch_fee_record_summary($db, $sourceId);
            $targetSummary = fetch_fee_record_summary($db, $targetId);
            json_success([
                'transfer_group_id' => $grpId,
                'entries'           => $entries,
                'source_profile'    => $sourceSummary,
                'target_profile'    => $targetSummary
            ], 200);
        }

        $userId = (int)($_SESSION['user_id'] ?? 1);

        $result = db_transaction(function(PDO $pdo) use ($sourceId, $targetId, $amount, $reason, $requestId, $reqOut, $reqIn, $userId) {
            // Lock both fee_record rows with SELECT ... FOR UPDATE in ascending id order to prevent deadlock
            $firstId = min($sourceId, $targetId);
            $secondId = max($sourceId, $targetId);

            $lock1 = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id FOR UPDATE");
            $lock1->execute(['id' => $firstId]);
            $row1 = $lock1->fetch();

            $lock2 = $pdo->prepare("SELECT id, student_id FROM fee_records WHERE id = :id FOR UPDATE");
            $lock2->execute(['id' => $secondId]);
            $row2 = $lock2->fetch();

            if (!$row1 || !$row2) {
                json_error('Không tìm thấy hồ sơ học phí nguồn hoặc đích.', 'NOT_FOUND', 404);
            }

            $sourceStudentId = ($row1['id'] == $sourceId) ? (int)$row1['student_id'] : (int)$row2['student_id'];
            $targetStudentId = ($row1['id'] == $targetId) ? (int)$row1['student_id'] : (int)$row2['student_id'];

            // Source and target must belong to the same student
            if ($sourceStudentId !== $targetStudentId) {
                json_error('Hai hồ sơ học phí phải thuộc về cùng một sinh viên.', 'DIFFERENT_STUDENTS', 422);
            }

            // Recheck both balances after locking
            $sourceSummary = fetch_fee_record_summary($pdo, $sourceId);
            $targetSummary = fetch_fee_record_summary($pdo, $targetId);

            $sourceNet = (int)($sourceSummary['net_due'] ?? 0);
            $sourcePaid = (int)($sourceSummary['total_paid'] ?? 0);
            $sourcePending = (int)($sourceSummary['pending_refunds_sum'] ?? 0);
            $sourceExcess = max(0, $sourcePaid - $sourceNet);
            $sourceAvailable = max(0, $sourceExcess - $sourcePending);

            $targetNet = (int)($targetSummary['net_due'] ?? 0);
            $targetPaid = (int)($targetSummary['total_paid'] ?? 0);
            $targetOutstanding = max(0, $targetNet - $targetPaid);

            // Target must have outstanding balance > 0 (net_due − total_paid > 0)
            if ($targetOutstanding <= 0) {
                json_error('Hồ sơ đích không còn số còn nợ.', 'TARGET_NO_OUTSTANDING', 422);
            }

            // Amount ≤ available excess of source, and amount ≤ outstanding balance of target
            if ($amount > $sourceAvailable) {
                json_error('Số tiền chuyển vượt quá số tiền nộp thừa khả dụng của hồ sơ nguồn (Khả dụng: ' . number_format($sourceAvailable, 0, ',', '.') . ' ₫).', 'EXCESS_EXCEEDED', 422);
            }

            if ($amount > $targetOutstanding) {
                json_error('Số tiền chuyển vượt quá số còn nợ của hồ sơ đích (Còn nợ: ' . number_format($targetOutstanding, 0, ',', '.') . ' ₫).', 'TARGET_OUTSTANDING_EXCEEDED', 422);
            }

            // Transfer is a single transaction
            // Generate transfer_group_id
            $transferGroupId = generate_uuid();
            $today = date('Y-m-d');

            // Write two ledger rows sharing one transfer_group_id:
            // transfer_out on source, transfer_in on target.
            // Neither has a receipt number. Both carry request_id with distinct suffixes (-out and -in).
            $insOut = $pdo->prepare(
                "INSERT INTO ledger_entries (
                    fee_record_id, entry_type, amount, method, entry_date,
                    receipt_no, reverses_entry_id, transfer_group_id, reason, note, request_id, created_by, created_at
                ) VALUES (
                    :frId, 'transfer_out', :amount, 'Chuyển khoản', :entryDate,
                    NULL, NULL, :grpId, :reason, :note, :reqId, :userId, NOW()
                )"
            );

            $insIn = $pdo->prepare(
                "INSERT INTO ledger_entries (
                    fee_record_id, entry_type, amount, method, entry_date,
                    receipt_no, reverses_entry_id, transfer_group_id, reason, note, request_id, created_by, created_at
                ) VALUES (
                    :frId, 'transfer_in', :amount, 'Chuyển khoản', :entryDate,
                    NULL, NULL, :grpId, :reason, :note, :reqId, :userId, NOW()
                )"
            );

            try {
                $insOut->execute([
                    'frId'      => $sourceId,
                    'amount'    => $amount,
                    'entryDate' => $today,
                    'grpId'     => $transferGroupId,
                    'reason'    => $reason,
                    'note'      => 'Chuyển tiền thừa sang hồ sơ #' . $targetId,
                    'reqId'     => $reqOut,
                    'userId'    => $userId
                ]);
                $outId = (int)$pdo->lastInsertId();

                $insIn->execute([
                    'frId'      => $targetId,
                    'amount'    => $amount,
                    'entryDate' => $today,
                    'grpId'     => $transferGroupId,
                    'reason'    => $reason,
                    'note'      => 'Nhận tiền thừa từ hồ sơ #' . $sourceId,
                    'reqId'     => $reqIn,
                    'userId'    => $userId
                ]);
                $inId = (int)$pdo->lastInsertId();
            } catch (PDOException $pe) {
                // Check if race condition idempotency
                if ($pe->getCode() === '23000') {
                    $chk = $pdo->prepare("SELECT * FROM ledger_entries WHERE request_id = :reqOut");
                    $chk->execute(['reqOut' => $reqOut]);
                    $ex = $chk->fetch();
                    if ($ex) {
                        return [
                            'transfer_group_id' => $ex['transfer_group_id'],
                            'is_idempotent'     => true
                        ];
                    }
                }
                throw $pe;
            }

            // Audit both rows and the action
            log_audit($userId, 'transfer_out', 'ledger_entries', (string)$outId, [
                'fee_record_id'     => $sourceId,
                'target_id'         => $targetId,
                'amount'            => $amount,
                'transfer_group_id' => $transferGroupId,
                'request_id'        => $reqOut
            ], $pdo);

            log_audit($userId, 'transfer_in', 'ledger_entries', (string)$inId, [
                'fee_record_id'     => $targetId,
                'source_id'         => $sourceId,
                'amount'            => $amount,
                'transfer_group_id' => $transferGroupId,
                'request_id'        => $reqIn
            ], $pdo);

            log_audit($userId, 'transfer_excess', 'fee_records', (string)$sourceId, [
                'source_id'         => $sourceId,
                'target_id'         => $targetId,
                'amount'            => $amount,
                'reason'            => $reason,
                'transfer_group_id' => $transferGroupId
            ], $pdo);

            return [
                'transfer_group_id' => $transferGroupId,
                'transfer_out_id'   => $outId,
                'transfer_in_id'    => $inId,
                'amount'            => $amount,
                'source_fee_record_id' => $sourceId,
                'target_fee_record_id' => $targetId
            ];
        }, $db);

        // Fetch updated summaries
        $updatedSource = fetch_fee_record_summary($db, $sourceId);
        $updatedTarget = fetch_fee_record_summary($db, $targetId);

        $httpStatus = !empty($result['is_idempotent']) ? 200 : 201;
        json_success(array_merge($result, [
            'source_profile' => $updatedSource,
            'target_profile' => $updatedTarget
        ]), $httpStatus);
    }
}
