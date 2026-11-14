<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
require_once 'api/lib/calc.php';
$feeSub = get_fee_summary_subquery();

$t0 = microtime(true);
$sql = "SELECT STRAIGHT_JOIN 
            l.id,
            l.fee_record_id,
            v.net_due,
            GREATEST(0, v.net_due - (
                SUM(CASE WHEN l.entry_type IN ('payment', 'transfer_in') THEN l.amount WHEN l.entry_type IN ('reversal', 'transfer_out') THEN -l.amount ELSE 0 END) 
                OVER (PARTITION BY l.fee_record_id ORDER BY l.entry_date ASC, l.created_at ASC, l.id ASC)
                - COALESCE((SELECT SUM(rf.amount) FROM refund_requests rf WHERE rf.fee_record_id = l.fee_record_id AND rf.status = 'completed' AND rf.completed_at <= l.created_at), 0)
            )) AS remaining_balance
        FROM ledger_entries l
        JOIN fee_records fr ON l.fee_record_id = fr.id
        JOIN {$feeSub} v ON fr.id = v.fee_record_id
        ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC LIMIT 50";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
$t1 = microtime(true);
echo "Count: " . count($rows) . " | With window func: " . round(($t1 - $t0) * 1000, 2) . " ms\n";
