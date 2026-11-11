<?php
/**
 * Semesters API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';

function handle_semesters_routes(string $method, string $path, array $matches): void {
    require_admin();
    $db = get_db_connection();

    // 1. GET /api/semesters
    if ($path === '/api/semesters' && $method === 'GET') {
        $stmt = $db->query(
            "SELECT id, term_no, academic_year_start, created_at,
                    CONCAT('Học kỳ ', term_no, ' - Năm học ', academic_year_start, '-', academic_year_start + 1) AS label
             FROM semesters
             ORDER BY academic_year_start DESC, term_no DESC"
        );
        json_success($stmt->fetchAll());
    }

    // 2. POST /api/semesters
    if ($path === '/api/semesters' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['term_no', 'academic_year_start']);

        $termNo = isset($input['term_no']) ? (int)$input['term_no'] : 0;
        $yearStart = isset($input['academic_year_start']) ? (int)$input['academic_year_start'] : 0;

        $errors = [];
        if ($termNo < 1 || $termNo > 3) {
            $errors['term_no'] = 'Học kỳ phải là 1, 2 hoặc 3.';
        }
        if ($yearStart < 2000 || $yearStart > 2100) {
            $errors['academic_year_start'] = 'Năm học bắt đầu không hợp lệ (từ năm 2000 đến 2100).';
        }
        if (!empty($errors)) {
            json_error('Thông tin học kỳ không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra trùng
        $chk = $db->prepare("SELECT id FROM semesters WHERE term_no = :term AND academic_year_start = :year LIMIT 1");
        $chk->execute(['term' => $termNo, 'year' => $yearStart]);
        if ($chk->fetch()) {
            $msg = "Học kỳ {$termNo} năm học {$yearStart}-" . ($yearStart + 1) . " đã tồn tại.";
            json_error($msg, 'DUPLICATE_SEMESTER', 400, ['term_no' => $msg]);
        }

        $stmt = $db->prepare("INSERT INTO semesters (term_no, academic_year_start, created_at) VALUES (:term, :year, NOW())");
        $stmt->execute(['term' => $termNo, 'year' => $yearStart]);
        $newId = (int)$db->lastInsertId();

        log_audit(null, 'create', 'semesters', (string)$newId, ['term_no' => $termNo, 'academic_year_start' => $yearStart]);

        $resStmt = $db->prepare(
            "SELECT id, term_no, academic_year_start, created_at,
                    CONCAT('Học kỳ ', term_no, ' - Năm học ', academic_year_start, '-', academic_year_start + 1) AS label
             FROM semesters WHERE id = :id"
        );
        $resStmt->execute(['id' => $newId]);
        json_success($resStmt->fetch(), 201);
    }

    // 3. PUT /api/semesters/{id}
    if (preg_match('#^/api/semesters/(\d+)$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $id = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['term_no', 'academic_year_start']);

        $termNo = isset($input['term_no']) ? (int)$input['term_no'] : 0;
        $yearStart = isset($input['academic_year_start']) ? (int)$input['academic_year_start'] : 0;

        $errors = [];
        if ($termNo < 1 || $termNo > 3) {
            $errors['term_no'] = 'Học kỳ phải là 1, 2 hoặc 3.';
        }
        if ($yearStart < 2000 || $yearStart > 2100) {
            $errors['academic_year_start'] = 'Năm học bắt đầu không hợp lệ.';
        }
        if (!empty($errors)) {
            json_error('Thông tin học kỳ không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        $chk = $db->prepare("SELECT id FROM semesters WHERE term_no = :term AND academic_year_start = :year AND id != :id LIMIT 1");
        $chk->execute(['term' => $termNo, 'year' => $yearStart, 'id' => $id]);
        if ($chk->fetch()) {
            $msg = "Học kỳ {$termNo} năm học {$yearStart}-" . ($yearStart + 1) . " đã tồn tại.";
            json_error($msg, 'DUPLICATE_SEMESTER', 400, ['term_no' => $msg]);
        }

        $up = $db->prepare("UPDATE semesters SET term_no = :term, academic_year_start = :year WHERE id = :id");
        $up->execute(['term' => $termNo, 'year' => $yearStart, 'id' => $id]);

        log_audit(null, 'update', 'semesters', (string)$id, ['term_no' => $termNo, 'academic_year_start' => $yearStart]);

        $resStmt = $db->prepare(
            "SELECT id, term_no, academic_year_start, created_at,
                    CONCAT('Học kỳ ', term_no, ' - Năm học ', academic_year_start, '-', academic_year_start + 1) AS label
             FROM semesters WHERE id = :id"
        );
        $resStmt->execute(['id' => $id]);
        json_success($resStmt->fetch());
    }

    // 4. DELETE /api/semesters/{id}
    if (preg_match('#^/api/semesters/(\d+)$#', $path, $m) && $method === 'DELETE') {
        verify_csrf_token();
        $id = (int)$m[1];

        // Kiểm tra xem có fee_records hoặc credit_rates nào tham chiếu không
        $chkFee = $db->prepare("SELECT COUNT(*) FROM fee_records WHERE semester_id = :id");
        $chkFee->execute(['id' => $id]);
        if ((int)$chkFee->fetchColumn() > 0) {
            json_error('Không thể xóa học kỳ đã có hồ sơ học phí liên kết.', 'SEMESTER_IN_USE', 409);
        }

        $chkRate = $db->prepare("SELECT COUNT(*) FROM credit_rates WHERE semester_id = :id");
        $chkRate->execute(['id' => $id]);
        if ((int)$chkRate->fetchColumn() > 0) {
            json_error('Không thể xóa học kỳ đã thiết lập định mức tín chỉ.', 'SEMESTER_IN_USE', 409);
        }

        $del = $db->prepare("DELETE FROM semesters WHERE id = :id");
        $del->execute(['id' => $id]);

        log_audit(null, 'delete', 'semesters', (string)$id);
        json_success(['message' => 'Đã xóa học kỳ thành công.']);
    }
}
