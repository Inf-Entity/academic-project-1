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

    public function getCookieFile(): string {
        return $this->cookieFile;
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

$stCount = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$frCount = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
$exCount = (int)$pdo->query("SELECT COUNT(*) FROM exemptions")->fetchColumn();
$leCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
$rfCount = (int)$pdo->query("SELECT COUNT(*) FROM refund_requests")->fetchColumn();
it("Seed data nạp đủ số dòng chuẩn: 13 sinh viên, 13 hồ sơ học phí, 8 miễn giảm, 13 giao dịch sổ cái, 4 yêu cầu hoàn tiền", 
    $stCount === 13 && $frCount === 13 && $exCount === 8 && $leCount === 13 && $rfCount === 4,
    ['students' => 13, 'fee_records' => 13, 'exemptions' => 8, 'ledger_entries' => 13, 'refund_requests' => 4],
    ['students' => $stCount, 'fee_records' => $frCount, 'exemptions' => $exCount, 'ledger_entries' => $leCount, 'refund_requests' => $rfCount]
);

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
// 16. DUPLICATE PROFILE, REFUND AUDIT & OVERPAID RESOLUTION (PART 1, 2, 3)
// ----------------------------------------------------------------------------
suite_header("16. Quản lý hồ sơ trùng lặp, Kiểm toán hoàn tiền & Xử lý số tiền thừa (Parts 1, 2, 3)");

// 1. Duplicate create returns 409 DUPLICATE_FEE_RECORD and inserts no row. Count rows before and after.
$cntBefore = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
$dupRes = $api->request('POST', '/fee-records', [
    'student_id'         => 1,
    'semester_id'        => 3,
    'registered_credits' => 15,
]);
$cntAfter = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
it("Duplicate create trả về HTTP 409 DUPLICATE_FEE_RECORD, message và existing_id", 
    $dupRes['status'] === 409 && 
    ($dupRes['body']['error']['code'] ?? '') === 'DUPLICATE_FEE_RECORD' &&
    ($dupRes['body']['error']['message'] ?? '') === 'Sinh viên này đã có hồ sơ học phí cho học kỳ này.' &&
    !empty($dupRes['body']['data']['existing_id'])
);
it("Duplicate create không ghi thêm dòng vào fee_records", $cntBefore === $cntAfter);

// 2. Two parallel creates for the same student and semester produce exactly one row and one 409.
$pdo->prepare("INSERT INTO students (student_code, full_name, name_search, class_name, created_at, updated_at) VALUES ('SVPAR1', 'Sinh viên Song song', 'sinh vien song song', 'K20', NOW(), NOW())")->execute();
$parStudentId = (int)$pdo->lastInsertId();
$parSemesterId = 1; // HK1 2025-2026 (có định mức 800.000)

// Use 2 separate ApiTestClients with different sessions (admin and staff01) so sessions don't serialize
$clientA = new ApiTestClient();
$clientA->login('admin', 'Admin@123');
$clientB = new ApiTestClient();
$clientB->login('staff01', 'Staff@123');

$mh = curl_multi_init();
$bodyJson = json_encode([
    'student_id'         => $parStudentId,
    'semester_id'        => $parSemesterId,
    'registered_credits' => 12
]);

$chA = curl_init('http://localhost/tuition-system/api/fee-records');
curl_setopt_array($chA, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $bodyJson,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tuition-Test-Db: tuition_test',
        'X-CSRF-Token: ' . $clientA->getCsrfToken()
    ],
    CURLOPT_COOKIEFILE     => $clientA->getCookieFile(),
    CURLOPT_COOKIEJAR      => $clientA->getCookieFile(),
    CURLOPT_TIMEOUT        => 15
]);

$chB = curl_init('http://localhost/tuition-system/api/fee-records');
curl_setopt_array($chB, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $bodyJson,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tuition-Test-Db: tuition_test',
        'X-CSRF-Token: ' . $clientB->getCsrfToken()
    ],
    CURLOPT_COOKIEFILE     => $clientB->getCookieFile(),
    CURLOPT_COOKIEJAR      => $clientB->getCookieFile(),
    CURLOPT_TIMEOUT        => 15
]);

curl_multi_add_handle($mh, $chA);
curl_multi_add_handle($mh, $chB);

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$statusA = curl_getinfo($chA, CURLINFO_HTTP_CODE);
$statusB = curl_getinfo($chB, CURLINFO_HTTP_CODE);
curl_multi_remove_handle($mh, $chA);
curl_multi_remove_handle($mh, $chB);
curl_multi_close($mh);
curl_close($chA);
curl_close($chB);

$statuses = [$statusA, $statusB];
sort($statuses);
$parRecordCount = (int)$pdo->query("SELECT COUNT(*) FROM fee_records WHERE student_id = {$parStudentId} AND semester_id = {$parSemesterId}")->fetchColumn();

it("Hai yêu cầu tạo hồ sơ song song cho cùng 1 sinh viên/kỳ cho đúng 1 thành công (201) và 1 bị từ chối 409", 
    $statuses === [201, 409]
);
it("Hai yêu cầu song song tạo chính xác đúng 1 dòng trong cơ sở dữ liệu", $parRecordCount === 1);

// Dọn dẹp student song song
$pdo->exec("DELETE FROM fee_records WHERE student_id = {$parStudentId}");
$pdo->exec("DELETE FROM students WHERE id = {$parStudentId}");

// 3. Refund request: amount = excess + 1 rejected; amount = excess accepted; second request for 1 VND rejected while first is pending.
// SV007 HK2 (fee record 11): net 7.5M, paid 8.2M, excess 700k
$refOverRes = $api->request('POST', '/fee-records/11/refunds', [
    'amount'     => 700001,
    'reason'     => 'Yêu cầu hoàn vượt số dư thừa 1 đồng',
    'request_id' => generate_uuid()
]);
it("Yêu cầu hoàn tiền với amount = excess + 1 bị từ chối (HTTP 422)", $refOverRes['status'] === 422);

$refExactReqId = generate_uuid();
$refExactRes = $api->request('POST', '/fee-records/11/refunds', [
    'amount'     => 700000,
    'reason'     => 'Yêu cầu hoàn đúng số dư thừa 700.000',
    'request_id' => $refExactReqId
]);
it("Yêu cầu hoàn tiền với amount = excess được chấp nhận (HTTP 201 Created)", $refExactRes['status'] === 201);
$pendingRefundId = $refExactRes['body']['data']['id'] ?? null;

$refSecondRes = $api->request('POST', '/fee-records/11/refunds', [
    'amount'     => 1,
    'reason'     => 'Yêu cầu hoàn 1 đồng khi yêu cầu đầu đang chờ duyệt',
    'request_id' => generate_uuid()
]);
it("Yêu cầu hoàn 1 VND thứ hai bị từ chối khi yêu cầu đầu đang chờ duyệt (HTTP 422)", $refSecondRes['status'] === 422);

// 4. Cancel releases excess; a new request for the full excess is accepted.
$cancelRes = $api->request('POST', "/refunds/{$pendingRefundId}/cancel", [
    'cancel_reason' => 'Hủy để giải phóng số dư thừa'
]);
it("Hủy yêu cầu hoàn tiền đang chờ duyệt thành công (HTTP 200)", $cancelRes['status'] === 200);

$refNewRes = $api->request('POST', '/fee-records/11/refunds', [
    'amount'     => 700000,
    'reason'     => 'Yêu cầu hoàn tiền mới sau khi đã hủy yêu cầu cũ',
    'request_id' => generate_uuid()
]);
it("Sau khi hủy, yêu cầu hoàn tiền mới cho toàn bộ số dư thừa (700.000) được chấp nhận (HTTP 201)", $refNewRes['status'] === 201);
$newRefundId = $refNewRes['body']['data']['id'] ?? null;

// Hủy tiếp để trả SV007 về trạng thái ban đầu sạch
$api->request('POST', "/refunds/{$newRefundId}/cancel", ['cancel_reason' => 'Dọn dẹp test']);

// 5. Completion re-check concurrency: two pending requests of excess each, completed concurrently, produce exactly one success.
// Giả lập 2 yêu cầu pending trong DB cho cùng khoản dư thừa 700k
$concReqA = generate_uuid();
$concReqB = generate_uuid();
$pdo->prepare("INSERT INTO refund_requests (fee_record_id, amount, reason, status, request_id, created_by, created_at) VALUES (11, 700000, 'Pending A', 'pending', :r, 1, NOW())")->execute(['r' => $concReqA]);
$rfIdA = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO refund_requests (fee_record_id, amount, reason, status, request_id, created_by, created_at) VALUES (11, 700000, 'Pending B', 'pending', :r, 1, NOW())")->execute(['r' => $concReqB]);
$rfIdB = (int)$pdo->lastInsertId();

$cookieCompA = $clientA->getCookieFile();
$cookieCompB = $clientB->getCookieFile();

$mh2 = curl_multi_init();
$chCompA = curl_init("http://localhost/tuition-system/api/refunds/{$rfIdA}/complete");
curl_setopt_array($chCompA, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => '{}',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tuition-Test-Db: tuition_test',
        'X-CSRF-Token: ' . $clientA->getCsrfToken()
    ],
    CURLOPT_COOKIEFILE     => $cookieCompA,
    CURLOPT_COOKIEJAR      => $cookieCompA,
    CURLOPT_TIMEOUT        => 15
]);

$chCompB = curl_init("http://localhost/tuition-system/api/refunds/{$rfIdB}/complete");
curl_setopt_array($chCompB, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => '{}',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tuition-Test-Db: tuition_test',
        'X-CSRF-Token: ' . $clientB->getCsrfToken()
    ],
    CURLOPT_COOKIEFILE     => $cookieCompB,
    CURLOPT_COOKIEJAR      => $cookieCompB,
    CURLOPT_TIMEOUT        => 15
]);

curl_multi_add_handle($mh2, $chCompA);
curl_multi_add_handle($mh2, $chCompB);

$running2 = null;
do {
    curl_multi_exec($mh2, $running2);
    curl_multi_select($mh2);
} while ($running2 > 0);

$statusCompA = curl_getinfo($chCompA, CURLINFO_HTTP_CODE);
$statusCompB = curl_getinfo($chCompB, CURLINFO_HTTP_CODE);
curl_multi_remove_handle($mh2, $chCompA);
curl_multi_remove_handle($mh2, $chCompB);
curl_multi_close($mh2);
curl_close($chCompA);
curl_close($chCompB);

$compStatuses = [$statusCompA, $statusCompB];
sort($compStatuses);
it("Duyệt hoàn tiền đồng thời cho 2 yêu cầu vượt số dư thừa chỉ cho phép đúng 1 thành công (200) và 1 bị từ chối (422)", 
    $compStatuses === [200, 422]
);

// Dọn dẹp test đồng thời hoàn tiền trên SV007
$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = 11 AND entry_type = 'refund'");
$pdo->exec("DELETE FROM refund_requests WHERE fee_record_id = 11");

// 8. Transfer validations:
// - Transfer to self rejected
$xferSelfRes = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => 11,
    'amount'               => 100000,
    'reason'               => 'Chuyển cho chính mình',
    'request_id'           => generate_uuid()
]);
it("Chuyển tiền cho chính hồ sơ đó bị từ chối (HTTP 422)", $xferSelfRes['status'] === 422);

// - Transfer across students rejected
$xferCrossRes = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => 1,
    'amount'               => 100000,
    'reason'               => 'Chuyển khác sinh viên',
    'request_id'           => generate_uuid()
]);
it("Chuyển tiền giữa hai sinh viên khác nhau bị từ chối (HTTP 422)", $xferCrossRes['status'] === 422);

// - Transfer amount above source available excess rejected
$xferOverSourceRes = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => 12,
    'amount'               => 700001,
    'reason'               => 'Vượt dư thừa nguồn',
    'request_id'           => generate_uuid()
]);
it("Chuyển tiền vượt quá số tiền thừa khả dụng của nguồn bị từ chối (HTTP 422)", $xferOverSourceRes['status'] === 422);

// - Transfer to fully paid target rejected
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (7, 1, 10, 800000, 8000000, NOW(), NOW())")->execute();
$tempTargetId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, request_id, created_by, created_at) VALUES (:f, 'payment', 8000000, 'Tiền mặt', CURDATE(), :req, 1, NOW())")->execute([
    'f'   => $tempTargetId,
    'req' => generate_uuid()
]);
$xferPaidTargetRes = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => $tempTargetId,
    'amount'               => 100000,
    'reason'               => 'Chuyển sang hồ sơ đã thanh toán đủ',
    'request_id'           => generate_uuid()
]);
it("Chuyển tiền sang hồ sơ đích đã thanh toán đủ (outstanding = 0) bị từ chối (HTTP 422)", $xferPaidTargetRes['status'] === 422);
$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = {$tempTargetId}");
$pdo->exec("DELETE FROM fee_records WHERE id = {$tempTargetId}");

// 9. Transfer with same request_id twice creates two rows only once (Idempotency)
$xferIdempReqId = generate_uuid();
$xferCall1 = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => 12,
    'amount'               => 700000,
    'reason'               => 'Chuyển tiền thừa sang HK1 2026-2027',
    'request_id'           => $xferIdempReqId
]);
it("Chuyển tiền lần đầu thành công (HTTP 201 Created)", $xferCall1['status'] === 201);

$xferCall2 = $api->request('POST', '/fee-records/11/transfers', [
    'target_fee_record_id' => 12,
    'amount'               => 700000,
    'reason'               => 'Chuyển tiền thừa sang HK1 2026-2027',
    'request_id'           => $xferIdempReqId
]);
it("Gọi lại chuyển tiền với cùng request_id trả về HTTP 200 Idempotent", $xferCall2['status'] === 200);

$xferLedgerCount = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE request_id LIKE '{$xferIdempReqId}%'")->fetchColumn();
it("Chuyển tiền với cùng request_id chỉ tạo đúng 2 bút toán (transfer_out và transfer_in)", $xferLedgerCount === 2);

// 7. Transfer effect & reconciliation:
// - source total_paid drops; target outstanding drops by the same amount; both rows share transfer_group_id; report totals reconcile.
$sourceSumAfter = fetch_fee_record_summary($pdo, 11);
$targetSumAfter = fetch_fee_record_summary($pdo, 12);

$sourcePaidDrops = ((int)$sourceSumAfter['total_paid'] === 7500000); // 8.2M - 700k
$targetOutstandingDrops = ((int)$targetSumAfter['total_paid'] === 700000 && (int)$targetSumAfter['net_due'] - 700000 === 9500000);

$xferEntries = $pdo->query("SELECT id, fee_record_id, entry_type, amount, receipt_no, transfer_group_id FROM ledger_entries WHERE request_id LIKE '{$xferIdempReqId}%' ORDER BY id ASC")->fetchAll();
$sharedGroupId = (!empty($xferEntries[0]['transfer_group_id']) && $xferEntries[0]['transfer_group_id'] === $xferEntries[1]['transfer_group_id']);
$noReceiptNumbers = (empty($xferEntries[0]['receipt_no']) && empty($xferEntries[1]['receipt_no']));

it("Sau khi chuyển: hồ sơ nguồn (HK2) giảm total_paid về 7.500.000 (Fully Paid), hồ sơ đích (HK1) giảm nợ còn 9.500.000", 
    $sourcePaidDrops && $targetOutstandingDrops
);
it("Hai bút toán transfer_out và transfer_in cùng chung transfer_group_id và không có số biên lai", 
    $sharedGroupId && $noReceiptNumbers
);

// Đối soát báo cáo sau chuyển khoản
$repAllRes = $api->request('GET', '/reports/summary?semester=ALL');
$repDataAll = $repAllRes['body']['data'] ?? [];
$repReconciled = ((int)($repDataAll['collected'] ?? 0) + (int)($repDataAll['outstanding'] ?? 0) === (int)($repDataAll['receivable'] ?? 0)) && ($repDataAll['reconciles'] ?? false);
it("Sau khi chuyển khoản tiền thừa, báo cáo tài chính toàn trường vẫn đối soát cân bằng tuyệt đối", $repReconciled);

// 6. Reversal of a payment whose money was refunded or transferred is rejected; total_paid never becomes negative.
$pay11RevRes = $api->request('POST', '/ledger/11/reversal', [
    'reason'     => 'Đảo mục phiếu thu 8.2M sau khi tiền đã bị chuyển',
    'request_id' => generate_uuid()
]);
$sourceSumAfterRevAttempt = fetch_fee_record_summary($pdo, 11);
it("Đảo mục thanh toán khi số tiền đã được chuyển/hoàn bị từ chối với HTTP 422", 
    $pay11RevRes['status'] === 422 && 
    ($pay11RevRes['body']['error']['message'] ?? '') === 'Không thể đảo bút toán vì số tiền đã được hoàn hoặc chuyển đi.'
);
it("total_paid của hồ sơ nguồn không bao giờ bị âm sau khi đảo mục bị từ chối", (int)$sourceSumAfterRevAttempt['total_paid'] >= 0);

// 10. Transfer rows cannot be reversed.
$outEntryId = (int)$xferEntries[0]['id'];
$revTransferRes = $api->request('POST', "/ledger/{$outEntryId}/reversal", [
    'reason'     => 'Thử đảo bút toán chuyển khoản',
    'request_id' => generate_uuid()
]);
it("Bút toán chuyển khoản nội bộ không thể bị đảo mục (HTTP 422)", 
    $revTransferRes['status'] === 422 && 
    ($revTransferRes['body']['error']['message'] ?? '') === 'Không thể đảo bút toán chuyển khoản nội bộ.'
);

// 11. Pending refund and transfer together never exceed available excess.
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (7, 1, 10, 800000, 8000000, NOW(), NOW())")->execute();
$comboSourceId = (int)$pdo->lastInsertId();
// Nộp 9.000.000 (net = 8.0M => excess = 1.000.000)
$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, request_id, created_by, created_at) VALUES (:f, 'payment', 9000000, 'Tiền mặt', CURDATE(), :req, 1, NOW())")->execute([
    'f'   => $comboSourceId,
    'req' => generate_uuid()
]);
// Tạo pending refund 400.000 -> available excess còn lại: 600.000
$pdo->prepare("INSERT INTO refund_requests (fee_record_id, amount, reason, status, request_id, created_by, created_at) VALUES (:f, 400000, 'Pending combo', 'pending', :req, 1, NOW())")->execute([
    'f'   => $comboSourceId,
    'req' => generate_uuid()
]);

// Thử transfer 600.001 -> Phải bị từ chối 422
$comboOverRes = $api->request('POST', "/fee-records/{$comboSourceId}/transfers", [
    'target_fee_record_id' => 12,
    'amount'               => 600001,
    'reason'               => 'Chuyển vượt available excess khi có pending refund',
    'request_id'           => generate_uuid()
]);
it("Chuyển tiền vượt available excess (excess - pending) bị từ chối (HTTP 422)", $comboOverRes['status'] === 422);

// Transfer đúng 600.000 -> Thành công 201
$comboExactRes = $api->request('POST', "/fee-records/{$comboSourceId}/transfers", [
    'target_fee_record_id' => 12,
    'amount'               => 600000,
    'reason'               => 'Chuyển đúng available excess',
    'request_id'           => generate_uuid()
]);
it("Chuyển đúng available excess còn lại (600.000) thành công (HTTP 201)", $comboExactRes['status'] === 201);

// Sau khi transfer 600.000, available excess = 0. Thử transfer thêm 1 VND -> bị từ chối 422
$comboZeroRes = $api->request('POST', "/fee-records/{$comboSourceId}/transfers", [
    'target_fee_record_id' => 12,
    'amount'               => 1,
    'reason'               => 'Chuyển khi available excess = 0',
    'request_id'           => generate_uuid()
]);
it("Sau khi pending refund + transfer đã dùng hết excess, chuyển thêm 1 VND bị từ chối (HTTP 422)", $comboZeroRes['status'] === 422);

// Dọn dẹp hồ sơ combo
$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = {$comboSourceId}");
$pdo->exec("DELETE FROM refund_requests WHERE fee_record_id = {$comboSourceId}");
$pdo->exec("DELETE FROM fee_records WHERE id = {$comboSourceId}");

// ----------------------------------------------------------------------------
// 17. KIỂM THỬ KHẮC PHỤC LỖI (DEFECT VERIFICATION: ENCODING, REFUNDS, OVERPAID)
// ----------------------------------------------------------------------------
suite_header("17. Kiểm thử các lỗi đã khắc phục (Defect Verification: Encoding, Refunds, Overpaid Status)");

// 17.1 Encoding round-trip (Issue 1)
$testStrings = ["Nguyễn Văn An", "Đã thanh toán đủ", "Thanh toán một phần"];

$stmt = $pdo->prepare("SELECT :s1 AS s1, :s2 AS s2, :s3 AS s3");
$stmt->execute([
    's1' => $testStrings[0],
    's2' => $testStrings[1],
    's3' => $testStrings[2]
]);
$dbRow = $stmt->fetch();
$dbRoundTripOk = ($dbRow['s1'] === $testStrings[0] && $dbRow['s2'] === $testStrings[1] && $dbRow['s3'] === $testStrings[2])
    && (bin2hex($dbRow['s1']) === bin2hex($testStrings[0]))
    && (bin2hex($dbRow['s2']) === bin2hex($testStrings[1]))
    && (bin2hex($dbRow['s3']) === bin2hex($testStrings[2]));

$jsonEncoded = json_encode($testStrings, JSON_UNESCAPED_UNICODE);
$jsonDecoded = json_decode($jsonEncoded, true);
$jsonRoundTripOk = ($jsonDecoded === $testStrings)
    && (bin2hex($jsonDecoded[0]) === bin2hex($testStrings[0]))
    && (bin2hex($jsonDecoded[1]) === bin2hex($testStrings[1]))
    && (bin2hex($jsonDecoded[2]) === bin2hex($testStrings[2]));

$sv001Check = $pdo->query("SELECT full_name, HEX(full_name) AS hex_name FROM students WHERE student_code = 'SV001'")->fetch();
$sv001HexOk = ($sv001Check && str_starts_with($sv001Check['hex_name'], '4E677579E1BB856E')); // UTF-8 bytes for "Nguyễn"

it("Encoding round-trip: Nguyễn Văn An, Đã thanh toán đủ, Thanh toán một phần toàn vẹn qua DB, JSON và HEX SV001",
    $dbRoundTripOk && $jsonRoundTripOk && $sv001HexOk
);

// Chuẩn bị dữ liệu kiểm thử cho các test 17.2 -> 17.5
// Tạo 1 sinh viên kiểm thử SV999
$pdo->exec("DELETE FROM students WHERE student_code = 'SV999'");
$pdo->prepare("INSERT INTO students (student_code, full_name, name_search, class_name, created_at, updated_at) VALUES ('SV999', 'Nguyễn Kiểm Thử', 'nguyen kiem thu', 'TEST-01', NOW(), NOW())")->execute();
$testStudentId = (int)$pdo->lastInsertId();

// Tạo hồ sơ học phí A (Kỳ 1) cho SV999: 10 tín chỉ x 850.000 = 8.500.000
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (:sid, 1, 10, 850000, 8500000, NOW(), NOW())")->execute(['sid' => $testStudentId]);
$feeRecordA = (int)$pdo->lastInsertId();

// Nộp 9.500.000 -> nộp thừa 1.000.000
$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, request_id, created_by, created_at) VALUES (:frId, 'payment', 9500000, 'Chuyển khoản', CURDATE(), :rcpt, :reqId, 1, NOW())")->execute([
    'frId'  => $feeRecordA,
    'rcpt'  => 'PT-' . date('Ymd') . '-9991',
    'reqId' => generate_uuid()
]);

// 17.2 Refund created via endpoint appears in GET /api/fee-records/{id}.refunds
$refundReqId = generate_uuid();
$createRefRes = $api->request('POST', "/fee-records/{$feeRecordA}/refunds", [
    'amount'     => 1000000,
    'reason'     => 'Hoàn tiền thừa kiểm thử',
    'request_id' => $refundReqId
]);
$createdRefundId = (int)($createRefRes['body']['data']['id'] ?? 0);

$getFeeARes = $api->request('GET', "/fee-records/{$feeRecordA}");
$refundsList = $getFeeARes['body']['data']['refunds'] ?? null;
$refundFound = false;
if (is_array($refundsList)) {
    foreach ($refundsList as $rfItem) {
        if ((int)($rfItem['id'] ?? 0) === $createdRefundId) {
            $refundFound = true;
            break;
        }
    }
}
it("Refund created via the endpoint appears in GET /api/fee-records/{id}.refunds", 
    $createRefRes['status'] === 201 && is_array($refundsList) && $refundFound
);

// 17.3 After refund completion, the record's status is Fully Paid and overpaid is false
$compRes = $api->request('POST', "/refunds/{$createdRefundId}/complete");
$getFeeAAfterComp = $api->request('GET', "/fee-records/{$feeRecordA}");
$feeAData = $getFeeAAfterComp['body']['data'] ?? [];
it("After refund completion, the record's status is Fully Paid and overpaid is false", 
    $compRes['status'] === 200 
    && ($feeAData['status'] ?? '') === 'Fully Paid' 
    && ($feeAData['is_overpaid'] ?? null) === false
    && (int)($feeAData['total_paid'] ?? 0) === 8500000
    && (int)($feeAData['net_due'] ?? 0) === 8500000
);

// Tạo hồ sơ học phí B (nguồn) và C (đích) cho SV999 để test chuyển tiền thừa (transfer)
// Hồ sơ B (Kỳ 2): Gross 7.000.000, nộp 7.700.000 -> thừa 700.000
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (:sid, 2, 10, 700000, 7000000, NOW(), NOW())")->execute(['sid' => $testStudentId]);
$feeRecordB = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, request_id, created_by, created_at) VALUES (:frId, 'payment', 7700000, 'Chuyển khoản', CURDATE(), :rcpt, :reqId, 1, NOW())")->execute([
    'frId'  => $feeRecordB,
    'rcpt'  => 'PT-' . date('Ymd') . '-9992',
    'reqId' => generate_uuid()
]);

// Hồ sơ C (Kỳ 3): Gross 8.500.000, nộp 0 -> còn nợ 8.500.000
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition, created_at, updated_at) VALUES (:sid, 3, 10, 850000, 8500000, NOW(), NOW())")->execute(['sid' => $testStudentId]);
$feeRecordC = (int)$pdo->lastInsertId();

// 17.4 After transfer, source is Fully Paid, target outstanding decreased by the transfer amount, and neither profile shows Overpaid
$transferRes = $api->request('POST', "/fee-records/{$feeRecordB}/transfers", [
    'target_fee_record_id' => $feeRecordC,
    'amount'               => 700000,
    'reason'               => 'Chuyển toàn bộ 700.000 tiền thừa sang kỳ 3',
    'request_id'           => generate_uuid()
]);

$getSourceRes = $api->request('GET', "/fee-records/{$feeRecordB}");
$getTargetRes = $api->request('GET', "/fee-records/{$feeRecordC}");
$sourceData = $getSourceRes['body']['data'] ?? [];
$targetData = $getTargetRes['body']['data'] ?? [];

$sourceOk = (($sourceData['status'] ?? '') === 'Fully Paid' && ($sourceData['is_overpaid'] ?? null) === false && (int)($sourceData['total_paid'] ?? 0) === 7000000);
$targetOk = (($targetData['is_overpaid'] ?? null) === false && (int)($targetData['outstanding'] ?? 0) === 7800000 && (int)($targetData['total_paid'] ?? 0) === 700000);

it("After transfer, source is Fully Paid, target outstanding decreased by the transfer amount, and neither profile shows Overpaid", 
    $transferRes['status'] === 201 && $sourceOk && $targetOk
);

// 17.5 Cancel of a pending refund returns the excess and the Overpaid warning reappears correctly
// Nộp thêm 500.000 vào hồ sơ B -> nộp thừa lại 500.000 (total_paid = 7.500.000, net = 7.000.000)
$pdo->prepare("INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, request_id, created_by, created_at) VALUES (:frId, 'payment', 500000, 'Chuyển khoản', CURDATE(), :rcpt, :reqId, 1, NOW())")->execute([
    'frId'  => $feeRecordB,
    'rcpt'  => 'PT-' . date('Ymd') . '-9993',
    'reqId' => generate_uuid()
]);

// Tạo pending refund 500.000
$refCancelTestRes = $api->request('POST', "/fee-records/{$feeRecordB}/refunds", [
    'amount'     => 500000,
    'reason'     => 'Yêu cầu hoàn để kiểm thử hủy',
    'request_id' => generate_uuid()
]);
$refundToCancelId = (int)($refCancelTestRes['body']['data']['id'] ?? 0);

// Hủy yêu cầu hoàn tiền này
$cancelActionRes = $api->request('POST', "/refunds/{$refundToCancelId}/cancel", [
    'cancel_reason' => 'Hủy yêu cầu hoàn tiền để trả lại số dư thừa'
]);

$getFeeBAfterCancel = $api->request('GET', "/fee-records/{$feeRecordB}");
$feeBAfterCancelData = $getFeeBAfterCancel['body']['data'] ?? [];

it("Cancel of a pending refund returns the excess and the Overpaid warning reappears correctly", 
    $cancelActionRes['status'] === 200
    && ($feeBAfterCancelData['is_overpaid'] ?? false) === true
    && (int)($feeBAfterCancelData['overpaid_amount'] ?? 0) === 500000
    && (int)($feeBAfterCancelData['pending_refunds_sum'] ?? -1) === 0
);

// Dọn dẹp dữ liệu kiểm thử SV999
$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id IN ({$feeRecordA}, {$feeRecordB}, {$feeRecordC})");
$pdo->exec("DELETE FROM refund_requests WHERE fee_record_id IN ({$feeRecordA}, {$feeRecordB}, {$feeRecordC})");
$pdo->exec("DELETE FROM fee_records WHERE id IN ({$feeRecordA}, {$feeRecordB}, {$feeRecordC})");
$pdo->exec("DELETE FROM students WHERE id = {$testStudentId}");

// ----------------------------------------------------------------------------
// 18. KIỂM THỬ XÁC THỰC DỮ LIỆU ĐẦU VÀO & BẢN ĐỒ LỖI (PART 1: VALIDATION & FIELD MAP)
// ----------------------------------------------------------------------------
suite_header("18. Kiểm thử xác thực dữ liệu đầu vào & bản đồ lỗi (Part 1: Validation & Field Map)");

// 18.1 Amounts
$validAmounts = ['1.500.000', '1,500,000', '1 500 000', '1500000 đ', '₫1.500.000'];
$allValidAmountsParsed = true;
foreach ($validAmounts as $va) {
    if (parse_currency($va) !== 1500000) {
        $allValidAmountsParsed = false;
        break;
    }
}
it("Các định dạng số tiền hợp lệ ('1.500.000', '1,500,000', '1 500 000', '1500000 đ', '₫1.500.000') đều chuẩn hóa thành 1.500.000", $allValidAmountsParsed);

$invalidAmounts = ['1,5abc', '-100', '0', '1.5', '1e6', '٣٠٠', '1000000000000', ''];
$allInvalidAmountsRejected = true;
foreach ($invalidAmounts as $ia) {
    if (parse_currency($ia) !== null) {
        $allInvalidAmountsRejected = false;
        break;
    }
}
it("parse_currency từ chối các giá trị sai: '1,5abc', '-100', '0', '1.5', '1e6', '٣٠٠', > 999 tỷ và rỗng", $allInvalidAmountsRejected);

$badAmtRes = $api->request('POST', "/fee-records/12/payments", [
    'amount'     => '1,5abc',
    'method'     => 'Chuyển khoản',
    'entry_date' => date('Y-m-d'),
    'request_id' => generate_uuid()
]);
it("API nộp tiền với số tiền sai định dạng trả về HTTP 400 và có error.fields.amount", 
    $badAmtRes['status'] === 400 && !empty($badAmtRes['body']['error']['fields']['amount'])
);

// 18.2 Student Code validation
$badCodeShort = $api->request('POST', "/students", [
    'student_code' => 'SV01',
    'full_name'    => 'Nguyễn Văn Test'
]);
it("API thêm sinh viên từ chối mã < 6 ký tự ('SV01') với HTTP 400 và error.fields.student_code", 
    $badCodeShort['status'] === 400 && !empty($badCodeShort['body']['error']['fields']['student_code'])
);

$badCodeLong = $api->request('POST', "/students", [
    'student_code' => 'SV1234567890123',
    'full_name'    => 'Nguyễn Văn Test'
]);
it("API thêm sinh viên từ chối mã > 12 ký tự với HTTP 400 và error.fields.student_code", 
    $badCodeLong['status'] === 400 && !empty($badCodeLong['body']['error']['fields']['student_code'])
);

$badCodeSpace = $api->request('POST', "/students", [
    'student_code' => 'SV 001',
    'full_name'    => 'Nguyễn Văn Test'
]);
it("API thêm sinh viên từ chối mã chứa khoảng trắng ('SV 001') với HTTP 400 và error.fields.student_code", 
    $badCodeSpace['status'] === 400 && !empty($badCodeSpace['body']['error']['fields']['student_code'])
);

$badCodePunct = $api->request('POST', "/students", [
    'student_code' => 'SV-001',
    'full_name'    => 'Nguyễn Văn Test'
]);
it("API thêm sinh viên từ chối mã chứa ký tự đặc biệt ('SV-001') với HTTP 400 và error.fields.student_code", 
    $badCodePunct['status'] === 400 && !empty($badCodePunct['body']['error']['fields']['student_code'])
);

$api->request('POST', "/students", [
    'student_code' => 'SV202699',
    'full_name'    => 'Nguyễn Văn Đã Có'
]);
$dupCodeRes = $api->request('POST', "/students", [
    'student_code' => 'SV202699',
    'full_name'    => 'Nguyễn Văn Trùng Mã'
]);
$pdo->exec("DELETE FROM students WHERE student_code = 'SV202699'");
it("API thêm sinh viên từ chối mã đã tồn tại với HTTP 400 và thông báo 'Mã sinh viên đã tồn tại.'", 
    $dupCodeRes['status'] === 400 && ($dupCodeRes['body']['error']['fields']['student_code'] ?? '') === 'Mã sinh viên đã tồn tại.'
);

// 18.3 Full Name validation
$badNameCtrl = $api->request('POST', "/students", [
    'student_code' => 'SVTEST01',
    'full_name'    => "Nguyễn Văn\tTest"
]);
it("API thêm sinh viên từ chối tên chứa ký tự điều khiển (control chars) với HTTP 400", 
    $badNameCtrl['status'] === 400 && !empty($badNameCtrl['body']['error']['fields']['full_name'])
);

$badNameDigits = $api->request('POST', "/students", [
    'student_code' => 'SVTEST02',
    'full_name'    => "12345678"
]);
it("API thêm sinh viên từ chối tên chỉ gồm chữ số với HTTP 400 và error.fields.full_name", 
    $badNameDigits['status'] === 400 && !empty($badNameDigits['body']['error']['fields']['full_name'])
);

$badNameSymbols = $api->request('POST', "/students", [
    'student_code' => 'SVTEST03',
    'full_name'    => "!@#$%^&*()"
]);
it("API thêm sinh viên từ chối tên chỉ gồm ký tự đặc biệt với HTTP 400 và error.fields.full_name", 
    $badNameSymbols['status'] === 400 && !empty($badNameSymbols['body']['error']['fields']['full_name'])
);

// Combining diacritics NFC normalized
$combiningNameNFD = "Nguye" . "\xCC\x83" . "n Va" . "\xCC\x86" . "n Test";
$addCombiningRes = $api->request('POST', "/students", [
    'student_code' => 'SVTEST04',
    'full_name'    => $combiningNameNFD
]);
$savedCombName = (string)$pdo->query("SELECT full_name FROM students WHERE student_code = 'SVTEST04'")->fetchColumn();
it("API chấp nhận tên chứa dấu tổ hợp (combining diacritics) và NFC-normalize khi lưu", 
    $addCombiningRes['status'] === 201 && $savedCombName === normalize_nfc($combiningNameNFD)
);
$pdo->exec("DELETE FROM students WHERE student_code = 'SVTEST04'");

// 18.4 Class validation
$longClassRes = $api->request('POST', "/students", [
    'student_code' => 'SVTEST05',
    'full_name'    => 'Nguyễn Văn Test',
    'class_name'   => str_repeat('A', 51)
]);
it("API từ chối tên lớp dài hơn 50 ký tự với HTTP 400 và error.fields.class_name", 
    $longClassRes['status'] === 400 && !empty($longClassRes['body']['error']['fields']['class_name'])
);

$emptyClassRes = $api->request('POST', "/students", [
    'student_code' => 'SVTEST06',
    'full_name'    => 'Nguyễn Văn Test',
    'class_name'   => '   '
]);
$savedEmptyClass = $pdo->query("SELECT class_name FROM students WHERE student_code = 'SVTEST06'")->fetchColumn();
it("API lưu lớp rỗng hoặc chỉ có khoảng trắng thành NULL", 
    $emptyClassRes['status'] === 201 && $savedEmptyClass === null
);
$pdo->exec("DELETE FROM students WHERE student_code = 'SVTEST06'");

// 18.5 Date validation
$invalidCalDateRes = $api->request('POST', "/fee-records/12/payments", [
    'amount'     => 100000,
    'method'     => 'Chuyển khoản',
    'entry_date' => '2026-02-31',
    'request_id' => generate_uuid()
]);
it("API từ chối ngày thanh toán không tồn tại trên lịch (31/02/2026) với HTTP 400", 
    $invalidCalDateRes['status'] === 400 && !empty($invalidCalDateRes['body']['error']['fields']['entry_date'])
);

$futureDateRes = $api->request('POST', "/fee-records/12/payments", [
    'amount'     => 100000,
    'method'     => 'Chuyển khoản',
    'entry_date' => date('Y-m-d', strtotime('+10 days')),
    'request_id' => generate_uuid()
]);
it("API từ chối ngày thanh toán trong tương lai với HTTP 400", 
    $futureDateRes['status'] === 400 && !empty($futureDateRes['body']['error']['fields']['entry_date'])
);

$ddmmyyyyRes = $api->request('POST', "/fee-records/12/payments", [
    'amount'     => 100000,
    'method'     => 'Chuyển khoản',
    'entry_date' => '01/09/2026',
    'request_id' => generate_uuid()
]);
$savedDdMmYyyyDate = (string)$pdo->query("SELECT entry_date FROM ledger_entries WHERE fee_record_id = 12 AND amount = 100000 ORDER BY id DESC LIMIT 1")->fetchColumn();
it("API nộp tiền chấp nhận định dạng ngày dd/mm/yyyy và lưu thành định dạng ISO YYYY-MM-DD", 
    $ddmmyyyyRes['status'] === 201 && $savedDdMmYyyyDate === '2026-09-01'
);
$pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = 12 AND amount = 100000");

$badDateRangeRes = $api->request('GET', "/history?from=2026-09-30&to=2026-09-01");
it("API tra cứu lịch sử từ chối khoảng ngày có 'to' nhỏ hơn 'from' với HTTP 400", 
    $badDateRangeRes['status'] === 400 && !empty($badDateRangeRes['body']['error']['fields']['to'])
);

// 18.6 Exemption validation
$badExemptZeroRes = $api->request('PUT', "/fee-records/2/exemption", [
    'type'   => 'percent',
    'value'  => 0,
    'reason' => 'Test 0%'
]);
it("API miễn giảm từ chối phần trăm = 0 với HTTP 400", 
    $badExemptZeroRes['status'] === 400 && !empty($badExemptZeroRes['body']['error']['fields']['value'])
);

$badExempt101Res = $api->request('PUT', "/fee-records/2/exemption", [
    'type'   => 'percent',
    'value'  => 101,
    'reason' => 'Test 101%'
]);
it("API miễn giảm từ chối phần trăm = 101 với HTTP 400", 
    $badExempt101Res['status'] === 400 && !empty($badExempt101Res['body']['error']['fields']['value'])
);

$badExemptStrRes = $api->request('PUT', "/fee-records/2/exemption", [
    'type'   => 'percent',
    'value'  => '50%',
    'reason' => 'Test 50%'
]);
it("API miễn giảm từ chối chuỗi '50%' với HTTP 400", 
    $badExemptStrRes['status'] === 400 && !empty($badExemptStrRes['body']['error']['fields']['value'])
);

$badExemptFixedOverRes = $api->request('PUT', "/fee-records/2/exemption", [
    'type'   => 'fixed',
    'value'  => 999999999,
    'reason' => 'Test vượt'
]);
$exOverMsg = $badExemptFixedOverRes['body']['error']['fields']['value'] ?? '';
it("API miễn giảm cố định vượt học phí gốc bị từ chối với HTTP 400 và thông báo có chứa học phí gốc", 
    $badExemptFixedOverRes['status'] === 400 && str_contains($exOverMsg, '15.300.000')
);

// 18.7 Semester term validation
$badTermRes = $api->request('POST', "/semesters", [
    'academic_year_start' => 2027,
    'term_no'             => 4
]);
it("API thêm học kỳ từ chối term_no = 4 với HTTP 400", 
    $badTermRes['status'] === 400 && !empty($badTermRes['body']['error']['fields']['term_no'])
);

$dupSemRes = $api->request('POST', "/semesters", [
    'academic_year_start' => 2026,
    'term_no'             => 1
]);
it("API thêm học kỳ từ chối học kỳ trùng lặp với HTTP 400", 
    $dupSemRes['status'] === 400 && !empty($dupSemRes['body']['error']['fields']['term_no'])
);

// 18.8 Rate validation
$badRatePriceRes = $api->request('POST', "/rates", [
    'semester_id'      => 1,
    'price_per_credit' => 0
]);
it("API định mức từ chối đơn giá = 0 với HTTP 400", 
    $badRatePriceRes['status'] === 400 && !empty($badRatePriceRes['body']['error']['fields']['price_per_credit'])
);

$dupRateRes = $api->request('POST', "/rates", [
    'semester_id'      => 1,
    'price_per_credit' => 850000
]);
it("API định mức từ chối học kỳ đã có định mức với HTTP 400", 
    $dupRateRes['status'] === 400 && !empty($dupRateRes['body']['error']['fields']['semester_id'])
);

// 18.9 Login validation
$badLoginEmpty = (new ApiTestClient())->request('POST', "/login", [
    'username' => '',
    'password' => ''
]);
it("API đăng nhập từ chối tài khoản hoặc mật khẩu rỗng với HTTP 400", 
    $badLoginEmpty['status'] === 400 && !empty($badLoginEmpty['body']['error']['fields']['username'])
);

$badLoginLongUser = (new ApiTestClient())->request('POST', "/login", [
    'username' => str_repeat('u', 51),
    'password' => 'Pass@123'
]);
it("API đăng nhập từ chối tên đăng nhập dài hơn 50 ký tự với HTTP 400", 
    $badLoginLongUser['status'] === 400 && !empty($badLoginLongUser['body']['error']['fields']['username'])
);

$passSpacesClient = new ApiTestClient();
$passWithSpacesRes = $passSpacesClient->login('admin', ' Admin@123 ');
it("Mật khẩu có khoảng trắng đầu/cuối được bảo toàn nguyên vẹn (không bị trim), sai mật khẩu bị từ chối", 
    $passWithSpacesRes['status'] === 401
);

// 18.10 Search validation
$longSearchRes = $api->request('GET', "/students?q=" . str_repeat('a', 101));
it("API tìm kiếm từ chối từ khóa dài hơn 100 ký tự với HTTP 400", 
    $longSearchRes['status'] === 400 && !empty($longSearchRes['body']['error']['fields']['q'])
);

$diacriticsOnlySearchRes = $api->request('GET', "/students?q=" . urlencode("   ̀ ́ ̃ ̉ ̣   "));
it("API tìm kiếm xử lý từ khóa chỉ chứa dấu thanh hoặc khoảng trắng tương đương rỗng, trả về danh sách đầy đủ", 
    $diacriticsOnlySearchRes['status'] === 200 && count($diacriticsOnlySearchRes['body']['data'] ?? []) === 13
);

$longFeeSearchRes = $api->request('GET', "/fee-records?student=" . str_repeat('a', 101));
it("API lọc hồ sơ từ chối từ khóa sinh viên dài hơn 100 ký tự với HTTP 400 và error.fields.student", 
    $longFeeSearchRes['status'] === 400 && !empty($longFeeSearchRes['body']['error']['fields']['student'])
);

$invalidFeeSemRes = $api->request('GET', "/fee-records?semester=abc");
it("API lọc hồ sơ từ chối học kỳ không hợp lệ với HTTP 400 và error.fields.semester", 
    $invalidFeeSemRes['status'] === 400 && !empty($invalidFeeSemRes['body']['error']['fields']['semester'])
);

$longReceiptRes = $api->request('GET', "/receipts/" . str_repeat('p', 101));
it("API tra cứu biên lai từ chối mã tra cứu dài hơn 100 ký tự với HTTP 400 và error.fields.receipt_no", 
    $longReceiptRes['status'] === 400 && !empty($longReceiptRes['body']['error']['fields']['receipt_no'])
);

$diacriticsReceiptRes = $api->request('GET', "/receipts/" . urlencode("   ̀ ́ ̃   "));
it("API tra cứu biên lai từ chối mã tra cứu chỉ chứa dấu thanh với HTTP 400 và error.fields.receipt_no", 
    $diacriticsReceiptRes['status'] === 400 && !empty($diacriticsReceiptRes['body']['error']['fields']['receipt_no'])
);

// ----------------------------------------------------------------------------
// 19. KIỂM THỬ DỮ LIỆU SINH VIÊN MỞ RỘNG (PART 2: REFRESHED STUDENT CASES)
// ----------------------------------------------------------------------------
suite_header("19. Kiểm thử dữ liệu sinh viên mở rộng (Part 2: Refreshed Student Cases)");

// 19.1 SV008: "Lý Thị Mỹ Linh", class "K22-QTKD1"
$sv008 = $pdo->query("SELECT * FROM students WHERE student_code = 'SV008'")->fetch(PDO::FETCH_ASSOC);
it("SV008 'Lý Thị Mỹ Linh' tồn tại trong cơ sở dữ liệu với lớp 'K22-QTKD1' và dấu tiếng Việt đầy đủ", 
    !empty($sv008) && $sv008['full_name'] === 'Lý Thị Mỹ Linh' && $sv008['class_name'] === 'K22-QTKD1'
);

// 19.2 SV009: "Đoàn Văn Ơn Ích" diacritic-insensitive search matching "don" and "đoàn"
$searchDonRes = $api->request('GET', "/students?q=don");
$searchDonStudents = array_column($searchDonRes['body']['data'] ?? [], 'student_code');
it("Tìm kiếm sinh viên với từ khóa 'don' tìm thấy SV009 'Đoàn Văn Ơn Ích'", 
    in_array('SV009', $searchDonStudents, true)
);

$searchDoanRes = $api->request('GET', "/students?q=" . urlencode("đoàn"));
$searchDoanStudents = array_column($searchDoanRes['body']['data'] ?? [], 'student_code');
it("Tìm kiếm sinh viên với từ khóa 'đoàn' tìm thấy SV009 'Đoàn Văn Ơn Ích'", 
    in_array('SV009', $searchDoanStudents, true)
);

// 19.3 SV010: 40-character name accepted and near-limit display
$sv010 = $pdo->query("SELECT * FROM students WHERE student_code = 'SV010'")->fetch(PDO::FETCH_ASSOC);
$sv010NameLen = mb_strlen($sv010['full_name'] ?? '', 'UTF-8');
it("SV010 tồn tại với họ tên chính xác 40 ký tự ('Trương Thị Ánh Tuyết Hoàng Thị Hồng Loan')", 
    !empty($sv010) && $sv010NameLen === 40
);

// 19.4 SV011: class NULL, no fee records, deletable
$sv011 = $pdo->query("SELECT * FROM students WHERE student_code = 'SV011'")->fetch(PDO::FETCH_ASSOC);
$sv011FeeCount = (int)$pdo->query("SELECT COUNT(*) FROM fee_records WHERE student_id = {$sv011['id']}")->fetchColumn();
it("SV011 'Ngô Quang Khải' có class_name là NULL và không có hồ sơ học phí nào", 
    !empty($sv011) && $sv011['class_name'] === null && $sv011FeeCount === 0
);

$delSv011Res = $api->request('DELETE', "/students/{$sv011['id']}");
$sv011AfterDel = $pdo->query("SELECT COUNT(*) FROM students WHERE student_code = 'SV011'")->fetchColumn();
it("Xóa sinh viên SV011 không có hồ sơ học phí thành công (HTTP 200) và bản ghi bị xóa khỏi cơ sở dữ liệu", 
    $delSv011Res['status'] === 200 && (int)$sv011AfterDel === 0
);

// Re-insert SV011 so future seed tests remain clean
$pdo->prepare("INSERT INTO students (student_code, full_name, class_name, name_search, created_at, updated_at) VALUES ('SV011', 'Ngô Quang Khải', NULL, 'ngo quang khai', NOW(), NOW())")->execute();

// 19.5 SV012: Fee record 13 with 50% exemption, refund completed, second payment, running balance
$sv012 = $pdo->query("SELECT * FROM students WHERE student_code = 'SV012'")->fetch(PDO::FETCH_ASSOC);
$fee13 = fetch_fee_record_summary($pdo, 13);
$fr13Exempt = $pdo->query("SELECT * FROM exemptions WHERE fee_record_id = 13")->fetch(PDO::FETCH_ASSOC);
$fr13Refund = $pdo->query("SELECT * FROM refund_requests WHERE fee_record_id = 13")->fetch(PDO::FETCH_ASSOC);

it("SV012 có hồ sơ học phí #13 với miễn giảm 50% (Gross: 8.000.000, Net: 4.000.000)", 
    !empty($fee13) 
    && (int)$fee13['gross_tuition'] === 8000000 
    && (int)$fr13Exempt['value'] === 50 
    && (int)$fee13['net_due'] === 4000000
);

it("SV012 có yêu cầu hoàn tiền #4 trạng thái 'completed' (500.000 VND)", 
    !empty($fr13Refund) 
    && $fr13Refund['status'] === 'completed' 
    && (int)$fr13Refund['amount'] === 500000
);

$historySv012Res = $api->request('GET', "/history?student=SV012");
$histEntries = $historySv012Res['body']['data'] ?? [];
$histAmounts = array_map('intval', array_column($histEntries, 'amount'));
$histBalances = array_map('intval', array_column($histEntries, 'remaining_balance'));
it("Tra cứu lịch sử giao dịch của SV012 trả về đúng 2 giao dịch với số dư lũy kế (running balance) chuẩn", 
    count($histEntries) === 2 
    && in_array(3000000, $histAmounts, true) 
    && in_array(1500000, $histAmounts, true)
    && in_array(1000000, $histBalances, true)
    && in_array(0, $histBalances, true)
);

// 19.6 Lowercase student code normalization for "sv013"
$sv013Db = $pdo->query("SELECT student_code FROM students WHERE full_name = 'Hoàng Minh Tuấn'")->fetchColumn();
it("Mã sinh viên viết thường 'sv013' trong seed data được tự động lưu thành chữ in hoa 'SV013'", 
    $sv013Db === 'SV013'
);

$apiAddLowerCodeRes = $api->request('POST', "/students", [
    'student_code' => 'sv2026888',
    'full_name'    => 'Trần Văn In Hoa'
]);
$savedLowerCode = (string)$pdo->query("SELECT student_code FROM students WHERE full_name = 'Trần Văn In Hoa'")->fetchColumn();
it("Thêm sinh viên qua API với mã chữ thường ('sv2026888') được chuẩn hóa và lưu thành chữ in hoa ('SV2026888')", 
    $apiAddLowerCodeRes['status'] === 201 && $savedLowerCode === 'SV2026888'
);
$pdo->exec("DELETE FROM students WHERE student_code = 'SV2026888'");

// 19.7 Name boundary: 40-character name accepted and 101-character name rejected
$name40Char = "Trương Thị Ánh Tuyết Hoàng Thị Hồng Loan";
$add40CharRes = $api->request('POST', "/students", [
    'student_code' => 'SVTEST40',
    'full_name'    => $name40Char
]);
it("Tên có độ dài cận biên 40 ký tự được API chấp nhận tạo mới thành công (HTTP 201)", 
    $add40CharRes['status'] === 201
);
$pdo->exec("DELETE FROM students WHERE student_code = 'SVTEST40'");

$name101Char = str_repeat('A', 101);
$add101CharRes = $api->request('POST', "/students", [
    'student_code' => 'SVTEST101',
    'full_name'    => $name101Char
]);
it("Tên vượt giới hạn 101 ký tự bị API từ chối với HTTP 400 và error.fields.full_name", 
    $add101CharRes['status'] === 400 && !empty($add101CharRes['body']['error']['fields']['full_name'])
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
