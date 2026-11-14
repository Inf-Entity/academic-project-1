<?php
/**
 * Dataset Generator for Performance Testing
 * Target database: tuition_perf
 * Deterministic with seed mt_srand(20261114)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/lib/validate.php';

$startTime = microtime(true);
mt_srand(20261114);

$host = '127.0.0.1';
$user = 'root';
$pass = '';
$dbName = 'tuition_perf';

echo "=== KHỞI TẠO DỮ LIỆU HIỆU NĂNG (tuition_perf) ===\n";

try {
    $pdoRoot = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdoRoot->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO 'tuition_user'@'localhost'");
    $pdoRoot->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO 'tuition_user'@'127.0.0.1'");
    $pdoRoot->exec("FLUSH PRIVILEGES");
} catch (Exception $e) {
    echo "Lỗi cấp quyền / tạo DB root: " . $e->getMessage() . "\n";
}

$pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// 1. Tải schema.sql
echo "1. Đang nạp schema.sql vào {$dbName}...\n";
$schemaSql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
$pdo->exec("USE `{$dbName}`;");
$pdo->exec($schemaSql);

// 2. Thêm admin user
echo "2. Đang tạo người dùng quản trị...\n";
$adminPassHash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 8]);
$pdo->prepare("
    INSERT INTO users (username, password_hash, full_name, role, must_change_password)
    VALUES ('admin', ?, 'Quản Trị Viên', 'admin', 0)
")->execute([$adminPassHash]);
$adminId = (int)$pdo->lastInsertId();

// 3. Thêm 6 học kỳ
echo "3. Đang tạo 6 học kỳ và định mức tín chỉ...\n";
$semestersData = [
    [1, 2024, 700000, 0, '2025-01-15'],
    [2, 2024, 750000, 0, '2025-06-15'],
    [1, 2025, 800000, 0, '2026-01-15'],
    [2, 2025, 850000, 0, '2026-06-15'],
    [1, 2026, 900000, 1, '2027-01-15'],
    [2, 2026, 950000, 1, '2027-06-15'],
];

$semStmt = $pdo->prepare("
    INSERT INTO semesters (term_no, academic_year_start, registration_open, registration_close)
    VALUES (?, ?, ?, ?)
");
$rateStmt = $pdo->prepare("
    INSERT INTO credit_rates (semester_id, price_per_credit)
    VALUES (?, ?)
");

$semIds = [];
$semRates = [];
foreach ($semestersData as $sd) {
    $semStmt->execute([$sd[0], $sd[1], $sd[3], $sd[4]]);
    $sId = (int)$pdo->lastInsertId();
    $semIds[] = $sId;
    $semRates[$sId] = $sd[2];
    $rateStmt->execute([$sId, $sd[2]]);
}

// 4. Sinh 500 sinh viên
echo "4. Đang tạo 500 sinh viên và tài khoản...\n";
$familyNames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Phan', 'Vũ', 'Võ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ', 'Ngô', 'Dương', 'Lý'];
$middleNames = ['Văn', 'Thị', 'Minh', 'Hoàng', 'Đức', 'Hữu', 'Tuấn', 'Ngọc', 'Xuân', 'Hải'];
$givenNames = ['An', 'Bình', 'Cường', 'Dũng', 'Giang', 'Hải', 'Hùng', 'Khoa', 'Linh', 'Long', 'Mai', 'Nam', 'Phong', 'Quân', 'Sơn', 'Thảo', 'Trang', 'Tuấn', 'Việt', 'Vy'];
$classes = ['D21CNTT01', 'D21CNTT02', 'D21DTVT01', 'D22CNTT01', 'D22DTVT01', 'D23QTKD01', 'D23ATTT01', 'D24CNTT01'];

$defaultStudentPass = password_hash('Student@123', PASSWORD_BCRYPT, ['cost' => 8]);

$pdo->beginTransaction();
$stInsert = $pdo->prepare("
    INSERT INTO students (student_code, full_name, class_name, name_search)
    VALUES (?, ?, ?, ?)
");
$userInsert = $pdo->prepare("
    INSERT INTO users (username, password_hash, full_name, role, student_id, must_change_password)
    VALUES (?, ?, ?, 'student', ?, 0)
");

$studentIds = [];
for ($i = 1; $i <= 500; $i++) {
    $code = sprintf('SV%04d', $i);
    $fn = $familyNames[mt_rand(0, count($familyNames) - 1)] . ' ' .
          $middleNames[mt_rand(0, count($middleNames) - 1)] . ' ' .
          $givenNames[mt_rand(0, count($givenNames) - 1)];
    $cls = $classes[mt_rand(0, count($classes) - 1)];
    $norm = normalize_vietnamese($fn);

    $stInsert->execute([$code, $fn, $cls, $norm]);
    $stId = (int)$pdo->lastInsertId();
    $studentIds[] = $stId;

    $username = sprintf('sv%03d', $i);
    $userInsert->execute([$username, $defaultStudentPass, $fn, $stId]);
}
$pdo->commit();

// 5. Sinh 3,000 hồ sơ học phí (500 sinh viên x 6 học kỳ)
echo "5. Đang tạo 3,000 hồ sơ học phí và chính sách miễn giảm...\n";
$pdo->beginTransaction();
$frInsert = $pdo->prepare("
    INSERT INTO fee_records (student_id, semester_id, registered_credits, rate_snapshot, gross_tuition)
    VALUES (?, ?, ?, ?, ?)
");
$exInsert = $pdo->prepare("
    INSERT INTO exemptions (fee_record_id, type, value, reason, created_by)
    VALUES (?, ?, ?, ?, ?)
");

$feeRecordIds = [];
foreach ($studentIds as $stId) {
    foreach ($semIds as $sId) {
        $credits = mt_rand(14, 22);
        $rate = $semRates[$sId];
        $gross = $credits * $rate;

        $frInsert->execute([$stId, $sId, $credits, $rate, $gross]);
        $frId = (int)$pdo->lastInsertId();
        $feeRecordIds[] = $frId;

        // ~10% hồ sơ có miễn giảm
        if (mt_rand(1, 10) === 1) {
            $isPercent = mt_rand(0, 1) === 1;
            if ($isPercent) {
                $pVal = [10, 20, 30, 50, 100][mt_rand(0, 4)];
                $exInsert->execute([$frId, 'percent', $pVal, "Miễn giảm chính sách {$pVal}%", $adminId]);
            } else {
                $fVal = mt_rand(1, 4) * 1000000;
                $exInsert->execute([$frId, 'fixed', $fVal, "Học bổng khuyến khích", $adminId]);
            }
        }
    }
}
$pdo->commit();

// 6. Sinh >= 50,000 ledger entries
echo "6. Đang tạo >= 50,000 giao dịch sổ cái (ledger_entries)...\n";
$targetEntries = 52000;
$entriesPerRecord = (int)ceil($targetEntries / count($feeRecordIds)); // ~18 per record

$methods = ['Tiền mặt', 'Chuyển khoản', 'Mã QR', 'Thẻ Visa/Mastercard'];
$batchSize = 2000;
$batch = [];
$totalGenerated = 0;

$pdo->beginTransaction();
$dayCounter = [];

for ($i = 0; $i < $targetEntries; $i++) {
    $frId = $feeRecordIds[$i % count($feeRecordIds)];
    
    // Day distribution between 2024-09-01 and 2026-11-14 (800 days)
    $dayOffset = mt_rand(0, 790);
    $entryDate = date('Y-m-d', strtotime("2024-09-01 +{$dayOffset} days"));
    $createdAt = $entryDate . ' ' . sprintf('%02d:%02d:%02d', mt_rand(7, 21), mt_rand(0, 59), mt_rand(0, 59));
    
    if (!isset($dayCounter[$entryDate])) {
        $dayCounter[$entryDate] = 1;
    }
    $seq = $dayCounter[$entryDate]++;

    // Entry types: 88% payment, 4% reversal, 4% transfer_in, 4% transfer_out
    $randType = mt_rand(1, 100);
    if ($randType <= 88) {
        $type = 'payment';
    } elseif ($randType <= 92) {
        $type = 'reversal';
    } elseif ($randType <= 96) {
        $type = 'transfer_in';
    } else {
        $type = 'transfer_out';
    }

    $amount = mt_rand(5, 50) * 100000; // 500,000 to 5,000,000
    $method = $methods[mt_rand(0, count($methods) - 1)];
    $rcpt = sprintf('PT-%s-%05d', date('Ymd', strtotime($entryDate)), $seq);
    $reqId = sprintf('REQ-PERF-%08d', $i + 1);
    $reason = ($type === 'payment') ? 'Thu học phí kỳ ' . mt_rand(1, 6) : 'Bút toán điều chỉnh ' . $type;
    $tgId = in_array($type, ['transfer_in', 'transfer_out'], true) ? sprintf('TG-%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff)) : null;

    $batch[] = [
        $frId, $type, $amount, $method, $entryDate, $rcpt, $reason, $reqId, $tgId, $adminId, $createdAt
    ];

    if (count($batch) >= $batchSize) {
        insert_ledger_batch($pdo, $batch);
        $totalGenerated += count($batch);
        $batch = [];
    }
}

if (!empty($batch)) {
    insert_ledger_batch($pdo, $batch);
    $totalGenerated += count($batch);
}
$pdo->commit();

function insert_ledger_batch(PDO $pdo, array $batch): void {
    $rowPlaces = [];
    $flatValues = [];
    foreach ($batch as $row) {
        $rowPlaces[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        foreach ($row as $val) {
            $flatValues[] = $val;
        }
    }
    $sql = "INSERT INTO ledger_entries (fee_record_id, entry_type, amount, method, entry_date, receipt_no, reason, request_id, transfer_group_id, created_by, created_at) VALUES " . implode(', ', $rowPlaces);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($flatValues);
}

// 7. Thống kê kết quả
$countStudents = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$countSemesters = $pdo->query("SELECT COUNT(*) FROM semesters")->fetchColumn();
$countFeeRecords = $pdo->query("SELECT COUNT(*) FROM fee_records")->fetchColumn();
$countLedger = $pdo->query("SELECT COUNT(*) FROM ledger_entries")->fetchColumn();

$elapsed = round(microtime(true) - $startTime, 2);

echo "=== HOÀN TẤT SINH DỮ LIỆU HIỆU NĂNG ===\n";
echo "- Thời gian thực thi: {$elapsed} giây\n";
echo "- Số lượng sinh viên: {$countStudents} (yêu cầu 500)\n";
echo "- Số lượng học kỳ: {$countSemesters} (yêu cầu 6)\n";
echo "- Số lượng hồ sơ học phí: {$countFeeRecords} (yêu cầu 3,000)\n";
echo "- Số lượng giao dịch sổ cái: {$countLedger} (yêu cầu >= 50,000)\n";

file_put_contents(__DIR__ . '/generation_metrics.json', json_encode([
    'database' => $dbName,
    'execution_time_seconds' => $elapsed,
    'students_count' => (int)$countStudents,
    'semesters_count' => (int)$countSemesters,
    'fee_records_count' => (int)$countFeeRecords,
    'ledger_entries_count' => (int)$countLedger,
    'seed' => 20261114,
    'created_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
