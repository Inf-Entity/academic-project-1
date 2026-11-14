<?php
/**
 * EXPLAIN Query Analysis before and after index optimization
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/calc.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sub = get_fee_summary_subquery();

echo "========================================================\n";
echo "1. PHÂN TÍCH EXPLAIN TRƯỚC KHI THÊM INDEX TỐI ƯU\n";
echo "========================================================\n";

$sqlRep = "EXPLAIN SELECT COUNT(*), SUM(v.net_due), SUM(v.total_paid) FROM ({$sub}) v";
$explainRepBefore = $pdo->query($sqlRep)->fetchAll();
echo "--- EXPLAIN Báo cáo tổng hợp (/api/reports/summary) ---\n";
print_r($explainRepBefore);

$t0 = microtime(true);
$pdo->query("SELECT COUNT(*), SUM(v.net_due), SUM(v.total_paid) FROM ({$sub}) v")->fetchAll();
$timeRepBefore = round((microtime(true) - $t0) * 1000, 2);
echo "Thời gian thực thi /api/reports/summary (Trước): {$timeRepBefore} ms\n\n";

$sqlHist = "EXPLAIN SELECT l.id, l.entry_date, l.amount, s.student_code FROM ledger_entries l JOIN fee_records fr ON l.fee_record_id = fr.id JOIN students s ON fr.student_id = s.id ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC LIMIT 100";
$explainHistBefore = $pdo->query($sqlHist)->fetchAll();
echo "--- EXPLAIN Lịch sử giao dịch (/api/history) ---\n";
print_r($explainHistBefore);

$t1 = microtime(true);
$pdo->query("SELECT l.id, l.entry_date, l.amount, s.student_code FROM ledger_entries l JOIN fee_records fr ON l.fee_record_id = fr.id JOIN students s ON fr.student_id = s.id ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC LIMIT 100")->fetchAll();
$timeHistBefore = round((microtime(true) - $t1) * 1000, 2);
echo "Thời gian thực thi /api/history (Trước): {$timeHistBefore} ms\n\n";

echo "========================================================\n";
echo "2. THÊM CÁC INDEX TỐI ƯU CHO HOT PATH TRÊN BẢNG LỚN\n";
echo "========================================================\n";

// Các index tối ưu hóa:
// 1. idx_ledger_perf_agg: Tối ưu subquery GROUP BY fee_record_id WHERE entry_type = 'payment' / 'reversal'
// 2. idx_ledger_perf_order: Tối ưu ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC
try {
    $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_fee_type_amount (fee_record_id, entry_type, amount)");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_order (entry_date, created_at, id)");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE refund_requests ADD INDEX idx_refund_status_fee (status, fee_record_id, amount)");
} catch (Exception $e) {}

echo "Đã thêm 2 composite indexes vào ledger_entries.\n\n";

echo "========================================================\n";
echo "3. PHÂN TÍCH EXPLAIN SAU KHI THÊM INDEX TỐI ƯU\n";
echo "========================================================\n";

$explainRepAfter = $pdo->query($sqlRep)->fetchAll();
echo "--- EXPLAIN Báo cáo tổng hợp (/api/reports/summary) (Sau) ---\n";
print_r($explainRepAfter);

$t2 = microtime(true);
$pdo->query("SELECT COUNT(*), SUM(v.net_due), SUM(v.total_paid) FROM ({$sub}) v")->fetchAll();
$timeRepAfter = round((microtime(true) - $t2) * 1000, 2);
echo "Thời gian thực thi /api/reports/summary (Sau): {$timeRepAfter} ms\n\n";

$explainHistAfter = $pdo->query($sqlHist)->fetchAll();
echo "--- EXPLAIN Lịch sử giao dịch (/api/history) (Sau) ---\n";
print_r($explainHistAfter);

$t3 = microtime(true);
$pdo->query("SELECT l.id, l.entry_date, l.amount, s.student_code FROM ledger_entries l JOIN fee_records fr ON l.fee_record_id = fr.id JOIN students s ON fr.student_id = s.id ORDER BY l.entry_date DESC, l.created_at DESC, l.id DESC LIMIT 100")->fetchAll();
$timeHistAfter = round((microtime(true) - $t3) * 1000, 2);
echo "Thời gian thực thi /api/history (Sau): {$timeHistAfter} ms\n\n";

$analysisData = [
    'reports_summary' => [
        'before_ms' => $timeRepBefore,
        'after_ms' => $timeRepAfter,
        'speedup' => round($timeRepBefore / max(0.1, $timeRepAfter), 2) . 'x',
        'explain_before' => $explainRepBefore,
        'explain_after' => $explainRepAfter,
    ],
    'history' => [
        'before_ms' => $timeHistBefore,
        'after_ms' => $timeHistAfter,
        'speedup' => round($timeHistBefore / max(0.1, $timeHistAfter), 2) . 'x',
        'explain_before' => $explainHistBefore,
        'explain_after' => $explainHistAfter,
    ],
    'indexes_added' => [
        'ALTER TABLE ledger_entries ADD INDEX idx_ledger_fee_type_amount (fee_record_id, entry_type, amount);',
        'ALTER TABLE ledger_entries ADD INDEX idx_ledger_order (entry_date, created_at, id);',
    ]
];

file_put_contents(__DIR__ . '/explain_analysis.json', json_encode($analysisData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Đã lưu kết quả phân tích vào explain_analysis.json\n";
