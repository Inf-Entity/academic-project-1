<?php
/**
 * Payment and Transaction History API Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/validate.php';
require_once dirname(__DIR__) . '/lib/response.php';
require_once dirname(__DIR__) . '/lib/calc.php';

function handle_history_routes(string $method, string $path): void {
    require_admin();
    $db = get_db_connection();

    if ($path === '/api/history' && $method === 'GET') {
        $student = trim((string)($_GET['student'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $name = trim((string)($_GET['name'] ?? ''));
        $semester = trim((string)($_GET['semester'] ?? ''));
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ($_GET['type'] ?? '')));

        if (mb_strlen($q, 'UTF-8') > 100) {
            json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, ['q' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.']);
        }
        if (mb_strlen($student, 'UTF-8') > 100) {
            json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, ['student' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.']);
        }
        if (mb_strlen($name, 'UTF-8') > 100) {
            json_error('Từ khóa tìm kiếm không được vượt quá 100 ký tự.', 'VALIDATION_ERROR', 400, ['name' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.']);
        }

        if (is_search_query_empty_or_diacritics_only($q)) {
            $q = '';
        }
        if (is_search_query_empty_or_diacritics_only($student)) {
            $student = '';
        }
        if (is_search_query_empty_or_diacritics_only($name)) {
            $name = '';
        }

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

        if ($parsedFrom !== null && $parsedTo !== null && $parsedTo < $parsedFrom) {
            json_error('Đến ngày không được nhỏ hơn từ ngày.', 'VALIDATION_ERROR', 400, [
                'to' => 'Đến ngày không được nhỏ hơn từ ngày.'
            ]);
        }

        if ($semester !== '' && $semester !== 'ALL') {
            if (!is_numeric($semester) || (int)$semester <= 0) {
                json_error('Vui lòng chọn học kỳ hợp lệ.', 'VALIDATION_ERROR', 400, [
                    'semester' => 'Vui lòng chọn học kỳ.'
                ]);
            }
        }

        // Truy vấn danh sách giao dịch từ ledger_entries kèm subquery tổng hợp công nợ
        $feeSub = get_fee_summary_subquery();
        $sql = "SELECT 
                    l.id,
                    l.fee_record_id,
                    l.entry_type AS type,
                    l.amount,
                    l.method,
                    l.entry_date,
                    l.receipt_no,
                    l.reverses_entry_id,
                    l.transfer_group_id,
                    l.reason,
                    l.note,
                    l.request_id,
                    l.created_at,
                    u.full_name AS created_by_name,
                    s.id AS student_id,
                    s.student_code,
                    s.full_name AS student_name,
                    s.class_name,
                    sem.id AS semester_id,
                    CONCAT('Học kỳ ', sem.term_no, ' - Năm học ', sem.academic_year_start, '-', sem.academic_year_start + 1) AS semester_label,
                    v.net_due,
                    v.total_paid,
                    GREATEST(0, v.net_due - (
                        SUM(CASE WHEN l.entry_type IN ('payment', 'transfer_in') THEN l.amount WHEN l.entry_type IN ('reversal', 'transfer_out') THEN -l.amount ELSE 0 END) 
                        OVER (PARTITION BY l.fee_record_id ORDER BY l.entry_date ASC, l.created_at ASC, l.id ASC)
                        - COALESCE((SELECT SUM(rf.amount) FROM refund_requests rf WHERE rf.fee_record_id = l.fee_record_id AND rf.status = 'completed' AND rf.completed_at <= l.created_at), 0)
                    )) AS remaining_balance
                FROM ledger_entries l
                JOIN fee_records fr ON l.fee_record_id = fr.id
                JOIN students s ON fr.student_id = s.id
                JOIN semesters sem ON fr.semester_id = sem.id
                JOIN {$feeSub} v ON fr.id = v.fee_record_id
                LEFT JOIN users u ON l.created_by = u.id
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $normQ = normalize_vietnamese($q);
            $sql .= " AND (s.student_code LIKE :q_code OR s.name_search LIKE :q_name OR l.receipt_no LIKE :q_rcpt)";
            $params['q_code'] = '%' . strtoupper($q) . '%';
            $params['q_name'] = '%' . $normQ . '%';
            $params['q_rcpt'] = '%' . strtoupper($q) . '%';
        } elseif ($student !== '' && $name !== '' && $student === $name) {
            // Khi client gửi cùng chuỗi tìm kiếm cho cả student và name
            $norm = normalize_vietnamese($student);
            $sql .= " AND (s.student_code LIKE :st_code OR s.name_search LIKE :st_name OR l.receipt_no LIKE :st_rcpt)";
            $params['st_code'] = '%' . strtoupper($student) . '%';
            $params['st_name'] = '%' . $norm . '%';
            $params['st_rcpt'] = '%' . strtoupper($student) . '%';
        } else {
            if ($student !== '' && $student !== 'ALL') {
                if (is_numeric($student)) {
                    $sql .= " AND s.id = :student_id";
                    $params['student_id'] = (int)$student;
                } else {
                    $sql .= " AND s.student_code = :student_code";
                    $params['student_code'] = strtoupper($student);
                }
            }

            if ($name !== '') {
                $normName = normalize_vietnamese($name);
                $sql .= " AND s.name_search LIKE :norm_name";
                $params['norm_name'] = '%' . $normName . '%';
            }
        }

        if ($semester !== '' && $semester !== 'ALL') {
            $sql .= " AND sem.id = :sem_id";
            $params['sem_id'] = (int)$semester;
        }

        if ($parsedFrom !== null) {
            $sql .= " AND l.entry_date >= :from_date";
            $params['from_date'] = $parsedFrom;
        }

        if ($parsedTo !== null) {
            $sql .= " AND l.entry_date <= :to_date";
            $params['to_date'] = $parsedTo;
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            $sLower = mb_strtolower($statusFilter, 'UTF-8');
            if (in_array($sLower, ['payment', 'thu học phí'], true)) {
                $sql .= " AND l.entry_type = 'payment'";
            } elseif (in_array($sLower, ['reversal', 'đảo mục', 'hủy'], true)) {
                $sql .= " AND l.entry_type = 'reversal'";
            } elseif (in_array($sLower, ['transfer', 'chuyển khoản', 'chuyển khoản nội bộ'], true)) {
                $sql .= " AND l.entry_type IN ('transfer_out', 'transfer_in')";
            } elseif (in_array($sLower, ['unpaid', 'chưa thanh toán'], true)) {
                $sql .= " AND v.derived_status = 'Unpaid'";
            } elseif (in_array($sLower, ['partially paid', 'thanh toán một phần', 'partial'], true)) {
                $sql .= " AND v.derived_status = 'Partially Paid'";
            } elseif (in_array($sLower, ['fully paid', 'đã thanh toán đủ', 'paid'], true)) {
                $sql .= " AND v.derived_status = 'Fully Paid'";
            } elseif (in_array($sLower, ['overpaid', 'thừa tiền', 'nộp thừa'], true)) {
                $sql .= " AND v.derived_status = 'Overpaid'";
            }
        }

        $sql .= " ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        json_success($rows);
    }
}
