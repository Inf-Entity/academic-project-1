<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
require_once 'api/lib/calc.php';
$feeSub = get_fee_summary_subquery();

// Test without correlated subquery
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
            v.net_due,
            v.total_paid
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
echo "Without subquery: " . round(($t1 - $t0) * 1000, 2) . " ms\n";
