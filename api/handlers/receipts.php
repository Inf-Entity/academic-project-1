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
    require_admin();

    if (preg_match('#^/api/receipts/(\d+)$#', $path, $m) && $method === 'GET') {
        $entryId = (int)$m[1];
        $db = get_db_connection();

        $feeSub = get_fee_summary_subquery();
        $stmt = $db->prepare(
            "SELECT 
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
             WHERE l.id = :id"
        );
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch();

        if (!$entry) {
            json_error('Không tìm thấy biên lai thanh toán.', 'NOT_FOUND', 404);
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
