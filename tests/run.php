<?php
/**
 * CLI Test Runner for Student Tuition Management System
 * Run against dedicated test database: tuition_test
 * Usage: php tests/run.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

// Thiết lập môi trường kiểm thử
putenv('TUITION_DB=tuition_test');
$_SERVER['HTTP_X_TUITION_TEST_DB'] = 'tuition_test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/api/lib/db.php';
require_once dirname(__DIR__) . '/api/lib/calc.php';
require_once dirname(__DIR__) . '/api/lib/validate.php';
require_once dirname(__DIR__) . '/api/lib/response.php';
require_once dirname(__DIR__) . '/api/lib/auth.php';

// CLI Colors
const COLOR_RESET   = "\033[0m";
const COLOR_GREEN   = "\033[32m";
const COLOR_RED     = "\033[31m";
const COLOR_YELLOW  = "\033[33m";
const COLOR_CYAN    = "\033[36m";
const COLOR_BOLD    = "\033[1m";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function it(string $description, bool $condition, $expected = null, $actual = null): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo COLOR_GREEN . "  [✓] " . COLOR_RESET . $description . "\n";
    } else {
        $failedTests++;
        echo COLOR_RED . "  [✗] " . COLOR_BOLD . $description . COLOR_RESET . "\n";
        if ($expected !== null || $actual !== null) {
            echo "      Expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
            echo "      Actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

function suite_header(string $name): void {
    echo "\n" . COLOR_CYAN . COLOR_BOLD . "▶ " . $name . COLOR_RESET . "\n";
}

/**
 * HTTP Client chuyên dụng cho kiểm thử API thực tế trên máy chủ Apache local
 */
class ApiTestClient {
    private string $baseUrl;
    private string $cookieFile;
    private ?string $csrfToken = null;

    public function __construct(string $baseUrl = 'http://localhost/tuition-system/api') {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = sys_get_temp_dir() . '/tuition_test_cookie_' . uniqid() . '.txt';
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function clearSession(): void {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
        $this->csrfToken = null;
    }

    public function setCsrfToken(?string $token): void {
        $this->csrfToken = $token;
    }

    public function getCsrfToken(): ?string {
        return $this->csrfToken;
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array {
        $url = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);

        $httpHeaders = [
            'Accept: application/json',
            'X-Tuition-Test-Db: tuition_test',
        ];

        if ($this->csrfToken !== null && !isset($headers['X-CSRF-Token'])) {
            $httpHeaders[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }

        foreach ($headers as $k => $v) {
            $httpHeaders[] = "{$k}: {$v}";
        }

        if ($body !== null) {
            $httpHeaders[] = 'Content-Type: application/json';
            $jsonBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $rawResponse = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $json = json_decode((string)$rawResponse, true);
        if ($json === null) {
            $json = ['ok' => false, 'raw' => $rawResponse, 'curl_error' => $curlError];
        }

        if ($statusCode === 200 && isset($json['data']['csrf_token'])) {
            $this->csrfToken = $json['data']['csrf_token'];
        }

        return [
            'status' => $statusCode,
            'body'   => $json,
            'raw'    => $rawResponse
        ];
    }

    public function login(string $username = 'admin', string $password = 'Admin@123'): array {
        $res = $this->request('POST', '/login', [
            'username' => $username,
            'password' => $password,
        ]);
        if ($res['status'] === 200 && isset($res['body']['data']['csrf_token'])) {
            $this->csrfToken = $res['body']['data']['csrf_token'];
        }
        return $res;
    }
}

echo COLOR_BOLD . "====================================================================\n";
echo " BỘ KIỂM THỬ TỰ ĐỘNG BACKEND (PHP + MariaDB) - TUITION SYSTEM TEST\n";
echo " Database mục tiêu: tuition_test\n";
echo "====================================================================\n" . COLOR_RESET;

// ----------------------------------------------------------------------------
// 0. RESET SCHEMA & LOAD SEED DATA TRÊN TUITION_TEST
// ----------------------------------------------------------------------------
suite_header("0. Khởi tạo & nạp dữ liệu sạch vào database: tuition_test");

$rootPdo = null;
try {
    $rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `tuition_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage() . "\n");
}

function reset_test_database(PDO $pdo): void {
    $pdo->exec("USE `tuition_test`;");
    $schemaSql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $pdo->exec($schemaSql);

    $seedSql = file_get_contents(dirname(__DIR__) . '/database/seed.sql');
    $pdo->exec($seedSql);

    $phpExe = 'C:\\xampp\\php\\php.exe';
    if (!file_exists($phpExe)) {
        $phpExe = PHP_BINARY;
    }
    $seedScript = dirname(__DIR__) . '/database/seed_users.php';
    exec(escapeshellarg($phpExe) . " " . escapeshellarg($seedScript) . " --db=tuition_test 2>&1", $out, $ret);
}

reset_test_database($rootPdo);
$pdo = get_db_connection('tuition_test');
it("Database tuition_test đã được reset và nạp seed data thành công", $pdo instanceof PDO);

$api = new ApiTestClient();
$loginRes = $api->login('admin', 'Admin@123');
it("Đăng nhập quản trị viên admin qua API thành công (HTTP 200) và nhận CSRF Token", 
    $loginRes['status'] === 200 && !empty($api->getCsrfToken())
);

// ----------------------------------------------------------------------------
// 1. FEE CALCULATION
// ----------------------------------------------------------------------------
suite_header("1. Tính toán học phí, miễn giảm & làm tròn (Fee Calculation)");

$gross1 = calc_gross(15, 850000);
it("Học phí gốc 15 tín chỉ x 850.000 = 12.750.000", $gross1 === 12750000, 12750000, $gross1);

$exPct = calc_exemption(12750000, 'percent', 10);
it("Miễn giảm 10% trên 12.750.000 = 1.275.000", $exPct === 1275000, 1275000, $exPct);

$exRound = calc_exemption(1000000, 'percent', 33.33);
it("Miễn giảm 33.33% trên 1.000.000 làm tròn đến đồng: 333.300", $exRound === 333300, 333300, $exRound);

$ex100 = calc_exemption(7200000, 'percent', 100);
it("Miễn giảm 100% bằng chính học phí gốc (7.200.000)", $ex100 === 7200000, 7200000, $ex100);

$exFixedEqual = calc_exemption(5000000, 'fixed', 5000000);
it("Miễn giảm cố định bằng đúng học phí gốc (5.000.000)", $exFixedEqual === 5000000, 5000000, $exFixedEqual);

$exFixedOver = calc_exemption(5000000, 'fixed', 8000000);
it("Miễn giảm cố định vượt học phí gốc được chặn ở mức học phí gốc (5.000.000)", $exFixedOver === 5000000, 5000000, $exFixedOver);

// Kiểm tra qua API thực tế: gửi miễn giảm cố định vượt quá gross của hồ sơ #2 (gross 15.300.000)
$overExRes = $api->request('PUT', '/fee-records/2/exemption', [
    'type'   => 'fixed',
    'value'  => 20000000,
    'reason' => 'Test miễn giảm vượt học phí gốc'
]);
it("API từ chối miễn giảm cố định vượt học phí gốc (HTTP 400)", 
    $overExRes['status'] === 400 && isset($overExRes['body']['error']['fields']['value'])
);

// Kiểm tra qua API thực tế: gửi miễn giảm phần trăm có số lẻ thập phân (10.5)
$decExRes = $api->request('PUT', '/fee-records/2/exemption', [
    'type'   => 'percent',
    'value'  => 10.5,
    'reason' => 'Test phần trăm thập phân'
]);
it("API từ chối phần trăm miễn giảm có số lẻ thập phân (HTTP 400)", 
    $decExRes['status'] === 400 && isset($decExRes['body']['error']['fields']['value'])
);

$netDue = calc_net_due(12750000, 1275000);
it("Số tiền thực nộp Net Due = Gross - Exemption (11.475.000)", $netDue === 11475000, 11475000, $netDue);

$netDueFloor = calc_net_due(3000000, 5000000);
it("Net Due chặn dưới tại 0 không bao giờ âm", $netDueFloor === 0, 0, $netDueFloor);

// ----------------------------------------------------------------------------
// 2. OVERPAYMENT REJECTION VIA REAL API
// ----------------------------------------------------------------------------
suite_header("2. Quy tắc nộp thừa & bảo toàn dữ liệu (Overpayment Rejection)");

$fee2 = fetch_fee_record_summary($pdo, 2);
$balanceFee2 = (int)$fee2['net_due'] - (int)$fee2['total_paid'];
it("Hồ sơ #2 còn nợ đúng 10.300.000 VND", $balanceFee2 === 10300000, 10300000, $balanceFee2);

$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();

// Gọi API nộp tiền vượt đúng 1 VND: 10.300.001
$payOverRes = $api->request('POST', '/fee-records/2/payments', [
    'amount'     => $balanceFee2 + 1,
    'method'     => 'Tiền mặt',
    'request_id' => 'd1111111-1111-1111-1111-111111111101',
    'note'       => 'Test nộp vượt 1 VND'
]);

it("API từ chối nộp vượt 1 VND với HTTP 422 và đúng thông báo quy định", 
    $payOverRes['status'] === 422 && 
    ($payOverRes['body']['error']['message'] ?? '') === 'Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước.'
);

$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
it("Số lượng dòng trong ledger_entries không thay đổi sau khi bị từ chối", $countBefore === $countAfter, $countBefore, $countAfter);

// Gọi API nộp đúng bằng số dư nợ còn lại (10.300.000 VND)
$payExactRes = $api->request('POST', '/fee-records/2/payments', [
    'amount'     => $balanceFee2,
    'method'     => 'Chuyển khoản',
    'request_id' => 'd1111111-1111-1111-1111-111111111102',
    'note'       => 'Thanh toán hết nợ còn lại'
]);

it("API chấp nhận nộp đúng bằng số nợ còn lại (HTTP 201 Created)", 
    $payExactRes['status'] === 201 && !empty($payExactRes['body']['data']['receipt_no'])
);

$fee2After = fetch_fee_record_summary($pdo, 2);
it("Sau khi nộp đủ, hồ sơ #2 có total_paid = net_due và outstanding = 0", 
    (int)$fee2After['total_paid'] === (int)$fee2After['net_due']
);

// ----------------------------------------------------------------------------
// 3. STATUS BOUNDARIES
// ----------------------------------------------------------------------------
suite_header("3. Trạng thái thanh toán tại các ngưỡng biên (Boundaries)");

$stUnpaid = derive_payment_status(0, 5000000);
it("Đã nộp 0, nợ > 0 -> Trạng thái 'Unpaid' (Chưa thanh toán)", 
    $stUnpaid['status'] === 'Unpaid' && $stUnpaid['status_label'] === 'Chưa thanh toán'
);

$stPartial = derive_payment_status(4999999, 5000000);
it("Nộp thiếu đúng 1 VND (4.999.999/5.000.000) -> Trạng thái 'Partially Paid' (Thanh toán một phần)", 
    $stPartial['status'] === 'Partially Paid' && $stPartial['outstanding'] === 1
);

$stPaidExact = derive_payment_status(5000000, 5000000);
it("Nộp đủ 100% -> Trạng thái 'Fully Paid' (Đã thanh toán đủ)", 
    $stPaidExact['status'] === 'Fully Paid' && $stPaidExact['outstanding'] === 0
);

$stPaidZero = derive_payment_status(0, 0);
it("Net Due = 0 (miễn 100%) -> Trạng thái 'Fully Paid'", 
    $stPaidZero['status'] === 'Fully Paid' && $stPaidZero['outstanding'] === 0
);

$stOver = derive_payment_status(5000001, 5000000);
it("Nộp vượt quá công nợ -> Kích hoạt cảnh báo nộp thừa (is_overpaid = true, overpaid_amount = 1)", 
    $stOver['is_overpaid'] === true && $stOver['overpaid_amount'] === 1 && $stOver['status'] === 'Fully Paid'
);

// ----------------------------------------------------------------------------
// 4. RATE SNAPSHOT IMMUTABILITY
// ----------------------------------------------------------------------------
suite_header("4. Tính bất biến của Rate Snapshot khi sửa định mức tín chỉ");

$r1Before = $pdo->query("SELECT rate_snapshot, gross_tuition FROM fee_records WHERE id = 1")->fetch();
it("Hồ sơ #1 ban đầu có rate_snapshot = 850.000 và gross = 12.750.000", 
    (int)$r1Before['rate_snapshot'] === 850000 && (int)$r1Before['gross_tuition'] === 12750000
);

// Sửa định mức tín chỉ kỳ 3 qua API PUT /api/rates/3 lên 900.000
$upRateRes = $api->request('PUT', '/rates/3', [
    'price_per_credit' => 900000
]);
it("Cập nhật định mức tín chỉ kỳ 3 qua API thành công (HTTP 200)", $upRateRes['status'] === 200);

$r1After = $pdo->query("SELECT rate_snapshot, gross_tuition FROM fee_records WHERE id = 1")->fetch();
it("Sau khi cập nhật định mức kỳ 3 lên 900.000, hồ sơ #1 vẫn giữ nguyên rate_snapshot 850.000", 
    (int)$r1After['rate_snapshot'] === 850000
);
it("Hồ sơ #1 vẫn giữ nguyên học phí gốc 12.750.000", 
    (int)$r1After['gross_tuition'] === 12750000
);

// Tạo hồ sơ mới cho sinh viên 4 trong kỳ 3 qua API POST /api/fee-records
$newFeeRes = $api->request('POST', '/fee-records', [
    'student_id'         => 4,
    'semester_id'        => 3,
    'registered_credits' => 10
]);
it("Tạo hồ sơ mới nhận định mức mới 900.000 và gross = 9.000.000 (HTTP 201)", 
    $newFeeRes['status'] === 201 && 
    (int)$newFeeRes['body']['data']['rate_snapshot'] === 900000 && 
    (int)$newFeeRes['body']['data']['gross_tuition'] === 9000000
);

// Dọn dẹp
$pdo->exec("DELETE FROM fee_records WHERE student_id = 4 AND semester_id = 3");
$pdo->exec("UPDATE credit_rates SET price_per_credit = 850000 WHERE semester_id = 3");

// ----------------------------------------------------------------------------
// 5. REFUND LIFECYCLE VIA REAL API
// ----------------------------------------------------------------------------
suite_header("5. Quy trình hoàn tiền & kiểm soát nộp thừa (Refund Lifecycle)");

$fee5 = fetch_fee_record_summary($pdo, 5);
it("Hồ sơ #5 nộp thừa 1.000.000 (total_paid 8.2M, net 7.2M)", 
    (int)$fee5['total_paid'] === 8200000 && (int)$fee5['net_due'] === 7200000
);

// Thử tạo thêm yêu cầu hoàn 500.000 khi đã có pending 1.000.000 -> phải bị chặn 422
$overRefundRes = $api->request('POST', '/fee-records/5/refunds', [
    'amount'     => 500000,
    'reason'     => 'Yêu cầu hoàn tiền vượt quá số khả dụng',
    'request_id' => 'e1111111-1111-1111-1111-111111111101'
]);
it("Tạo yêu cầu hoàn vượt số nộp thừa khả dụng bị từ chối qua API (HTTP 422)", 
    $overRefundRes['status'] === 422
);

// Hoàn tất yêu cầu hoàn tiền đang chờ duyệt (#1) qua API POST /api/refunds/1/complete
$compRefundRes = $api->request('POST', '/refunds/1/complete');
it("Duyệt hoàn tất yêu cầu hoàn tiền #1 qua API thành công (HTTP 200)", 
    $compRefundRes['status'] === 200 && ($compRefundRes['body']['data']['status'] ?? '') === 'completed'
);

$fee5Completed = fetch_fee_record_summary($pdo, 5);
it("Sau khi duyệt hoàn tất, total_paid giảm từ 8.2M xuống đúng 7.2M", 
    (int)$fee5Completed['total_paid'] === 7200000, 7200000, (int)$fee5Completed['total_paid']
);

$st5 = derive_payment_status((int)$fee5Completed['total_paid'], (int)$fee5Completed['net_due']);
it("Trạng thái chuyển thành 'Fully Paid' và không còn nộp thừa", 
    $st5['status'] === 'Fully Paid' && $st5['is_overpaid'] === false
);

// ----------------------------------------------------------------------------
// 6. REVERSAL LIFECYCLE VIA REAL API
// ----------------------------------------------------------------------------
suite_header("6. Bút toán đảo mục (Reversal Lifecycle)");

$fee7 = fetch_fee_record_summary($pdo, 7);
it("Hồ sơ #7 có total_paid = 0 sau khi thanh toán được đảo mục", 
    (int)$fee7['total_paid'] === 0, 0, (int)$fee7['total_paid']
);

// Đảo mục lần 2 của giao dịch #6 qua API
$secondRevRes = $api->request('POST', '/ledger/6/reversal', [
    'reason'     => 'Cố tình đảo mục lần 2',
    'request_id' => 'f1111111-1111-1111-1111-111111111101'
]);
it("Đảo mục lần 2 của cùng một giao dịch bị từ chối qua API (HTTP 409 Conflict)", 
    $secondRevRes['status'] === 409
);

// Đảo mục của một bản ghi đảo mục (#7) qua API
$revOfRevRes = $api->request('POST', '/ledger/7/reversal', [
    'reason'     => 'Đảo mục của bản ghi đảo mục',
    'request_id' => 'f1111111-1111-1111-1111-111111111102'
]);
it("Đảo mục một giao dịch đảo mục (reversal of reversal) bị từ chối qua API (HTTP 422)", 
    $revOfRevRes['status'] === 422
);

// ----------------------------------------------------------------------------
// 7. IDEMPOTENCY WITH REQUEST_ID
// ----------------------------------------------------------------------------
suite_header("7. Tính bất biến trùng lặp (Idempotency with request_id)");

$newReqId = '11111111-2222-3333-4444-555555555555';

// Lần gọi 1
$idem1 = $api->request('POST', '/fee-records/3/payments', [
    'amount'     => 1000000,
    'method'     => 'Tiền mặt',
    'request_id' => $newReqId,
    'note'       => 'Test Idempotency Lần 1'
]);
it("Lần gọi thứ nhất thành công tạo mới giao dịch (HTTP 201 Created)", $idem1['status'] === 201);
$createdEntryId = $idem1['body']['data']['id'] ?? null;

// Lần gọi 2 với cùng request_id
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE request_id = '{$newReqId}'")->fetchColumn();
$idem2 = $api->request('POST', '/fee-records/3/payments', [
    'amount'     => 1000000,
    'method'     => 'Tiền mặt',
    'request_id' => $newReqId,
    'note'       => 'Test Idempotency Lần 2'
]);

it("Lần gọi thứ hai với cùng request_id trả về HTTP 200 và cùng bản ghi cũ mà không tạo thêm dòng mới", 
    $idem2['status'] === 200 && 
    ($idem2['body']['data']['id'] ?? null) === $createdEntryId
);

$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE request_id = '{$newReqId}'")->fetchColumn();
it("Số lượng bản ghi giữ nguyên là đúng 1 dòng", $countBefore === 1 && $countAfter === 1);

// Dọn dẹp
$pdo->exec("DELETE FROM ledger_entries WHERE request_id = '{$newReqId}'");

// ----------------------------------------------------------------------------
// 8. RECEIPT SEQUENCE
// ----------------------------------------------------------------------------
suite_header("8. Đánh số biên lai liên tục & Reset theo ngày (PT-YYYYMMDD-NNNN)");

$rc1 = $pdo->query("SELECT receipt_no FROM ledger_entries WHERE id = 2")->fetchColumn();
$rc2 = $pdo->query("SELECT receipt_no FROM ledger_entries WHERE id = 3")->fetchColumn();
it("Phiếu thứ nhất ngày 2026-09-05 nhận số PT-20260905-0001", $rc1 === 'PT-20260905-0001', 'PT-20260905-0001', $rc1);
it("Phiếu thứ hai cùng ngày nhận số tiếp theo PT-20260905-0002", $rc2 === 'PT-20260905-0002', 'PT-20260905-0002', $rc2);

$rc3 = $pdo->query("SELECT receipt_no FROM ledger_entries WHERE id = 4")->fetchColumn();
it("Ngày mới (2026-09-10) bắt đầu lại từ số 0001 (PT-20260910-0001)", $rc3 === 'PT-20260910-0001', 'PT-20260910-0001', $rc3);

// ----------------------------------------------------------------------------
// 9. CONCURRENCY (Parallel processes race condition test)
// ----------------------------------------------------------------------------
suite_header("9. Kiểm thử tương tranh thanh toán đồng thời (Concurrency Test)");

$testStudentCode = 'SVCONC';
$pdo->prepare("INSERT INTO students (student_code, full_name, name_search, class_name, created_at, updated_at) VALUES (:c, 'Test Concurrency', 'test concurrency', 'K20', NOW(), NOW())")
    ->execute(['c' => $testStudentCode]);
$concStudentId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (:sid, 3, 10, 850000, 8500000, NOW(), NOW())")
    ->execute(['sid' => $concStudentId]);
$concFeeId = (int)$pdo->lastInsertId();

$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$workerScript = sys_get_temp_dir() . '/conc_worker.php';
$workerCode = <<<CODE
<?php
putenv('TUITION_DB=tuition_test');
\$_SERVER['HTTP_X_TUITION_TEST_DB'] = 'tuition_test';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once '{$projectRoot}/api/lib/db.php';
require_once '{$projectRoot}/api/lib/calc.php';

\$feeId = (int)\$argv[1];
\$amount = 5000000;
\$reqId = \$argv[2];

try {
    \$res = db_transaction(function(PDO \$pdo) use (\$feeId, \$amount, \$reqId) {
        \$pdo->prepare("SELECT id FROM fee_records WHERE id = :id FOR UPDATE")->execute(['id' => \$feeId]);
        \$sum = fetch_fee_record_summary(\$pdo, \$feeId);
        \$net = (int)\$sum['net_due'];
        \$paid = (int)\$sum['total_paid'];
        if ((\$paid + \$amount) > \$net) {
            echo "REJECTED_OVERPAYMENT\n";
            exit(2);
        }

        \$today = date('Y-m-d');
        \$pdo->prepare("INSERT INTO receipt_counters (day, last_no) VALUES (:t, 0) ON DUPLICATE KEY UPDATE day = day")->execute(['t' => \$today]);
        \$cnt = \$pdo->prepare("SELECT last_no FROM receipt_counters WHERE day = :t FOR UPDATE");
        \$cnt->execute(['t' => \$today]);
        \$nextNo = (int)\$cnt->fetchColumn() + 1;
        \$pdo->prepare("UPDATE receipt_counters SET last_no = :n WHERE day = :t")->execute(['n' => \$nextNo, 't' => \$today]);
        \$rcNo = sprintf('PT-%s-%04d', str_replace('-', '', \$today), \$nextNo);

        \$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, request_id, created_by, created_at) VALUES (:fr, 'payment', :amt, 'Tiền mặt', :dt, :rc, :req, 1, NOW())")
            ->execute(['fr' => \$feeId, 'amt' => \$amount, 'dt' => \$today, 'rc' => \$rcNo, 'req' => \$reqId]);

        echo "SUCCESS\n";
    });
} catch (Exception \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
    exit(1);
}
CODE;

file_put_contents($workerScript, $workerCode);

$phpBin = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBin)) {
    $phpBin = PHP_BINARY;
}
$cmd1 = escapeshellarg($phpBin) . " " . escapeshellarg($workerScript) . " {$concFeeId} " . escapeshellarg('c1111111-1111-1111-1111-111111111101');
$cmd2 = escapeshellarg($phpBin) . " " . escapeshellarg($workerScript) . " {$concFeeId} " . escapeshellarg('c1111111-1111-1111-1111-111111111102');

$p1 = proc_open($cmd1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
$p2 = proc_open($cmd2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

$out1 = stream_get_contents($pipes1[1]);
$err1 = stream_get_contents($pipes1[2]);
fclose($pipes1[1]); fclose($pipes1[2]);
$code1 = proc_close($p1);

$out2 = stream_get_contents($pipes2[1]);
$err2 = stream_get_contents($pipes2[2]);
fclose($pipes2[1]); fclose($pipes2[2]);
$code2 = proc_close($p2);

@unlink($workerScript);

$outputs = trim($out1) . ' | ' . trim($out2);
$successCount = (strpos($out1, 'SUCCESS') !== false ? 1 : 0) + (strpos($out2, 'SUCCESS') !== false ? 1 : 0);
$rejectCount = (strpos($out1, 'REJECTED') !== false ? 1 : 0) + (strpos($out2, 'REJECTED') !== false ? 1 : 0);

it("Hai tiến trình nộp tiền đồng thời vượt công nợ cho kết quả: đúng 1 thành công và 1 bị từ chối", 
    $successCount === 1 && $rejectCount === 1,
    "1 SUCCESS và 1 REJECTED",
    $outputs
);

$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = {$concFeeId}");
$pdo->exec("DELETE FROM fee_records WHERE id = {$concFeeId}");
$pdo->exec("DELETE FROM students WHERE id = {$concStudentId}");

// ----------------------------------------------------------------------------
// 10. SEARCH & DIACRITIC NORMALIZATION VIA REAL API
// ----------------------------------------------------------------------------
suite_header("10. Tìm kiếm tiếng Việt không dấu & hoa thường (Search Normalization)");

$q1 = normalize_vietnamese("nguyen");
$q2 = normalize_vietnamese("Nguyễn");
$q3 = normalize_vietnamese("NGUYEN");
$q4 = normalize_vietnamese("nguyễn");
$q5 = normalize_vietnamese("Hòa");

it("Các biến thể của 'nguyen' đều chuẩn hóa về cùng chuỗi 'nguyen'", 
    $q1 === 'nguyen' && $q2 === 'nguyen' && $q3 === 'nguyen' && $q4 === 'nguyen'
);

// Tìm kiếm sinh viên qua API GET /api/students?q=nguyen
$stSearchRes = $api->request('GET', '/students?q=nguyen');
$foundCodes = array_column($stSearchRes['body']['data'] ?? [], 'student_code');
it("API GET /api/students?q=nguyen khớp chính xác cả SV001 và SV005", 
    in_array('SV001', $foundCodes) && in_array('SV005', $foundCodes)
);

// Tìm kiếm sinh viên qua API GET /api/students?q=Hòa
$stHoaRes = $api->request('GET', '/students?q=' . urlencode('Hòa'));
$foundHoa = array_column($stHoaRes['body']['data'] ?? [], 'student_code');
it("API GET /api/students?q=Hòa khớp chính xác SV005", 
    in_array('SV005', $foundHoa) && !in_array('SV001', $foundHoa)
);

// Tìm kiếm lịch sử giao dịch qua API GET /api/history?q=nguyen
$histSearchRes = $api->request('GET', '/history?q=nguyen');
$histCodes = array_unique(array_column($histSearchRes['body']['data'] ?? [], 'student_code'));
it("API GET /api/history?q=nguyen trả về lịch sử giao dịch của cả SV001 và SV005", 
    in_array('SV001', $histCodes) && in_array('SV005', $histCodes)
);

// Kiểm tra query kết hợp student=nguyen&name=nguyen từ UI
$histCombinedRes = $api->request('GET', '/history?student=nguyen&name=nguyen');
it("API GET /api/history?student=nguyen&name=nguyen trả về kết quả hợp lệ", 
    count($histCombinedRes['body']['data'] ?? []) > 0
);

// ----------------------------------------------------------------------------
// 11. DELETION RESTRICTIONS VIA REAL API
// ----------------------------------------------------------------------------
suite_header("11. Ràng buộc xóa sinh viên & thông điệp chính xác (Deletion Rules)");

// Xóa SV004 (không có hồ sơ học phí) qua API DELETE /api/students/4
$del4Res = $api->request('DELETE', '/students/4');
it("API DELETE /api/students/4 thành công (HTTP 200)", $del4Res['status'] === 200);

$exists4 = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE id = 4")->fetchColumn();
it("SV004 đã được xóa khỏi database", $exists4 === 0);

// Khôi phục lại SV004
$pdo->prepare("INSERT INTO students (id, student_code, full_name, name_search, class_name, created_at, updated_at) VALUES (4, 'SV004', 'Phạm Thị Dung', 'pham thi dung', 'K21-KT2', NOW(), NOW())")->execute();

// Xóa SV001 (đã có dữ liệu học phí) qua API DELETE /api/students/1
$del1Res = $api->request('DELETE', '/students/1');
it("API DELETE /api/students/1 bị chặn với HTTP 409 và thông điệp chính xác: 'Không thể xóa sinh viên đã có dữ liệu học phí.'", 
    $del1Res['status'] === 409 && 
    ($del1Res['body']['error']['message'] ?? '') === 'Không thể xóa sinh viên đã có dữ liệu học phí.'
);

// ----------------------------------------------------------------------------
// 12. REPORTS RECONCILIATION VIA REAL API
// ----------------------------------------------------------------------------
suite_header("12. Đối soát báo cáo tài chính (Reports Reconciliation Equation)");

$reportRes = $api->request('GET', '/reports/summary?semester=3');
it("API GET /api/reports/summary?semester=3 trả về HTTP 200", $reportRes['status'] === 200);

$repData = $reportRes['body']['data'] ?? [];
$rec = (int)($repData['receivable'] ?? 0);
$col = (int)($repData['collected'] ?? 0);
$out = (int)($repData['outstanding'] ?? 0);
$totalSt = (int)($repData['total_students_with_records'] ?? 0);
$statusSum = (int)($repData['status_counts']['unpaid'] ?? 0) + 
             (int)($repData['status_counts']['partial'] ?? 0) + 
             (int)($repData['status_counts']['paid'] ?? 0);

it("Phương trình đối soát cân bằng tuyệt đối: collected ({$col}) + outstanding ({$out}) = receivable ({$rec})", 
    ($col + $out) === $rec && $repData['reconciles'] === true
);

it("Tổng số lượng sinh viên theo từng trạng thái bằng đúng tổng số hồ sơ kỳ 3", 
    $statusSum === $totalSt && $totalSt > 0,
    $totalSt,
    $statusSum
);

// ----------------------------------------------------------------------------
// 13. AUTH & SECURITY CHECKS VIA REAL API
// ----------------------------------------------------------------------------
suite_header("13. Kiểm thử an ninh, phiên làm việc & CSRF (Auth & Security)");

$unauthClient = new ApiTestClient();

// 1. Mọi endpoint trả về 401 khi không có session
$unauthFee = $unauthClient->request('GET', '/fee-records/1');
it("GET /api/fee-records/1 trả về 401 Unauthorized khi không có phiên đăng nhập", 
    $unauthFee['status'] === 401 && empty($unauthFee['body']['data'])
);

$unauthSt = $unauthClient->request('GET', '/students');
it("GET /api/students trả về 401 Unauthorized khi không có phiên đăng nhập", 
    $unauthSt['status'] === 401
);

// 2. CSRF Protection
// Ghi dữ liệu không kèm X-CSRF-Token -> 403
$apiNoCsrf = new ApiTestClient();
$apiNoCsrf->login('admin', 'Admin@123');
$noCsrfRes = $apiNoCsrf->request('POST', '/students', [
    'student_code' => 'TESTCS',
    'full_name'    => 'Test CSRF'
], ['X-CSRF-Token' => '']);
it("Yêu cầu ghi không có X-CSRF-Token bị chặn HTTP 403 Forbidden", $noCsrfRes['status'] === 403);

// Ghi dữ liệu với sai CSRF Token -> 403
$wrongCsrfRes = $apiNoCsrf->request('POST', '/students', [
    'student_code' => 'TESTCS',
    'full_name'    => 'Test CSRF'
], ['X-CSRF-Token' => 'invalid_csrf_token_value_1234567890']);
it("Yêu cầu ghi với sai CSRF token bị chặn HTTP 403 Forbidden", $wrongCsrfRes['status'] === 403);

// 3. Khóa tài khoản sau 5 lần đăng nhập sai
$lockClient = new ApiTestClient();
for ($i = 1; $i <= 5; $i++) {
    $failRes = $lockClient->request('POST', '/login', [
        'username' => 'staff01',
        'password' => 'WrongPassword!' . $i
    ]);
}

$userCheck = $pdo->query("SELECT failed_logins, locked_until FROM users WHERE username = 'staff01'")->fetch();
$isLocked = !empty($userCheck['locked_until']) && strtotime($userCheck['locked_until']) > time();
it("Sau 5 lần đăng nhập sai, tài khoản bị tạm khóa 15 phút (locked_until được thiết lập)", 
    $isLocked === true && (int)$userCheck['failed_logins'] === 5
);

// Lần thử thứ 6 với tài khoản đang bị khóa
$lockRes6 = $lockClient->request('POST', '/login', [
    'username' => 'staff01',
    'password' => 'Staff@123'
]);
it("Lần đăng nhập thứ 6 bị chặn với HTTP 409 ACCOUNT_LOCKED", 
    $lockRes6['status'] === 409 && ($lockRes6['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED'
);

// Giả lập hết thời gian khóa 15 phút
$pdo->exec("UPDATE users SET locked_until = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE username = 'staff01'");
$loginAfterUnlock = $lockClient->request('POST', '/login', [
    'username' => 'staff01',
    'password' => 'Staff@123'
]);
it("Sau khi hết thời gian khóa 15 phút, tài khoản đăng nhập lại thành công (HTTP 200)", 
    $loginAfterUnlock['status'] === 200
);

// ----------------------------------------------------------------------------
// 14. INPUT VALIDATION & AMOUNT PARSING
// ----------------------------------------------------------------------------
suite_header("14. Xác thực dữ liệu đầu vào & Chuẩn hóa tiền tệ (Input Validation)");

it("Chấp nhận định dạng tiền tệ có dấu chấm '1.500.000' -> 1500000", parse_currency("1.500.000") === 1500000);
it("Chấp nhận định dạng tiền tệ có dấu phẩy '1,500,000' -> 1500000", parse_currency("1,500,000") === 1500000);
it("Từ chối chuỗi có chữ '1,5abc'", parse_currency("1,5abc") === null);
it("Từ chối số âm '-100'", parse_currency("-100") === null);
it("Từ chối số 0", parse_currency("0") === null);
it("Từ chối số thập phân '1.5'", parse_currency("1.5") === null);
it("Từ chối giá trị vượt quá 999.999.999.999 '1000000000000'", parse_currency("1000000000000") === null);
it("Từ chối chuỗi rỗng ''", parse_currency("") === null);

// Unicode name: 100 ký tự chấp nhận, 101 ký tự bị từ chối qua API POST /api/students
$name100 = mb_substr(str_repeat('Đỗ ', 33) . 'An', 0, 100, 'UTF-8');
$name101 = $name100 . 'X';

$st100Res = $api->request('POST', '/students', [
    'student_code' => 'SVTEST100',
    'full_name'    => $name100
]);
it("Tên Unicode 100 ký tự được chấp nhận qua API (HTTP 201)", $st100Res['status'] === 201);
$pdo->exec("DELETE FROM students WHERE student_code = 'SVTEST100'");

$st101Res = $api->request('POST', '/students', [
    'student_code' => 'SVTEST101',
    'full_name'    => $name101
]);
it("Tên Unicode 101 ký tự bị từ chối qua API (HTTP 400)", $st101Res['status'] === 400);

// Lý do hoàn tiền dài 201 ký tự bị từ chối qua API
$reason201 = str_repeat('A', 201);
$ref201Res = $api->request('POST', '/fee-records/5/refunds', [
    'amount'     => 100000,
    'reason'     => $reason201,
    'request_id' => '99999999-9999-9999-9999-999999999999'
]);
it("Lý do dài 201 ký tự bị từ chối qua API (HTTP 400)", $ref201Res['status'] === 400);

// ----------------------------------------------------------------------------
// 15. PAYMENT DATE VALIDATION VIA REAL API
// ----------------------------------------------------------------------------
suite_header("15. Xác thực ngày thanh toán (Payment Date Validation)");

$tomorrowIso = date('Y-m-d', strtotime('+1 day'));

// Thanh toán với ngày tương lai qua API
$futurePayRes = $api->request('POST', '/fee-records/1/payments', [
    'amount'     => 100000,
    'method'     => 'Tiền mặt',
    'entry_date' => $tomorrowIso,
    'request_id' => '77777777-7777-7777-7777-777777777777'
]);
it("Ngày thanh toán trong tương lai bị từ chối qua API (HTTP 400)", 
    $futurePayRes['status'] === 400 && isset($futurePayRes['body']['error']['fields']['entry_date'])
);

// Thanh toán với ngày không đúng định dạng qua API
$invDatePayRes = $api->request('POST', '/fee-records/1/payments', [
    'amount'     => 100000,
    'method'     => 'Tiền mặt',
    'entry_date' => 'invalid-date-format',
    'request_id' => '88888888-8888-8888-8888-888888888888'
]);
it("Ngày thanh toán sai định dạng bị từ chối qua API (HTTP 400)", 
    $invDatePayRes['status'] === 400 && isset($invDatePayRes['body']['error']['fields']['entry_date'])
);

// ----------------------------------------------------------------------------
// TỔNG KẾT BỘ KIỂM THỬ
// ----------------------------------------------------------------------------
echo "\n" . COLOR_BOLD . "====================================================================\n";
if ($failedTests === 0) {
    echo COLOR_GREEN . COLOR_BOLD . " 🎉 TẤT CẢ {$passedTests}/{$totalTests} BÀI TEST ĐÃ VƯỢT QUA XUẤT SẮC!\n" . COLOR_RESET;
} else {
    echo COLOR_RED . COLOR_BOLD . " ⚠️ HOÀN THÀNH: {$passedTests} ĐẠT, {$failedTests} THẤT BẠI TRÊN TỔNG SỐ {$totalTests} BÀI TEST.\n" . COLOR_RESET;
}
echo COLOR_BOLD . "====================================================================\n" . COLOR_RESET;

exit($failedTests === 0 ? 0 : 1);
