<?php
/**
 * Receipts API Handler: Printable receipt data
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_receipts_routes(string $method, string $path, array $matches): void {
    start_session_safe();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        json_error('Vui lòng đăng nhập để tiếp tục.', 'UNAUTHORIZED', 401);
    }

    $role = $_SESSION['role'];
    $scopedStudentId = null;
    if ($role === 'student') {
        $studentUser = require_student(false);
        $scopedStudentId = (int)$studentUser['student_id'];
    } elseif ($role !== 'admin') {
        json_error('Bạn không có quyền truy cập chức năng này.', 'FORBIDDEN', 403);
    }

    if ($method === 'GET') {
        $rawParam = !empty($matches[2]) ? (string)$matches[2] : (!empty($matches[1]) ? (string)$matches[1] : (string)($_GET['receipt_no'] ?? ($_GET['no'] ?? '')));
        $lookupParam = trim(urldecode($rawParam));

        if (mb_strlen($lookupParam, 'UTF-8') > 100) {
            json_error('Mã tra cứu không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, [
                'receipt_no' => 'Mã tra cứu không được vượt quá 100 ký tự.'
            ]);
        }

        if ($lookupParam === '' || is_search_query_empty_or_diacritics_only($lookupParam)) {
            json_error('Vui lòng cung cấp số biên lai hoặc mã giao dịch.', 'VALIDATION_ERROR', 400, [
                'receipt_no' => 'Vui lòng cung cấp số biên lai.'
            ]);
        }

        $db = get_db_connection();
        $feeSub = get_fee_summary_subquery();
        
        $sql = "SELECT 
                l.id, l.fee_record_id, l.entry_type, l.amount, l.method, l.entry_date, l.receipt_no, l.note, l.created_at,
                u.full_name AS signer_name,
                s.student_code, s.full_name AS student_name, s.class_name,
                sem.term_no, sem.academic_year_start,
                CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label,
                v.net_due, v.total_paid
             FROM ledger_entries l
             JOIN fee_records fr ON l.fee_record_id = fr.id
             JOIN students s ON fr.student_id = s.id
             JOIN semesters sem ON fr.semester_id = sem.id
             JOIN {$feeSub} v ON fr.id = v.fee_record_id
             LEFT JOIN users u ON l.created_by = u.id
             WHERE " . (is_numeric($lookupParam) ? "l.id = :val" : "l.receipt_no = :val");

        $params = ['val' => $lookupParam];
        if ($scopedStudentId !== null) {
            $sql .= " AND fr.student_id = :stId";
            $params['stId'] = $scopedStudentId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $entry = $stmt->fetch();

        if (!$entry) {
            json_error('Không tìm thấy biên lai thanh toán.', 'NOT_FOUND', 404, [
                'receipt_no' => 'Không tìm thấy biên lai thanh toán.'
            ]);
        }

        if ($entry['entry_type'] !== 'payment') {
            json_error('Chỉ các giao dịch thu học phí (payment) mới có biên lai thanh toán.', 'INVALID_RECEIPT_TYPE', 400);
        }

        $config = get_db_config();
        $configPath = dirname(__DIR__, 2) . '/config/config.php';
        $schoolName = '[Tên trường]';
        if (file_exists($configPath)) {
            $fullCfg = require $configPath;
            $schoolName = $fullCfg['app']['school_name'] ?? $schoolName;
        }

        $amount = (int)$entry['amount'];
        $amountWords = number_to_vietnamese_words($amount);
        $remainingBalance = max(0, (int)$entry['net_due'] - (int)$entry['total_paid']);

        json_success([
            'id'                => (int)$entry['id'],
            'fee_record_id'     => (int)$entry['fee_record_id'],
            'receipt_no'        => $entry['receipt_no'],
            'entry_date'        => $entry['entry_date'],
            'student_code'      => $entry['student_code'],
            'student_name'      => $entry['student_name'],
            'class_name'        => $entry['class_name'] ?? '',
            'semester_label'    => $entry['semester_label'],
            'amount'            => $amount,
            'amount_words'      => $amountWords,
            'method'            => $entry['method'],
            'note'              => $entry['note'] ?? '',
            'remaining_balance' => $remainingBalance,
            'signer_name'       => $entry['signer_name'] ?? 'Quản trị viên',
            'school_name'       => $schoolName
        ]);
    }
}
