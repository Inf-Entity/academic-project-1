<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
require_once 'api/lib/calc.php';
$feeSub = get_fee_summary_subquery();

// Test 1: Full query as in history.php
$t0 = microtime(true);
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
        ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
$t1 = microtime(true);
echo "Count: " . count($rows) . " | Full SQL: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

$t2 = microtime(true);
$json = json_encode($rows);
$t3 = microtime(true);
echo "JSON encode: " . round(($t3 - $t2) * 1000, 2) . " ms | Length: " . strlen($json) . " bytes\n";
