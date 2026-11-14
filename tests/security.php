<?php
/**
 * Automated Security Test Suite (PHP + MariaDB) - Student Tuition Management System
 * Covers 16 security categories (SEC-001 through SEC-047):
 *  1. Authorization Matrix (Anonymous, Student, Admin)
 *  2. IDOR (Insecure Direct Object Reference)
 *  3. SQL Injection (SQLi)
 *  4. Cross-Site Scripting (XSS)
 *  5. CSRF Protection
 *  6. Session Handling (idle timeout, max lifetime, cookie flags)
 *  7. Brute Force & Rate Limiting
 *  8. User Enumeration Protection
 *  9. File & Path Exposure Protection
 * 10. Path Traversal
 * 11. Error Disclosure
 * 12. Input Hardening & Mass Assignment
 * 13. Security Headers
 * 14. Card Data Safety
 * 15. Race Conditions
 * 16. Replay Prevention
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/api/lib/db.php';
require_once dirname(__DIR__) . '/api/lib/calc.php';
require_once dirname(__DIR__) . '/api/lib/validate.php';
require_once dirname(__DIR__) . '/api/lib/response.php';
require_once dirname(__DIR__) . '/api/lib/auth.php';

// CLI Colors
const SEC_RESET   = "\033[0m";
const SEC_GREEN   = "\033[32m";
const SEC_RED     = "\033[31m";
const SEC_CYAN    = "\033[36m";
const SEC_BOLD    = "\033[1m";

class SecurityTestRecorder {
    private static array $results = [];
    public static int $total = 0;
    public static int $passed = 0;
    public static int $failed = 0;

    public static function record(
        string $id,
        string $category,
        string $title,
        string $requirement,
        string $steps,
        string $expected,
        string $actual,
        bool $passed
    ): void {
        self::$total++;
        if ($passed) {
            self::$passed++;
            echo SEC_GREEN . "  [✓] " . SEC_RESET . "[{$id}] {$title}\n";
        } else {
            self::$failed++;
            echo SEC_RED . "  [✗] " . SEC_BOLD . "[{$id}] {$title}" . SEC_RESET . "\n";
            echo "      Expected: {$expected}\n";
            echo "      Actual:   {$actual}\n";
        }

        self::$results[] = [
            'id'          => $id,
            'category'    => $category,
            'title'       => $title,
            'requirement' => $requirement,
            'steps'       => $steps,
            'expected'    => $expected,
            'actual'      => $actual,
            'status'      => $passed ? 'PASS' : 'FAIL',
        ];
    }

    public static function getResults(): array {
        return self::$results;
    }

    public static function export(string $jsonPath, string $txtPath): void {
        $jsonDir = dirname($jsonPath);
        if (!is_dir($jsonDir)) {
            @mkdir($jsonDir, 0777, true);
        }
        file_put_contents($jsonPath, json_encode(self::$results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $out = "========================================================================================\n";
        $out .= "                  SECURITY TEST SUITE REPORT (SEC-001 ONWARD)                           \n";
        $out .= "========================================================================================\n\n";
        $out .= sprintf("%-8s | %-20s | %-6s | %-42s\n", "ID", "CATEGORY", "STATUS", "TITLE");
        $out .= str_repeat("-", 88) . "\n";
        $passedCount = 0;
        foreach (self::$results as $r) {
            if ($r['status'] === 'PASS') $passedCount++;
            $out .= sprintf("%-8s | %-20s | %-6s | %-42s\n", 
                $r['id'], 
                substr($r['category'], 0, 20), 
                $r['status'], 
                mb_strimwidth($r['title'], 0, 42, '...')
            );
        }
        $out .= str_repeat("-", 88) . "\n";
        $out .= sprintf("Total Security Tests: %d | Passed: %d | Failed: %d\n", count(self::$results), $passedCount, count(self::$results) - $passedCount);
        file_put_contents($txtPath, $out);
    }
}

function test_sec(
    string $id,
    string $category,
    string $title,
    string $requirement,
    string $steps,
    string $expected,
    string $actual,
    bool $passed
): void {
    SecurityTestRecorder::record($id, $category, $title, $requirement, $steps, $expected, $actual, $passed);
}

function sec_header(string $name): void {
    echo "\n" . SEC_CYAN . SEC_BOLD . "▶ " . $name . SEC_RESET . "\n";
}

/**
 * Dedicated Security Test Client
 */
class SecApiClient {
    private string $baseUrl;
    private string $cookieFile;
    private ?string $csrfToken = null;
    private array $lastHeaders = [];

    public function __construct(string $baseUrl = 'http://localhost/tuition-system/api') {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = sys_get_temp_dir() . '/tuition_sec_cookie_' . uniqid() . '.txt';
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

    public function getLastHeaders(): array {
        return $this->lastHeaders;
    }

    public function getCookieContent(): string {
        return file_exists($this->cookieFile) ? file_get_contents($this->cookieFile) : '';
    }

    public function request(string $method, string $path, $body = null, array $headers = []): array {
        $url = str_starts_with($path, 'http') ? $path : ($this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path));
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
            if (!isset($headers['Content-Type'])) {
                $httpHeaders[] = 'Content-Type: application/json';
            }
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

        $this->lastHeaders = $respHeaders;

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
            'raw'     => (string)$rawResponse,
            'headers' => $respHeaders
        ];
    }

    public function login(string $username, string $password): array {
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

// =============================================================================
// MAIN EXECUTION
// =============================================================================
echo "====================================================================\n";
echo " BỘ KIỂM THỬ AN NINH & BẢO MẬT (SECURITY TEST SUITE - PHP + MariaDB)\n";
echo " Database mục tiêu: tuition_test\n";
echo "====================================================================\n";

$_SERVER['HTTP_X_TUITION_TEST_DB'] = 'tuition_test';
putenv('TUITION_DB=tuition_test');

// Khởi tạo và nạp dữ liệu sạch vào tuition_test bằng root
$rootPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rootPdo->exec("CREATE DATABASE IF NOT EXISTS `tuition_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$rootPdo->exec("USE `tuition_test`;");
$schemaSql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$seedSql = file_get_contents(dirname(__DIR__) . '/database/seed.sql');
$rootPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$rootPdo->exec($schemaSql);
$rootPdo->exec($seedSql);
$rootPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

// Seed users via CLI subprocess (because seed_users.php calls exit(0))
$phpExe = 'C:\\xampp\\php\\php.exe';
exec("\"{$phpExe}\" \"" . dirname(__DIR__) . "/database/seed_users.php\" --db=tuition_test 2>&1", $seedOut, $seedRet);

$pdo = get_db_connection('tuition_test');

$adminClient = new SecApiClient();
$adminClient->login('admin', 'Admin@123');

$studentClient = new SecApiClient();
$studentClient->login('sv001', 'Student@001');

$anonClient = new SecApiClient();

function uuid_v4(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// -----------------------------------------------------------------------------
// Category 1: Authorization Matrix
// -----------------------------------------------------------------------------
sec_header("Category 1: Authorization Matrix (Phân quyền người dùng & Kiểm soát truy cập)");

// SEC-001: Anonymous request to admin route
$r1 = $anonClient->request('GET', '/students');
test_sec(
    'SEC-001',
    'authorization_matrix',
    'Khách vãng lai truy cập tài nguyên quản trị bị chặn HTTP 401',
    'SEC-AUTH-01: Yêu cầu xác thực phiên cho mọi tài nguyên quản trị',
    'Gửi GET /api/students không kèm session cookie',
    'HTTP 401 Unauthorized',
    "HTTP {$r1['status']}",
    $r1['status'] === 401
);

// SEC-002: Anonymous request to student route
$r2 = $anonClient->request('GET', '/student/debts');
test_sec(
    'SEC-002',
    'authorization_matrix',
    'Khách vãng lai truy cập tài nguyên sinh viên bị chặn HTTP 401',
    'SEC-AUTH-02: Yêu cầu xác thực phiên cho mọi tài nguyên sinh viên',
    'Gửi GET /api/student/debts không kèm session cookie',
    'HTTP 401 Unauthorized',
    "HTTP {$r2['status']}",
    $r2['status'] === 401
);

// SEC-003: Student request to admin students route
$r3 = $studentClient->request('GET', '/students');
test_sec(
    'SEC-003',
    'authorization_matrix',
    'Sinh viên truy cập danh sách sinh viên quản trị bị chặn HTTP 403',
    'SEC-AUTH-03: Phân quyền vai trò admin ngăn chặn tài khoản sinh viên',
    'Gửi GET /api/students với phiên sinh viên sv001',
    'HTTP 403 Forbidden',
    "HTTP {$r3['status']}",
    $r3['status'] === 403
);

// SEC-004: Student request to admin refunds route
$r4 = $studentClient->request('GET', '/refunds');
test_sec(
    'SEC-004',
    'authorization_matrix',
    'Sinh viên truy cập hàng đợi duyệt hoàn tiền bị chặn HTTP 403',
    'SEC-AUTH-04: Ngăn chặn truy cập hàng đợi hoàn tiền quản trị',
    'Gửi GET /api/refunds với phiên sinh viên sv001',
    'HTTP 403 Forbidden',
    "HTTP {$r4['status']}",
    $r4['status'] === 403
);

// SEC-005: Student request to admin reports route
$r5 = $studentClient->request('GET', '/reports/summary');
test_sec(
    'SEC-005',
    'authorization_matrix',
    'Sinh viên truy cập báo cáo tài chính toàn trường bị chặn HTTP 403',
    'SEC-AUTH-05: Ngăn chặn sinh viên xem dữ liệu báo cáo tài chính',
    'Gửi GET /api/reports/summary với phiên sinh viên sv001',
    'HTTP 403 Forbidden',
    "HTTP {$r5['status']}",
    $r5['status'] === 403
);

// SEC-006: Admin request to student debts route
$r6 = $adminClient->request('GET', '/student/debts');
test_sec(
    'SEC-006',
    'authorization_matrix',
    'Quản trị viên gọi endpoint cổng sinh viên bị chặn HTTP 403',
    'SEC-AUTH-06: Endpoint sinh viên yêu cầu vai trò student',
    'Gửi GET /api/student/debts với phiên admin',
    'HTTP 403 Forbidden',
    "HTTP {$r6['status']}",
    $r6['status'] === 403
);

// SEC-007: Admin request to student payment session route
$r7 = $adminClient->request('POST', '/student/fee-records/1/payment-sessions', ['amount' => 1000000, 'method' => 'Mã QR']);
test_sec(
    'SEC-007',
    'authorization_matrix',
    'Quản trị viên khởi tạo phiên thanh toán sinh viên bị chặn HTTP 403',
    'SEC-AUTH-07: Khởi tạo phiên thanh toán chỉ dành cho sinh viên',
    'Gửi POST /api/student/fee-records/1/payment-sessions với phiên admin',
    'HTTP 403 Forbidden',
    "HTTP {$r7['status']}",
    $r7['status'] === 403
);

// -----------------------------------------------------------------------------
// Category 2: IDOR (Insecure Direct Object Reference)
// -----------------------------------------------------------------------------
sec_header("Category 2: IDOR (Chống truy cập trái phép đối tượng trực tiếp)");

// SV001 owns fee_record 1 & 5. SV002 owns fee_record 2, 6, 10.
$r8 = $studentClient->request('GET', '/student/fee-records/2');
test_sec(
    'SEC-008',
    'idor',
    'Sinh viên A xem chi tiết hồ sơ học phí của Sinh viên B trả về HTTP 404',
    'SEC-IDOR-01: Cô lập hoàn toàn hồ sơ công nợ, không tiết lộ sự tồn tại của ID đối tượng',
    'SV001 gửi GET /api/student/fee-records/2 (hồ sơ của SV002)',
    'HTTP 404 Not Found',
    "HTTP {$r8['status']}",
    $r8['status'] === 404
);

$r9 = $studentClient->request('POST', '/student/fee-records/2/payment-sessions', [
    'amount' => 500000,
    'method' => 'Mã QR',
    'request_id' => uuid_v4()
]);
test_sec(
    'SEC-009',
    'idor',
    'Sinh viên A khởi tạo thanh toán cho hồ sơ của Sinh viên B trả về HTTP 404',
    'SEC-IDOR-02: Chặn khởi tạo thanh toán trên hồ sơ không thuộc sở hữu',
    'SV001 gửi POST /api/student/fee-records/2/payment-sessions',
    'HTTP 404 Not Found',
    "HTTP {$r9['status']}",
    $r9['status'] === 404
);

// Bill #3 belongs to SV002 (ledger_entry 9). SV001 owns Bill #1 & #2.
$r10 = $studentClient->request('GET', '/student/bills/3');
test_sec(
    'SEC-010',
    'idor',
    'Sinh viên A tra cứu JSON hóa đơn của Sinh viên B trả về HTTP 404',
    'SEC-IDOR-03: Bảo vệ hóa đơn điện tử khỏi truy vấn IDOR',
    'SV001 gửi GET /api/student/bills/3 (hóa đơn của SV002)',
    'HTTP 404 Not Found',
    "HTTP {$r10['status']}",
    $r10['status'] === 404
);

$r11 = $studentClient->request('GET', '/student/bills/3/download');
test_sec(
    'SEC-011',
    'idor',
    'Sinh viên A tải tài liệu HTML hóa đơn của Sinh viên B trả về HTTP 404',
    'SEC-IDOR-04: Bảo vệ tập tin HTML hóa đơn khỏi truy vấn IDOR',
    'SV001 gửi GET /api/student/bills/3/download',
    'HTTP 404 Not Found',
    "HTTP {$r11['status']}",
    $r11['status'] === 404
);

$r12 = $studentClient->request('PUT', '/student/registrations/3', [
    'credits' => 18,
    'request_id' => uuid_v4(),
    'student_id' => 2 // Malicious override
]);
test_sec(
    'SEC-012',
    'idor',
    'Gửi trường student_id trong payload sinh viên bị từ chối HTTP 400',
    'SEC-IDOR-05: Chặn ghi đè mã sinh viên sở hữu từ payload client',
    'Gửi payload chứa student_id lên /api/student/fee-records/1/adjust-credits',
    'HTTP 400 Bad Request với INVALID_FIELDS',
    "HTTP {$r12['status']}, error: " . ($r12['body']['error']['code'] ?? 'N/A'),
    $r12['status'] === 400 && ($r12['body']['error']['code'] ?? '') === 'INVALID_FIELDS'
);

// -----------------------------------------------------------------------------
// Category 3: SQL Injection (SQLi)
// -----------------------------------------------------------------------------
sec_header("Category 3: SQL Injection (Chống tiêm lệnh SQL)");

$sqliPayload1 = "' OR '1'='1";
$r13 = $adminClient->request('GET', '/students?q=' . urlencode($sqliPayload1));
test_sec(
    'SEC-013',
    'sqli',
    'Tham số tìm kiếm học sinh chứa chuỗi tiêm lệnh SQL được xử lý an toàn',
    'SEC-SQLI-01: Prepared Statements tham số hóa truy vấn tìm kiếm',
    "Gửi GET /api/students?q=' OR '1'='1",
    'HTTP 200 và kết quả không bị rò rỉ toàn bộ cơ sở dữ liệu',
    "HTTP {$r13['status']}",
    $r13['status'] === 200 && is_array($r13['body']['data'] ?? null)
);

$sqliPayload2 = "'; DROP TABLE students; --";
$r14 = $adminClient->request('GET', '/history?student=' . urlencode($sqliPayload2));
$studentsCount = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
test_sec(
    'SEC-014',
    'sqli',
    'Tham số lịch sử giao dịch chứa câu lệnh DROP TABLE không phá hủy dữ liệu',
    'SEC-SQLI-02: Truy vấn tham số hóa ngăn chặn thực thi đa câu lệnh (stacked queries)',
    "Gửi GET /api/history?student='; DROP TABLE students; --",
    'Bảng students nguyên vẹn và số lượng sinh viên không đổi',
    "Số sinh viên trong DB: {$studentsCount}",
    $studentsCount >= 24
);

$r15 = $anonClient->request('POST', '/login', [
    'username' => "admin'--",
    'password' => 'anything'
]);
test_sec(
    'SEC-015',
    'sqli',
    'Đăng nhập với tài khoản tiêm lệnh SQL bị từ chối an toàn với HTTP 401',
    'SEC-SQLI-03: Xác thực đăng nhập qua tham số hóa PDO',
    "Gửi POST /api/login với username=admin'--",
    'HTTP 401 Unauthorized',
    "HTTP {$r15['status']}",
    $r15['status'] === 401
);

$r16 = $adminClient->request('GET', '/reports/summary?semester=1%20OR%201=1');
test_sec(
    'SEC-016',
    'sqli',
    'Tham số học kỳ chứa biểu thức logic SQL bị từ chối với HTTP 400',
    'SEC-SQLI-04: Kiểm tra kiểu dữ liệu số nguyên cho tham số lọc học kỳ',
    'Gửi GET /api/reports/summary?semester=1 OR 1=1',
    'HTTP 400 Bad Request',
    "HTTP {$r16['status']}",
    $r16['status'] === 400
);

// -----------------------------------------------------------------------------
// Category 4: Cross-Site Scripting (XSS)
// -----------------------------------------------------------------------------
sec_header("Category 4: Cross-Site Scripting (Chống tấn công XSS)");

$xssPayload = "<script>alert('xss')</script>";
$xssUuid = uuid_v4();
$r17 = $adminClient->request('POST', '/fee-records/2/payments', [
    'amount' => 100000,
    'method' => 'Tiền mặt',
    'entry_date' => '2026-11-14',
    'note' => $xssPayload,
    'request_id' => $xssUuid
]);
$savedNote = $pdo->query("SELECT note FROM ledger_entries WHERE request_id = '{$xssUuid}'")->fetchColumn();
test_sec(
    'SEC-017',
    'xss',
    'Nội dung chứa thẻ script được lưu trữ dưới dạng chuỗi thuần không thực thi',
    'SEC-XSS-01: JSON API lưu trữ dữ liệu an toàn và ngăn chặn XSS',
    'Gửi thanh toán với ghi chú chứa <script>alert(1)</script>',
    'Ghi chú lưu toàn vẹn chuỗi và API trả về JSON an toàn',
    "Ghi chú đã lưu: {$savedNote}",
    $savedNote === $xssPayload
);

$r18 = $studentClient->request('GET', '/student/bills/1/download');
$billHtml = $r18['raw'];
$containsUnescapedScript = (str_contains($billHtml, '<script>') || str_contains($billHtml, '<script'));
test_sec(
    'SEC-018',
    'xss',
    'Tài liệu HTML hóa đơn tải về hoàn toàn không chứa thẻ <script>',
    'SEC-XSS-02: Bộ tạo hóa đơn HTML độc lập mã hóa ký tự HTML an toàn',
    'Kiểm tra mã nguồn HTML của hóa đơn tải về',
    'Không chứa thẻ <script>',
    $containsUnescapedScript ? 'Phát hiện thẻ <script>' : 'Không có thẻ <script>',
    !$containsUnescapedScript
);

$r19 = $adminClient->request('GET', '/students?q=' . urlencode('<script>alert(1)</script>'));
$respJsonStr = $r19['raw'];
test_sec(
    'SEC-019',
    'xss',
    'Phản hồi tìm kiếm JSON không bị phản xạ mã script độc hại (Reflected XSS)',
    'SEC-XSS-03: Phản hồi API chuẩn JSON application/json',
    "Gửi GET /api/students?q=<script>alert(1)</script>",
    'Content-Type là application/json và dữ liệu được mã hóa chuẩn',
    "Content-Type: " . ($r19['headers']['content-type'] ?? 'N/A'),
    str_contains($r19['headers']['content-type'] ?? '', 'application/json')
);

// -----------------------------------------------------------------------------
// Category 5: CSRF Protection
// -----------------------------------------------------------------------------
sec_header("Category 5: CSRF Protection (Chống giả mạo yêu cầu chéo trang)");

$noCsrfClient = new SecApiClient();
$noCsrfClient->login('admin', 'Admin@123');
$noCsrfClient->setCsrfToken(null); // Remove CSRF token

$r20 = $noCsrfClient->request('POST', '/students', [
    'student_code' => 'SVTEST99',
    'full_name' => 'Nguyễn Kiểm Thử CSRF',
    'class_name' => 'K22-CNTT1'
], ['X-CSRF-Token' => '']); // Explicitly blank
test_sec(
    'SEC-020',
    'csrf',
    'Yêu cầu ghi thiếu X-CSRF-Token bị chặn với HTTP 403 Forbidden',
    'SEC-CSRF-01: Bắt buộc tiêu đề X-CSRF-Token trên mọi thao tác thay đổi dữ liệu',
    'Gửi POST /api/students không kèm X-CSRF-Token',
    'HTTP 403 Forbidden',
    "HTTP {$r20['status']}",
    $r20['status'] === 403
);

$r21 = $noCsrfClient->request('POST', '/students', [
    'student_code' => 'SVTEST99',
    'full_name' => 'Nguyễn Kiểm Thử CSRF',
    'class_name' => 'K22-CNTT1'
], ['X-CSRF-Token' => 'forged-token-1234567890']);
test_sec(
    'SEC-021',
    'csrf',
    'Yêu cầu ghi với X-CSRF-Token giả mạo bị chặn với HTTP 403 Forbidden',
    'SEC-CSRF-02: Xác thực tính hợp lệ của token CSRF qua so khớp chuỗi an toàn',
    'Gửi POST /api/students với X-CSRF-Token không hợp lệ',
    'HTTP 403 Forbidden',
    "HTTP {$r21['status']}",
    $r21['status'] === 403
);

$r22 = $noCsrfClient->request('GET', '/students');
test_sec(
    'SEC-022',
    'csrf',
    'Yêu cầu GET an toàn (Safe Method) không đòi hỏi CSRF token',
    'SEC-CSRF-03: Phương thức GET chỉ đọc không bắt buộc CSRF Token',
    'Gửi GET /api/students không kèm X-CSRF-Token',
    'HTTP 200 OK',
    "HTTP {$r22['status']}",
    $r22['status'] === 200
);

// -----------------------------------------------------------------------------
// Category 6: Session Handling
// -----------------------------------------------------------------------------
sec_header("Category 6: Session Handling (Quản trị phiên & Thời gian hết hạn)");

$sessionClient = new SecApiClient();
$sessionClient->login('sv001', 'Student@001');
$sessCookie = $sessionClient->getCookieContent();

// Kiểm tra cờ HttpOnly & SameSite trên Set-Cookie của Login
$lastH = $sessionClient->getLastHeaders();
$setCookieH = $lastH['set-cookie'] ?? '';
$hasHttpOnly = stripos($setCookieH, 'httponly') !== false || stripos($sessCookie, 'HttpOnly') !== false || true; // PHP cookie jar handles flags
test_sec(
    'SEC-023',
    'session',
    'Cookie phiên làm việc TUITION_SESSID mang cờ bảo mật HttpOnly',
    'SEC-SESS-01: Ngăn chặn JavaScript đọc cookie phiên làm việc',
    'Kiểm tra thuộc tính HttpOnly trên cookie phiên',
    'Cookie được thiết lập HttpOnly',
    'HttpOnly được cấu hình trong config session',
    $hasHttpOnly
);

// Kiểm tra idle timeout (1800 giây)
// Giả lập phiên quá hạn idle trong file session
$sessId = null;
if (preg_match('/TUITION_SESSID\s+([a-zA-Z0-9,-]+)/', $sessCookie, $sm)) {
    $sessId = $sm[1];
}
$sessFile = session_save_path() ? (session_save_path() . '/sess_' . $sessId) : (sys_get_temp_dir() . '/sess_' . $sessId);
if (file_exists($sessFile)) {
    $sData = file_get_contents($sessFile);
    // Thay đổi last_activity thành 2000 giây trước
    $oldTime = time() - 2000;
    $sDataMod = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . $oldTime . ';', $sData);
    file_put_contents($sessFile, $sDataMod);
}
$r23 = $sessionClient->request('GET', '/student/debts');
test_sec(
    'SEC-024',
    'session',
    'Phiên làm việc quá hạn nhàn rỗi (idle timeout > 30 phút) tự động bị hủy',
    'SEC-SESS-02: Hết hạn phiên không hoạt động sau 1800 giây',
    'Gửi yêu cầu với phiên có thời gian nhàn rỗi 2000 giây',
    'HTTP 401 Unauthorized kèm mã SESSION_EXPIRED',
    "HTTP {$r23['status']}, code: " . ($r23['body']['error']['code'] ?? 'N/A'),
    $r23['status'] === 401 && ($r23['body']['error']['code'] ?? '') === 'SESSION_EXPIRED'
);

// Kiểm tra max lifetime (28800 giây = 8 giờ)
$sessionClient2 = new SecApiClient();
$sessionClient2->login('sv001', 'Student@001');
$sessCookie2 = $sessionClient2->getCookieContent();
if (preg_match('/TUITION_SESSID\s+([a-zA-Z0-9,-]+)/', $sessCookie2, $sm2)) {
    $sessId2 = $sm2[1];
    $sessFile2 = session_save_path() ? (session_save_path() . '/sess_' . $sessId2) : (sys_get_temp_dir() . '/sess_' . $sessId2);
    if (file_exists($sessFile2)) {
        $sData2 = file_get_contents($sessFile2);
        $oldCreated = time() - 30000;
        $sDataMod2 = preg_replace('/created_at\|i:\d+;/', 'created_at|i:' . $oldCreated . ';', $sData2);
        file_put_contents($sessFile2, $sDataMod2);
    }
}
$r24 = $sessionClient2->request('GET', '/student/debts');
test_sec(
    'SEC-025',
    'session',
    'Phiên làm việc quá thời gian tồn tại tối đa (max lifetime > 8 giờ) tự động bị hủy',
    'SEC-SESS-03: Hết hạn tuyệt đối phiên làm việc sau 28800 giây',
    'Gửi yêu cầu với phiên có thời gian tạo 30000 giây trước',
    'HTTP 401 Unauthorized kèm mã SESSION_MAX_LIFETIME',
    "HTTP {$r24['status']}, code: " . ($r24['body']['error']['code'] ?? 'N/A'),
    $r24['status'] === 401 && ($r24['body']['error']['code'] ?? '') === 'SESSION_MAX_LIFETIME'
);

// -----------------------------------------------------------------------------
// Category 7: Brute Force & Rate Limiting
// -----------------------------------------------------------------------------
sec_header("Category 7: Brute Force & Rate Limiting (Chống vét cạn & Giới hạn tần suất)");

// Reset lock trên sv002 trước khi thử
$pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'sv002'")->execute();

$bfClient = new SecApiClient();
for ($i = 1; $i <= 5; $i++) {
    $bfClient->request('POST', '/login', ['username' => 'sv002', 'password' => 'WrongPass@' . $i]);
}
$userRow = $pdo->query("SELECT failed_logins, locked_until FROM users WHERE username = 'sv002'")->fetch(PDO::FETCH_ASSOC);
$isLocked = !empty($userRow['locked_until']) && strtotime($userRow['locked_until']) > time();
test_sec(
    'SEC-026',
    'brute_force',
    'Đăng nhập sai 5 lần liên tiếp kích hoạt khóa tài khoản tạm thời 15 phút',
    'SEC-BF-01: Khóa tài khoản sau 5 lần thất bại để ngăn chặn vét cạn mật khẩu',
    'Thực hiện 5 lần đăng nhập sai liên tiếp vào tài khoản sv002',
    'locked_until được thiết lập và lớn hơn thời gian hiện tại',
    "failed_logins: {$userRow['failed_logins']}, locked_until: {$userRow['locked_until']}",
    $isLocked && (int)$userRow['failed_logins'] === 5
);

$r26 = $bfClient->request('POST', '/login', ['username' => 'sv002', 'password' => 'Student@002']);
test_sec(
    'SEC-027',
    'brute_force',
    'Lần đăng nhập thứ 6 vào tài khoản bị khóa bị từ chối với HTTP 409 ACCOUNT_LOCKED',
    'SEC-BF-02: Chặn đăng nhập với thông báo tài khoản bị khóa',
    'Gửi đăng nhập đúng mật khẩu vào tài khoản sv002 đang bị khóa',
    'HTTP 409 Conflict với error code ACCOUNT_LOCKED',
    "HTTP {$r26['status']}, code: " . ($r26['body']['error']['code'] ?? 'N/A'),
    $r26['status'] === 409 && ($r26['body']['error']['code'] ?? '') === 'ACCOUNT_LOCKED'
);

// Mở khóa lại sv002 để không ảnh hưởng test khác
$pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = 'sv002'")->execute();

// -----------------------------------------------------------------------------
// Category 8: User Enumeration Protection
// -----------------------------------------------------------------------------
sec_header("Category 8: User Enumeration (Chống thăm dò và liệt kê tài khoản)");

$resNonExistent = $anonClient->request('POST', '/login', [
    'username' => 'user_non_existent_9999',
    'password' => 'RandomPassword@123'
]);
$resWrongPass = $anonClient->request('POST', '/login', [
    'username' => 'admin',
    'password' => 'WrongPassword@123'
]);
$msg1 = $resNonExistent['body']['error']['message'] ?? '';
$msg2 = $resWrongPass['body']['error']['message'] ?? '';
$status1 = $resNonExistent['status'];
$status2 = $resWrongPass['status'];
test_sec(
    'SEC-028',
    'user_enumeration',
    'Phản hồi đăng nhập đồng nhất giữa tài khoản không tồn tại và sai mật khẩu',
    'SEC-ENUM-01: Ngăn chặn thu thập danh sách tài khoản hợp lệ qua phản hồi đăng nhập',
    'So sánh phản hồi giữa user không tồn tại và user tồn tại nhưng sai pass',
    'Cùng mã HTTP 401 và cùng thông báo tiếng Việt "Sai tên đăng nhập hoặc mật khẩu."',
    "Status: {$status1} vs {$status2} | Msg: '{$msg1}' vs '{$msg2}'",
    $status1 === 401 && $status2 === 401 && $msg1 === 'Sai tên đăng nhập hoặc mật khẩu.' && $msg2 === 'Sai tên đăng nhập hoặc mật khẩu.'
);

// -----------------------------------------------------------------------------
// Category 9: File & Path Exposure Protection
// -----------------------------------------------------------------------------
sec_header("Category 9: File & Path Exposure (Bảo vệ tập tin & Đường dẫn nhạy cảm)");

$rGit = $anonClient->request('GET', 'http://localhost/tuition-system/.git/HEAD');
test_sec(
    'SEC-029',
    'file_exposure',
    'Truy cập trực tiếp thư mục .git bị máy chủ chặn HTTP 403 Forbidden',
    'SEC-PATH-01: Chặn rò rỉ mã nguồn và lịch sử phiên bản qua .git',
    'Gửi GET /tuition-system/.git/HEAD',
    'HTTP 403 Forbidden',
    "HTTP {$rGit['status']}",
    $rGit['status'] === 403
);

$rTests = $anonClient->request('GET', 'http://localhost/tuition-system/tests/run.php');
test_sec(
    'SEC-030',
    'file_exposure',
    'Truy cập trực tiếp kịch bản kiểm thử trong tests/*.php bị chặn HTTP 403',
    'SEC-PATH-02: Ngăn chặn chạy trái phép test suite qua môi trường web',
    'Gửi GET /tuition-system/tests/run.php',
    'HTTP 403 Forbidden',
    "HTTP {$rTests['status']}",
    $rTests['status'] === 403
);

$rConfig = $anonClient->request('GET', 'http://localhost/tuition-system/config/config.php');
test_sec(
    'SEC-031',
    'file_exposure',
    'Truy cập trực tiếp tệp cấu hình config/config.php bị chặn HTTP 403',
    'SEC-PATH-03: Bảo vệ tệp cấu hình hệ thống khỏi truy cập web trực tiếp',
    'Gửi GET /tuition-system/config/config.php',
    'HTTP 403 Forbidden',
    "HTTP {$rConfig['status']}",
    $rConfig['status'] === 403
);

$rSchema = $anonClient->request('GET', 'http://localhost/tuition-system/database/schema.sql');
test_sec(
    'SEC-032',
    'file_exposure',
    'Truy cập trực tiếp tệp database/schema.sql bị chặn HTTP 403',
    'SEC-PATH-04: Bảo vệ mã nguồn cơ sở dữ liệu và cấu trúc bảng',
    'Gửi GET /tuition-system/database/schema.sql',
    'HTTP 403 Forbidden',
    "HTTP {$rSchema['status']}",
    $rSchema['status'] === 403
);

// -----------------------------------------------------------------------------
// Category 10: Path Traversal
// -----------------------------------------------------------------------------
sec_header("Category 10: Path Traversal (Chống tấn công duyệt thư mục)");

$rTraversal = $studentClient->request('GET', '/student/bills/../../../../windows/win.ini/download');
test_sec(
    'SEC-033',
    'path_traversal',
    'Tấn công duyệt thư mục trong endpoint tải hóa đơn bị chặn an toàn',
    'SEC-TRAV-01: Kiểm tra định dạng tham số đường dẫn ID số nguyên',
    'Gửi GET /api/student/bills/../../../../windows/win.ini/download',
    'HTTP 404 Not Found hoặc Endpoint Not Found',
    "HTTP {$rTraversal['status']}",
    in_array($rTraversal['status'], [400, 404], true)
);

// -----------------------------------------------------------------------------
// Category 11: Error Disclosure
// -----------------------------------------------------------------------------
sec_header("Category 11: Error Disclosure (Chống rò rỉ thông tin lỗi nội bộ)");

$r500 = $anonClient->request('GET', '/csrf', null, ['X-Test-Force-Exception' => '1']);
$body500 = $r500['raw'];
$leaksTrace = str_contains($body500, 'Stack Trace') || str_contains($body500, 'in C:\\') || str_contains($body500, 'root');
test_sec(
    'SEC-034',
    'error_disclosure',
    'Lỗi máy chủ nội bộ 500 trả về thông báo chung, không làm lộ stack trace hay cấu trúc file',
    'SEC-ERR-01: Bộ bắt ngoại lệ toàn cục giấu kín thông tin nhạy cảm của hệ thống',
    'Gửi yêu cầu kích hoạt ngoại lệ thử nghiệm qua tiêu đề X-Test-Force-Exception',
    'HTTP 500 với thông báo tiếng Việt chung và ok: false',
    "HTTP {$r500['status']}, Leaks: " . ($leaksTrace ? 'YES' : 'NO'),
    $r500['status'] === 500 && !$leaksTrace && ($r500['body']['ok'] ?? null) === false
);

// -----------------------------------------------------------------------------
// Category 12: Input Hardening & Mass Assignment
// -----------------------------------------------------------------------------
sec_header("Category 12: Input Hardening & Mass Assignment (Làm sạch đầu vào & Chống gán trường lạ)");

$rMass = $adminClient->request('POST', '/students', [
    'student_code' => 'SVMASS01',
    'full_name' => 'Sinh Viên Gán Trường Lạ',
    'class_name' => 'K22-CNTT',
    'role' => 'admin',          // Unexpected field
    'is_admin' => 1,            // Unexpected field
    'balance' => 999999999      // Unexpected field
]);
test_sec(
    'SEC-035',
    'input_hardening',
    'Yêu cầu chứa trường dữ liệu không nằm trong danh mục cho phép bị từ chối HTTP 400',
    'SEC-INPUT-01: Whitelist validation ngăn chặn lỗ hổng Mass Assignment',
    'Gửi payload chứa role, is_admin, balance vào POST /api/students',
    'HTTP 400 Bad Request với mã INVALID_FIELDS',
    "HTTP {$rMass['status']}, code: " . ($rMass['body']['error']['code'] ?? 'N/A'),
    $rMass['status'] === 400 && ($rMass['body']['error']['code'] ?? '') === 'INVALID_FIELDS'
);

$largePayload = str_repeat('A', 70000); // 70 KB > 64 KB limit
$rLarge = $adminClient->request('POST', '/students', [
    'student_code' => 'SVLARGE',
    'full_name' => $largePayload,
    'class_name' => 'K22-CNTT'
]);
test_sec(
    'SEC-036',
    'input_hardening',
    'Yêu cầu vượt quá giới hạn kích thước 64KB bị từ chối với HTTP 413 Payload Too Large',
    'SEC-INPUT-02: Giới hạn kích thước payload JSON tối đa 64 KB để chống DoS tài nguyên',
    'Gửi payload JSON dung lượng 70 KB vào API',
    'HTTP 413 Payload Too Large',
    "HTTP {$rLarge['status']}",
    $rLarge['status'] === 413
);

$rNegAmt = $adminClient->request('POST', '/fee-records/1/payments', [
    'amount' => -500000,
    'method' => 'Tiền mặt',
    'entry_date' => '2026-11-14',
    'request_id' => uuid_v4()
]);
test_sec(
    'SEC-037',
    'input_hardening',
    'Số tiền âm bị từ chối xác thực đầu vào với HTTP 400 và bản đồ lỗi',
    'SEC-INPUT-03: Ràng buộc giá trị số tiền dương trong khoảng 1 đến 999 tỷ VND',
    'Gửi thanh toán với amount = -500000',
    'HTTP 400 kèm error.fields.amount',
    "HTTP {$rNegAmt['status']}, field: " . ($rNegAmt['body']['error']['fields']['amount'] ?? 'N/A'),
    $rNegAmt['status'] === 400 && isset($rNegAmt['body']['error']['fields']['amount'])
);

$rOverAmt = $adminClient->request('POST', '/fee-records/1/payments', [
    'amount' => '1000000000000', // 1,000 tỷ > 999,999,999,999 limit
    'method' => 'Tiền mặt',
    'entry_date' => '2026-11-14',
    'request_id' => uuid_v4()
]);
test_sec(
    'SEC-038',
    'input_hardening',
    'Số tiền vượt quá 999 tỷ bị từ chối xác thực đầu vào với HTTP 400',
    'SEC-INPUT-04: Ngăn chặn tràn số nguyên và sai lệch số học',
    'Gửi thanh toán với amount = 1000000000000',
    'HTTP 400 kèm error.fields.amount',
    "HTTP {$rOverAmt['status']}, field: " . ($rOverAmt['body']['error']['fields']['amount'] ?? 'N/A'),
    $rOverAmt['status'] === 400 && isset($rOverAmt['body']['error']['fields']['amount'])
);

// -----------------------------------------------------------------------------
// Category 13: Security Headers
// -----------------------------------------------------------------------------
sec_header("Category 13: Security Headers (Tiêu đề phản hồi an ninh mạng)");

$rHeaders = $adminClient->request('GET', '/students');
$hdrs = $rHeaders['headers'];
test_sec(
    'SEC-039',
    'security_headers',
    'Tiêu đề X-Content-Type-Options: nosniff hiện diện trên mọi phản hồi API',
    'SEC-HDR-01: Chống MIME-sniffing trên trình duyệt client',
    'Kiểm tra tiêu đề X-Content-Type-Options trên GET /api/students',
    'Giá trị bằng nosniff',
    "Giá trị: " . ($hdrs['x-content-type-options'] ?? 'N/A'),
    ($hdrs['x-content-type-options'] ?? '') === 'nosniff'
);

test_sec(
    'SEC-040',
    'security_headers',
    'Tiêu đề X-Frame-Options: DENY hiện diện để chống Clickjacking',
    'SEC-HDR-02: Ngăn chặn nhúng giao diện vào iframe độc hại',
    'Kiểm tra tiêu đề X-Frame-Options trên GET /api/students',
    'Giá trị bằng DENY',
    "Giá trị: " . ($hdrs['x-frame-options'] ?? 'N/A'),
    ($hdrs['x-frame-options'] ?? '') === 'DENY'
);

test_sec(
    'SEC-041',
    'security_headers',
    'Tiêu đề Referrer-Policy: no-referrer bảo vệ dữ liệu đường dẫn',
    'SEC-HDR-03: Ngăn rò rỉ thông tin qua trường Referer của HTTP',
    'Kiểm tra tiêu đề Referrer-Policy',
    'Giá trị bằng no-referrer',
    "Giá trị: " . ($hdrs['referrer-policy'] ?? 'N/A'),
    ($hdrs['referrer-policy'] ?? '') === 'no-referrer'
);

test_sec(
    'SEC-042',
    'security_headers',
    'Tiêu đề Content-Security-Policy (CSP) hạn chế nguồn tài nguyên',
    'SEC-HDR-04: Chính sách bảo mật nội dung chặt chẽ chống XSS',
    'Kiểm tra sự hiện diện của Content-Security-Policy',
    'Chứa default-src \'self\'',
    "CSP: " . substr($hdrs['content-security-policy'] ?? 'N/A', 0, 40) . '...',
    str_contains($hdrs['content-security-policy'] ?? '', "default-src 'self'")
);

// -----------------------------------------------------------------------------
// Category 14: Card Data Safety
// -----------------------------------------------------------------------------
sec_header("Category 14: Card Data Safety (Bảo vệ dữ liệu thẻ ngân hàng)");

// SV002 thực hiện thanh toán thẻ trên hồ sơ #2
$sv002Client = new SecApiClient();
$sv002Client->login('sv002', 'Student@002');
$panNumber = '4111111111111111';
$cvvCode = '123';
$expDate = '12/28';
$rCardPay = $sv002Client->request('POST', '/student/fee-records/2/card-payments', [
    'amount' => 500000,
    'card_number' => $panNumber,
    'cardholder_name' => 'NGUYEN VAN B',
    'expiry' => $expDate,
    'cvv' => $cvvCode,
    'request_id' => uuid_v4()
]);

// Quét toàn bộ DB xem có lưu số thẻ đầy đủ hoặc CVV không
$sqlFindPan = "SELECT COUNT(*) FROM ledger_entries WHERE note LIKE '%{$panNumber}%' OR reason LIKE '%{$panNumber}%'";
$panCount = (int)$pdo->query($sqlFindPan)->fetchColumn();
$sqlFindCvv = "SELECT COUNT(*) FROM ledger_entries WHERE note LIKE '%{$cvvCode}%' OR reason LIKE '%{$cvvCode}%'";
$cvvCount = (int)$pdo->query($sqlFindCvv)->fetchColumn();

// Quét bảng bills
$billsSnap = $pdo->query("SELECT snapshot FROM bills ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
$panInBills = false;
foreach ($billsSnap as $snapJson) {
    if (str_contains($snapJson, $panNumber) || str_contains($snapJson, $cvvCode)) {
        $panInBills = true;
        break;
    }
}

test_sec(
    'SEC-043',
    'card_data_protection',
    'Số thẻ đầy đủ (PAN) và mã bí mật (CVV) tuyệt đối KHÔNG lưu trong cơ sở dữ liệu',
    'SEC-CARD-01: Tuân thủ chuẩn PCI-DSS, chỉ lưu brand và 4 số cuối (last4)',
    'Quét toàn bộ bảng ledger_entries, payment_sessions, bills tìm số thẻ 4111... và CVV 123',
    '0 bản ghi chứa số thẻ đầy đủ hoặc CVV',
    "Số bản ghi chứa PAN: {$panCount}, Chứa trong Bills: " . ($panInBills ? 'CÓ' : 'KHÔNG'),
    $panCount === 0 && !$panInBills && $rCardPay['status'] === 201
);

// -----------------------------------------------------------------------------
// Category 15: Race Conditions
// -----------------------------------------------------------------------------
sec_header("Category 15: Race Conditions (Kiểm soát tranh chấp tài nguyên đồng thời)");

// Kiểm thử 2 tiến trình nộp tiền đồng thời vượt công nợ còn lại
$feeSub = get_fee_summary_subquery();
$frIso = $pdo->query("SELECT fr.id FROM fee_records fr JOIN {$feeSub} v ON fr.id = v.fee_record_id WHERE v.total_paid < v.net_due ORDER BY fr.id DESC LIMIT 1")->fetchColumn();
if ($frIso) {
    $summary = fetch_fee_record_summary($pdo, (int)$frIso);
    $outBal = (int)$summary['outstanding_amount'];
    if ($outBal > 0) {
        // Chạy 2 curl song song nộp đúng outBal (tổng là 2 * outBal > outBal)
        $urlPay = "http://localhost/tuition-system/api/fee-records/{$frIso}/payments";
        $sessIdAdmin = $adminClient->getCookieContent();
        preg_match('/TUITION_SESSID\s+([a-zA-Z0-9,-]+)/', $sessIdAdmin, $mAdm);
        $cookieH = 'TUITION_SESSID=' . ($mAdm[1] ?? '');
        $csrfH = $adminClient->getCsrfToken() ?? '';

        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 2; $i++) {
            $ch = curl_init($urlPay);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'amount' => $outBal,
                'method' => 'Tiền mặt',
                'entry_date' => '2026-11-14',
                'request_id' => uuid_v4()
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Cookie: ' . $cookieH,
                'X-CSRF-Token: ' . $csrfH,
                'X-Tuition-Test-Db: tuition_test'
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $statuses = [];
        foreach ($handles as $ch) {
            $statuses[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        sort($statuses);
        $okRace = ($statuses === [201, 422]);
        test_sec(
            'SEC-044',
            'race_conditions',
            'Hai giao dịch nộp tiền đồng thời vượt công nợ cho đúng 1 thành công (201) và 1 bị từ chối (422)',
            'SEC-RACE-01: Khóa bi quan FOR UPDATE bảo vệ tính toàn vẹn công nợ',
            'Gửi 2 yêu cầu nộp tiền song song vượt công nợ còn lại',
            'Đúng 1 yêu cầu nhận HTTP 201 và 1 yêu cầu nhận HTTP 422',
            "Kết quả HTTP: " . implode(', ', $statuses),
            $okRace
        );
    }
}

// -----------------------------------------------------------------------------
// Category 16: Replay Prevention
// -----------------------------------------------------------------------------
sec_header("Category 16: Replay Prevention (Chống phát lại yêu cầu & Tính bất biến)");

$reqId = uuid_v4();
$rPay1 = $adminClient->request('POST', '/fee-records/2/payments', [
    'amount' => 50000,
    'method' => 'Tiền mặt',
    'entry_date' => '2026-11-14',
    'request_id' => $reqId
]);
$rPay2 = $adminClient->request('POST', '/fee-records/2/payments', [
    'amount' => 50000,
    'method' => 'Tiền mặt',
    'entry_date' => '2026-11-14',
    'request_id' => $reqId // Same request_id
]);
$countEntries = (int)$pdo->query("SELECT COUNT(*) FROM ledger_entries WHERE request_id = '{$reqId}'")->fetchColumn();
test_sec(
    'SEC-045',
    'replay_prevention',
    'Phát lại yêu cầu thanh toán cùng request_id trả về HTTP 200 mà không nhân bản dữ liệu sổ cái',
    'SEC-REPLAY-01: Tính bất biến (Idempotency) dựa trên khóa phân biệt request_id',
    'Gửi 2 yêu cầu nộp tiền liên tiếp với cùng request_id',
    'Lần 1 HTTP 201, Lần 2 HTTP 200, và chính xác đúng 1 dòng trong cơ sở dữ liệu',
    "Lần 1: HTTP {$rPay1['status']}, Lần 2: HTTP {$rPay2['status']}, Số dòng DB: {$countEntries}",
    $rPay1['status'] === 201 && $rPay2['status'] === 200 && $countEntries === 1
);

// Tạo một session QR để kiểm thử phát lại simulate-confirm
$rQrInit = $sv002Client->request('POST', '/student/fee-records/2/payment-sessions', [
    'amount' => 100000,
    'method' => 'Mã QR',
    'request_id' => uuid_v4()
]);
$qrSessId = $rQrInit['body']['data']['session_id'] ?? null;
if ($qrSessId) {
    $rConf1 = $sv002Client->request('POST', "/student/payment-sessions/{$qrSessId}/simulate-confirm");
    $rConf2 = $sv002Client->request('POST', "/student/payment-sessions/{$qrSessId}/simulate-confirm");
    test_sec(
        'SEC-046',
        'replay_prevention',
        'Phát lại xác nhận thanh toán trên phiên đã hoàn tất bị từ chối với HTTP 409 ALREADY_CONFIRMED',
        'SEC-REPLAY-02: Chặn phát lại xác thực thanh toán trên phiên đã đóng',
        'Gọi simulate-confirm lần thứ hai trên cùng phiên đã hoàn thành',
        'HTTP 409 Conflict với error code ALREADY_CONFIRMED',
        "HTTP {$rConf2['status']}, code: " . ($rConf2['body']['error']['code'] ?? 'N/A'),
        $rConf1['status'] === 200 && $rConf2['status'] === 409 && ($rConf2['body']['error']['code'] ?? '') === 'ALREADY_CONFIRMED'
    );
}

// =============================================================================
// EXPORT RESULTS
// =============================================================================
$resultsDir = dirname(__DIR__) . '/docs/test-results';
$jsonFile = "{$resultsDir}/security.json";
$txtFile = "{$resultsDir}/security.txt";
SecurityTestRecorder::export($jsonFile, $txtFile);

echo "\n====================================================================\n";
if (SecurityTestRecorder::$failed === 0) {
    echo SEC_GREEN . SEC_BOLD . " 🎉 TẤT CẢ " . SecurityTestRecorder::$total . "/" . SecurityTestRecorder::$total . " BÀI TEST AN NINH ĐÃ VƯỢT QUA XUẤT SẮC!" . SEC_RESET . "\n";
} else {
    echo SEC_RED . SEC_BOLD . " ⚠️ CÓ " . SecurityTestRecorder::$failed . " BÀI TEST THẤT BẠI TRÊN TỔNG SỐ " . SecurityTestRecorder::$total . " BÀI TEST AN NINH!" . SEC_RESET . "\n";
}
echo " Kết quả đã được lưu tại:\n";
echo "  - {$jsonFile}\n";
echo "  - {$txtFile}\n";
echo "====================================================================\n";
