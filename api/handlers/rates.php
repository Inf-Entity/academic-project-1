<?php
/**
 * Credit Rates API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/csrf.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/audit.php';

function handle_rates_routes(string $method, string $path, array $matches): void {
    require_admin();
    $db = get_db_connection();

    // 1. GET /api/rates
    if ($path === '/api/rates' && $method === 'GET') {
        $stmt = $db->query(
            "SELECT r.id, r.semester_id, r.price_per_credit, r.updated_at,
                    sem.term_no, sem.academic_year_start,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM credit_rates r
             JOIN semesters sem ON r.semester_id = sem.id
             ORDER BY sem.academic_year_start DESC, sem.term_no DESC"
        );
        json_success($stmt->fetchAll());
    }

    // 2. POST /api/rates
    if ($path === '/api/rates' && $method === 'POST') {
        verify_csrf_token();
        $input = get_json_input();
        validate_allowed_fields($input, ['semester_id', 'price_per_credit']);

        $semesterId = isset($input['semester_id']) ? (int)$input['semester_id'] : 0;
        $price = parse_currency($input['price_per_credit'] ?? null);

        $errors = [];
        if ($semesterId <= 0) {
            $errors['semester_id'] = 'Vui lòng chọn học kỳ.';
        }
        if ($price === null || $price <= 0) {
            $errors['price_per_credit'] = 'Đơn giá tín chỉ phải là số nguyên dương lớn hơn 0.';
        }
        if (!empty($errors)) {
            json_error('Thông tin định mức học phí không hợp lệ.', 'VALIDATION_ERROR', 400, $errors);
        }

        // Kiểm tra học kỳ tồn tại
        $chkSem = $db->prepare("SELECT id FROM semesters WHERE id = :id");
        $chkSem->execute(['id' => $semesterId]);
        if (!$chkSem->fetch()) {
            json_error('Học kỳ không tồn tại.', 'NOT_FOUND', 404);
        }

        // Kiểm tra định mức cho học kỳ đã có chưa
        $chkRate = $db->prepare("SELECT id FROM credit_rates WHERE semester_id = :semId");
        $chkRate->execute(['semId' => $semesterId]);
        if ($chkRate->fetch()) {
            json_error('Học kỳ này đã được thiết lập định mức. Vui lòng chỉnh sửa thay vì tạo mới.', 'DUPLICATE_RATE', 409);
        }

        $ins = $db->prepare("INSERT INTO credit_rates (semester_id, price_per_credit, updated_at) VALUES (:semId, :price, NOW())");
        $ins->execute(['semId' => $semesterId, 'price' => $price]);
        $newId = (int)$db->lastInsertId();

        log_audit(null, 'create', 'credit_rates', (string)$newId, ['semester_id' => $semesterId, 'price_per_credit' => $price]);

        $res = $db->prepare(
            "SELECT r.id, r.semester_id, r.price_per_credit, r.updated_at,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM credit_rates r JOIN semesters sem ON r.semester_id = sem.id
             WHERE r.id = :id"
        );
        $res->execute(['id' => $newId]);
        json_success($res->fetch(), 201);
    }

    // 3. PUT /api/rates/{semesterId}
    if (preg_match('#^/api/rates/(\d+)$#', $path, $m) && $method === 'PUT') {
        verify_csrf_token();
        $semesterId = (int)$m[1];
        $input = get_json_input();
        validate_allowed_fields($input, ['price_per_credit']);

        $price = parse_currency($input['price_per_credit'] ?? null);
        if ($price === null || $price <= 0) {
            json_error('Đơn giá tín chỉ phải là số nguyên dương lớn hơn 0.', 'VALIDATION_ERROR', 400, [
                'price_per_credit' => 'Đơn giá tín chỉ không hợp lệ.'
            ]);
        }

        $chk = $db->prepare("SELECT id, price_per_credit FROM credit_rates WHERE semester_id = :semId");
        $chk->execute(['semId' => $semesterId]);
        $existing = $chk->fetch();
        if (!$existing) {
            json_error('Không tìm thấy định mức cho học kỳ này.', 'NOT_FOUND', 404);
        }

        $up = $db->prepare("UPDATE credit_rates SET price_per_credit = :price, updated_at = NOW() WHERE semester_id = :semId");
        $up->execute(['price' => $price, 'semId' => $semesterId]);

        log_audit(null, 'update', 'credit_rates', (string)$existing['id'], [
            'semester_id'      => $semesterId,
            'old_price'        => $existing['price_per_credit'],
            'price_per_credit' => $price
        ]);

        $res = $db->prepare(
            "SELECT r.id, r.semester_id, r.price_per_credit, r.updated_at,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label
             FROM credit_rates r JOIN semesters sem ON r.semester_id = sem.id
             WHERE r.semester_id = :semId"
        );
        $res->execute(['semId' => $semesterId]);
        json_success($res->fetch());
    }

    // 4. DELETE /api/rates/{semesterId}
    if (preg_match('#^/api/rates/(\d+)$#', $path, $m) && $method === 'DELETE') {
        verify_csrf_token();
        $semesterId = (int)$m[1];

        // "Deleting a rate is blocked if any fee_record references its semester."
        $chkFee = $db->prepare("SELECT COUNT(*) FROM fee_records WHERE semester_id = :semId");
        $chkFee->execute(['semId' => $semesterId]);
        if ((int)$chkFee->fetchColumn() > 0) {
            json_error('Không thể xóa định mức học phí của học kỳ đã có hồ sơ học phí.', 'RATE_IN_USE', 409);
        }

        $chk = $db->prepare("SELECT id FROM credit_rates WHERE semester_id = :semId");
        $chk->execute(['semId' => $semesterId]);
        $existing = $chk->fetch();
        if (!$existing) {
            json_error('Không tìm thấy định mức cho học kỳ này.', 'NOT_FOUND', 404);
        }

        $del = $db->prepare("DELETE FROM credit_rates WHERE semester_id = :semId");
        $del->execute(['semId' => $semesterId]);

        log_audit(null, 'delete', 'credit_rates', (string)$existing['id'], ['semester_id' => $semesterId]);
        json_success(['message' => 'Đã xóa định mức học phí thành công.']);
    }
}
