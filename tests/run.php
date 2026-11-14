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

    public function getSessionId(): ?string {
        if (!file_exists($this->cookieFile)) {
            return null;
        }
        $content = file_get_contents($this->cookieFile);
        if (preg_match('/TUITION_SESSID\s+([a-zA-Z0-9,-]+)/', $content, $m)) {
            return $m[1];
        }
        return null;
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

        $respHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$respHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

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
            'status'  => $statusCode,
            'body'    => $json,
            'raw'     => $rawResponse,
            'headers' => $respHeaders
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
$billCount = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
it("Seed data nạp đủ số dòng chuẩn: 24 sinh viên, 22 hồ sơ học phí, 10 miễn giảm, 26 giao dịch sổ cái, 5 yêu cầu hoàn tiền, 7 hóa đơn", 
    $stCount === 24 && $frCount === 22 && $exCount === 10 && $leCount === 26 && $rfCount === 5 && $billCount === 7,
    ['students' => 24, 'fee_records' => 22, 'exemptions' => 10, 'ledger_entries' => 26, 'refund_requests' => 5, 'bills' => 7],
    ['students' => $stCount, 'fee_records' => $frCount, 'exemptions' => $exCount, 'ledger_entries' => $leCount, 'refund_requests' => $rfCount, 'bills' => $billCount]
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

// Kiểm tra quy tắc Maker-checker: Người tạo (#1 admin) không thể tự duyệt yêu cầu của chính mình
$selfCompRes = $api->request('POST', '/refunds/1/complete');
it("Maker-checker: Người tạo không thể tự duyệt hoàn tiền (HTTP 403 và đúng thông báo)", 
    $selfCompRes['status'] === 403 && str_contains($selfCompRes['body']['error']['message'] ?? '', 'Không thể tự duyệt yêu cầu hoàn tiền do chính bạn tạo')
);

// Hoàn tất yêu cầu hoàn tiền đang chờ duyệt (#1) bởi cán bộ khác (staff01) qua API POST /api/refunds/1/complete
$staffApi = new ApiTestClient();
$staffApi->login('staff01', 'Staff@123');
$compRefundRes = $staffApi->request('POST', '/refunds/1/complete');
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
$pdo->prepare("INSERT INTO refund_requests (fee_record_id, amount, reason, status, request_id, created_by, created_at) VALUES (11, 700000, 'Pending A', 'pending', :r, 2, NOW())")->execute(['r' => $concReqA]);
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

// 17.3 Maker-checker check: creator cannot complete refund
$creatorCompRes = $api->request('POST', "/refunds/{$createdRefundId}/complete");
it("Maker-checker: Người tạo không thể tự duyệt hoàn tất (HTTP 403)", 
    $creatorCompRes['status'] === 403
);

// After refund completion by checker (staff01), the record's status is Fully Paid and overpaid is false
$compRes = $staffApi->request('POST', "/refunds/{$createdRefundId}/complete");
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
    $diacriticsOnlySearchRes['status'] === 200 && count($diacriticsOnlySearchRes['body']['data'] ?? []) === 24
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
// 20. KIỂM THỬ NOTIFICATION BADGES & TRẠNG THÁI ĐIỀU HƯỚNG (PART 1)
// ----------------------------------------------------------------------------
suite_header("20. Kiểm thử Notification Badges & Trạng thái Điều hướng (Part 1)");

// 20.1 GET /api/nav/badges yêu cầu quyền quản trị viên
$unauthClient = new ApiTestClient();
$badgeUnauthRes = $unauthClient->request('GET', '/nav/badges');
it("API GET /api/nav/badges từ chối người dùng chưa đăng nhập với HTTP 401", 
    $badgeUnauthRes['status'] === 401
);

// 20.2 GET /api/nav/badges trả về đầy đủ 4 khóa badge chuẩn
$badgeRes = $api->request('GET', '/nav/badges');
$badgeData = $badgeRes['body']['data'] ?? [];
it("API GET /api/nav/badges trả về HTTP 200 và cấu trúc {refunds, new_transactions, pending_profiles, overpaid_profiles}", 
    $badgeRes['status'] === 200 
    && isset($badgeData['refunds']) 
    && isset($badgeData['new_transactions']) 
    && isset($badgeData['pending_profiles']) 
    && isset($badgeData['overpaid_profiles'])
);

// 20.3 Số lượng badge khớp chính xác 100% với truy vấn trực tiếp SQL
$sqlRefunds = (int)$pdo->query("SELECT COUNT(*) FROM refund_requests WHERE status = 'pending'")->fetchColumn();
$lastSeenSql = (int)$pdo->query("SELECT last_seen_entry_id FROM user_nav_state WHERE user_id = 1 AND nav_key = 'history'")->fetchColumn();
$sqlNewTx = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE id > {$lastSeenSql}")->fetchColumn();

$feeSubSql = get_fee_summary_subquery();
$sqlPendingProf = (int)$pdo->query("SELECT COUNT(*) FROM {$feeSubSql} v WHERE v.total_paid > v.net_due AND COALESCE(v.pending_refunds_count, 0) > 0")->fetchColumn();
$sqlOverpaidProf = (int)$pdo->query("SELECT COUNT(*) FROM {$feeSubSql} v WHERE v.total_paid > v.net_due AND (v.total_paid - v.net_due) > COALESCE(v.pending_refunds_sum, 0)")->fetchColumn();

it("Badge refunds khớp trực tiếp với SQL COUNT(refund_requests pending)", 
    (int)$badgeData['refunds'] === $sqlRefunds,
    $sqlRefunds, (int)$badgeData['refunds']
);
it("Badge new_transactions khớp trực tiếp với SQL COUNT(ledger_entries > last_seen)", 
    (int)$badgeData['new_transactions'] === $sqlNewTx,
    $sqlNewTx, (int)$badgeData['new_transactions']
);
it("Badge pending_profiles khớp trực tiếp với SQL COUNT hồ sơ nộp thừa có yêu cầu pending", 
    (int)$badgeData['pending_profiles'] === $sqlPendingProf,
    $sqlPendingProf, (int)$badgeData['pending_profiles']
);
it("Badge overpaid_profiles khớp trực tiếp với SQL COUNT hồ sơ nộp thừa chưa được bao phủ bởi pending", 
    (int)$badgeData['overpaid_profiles'] === $sqlOverpaidProf,
    $sqlOverpaidProf, (int)$badgeData['overpaid_profiles']
);

// 20.4 Baseline và Reset cho Giao dịch mới (New transactions)
// Baseline khi đăng nhập lần đầu: seed data không hiển thị là mới (new_transactions == 0 ban đầu sau khi seen hoặc đăng nhập)
$pdo->exec("DELETE FROM user_nav_state WHERE user_id = 2 AND nav_key = 'history'");
$testClientNav = new ApiTestClient();
$testClientNav->login('staff01', 'Staff@123');
$staffBadgeRes = $testClientNav->request('GET', '/nav/badges');
it("Baseline lần đầu đăng nhập: dữ liệu seed sẵn có không hiển thị là giao dịch mới (count = 0)", 
    (int)($staffBadgeRes['body']['data']['new_transactions'] ?? -1) === 0
);

// Thêm một giao dịch thanh toán mới vào sổ cái (trên hồ sơ 12 chưa thanh toán)
$sampleTxReqId = generate_uuid();
$addTxRes = $testClientNav->request('POST', '/fee-records/12/payments', [
    'amount'     => 100000,
    'method'     => 'Tiền mặt',
    'entry_date' => date('Y-m-d'),
    'request_id' => $sampleTxReqId
]);
it("Tạo giao dịch nộp tiền mới thành công để kiểm thử badge (HTTP 201)", 
    $addTxRes['status'] === 201
);

// Số giao dịch mới của admin (chưa xem lịch sử) tăng thêm 1
$adminBadgeAfterTx = $api->request('GET', '/nav/badges');
it("Badge new_transactions tăng lên khi có giao dịch mới phát sinh", 
    (int)($adminBadgeAfterTx['body']['data']['new_transactions'] ?? 0) >= 1
);

// 20.5 POST /api/nav/seen kiểm tra ràng buộc & cập nhật trạng thái
$seenNoCsrfClient = new ApiTestClient();
$seenNoCsrfClient->login('admin', 'Admin@123');
$seenNoCsrfClient->setCsrfToken('invalid_csrf_token');
$seenNoCsrfRes = $seenNoCsrfClient->request('POST', '/nav/seen', [
    'nav_key'    => 'history',
    'request_id' => generate_uuid()
]);
it("POST /api/nav/seen từ chối khi CSRF Token không hợp lệ (HTTP 403)", 
    $seenNoCsrfRes['status'] === 403
);

$seenBadReqIdRes = $api->request('POST', '/nav/seen', [
    'nav_key'    => 'history',
    'request_id' => 'not-a-valid-uuid'
]);
it("POST /api/nav/seen từ chối request_id không chuẩn UUID với HTTP 400", 
    $seenBadReqIdRes['status'] === 400
);

$seenBadKeyRes = $api->request('POST', '/nav/seen', [
    'nav_key'    => 'invalid_key',
    'request_id' => generate_uuid()
]);
it("POST /api/nav/seen từ chối nav_key không hợp lệ với HTTP 400", 
    $seenBadKeyRes['status'] === 400
);

$seenSuccessRes = $api->request('POST', '/nav/seen', [
    'nav_key'    => 'history',
    'request_id' => generate_uuid()
]);
it("POST /api/nav/seen ghi nhận đã xem màn hình Lịch sử thành công (HTTP 200)", 
    $seenSuccessRes['status'] === 200 
    && ($seenSuccessRes['body']['data']['nav_key'] ?? '') === 'history'
    && !empty($seenSuccessRes['body']['data']['last_seen_entry_id'])
);

// Sau khi POST /nav/seen, badge new_transactions của admin quay về 0
$adminBadgeAfterSeen = $api->request('GET', '/nav/badges');
it("Mở màn hình Lịch sử gọi POST /api/nav/seen xóa sạch badge new_transactions (count = 0)", 
    (int)($adminBadgeAfterSeen['body']['data']['new_transactions'] ?? -1) === 0
);

// 20.6 GET /api/nav/badges là truy vấn chỉ đọc (read-only), không làm thay đổi dữ liệu
$navStateBefore = $pdo->query("SELECT * FROM user_nav_state ORDER BY user_id, nav_key")->fetchAll();
$api->request('GET', '/nav/badges');
$api->request('GET', '/nav/badges');
$navStateAfter = $pdo->query("SELECT * FROM user_nav_state ORDER BY user_id, nav_key")->fetchAll();
it("GET /api/nav/badges tuyệt đối không ghi dữ liệu vào database (chỉ đọc)", 
    $navStateBefore === $navStateAfter
);

// ----------------------------------------------------------------------------
// 21. KIỂM THỬ TRẠNG THÁI CHỜ DUYỆT (PENDING) & QUY TẮC MAKER-CHECKER (PART 2)
// ----------------------------------------------------------------------------
suite_header("21. Kiểm thử Trạng thái Chờ duyệt (Pending) & Quy tắc Maker-Checker (Part 2)");

// 21.1 Chuỗi chuyển đổi trạng thái dẫn xuất Chờ duyệt trên từng ngưỡng biên (Boundary States)
// Tạo sinh viên mới và hồ sơ học phí để kiểm thử trọn vòng đời
$stBoundCode = 'SVBOUND01';
$cleanOldFr = $pdo->query("SELECT id FROM fee_records WHERE student_id IN (SELECT id FROM students WHERE student_code = '{$stBoundCode}')")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cleanOldFr as $oldFrId) {
    $pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM refund_requests WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM exemptions WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM fee_records WHERE id = {$oldFrId}");
}
$pdo->exec("DELETE FROM students WHERE student_code = '{$stBoundCode}'");

$addStBoundRes = $api->request('POST', '/students', [
    'student_code' => $stBoundCode,
    'full_name'    => 'Nguyễn Văn Kiểm Thử Biên'
]);
$stBoundId = (int)($addStBoundRes['body']['data']['id'] ?? 0);

// Tạo hồ sơ: 10 tín chỉ kỳ 1 (800.000) = 8.000.000
$addFrBoundRes = $api->request('POST', '/fee-records', [
    'student_id'         => $stBoundId,
    'semester_id'        => 1,
    'registered_credits' => 10
]);
$frBoundId = (int)($addFrBoundRes['body']['data']['id'] ?? ($addFrBoundRes['body']['data']['fee_record_id'] ?? 0));

// Nộp đủ 8.000.000
$payBoundRes = $api->request('POST', "/fee-records/{$frBoundId}/payments", [
    'amount'     => 8000000,
    'method'     => 'Chuyển khoản',
    'entry_date' => date('Y-m-d'),
    'request_id' => generate_uuid()
]);

// Thêm miễn giảm 1.000.000 sau thanh toán -> tạo trạng thái nộp thừa 1.000.000 (total_paid 8M, net_due 7M)
$exBoundRes = $api->request('PUT', "/fee-records/{$frBoundId}/exemption", [
    'type'   => 'fixed',
    'value'  => 1000000,
    'reason' => 'Miễn giảm sau nộp để kiểm thử nộp thừa'
]);

// Biên 1: Chưa có yêu cầu hoàn tiền nào -> Nộp thừa nhưng KHÔNG có trạng thái 'Chờ duyệt'
$frCheck1 = $api->request('GET', "/fee-records/{$frBoundId}");
$d1 = $frCheck1['body']['data'] ?? [];
it("Biên 1 (no refund): Hồ sơ nộp thừa chưa có yêu cầu hoàn tiền -> is_pending = false", 
    ($d1['is_overpaid'] ?? false) === true 
    && ($d1['is_pending'] ?? true) === false 
    && ($d1['workflow_status'] ?? null) === null
);

// Biên 2: Tạo yêu cầu hoàn tiền pending 1.000.000 do admin tạo -> Xuất hiện trạng thái 'Chờ duyệt'
$reqBoundUuid = generate_uuid();
$createRefBound = $api->request('POST', "/fee-records/{$frBoundId}/refunds", [
    'amount'     => 1000000,
    'reason'     => 'Yêu cầu hoàn tiền thử nghiệm',
    'request_id' => $reqBoundUuid
]);
$rfBoundId = (int)($createRefBound['body']['data']['id'] ?? 0);

$frCheck2 = $api->request('GET', "/fee-records/{$frBoundId}");
$d2 = $frCheck2['body']['data'] ?? [];
it("Biên 2 (pending refund): Có yêu cầu hoàn tiền pending -> is_pending = true, workflow_status = 'Pending'", 
    ($d2['is_overpaid'] ?? false) === true 
    && ($d2['is_pending'] ?? false) === true 
    && ($d2['workflow_status'] ?? '') === 'Pending'
    && ($d2['workflow_status_label'] ?? '') === 'Chờ duyệt'
    && (int)($d2['pending_amount'] ?? 0) === 1000000
);

// Biên 3: Hủy yêu cầu hoàn tiền pending -> Trạng thái 'Chờ duyệt' biến mất ngay lập tức
$cancelBoundRes = $api->request('POST', "/refunds/{$rfBoundId}/cancel", [
    'cancel_reason' => 'Hủy để kiểm tra biên trạng thái'
]);
it("Người tạo tự hủy yêu cầu hoàn tiền pending thành công (HTTP 200)", 
    $cancelBoundRes['status'] === 200
);

$frCheck3 = $api->request('GET', "/fee-records/{$frBoundId}");
$d3 = $frCheck3['body']['data'] ?? [];
it("Biên 3 (cancelled refund): Sau khi hủy yêu cầu hoàn tiền -> is_pending = false", 
    ($d3['is_overpaid'] ?? false) === true 
    && ($d3['is_pending'] ?? true) === false 
    && ($d3['workflow_status'] ?? null) === null
);

// Biên 4: Tạo lại yêu cầu hoàn tiền và hoàn tất bởi checker (staff01) -> Chờ duyệt biến mất, hồ sơ hết nộp thừa
$reqBound2Uuid = generate_uuid();
$createRefBound2 = $api->request('POST', "/fee-records/{$frBoundId}/refunds", [
    'amount'     => 1000000,
    'reason'     => 'Yêu cầu hoàn tiền đợt 2',
    'request_id' => $reqBound2Uuid
]);
$rfBound2Id = (int)($createRefBound2['body']['data']['id'] ?? 0);

// Cán bộ staff01 duyệt hoàn tất
$compBound2Res = $staffApi->request('POST', "/refunds/{$rfBound2Id}/complete");
it("Cán bộ checker duyệt hoàn tất yêu cầu hoàn tiền thành công (HTTP 200)", 
    $compBound2Res['status'] === 200
);

$frCheck4 = $api->request('GET', "/fee-records/{$frBoundId}");
$d4 = $frCheck4['body']['data'] ?? [];
it("Biên 4 (completed refund): Sau khi duyệt hoàn tất -> is_pending = false và không còn nộp thừa", 
    ($d4['is_overpaid'] ?? true) === false 
    && ($d4['is_pending'] ?? true) === false 
    && ($d4['status'] ?? '') === 'Fully Paid'
    && (int)($d4['total_paid'] ?? 0) === 7000000
);

// Dọn dẹp dữ liệu sinh viên kiểm thử biên (theo thứ tự bảng con trước)
$pdo->prepare("DELETE FROM ledger_entries WHERE fee_record_id = :id")->execute(['id' => $frBoundId]);
$pdo->prepare("DELETE FROM refund_requests WHERE fee_record_id = :id")->execute(['id' => $frBoundId]);
$pdo->prepare("DELETE FROM exemptions WHERE fee_record_id = :id")->execute(['id' => $frBoundId]);
$pdo->prepare("DELETE FROM fee_records WHERE id = :id")->execute(['id' => $frBoundId]);
$pdo->prepare("DELETE FROM students WHERE id = :id")->execute(['id' => $stBoundId]);

// 21.2 Kiểm tra sâu quy tắc Maker-checker & Ghi log lỗi ứng dụng
// Tạo sinh viên & hồ sơ kiểm thử riêng để độc lập tuyệt đối
$stMkCode = 'SVMKCHECK';
$cleanOldFr = $pdo->query("SELECT id FROM fee_records WHERE student_id IN (SELECT id FROM students WHERE student_code = '{$stMkCode}')")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cleanOldFr as $oldFrId) {
    $pdo->exec("DELETE FROM ledger_entries WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM refund_requests WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM exemptions WHERE fee_record_id = {$oldFrId}");
    $pdo->exec("DELETE FROM fee_records WHERE id = {$oldFrId}");
}
$pdo->exec("DELETE FROM students WHERE student_code = '{$stMkCode}'");

$addStMkRes = $api->request('POST', '/students', [
    'student_code' => $stMkCode,
    'full_name'    => 'Trần Kiểm Thử Maker'
]);
$stMkId = (int)($addStMkRes['body']['data']['id'] ?? 0);

// Tạo hồ sơ: 10 tín chỉ kỳ 1 (800.000) = 8.000.000
$addFrMkRes = $api->request('POST', '/fee-records', [
    'student_id'         => $stMkId,
    'semester_id'        => 1,
    'registered_credits' => 10
]);
$frMkId = (int)($addFrMkRes['body']['data']['id'] ?? ($addFrMkRes['body']['data']['fee_record_id'] ?? 0));

// Nộp 8.000.000
$api->request('POST', "/fee-records/{$frMkId}/payments", [
    'amount'     => 8000000,
    'method'     => 'Tiền mặt',
    'entry_date' => date('Y-m-d'),
    'request_id' => generate_uuid()
]);

// Miễn giảm 1.000.000 -> tạo dư thừa 1.000.000 (total_paid 8M, net_due 7M)
$api->request('PUT', "/fee-records/{$frMkId}/exemption", [
    'type'   => 'fixed',
    'value'  => 1000000,
    'reason' => 'Tạo nộp thừa để kiểm thử Maker-Checker'
]);

$mkRefundUuid = generate_uuid();
$mkCreateRes = $api->request('POST', "/fee-records/{$frMkId}/refunds", [
    'amount'     => 700000,
    'reason'     => 'Kiểm thử Maker-Checker Server Enforced',
    'request_id' => $mkRefundUuid
]);
$mkRefundId = (int)($mkCreateRes['body']['data']['id'] ?? 0);

// Admin tự duyệt yêu cầu do chính mình tạo -> phải bị 403
$mkSelfApproveRes = $api->request('POST', "/refunds/{$mkRefundId}/complete");
it("Maker-checker: Server từ chối người tạo tự duyệt yêu cầu hoàn tiền với HTTP 403", 
    $mkSelfApproveRes['status'] === 403 
    && str_contains($mkSelfApproveRes['body']['error']['message'] ?? '', 'Không thể tự duyệt yêu cầu hoàn tiền do chính bạn tạo')
);

// Kiểm tra ghi log lỗi ứng dụng (logs/app_error.log)
$logFilePath = dirname(__DIR__) . '/logs/app_error.log';
$logContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
it("Maker-checker: Hệ thống ghi nhận lần duyệt vi phạm quy tắc vào app_error.log", 
    str_contains($logContent, 'Maker-checker violation') && str_contains($logContent, (string)$mkRefundId)
);

// Admin tự hủy yêu cầu do chính mình tạo -> hoàn toàn được phép
$mkCancelSelfRes = $api->request('POST', "/refunds/{$mkRefundId}/cancel", [
    'cancel_reason' => 'Admin tự hủy yêu cầu của chính mình'
]);
it("Maker-checker: Người tạo được phép tự hủy yêu cầu hoàn tiền đang chờ duyệt (HTTP 200)", 
    $mkCancelSelfRes['status'] === 200
);

// 21.3 Hàng đợi duyệt hoàn tiền (Approval Queue)
$appQueueRes = $api->request('GET', '/refunds?status=pending');
$queueList = $appQueueRes['body']['data'] ?? [];
$allPendingInQueue = true;
foreach ($queueList as $qItem) {
    if (($qItem['status'] ?? '') !== 'pending') {
        $allPendingInQueue = false;
        break;
    }
}
it("API Hàng đợi duyệt hoàn tiền (GET /api/refunds?status=pending) chỉ trả về yêu cầu có trạng thái 'pending'", 
    $appQueueRes['status'] === 200 && is_array($queueList) && $allPendingInQueue
);

// Kiểm tra các trường thông tin cần thiết hiển thị trên màn hình Approval Queue
$firstQueueItem = $queueList[0] ?? null;
it("Hàng đợi duyệt chứa đầy đủ thông tin: sinh viên, học kỳ, số tiền, lý do, người yêu cầu, ngày tạo", 
    $firstQueueItem === null || (
        isset($firstQueueItem['student_code'])
        && isset($firstQueueItem['student_name'])
        && isset($firstQueueItem['semester_label'])
        && isset($firstQueueItem['amount'])
        && isset($firstQueueItem['reason'])
        && isset($firstQueueItem['created_by_name'])
        && isset($firstQueueItem['created_at'])
    )
);

// 21.4 Bộ lọc 'Chờ duyệt' trong danh sách hồ sơ học phí (Tuition Records Filter)
// Tạo 1 yêu cầu pending trên hồ sơ $frMkId để test bộ lọc
$filterPendingUuid = generate_uuid();
$api->request('POST', "/fee-records/{$frMkId}/refunds", [
    'amount'     => 500000,
    'reason'     => 'Tạo pending để test bộ lọc Chờ duyệt',
    'request_id' => $filterPendingUuid
]);

$filterPendingRes = $api->request('GET', '/fee-records?status=PENDING');
$filterPendingRows = $filterPendingRes['body']['data'] ?? [];
$allFilterPendingOk = count($filterPendingRows) > 0;
foreach ($filterPendingRows as $pRow) {
    if (!($pRow['is_pending'] ?? false)) {
        $allFilterPendingOk = false;
        break;
    }
}
it("Bộ lọc status=PENDING trên danh sách hồ sơ chỉ trả về đúng các hồ sơ đang ở trạng thái 'Chờ duyệt'", 
    $filterPendingRes['status'] === 200 && $allFilterPendingOk
);

$filterVnPendingRes = $api->request('GET', '/fee-records?status=' . urlencode('Chờ duyệt'));
$filterVnRows = $filterVnPendingRes['body']['data'] ?? [];
it("Bộ lọc status='Chờ duyệt' (tiếng Việt) hoạt động đồng nhất với status=PENDING", 
    $filterVnPendingRes['status'] === 200 && count($filterVnRows) === count($filterPendingRows)
);

// Dọn dẹp dữ liệu sinh viên kiểm thử maker
$pdo->prepare("DELETE FROM ledger_entries WHERE fee_record_id = :id")->execute(['id' => $frMkId]);
$pdo->prepare("DELETE FROM refund_requests WHERE fee_record_id = :id")->execute(['id' => $frMkId]);
$pdo->prepare("DELETE FROM exemptions WHERE fee_record_id = :id")->execute(['id' => $frMkId]);
$pdo->prepare("DELETE FROM fee_records WHERE id = :id")->execute(['id' => $frMkId]);
$pdo->prepare("DELETE FROM students WHERE id = :id")->execute(['id' => $stMkId]);

// ----------------------------------------------------------------------------
// 22. KIỂM THỬ TÍNH NĂNG TÀI KHOẢN SINH VIÊN (STUDENT PORTAL & SECURITY)
// ----------------------------------------------------------------------------
suite_header("22. Phân quyền, Tạo tài khoản & Bảo mật Sinh viên (Parts 1-4)");

// 22.1 Quản trị viên cấp tài khoản sinh viên & Đặt lại mật khẩu
$sv011Current = $pdo->query("SELECT id, student_code FROM students WHERE student_code = 'SV011'")->fetch(PDO::FETCH_ASSOC);
$sv011Id = (int)$sv011Current['id'];

$createAccRes = $api->request('POST', "/students/{$sv011Id}/account");
it("Admin cấp tài khoản cho SV011 thành công với mật khẩu tạm thời 12 ký tự (HTTP 201)", 
    $createAccRes['status'] === 201 
    && !empty($createAccRes['body']['data']['temporary_password'])
    && strlen($createAccRes['body']['data']['temporary_password']) === 12
    && $createAccRes['body']['data']['username'] === 'sv011'
    && (int)$createAccRes['body']['data']['must_change_password'] === 1
);

$dupAccRes = $api->request('POST', "/students/{$sv011Id}/account");
it("Tạo trùng tài khoản cho cùng sinh viên bị từ chối với HTTP 409 và thông báo quy định", 
    $dupAccRes['status'] === 409 
    && str_contains($dupAccRes['body']['error']['message'] ?? '', 'Sinh viên này đã có tài khoản')
);

$resetAccRes = $api->request('POST', "/students/{$sv011Id}/account/reset");
it("Admin đặt lại mật khẩu cho sinh viên thành công (HTTP 200)", 
    $resetAccRes['status'] === 200 
    && !empty($resetAccRes['body']['data']['temporary_password'])
    && strlen($resetAccRes['body']['data']['temporary_password']) === 12
);

// Chuẩn bị tài khoản sv011 với mật khẩu chuẩn Student@011 để kiểm thử đăng ký tín chỉ
$hash011 = password_hash('Student@011', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE users SET password_hash = :h, must_change_password = 0 WHERE username = 'sv011'")->execute(['h' => $hash011]);

// 22.2 Kiểm thử Đăng nhập & Đổi mật khẩu bắt buộc (sv006 với must_change_password = 1)
$client006 = new ApiTestClient();
$login006Res = $client006->login('sv006', 'Student@006');
it("Sinh viên SV006 đăng nhập thành công với vai trò student (HTTP 200)", 
    $login006Res['status'] === 200 
    && $login006Res['body']['data']['role'] === 'student'
    && (int)$login006Res['body']['data']['must_change_password'] === 1
);

// GET /api/me được phép khi must_change_password = 1
$me006Res = $client006->request('GET', '/me');
it("GET /api/me cho phép sinh viên đang có must_change_password = 1 truy cập (HTTP 200)", 
    $me006Res['status'] === 200 && (int)$me006Res['body']['data']['must_change_password'] === 1
);

// Các endpoint sinh viên khác bị chặn 403 PASSWORD_CHANGE_REQUIRED
$blockSemRes = $client006->request('GET', '/student/semesters');
$blockDebtsRes = $client006->request('GET', '/student/debts');
$blockHistRes = $client006->request('GET', '/student/history');
it("Tất cả endpoint sinh viên khác trả về 403 PASSWORD_CHANGE_REQUIRED khi chưa đổi mật khẩu", 
    $blockSemRes['status'] === 403 
    && ($blockSemRes['body']['error']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED'
    && $blockDebtsRes['status'] === 403 
    && ($blockDebtsRes['body']['error']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED'
    && $blockHistRes['status'] === 403 
    && ($blockHistRes['body']['error']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED'
);

// Đổi mật khẩu: từ chối tái sử dụng mật khẩu tạm thời
$reuseRes = $client006->request('POST', '/student/password', [
    'current_password'          => 'Student@006',
    'new_password'              => 'Student@006',
    'new_password_confirmation' => 'Student@006'
]);
it("Đổi mật khẩu từ chối tái sử dụng mật khẩu hiện tại / tạm thời với HTTP 400", 
    $reuseRes['status'] === 400 && !empty($reuseRes['body']['error']['fields']['new_password'])
);

// Đổi mật khẩu: từ chối mật khẩu không đủ độ dài hoặc thiếu số/chữ
$weakPwRes = $client006->request('POST', '/student/password', [
    'current_password'          => 'Student@006',
    'new_password'              => 'short',
    'new_password_confirmation' => 'short'
]);
it("Đổi mật khẩu từ chối mật khẩu yếu (< 8 ký tự hoặc thiếu chữ/số) với HTTP 400", 
    $weakPwRes['status'] === 400 && !empty($weakPwRes['body']['error']['fields']['new_password'])
);

// Đổi mật khẩu: từ chối xác nhận không khớp
$mismatchPwRes = $client006->request('POST', '/student/password', [
    'current_password'          => 'Student@006',
    'new_password'              => 'NewPassword2026',
    'new_password_confirmation' => 'Different2026'
]);
it("Đổi mật khẩu từ chối khi xác nhận mật khẩu không khớp với HTTP 400", 
    $mismatchPwRes['status'] === 400 && !empty($mismatchPwRes['body']['error']['fields']['new_password_confirmation'])
);

// Đổi mật khẩu thành công
$changePwRes = $client006->request('POST', '/student/password', [
    'current_password'          => 'Student@006',
    'new_password'              => 'NewPassword2026',
    'new_password_confirmation' => 'NewPassword2026'
]);
$mustChangeAfter = (int)$pdo->query("SELECT must_change_password FROM users WHERE username = 'sv006'")->fetchColumn();
it("Đổi mật khẩu thành công (HTTP 200) và cờ must_change_password được xóa về 0", 
    $changePwRes['status'] === 200 && $mustChangeAfter === 0
);

// Sau khi đổi mật khẩu, truy cập endpoint sinh viên thành công
$unblockDebtsRes = $client006->request('GET', '/student/debts');
it("Sau khi đổi mật khẩu, sinh viên truy cập bình thường các chức năng (HTTP 200)", 
    $unblockDebtsRes['status'] === 200
);

// 22.3 Đăng ký tín chỉ (Registration: SV011 chưa có hồ sơ)
$client011 = new ApiTestClient();
$client011->login('sv011', 'Student@011');

// Xem danh sách học kỳ mở
$sem011Res = $client011->request('GET', '/student/semesters');
$openSems = $sem011Res['body']['data'] ?? [];
$hasHk1_2627 = false;
$hasClosedSem = false;
foreach ($openSems as $os) {
    if ($os['term_no'] === 1 && $os['academic_year_start'] === 2026) $hasHk1_2627 = true;
    if ($os['term_no'] === 2 && $os['academic_year_start'] === 2025) $hasClosedSem = true;
}
it("GET /api/student/semesters trả về đúng học kỳ mở HK1 2026-2027 và không chứa học kỳ đóng", 
    $sem011Res['status'] === 200 && $hasHk1_2627 && !$hasClosedSem
);

// Từ chối số tín chỉ < min (0) và > max (31)
$belowMinRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 0,
    'request_id'  => 'c1111111-0000-0000-0000-000000000001'
]);
it("Đăng ký tín chỉ từ chối credits < min (0) với HTTP 400 và trường error.fields.credits", 
    $belowMinRes['status'] === 400 && !empty($belowMinRes['body']['error']['fields']['credits'])
);

$aboveMaxRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 31,
    'request_id'  => 'c1111111-0000-0000-0000-000000000002'
]);
it("Đăng ký tín chỉ từ chối credits > max (31) với HTTP 400 và trường error.fields.credits", 
    $aboveMaxRes['status'] === 400 && !empty($aboveMaxRes['body']['error']['fields']['credits'])
);

// Từ chối học kỳ đóng (HK2 2025-2026, semester_id = 2)
$closedSemRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 2,
    'credits'     => 15,
    'request_id'  => 'c1111111-0000-0000-0000-000000000003'
]);
it("Đăng ký học kỳ đã đóng bị từ chối với HTTP 422 và thông báo 'Học kỳ này đã đóng đăng ký.'", 
    $closedSemRes['status'] === 422 
    && str_contains($closedSemRes['body']['error']['message'] ?? '', 'Học kỳ này đã đóng đăng ký')
);

// Đăng ký hợp lệ 15 tín chỉ vào HK1 2026-2027 (semester_id = 3)
$regReqId = 'c1111111-0000-0000-0000-000000000004';
$validRegRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 15,
    'request_id'  => $regReqId
]);
it("Đăng ký tín chỉ hợp lệ tạo mới hồ sơ học phí với rate_snapshot và gross tính đúng (HTTP 201)", 
    $validRegRes['status'] === 201 
    && (int)$validRegRes['body']['data']['registered_credits'] === 15
    && (int)$validRegRes['body']['data']['rate_snapshot'] === 850000
    && (int)$validRegRes['body']['data']['gross_tuition'] === 12750000
);
$sv011NewFrId = (int)($validRegRes['body']['data']['id'] ?? $validRegRes['body']['data']['fee_record_id'] ?? 0);

// Tính bất biến trùng lặp (Idempotent by request_id)
$dupReqRegRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 15,
    'request_id'  => $regReqId
]);
it("Gọi lại đăng ký với cùng request_id trả về HTTP 200 bản ghi cũ không tạo mới", 
    $dupReqRegRes['status'] === 200 
    && (int)($dupReqRegRes['body']['data']['id'] ?? $dupReqRegRes['body']['data']['fee_record_id'] ?? 0) === $sv011NewFrId
);

// Đăng ký trùng học kỳ với request_id khác trả về HTTP 409 DUPLICATE_FEE_RECORD
$diffReqRegRes = $client011->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 15,
    'request_id'  => 'c1111111-0000-0000-0000-000000000005'
]);
it("Đăng ký trùng học kỳ trả về HTTP 409 DUPLICATE_FEE_RECORD và existing_id", 
    $diffReqRegRes['status'] === 409 
    && ($diffReqRegRes['body']['error']['code'] ?? '') === 'DUPLICATE_FEE_RECORD'
    && (int)($diffReqRegRes['body']['data']['existing_id'] ?? 0) === $sv011NewFrId
);

// Điều chỉnh số tín chỉ trước khi thanh toán (total_paid = 0): tính lại gross_tuition
$changeCredRes = $client011->request('PUT', '/student/registrations/3', [
    'credits'    => 20,
    'request_id' => 'c1111111-0000-0000-0000-000000000006'
]);
it("Điều chỉnh số tín chỉ trước khi thanh toán thành công và tính lại gross = 20 x 850.000 = 17.000.000 (HTTP 200)", 
    $changeCredRes['status'] === 200 
    && (int)$changeCredRes['body']['data']['registered_credits'] === 20
    && (int)$changeCredRes['body']['data']['gross_tuition'] === 17000000
);

// 22.4 Công nợ học phí (Tuition Debt & Isolation)
$client001 = new ApiTestClient();
$client001->login('sv001', 'Student@001');

$debts001Res = $client001->request('GET', '/student/debts');
$debts001Data = $debts001Res['body']['data'] ?? [];
$outRecords001 = $debts001Data['outstanding_records'] ?? [];
$allRecords001 = $debts001Data['all_records'] ?? [];
$allOutGtZero = true;
foreach ($outRecords001 as $r) {
    if ($r['outstanding'] <= 0) $allOutGtZero = false;
}
it("GET /api/student/debts: outstanding_records chỉ chứa hồ sơ có nợ > 0 và tính tổng đúng", 
    $debts001Res['status'] === 200 
    && count($allRecords001) >= 2 
    && $allOutGtZero
    && (int)$debts001Data['total_outstanding'] === array_sum(array_column($allRecords001, 'outstanding'))
);

// Kiểm thử sinh viên chưa có hồ sơ thấy empty state
$clientEmpty = new ApiTestClient();
// SV004 không có hồ sơ học phí
$hash004 = password_hash('Student@004', PASSWORD_DEFAULT);
$sv004Row = $pdo->query("SELECT id, full_name FROM students WHERE student_code = 'SV004'")->fetch();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, student_id, must_change_password) VALUES ('sv004', :h, :name, 'student', :stId, 0) ON DUPLICATE KEY UPDATE password_hash = :h2")->execute(['h' => $hash004, 'name' => $sv004Row['full_name'], 'stId' => $sv004Row['id'], 'h2' => $hash004]);
$clientEmpty->login('sv004', 'Student@004');
$emptyDebtRes = $clientEmpty->request('GET', '/student/debts');
it("Sinh viên không có hồ sơ học phí thấy trạng thái trống (total_outstanding = 0, danh sách rỗng)", 
    $emptyDebtRes['status'] === 200 
    && (int)($emptyDebtRes['body']['data']['total_outstanding'] ?? -1) === 0
    && empty($emptyDebtRes['body']['data']['outstanding_records'])
    && empty($emptyDebtRes['body']['data']['all_records'])
);

// 22.5 Thanh toán học phí (Payment: SV003 có hồ sơ #3 nợ 8.200.000)
$client003 = new ApiTestClient();
$client003->login('sv003', 'Student@003');

// Hồ sơ 3 nợ 8.200.000. Nộp vượt 1 VND (8.200.001) bị từ chối và không ghi dòng nào
$leCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
$overpayPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 8200001,
    'method'     => 'Thẻ',
    'request_id' => 'c3333333-0000-0000-0000-000000000001'
]);
$leCountAfterOverpay = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
it("Thanh toán vượt công nợ (balance + 1) bị từ chối với HTTP 422 và không ghi dòng sổ cái", 
    $overpayPayRes['status'] === 422 
    && str_contains($overpayPayRes['body']['error']['message'] ?? '', 'Số tiền vượt quá số còn nợ')
    && $leCountAfterOverpay === $leCountBefore
);

// Từ chối phương thức 'Tiền mặt' cho sinh viên
$cashPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 5000000,
    'method'     => 'Tiền mặt',
    'request_id' => 'c3333333-0000-0000-0000-000000000002'
]);
it("Sinh viên chọn phương thức 'Tiền mặt' bị từ chối với HTTP 400", 
    $cashPayRes['status'] === 400 && !empty($cashPayRes['body']['error']['fields']['method'])
);

// Hồ sơ nộp thừa chặn thanh toán (SV001 hồ sơ #5 nộp thừa 1.000.000)
// Section 5 completed refund #1, đặt lại pending để hồ sơ #5 khôi phục trạng thái nộp thừa và có pending refund
$pdo->prepare("UPDATE refund_requests SET status = 'pending', completed_by = NULL, completed_at = NULL WHERE id = 1")->execute();
$overpaidBlockRes = $client001->request('POST', '/student/fee-records/5/payments', [
    'amount'     => 100000,
    'method'     => 'Chuyển khoản',
    'request_id' => 'c1111111-0000-0000-0000-000000000007'
]);
it("Hồ sơ đang nộp thừa chặn thanh toán với HTTP 422 và đúng thông báo quy định", 
    $overpaidBlockRes['status'] === 422 
    && str_contains($overpaidBlockRes['body']['error']['message'] ?? '', 'Hồ sơ đang có số tiền thừa')
);

// Mô phỏng cổng thanh toán thất bại: không ghi dòng nào và trả về 502
$failGwPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 1000000,
    'method'     => 'Thẻ',
    'request_id' => 'c3333333-0000-0000-0000-000000000003'
], ['X-Simulate-Gateway-Fail' => '1']);
$leCountAfterGwFail = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
it("Cổng thanh toán thất bại trả về HTTP 502, thông báo quy định và không ghi dữ liệu sổ cái", 
    $failGwPayRes['status'] === 502 
    && str_contains($failGwPayRes['body']['error']['message'] ?? '', 'Thanh toán chưa được xử lý')
    && $leCountAfterGwFail === $leCountBefore
);

// Thanh toán thiếu 1 đồng: (8.200.000 - 1 = 8.199.999) -> Trạng thái 'Thanh toán một phần'
$partialPayReqId = 'c3333333-0000-0000-0000-000000000004';
$partialPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 8199999,
    'method'     => 'Thẻ',
    'request_id' => $partialPayReqId
]);
$fr3SummaryPartial = fetch_fee_record_summary($pdo, 3);
it("Thanh toán thiếu 1 đồng thành công (HTTP 201) và trạng thái hồ sơ là 'Partially Paid' (Thanh toán một phần)", 
    $partialPayRes['status'] === 201 
    && !empty($partialPayRes['body']['data']['receipt_no'])
    && !empty($partialPayRes['body']['data']['gateway_ref'])
    && $fr3SummaryPartial['status'] === 'Partially Paid'
);

// Trùng lặp request_id: không gọi lại gateway, trả về biên lai cũ
$dupPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 8199999,
    'method'     => 'Thẻ',
    'request_id' => $partialPayReqId
]);
it("Gửi trùng request_id thanh toán trả về HTTP 200 và cùng số biên lai mà không trừ thêm tiền", 
    $dupPayRes['status'] === 200 
    && $dupPayRes['body']['data']['receipt_no'] === $partialPayRes['body']['data']['receipt_no']
);

// Thanh toán đúng số tiền còn nợ (1 VND) -> Trạng thái 'Fully Paid'
$exactPayRes = $client003->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 1,
    'method'     => 'Chuyển khoản',
    'request_id' => 'c3333333-0000-0000-0000-000000000005'
]);
$fr3SummaryFully = fetch_fee_record_summary($pdo, 3);
it("Thanh toán đúng số dư nợ còn lại thành công và hồ sơ chuyển thành 'Fully Paid' (Đã thanh toán đủ)", 
    $exactPayRes['status'] === 201 
    && $fr3SummaryFully['status'] === 'Fully Paid' 
    && $fr3SummaryFully['outstanding'] === 0
);

// Kiểm tra điều chỉnh số tín chỉ sau khi đã có thanh toán (SV011 sau khi nộp tiền)
$client011->request('POST', "/student/fee-records/{$sv011NewFrId}/payments", [
    'amount'     => 1000000,
    'method'     => 'Thẻ',
    'request_id' => 'c1111111-0000-0000-0000-000000000008'
]);
$putAfterPayRes = $client011->request('PUT', '/student/registrations/3', [
    'credits'    => 18,
    'request_id' => 'c1111111-0000-0000-0000-000000000009'
]);
it("Điều chỉnh số tín chỉ sau khi đã có thanh toán bị từ chối với HTTP 422 và hướng dẫn liên hệ PĐT", 
    $putAfterPayRes['status'] === 422 
    && str_contains($putAfterPayRes['body']['error']['message'] ?? '', 'Đã có thanh toán, vui lòng liên hệ phòng đào tạo')
);

// 22.6 Lịch sử thanh toán & Số dư lũy kế (History)
$client002 = new ApiTestClient();
$client002->login('sv002', 'Student@002');
$histRes = $client002->request('GET', '/student/history');
$histEntries = $histRes['body']['data']['entries'] ?? [];
it("GET /api/student/history trả về danh sách giao dịch kèm số dư lũy kế và nhãn tiếng Việt chuẩn", 
    $histRes['status'] === 200 
    && count($histEntries) >= 2 
    && isset($histEntries[0]['running_balance'])
    && isset($histEntries[0]['type_label'])
);

// Lịch sử từ chối to < from
$badRangeRes = $client002->request('GET', '/student/history?from=2026-10-01&to=2026-09-01');
it("Lịch sử từ chối khoảng ngày to < from với HTTP 400", 
    $badRangeRes['status'] === 400 && !empty($badRangeRes['body']['error']['fields']['to'])
);

// Lịch sử từ chối khoảng thời gian tra cứu vượt quá 5 năm
$over5YearsRes = $client002->request('GET', '/student/history?from=2020-01-01&to=2026-01-02');
it("Lịch sử từ chối khoảng thời gian tra cứu vượt quá 5 năm với HTTP 400", 
    $over5YearsRes['status'] === 400 
    && !empty($over5YearsRes['body']['error']['fields']['to'])
    && str_contains($over5YearsRes['body']['error']['message'] ?? '', '5 năm')
);

// Lịch sử hiển thị yêu cầu hoàn tiền đang chờ duyệt dưới dạng pending, không phải completed
$hist001Res = $client001->request('GET', '/student/history');
$pending001 = $hist001Res['body']['data']['pending_refunds'] ?? [];
$entries001 = $hist001Res['body']['data']['entries'] ?? [];
$pendingInEntries = false;
foreach ($entries001 as $e) {
    if (($e['entry_type'] ?? '') === 'refund' && ($e['status'] ?? '') === 'pending') {
        $pendingInEntries = true;
    }
}
it("Yêu cầu hoàn tiền đang chờ duyệt xuất hiện trong pending_refunds với nhãn quy định và không bị lẫn vào entries completed", 
    $hist001Res['status'] === 200 
    && count($pending001) >= 1 
    && !$pendingInEntries
    && ($pending001[0]['status_label'] ?? '') === 'Yêu cầu hoàn tiền đang chờ duyệt'
);

// In biên lai: biên lai của chính sinh viên thành công, biên lai của sinh viên khác trả về 404
$sv003RcptNo = $exactPayRes['body']['data']['receipt_no'];
$ownRcptRes = $client003->request('GET', "/receipts/{$sv003RcptNo}");
it("Sinh viên tra cứu biên lai thanh toán của chính mình thành công (HTTP 200)", 
    $ownRcptRes['status'] === 200 && ($ownRcptRes['body']['data']['receipt_no'] ?? '') === $sv003RcptNo
);

$otherRcptRes = $client001->request('GET', "/receipts/{$sv003RcptNo}");
it("Sinh viên A tra cứu biên lai thanh toán của Sinh viên B trả về HTTP 404 cô lập hoàn toàn", 
    $otherRcptRes['status'] === 404
);

// 22.7 Kiểm thử Cô lập dữ liệu & Ràng buộc bảo mật toàn diện (Security Checks)

// 1. Sinh viên A truy cập hoặc nộp tiền vào hồ sơ của Sinh viên B trả về 404
$otherPayRes = $client001->request('POST', '/student/fee-records/3/payments', [
    'amount'     => 100000,
    'method'     => 'Thẻ',
    'request_id' => 'c1111111-9999-9999-9999-999999999999'
]);
it("Sinh viên A gửi yêu cầu nộp tiền vào hồ sơ của Sinh viên B trả về HTTP 404 (không lộ sự tồn tại)", 
    $otherPayRes['status'] === 404
);

$otherEditReg = $client006->request('PUT', '/student/registrations/3', [
    'credits'    => 20,
    'request_id' => 'c1111111-9999-9999-9999-999999999998'
]);
it("Sinh viên A gửi yêu cầu điều chỉnh đăng ký chưa tồn tại (hoặc của sinh viên khác) trả về HTTP 404", 
    $otherEditReg['status'] === 404
);

// 2. Yêu cầu sinh viên chứa trường student_id bị từ chối 400 (Whitelist validation)
$unknownFieldRes = $client002->request('POST', '/student/registrations', [
    'semester_id' => 3,
    'credits'     => 15,
    'student_id'  => 999,
    'request_id'  => 'c2222222-8888-8888-8888-888888888888'
]);
it("Yêu cầu sinh viên chứa trường student_id trong body bị từ chối với HTTP 400 (trường không hợp lệ)", 
    $unknownFieldRes['status'] === 400 && !empty($unknownFieldRes['body']['error']['fields']['student_id'])
);

// 3. Người dùng chưa đăng nhập gọi endpoint sinh viên nhận HTTP 401
$unauthClient = new ApiTestClient();
$unauthSemRes = $unauthClient->request('GET', '/student/semesters');
$unauthDebtRes = $unauthClient->request('GET', '/student/debts');
$unauthHistRes = $unauthClient->request('GET', '/student/history');
it("Người dùng chưa đăng nhập gọi mọi endpoint /api/student/* đều nhận HTTP 401", 
    $unauthSemRes['status'] === 401 && $unauthDebtRes['status'] === 401 && $unauthHistRes['status'] === 401
);

// 4. Quản trị viên (admin session) gọi endpoint sinh viên nhận HTTP 403
$adminCallSem = $api->request('GET', '/student/semesters');
$adminCallDebt = $api->request('GET', '/student/debts');
$adminCallHist = $api->request('GET', '/student/history');
$adminCallPw = $api->request('POST', '/student/password', ['current_password' => 'x', 'new_password' => 'x']);
$adminCallReg = $api->request('POST', '/student/registrations', ['semester_id' => 3, 'credits' => 15, 'request_id' => 'adm-1']);
$adminCallRcpt = $api->request('GET', '/student/receipts/PT-20260905-0001');
it("Quản trị viên (admin session) gọi endpoint /api/student/* đều bị từ chối với HTTP 403", 
    $adminCallSem['status'] === 403 
    && $adminCallDebt['status'] === 403 
    && $adminCallHist['status'] === 403
    && $adminCallPw['status'] === 403
    && $adminCallReg['status'] === 403
    && $adminCallRcpt['status'] === 403
);

// 5. Sinh viên gọi endpoint quản trị nhận HTTP 403
$studentCallAdmin = $client002->request('GET', '/students');
$studentCallRefunds = $client002->request('GET', '/refunds');
$studentCallReports = $client002->request('GET', '/reports/summary');
it("Sinh viên gọi endpoint quản trị (/students, /refunds, /reports) đều bị từ chối với HTTP 403", 
    $studentCallAdmin['status'] === 403 && $studentCallRefunds['status'] === 403 && $studentCallReports['status'] === 403
);

// 6. Yêu cầu POST/PUT của sinh viên không có CSRF Token hợp lệ bị từ chối HTTP 403
$badCsrfClient = new ApiTestClient();
$badCsrfClient->login('sv002', 'Student@002');
$badCsrfClient->setCsrfToken('tampered_token_xyz');
$noCsrfRes = $badCsrfClient->request('POST', '/student/fee-records/2/payments', [
    'amount'     => 1000,
    'method'     => 'Thẻ',
    'request_id' => 'c2222222-7777-7777-7777-777777777777'
]);
it("Yêu cầu ghi của sinh viên thiếu hoặc sai CSRF Token bị từ chối với HTTP 403", 
    $noCsrfRes['status'] === 403
);

// 7. Rate limit và khóa tài khoản sinh viên áp dụng đồng nhất (5 lần sai -> khóa 15 phút HTTP 409)
$testLockClient = new ApiTestClient();
for ($i = 0; $i < 5; $i++) {
    $testLockClient->request('POST', '/login', [
        'username' => 'sv003',
        'password' => 'WrongPass@999'
    ]);
}
$lockedLoginRes = $testLockClient->request('POST', '/login', [
    'username' => 'sv003',
    'password' => 'Student@003'
]);
$pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'sv003'")->execute();
it("Đăng nhập tài khoản sinh viên sai 5 lần liên tiếp kích hoạt khóa tài khoản 15 phút với HTTP 409", 
    $lockedLoginRes['status'] === 409 
    && ($lockedLoginRes['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED'
);

// Dọn dẹp dữ liệu kiểm thử
$pdo->prepare("DELETE FROM audit_log WHERE user_id IN (SELECT id FROM users WHERE username = 'sv004')")->execute();
$pdo->prepare("DELETE FROM users WHERE username = 'sv004'")->execute();
$pdo->prepare("DELETE FROM bills WHERE ledger_entry_id IN (SELECT id FROM ledger_entries WHERE fee_record_id = :id)")->execute(['id' => $sv011NewFrId]);
$pdo->prepare("DELETE FROM ledger_entries WHERE fee_record_id = :id")->execute(['id' => $sv011NewFrId]);
$pdo->prepare("DELETE FROM fee_records WHERE id = :id")->execute(['id' => $sv011NewFrId]);

// ----------------------------------------------------------------------------
// 23. KIỂM THỬ KHẮC PHỤC 3 LỖI HỆ THỐNG (ISSUES 1, 2, 3 VERIFICATION)
// ----------------------------------------------------------------------------
suite_header("23. Kiểm thử Khắc phục 3 Lỗi Hệ thống (Issues 1, 2, 3)");

// Nạp lại dữ liệu seed chuẩn để kiểm tra số liệu nguyên bản
reset_test_database($rootPdo);
$pdo = get_db_connection('tuition_test');
$api = new ApiTestClient();
$api->login('admin', 'Admin@123');

// 1. Issue 1: Không có lỗi HTTP 500 khi xóa sinh viên đã cấp tài khoản người dùng
$sv013Stmt = $pdo->query("SELECT id FROM students WHERE student_code = 'SV013'");
$sv013Id = (int)$sv013Stmt->fetchColumn();
if ($sv013Id > 0) {
    // Cấp tài khoản cho SV013
    $api->request('POST', "/students/{$sv013Id}/account");
    // Xóa SV013 qua API (trước đây bị lỗi 500 do foreign key constraint fk_users_students)
    $delSv013Res = $api->request('DELETE', "/students/{$sv013Id}");
    it("Xóa sinh viên có tài khoản người dùng thành công (HTTP 200) không gây lỗi 500", 
        $delSv013Res['status'] === 200 && ($delSv013Res['body']['ok'] ?? false) === true
    );
    // Khôi phục SV013 để bảo toàn dữ liệu seed
    $pdo->prepare("INSERT INTO students (id, student_code, full_name, name_search, class_name, created_at, updated_at) VALUES (:id, 'SV013', 'Hoàng Minh Tuấn', 'hoang minh tuan', 'CNTT1', NOW(), NOW())")->execute(['id' => $sv013Id]);
}

// 2. Issue 1: Đổi mật khẩu sinh viên không phát sinh Warning/TypeError (500)
$sv006Client = new ApiTestClient();
$sv006Client->login('sv006', 'Student@006');
$pwWrongRes = $sv006Client->request('POST', '/student/password', [
    'current_password'          => 'WrongPassword@123',
    'new_password'              => 'StudentNew@006',
    'new_password_confirmation' => 'StudentNew@006'
]);
it("POST /student/password xử lý an toàn không sinh 500 khi mật khẩu sai (trả về HTTP 400)", 
    $pwWrongRes['status'] === 400 && ($pwWrongRes['body']['error']['code'] ?? '') === 'INVALID_PASSWORD'
);

// 3. Issue 1: Ngoại lệ không mong muốn trả về thông điệp tiếng Việt 500 chuẩn, không lộ đường dẫn hay stack trace
$forcedExRes = $api->request('GET', '/me', null, ['X-Test-Force-Exception' => '1']);
$forcedBodyStr = json_encode($forcedExRes['body'], JSON_UNESCAPED_UNICODE);
it("Ngoại lệ trả về HTTP 500 với thông báo tiếng Việt chung, không lộ stack trace hay đường dẫn file", 
    $forcedExRes['status'] === 500 
    && ($forcedExRes['body']['error']['message'] ?? '') === 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại.'
    && ($forcedExRes['body']['error']['code'] ?? '') === 'INTERNAL_SERVER_ERROR'
    && strpos($forcedBodyStr, 'Stack Trace') === false
    && strpos($forcedBodyStr, 'bigprojectnew') === false
    && strpos($forcedBodyStr, '.php') === false
);

// 4. Issue 2: Thống kê trạng thái thanh toán khớp trực tiếp SQL trên từng học kỳ và toàn hệ thống
$feeSubSql = get_fee_summary_subquery();

// Toàn hệ thống (ALL)
$repAllRes = $api->request('GET', '/reports/summary');
$allData = $repAllRes['body']['data'] ?? [];
$sqlAllCounts = $pdo->query("SELECT 
    COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid,
    COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial,
    COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid,
    COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid,
    COUNT(*) AS total
FROM {$feeSubSql} v")->fetch();

it("Báo cáo ALL: 3 trạng thái khớp trực tiếp SQL (Unpaid={$sqlAllCounts['unpaid']}, Partial={$sqlAllCounts['partial']}, Paid={$sqlAllCounts['paid']})", 
    (int)($allData['status_counts']['unpaid'] ?? -1) === (int)$sqlAllCounts['unpaid']
    && (int)($allData['status_counts']['partial'] ?? -1) === (int)$sqlAllCounts['partial']
    && (int)($allData['status_counts']['paid'] ?? -1) === (int)$sqlAllCounts['paid']
);
it("Báo cáo ALL: Nộp thừa được báo cáo riêng biệt (Overpaid={$sqlAllCounts['overpaid']}) và tổng 3 trạng thái bằng tổng trừ nộp thừa", 
    (int)($allData['overpaid']['count'] ?? -1) === (int)$sqlAllCounts['overpaid']
    && ((int)$allData['status_counts']['unpaid'] + (int)$allData['status_counts']['partial'] + (int)$allData['status_counts']['paid']) === ((int)$allData['total_students_with_records'] - (int)$allData['overpaid']['count'])
    && ((int)$allData['status_counts']['unpaid'] + (int)$allData['status_counts']['partial'] + (int)$allData['status_counts']['paid'] + (int)$allData['overpaid']['count']) === (int)$allData['total_students_with_records']
);

// Học kỳ 1 (2025-2026)
$repSem1Res = $api->request('GET', '/reports/summary?semester=1');
$sem1Data = $repSem1Res['body']['data'] ?? [];
$sqlSem1Counts = $pdo->query("SELECT 
    COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid,
    COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial,
    COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid,
    COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid,
    COUNT(*) AS total
FROM {$feeSubSql} v WHERE v.semester_id = 1")->fetch();

it("Báo cáo HK1 2025-2026: Khớp đúng SQL (Unpaid=0, Partial=0, Paid=4, Overpaid=0, Total=4)", 
    (int)($sem1Data['status_counts']['unpaid'] ?? -1) === (int)$sqlSem1Counts['unpaid']
    && (int)($sem1Data['status_counts']['partial'] ?? -1) === (int)$sqlSem1Counts['partial']
    && (int)($sem1Data['status_counts']['paid'] ?? -1) === (int)$sqlSem1Counts['paid']
    && (int)($sem1Data['overpaid']['count'] ?? -1) === (int)$sqlSem1Counts['overpaid']
    && (int)($sem1Data['total_students_with_records'] ?? -1) === (int)$sqlSem1Counts['total']
);

// Học kỳ 2 (2025-2026 - kỳ có 2 hồ sơ nộp thừa)
$repSem2Res = $api->request('GET', '/reports/summary?semester=2');
$sem2Data = $repSem2Res['body']['data'] ?? [];
$sqlSem2Counts = $pdo->query("SELECT 
    COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid,
    COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial,
    COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid,
    COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid,
    COUNT(*) AS total
FROM {$feeSubSql} v WHERE v.semester_id = 2")->fetch();

it("Báo cáo HK2 2025-2026: Đã thanh toán đủ = 1 (không gộp 2 hồ sơ nộp thừa vào paid)", 
    (int)($sem2Data['status_counts']['paid'] ?? -1) === 1
    && (int)($sem2Data['overpaid']['count'] ?? -1) === 2
    && (int)($sem2Data['status_counts']['unpaid'] ?? -1) === 1
    && (int)($sem2Data['status_counts']['partial'] ?? -1) === 0
);

// Học kỳ 1 (2026-2027)
$repSem3Res = $api->request('GET', '/reports/summary?semester=3');
$sem3Data = $repSem3Res['body']['data'] ?? [];
it("Báo cáo HK1 2026-2027: Khớp đúng seed data (Unpaid=2, Partial=2, Paid=1, Overpaid=0, Total=5)", 
    (int)($sem3Data['status_counts']['unpaid'] ?? -1) === 2
    && (int)($sem3Data['status_counts']['partial'] ?? -1) === 2
    && (int)($sem3Data['status_counts']['paid'] ?? -1) === 1
    && (int)($sem3Data['overpaid']['count'] ?? -1) === 0
    && (int)($sem3Data['total_students_with_records'] ?? -1) === 5
);

// 5. Issue 2: Sinh viên có 2 hồ sơ ở trạng thái khác nhau được tính 1 lần cho mỗi hồ sơ
$sv002Records = $pdo->query("SELECT v.semester_id, v.derived_status FROM {$feeSubSql} v JOIN students s ON v.student_id = s.id WHERE s.student_code = 'SV002'")->fetchAll();
$sv002Statuses = array_column($sv002Records, 'derived_status');
it("SV002 có 3 hồ sơ học phí: 2 hồ sơ 'Fully Paid' và 1 hồ sơ 'Partially Paid'", 
    count($sv002Statuses) === 3
    && count(array_filter($sv002Statuses, fn($s) => $s === 'Fully Paid')) === 2
    && count(array_filter($sv002Statuses, fn($s) => $s === 'Partially Paid')) === 1
);

// Issue 2: Tra cứu lịch sử sử dụng chung derived_status duy nhất từ calc.php
$histPaidRes = $api->request('GET', '/history?status=' . urlencode('Đã thanh toán đủ'));
$histPaidEntries = $histPaidRes['body']['data'] ?? [];
$histPaidOk = true;
foreach ($histPaidEntries as $entry) {
    $ckStmt = $pdo->prepare("SELECT derived_status FROM {$feeSubSql} v WHERE v.fee_record_id = :frId");
    $ckStmt->execute(['frId' => $entry['fee_record_id']]);
    if ($ckStmt->fetchColumn() !== 'Fully Paid') {
        $histPaidOk = false;
        break;
    }
}
it("GET /api/history?status=Đã thanh toán đủ sử dụng đúng derived_status duy nhất từ calc.php", 
    $histPaidRes['status'] === 200 && count($histPaidEntries) > 0 && $histPaidOk
);

// 6. Issue 3: Tổng số sinh viên và KPI thống kê không đổi khi lọc theo từ khóa tìm kiếm
$stListAllRes = $api->request('GET', '/students');
$stListFilterRes = $api->request('GET', '/students?q=nguyen');
$stListZeroRes = $api->request('GET', '/students?q=nonexistentstudent');

$allStudents = $stListAllRes['body']['data'] ?? [];
$filteredStudents = $stListFilterRes['body']['data'] ?? [];
$zeroStudents = $stListZeroRes['body']['data'] ?? [];
$sqlTotalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

$allSummary = $stListAllRes['body']['meta']['summary'] ?? [];
$filterSummary = $stListFilterRes['body']['meta']['summary'] ?? [];
$zeroSummary = $stListZeroRes['body']['meta']['summary'] ?? [];

it("Danh sách sinh viên trả về total_students_count = 24 không đổi khi lọc tìm kiếm", 
    count($allStudents) === $sqlTotalStudents
    && count($filteredStudents) === 5
    && count($zeroStudents) === 0
    && (int)($allStudents[0]['total_students_count'] ?? 0) === $sqlTotalStudents
    && (int)($filteredStudents[0]['total_students_count'] ?? 0) === $sqlTotalStudents
);

it("Server trả về thống kê toàn trường (KPI summary) chuẩn xác kể cả khi danh sách bị lọc hoặc rỗng", 
    (int)($allSummary['total_students_count'] ?? 0) === 24
    && (int)($allSummary['with_records_count'] ?? 0) === 16
    && (int)($allSummary['fully_paid_count'] ?? 0) === 11
    && (int)($allSummary['outstanding_count'] ?? 0) === 5
    && (int)($allSummary['overpaid_count'] ?? 0) === 2
    && (int)($filterSummary['with_records_count'] ?? 0) === 16
    && (int)($filterSummary['fully_paid_count'] ?? 0) === 11
    && (int)($zeroSummary['total_students_count'] ?? 0) === 24
    && (int)($zeroSummary['with_records_count'] ?? 0) === 16
);

// 7. Issue 3: Số liệu tài chính từng sinh viên khớp đúng SQL (bao gồm SV miễn 100% là Fully Paid)
$studentsByCode = [];
foreach ($allStudents as $st) {
    $studentsByCode[$st['student_code']] = $st;
}

it("SV006 (miễn 100%, net_due = 0) có trạng thái 'Đã thanh toán đủ', không bị đếm nhầm thành 'Chưa thanh toán'", 
    isset($studentsByCode['SV006'])
    && $studentsByCode['SV006']['total_net_due'] === 0
    && $studentsByCode['SV006']['total_paid'] === 0
    && $studentsByCode['SV006']['total_outstanding'] === 0
    && $studentsByCode['SV006']['payment_status'] === 'Đã thanh toán đủ'
);

it("SV001 có tổng phải nộp 18.675.000, đã nộp 19.675.000, nợ 0, trạng thái 'Đã thanh toán đủ'", 
    isset($studentsByCode['SV001'])
    && $studentsByCode['SV001']['total_net_due'] === 18675000
    && $studentsByCode['SV001']['total_paid'] === 19675000
    && $studentsByCode['SV001']['total_outstanding'] === 0
    && $studentsByCode['SV001']['payment_status'] === 'Đã thanh toán đủ'
);

it("SV002 có tổng phải nộp 37.796.000, đã nộp 27.496.000, còn nợ 10.300.000, trạng thái 'Thanh toán một phần'", 
    isset($studentsByCode['SV002'])
    && $studentsByCode['SV002']['total_net_due'] === 37796000
    && $studentsByCode['SV002']['total_paid'] === 27496000
    && $studentsByCode['SV002']['total_outstanding'] === 10300000
    && $studentsByCode['SV002']['payment_status'] === 'Thanh toán một phần'
);

// ----------------------------------------------------------------------------
// 24. KIỂM THỬ MỞ RỘNG: ĐĂNG NHẬP XÁC THỰC & BÁO CÁO THỐNG KÊ (ISSUES 1 & 2 VERIFICATION)
// ----------------------------------------------------------------------------
suite_header("24. Kiểm thử Mở rộng: Đăng nhập Xác thực & Báo cáo Thống kê");

// A. Đăng nhập Admin và Sinh viên thành công trả về 200 kèm đầy đủ các trường quy định
$freshClient = new ApiTestClient();
$adminLoginRes = $freshClient->request('POST', '/login', [
    'username' => 'admin',
    'password' => 'Admin@123'
]);
$adminData = $adminLoginRes['body']['data'] ?? [];
it("Admin login trả về HTTP 200 với ok: true và role 'admin'", 
    $adminLoginRes['status'] === 200 
    && ($adminLoginRes['body']['ok'] ?? false) === true
    && ($adminData['role'] ?? '') === 'admin'
    && ($adminData['username'] ?? '') === 'admin'
    && array_key_exists('student_id', $adminData) && $adminData['student_id'] === null
    && ($adminData['must_change_password'] ?? -1) === 0
    && !empty($adminData['csrf_token'])
);

$svClient = new ApiTestClient();
$svLoginRes = $svClient->request('POST', '/login', [
    'username' => 'sv001',
    'password' => 'Student@001'
]);
$svData = $svLoginRes['body']['data'] ?? [];
it("Sinh viên sv001 login trả về HTTP 200 với ok: true, role 'student' và must_change_password", 
    $svLoginRes['status'] === 200 
    && ($svLoginRes['body']['ok'] ?? false) === true
    && ($svData['role'] ?? '') === 'student'
    && ($svData['username'] ?? '') === 'sv001'
    && ($svData['student_id'] ?? 0) === 1
    && ($svData['must_change_password'] ?? -1) === 0
    && !empty($svData['csrf_token'])
);

// B. Sai mật khẩu, Tên đăng nhập không tồn tại, và Khóa tài khoản không phát sinh HTTP 500
$wrongPwClient = new ApiTestClient();
$wrongPwRes = $wrongPwClient->request('POST', '/login', [
    'username' => 'admin',
    'password' => 'WrongAdminPassword@999'
]);
it("Đăng nhập sai mật khẩu trả về HTTP 401 với thông báo 'Sai tên đăng nhập hoặc mật khẩu.' không bị lỗi 500", 
    $wrongPwRes['status'] === 401 
    && ($wrongPwRes['body']['ok'] ?? true) === false
    && ($wrongPwRes['body']['error']['message'] ?? '') === 'Sai tên đăng nhập hoặc mật khẩu.'
    && ($wrongPwRes['body']['error']['code'] ?? '') === 'INVALID_CREDENTIALS'
);

$unknownUserClient = new ApiTestClient();
$unknownUserRes = $unknownUserClient->request('POST', '/login', [
    'username' => 'nonexistent_user_99999',
    'password' => 'SomePassword@123'
]);
it("Tên đăng nhập không tồn tại trả về HTTP 401 cùng thông báo quy định không bị lỗi 500", 
    $unknownUserRes['status'] === 401 
    && ($unknownUserRes['body']['ok'] ?? true) === false
    && ($unknownUserRes['body']['error']['message'] ?? '') === 'Sai tên đăng nhập hoặc mật khẩu.'
    && ($unknownUserRes['body']['error']['code'] ?? '') === 'INVALID_CREDENTIALS'
);

// C. Khóa tài khoản sau 5 lần sai và kiểm tra hết hạn sau 15 phút
$pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'sv003'")->execute();
$lockTestClient = new ApiTestClient();
for ($i = 1; $i <= 5; $i++) {
    $lockTestClient->request('POST', '/login', ['username' => 'sv003', 'password' => 'WrongPass@' . $i]);
}
$lockedCheckRes = $lockTestClient->request('POST', '/login', ['username' => 'sv003', 'password' => 'Student@003']);
it("Tài khoản bị khóa sau 5 lần sai trả về HTTP 409 ACCOUNT_LOCKED không bị lỗi 500", 
    $lockedCheckRes['status'] === 409 
    && ($lockedCheckRes['body']['ok'] ?? true) === false
    && ($lockedCheckRes['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED'
    && str_contains($lockedCheckRes['body']['error']['message'] ?? '', 'tạm khóa 15 phút')
);
// Giả lập hết hạn khóa 15 phút (đặt locked_until về quá khứ)
$pdo->prepare("UPDATE users SET locked_until = '2020-01-01 00:00:00' WHERE username = 'sv003'")->execute();
$unlockedRes = $lockTestClient->request('POST', '/login', ['username' => 'sv003', 'password' => 'Student@003']);
it("Tài khoản hết hạn khóa 15 phút đăng nhập thành công trở lại (HTTP 200)", 
    $unlockedRes['status'] === 200 && ($unlockedRes['body']['ok'] ?? false) === true
);

// D. Đăng nhập ghi nhận dòng audit_log và rate_limit không phát sinh lỗi
$auditSuccessRow = $pdo->query("SELECT id, action, entity, user_id FROM audit_log WHERE action = 'login_success' AND entity = 'users' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$auditFailedRow = $pdo->query("SELECT id, action, entity, payload FROM audit_log WHERE action = 'login_failed' AND entity = 'users' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rateLimitDir = sys_get_temp_dir() . '/tuition_rate_limits';
$hasRateLimitFile = is_dir($rateLimitDir) && count(glob($rateLimitDir . '/*.json')) > 0;
it("Đăng nhập ghi audit_log (login_success, login_failed) và cập nhật rate limit không phát sinh lỗi", 
    !empty($auditSuccessRow['id'])
    && !empty($auditFailedRow['id'])
    && $hasRateLimitFile
);

// E. /api/reports/summary trả về số nguyên cho mọi trạng thái và overpaid, không có null
$allSummaryRes = $freshClient->request('GET', '/reports/summary');
$allRepData = $allSummaryRes['body']['data'] ?? [];
$allStatusCounts = $allRepData['status_counts'] ?? [];
$allOverpaid = $allRepData['overpaid'] ?? [];
it("/api/reports/summary phạm vi ALL trả về số nguyên cho mọi trạng thái, không có giá trị null", 
    $allSummaryRes['status'] === 200
    && is_int($allStatusCounts['unpaid'] ?? null)
    && is_int($allStatusCounts['partial'] ?? null)
    && is_int($allStatusCounts['paid'] ?? null)
    && is_int($allOverpaid['count'] ?? null)
    && is_int($allOverpaid['amount'] ?? null)
    && is_int($allRepData['total_students_with_records'] ?? null)
);

// Kiểm tra từng học kỳ riêng biệt: 1, 2, 3
$allSemestersValid = true;
foreach ([1, 2, 3] as $sid) {
    $semCheckRes = $freshClient->request('GET', "/reports/summary?semester={$sid}");
    $scData = $semCheckRes['body']['data'] ?? [];
    $scStatus = $scData['status_counts'] ?? [];
    $scOverpaid = $scData['overpaid'] ?? [];
    if ($semCheckRes['status'] !== 200
        || !is_int($scStatus['unpaid'] ?? null)
        || !is_int($scStatus['partial'] ?? null)
        || !is_int($scStatus['paid'] ?? null)
        || !is_int($scOverpaid['count'] ?? null)
        || !is_int($scOverpaid['amount'] ?? null)
    ) {
        $allSemestersValid = false;
        break;
    }
}
it("/api/reports/summary trả về số nguyên không có null cho từng học kỳ riêng lẻ (1, 2, 3)", 
    $allSemestersValid
);

// F. Tổng số lượng các trạng thái cộng nộp thừa bằng đúng tổng số hồ sơ trong phạm vi
$sumReconcilesAll = (($allStatusCounts['unpaid'] + $allStatusCounts['partial'] + $allStatusCounts['paid'] + $allOverpaid['count']) === $allRepData['total_students_with_records']);
$sumReconcilesSems = true;
foreach ([1, 2, 3] as $sid) {
    $sRes = $freshClient->request('GET', "/reports/summary?semester={$sid}");
    $sd = $sRes['body']['data'] ?? [];
    $sum = ($sd['status_counts']['unpaid'] ?? 0) + ($sd['status_counts']['partial'] ?? 0) + ($sd['status_counts']['paid'] ?? 0) + ($sd['overpaid']['count'] ?? 0);
    if ($sum !== ($sd['total_students_with_records'] ?? -1)) {
        $sumReconcilesSems = false;
        break;
    }
}
it("Tổng số lượng 3 trạng thái + nộp thừa bằng đúng tổng số hồ sơ học phí trong phạm vi (toàn trường & từng kỳ)", 
    $sumReconcilesAll && $sumReconcilesSems
);

// G. Học kỳ không có hồ sơ học phí nào (phạm vi rỗng) trả về số 0 cho mọi trạng thái, không bị '-'
$pdo->exec("INSERT INTO semesters (term_no, academic_year_start) VALUES (3, 2029)");
$emptySemesterId = (int)$pdo->lastInsertId();
$emptyScopeRes = $freshClient->request('GET', "/reports/summary?semester={$emptySemesterId}");
$emptyScopeData = $emptyScopeRes['body']['data'] ?? [];
$emptyStatus = $emptyScopeData['status_counts'] ?? [];
$emptyOverpaid = $emptyScopeData['overpaid'] ?? [];
it("Học kỳ không có hồ sơ nào trả về số 0 cho mọi trạng thái (không phải null hay '-')", 
    $emptyScopeRes['status'] === 200
    && ($emptyScopeData['total_students_with_records'] ?? -1) === 0
    && ($emptyStatus['unpaid'] ?? -1) === 0
    && ($emptyStatus['partial'] ?? -1) === 0
    && ($emptyStatus['paid'] ?? -1) === 0
    && ($emptyOverpaid['count'] ?? -1) === 0
    && ($emptyOverpaid['amount'] ?? -1) === 0
    && ($emptyScopeData['gross_tuition'] ?? -1) === 0
    && ($emptyScopeData['receivable'] ?? -1) === 0
    && ($emptyScopeData['collected'] ?? -1) === 0
    && ($emptyScopeData['outstanding'] ?? -1) === 0
);
$pdo->exec("DELETE FROM semesters WHERE id = {$emptySemesterId}");

// ----------------------------------------------------------------------------
// 25. KIỂM THỬ THỐNG KÊ TRẠNG THÁI THANH TOÁN (STATUS BREAKDOWN & PARAMETER VALIDATION)
// ----------------------------------------------------------------------------
suite_header("25. Kiểm thử Thống kê Trạng thái Thanh toán (Status Breakdown Verification)");

// 1. Endpoint thống kê trả về số nguyên, không có null cho toàn hệ thống và từng học kỳ
$statClient = new ApiTestClient();
$statClient->login('admin', 'Admin@123');

$statAllRes = $statClient->request('GET', '/reports/summary');
$statAllData = $statAllRes['body']['data'] ?? [];
$statAllCounts = $statAllData['status_counts'] ?? [];
$statAllOverpaid = $statAllData['overpaid'] ?? [];
it("Endpoint thống kê trả về số nguyên không có null cho phạm vi ALL", 
    $statAllRes['status'] === 200
    && is_int($statAllCounts['unpaid'] ?? null)
    && is_int($statAllCounts['partial'] ?? null)
    && is_int($statAllCounts['paid'] ?? null)
    && is_int($statAllOverpaid['count'] ?? null)
    && is_int($statAllData['total_students_with_records'] ?? null)
    && ($statAllCounts['unpaid'] !== null)
    && ($statAllCounts['partial'] !== null)
    && ($statAllCounts['paid'] !== null)
);

// Từng học kỳ (1, 2, 3)
$statSemsIntsOk = true;
foreach ([1, 2, 3] as $sId) {
    $semRes = $statClient->request('GET', "/reports/summary?semester={$sId}");
    $sData = $semRes['body']['data'] ?? [];
    $sc = $sData['status_counts'] ?? [];
    $so = $sData['overpaid'] ?? [];
    if ($semRes['status'] !== 200
        || !is_int($sc['unpaid'] ?? null)
        || !is_int($sc['partial'] ?? null)
        || !is_int($sc['paid'] ?? null)
        || !is_int($so['count'] ?? null)
    ) {
        $statSemsIntsOk = false;
        break;
    }
}
it("Endpoint thống kê trả về số nguyên không có null cho từng học kỳ riêng lẻ", $statSemsIntsOk);

// 2. Tổng 3 trạng thái + nộp thừa bằng đúng tổng số hồ sơ học phí trong phạm vi (toàn trường & từng kỳ)
$statSumAll = ($statAllCounts['unpaid'] + $statAllCounts['partial'] + $statAllCounts['paid'] + $statAllOverpaid['count']) === $statAllData['total_students_with_records'];
$statSumSems = true;
foreach ([1, 2, 3] as $sId) {
    $semRes = $statClient->request('GET', "/reports/summary?semester={$sId}");
    $sData = $semRes['body']['data'] ?? [];
    $sum = ($sData['status_counts']['unpaid'] ?? 0) + ($sData['status_counts']['partial'] ?? 0) + ($sData['status_counts']['paid'] ?? 0) + ($sData['overpaid']['count'] ?? 0);
    if ($sum !== ($sData['total_students_with_records'] ?? -1)) {
        $statSumSems = false;
        break;
    }
}
it("3 trạng thái cộng nộp thừa bằng đúng tổng số hồ sơ học phí trong phạm vi", $statSumAll && $statSumSems);

// 3. Học kỳ không có hồ sơ nào trả về 0 cho mọi trạng thái, không phải null
$pdo->exec("INSERT INTO semesters (term_no, academic_year_start) VALUES (2, 2030)");
$noRecordSemId = (int)$pdo->lastInsertId();
$noRecRes = $statClient->request('GET', "/reports/summary?semester={$noRecordSemId}");
$noRecData = $noRecRes['body']['data'] ?? [];
it("Học kỳ không có hồ sơ học phí nào trả về 0 cho mọi trạng thái, không phải null", 
    $noRecRes['status'] === 200
    && ($noRecData['total_students_with_records'] ?? -1) === 0
    && ($noRecData['status_counts']['unpaid'] ?? -1) === 0
    && ($noRecData['status_counts']['partial'] ?? -1) === 0
    && ($noRecData['status_counts']['paid'] ?? -1) === 0
    && ($noRecData['overpaid']['count'] ?? -1) === 0
);
$pdo->exec("DELETE FROM semesters WHERE id = {$noRecordSemId}");

// 4. Tham số semester thiếu hoặc không hợp lệ trả về kết quả xác định (default scope hoặc 400 kèm field error), không bao giờ bị 500
$noParamRes = $statClient->request('GET', '/reports/summary');
it("Yêu cầu không có tham số semester trả về phạm vi mặc định ALL với HTTP 200", 
    $noParamRes['status'] === 200 
    && ($noParamRes['body']['ok'] ?? false) === true
    && ($noParamRes['body']['data']['total_students_with_records'] ?? 0) > 0
);

$emptyParamRes = $statClient->request('GET', '/reports/summary?semester=');
it("Yêu cầu với tham số semester rỗng (semester=) trả về phạm vi mặc định ALL với HTTP 200", 
    $emptyParamRes['status'] === 200 
    && ($emptyParamRes['body']['ok'] ?? false) === true
    && ($emptyParamRes['body']['data']['total_students_with_records'] ?? 0) > 0
);

$undefParamRes = $statClient->request('GET', '/reports/summary?semester=undefined');
it("Yêu cầu với semester=undefined trả về HTTP 400 kèm lỗi trường semester, không bị lỗi 500", 
    $undefParamRes['status'] === 400 
    && ($undefParamRes['body']['ok'] ?? true) === false
    && isset($undefParamRes['body']['error']['fields']['semester'])
    && $undefParamRes['body']['error']['code'] === 'VALIDATION_ERROR'
);

$negParamRes = $statClient->request('GET', '/reports/summary?semester=-1');
it("Yêu cầu với semester âm (-1) trả về HTTP 400 kèm lỗi trường semester, không bị lỗi 500", 
    $negParamRes['status'] === 400 
    && ($negParamRes['body']['ok'] ?? true) === false
    && isset($negParamRes['body']['error']['fields']['semester'])
);

$strParamRes = $statClient->request('GET', '/reports/summary?semester=invalid_name');
it("Yêu cầu với semester dạng chuỗi chữ trả về HTTP 400 kèm lỗi trường semester, không bị lỗi 500", 
    $strParamRes['status'] === 400 
    && ($strParamRes['body']['ok'] ?? true) === false
    && isset($strParamRes['body']['error']['fields']['semester'])
);

// 5. Mỗi số lượng trạng thái bằng đúng kết quả SQL tính toán trực tiếp từ calc.php get_fee_summary_subquery()
$calcSubSql = get_fee_summary_subquery();
$sqlAll = $pdo->query("SELECT 
    COUNT(*) AS total,
    COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid,
    COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial,
    COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid,
    COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid
FROM {$calcSubSql} v")->fetch(PDO::FETCH_ASSOC);

it("Mỗi số lượng trạng thái phạm vi ALL bằng đúng kết quả SQL trực tiếp từ calc.php", 
    (int)$statAllCounts['unpaid'] === (int)$sqlAll['unpaid']
    && (int)$statAllCounts['partial'] === (int)$sqlAll['partial']
    && (int)$statAllCounts['paid'] === (int)$sqlAll['paid']
    && (int)$statAllOverpaid['count'] === (int)$sqlAll['overpaid']
    && (int)$statAllData['total_students_with_records'] === (int)$sqlAll['total']
);

$sqlSemsMatch = true;
foreach ([1, 2, 3] as $sId) {
    $semRes = $statClient->request('GET', "/reports/summary?semester={$sId}");
    $sData = $semRes['body']['data'] ?? [];
    $sc = $sData['status_counts'] ?? [];
    $so = $sData['overpaid'] ?? [];
    $sqlSem = $pdo->query("SELECT 
        COUNT(*) AS total,
        COUNT(CASE WHEN v.derived_status = 'Unpaid' THEN 1 END) AS unpaid,
        COUNT(CASE WHEN v.derived_status = 'Partially Paid' THEN 1 END) AS partial,
        COUNT(CASE WHEN v.derived_status = 'Fully Paid' THEN 1 END) AS paid,
        COUNT(CASE WHEN v.derived_status = 'Overpaid' THEN 1 END) AS overpaid
    FROM {$calcSubSql} v WHERE v.semester_id = {$sId}")->fetch(PDO::FETCH_ASSOC);

    if ((int)($sc['unpaid'] ?? -1) !== (int)$sqlSem['unpaid']
        || (int)($sc['partial'] ?? -1) !== (int)$sqlSem['partial']
        || (int)($sc['paid'] ?? -1) !== (int)$sqlSem['paid']
        || (int)($so['count'] ?? -1) !== (int)$sqlSem['overpaid']
        || (int)($sData['total_students_with_records'] ?? -1) !== (int)$sqlSem['total']
    ) {
        $sqlSemsMatch = false;
        break;
    }
}
it("Mỗi số lượng trạng thái cho từng học kỳ riêng biệt bằng đúng kết quả SQL trực tiếp từ calc.php", $sqlSemsMatch);

// 6. Tài khoản sinh viên không thể truy cập endpoint báo cáo thống kê (HTTP 403 Forbidden)
$studentReportRes = $svClient->request('GET', '/reports/summary');
it("Tài khoản sinh viên không thể truy cập endpoint báo cáo thống kê (HTTP 403)", 
    $studentReportRes['status'] === 403
);

// ----------------------------------------------------------------------------
// 26. KIỂM THỬ TRUY CẬP MENU THANH TOÁN HỌC PHÍ SINH VIÊN (ISSUE 1 VERIFICATION)
// ----------------------------------------------------------------------------
suite_header("26. Kiểm thử Truy cập Menu Thanh toán & Quyền Sinh viên (Issue 1 Verification)");

// 1. Sinh viên sv001 (must_change_password = 0) nạp dữ liệu nợ và màn hình thanh toán thành công (HTTP 200)
$sv001Client = new ApiTestClient();
$sv001Login = $sv001Client->login('sv001', 'Student@001');
it("Sinh viên sv001 đăng nhập thành công với role='student' và must_change_password=0",
    $sv001Login['status'] === 200
    && ($sv001Login['body']['data']['role'] ?? '') === 'student'
    && (int)($sv001Login['body']['data']['must_change_password'] ?? -1) === 0
);

$sv001DebtsRes = $sv001Client->request('GET', '/student/debts');
it("Sinh viên sv001 nạp dữ liệu công nợ và màn hình thanh toán thành công (HTTP 200)",
    $sv001DebtsRes['status'] === 200
    && ($sv001DebtsRes['body']['ok'] ?? false) === true
    && isset($sv001DebtsRes['body']['data']['all_records'])
);

// sv001 có hồ sơ nộp thừa (#5) hiển thị đúng cảnh báo "Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo."
$sv001Records = $sv001DebtsRes['body']['data']['all_records'] ?? [];
$hasOverpaidRecord = false;
$overpaidHasCorrectWarning = false;
foreach ($sv001Records as $rec) {
    if (!empty($rec['is_overpaid'])) {
        $hasOverpaidRecord = true;
        if (($rec['warning'] ?? '') === 'Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.') {
            $overpaidHasCorrectWarning = true;
        }
    }
}
it("Hồ sơ nộp thừa của sv001 hiển thị cảnh báo quy định: 'Hồ sơ đang có số tiền thừa. Vui lòng liên hệ phòng đào tạo.'",
    $hasOverpaidRecord && $overpaidHasCorrectWarning
);

// 2. Sinh viên sv002 (must_change_password = 0) nạp dữ liệu nợ và màn hình thanh toán thành công (HTTP 200)
$sv002Client = new ApiTestClient();
$sv002Login = $sv002Client->login('sv002', 'Student@002');
it("Sinh viên sv002 đăng nhập thành công với role='student' và must_change_password=0",
    $sv002Login['status'] === 200
    && ($sv002Login['body']['data']['role'] ?? '') === 'student'
    && (int)($sv002Login['body']['data']['must_change_password'] ?? -1) === 0
);

$sv002DebtsRes = $sv002Client->request('GET', '/student/debts');
it("Sinh viên sv002 nạp dữ liệu công nợ và màn hình thanh toán thành công (HTTP 200)",
    $sv002DebtsRes['status'] === 200
    && ($sv002DebtsRes['body']['ok'] ?? false) === true
    && count($sv002DebtsRes['body']['data']['outstanding_records'] ?? []) > 0
);

// 3. sv006 có must_change_password = 1 bị chặn tại gate đổi mật khẩu với HTTP 403 PASSWORD_CHANGE_REQUIRED
$sv006Client = new ApiTestClient();
$sv006Login = $sv006Client->login('sv006', 'Student@006');
it("Sinh viên sv006 đăng nhập thành công nhưng có must_change_password=1",
    $sv006Login['status'] === 200
    && (int)($sv006Login['body']['data']['must_change_password'] ?? 0) === 1
);

$sv006DebtsRes = $sv006Client->request('GET', '/student/debts');
it("Sinh viên sv006 bị chặn truy cập /api/student/debts khi chưa đổi mật khẩu (HTTP 403 PASSWORD_CHANGE_REQUIRED)",
    $sv006DebtsRes['status'] === 403
    && ($sv006DebtsRes['body']['error']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED'
);

// 4. Sinh viên không thể truy cập bất kỳ endpoint quản trị nào (Admin Data Isolation)
$svAdminCheck1 = $sv001Client->request('GET', '/students');
$svAdminCheck2 = $sv001Client->request('GET', '/refunds');
$svAdminCheck3 = $sv001Client->request('GET', '/rates');
$svAdminCheck4 = $sv001Client->request('GET', '/reports/summary');
it("Sinh viên bị chặn truy cập toàn bộ dữ liệu quản trị /students, /refunds, /rates, /reports (HTTP 403)",
    $svAdminCheck1['status'] === 403
    && $svAdminCheck2['status'] === 403
    && $svAdminCheck3['status'] === 403
    && $svAdminCheck4['status'] === 403
);

// 5. Admin không thể truy cập endpoint sinh viên (Student Data Isolation)
$adminClientForSv = new ApiTestClient();
$adminClientForSv->login('admin', 'Admin@123');
$adminSvCheck = $adminClientForSv->request('GET', '/student/debts');
it("Quản trị viên (admin) gọi endpoint sinh viên /api/student/debts bị từ chối (HTTP 403)",
    $adminSvCheck['status'] === 403
);

// ----------------------------------------------------------------------------
// 27. KIỂM THỬ 3 CỘT SỐ TIỀN BÁO CÁO THỐNG KÊ (PHẢI THU, ĐÃ THU, CÒN NỢ)
// ----------------------------------------------------------------------------
suite_header("27. Kiểm thử 3 Cột Số tiền Thống kê Báo cáo (Phải thu, Đã thu, Còn nợ)");

$statAmtClient = new ApiTestClient();
$statAmtClient->login('admin', 'Admin@123');

// 1. Endpoint /api/reports/summary trả về số nguyên cho receivable, collected, outstanding không có null phạm vi ALL
$repAllAmt = $statAmtClient->request('GET', '/reports/summary');
$allAmtData = $repAllAmt['body']['data'] ?? [];
$allAmounts = $allAmtData['status_amounts'] ?? [];

it("/api/reports/summary phạm vi ALL trả về số nguyên cho 3 cột số tiền (receivable, collected, outstanding), không có null",
    $repAllAmt['status'] === 200
    && is_int($allAmtData['receivable'])
    && is_int($allAmtData['collected'])
    && is_int($allAmtData['outstanding'])
    && is_int($allAmounts['unpaid']['receivable'] ?? null)
    && is_int($allAmounts['unpaid']['collected'] ?? null)
    && is_int($allAmounts['unpaid']['outstanding'] ?? null)
    && is_int($allAmounts['partial']['receivable'] ?? null)
    && is_int($allAmounts['partial']['collected'] ?? null)
    && is_int($allAmounts['partial']['outstanding'] ?? null)
    && is_int($allAmounts['paid']['receivable'] ?? null)
    && is_int($allAmounts['paid']['collected'] ?? null)
    && is_int($allAmounts['paid']['outstanding'] ?? null)
    && is_int($allAmounts['overpaid']['receivable'] ?? null)
    && is_int($allAmounts['overpaid']['collected'] ?? null)
    && is_int($allAmounts['overpaid']['outstanding'] ?? null)
);

// 2. Cho từng học kỳ riêng biệt (1, 2, 3), endpoint trả về số nguyên không có null cho cả 3 cột số tiền
$semAmtsIntMatch = true;
foreach ([1, 2, 3] as $sId) {
    $semAmtRes = $statAmtClient->request('GET', "/reports/summary?semester={$sId}");
    $sAmtData = $semAmtRes['body']['data'] ?? [];
    $sa = $sAmtData['status_amounts'] ?? [];
    if ($semAmtRes['status'] !== 200
        || !is_int($sAmtData['receivable'])
        || !is_int($sAmtData['collected'])
        || !is_int($sAmtData['outstanding'])
        || !is_int($sa['unpaid']['receivable'] ?? null)
        || !is_int($sa['unpaid']['collected'] ?? null)
        || !is_int($sa['unpaid']['outstanding'] ?? null)
        || !is_int($sa['partial']['receivable'] ?? null)
        || !is_int($sa['partial']['collected'] ?? null)
        || !is_int($sa['partial']['outstanding'] ?? null)
        || !is_int($sa['paid']['receivable'] ?? null)
        || !is_int($sa['paid']['collected'] ?? null)
        || !is_int($sa['paid']['outstanding'] ?? null)
        || !is_int($sa['overpaid']['receivable'] ?? null)
        || !is_int($sa['overpaid']['collected'] ?? null)
        || !is_int($sa['overpaid']['outstanding'] ?? null)
    ) {
        $semAmtsIntMatch = false;
        break;
    }
}
it("Endpoint trả về số nguyên không có null cho cả 3 cột số tiền trong từng học kỳ (1, 2, 3)", $semAmtsIntMatch);

// 3. Phương trình đối soát: receivable = collected + outstanding cho mọi phạm vi (ALL, 1, 2, 3) và cho từng trạng thái
$reconcilesAllScopes = true;
foreach (['ALL', 1, 2, 3] as $scopeVal) {
    $param = $scopeVal === 'ALL' ? '' : "?semester={$scopeVal}";
    $res = $statAmtClient->request('GET', "/reports/summary{$param}");
    $d = $res['body']['data'] ?? [];
    $rec = $d['receivable'] ?? -1;
    $col = $d['collected'] ?? -2;
    $out = $d['outstanding'] ?? -3;
    $recOk = ($col + $out) === $rec && ($d['reconciles'] ?? false) === true;
    
    // Kiểm tra từng dòng trạng thái cũng phải cân bằng: receivable = collected + outstanding
    $sa = $d['status_amounts'] ?? [];
    $unpaidOk = (($sa['unpaid']['collected'] ?? 0) + ($sa['unpaid']['outstanding'] ?? 0)) === ($sa['unpaid']['receivable'] ?? -1);
    $partialOk = (($sa['partial']['collected'] ?? 0) + ($sa['partial']['outstanding'] ?? 0)) === ($sa['partial']['receivable'] ?? -1);
    $paidOk = (($sa['paid']['collected'] ?? 0) + ($sa['paid']['outstanding'] ?? 0)) === ($sa['paid']['receivable'] ?? -1);
    $overpaidOk = (($sa['overpaid']['collected'] ?? 0) + ($sa['overpaid']['outstanding'] ?? 0)) === ($sa['overpaid']['receivable'] ?? -1);

    if (!$recOk || !$unpaidOk || !$partialOk || !$paidOk || !$overpaidOk) {
        $reconcilesAllScopes = false;
        break;
    }
}
it("Phương trình đối soát receivable = collected + outstanding cân bằng tuyệt đối trong mọi phạm vi và từng trạng thái", $reconcilesAllScopes);

// 4. Mỗi số tiền bằng đúng kết quả SQL trực tiếp từ shared derivation (calc.php) cho phạm vi ALL
$sqlAllAmts = $pdo->query("SELECT 
    COALESCE(SUM(v.net_due), 0) AS receivable,
    COALESCE(SUM(LEAST(v.total_paid, v.net_due)), 0) AS collected,
    COALESCE(SUM(v.outstanding_amount), 0) AS outstanding,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.net_due ELSE 0 END), 0) AS unpaid_rec,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS unpaid_col,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS unpaid_out,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.net_due ELSE 0 END), 0) AS partial_rec,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS partial_col,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS partial_out,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.net_due ELSE 0 END), 0) AS paid_rec,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS paid_col,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS paid_out,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.net_due ELSE 0 END), 0) AS overpaid_rec,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS overpaid_col,
    COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS overpaid_out
FROM {$calcSubSql} v")->fetch(PDO::FETCH_ASSOC);

it("Mỗi số tiền của 3 cột trong phạm vi ALL bằng đúng kết quả SQL trực tiếp từ calc.php",
    (int)$allAmtData['receivable'] === (int)$sqlAllAmts['receivable']
    && (int)$allAmtData['collected'] === (int)$sqlAllAmts['collected']
    && (int)$allAmtData['outstanding'] === (int)$sqlAllAmts['outstanding']
    && (int)$allAmounts['unpaid']['receivable'] === (int)$sqlAllAmts['unpaid_rec']
    && (int)$allAmounts['unpaid']['collected'] === (int)$sqlAllAmts['unpaid_col']
    && (int)$allAmounts['unpaid']['outstanding'] === (int)$sqlAllAmts['unpaid_out']
    && (int)$allAmounts['partial']['receivable'] === (int)$sqlAllAmts['partial_rec']
    && (int)$allAmounts['partial']['collected'] === (int)$sqlAllAmts['partial_col']
    && (int)$allAmounts['partial']['outstanding'] === (int)$sqlAllAmts['partial_out']
    && (int)$allAmounts['paid']['receivable'] === (int)$sqlAllAmts['paid_rec']
    && (int)$allAmounts['paid']['collected'] === (int)$sqlAllAmts['paid_col']
    && (int)$allAmounts['paid']['outstanding'] === (int)$sqlAllAmts['paid_out']
    && (int)$allAmounts['overpaid']['receivable'] === (int)$sqlAllAmts['overpaid_rec']
    && (int)$allAmounts['overpaid']['collected'] === (int)$sqlAllAmts['overpaid_col']
    && (int)$allAmounts['overpaid']['outstanding'] === (int)$sqlAllAmts['overpaid_out']
);

// 5. Mỗi số tiền cho từng học kỳ riêng biệt bằng đúng kết quả SQL trực tiếp từ calc.php
$sqlAmtsSemsMatch = true;
foreach ([1, 2, 3] as $sId) {
    $semRes = $statAmtClient->request('GET', "/reports/summary?semester={$sId}");
    $sData = $semRes['body']['data'] ?? [];
    $sa = $sData['status_amounts'] ?? [];
    $sqlSem = $pdo->query("SELECT 
        COALESCE(SUM(v.net_due), 0) AS receivable,
        COALESCE(SUM(LEAST(v.total_paid, v.net_due)), 0) AS collected,
        COALESCE(SUM(v.outstanding_amount), 0) AS outstanding,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.net_due ELSE 0 END), 0) AS unpaid_rec,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS unpaid_col,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Unpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS unpaid_out,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.net_due ELSE 0 END), 0) AS partial_rec,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS partial_col,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Partially Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS partial_out,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.net_due ELSE 0 END), 0) AS paid_rec,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS paid_col,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Fully Paid' THEN v.outstanding_amount ELSE 0 END), 0) AS paid_out,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.net_due ELSE 0 END), 0) AS overpaid_rec,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN LEAST(v.total_paid, v.net_due) ELSE 0 END), 0) AS overpaid_col,
        COALESCE(SUM(CASE WHEN v.derived_status = 'Overpaid' THEN v.outstanding_amount ELSE 0 END), 0) AS overpaid_out
    FROM {$calcSubSql} v WHERE v.semester_id = {$sId}")->fetch(PDO::FETCH_ASSOC);

    if ((int)$sData['receivable'] !== (int)$sqlSem['receivable']
        || (int)$sData['collected'] !== (int)$sqlSem['collected']
        || (int)$sData['outstanding'] !== (int)$sqlSem['outstanding']
        || (int)($sa['unpaid']['receivable'] ?? -1) !== (int)$sqlSem['unpaid_rec']
        || (int)($sa['unpaid']['collected'] ?? -1) !== (int)$sqlSem['unpaid_col']
        || (int)($sa['unpaid']['outstanding'] ?? -1) !== (int)$sqlSem['unpaid_out']
        || (int)($sa['partial']['receivable'] ?? -1) !== (int)$sqlSem['partial_rec']
        || (int)($sa['partial']['collected'] ?? -1) !== (int)$sqlSem['partial_col']
        || (int)($sa['partial']['outstanding'] ?? -1) !== (int)$sqlSem['partial_out']
        || (int)($sa['paid']['receivable'] ?? -1) !== (int)$sqlSem['paid_rec']
        || (int)($sa['paid']['collected'] ?? -1) !== (int)$sqlSem['paid_col']
        || (int)($sa['paid']['outstanding'] ?? -1) !== (int)$sqlSem['paid_out']
        || (int)($sa['overpaid']['receivable'] ?? -1) !== (int)$sqlSem['overpaid_rec']
        || (int)($sa['overpaid']['collected'] ?? -1) !== (int)$sqlSem['overpaid_col']
        || (int)($sa['overpaid']['outstanding'] ?? -1) !== (int)$sqlSem['overpaid_out']
    ) {
        $sqlAmtsSemsMatch = false;
        break;
    }
}
it("Mỗi số tiền của 3 cột cho từng học kỳ riêng biệt bằng đúng kết quả SQL trực tiếp từ calc.php", $sqlAmtsSemsMatch);

// 6. Học kỳ không có hồ sơ học phí nào trả về 0 cho cả 3 cột số tiền, không phải null hay '-'
$pdo->exec("INSERT INTO semesters (term_no, academic_year_start) VALUES (3, 2035)");
$noRecSemId2 = (int)$pdo->lastInsertId();
$noRecAmtRes = $statAmtClient->request('GET', "/reports/summary?semester={$noRecSemId2}");
$noRecAmtData = $noRecAmtRes['body']['data'] ?? [];
$noRecStatusAmts = $noRecAmtData['status_amounts'] ?? [];
it("Học kỳ không có hồ sơ học phí nào trả về 0 cho cả 3 cột số tiền ở mọi trạng thái và tổng", 
    $noRecAmtRes['status'] === 200
    && ($noRecAmtData['receivable'] ?? -1) === 0
    && ($noRecAmtData['collected'] ?? -1) === 0
    && ($noRecAmtData['outstanding'] ?? -1) === 0
    && ($noRecStatusAmts['unpaid']['receivable'] ?? -1) === 0
    && ($noRecStatusAmts['unpaid']['collected'] ?? -1) === 0
    && ($noRecStatusAmts['unpaid']['outstanding'] ?? -1) === 0
    && ($noRecStatusAmts['partial']['receivable'] ?? -1) === 0
    && ($noRecStatusAmts['partial']['collected'] ?? -1) === 0
    && ($noRecStatusAmts['partial']['outstanding'] ?? -1) === 0
    && ($noRecStatusAmts['paid']['receivable'] ?? -1) === 0
    && ($noRecStatusAmts['paid']['collected'] ?? -1) === 0
    && ($noRecStatusAmts['paid']['outstanding'] ?? -1) === 0
    && ($noRecStatusAmts['overpaid']['receivable'] ?? -1) === 0
    && ($noRecStatusAmts['overpaid']['collected'] ?? -1) === 0
    && ($noRecStatusAmts['overpaid']['outstanding'] ?? -1) === 0
);
$pdo->exec("DELETE FROM semesters WHERE id = {$noRecSemId2}");

// 7. Khoản nộp thừa (overpaid excess) được loại trừ khỏi tiền còn nợ và báo cáo riêng biệt
$sem2AmtRes = $statAmtClient->request('GET', '/reports/summary?semester=2');
$sem2AmtData = $sem2AmtRes['body']['data'] ?? [];
$sem2Overpaid = $sem2AmtData['overpaid'] ?? [];
$sem2StatusAmts = $sem2AmtData['status_amounts'] ?? [];
it("Khoản nộp thừa (overpaid excess) được loại trừ khỏi tiền còn nợ và báo cáo riêng biệt (1.700.000)", 
    $sem2AmtRes['status'] === 200
    && (int)$sem2Overpaid['amount'] === 1700000
    && (int)$sem2Overpaid['count'] === 2
    && (int)($sem2StatusAmts['overpaid']['outstanding'] ?? -1) === 0
    && (int)$sem2AmtData['outstanding'] === 11480000
);

// ----------------------------------------------------------------------------
// 28. LUỒNG THANH TOÁN MÃ QR & THẺ VISA/MASTERCARD MÔ PHỎNG (SUITE 28)
// ----------------------------------------------------------------------------
suite_header("28. Kiểm thử Mở rộng Luồng Thanh toán Sinh viên (Mã QR & Thẻ Visa/Mastercard Mô phỏng)");

// Thiết lập học kỳ và hồ sơ học phí riêng biệt phục vụ Suite 28
$pdo->prepare("INSERT INTO semesters (term_no, academic_year_start, registration_open) VALUES (3, 2027, 1) ON DUPLICATE KEY UPDATE registration_open = 1")->execute();
$s28SemId = (int)$pdo->query("SELECT id FROM semesters WHERE term_no = 3 AND academic_year_start = 2027")->fetchColumn();
$pdo->prepare("INSERT INTO credit_rates (semester_id, price_per_credit) VALUES (:sid, 900000) ON DUPLICATE KEY UPDATE price_per_credit = 900000")->execute(['sid' => $s28SemId]);

// Hồ sơ học phí cho SV002 (student_id = 2): 10 tín chỉ x 900.000 = 9.000.000 VND
$pdo->prepare("INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition) VALUES (2, :sid, 10, 900000, 9000000) ON DUPLICATE KEY UPDATE registered_credits = 10, gross_tuition = 9000000")->execute(['sid' => $s28SemId]);
$s28FrId = (int)$pdo->query("SELECT id FROM fee_records WHERE student_id = 2 AND semester_id = {$s28SemId}")->fetchColumn();

// Đăng nhập Sinh viên SV002 (Student A) và SV001 (Student B)
$clientA = new ApiTestClient();
$loginARes = $clientA->login('sv002', 'Student@002');
$clientB = new ApiTestClient();
$loginBRes = $clientB->login('sv001', 'Student@001');

it("Đăng nhập sinh viên sv002 và sv001 qua API thành công", 
    $loginARes['status'] === 200 && $loginBRes['status'] === 200
);

// 1. Tạo phiên thanh toán Mã QR hợp lệ
$reqId1 = 'e1111111-2222-3333-4444-000000000001';
$qrSessRes1 = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 3000000,
    'method'     => 'Mã QR',
    'request_id' => $reqId1
]);
$s1Data = $qrSessRes1['body']['data'] ?? [];
$s1Id = (int)($s1Data['session_id'] ?? 0);
$payload1 = (string)($s1Data['payload'] ?? '');
$parsedPayload = [];
if (str_starts_with($payload1, 'TUITION|')) {
    foreach (explode('|', substr($payload1, 8)) as $kv) {
        $p = explode('=', $kv, 2);
        if (count($p) === 2) $parsedPayload[$p[0]] = $p[1];
    }
}
$is5MinExpiry = !empty($s1Data['expires_at']) && abs(strtotime($s1Data['expires_at']) - (time() + 300)) <= 15;

it("Khởi tạo phiên thanh toán Mã QR hợp lệ trả về HTTP 201 với status='pending', expires_at 5 phút, payload chuẩn TUITION|... và simulation_enabled=true",
    $qrSessRes1['status'] === 201
    && $s1Id > 0
    && ($s1Data['status'] ?? '') === 'pending'
    && ($s1Data['amount'] ?? 0) === 3000000
    && ($s1Data['method'] ?? '') === 'Mã QR'
    && !empty($s1Data['payload'])
    && !empty($s1Data['qr_data'])
    && $is5MinExpiry
    && ($parsedPayload['SESSION'] ?? '') === (string)$s1Id
    && ($parsedPayload['FEE'] ?? '') === (string)$s28FrId
    && ($parsedPayload['AMT'] ?? '') === '3000000'
    && !empty($parsedPayload['EXP'])
    && ($s1Data['simulation_enabled'] ?? false) === true
);

// 1b. Tạo phiên mới cho cùng hồ sơ học phí hủy phiên pending cũ
$reqIdSecond = 'e1111111-2222-3333-4444-00000000001b';
$qrSessResSecond = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 1000000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdSecond
]);
$sSecondId = (int)($qrSessResSecond['body']['data']['session_id'] ?? 0);
$s1StatusAfterSecond = $pdo->query("SELECT status FROM payment_sessions WHERE id = {$s1Id}")->fetchColumn();
it("Khởi tạo phiên thanh toán mới cho cùng hồ sơ học phí tự động hủy phiên pending trước đó",
    $qrSessResSecond['status'] === 201
    && $sSecondId > 0
    && $sSecondId !== $s1Id
    && $s1StatusAfterSecond === 'cancelled'
);

// 2. Từ chối số tiền vượt quá công nợ còn lại
$qrOverRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 9000001,
    'method'     => 'Mã QR',
    'request_id' => 'e1111111-2222-3333-4444-000000000002'
]);
it("Khởi tạo phiên QR với số tiền vượt quá công nợ còn lại bị từ chối với HTTP 422 OVERPAYMENT_REJECTED",
    $qrOverRes['status'] === 422
    && ($qrOverRes['body']['error']['code'] ?? '') === 'OVERPAYMENT_REJECTED'
);

// 3. Từ chối phương thức khác 'Mã QR'
$qrBadMethodRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 1000000,
    'method'     => 'Chuyển khoản',
    'request_id' => 'e1111111-2222-3333-4444-000000000003'
]);
it("Khởi tạo phiên thanh toán với phương thức khác 'Mã QR' bị từ chối với HTTP 400",
    $qrBadMethodRes['status'] === 400
    && !empty($qrBadMethodRes['body']['error']['fields']['method'])
);

// 4. Sinh viên B không thể tạo phiên cho hồ sơ của Sinh viên A
$qrCrossRes = $clientB->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 1000000,
    'method'     => 'Mã QR',
    'request_id' => 'e1111111-2222-3333-4444-000000000004'
]);
it("Sinh viên B không thể tạo phiên thanh toán cho hồ sơ của Sinh viên A (HTTP 404 cô lập)",
    $qrCrossRes['status'] === 404
);

// 5. Gửi lại cùng request_id trả về phiên cũ (Idempotency)
$qrDupRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 3000000,
    'method'     => 'Mã QR',
    'request_id' => $reqId1
]);
it("Gửi lại cùng request_id tạo phiên QR trả về HTTP 200 và cùng session_id cũ (Idempotency)",
    $qrDupRes['status'] === 200
    && (int)($qrDupRes['body']['data']['session_id'] ?? 0) === $s1Id
);

// 6. Tra cứu phiên thanh toán của chính mình
$qrGetRes = $clientA->request('GET', "/student/payment-sessions/{$sSecondId}");
it("Tra cứu phiên thanh toán QR của chính mình thành công với HTTP 200 và remaining_seconds > 0",
    $qrGetRes['status'] === 200
    && ($qrGetRes['body']['data']['status'] ?? '') === 'pending'
    && ($qrGetRes['body']['data']['remaining_seconds'] ?? 0) > 0
    && ($qrGetRes['body']['data']['simulation_enabled'] ?? false) === true
);

// 7. Sinh viên B tra cứu phiên QR của Sinh viên A trả về HTTP 404
$qrCrossGetRes = $clientB->request('GET', "/student/payment-sessions/{$sSecondId}");
it("Sinh viên B tra cứu phiên QR của Sinh viên A trả về HTTP 404",
    $qrCrossGetRes['status'] === 404
);

// 8. Phiên hết hạn tự động chuyển trạng thái 'expired'
$pdo->prepare("UPDATE payment_sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id")->execute(['id' => $sSecondId]);
$qrExpGetRes = $clientA->request('GET', "/student/payment-sessions/{$sSecondId}");
it("Phiên thanh toán quá hạn (expires_at <= NOW) tự động cập nhật trạng thái 'expired'",
    $qrExpGetRes['status'] === 200
    && ($qrExpGetRes['body']['data']['status'] ?? '') === 'expired'
    && ($qrExpGetRes['body']['data']['remaining_seconds'] ?? -1) === 0
);

// 9. Hủy phiên thanh toán (POST .../cancel)
$reqIdCancel = 'e1111111-2222-3333-4444-000000000005';
$qrSessResCancel = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 1000000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdCancel
]);
$sCancelId = (int)($qrSessResCancel['body']['data']['session_id'] ?? 0);
$cancelRes = $clientA->request('POST', "/student/payment-sessions/{$sCancelId}/cancel");
$checkCancelled = $pdo->query("SELECT status FROM payment_sessions WHERE id = {$sCancelId}")->fetchColumn();
it("Hủy phiên thanh toán đang chờ (pending) thành công với HTTP 200 và chuyển status thành 'cancelled'",
    $cancelRes['status'] === 200
    && $checkCancelled === 'cancelled'
);

// 10. Mô phỏng xác nhận thanh toán QR thành công (POST .../simulate-confirm)
$reqIdSim = 'e1111111-2222-3333-4444-000000000006';
$qrSessSimRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 2000000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdSim
]);
$sSimId = (int)($qrSessSimRes['body']['data']['session_id'] ?? 0);
$leBeforeSim = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE fee_record_id = {$s28FrId}")->fetchColumn();

$simConfirmRes = $clientA->request('POST', "/student/payment-sessions/{$sSimId}/simulate-confirm");
$simEntry = $simConfirmRes['body']['data']['ledger_entry'] ?? [];
$leAfterSim = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE fee_record_id = {$s28FrId}")->fetchColumn();
$sessStatusAfter = $pdo->query("SELECT status FROM payment_sessions WHERE id = {$sSimId}")->fetchColumn();

it("Mô phỏng xác nhận thanh toán QR thành công (HTTP 200), ghi sổ cái phương thức 'Mã QR', sinh biên lai PT-*, và đổi status='confirmed'",
    $simConfirmRes['status'] === 200
    && $leAfterSim === $leBeforeSim + 1
    && $sessStatusAfter === 'confirmed'
    && ($simEntry['method'] ?? '') === 'Mã QR'
    && str_starts_with($simEntry['receipt_no'] ?? '', 'PT-')
    && (int)($simEntry['amount'] ?? 0) === 2000000
);

// 11. Gọi lại simulate-confirm cho phiên đã confirmed trả về HTTP 409
$simDupRes = $clientA->request('POST', "/student/payment-sessions/{$sSimId}/simulate-confirm");
$leCountDup = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE fee_record_id = {$s28FrId}")->fetchColumn();
it("Gọi lại simulate-confirm cho phiên đã confirmed trả về HTTP 409 ALREADY_CONFIRMED",
    $simDupRes['status'] === 409
    && ($simDupRes['body']['error']['code'] ?? '') === 'ALREADY_CONFIRMED'
    && $leCountDup === $leAfterSim
);

// 11b. Gọi simulate-confirm cho phiên đã hết hạn trả về HTTP 422 và không ghi sổ cái
$reqIdExp = 'e1111111-2222-3333-4444-00000000001e';
$qrExpSess = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 1000000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdExp
]);
$sExpId = (int)($qrExpSess['body']['data']['session_id'] ?? 0);
$pdo->prepare("UPDATE payment_sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id")->execute(['id' => $sExpId]);
$leBeforeExpConfirm = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE fee_record_id = {$s28FrId}")->fetchColumn();
$confirmExpRes = $clientA->request('POST', "/student/payment-sessions/{$sExpId}/simulate-confirm");
$leAfterExpConfirm = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE fee_record_id = {$s28FrId}")->fetchColumn();
it("Gọi simulate-confirm cho phiên đã quá hạn trả về HTTP 422 SESSION_EXPIRED và không ghi sổ cái",
    $confirmExpRes['status'] === 422
    && ($confirmExpRes['body']['error']['code'] ?? '') === 'SESSION_EXPIRED'
    && $leAfterExpConfirm === $leBeforeExpConfirm
);

// 12. Không thể hủy phiên đã confirmed
$cancelConfirmedRes = $clientA->request('POST', "/student/payment-sessions/{$sSimId}/cancel");
it("Không thể hủy phiên thanh toán đã hoàn thành (confirmed) với HTTP 422 CANNOT_CANCEL",
    $cancelConfirmedRes['status'] === 422
    && ($cancelConfirmedRes['body']['error']['code'] ?? '') === 'CANNOT_CANCEL'
);

// 13. Công nợ thay đổi trước khi xác nhận -> simulate-confirm bị từ chối 422 và đổi phiên sang cancelled
$reqIdRace = 'e1111111-2222-3333-4444-000000000007';
$qrRaceSess = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 5000000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdRace
]);
$sRaceId = (int)($qrRaceSess['body']['data']['session_id'] ?? 0);

$adminClient = new ApiTestClient();
$adminClient->login('admin', 'Admin@123');
$adminPayRes = $adminClient->request('POST', "/fee-records/{$s28FrId}/payments", [
    'amount'     => 6000000,
    'method'     => 'Tiền mặt',
    'request_id' => 'e1111111-2222-3333-4444-000000000008',
    'note'       => 'Admin thu tiền mặt trực tiếp'
]);

$confirmRaceRes = $clientA->request('POST', "/student/payment-sessions/{$sRaceId}/simulate-confirm");
$sessRaceStatus = $pdo->query("SELECT status FROM payment_sessions WHERE id = {$sRaceId}")->fetchColumn();
it("Khi công nợ giảm trước khi xác nhận khiến số tiền phiên vượt nợ, simulate-confirm bị từ chối HTTP 422 và đổi status sang 'cancelled'",
    $confirmRaceRes['status'] === 422
    && $sessRaceStatus === 'cancelled'
);

// 14. Vô hiệu hóa simulation (simulation_enabled = false) -> simulate-confirm trả về HTTP 404
$reqIdNoSim = 'e1111111-2222-3333-4444-000000000009';
$qrNoSimSess = $clientA->request('POST', "/student/fee-records/{$s28FrId}/payment-sessions", [
    'amount'     => 500000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdNoSim
]);
$sNoSimId = (int)($qrNoSimSess['body']['data']['session_id'] ?? 0);
$disabledSimRes = $clientA->request('POST', "/student/payment-sessions/{$sNoSimId}/simulate-confirm", [], ['X-Simulation-Enabled' => '0']);
it("Khi simulation bị vô hiệu hóa (simulation_enabled = false), simulate-confirm trả về HTTP 404",
    $disabledSimRes['status'] === 404
);

// 15. Thẻ thử nghiệm chấp thuận (4111 1111 1111 1111)
$reqIdCard1 = 'e1111111-2222-3333-4444-000000000010';
$cardApproveRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 500000,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => $reqIdCard1
]);
$cardApproveEntry = $cardApproveRes['body']['data'] ?? [];
it("Thẻ thử nghiệm chấp thuận (4111 1111 1111 1111) thanh toán thành công (HTTP 201), method='Thẻ Visa/Mastercard', note chứa brand Visa và last4 1111",
    $cardApproveRes['status'] === 201
    && ($cardApproveEntry['method'] ?? '') === 'Thẻ Visa/Mastercard'
    && str_contains($cardApproveEntry['note'] ?? '', 'Visa')
    && str_contains($cardApproveEntry['note'] ?? '', '1111')
    && str_starts_with($cardApproveEntry['receipt_no'] ?? '', 'PT-')
);

// 16. Thẻ thử nghiệm bị từ chối (5555 5555 5555 4444)
$leBeforeDecline = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
$cardDeclineRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '5555 5555 5555 4444',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '456',
    'request_id'      => 'e1111111-2222-3333-4444-000000000011'
]);
$leAfterDecline = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
it("Thẻ thử nghiệm bị từ chối (5555 5555 5555 4444) trả về HTTP 422 CARD_DECLINED với đúng thông báo quy định và không ghi dòng sổ cái",
    $cardDeclineRes['status'] === 422
    && ($cardDeclineRes['body']['error']['code'] ?? '') === 'CARD_DECLINED'
    && ($cardDeclineRes['body']['error']['message'] ?? '') === 'Thẻ bị từ chối. Vui lòng kiểm tra thông tin hoặc dùng thẻ khác.'
    && $leAfterDecline === $leBeforeDecline
);

// 17. Thẻ thử nghiệm timeout (4000 0000 0000 0002)
$cardTimeoutRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '4000 0000 0000 0002',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '789',
    'request_id'      => 'e1111111-2222-3333-4444-000000000012'
]);
$leAfterTimeout = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();
it("Thẻ thử nghiệm timeout (4000 0000 0000 0002) trả về HTTP 502 GATEWAY_TIMEOUT với đúng thông báo quy định và không ghi dòng sổ cái",
    $cardTimeoutRes['status'] === 502
    && ($cardTimeoutRes['body']['error']['code'] ?? '') === 'GATEWAY_TIMEOUT'
    && ($cardTimeoutRes['body']['error']['message'] ?? '') === 'Thanh toán chưa được xử lý. Vui lòng thử lại sau.'
    && $leAfterTimeout === $leBeforeDecline
);

// 18. Thẻ chuẩn Luhn khác ngoài danh sách thử nghiệm bị từ chối
$cardOtherLuhnRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '5105 1051 0510 5100',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '321',
    'request_id'      => 'e1111111-2222-3333-4444-000000000013'
]);
it("Thẻ hợp lệ chuẩn Luhn ngoài danh sách thử nghiệm bị từ chối với HTTP 422 CARD_DECLINED",
    $cardOtherLuhnRes['status'] === 422
    && ($cardOtherLuhnRes['body']['error']['code'] ?? '') === 'CARD_DECLINED'
);

// 19. Dữ liệu thẻ không hợp lệ trả về HTTP 400 kèm chi tiết từng trường
$cardBadDataRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '4111 1111 1111 1112',
    'cardholder_name' => 'NGUYEN 123 @#$',
    'expiry'          => '01/20',
    'cvv'             => '12',
    'request_id'      => 'e1111111-2222-3333-4444-000000000014'
]);
$badFields = $cardBadDataRes['body']['error']['fields'] ?? [];
it("Dữ liệu thẻ không hợp lệ (sai Luhn, tên chứa ký tự đặc biệt, hết hạn, CVV ngắn) bị từ chối HTTP 400 kèm lỗi từng trường",
    $cardBadDataRes['status'] === 400
    && isset($badFields['card_number'])
    && isset($badFields['cardholder_name'])
    && isset($badFields['expiry'])
    && isset($badFields['cvv'])
);

// 20. Sinh viên B không thể thanh toán thẻ cho hồ sơ của Sinh viên A
$cardCrossRes = $clientB->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'NGUYEN VAN AN',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => 'e1111111-2222-3333-4444-000000000015'
]);
it("Sinh viên B không thể thanh toán thẻ cho hồ sơ của Sinh viên A (HTTP 404 cô lập)",
    $cardCrossRes['status'] === 404
);

// 21. Thanh toán thẻ vượt quá công nợ còn lại (balance + 1)
$cardOverRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 500001,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => 'e1111111-2222-3333-4444-000000000016'
]);
it("Thanh toán thẻ vượt quá công nợ còn lại (balance + 1) bị từ chối với HTTP 422 OVERPAYMENT_REJECTED",
    $cardOverRes['status'] === 422
    && ($cardOverRes['body']['error']['code'] ?? '') === 'OVERPAYMENT_REJECTED'
);

// 22. Idempotency của thanh toán thẻ với cùng request_id
$cardDupRes = $clientA->request('POST', "/student/fee-records/{$s28FrId}/card-payments", [
    'amount'          => 500000,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => $reqIdCard1
]);
it("Gửi lại thanh toán thẻ với cùng request_id trả về HTTP 200 và cùng bản ghi cũ (Idempotency)",
    $cardDupRes['status'] === 200
    && (int)($cardDupRes['body']['data']['id'] ?? 0) === (int)($cardApproveEntry['id'] ?? -1)
);

// 23. Kiểm tra an toàn bảo mật thẻ (Zero Sensitive Data in DB)
$rawCardsToSearch = ['4111111111111111', '4111 1111 1111 1111', '5555555555554444', '4000000000000002'];
$foundLeakInDb = false;

$leRows = $pdo->query("SELECT note, reason, gateway_ref FROM ledger_entries")->fetchAll();
foreach ($leRows as $r) {
    foreach ($rawCardsToSearch as $card) {
        if (str_contains((string)$r['note'], $card) || str_contains((string)$r['reason'], $card) || str_contains((string)$r['gateway_ref'], $card)) {
            $foundLeakInDb = true;
            break 2;
        }
    }
}

$auditRows = $pdo->query("SELECT payload FROM audit_log")->fetchAll();
foreach ($auditRows as $r) {
    foreach ($rawCardsToSearch as $card) {
        if (str_contains((string)$r['payload'], $card)) {
            $foundLeakInDb = true;
            break 2;
        }
    }
}

$psRows = $pdo->query("SELECT method, request_id FROM payment_sessions")->fetchAll();
foreach ($psRows as $r) {
    foreach ($rawCardsToSearch as $card) {
        if (str_contains((string)$r['method'], $card) || str_contains((string)$r['request_id'], $card)) {
            $foundLeakInDb = true;
            break 2;
        }
    }
}

it("Kiểm tra an toàn dữ liệu thẻ: Số thẻ đầy đủ, CVV, hạn thẻ tuyệt đối KHÔNG lưu trong cơ sở dữ liệu (ledger_entries, audit_log, payment_sessions)",
    !$foundLeakInDb
);

// ============================================================================
// SUITE 29: Tìm kiếm Sinh viên & Học kỳ khi Tạo Hồ sơ Học phí (Issue 2)
// ============================================================================
echo "\n" . COLOR_CYAN . COLOR_BOLD . "▶ 29. Kiểm thử Tìm kiếm Sinh viên & Học kỳ khi Tạo Hồ sơ (Issue 2)" . COLOR_RESET . "\n";

$adm = new ApiTestClient();
$adm->login('admin', 'Admin@123');

// 1. Tìm kiếm sinh viên với từ khóa không dấu "nguyen" trả về SV001 và SV005
$stSearchRes1 = $adm->request('GET', '/students/search?q=nguyen');
$stList1 = $stSearchRes1['body']['data'] ?? [];
$stCodes1 = array_column($stList1, 'student_code');
it("Tìm kiếm sinh viên với từ khóa 'nguyen' trả về HTTP 200, gồm SV001, SV005 và các sinh viên mới họ Nguyễn",
    $stSearchRes1['status'] === 200
    && in_array('SV001', $stCodes1, true)
    && in_array('SV005', $stCodes1, true)
    && count($stList1) === 5
    && isset($stList1[0]['id'], $stList1[0]['full_name'], $stList1[0]['class_name'])
);

// 2. Tìm kiếm sinh viên với từ khóa có dấu "Nguyễn" trả về cùng kết quả
$stSearchRes2 = $adm->request('GET', '/students/search?q=' . urlencode('Nguyễn'));
$stList2 = $stSearchRes2['body']['data'] ?? [];
$stCodes2 = array_column($stList2, 'student_code');
it("Tìm kiếm sinh viên với từ khóa 'Nguyễn' (có dấu) trả về cùng kết quả gồm SV001 và SV005",
    $stSearchRes2['status'] === 200
    && in_array('SV001', $stCodes2, true)
    && in_array('SV005', $stCodes2, true)
    && count($stList2) === 5
);

// 3. Tìm kiếm học kỳ với từ khóa "HK1" trả về các học kỳ 1
$semSearchHk1 = $adm->request('GET', '/semesters/search?q=HK1');
$semListHk1 = $semSearchHk1['body']['data'] ?? [];
$allAreTerm1 = count($semListHk1) > 0;
foreach ($semListHk1 as $s) {
    if ((int)($s['term_no'] ?? 0) !== 1) {
        $allAreTerm1 = false;
        break;
    }
}
it("Tìm kiếm học kỳ với từ khóa 'HK1' trả về đúng các học kỳ 1",
    $semSearchHk1['status'] === 200
    && $allAreTerm1
    && count($semListHk1) >= 2
    && isset($semListHk1[0]['id'], $semListHk1[0]['label'])
);

// 4. Tìm kiếm học kỳ với từ khóa "2026" trả về các học kỳ liên quan đến năm 2026
$semSearch2026 = $adm->request('GET', '/semesters/search?q=2026');
$semList2026 = $semSearch2026['body']['data'] ?? [];
$allContain2026 = count($semList2026) > 0;
foreach ($semList2026 as $s) {
    $lbl = (string)($s['label'] ?? '');
    $yr = (int)($s['academic_year_start'] ?? 0);
    if (!str_contains($lbl, '2026') && $yr !== 2026 && ($yr + 1) !== 2026) {
        $allContain2026 = false;
        break;
    }
}
it("Tìm kiếm học kỳ với từ khóa '2026' trả về đúng các học kỳ liên quan năm 2026",
    $semSearch2026['status'] === 200
    && $allContain2026
    && count($semList2026) >= 2
);

// 5. Tìm kiếm học kỳ với từ khóa "Học kỳ 2" chỉ trả về bản ghi HK2
$semSearchHk2 = $adm->request('GET', '/semesters/search?q=' . urlencode('Học kỳ 2'));
$semListHk2 = $semSearchHk2['body']['data'] ?? [];
$allAreTerm2 = count($semListHk2) > 0;
foreach ($semListHk2 as $s) {
    if ((int)($s['term_no'] ?? 0) !== 2) {
        $allAreTerm2 = false;
        break;
    }
}
it("Tìm kiếm học kỳ với từ khóa 'Học kỳ 2' chỉ trả về các bản ghi HK2",
    $semSearchHk2['status'] === 200
    && $allAreTerm2
    && count($semListHk2) === 2
);

// 6. Truy vấn tìm kiếm rỗng trả về mảng data rỗng và thông điệp trống
$stEmptySearch = $adm->request('GET', '/students/search?q=');
$semEmptySearch = $adm->request('GET', '/semesters/search?q=');
it("Truy vấn tìm kiếm sinh viên và học kỳ rỗng trả về data rỗng và thông điệp trống",
    $stEmptySearch['status'] === 200
    && ($stEmptySearch['body']['data'] ?? null) === []
    && ($stEmptySearch['body']['message'] ?? '') === 'Nhập mã hoặc tên để tìm sinh viên.'
    && $semEmptySearch['status'] === 200
    && ($semEmptySearch['body']['data'] ?? null) === []
);

// 7. Truy vấn quá 100 ký tự (101 ký tự) bị từ chối với HTTP 400 kèm lỗi trường
$longQ = str_repeat('a', 101);
$stLongSearch = $adm->request('GET', '/students/search?q=' . $longQ);
$semLongSearch = $adm->request('GET', '/semesters/search?q=' . $longQ);
it("Truy vấn tìm kiếm dài hơn 100 ký tự bị từ chối với HTTP 400 và trường error.fields.q",
    $stLongSearch['status'] === 400
    && !empty($stLongSearch['body']['error']['fields']['q'])
    && $semLongSearch['status'] === 400
    && !empty($semLongSearch['body']['error']['fields']['q'])
);

// 8. Kết quả tìm kiếm sinh viên bị giới hạn tối đa 20 kết quả (Cap at 20)
$pdo->beginTransaction();
for ($i = 100; $i < 130; $i++) {
    $pdo->prepare("INSERT INTO students (student_code, full_name, name_search, class_name) VALUES (:c, :n, 'test cap', 'K24')")
        ->execute(['c' => "SVCAP{$i}", 'n' => "Sinh viên Cap {$i}"]);
}
$pdo->commit();
$capSearch = $adm->request('GET', '/students/search?q=SVCAP');
$pdo->prepare("DELETE FROM students WHERE student_code LIKE 'SVCAP%'")->execute();
it("Kết quả tìm kiếm sinh viên được giới hạn tối đa 20 bản ghi",
    $capSearch['status'] === 200
    && count($capSearch['body']['data'] ?? []) === 20
);

// 9. Tài khoản sinh viên hoặc chưa đăng nhập không có quyền truy cập endpoint tìm kiếm (HTTP 403 / 401)
$nonAdminSearch = $clientA->request('GET', '/students/search?q=nguyen');
$guestSearch = (new ApiTestClient())->request('GET', '/students/search?q=nguyen');
it("Tài khoản sinh viên bị từ chối truy cập endpoint tìm kiếm với HTTP 403, khách vãng lai bị từ chối với HTTP 401",
    $nonAdminSearch['status'] === 403
    && $guestSearch['status'] === 401
);

// 10. Tạo hồ sơ học phí với student_id không tồn tại bị từ chối HTTP 404 và có trường lỗi
$frCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
$fakeStCreate = $adm->request('POST', '/fee-records', [
    'student_id'         => 999999,
    'semester_id'        => 3,
    'registered_credits' => 15
]);
$frCountAfter1 = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
it("Tạo hồ sơ học phí với student_id không tồn tại bị từ chối HTTP 404 kèm trường lỗi và không ghi dòng",
    $fakeStCreate['status'] === 404
    && !empty($fakeStCreate['body']['error']['fields']['student_id'])
    && $frCountAfter1 === $frCountBefore
);

// 11. Tạo hồ sơ học phí với semester_id không tồn tại bị từ chối HTTP 404 và có trường lỗi
$fakeSemCreate = $adm->request('POST', '/fee-records', [
    'student_id'         => 1,
    'semester_id'        => 999999,
    'registered_credits' => 15
]);
$frCountAfter2 = (int)$pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
it("Tạo hồ sơ học phí với semester_id không tồn tại bị từ chối HTTP 404 kèm trường lỗi và không ghi dòng",
    $fakeSemCreate['status'] === 404
    && !empty($fakeSemCreate['body']['error']['fields']['semester_id'])
    && $frCountAfter2 === $frCountBefore
);

// ============================================================================
// SUITE 30: Hóa đơn Thanh toán Học phí Sinh viên (Part 1)
// ============================================================================
echo "\n" . COLOR_CYAN . COLOR_BOLD . "▶ 30. Kiểm thử Hóa đơn Thanh toán Học phí Sinh viên (Part 1)" . COLOR_RESET . "\n";

// 1. Sinh viên hoàn thành thanh toán QR tạo chính xác 1 hóa đơn với định dạng HD-YYYYMMDD-NNNN
$stClient = new ApiTestClient();
$stLoginRes = $stClient->login('sv002', 'Student@002');
$stId2 = (int)($stLoginRes['body']['data']['user']['student_id'] ?? 2);

$s30FrId = 2; // SV002 HK1 2026-2027 có nợ 4.650.000đ
$reqIdQr1 = 's30-qr-' . uniqid();

$qrSessionRes = $stClient->request('POST', "/student/fee-records/{$s30FrId}/payment-sessions", [
    'amount'     => 500000,
    'method'     => 'Mã QR',
    'request_id' => $reqIdQr1
]);
$qrSessionId = (int)($qrSessionRes['body']['data']['session_id'] ?? 0);

$qrSimRes = $stClient->request('POST', "/student/payment-sessions/{$qrSessionId}/simulate-confirm", []);
$qrLedgerId = (int)($qrSimRes['body']['data']['ledger_entry']['id'] ?? 0);

$qrBillStmt = $pdo->prepare("SELECT * FROM bills WHERE ledger_entry_id = :lid");
$qrBillStmt->execute(['lid' => $qrLedgerId]);
$qrBill = $qrBillStmt->fetch();

it("Sinh viên hoàn thành thanh toán QR tạo chính xác 1 hóa đơn với mã định dạng HD-YYYYMMDD-NNNN",
    $qrSimRes['status'] === 200
    && $qrLedgerId > 0
    && !empty($qrBill)
    && preg_match('/^HD-\d{8}-\d{4}$/', (string)$qrBill['bill_no']) === 1
);

// 2. Sinh viên thanh toán thẻ tạo chính xác 1 hóa đơn
$cardPayReqId = 's30-card-' . uniqid();
$cardPayRes = $stClient->request('POST', "/student/fee-records/{$s30FrId}/card-payments", [
    'amount'          => 200000,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => $cardPayReqId
]);
$cardLedgerId = (int)($cardPayRes['body']['data']['id'] ?? 0);
$cardBillStmt = $pdo->prepare("SELECT * FROM bills WHERE ledger_entry_id = :lid");
$cardBillStmt->execute(['lid' => $cardLedgerId]);
$cardBill = $cardBillStmt->fetch();

it("Sinh viên hoàn thành thanh toán thẻ tạo chính xác 1 hóa đơn với mã định dạng HD-YYYYMMDD-NNNN",
    $cardPayRes['status'] === 201
    && $cardLedgerId > 0
    && !empty($cardBill)
    && preg_match('/^HD-\d{8}-\d{4}$/', (string)$cardBill['bill_no']) === 1
);

// 3. Giao dịch thanh toán thẻ thất bại / bị từ chối KHÔNG tạo hóa đơn
$billsCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();
$failCardRes = $stClient->request('POST', "/student/fee-records/{$s30FrId}/card-payments", [
    'amount'          => 100000,
    'card_number'     => '5555 5555 5555 4444',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => 's30-card-fail-' . uniqid()
]);
$billsCountAfterFail = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();

it("Giao dịch thanh toán thẻ thất bại / bị từ chối trả về HTTP 422 và KHÔNG tạo hóa đơn",
    $failCardRes['status'] === 422
    && $billsCountAfterFail === $billsCountBefore
);

// 4. Gửi trùng request_id không tạo thêm hóa đơn thứ hai (Idempotency)
$dupCardRes = $stClient->request('POST', "/student/fee-records/{$s30FrId}/card-payments", [
    'amount'          => 200000,
    'card_number'     => '4111 1111 1111 1111',
    'cardholder_name' => 'TRAN THI BINH',
    'expiry'          => '12/28',
    'cvv'             => '123',
    'request_id'      => $cardPayReqId
]);
$dupBillsCount = (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE ledger_entry_id = {$cardLedgerId}")->fetchColumn();

it("Gửi trùng request_id trả về HTTP 200 và giữ nguyên chính xác 1 hóa đơn duy nhất (Idempotency)",
    $dupCardRes['status'] === 200
    && $dupBillsCount === 1
);

// 5. Giá trị snapshot khớp tuyệt đối với sổ cái: total_paid_after = total_paid_before + amount
$snap = json_decode((string)$cardBill['snapshot'], true);
$paidBefore = (int)($snap['fee_record']['total_paid_before'] ?? $snap['total_paid_before'] ?? -1);
$amtPaid    = (int)($snap['fee_record']['amount_paid'] ?? $snap['amount'] ?? -1);
$paidAfter  = (int)($snap['fee_record']['total_paid_after'] ?? $snap['total_paid_after'] ?? -2);
$balAfter   = (int)($snap['fee_record']['balance_after'] ?? $snap['remaining_balance'] ?? -1);
$netAmount  = (int)($snap['fee_record']['net_amount'] ?? $snap['net_due'] ?? -1);

it("Giá trị snapshot của hóa đơn khớp tuyệt đối với công thức sổ cái (total_paid_after = total_paid_before + amount)",
    $paidAfter === ($paidBefore + $amtPaid)
    && $balAfter === ($netAmount - $paidAfter)
    && ($snap['gateway_brand'] ?? $snap['payment']['card_brand'] ?? '') === 'Visa'
    && ($snap['gateway_last4'] ?? $snap['payment']['card_last4'] ?? '') === '1111'
);

// 6. Endpoint GET /api/student/bills/{ledgerEntryId} trả về dữ liệu chuẩn JSON
$getBillRes = $stClient->request('GET', "/student/bills/{$cardLedgerId}");
it("Endpoint GET /api/student/bills/{id} trả về HTTP 200 kèm đúng dữ liệu JSON của hóa đơn",
    $getBillRes['status'] === 200
    && ($getBillRes['body']['data']['bill_no'] ?? '') === $cardBill['bill_no']
    && !empty($getBillRes['body']['data']['snapshot'])
);

// 7. Endpoint GET /api/student/bills/{ledgerEntryId}/download trả về Content-Disposition attachment và HTML an toàn
$dlBillRes = $stClient->request('GET', "/student/bills/{$cardLedgerId}/download");
$dlCdHeader = $dlBillRes['headers']['content-disposition'] ?? '';
$dlRawHtml = $dlBillRes['raw'] ?? '';
it("Endpoint GET /api/student/bills/{id}/download trả về file đính kèm với Content-Disposition và HTML không chứa script",
    $dlBillRes['status'] === 200
    && str_contains($dlCdHeader, 'attachment;')
    && str_contains($dlCdHeader, 'filename="bill-' . $cardBill['bill_no'] . '.html"')
    && !str_contains(strtolower($dlRawHtml), '<script')
    && str_contains($dlRawHtml, $cardBill['bill_no'])
);

// 8. Sinh viên A truy cập hóa đơn của sinh viên B bị trả về HTTP 404 (cô lập dữ liệu)
$otherStudentClient = new ApiTestClient();
$otherStudentClient->login('sv001', 'Student@001');
$crossBillGet = $otherStudentClient->request('GET', "/student/bills/{$cardLedgerId}");
$crossBillDl  = $otherStudentClient->request('GET', "/student/bills/{$cardLedgerId}/download");

it("Sinh viên A truy cập hóa đơn của sinh viên B bị từ chối với HTTP 404 (cả JSON và tải về)",
    $crossBillGet['status'] === 404
    && $crossBillDl['status'] === 404
);

// 9. Quét toàn bộ nội dung HTML tải về khẳng định không xuất hiện số thẻ đầy đủ
$fullPanLeaks = ['4111 1111 1111 1111', '4111111111111111'];
$hasPanLeak = false;
foreach ($fullPanLeaks as $pan) {
    if (str_contains($dlRawHtml, $pan)) {
        $hasPanLeak = true;
        break;
    }
}
it("Quét toàn bộ HTML hóa đơn: Tuyệt đối không xuất hiện số thẻ đầy đủ (chỉ hiển thị brand và last4)",
    !$hasPanLeak && str_contains($dlRawHtml, 'Visa') && str_contains($dlRawHtml, '1111')
);

// ============================================================================
// SUITE 31: Quản lý Phiên làm việc & Đăng xuất khi Đóng Tab (Part 2)
// ============================================================================
echo "\n" . COLOR_CYAN . COLOR_BOLD . "▶ 31. Kiểm thử Quản lý Phiên làm việc & Đăng xuất khi Đóng Tab (Part 2)" . COLOR_RESET . "\n";

// Helper hàm chỉnh sửa timestamp session file
function modify_sess_timestamps(string $sessId, ?int $lastActivity = null, ?int $createdAt = null): bool {
    $sessFile = 'C:/xampp/tmp/sess_' . $sessId;
    if (!file_exists($sessFile)) {
        return false;
    }
    $content = file_get_contents($sessFile);
    if ($lastActivity !== null) {
        $content = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . $lastActivity . ';', $content);
    }
    if ($createdAt !== null) {
        $content = preg_replace('/created_at\|i:\d+;/', 'created_at|i:' . $createdAt . ';', $content);
    }
    return file_put_contents($sessFile, $content) !== false;
}

// 1. Idle timeout (30 phút không hoạt động) -> 401 SESSION_EXPIRED
$idleClient = new ApiTestClient();
$idleLogin = $idleClient->login('sv002', 'Student@002');
$idleSessId = $idleClient->getSessionId();
it("Đăng nhập thành công và lấy được mã phiên TUITION_SESSID",
    $idleLogin['status'] === 200 && !empty($idleSessId)
);

// Sửa last_activity lùi về 31 phút trước (1860 giây)
modify_sess_timestamps($idleSessId, time() - 1860, null);
$idleExpiredRes = $idleClient->request('GET', '/me');
$idleSubsequentRes = $idleClient->request('GET', '/me');
it("Phiên quá hạn idle timeout (30 phút) bị hủy và trả về HTTP 401 SESSION_EXPIRED trên request tiếp theo",
    $idleExpiredRes['status'] === 401
    && ($idleExpiredRes['body']['error']['code'] ?? '') === 'SESSION_EXPIRED'
    && $idleSubsequentRes['status'] === 401
);

// 2. Absolute lifetime (8 giờ tối đa) -> 401 SESSION_MAX_LIFETIME
$absClient = new ApiTestClient();
$absLogin = $absClient->login('sv002', 'Student@002');
$absSessId = $absClient->getSessionId();
// Sửa created_at lùi về 8 giờ 1 phút trước (28860 giây)
modify_sess_timestamps($absSessId, time(), time() - 28860);
$absExpiredRes = $absClient->request('GET', '/me');
it("Phiên quá hạn absolute lifetime (8 giờ) bị hủy và trả về HTTP 401 SESSION_MAX_LIFETIME",
    $absExpiredRes['status'] === 401
    && ($absExpiredRes['body']['error']['code'] ?? '') === 'SESSION_MAX_LIFETIME'
);

// 3. POST /api/logout-beacon với logout_token hợp lệ hủy phiên
$beaconClient = new ApiTestClient();
$bLogin = $beaconClient->login('sv002', 'Student@002');
$meRes = $beaconClient->request('GET', '/me');
$logoutToken = $meRes['body']['data']['logout_token'] ?? '';
it("GET /api/me trả về logout_token dành riêng cho beacon trong bộ nhớ",
    !empty($logoutToken) && strlen($logoutToken) >= 32
);

$beaconRes = $beaconClient->request('POST', '/logout-beacon', [
    'logout_token' => $logoutToken
]);
$afterBeaconMe = $beaconClient->request('GET', '/me');
it("Gửi logout beacon với token hợp lệ trả về HTTP 204 và hủy phiên đăng nhập ngay lập tức",
    $beaconRes['status'] === 204
    && $afterBeaconMe['status'] === 401
);

// 4. POST /api/logout-beacon với token sai KHÔNG hủy phiên (trả về 204 nhưng giữ phiên)
$wrongBeaconClient = new ApiTestClient();
$wbLogin = $wrongBeaconClient->login('sv002', 'Student@002');
$wrongBeaconRes = $wrongBeaconClient->request('POST', '/logout-beacon', [
    'logout_token' => 'incorrect_token_random_1234567890abcdef'
]);
$afterWrongBeaconMe = $wrongBeaconClient->request('GET', '/me');
it("Gửi logout beacon với token sai trả về HTTP 204 nhưng KHÔNG hủy phiên của người dùng",
    $wrongBeaconRes['status'] === 204
    && $afterWrongBeaconMe['status'] === 200
    && ($afterWrongBeaconMe['body']['data']['username'] ?? '') === 'sv002'
);

// 5. POST /api/logout-beacon từ chối trường lạ hoặc payload > 1024 bytes (HTTP 400)
$unknownFieldRes = $wrongBeaconClient->request('POST', '/logout-beacon', [
    'logout_token' => 'some_token',
    'attacker_injected_field' => 'malicious'
]);
$oversizedPayload = json_encode(['logout_token' => str_repeat('A', 1050)]);
$oversizedRes = $wrongBeaconClient->request('POST', '/logout-beacon', $oversizedPayload);

it("POST /api/logout-beacon từ chối payload có trường lạ hoặc vượt quá 1024 bytes với HTTP 400",
    $unknownFieldRes['status'] === 400
    && ($unknownFieldRes['body']['error']['code'] ?? '') === 'UNKNOWN_FIELDS'
    && $oversizedRes['status'] === 400
    && ($oversizedRes['body']['error']['code'] ?? '') === 'PAYLOAD_TOO_LARGE'
);

// 6. Endpoint /api/logout thông thường bắt buộc kiểm tra CSRF token
$csrfTestClient = new ApiTestClient();
$csrfTestClient->login('sv002', 'Student@002');
$noCsrfLogout = $csrfTestClient->request('POST', '/logout', [], ['X-CSRF-Token' => '']);
it("Endpoint POST /api/logout thông thường từ chối khi thiếu CSRF token (HTTP 403)",
    $noCsrfLogout['status'] === 403
);
$validCsrfLogout = $csrfTestClient->request('POST', '/logout', []);
it("Endpoint POST /api/logout thông thường với CSRF token hợp lệ trả về HTTP 200 và đăng xuất thành công",
    $validCsrfLogout['status'] === 200
);

// ============================================================================
// SUITE 32: Kiểm thử Dữ liệu Mẫu Mới (Part 3)
// ============================================================================
echo "\n" . COLOR_CYAN . COLOR_BOLD . "▶ 32. Kiểm thử Dữ liệu Mẫu Mới & Các Tình huống Thực tế (Part 3)" . COLOR_RESET . "\n";

// 1. Kiểm tra toàn bộ hồ sơ học phí (bao gồm SV014-SV024) khớp công thức tính chuẩn
$allFrs = $pdo->query(
    "SELECT fr.id, fr.registered_credits, fr.rate_snapshot, fr.gross_tuition,
            COALESCE((
                CASE 
                    WHEN ex.type = 'PERCENT' THEN FLOOR(fr.gross_tuition * ex.value / 100)
                    WHEN ex.type = 'FIXED' THEN LEAST(ex.value, fr.gross_tuition)
                    ELSE 0 
                END
            ), 0) AS expected_exemption,
            COALESCE((SELECT SUM(amount) FROM ledger_entries WHERE fee_record_id = fr.id), 0) AS actual_paid
     FROM fee_records fr
     LEFT JOIN exemptions ex ON fr.id = ex.fee_record_id"
)->fetchAll();

$allFormulasValid = true;
$mismatchDetails = [];
foreach ($allFrs as $r) {
    $expectedGross = (int)$r['registered_credits'] * (int)$r['rate_snapshot'];
    if ((int)$r['gross_tuition'] !== $expectedGross) {
        $allFormulasValid = false;
        $mismatchDetails[] = "FR #{$r['id']}: expected {$expectedGross}, got {$r['gross_tuition']}";
    }
}
it("Toàn bộ hồ sơ học phí mẫu (cũ và mới) tuân thủ chính xác công thức gross_tuition = registered_credits * rate_snapshot",
    $allFormulasValid && count($allFrs) >= 22
);

// 2. Toàn bộ hóa đơn mẫu (bills) có snapshot khớp hoàn toàn với ledger_entries và fee_records
$allBills = $pdo->query(
    "SELECT b.*, l.amount AS ledger_amount, l.method AS ledger_method, l.receipt_no AS ledger_receipt,
            fr.gross_tuition, fr.registered_credits, s.student_code
     FROM bills b
     JOIN ledger_entries l ON b.ledger_entry_id = l.id
     JOIN fee_records fr ON l.fee_record_id = fr.id
     JOIN students s ON fr.student_id = s.id"
)->fetchAll();

$allBillsMatch = true;
foreach ($allBills as $b) {
    $bsnap = json_decode((string)$b['snapshot'], true);
    if (!is_array($bsnap)) {
        $allBillsMatch = false;
        break;
    }
    $snapAmt = (int)($bsnap['amount'] ?? $bsnap['this_payment_amount'] ?? 0);
    $snapStudentCode = (string)($bsnap['student_code'] ?? ($bsnap['student']['student_code'] ?? ''));
    if ($snapAmt !== (int)$b['ledger_amount'] || $snapStudentCode !== (string)$b['student_code']) {
        $allBillsMatch = false;
        break;
    }
}
it("Toàn bộ các hóa đơn mẫu có snapshot khớp hoàn toàn với ledger_entries và students",
    $allBillsMatch && count($allBills) >= 7
);

// 3. Số biên lai (receipt_counters) và số hóa đơn (bill_counters) không có khoảng cách (gapless) trong từng ngày
$rcRows = $pdo->query("SELECT * FROM receipt_counters")->fetchAll();
$allReceiptsGapless = true;
foreach ($rcRows as $rc) {
    $day = $rc['day'];
    $lastNo = (int)$rc['last_no'];
    $dayStr = str_replace('-', '', $day);
    $rcs = $pdo->query("SELECT receipt_no FROM ledger_entries WHERE receipt_no LIKE 'PT-{$dayStr}-%' ORDER BY receipt_no ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (count($rcs) !== $lastNo) {
        $allReceiptsGapless = false;
        break;
    }
    for ($idx = 1; $idx <= $lastNo; $idx++) {
        $expectedRNo = sprintf('PT-%s-%04d', $dayStr, $idx);
        if ($rcs[$idx - 1] !== $expectedRNo) {
            $allReceiptsGapless = false;
            break 2;
        }
    }
}

$bcRows = $pdo->query("SELECT * FROM bill_counters")->fetchAll();
$allBillsGapless = true;
foreach ($bcRows as $bc) {
    $day = $bc['day'];
    $lastNo = (int)$bc['last_no'];
    $dayStr = str_replace('-', '', $day);
    $billsForDay = $pdo->query("SELECT bill_no FROM bills WHERE bill_no LIKE 'HD-{$dayStr}-%' ORDER BY bill_no ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (count($billsForDay) !== $lastNo) {
        $allBillsGapless = false;
        break;
    }
    for ($idx = 1; $idx <= $lastNo; $idx++) {
        $expectedBNo = sprintf('HD-%s-%04d', $dayStr, $idx);
        if ($billsForDay[$idx - 1] !== $expectedBNo) {
            $allBillsGapless = false;
            break 2;
        }
    }
}

it("Số biên lai (PT-) và số hóa đơn (HD-) được đánh số liên tục không có khoảng cách theo từng ngày",
    $allReceiptsGapless && $allBillsGapless
);

// 4. Tìm kiếm từ khóa 'Long' trả về cả hai sinh viên trùng tên 'Nguyễn Hoàng Long' (SV014 và SV015)
$admTest = new ApiTestClient();
$admTest->login('admin', 'Admin@123');
$longSearchRes = $admTest->request('GET', '/students/search?q=Long');
$longStudents = $longSearchRes['body']['data'] ?? [];
$longCodes = array_column($longStudents, 'student_code');

it("Tìm kiếm sinh viên với từ khóa 'Long' trả về cả 2 sinh viên trùng tên (SV014 và SV015)",
    $longSearchRes['status'] === 200
    && in_array('SV014', $longCodes, true)
    && in_array('SV015', $longCodes, true)
);

// 5. Kiểm tra chấp nhận tên sinh viên 100 ký tự và từ chối tên 101 ký tự
$sv017 = $pdo->query("SELECT * FROM students WHERE student_code = 'SV017'")->fetch();
$exact100NameLen = mb_strlen((string)($sv017['full_name'] ?? ''), 'UTF-8');

$name101 = str_repeat('A', 101);
$reject101Res = $admTest->request('GET', '/students/search?q=' . $name101);

it("Tên sinh viên SV017 đạt đúng 100 ký tự chuẩn UTF-8 và tìm kiếm vượt quá 100 ký tự (101 ký tự) bị từ chối với HTTP 400",
    $exact100NameLen === 100
    && $reject101Res['status'] === 400
);

// 6. Kiểm tra tính lặp lại (Repeatability): Nạp lại schema.sql, seed.sql, seed_users.php liên tiếp không lỗi
$repeatabilityOk = true;
try {
    reset_test_database($rootPdo);
    reset_test_database($rootPdo);
} catch (Throwable $t) {
    $repeatabilityOk = false;
}

$recountStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$recountBills = (int)$pdo->query("SELECT COUNT(*) FROM bills")->fetchColumn();

it("Nạp lại schema.sql, seed.sql, và seed_users.php hai lần liên tiếp chạy trơn tru, không lỗi và dữ liệu toàn vẹn",
    $repeatabilityOk
    && $recountStudents === 24
    && $recountBills === 7
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
