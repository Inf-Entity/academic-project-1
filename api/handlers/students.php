<?php
/**
 * Students API Handler: CRUD and Student Account Summary
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_students_routes(string $method, string $path, array $matches): void {
    require_admin();
    $db = get_db_connection();

    // 1. GET /api/students (List & Search)
    if ($path === '/api/students' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $norm = normalize_vietnamese($q);
            $stmt = $db->prepare(
                "SELECT id, student_code, full_name, class_name, created_at, updated_at
                 FROM students
                 WHERE student_code LIKE :code OR name_search LIKE :q
                 ORDER BY id ASC"
            );
            $stmt->execute([
                'code' => '%' . strtoupper($q) . '%',
                'q'    => '%' . $norm . '%'
            ]);
        } else {
            $stmt = $db->query(
                "SELECT id, student_code, full_name, class_name, created_at, updated_at
                 FROM students
                 ORDER BY id ASC"
            );
        }
        json_success($stmt->fetchAll());
    }

    // 2. POST /api/students (Create)
    if ($path === '/api/students' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['student_code', 'full_name', 'class_name']);

        $code = validate_student_code((string)($input['student_code'] ?? ''));
        $name = trim((string)($input['full_name'] ?? ''));
        $class = isset($input['class_name']) ? trim((string)$input['class_name']) : null;
        if ($class === '') {
            $class = null;
        }

        $errors = [];
        if (!$code) {
            $errors['student_code'] = 'Mã sinh viên phải gồm 6-12 ký tự chữ và số.';
        }
        if ($name === '' || !validate_string_length($name, 1, 100)) {
            $errors['full_name'] = 'Họ và tên không được để trống và tối đa 100 ký tự.';
        }
        if ($class !== null && !validate_string_length($class, 1, 50)) {
            $errors['class_name'] = 'Lớp sinh hoạt tối đa 50 ký tự.';
        }

        if (!empty($errors)) {
            json_error('Dữ liệu sinh viên không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra trùng mã sinh viên
        $checkStmt = $db->prepare("SELECT id FROM students WHERE student_code = :code LIMIT 1");
        $checkStmt->execute(['code' => $code]);
        if ($checkStmt->fetch()) {
            json_error("Mã sinh viên '{$code}' đã tồn tại trong hệ thống.", 'DUPLICATE_CODE', 409, [
                'student_code' => "Mã sinh viên '{$code}' đã tồn tại."
            ]);
        }

        $nameSearch = normalize_vietnamese($name);
        $insStmt = $db->prepare(
            "INSERT INTO students (student_code, full_name, name_search, class_name, created_at, updated_at)
             VALUES (:code, :name, :name_search, :class, NOW(), NOW())"
        );
        $insStmt->execute([
            'code'        => $code,
            'name'        => $name,
            'name_search' => $nameSearch,
            'class'       => $class,
        ]);

        $newId = (int)$db->lastInsertId();
        log_audit(null, 'create', 'students', (string)$newId, [
            'student_code' => $code,
            'full_name'    => $name,
            'class_name'   => $class
        ]);

        $stmt = $db->prepare("SELECT id, student_code, full_name, class_name, created_at, updated_at FROM students WHERE id = :id");
        $stmt->execute(['id' => $newId]);
        json_success($stmt->fetch(), 201);
    }

    // 3. GET /api/students/{id}/account (Student Tuition Account Summary)
    if (preg_match('#^/api/students/(\d+)/account$#', $path, $m) && $method === 'GET') {
        $studentId = (int)$m[1];
        $stStmt = $db->prepare("SELECT id, student_code, full_name, class_name, created_at FROM students WHERE id = :id");
        $stStmt->execute(['id' => $studentId]);
        $student = $stStmt->fetch();
        if (!$student) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }

        // Lấy tất cả hồ sơ học phí của sinh viên kèm thông tin học kỳ và tính toán
        $feeSub = get_fee_summary_subquery();
        $feeStmt = $db->prepare(
            "SELECT 
                v.*,
                sem.term_no,
                sem.academic_year_start,
                CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM {$feeSub} v
             JOIN semesters sem ON v.semester_id = sem.id
             WHERE v.student_id = :student_id
             ORDER BY sem.academic_year_start DESC, sem.term_no DESC"
        );
        $feeStmt->execute(['student_id' => $studentId]);
        $records = $feeStmt->fetchAll();

        $accountRecords = [];
        $totalGross = 0;
        $totalNetDue = 0;
        $totalPaidSum = 0;
        $totalOutstandingSum = 0;
        $totalOverpaidSum = 0;

        foreach ($records as $r) {
            $statusInfo = derive_payment_status((int)$r['total_paid'], (int)$r['net_due']);
            $totalGross += (int)$r['gross_tuition'];
            $totalNetDue += (int)$r['net_due'];
            $totalPaidSum += (int)$r['total_paid'];
            $totalOutstandingSum += $statusInfo['outstanding'];
            $totalOverpaidSum += $statusInfo['overpaid_amount'];

            $accountRecords[] = array_merge($r, $statusInfo);
        }

        json_success([
            'student'           => $student,
            'fee_records'       => $accountRecords,
            'total_gross'       => $totalGross,
            'total_net_due'     => $totalNetDue,
            'total_paid'        => $totalPaidSum,
            'total_outstanding' => $totalOutstandingSum,
            'total_overpaid'    => $totalOverpaidSum
        ]);
    }

    // 4. GET /api/students/{id}
    if (preg_match('#^/api/students/(\d+)$#', $path, $m) && $method === 'GET') {
        $studentId = (int)$m[1];
        $stmt = $db->prepare("SELECT id, student_code, full_name, class_name, created_at, updated_at FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }
        json_success($student);
    }

    // 5. PUT /api/students/{id}
    if (preg_match('#^/api/students/(\d+)$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $studentId = (int)$m[1];

        $stmt = $db->prepare("SELECT id, student_code, full_name, class_name FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }

        $input = get_json_input();
        validate_allowed_fields($input, ['student_code', 'full_name', 'class_name']);

        $code = isset($input['student_code']) ? validate_student_code((string)$input['student_code']) : $existing['student_code'];
        $name = isset($input['full_name']) ? trim((string)$input['full_name']) : $existing['full_name'];
        $class = isset($input['class_name']) ? trim((string)$input['class_name']) : $existing['class_name'];
        if ($class === '') {
            $class = null;
        }

        $errors = [];
        if (!$code) {
            $errors['student_code'] = 'Mã sinh viên phải gồm 6-12 ký tự chữ và số.';
        }
        if ($name === '' || !validate_string_length($name, 1, 100)) {
            $errors['full_name'] = 'Họ và tên không được để trống và tối đa 100 ký tự.';
        }
        if ($class !== null && !validate_string_length($class, 1, 50)) {
            $errors['class_name'] = 'Lớp sinh hoạt tối đa 50 ký tự.';
        }
        if (!empty($errors)) {
            json_error('Dữ liệu sinh viên không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        if ($code !== $existing['student_code']) {
            $checkStmt = $db->prepare("SELECT id FROM students WHERE student_code = :code AND id != :id LIMIT 1");
            $checkStmt->execute(['code' => $code, 'id' => $studentId]);
            if ($checkStmt->fetch()) {
                json_error("Mã sinh viên '{$code}' đã tồn tại trong hệ thống.", 'DUPLICATE_CODE', 409, [
                    'student_code' => "Mã sinh viên '{$code}' đã tồn tại."
                ]);
            }
        }

        $nameSearch = normalize_vietnamese($name);
        $upStmt = $db->prepare(
            "UPDATE students SET student_code = :code, full_name = :name, name_search = :name_search, class_name = :class, updated_at = NOW()
             WHERE id = :id"
        );
        $upStmt->execute([
            'code'        => $code,
            'name'        => $name,
            'name_search' => $nameSearch,
            'class'       => $class,
            'id'          => $studentId
        ]);

        log_audit(null, 'update', 'students', (string)$studentId, [
            'student_code' => $code,
            'full_name'    => $name,
            'class_name'   => $class
        ]);

        $stmt = $db->prepare("SELECT id, student_code, full_name, class_name, created_at, updated_at FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        json_success($stmt->fetch());
    }

    // 6. DELETE /api/students/{id}
    if (preg_match('#^/api/students/(\d+)$#', $path, $m) && $method === 'DELETE') {
        verify_csrf_token();
        $studentId = (int)$m[1];

        $stmt = $db->prepare("SELECT id, student_code, full_name FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }

        // Chặn xóa nếu có fee_record
        $chkFee = $db->prepare("SELECT COUNT(*) FROM fee_records WHERE student_id = :id");
        $chkFee->execute(['id' => $studentId]);
        if ((int)$chkFee->fetchColumn() > 0) {
            json_error('Không thể xóa sinh viên đã có dữ liệu học phí.', 'STUDENT_HAS_RECORDS', 409);
        }

        $delStmt = $db->prepare("DELETE FROM students WHERE id = :id");
        $delStmt->execute(['id' => $studentId]);

        log_audit(null, 'delete', 'students', (string)$studentId, [
            'student_code' => $student['student_code'],
            'full_name'    => $student['full_name']
        ]);

        json_success(['message' => "Đã xóa sinh viên '{$student['full_name']}' thành công."]);
    }
}
