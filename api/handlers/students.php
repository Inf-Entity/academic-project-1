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

    // 1b. GET /api/students/search (Combobox Search for fee record creation)
    if ($path === '/api/students/search' && $method === 'GET') {
        $rawQ = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $trimmed = trim($rawQ);
        if (mb_strlen($trimmed, 'UTF-8') > 100) {
            json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, [
                'q' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.'
            ]);
        }

        if ($trimmed === '') {
            send_security_headers();
            http_response_code(200);
            echo json_encode([
                'ok'      => true,
                'data'    => [],
                'message' => 'Nhập mã hoặc tên để tìm sinh viên.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $norm = normalize_vietnamese($trimmed);
        $stmt = $db->prepare(
            "SELECT id, student_code, full_name, class_name
             FROM students
             WHERE student_code LIKE :code OR name_search LIKE :q
             ORDER BY student_code ASC
             LIMIT 20"
        );
        $stmt->execute([
            'code' => '%' . strtoupper($trimmed) . '%',
            'q'    => '%' . $norm . '%'
        ]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
        }
        json_success($rows, 200);
    }

    // 1. GET /api/students (List & Search)
    if ($path === '/api/students' && $method === 'GET') {
        $rawQ = (string)($_GET['q'] ?? '');
        if (mb_strlen($rawQ, 'UTF-8') > 100) {
            json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, [
                'q' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.'
            ]);
        }

        $feeSub = get_fee_summary_subquery();

        // Thống kê toàn hệ thống xuyên suốt (System-wide student financial KPI metrics)
        $statsStmt = $db->query("
            SELECT 
                COUNT(*) AS total_students_count,
                COUNT(CASE WHEN fee_records_count > 0 THEN 1 END) AS with_records_count,
                COUNT(CASE WHEN payment_status = 'Đã thanh toán đủ' THEN 1 END) AS fully_paid_count,
                COUNT(CASE WHEN total_outstanding > 0 THEN 1 END) AS outstanding_count,
                COUNT(CASE WHEN total_overpaid > 0 THEN 1 END) AS overpaid_count
            FROM (
                SELECT 
                    s.id,
                    COUNT(v.fee_record_id) AS fee_records_count,
                    COALESCE(SUM(v.outstanding_amount), 0) AS total_outstanding,
                    COALESCE(SUM(v.overpaid_amount), 0) AS total_overpaid,
                    CASE 
                        WHEN COUNT(v.fee_record_id) = 0 THEN 'Chưa có hồ sơ'
                        WHEN SUM(v.outstanding_amount) = 0 THEN 'Đã thanh toán đủ'
                        WHEN SUM(v.total_paid) = 0 AND SUM(v.net_due) > 0 THEN 'Chưa thanh toán'
                        ELSE 'Thanh toán một phần'
                    END AS payment_status
                FROM students s
                LEFT JOIN {$feeSub} v ON s.id = v.student_id
                GROUP BY s.id
            ) t
        ");
        $stats = $statsStmt->fetch() ?: [
            'total_students_count' => 0,
            'with_records_count'   => 0,
            'fully_paid_count'     => 0,
            'outstanding_count'    => 0,
            'overpaid_count'       => 0,
        ];
        $totalStudentsCount = (int)$stats['total_students_count'];
        $withRecordsCount   = (int)$stats['with_records_count'];
        $fullyPaidCount     = (int)$stats['fully_paid_count'];
        $outstandingCount   = (int)$stats['outstanding_count'];
        $overpaidCount      = (int)$stats['overpaid_count'];

        $selectFields = "
            s.id, s.student_code, s.full_name, s.class_name, s.created_at, s.updated_at,
            CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END AS has_account,
            u.username AS account_username,
            COUNT(v.fee_record_id) AS fee_records_count,
            COALESCE(SUM(v.net_due), 0) AS total_net_due,
            COALESCE(SUM(v.total_paid), 0) AS total_paid,
            COALESCE(SUM(v.completed_refunds_sum), 0) AS total_refunded,
            COALESCE(SUM(v.outstanding_amount), 0) AS total_outstanding,
            COALESCE(SUM(v.overpaid_amount), 0) AS total_overpaid,
            CASE 
                WHEN COUNT(v.fee_record_id) = 0 THEN 'Chưa có hồ sơ'
                WHEN SUM(v.outstanding_amount) = 0 THEN 'Đã thanh toán đủ'
                WHEN SUM(v.total_paid) = 0 AND SUM(v.net_due) > 0 THEN 'Chưa thanh toán'
                ELSE 'Thanh toán một phần'
            END AS payment_status,
            CASE 
                WHEN COUNT(v.fee_record_id) = 0 THEN 'Chưa có hồ sơ'
                WHEN SUM(v.outstanding_amount) = 0 THEN 'Đã thanh toán đủ'
                WHEN SUM(v.total_paid) = 0 AND SUM(v.net_due) > 0 THEN 'Chưa thanh toán'
                ELSE 'Thanh toán một phần'
            END AS payment_status_label,
            {$totalStudentsCount} AS total_students_count,
            {$withRecordsCount} AS with_records_count,
            {$fullyPaidCount} AS fully_paid_count,
            {$outstandingCount} AS outstanding_count,
            {$overpaidCount} AS overpaid_count
        ";

        if (!is_search_query_empty_or_diacritics_only($rawQ)) {
            $norm = normalize_vietnamese($rawQ);
            $stmt = $db->prepare(
                "SELECT {$selectFields}
                 FROM students s
                 LEFT JOIN users u ON u.student_id = s.id
                 LEFT JOIN {$feeSub} v ON s.id = v.student_id
                 WHERE s.student_code LIKE :code OR s.name_search LIKE :q
                 GROUP BY s.id, s.student_code, s.full_name, s.class_name, s.created_at, s.updated_at, u.id, u.username
                 ORDER BY s.id ASC"
            );
            $stmt->execute([
                'code' => '%' . strtoupper(trim($rawQ)) . '%',
                'q'    => '%' . $norm . '%'
            ]);
        } else {
            $stmt = $db->query(
                "SELECT {$selectFields}
                 FROM students s
                 LEFT JOIN users u ON u.student_id = s.id
                 LEFT JOIN {$feeSub} v ON s.id = v.student_id
                 GROUP BY s.id, s.student_code, s.full_name, s.class_name, s.created_at, s.updated_at, u.id, u.username
                 ORDER BY s.id ASC"
            );
        }

        $students = $stmt->fetchAll();
        foreach ($students as &$st) {
            $st['id'] = (int)$st['id'];
            $st['has_account'] = (int)$st['has_account'];
            $st['fee_records_count'] = (int)$st['fee_records_count'];
            $st['total_net_due'] = (int)$st['total_net_due'];
            $st['total_paid'] = (int)$st['total_paid'];
            $st['total_refunded'] = (int)$st['total_refunded'];
            $st['total_outstanding'] = (int)$st['total_outstanding'];
            $st['total_overpaid'] = (int)$st['total_overpaid'];
            $st['total_students_count'] = (int)$st['total_students_count'];
            $st['with_records_count'] = (int)$st['with_records_count'];
            $st['fully_paid_count'] = (int)$st['fully_paid_count'];
            $st['outstanding_count'] = (int)$st['outstanding_count'];
            $st['overpaid_count'] = (int)$st['overpaid_count'];
        }
        unset($st);

        json_success($students, 200, [
            'summary' => [
                'total_students_count' => $totalStudentsCount,
                'with_records_count'   => $withRecordsCount,
                'fully_paid_count'     => $fullyPaidCount,
                'outstanding_count'    => $outstandingCount,
                'overpaid_count'       => $overpaidCount,
            ]
        ]);
    }

    // 2. POST /api/students (Create)
    if ($path === '/api/students' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['student_code', 'full_name', 'class_name']);

        $rawCode = isset($input['student_code']) ? trim((string)$input['student_code']) : '';
        $code = validate_student_code($rawCode);
        $nameError = null;
        $name = validate_full_name($input['full_name'] ?? null, $nameError);
        $class = isset($input['class_name']) ? trim((string)$input['class_name']) : null;
        if ($class === '') {
            $class = null;
        }

        $errors = [];
        if ($rawCode === '') {
            $errors['student_code'] = 'Mã sinh viên không được để trống.';
        } elseif (!$code) {
            $errors['student_code'] = 'Mã sinh viên phải gồm 6–12 ký tự chữ và số.';
        }
        if ($nameError !== null) {
            $errors['full_name'] = $nameError;
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
            json_error("Mã sinh viên '{$code}' đã tồn tại trong hệ thống.", 'DUPLICATE_CODE', 400, [
                'student_code' => 'Mã sinh viên đã tồn tại.'
            ]);
        }

        $nameSearch = generate_name_search($name);
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

        $rawCode = isset($input['student_code']) ? trim((string)$input['student_code']) : $existing['student_code'];
        $code = validate_student_code($rawCode);
        $nameError = null;
        $name = isset($input['full_name']) ? validate_full_name($input['full_name'], $nameError) : $existing['full_name'];
        $class = isset($input['class_name']) ? trim((string)$input['class_name']) : $existing['class_name'];
        if ($class === '') {
            $class = null;
        }

        $errors = [];
        if ($rawCode === '') {
            $errors['student_code'] = 'Mã sinh viên không được để trống.';
        } elseif (!$code) {
            $errors['student_code'] = 'Mã sinh viên phải gồm 6–12 ký tự chữ và số.';
        }
        if ($nameError !== null) {
            $errors['full_name'] = $nameError;
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
                json_error("Mã sinh viên '{$code}' đã tồn tại trong hệ thống.", 'DUPLICATE_CODE', 400, [
                    'student_code' => 'Mã sinh viên đã tồn tại.'
                ]);
            }
        }

        $nameSearch = generate_name_search($name);
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

        // Xóa an toàn trong giao dịch bảo toàn toàn vẹn tham chiếu
        db_transaction(function (PDO $pdo) use ($studentId) {
            // Xóa tài khoản sinh viên nếu có trước khi xóa hồ sơ sinh viên
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE student_id = :id");
            $userStmt->execute(['id' => $studentId]);
            $studentUser = $userStmt->fetch();
            if ($studentUser) {
                $studentUserId = (int)$studentUser['id'];
                $pdo->prepare("DELETE FROM user_nav_state WHERE user_id = :uid")->execute(['uid' => $studentUserId]);
                $pdo->prepare("UPDATE audit_log SET user_id = NULL WHERE user_id = :uid")->execute(['uid' => $studentUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = :uid")->execute(['uid' => $studentUserId]);
            }

            $delStmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
            $delStmt->execute(['id' => $studentId]);
        }, $db);

        log_audit(null, 'delete', 'students', (string)$studentId, [
            'student_code' => $student['student_code'],
            'full_name'    => $student['full_name']
        ]);

        json_success(['message' => "Đã xóa sinh viên '{$student['full_name']}' thành công."]);
    }

    // 7. POST /api/students/{id}/account (Tạo tài khoản sinh viên)
    if (preg_match('#^/api/students/(\d+)/account$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $studentId = (int)$m[1];

        $stmt = $db->prepare("SELECT id, student_code, full_name FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }

        // Kiểm tra xem sinh viên đã có tài khoản chưa
        $chkAccount = $db->prepare("SELECT id, username FROM users WHERE student_id = :id");
        $chkAccount->execute(['id' => $studentId]);
        if ($chkAccount->fetch()) {
            json_error('Sinh viên này đã có tài khoản.', 'DUPLICATE_ACCOUNT', 409);
        }

        // Tên đăng nhập = mã sinh viên viết thường
        $username = strtolower(trim($student['student_code']));

        // Kiểm tra trùng username
        $chkU = $db->prepare("SELECT id FROM users WHERE username = :u");
        $chkU->execute(['u' => $username]);
        if ($chkU->fetch()) {
            json_error("Tên đăng nhập '{$username}' đã tồn tại trong hệ thống.", 'DUPLICATE_USERNAME', 409);
        }

        // Tạo mật khẩu tạm thời 12 ký tự ngẫu nhiên
        $tempPassword = generate_student_temporary_password();
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $adminUser = get_session_user();
        $adminId = $adminUser['id'] ?? null;

        $insUser = $db->prepare(
            "INSERT INTO users (username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until, created_at)
             VALUES (:username, :hash, :full_name, 'student', :student_id, 1, 0, NULL, NOW())"
        );
        $insUser->execute([
            'username'   => $username,
            'hash'       => $passwordHash,
            'full_name'  => $student['full_name'],
            'student_id' => $studentId,
        ]);

        log_audit($adminId, 'create_account', 'students', (string)$studentId, [
            'username'     => $username,
            'student_code' => $student['student_code']
        ]);

        json_success([
            'student_id'           => $studentId,
            'username'             => $username,
            'temporary_password'   => $tempPassword,
            'must_change_password' => 1,
            'message'              => 'Tạo tài khoản sinh viên thành công.'
        ], 201);
    }

    // 8. POST /api/students/{id}/account/reset (Đặt lại mật khẩu sinh viên)
    if (preg_match('#^/api/students/(\d+)/account/reset$#', $path, $m) && $method === 'POST') {
        verify_csrf_token();
        $studentId = (int)$m[1];

        $stmt = $db->prepare("SELECT id, student_code, full_name FROM students WHERE id = :id");
        $stmt->execute(['id' => $studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            json_error('Không tìm thấy sinh viên.', 'NOT_FOUND', 404);
        }

        $chkAccount = $db->prepare("SELECT id, username FROM users WHERE student_id = :id");
        $chkAccount->execute(['id' => $studentId]);
        $userRow = $chkAccount->fetch();
        if (!$userRow) {
            json_error('Sinh viên chưa có tài khoản.', 'ACCOUNT_NOT_FOUND', 404);
        }

        $tempPassword = generate_student_temporary_password();
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

        $adminUser = get_session_user();
        $adminId = $adminUser['id'] ?? null;

        $upStmt = $db->prepare(
            "UPDATE users SET password_hash = :hash, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = :uid"
        );
        $upStmt->execute([
            'hash' => $passwordHash,
            'uid'  => $userRow['id']
        ]);

        log_audit($adminId, 'reset_account_password', 'students', (string)$studentId, [
            'username'     => $userRow['username'],
            'student_code' => $student['student_code']
        ]);

        json_success([
            'student_id'           => $studentId,
            'username'             => $userRow['username'],
            'temporary_password'   => $tempPassword,
            'must_change_password' => 1,
            'message'              => 'Đặt lại mật khẩu sinh viên thành công.'
        ], 200);
    }
}

/**
 * Sinh mật khẩu tạm thời 12 ký tự ngẫu nhiên (chữ hoa, chữ thường, số, ký tự đặc biệt) qua random_bytes
 */
function generate_student_temporary_password(): string {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%^&*';
    $all = $upper . $lower . $digits . $symbols;

    $pwd = [
        $upper[ord(random_bytes(1)) % strlen($upper)],
        $lower[ord(random_bytes(1)) % strlen($lower)],
        $digits[ord(random_bytes(1)) % strlen($digits)],
        $symbols[ord(random_bytes(1)) % strlen($symbols)],
    ];

    $bytes = random_bytes(8);
    for ($i = 0; $i < 8; $i++) {
        $pwd[] = $all[ord($bytes[$i]) % strlen($all)];
    }

    for ($i = count($pwd) - 1; $i > 0; $i--) {
        $j = ord(random_bytes(1)) % ($i + 1);
        $tmp = $pwd[$i];
        $pwd[$i] = $pwd[$j];
        $pwd[$j] = $tmp;
    }

    return implode('', $pwd);
}
