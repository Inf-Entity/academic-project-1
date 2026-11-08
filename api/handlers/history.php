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

        // Truy vấn danh sách giao dịch từ ledger_entries kèm v_fee_record_summaries để lấy công nợ
        $sql = "SELECT 
                    l.id,
                    l.fee_record_id,
                    l.entry_type AS type,
                    l.amount,
                    l.method,
                    l.entry_date,
                    l.receipt_no,
                    l.reverses_entry_id,
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
                    GREATEST(0, v.net_due - v.total_paid) AS remaining_balance
                FROM ledger_entries l
                JOIN fee_records fr ON l.fee_record_id = fr.id
                JOIN students s ON fr.student_id = s.id
                JOIN semesters sem ON fr.semester_id = sem.id
                JOIN v_fee_record_summaries v ON fr.id = v.fee_record_id
                LEFT JOIN users u ON l.created_by = u.id
                WHERE 1=1";
        $params = [];

        if ($q !== '') {
            $normQ = normalize_vietnamese($q);
            $sql .= " AND (s.student_code LIKE :q_code OR s.name_search LIKE :q_name)";
            $params['q_code'] = '%' . strtoupper($q) . '%';
            $params['q_name'] = '%' . $normQ . '%';
        } elseif ($student !== '' && $name !== '' && $student === $name) {
            // Khi client gửi cùng chuỗi tìm kiếm cho cả student và name
            $norm = normalize_vietnamese($student);
            $sql .= " AND (s.student_code LIKE :st_code OR s.name_search LIKE :st_name)";
            $params['st_code'] = '%' . strtoupper($student) . '%';
            $params['st_name'] = '%' . $norm . '%';
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

        if ($from !== '') {
            $sql .= " AND l.entry_date >= :from_date";
            $params['from_date'] = $from;
        }

        if ($to !== '') {
            $sql .= " AND l.entry_date <= :to_date";
            $params['to_date'] = $to;
        }

        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            $sLower = strtolower($statusFilter);
            if (in_array($sLower, ['payment', 'thu học phí'], true)) {
                $sql .= " AND l.entry_type = 'payment'";
            } elseif (in_array($sLower, ['reversal', 'đảo mục', 'hủy'], true)) {
                $sql .= " AND l.entry_type = 'reversal'";
            } elseif (in_array($sLower, ['unpaid', 'chưa thanh toán'], true)) {
                $sql .= " AND v.total_paid <= 0 AND v.net_due > 0";
            } elseif (in_array($sLower, ['partially paid', 'thanh toán một phần', 'partial'], true)) {
                $sql .= " AND v.total_paid > 0 AND v.total_paid < v.net_due";
            } elseif (in_array($sLower, ['fully paid', 'đã thanh toán đủ', 'paid'], true)) {
                $sql .= " AND v.total_paid >= v.net_due";
            } elseif (in_array($sLower, ['overpaid', 'thừa tiền', 'nộp thừa'], true)) {
                $sql .= " AND v.total_paid > v.net_due";
            }
        }

        $sql .= " ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        json_success($rows);
    }
}
