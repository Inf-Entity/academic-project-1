<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
require_once 'api/lib/calc.php';
$feeSub = get_fee_summary_subquery();

$sql = "EXPLAIN SELECT 
            l.id,
            v.net_due
        FROM ledger_entries l
        JOIN fee_records fr ON l.fee_record_id = fr.id
        JOIN {$feeSub} v ON fr.id = v.fee_record_id
        ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC";

$stmt = $pdo->query($sql);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
