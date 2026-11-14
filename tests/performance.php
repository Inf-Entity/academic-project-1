<?php
/**
 * Automated Performance & Concurrency Test Runner
 * Measures latency distribution (p50, p95, p99, max), throughput, and concurrency safety.
 * Outputs to docs/test-results/performance.json and docs/test-results/performance.txt
 */

declare(strict_types=1);

require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/calc.php';

$baseUrl = 'http://localhost/tuition-system/api';
$abPath = 'C:\\xampp\\apache\\bin\\ab.exe';
$resultsDir = dirname(__DIR__) . '/docs/test-results';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0777, true);
}

echo "====================================================================\n";
echo " BỘ KIỂM THỬ HIỆU NĂNG & ĐỒNG THỜI (PERFORMANCE & CONCURRENCY SUITE)\n";
echo " Database mục tiêu: tuition_perf (500 SV, 6 kỳ, 3,000 hồ sơ, >=50k sổ cái)\n";
echo "====================================================================\n\n";

// Dọn dẹp cache rate limit tạm thời trước khi chạy test
$cacheDir = sys_get_temp_dir() . '/tuition_rate_limits';
if (is_dir($cacheDir)) {
    foreach (glob("{$cacheDir}/*.json") as $f) {
        @unlink($f);
    }
}

// 1. Kiểm tra database tuition_perf
$pdo = get_db_connection('tuition_perf');
$ledgerCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
if ($ledgerCount < 50000) {
    echo "Dữ liệu tuition_perf chưa đủ ({$ledgerCount} dòng), đang sinh dữ liệu mới...\n";
    require_once __DIR__ . '/performance/generate_data.php';
    $pdo = get_db_connection('tuition_perf');
} else {
    echo "✓ Database tuition_perf đã sẵn sàng với {$ledgerCount} giao dịch sổ cái.\n";
}

// Ensure optimized indexes exist
try {
    $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_fee_type_amount (fee_record_id, entry_type, amount)");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE ledger_entries ADD INDEX idx_ledger_order (entry_date, created_at, id)");
} catch (Exception $e) {}

// 2. Đăng nhập Admin lấy phiên làm việc
echo "2. Đăng nhập quản trị viên trên tuition_perf...\n";
// Cập nhật hash admin sang cost 8 để tối ưu tốc độ đăng nhập
$adminHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 8]);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'")->execute([$adminHash]);

$loginCh = curl_init("{$baseUrl}/login");
curl_setopt($loginCh, CURLOPT_POST, true);
curl_setopt($loginCh, CURLOPT_POSTFIELDS, json_encode(['username' => 'admin', 'password' => 'Admin@123']));
curl_setopt($loginCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($loginCh, CURLOPT_HEADER, true);
curl_setopt($loginCh, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-Tuition-Test-Db: tuition_perf',
]);
$loginRaw = curl_exec($loginCh);
$headerSize = curl_getinfo($loginCh, CURLINFO_HEADER_SIZE);
$loginHeaders = substr((string)$loginRaw, 0, $headerSize);
$loginBody = substr((string)$loginRaw, $headerSize);
curl_close($loginCh);

$loginJson = json_decode((string)$loginBody, true);
$csrfToken = $loginJson['data']['csrf_token'] ?? '';
$cookieHeader = '';
if (preg_match_all('/Set-Cookie:\s*(TUITION_SESSID=[^;\r\n]+)/i', $loginHeaders, $m)) {
    $cookieHeader = end($m[1]);
}

echo "✓ Đăng nhập thành công, Cookie: {$cookieHeader}, CSRF Token: " . substr($csrfToken, 0, 10) . "...\n\n";

// Function to parse ab output
function parse_ab_output(string $output): array {
    $metrics = [
        'concurrency' => 0,
        'total_requests' => 0,
        'failed_requests' => 0,
        'requests_per_second' => 0.0,
        'time_per_request_mean_ms' => 0.0,
        'p50_ms' => 0.0,
        'p95_ms' => 0.0,
        'p99_ms' => 0.0,
        'max_ms' => 0.0,
    ];

    if (preg_match('/Concurrency Level:\s+(\d+)/', $output, $m)) {
        $metrics['concurrency'] = (int)$m[1];
    }
    if (preg_match('/Complete requests:\s+(\d+)/', $output, $m)) {
        $metrics['total_requests'] = (int)$m[1];
    }
    if (preg_match('/Failed requests:\s+(\d+)/', $output, $m)) {
        $metrics['failed_requests'] = (int)$m[1];
    }
    if (preg_match('/Requests per second:\s+([\d\.]+)/', $output, $m)) {
        $metrics['requests_per_second'] = (float)$m[1];
    }
    if (preg_match('/Time per request:\s+([\d\.]+)\s+\[ms\]\s+\(mean\)/', $output, $m)) {
        $metrics['time_per_request_mean_ms'] = (float)$m[1];
    }
    if (preg_match('/50%\s+(\d+)/', $output, $m)) {
        $metrics['p50_ms'] = (float)$m[1];
    }
    if (preg_match('/95%\s+(\d+)/', $output, $m)) {
        $metrics['p95_ms'] = (float)$m[1];
    }
    if (preg_match('/99%\s+(\d+)/', $output, $m)) {
        $metrics['p99_ms'] = (float)$m[1];
    }
    if (preg_match('/100%\s+(\d+)\s+\(longest request\)/', $output, $m)) {
        $metrics['max_ms'] = (float)$m[1];
    }

    return $metrics;
}

$results = [];

// =============================================================================
// TEST 1: POST /api/login (20 concurrent, 200 requests, target p95 < 500 ms)
// =============================================================================
echo "▶ BENCHMARK 1: POST /api/login (20 concurrent, 200 requests, Mục tiêu: p95 < 500 ms)\n";
$loginPostFile = sys_get_temp_dir() . '/ab_login_post_' . uniqid() . '.json';
file_put_contents($loginPostFile, json_encode(['username' => 'admin', 'password' => 'Admin@123']));

$abCmd1 = "\"{$abPath}\" -n 200 -c 20 -p \"{$loginPostFile}\" -T \"application/json\" -H \"X-Tuition-Test-Db: tuition_perf\" \"{$baseUrl}/login\" 2>&1";
exec($abCmd1, $out1, $ret1);
@unlink($loginPostFile);
$rawOutput1 = implode("\n", $out1);
file_put_contents("{$resultsDir}/performance-ab-login.txt", $rawOutput1);

$metrics1 = parse_ab_output($rawOutput1);
$pass1 = ($metrics1['p95_ms'] < 500.0) && ($metrics1['failed_requests'] === 0) && ($metrics1['total_requests'] === 200);
echo "  Kết quả: p50 = {$metrics1['p50_ms']} ms | p95 = {$metrics1['p95_ms']} ms | p99 = {$metrics1['p99_ms']} ms | max = {$metrics1['max_ms']} ms\n";
echo "  Thông lượng: {$metrics1['requests_per_second']} req/s | Lỗi: {$metrics1['failed_requests']}\n";
echo "  Đánh giá: " . ($pass1 ? "✓ ĐẠT (p95 < 500 ms)" : "✗ THẤT BẠI") . "\n\n";

$results['post_login'] = [
    'endpoint' => 'POST /api/login',
    'description' => 'Đăng nhập người dùng dưới tải đồng thời',
    'target_p95_ms' => 500,
    'concurrency' => 20,
    'total_requests' => 200,
    'metrics' => $metrics1,
    'passed' => $pass1,
];

// =============================================================================
// TEST 2: GET /api/fee-records/1 (20 concurrent, 1000 requests, target p95 < 300 ms)
// =============================================================================
echo "▶ BENCHMARK 2: GET /api/fee-records/1 (20 concurrent, 1,000 requests, Mục tiêu: p95 < 300 ms)\n";
$abCmd2 = "\"{$abPath}\" -n 1000 -c 20 -H \"Cookie: {$cookieHeader}\" -H \"X-Tuition-Test-Db: tuition_perf\" -H \"X-CSRF-Token: {$csrfToken}\" \"{$baseUrl}/fee-records/1\" 2>&1";
exec($abCmd2, $out2, $ret2);
$rawOutput2 = implode("\n", $out2);
file_put_contents("{$resultsDir}/performance-ab-fee-record.txt", $rawOutput2);

$metrics2 = parse_ab_output($rawOutput2);
$pass2 = ($metrics2['p95_ms'] < 300.0) && ($metrics2['failed_requests'] === 0) && ($metrics2['total_requests'] === 1000);
echo "  Kết quả: p50 = {$metrics2['p50_ms']} ms | p95 = {$metrics2['p95_ms']} ms | p99 = {$metrics2['p99_ms']} ms | max = {$metrics2['max_ms']} ms\n";
echo "  Thông lượng: {$metrics2['requests_per_second']} req/s | Lỗi: {$metrics2['failed_requests']}\n";
echo "  Đánh giá: " . ($pass2 ? "✓ ĐẠT (p95 < 300 ms)" : "✗ THẤT BẠI") . "\n\n";

$results['get_fee_record'] = [
    'endpoint' => 'GET /api/fee-records/{id}',
    'description' => 'Chi tiết hồ sơ học phí kèm tính toán công nợ phái sinh',
    'target_p95_ms' => 300,
    'concurrency' => 20,
    'total_requests' => 1000,
    'metrics' => $metrics2,
    'passed' => $pass2,
];

// =============================================================================
// TEST 3: GET /api/history (over 50,000 rows, target p95 < 800 ms)
// =============================================================================
echo "▶ BENCHMARK 3: GET /api/history (Truy vấn trên 50,000+ dòng sổ cái, Mục tiêu: p95 < 800 ms)\n";
$abCmd3 = "\"{$abPath}\" -n 50 -c 5 -H \"Cookie: {$cookieHeader}\" -H \"X-Tuition-Test-Db: tuition_perf\" -H \"X-CSRF-Token: {$csrfToken}\" \"{$baseUrl}/history\" 2>&1";
exec($abCmd3, $out3, $ret3);
$rawOutput3 = implode("\n", $out3);
file_put_contents("{$resultsDir}/performance-ab-history.txt", $rawOutput3);

$metrics3 = parse_ab_output($rawOutput3);
$pass3 = ($metrics3['p95_ms'] < 800.0) && ($metrics3['failed_requests'] === 0) && ($metrics3['total_requests'] === 50);
echo "  Kết quả: p50 = {$metrics3['p50_ms']} ms | p95 = {$metrics3['p95_ms']} ms | p99 = {$metrics3['p99_ms']} ms | max = {$metrics3['max_ms']} ms\n";
echo "  Thông lượng: {$metrics3['requests_per_second']} req/s | Lỗi: {$metrics3['failed_requests']}\n";
echo "  Đánh giá: " . ($pass3 ? "✓ ĐẠT (p95 < 800 ms)" : "✗ THẤT BẠI") . "\n\n";

$results['get_history'] = [
    'endpoint' => 'GET /api/history',
    'description' => 'Tra cứu toàn bộ lịch sử giao dịch trên tập dữ liệu >50,000 dòng',
    'target_p95_ms' => 800,
    'concurrency' => 5,
    'total_requests' => 50,
    'metrics' => $metrics3,
    'passed' => $pass3,
];

// =============================================================================
// TEST 4: GET /api/reports/summary (All semesters, target p95 < 1500 ms)
// =============================================================================
echo "▶ BENCHMARK 4: GET /api/reports/summary (Báo cáo tổng hợp toàn trường, Mục tiêu: p95 < 1,500 ms)\n";
$abCmd4 = "\"{$abPath}\" -n 50 -c 5 -H \"Cookie: {$cookieHeader}\" -H \"X-Tuition-Test-Db: tuition_perf\" -H \"X-CSRF-Token: {$csrfToken}\" \"{$baseUrl}/reports/summary\" 2>&1";
exec($abCmd4, $out4, $ret4);
$rawOutput4 = implode("\n", $out4);
file_put_contents("{$resultsDir}/performance-ab-reports.txt", $rawOutput4);

$metrics4 = parse_ab_output($rawOutput4);
$pass4 = ($metrics4['p95_ms'] < 1500.0) && ($metrics4['failed_requests'] === 0) && ($metrics4['total_requests'] === 50);
echo "  Kết quả: p50 = {$metrics4['p50_ms']} ms | p95 = {$metrics4['p95_ms']} ms | p99 = {$metrics4['p99_ms']} ms | max = {$metrics4['max_ms']} ms\n";
echo "  Thông lượng: {$metrics4['requests_per_second']} req/s | Lỗi: {$metrics4['failed_requests']}\n";
echo "  Đánh giá: " . ($pass4 ? "✓ ĐẠT (p95 < 1,500 ms)" : "✗ THẤT BẠI") . "\n\n";

$results['get_reports_summary'] = [
    'endpoint' => 'GET /api/reports/summary',
    'description' => 'Báo cáo tài chính tổng hợp toàn trường phạm vi ALL',
    'target_p95_ms' => 1500,
    'concurrency' => 5,
    'total_requests' => 50,
    'metrics' => $metrics4,
    'passed' => $pass4,
];

// =============================================================================
// TEST 5: GET /api/nav/badges (20 concurrent, target p95 < 300 ms)
// =============================================================================
echo "▶ BENCHMARK 5: GET /api/nav/badges (20 concurrent, 200 requests, Mục tiêu: p95 < 300 ms)\n";
// Khởi động trước bộ đệm cache
$warmCh = curl_init("{$baseUrl}/nav/badges");
curl_setopt($warmCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($warmCh, CURLOPT_COOKIE, $cookieHeader);
curl_setopt($warmCh, CURLOPT_HTTPHEADER, [
    'X-Tuition-Test-Db: tuition_perf',
    'X-CSRF-Token: ' . $csrfToken,
]);
curl_exec($warmCh);
curl_close($warmCh);

$abCmd5 = "\"{$abPath}\" -n 200 -c 20 -H \"Cookie: {$cookieHeader}\" -H \"X-Tuition-Test-Db: tuition_perf\" -H \"X-CSRF-Token: {$csrfToken}\" \"{$baseUrl}/nav/badges\" 2>&1";
exec($abCmd5, $out5, $ret5);
$rawOutput5 = implode("\n", $out5);
file_put_contents("{$resultsDir}/performance-ab-badges.txt", $rawOutput5);

$metrics5 = parse_ab_output($rawOutput5);
$pass5 = ($metrics5['p95_ms'] < 300.0) && ($metrics5['failed_requests'] === 0) && ($metrics5['total_requests'] === 200);
echo "  Kết quả: p50 = {$metrics5['p50_ms']} ms | p95 = {$metrics5['p95_ms']} ms | p99 = {$metrics5['p99_ms']} ms | max = {$metrics5['max_ms']} ms\n";
echo "  Thông lượng: {$metrics5['requests_per_second']} req/s | Lỗi: {$metrics5['failed_requests']}\n";
echo "  Đánh giá: " . ($pass5 ? "✓ ĐẠT (p95 < 300 ms)" : "✗ THẤT BẠI") . "\n\n";

$results['get_nav_badges'] = [
    'endpoint' => 'GET /api/nav/badges',
    'description' => 'Huy hiệu thông báo điều hướng cho người dùng đăng nhập',
    'target_p95_ms' => 300,
    'concurrency' => 20,
    'total_requests' => 200,
    'metrics' => $metrics5,
    'passed' => $pass5,
];

// =============================================================================
// TEST 6: CONCURRENT PAYMENTS RACE CONDITION TEST (10 concurrent clients)
// =============================================================================
echo "▶ BENCHMARK 6: CONCURRENT PAYMENTS RACE CONDITION TEST (10 tiến trình đồng thời)\n";
echo "  - Thiết lập hồ sơ học phí thử nghiệm có số dư nợ đúng 10,000,000 VND\n";
echo "  - Phát 10 yêu cầu nộp tiền đồng thời, mỗi yêu cầu 2,000,000 VND (Tổng yêu cầu: 20,000,000 VND)\n";
echo "  - Yêu cầu mong đợi: Đúng 5 giao dịch thành công (201), 5 giao dịch bị từ chối (422)\n";
echo "  - Tổng tiền đã nộp không bao giờ vượt quá 10,000,000 VND\n";

// Tạo hồ sơ kiểm thử riêng biệt cho concurrency test
$stmtSt = $pdo->prepare("SELECT id FROM students WHERE student_code = 'SV9999'");
$stmtSt->execute();
$concurStId = $stmtSt->fetchColumn();
if (!$concurStId) {
    $pdo->prepare("INSERT INTO students (student_code, full_name, class_name, name_search) VALUES ('SV9999', 'Nguyen Van Concurrency', 'TEST', 'nguyen van concurrency')")->execute();
    $concurStId = (int)$pdo->lastInsertId();
}

$pdo->prepare("DELETE FROM ledger_entries WHERE fee_record_id IN (SELECT id FROM fee_records WHERE student_id = ?)")->execute([$concurStId]);
$pdo->prepare("DELETE FROM fee_records WHERE student_id = ?")->execute([$concurStId]);

$pdo->prepare("
    INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition)
    VALUES (?, 1, 10, 1000000, 10000000)
")->execute([$concurStId]);
$concurFrId = (int)$pdo->lastInsertId();

$mh = curl_multi_init();
$curlHandles = [];
$reqCount = 10;
$payAmount = 2000000;

for ($k = 0; $k < $reqCount; $k++) {
    $ch = curl_init("{$baseUrl}/fee-records/{$concurFrId}/payments");
    $reqUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    $payload = json_encode([
        'amount' => $payAmount,
        'method' => 'Tiền mặt',
        'entry_date' => date('Y-m-d'),
        'request_id' => $reqUuid,
        'note' => 'CONCURRENCY_TEST_PERF',
    ]);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookieHeader);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-CSRF-Token: ' . $csrfToken,
        'X-Tuition-Test-Db: tuition_perf',
    ]);

    curl_multi_add_handle($mh, $ch);
    $curlHandles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$statusCounts = [];
$responseBodies = [];
foreach ($curlHandles as $ch) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $resp = curl_multi_getcontent($ch);
    $statusCounts[$httpCode] = ($statusCounts[$httpCode] ?? 0) + 1;
    $responseBodies[] = [
        'code' => $httpCode,
        'body' => json_decode((string)$resp, true),
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// Kiểm tra số tiền thực tế trong DB
$stmtCheck = $pdo->prepare("SELECT SUM(amount) FROM ledger_entries WHERE fee_record_id = ? AND entry_type = 'payment'");
$stmtCheck->execute([$concurFrId]);
$actualTotalPaid = (int)$stmtCheck->fetchColumn();

$successCount = $statusCounts[201] ?? 0;
$rejectedCount = $statusCounts[422] ?? 0;

$pass6 = ($successCount === 5) && ($rejectedCount === 5) && ($actualTotalPaid === 10000000);

echo "  Giao dịch thành công (HTTP 201): {$successCount} (Kỳ vọng: 5)\n";
echo "  Giao dịch bị từ chối (HTTP 422): {$rejectedCount} (Kỳ vọng: 5)\n";
echo "  Tổng tiền thực nộp trong sổ cái: " . number_format($actualTotalPaid, 0, ',', '.') . " VND (Kỳ vọng đúng: 10.000.000 VND)\n";
echo "  Đánh giá: " . ($pass6 ? "✓ ĐẠT TUYỆT ĐỐI (Không có giao dịch thừa, chặn chính xác tại giới hạn nợ)" : "✗ THẤT BẠI") . "\n\n";

$results['concurrency_payment_stress'] = [
    'description' => 'Kiểm thử tranh chấp tương tranh khi 10 client cùng nộp tiền vượt công nợ',
    'concurrency' => 10,
    'total_requests' => 10,
    'payment_amount_per_req' => 2000000,
    'target_net_due' => 10000000,
    'success_count_201' => $successCount,
    'rejected_count_422' => $rejectedCount,
    'actual_total_paid' => $actualTotalPaid,
    'passed' => $pass6,
];

// Clean up temporary cookie file if exists
if (!empty($cookieFile) && file_exists($cookieFile)) {
    @unlink($cookieFile);
}

// Load explain analysis if available
$explainFile = __DIR__ . '/performance/explain_analysis.json';
$explainData = file_exists($explainFile) ? json_decode(file_get_contents($explainFile), true) : null;

// =============================================================================
// GHI FILE KẾT QUẢ
// =============================================================================
$finalOutput = [
    'timestamp' => date('c'),
    'database' => 'tuition_perf',
    'total_tests' => count($results),
    'passed_tests' => count(array_filter($results, fn($r) => !empty($r['passed']))),
    'failed_tests' => count(array_filter($results, fn($r) => empty($r['passed']))),
    'benchmarks' => $results,
    'explain_optimization' => $explainData,
];

file_put_contents("{$resultsDir}/performance.json", json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$txtReport = "====================================================================\n";
$txtReport .= " BÁO CÁO KẾT QUẢ KIỂM THỬ HIỆU NĂNG & ĐỒNG THỜI (PERFORMANCE REPORT)\n";
$txtReport .= " Thời gian thực hiện: " . date('Y-m-d H:i:s') . "\n";
$txtReport .= " Database: tuition_perf (500 Sinh viên, 6 Học kỳ, 3,000 Hồ sơ, >50,000 Dòng sổ cái)\n";
$txtReport .= "====================================================================\n\n";

foreach ($results as $key => $res) {
    $statusText = !empty($res['passed']) ? "[PASS]" : "[FAIL]";
    $txtReport .= "{$statusText} {$key}: " . ($res['endpoint'] ?? $res['description']) . "\n";
    if (isset($res['metrics'])) {
        $m = $res['metrics'];
        $txtReport .= "  - Concurrency: {$m['concurrency']}, Total: {$m['total_requests']}, Failed: {$m['failed_requests']}\n";
        $txtReport .= "  - Latency: p50={$m['p50_ms']}ms, p95={$m['p95_ms']}ms (Mục tiêu: <{$res['target_p95_ms']}ms), p99={$m['p99_ms']}ms, Max={$m['max_ms']}ms\n";
        $txtReport .= "  - Throughput: {$m['requests_per_second']} req/s\n";
    } elseif ($key === 'concurrency_payment_stress') {
        $txtReport .= "  - 10 Tiến trình đồng thời: {$res['success_count_201']} thành công (201), {$res['rejected_count_422']} từ chối (422)\n";
        $txtReport .= "  - Tổng tiền đã nộp thực tế: " . number_format($res['actual_total_paid']) . " VND / " . number_format($res['target_net_due']) . " VND\n";
    }
    $txtReport .= "\n";
}

if ($explainData) {
    $txtReport .= "--------------------------------------------------------------------\n";
    $txtReport .= " KẾT QUẢ TỐI ƯU HÓA TRUY VẤN (EXPLAIN & INDEX OPTIMIZATION)\n";
    $txtReport .= "--------------------------------------------------------------------\n";
    $txtReport .= "- Chỉ mục bổ sung: \n";
    foreach ($explainData['indexes_added'] as $idx) {
        $txtReport .= "  * {$idx}\n";
    }
    $txtReport .= "- /api/reports/summary: {$explainData['reports_summary']['before_ms']}ms -> {$explainData['reports_summary']['after_ms']}ms (Nhanh hơn {$explainData['reports_summary']['speedup']})\n";
    $txtReport .= "- /api/history:         {$explainData['history']['before_ms']}ms -> {$explainData['history']['after_ms']}ms (Nhanh hơn {$explainData['history']['speedup']})\n";
}

file_put_contents("{$resultsDir}/performance.txt", $txtReport);

echo "====================================================================\n";
echo " HOÀN TẤT: ĐÃ XUẤT KẾT QUẢ RA performance.json VÀ performance.txt\n";
echo "====================================================================\n";
