<?php
$baseUrl = 'http://localhost/tuition-system/api';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');

// Login
$ch = curl_init("{$baseUrl}/login");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => 'admin', 'password' => 'Admin@123']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Tuition-Test-Db: tuition_perf']);
$resp = curl_exec($ch);
$hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$h = substr($resp, 0, $hSize);
$b = substr($resp, $hSize);
curl_close($ch);

preg_match_all('/Set-Cookie:\s*(TUITION_SESSID=[^;\r\n]+)/i', $h, $m);
$cookie = end($m[1]);
$data = json_decode($b, true);
$csrf = $data['data']['csrf_token'];

echo "Cookie: {$cookie}\n";
echo "CSRF: {$csrf}\n";

// Setup fee record
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
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-CSRF-Token: ' . $csrf,
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
foreach ($curlHandles as $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$actualTotalPaid = (int)$pdo->query("SELECT SUM(amount) FROM ledger_entries WHERE fee_record_id = {$concurFrId} AND entry_type = 'payment'")->fetchColumn();

echo "Status counts:\n";
print_r($statusCounts);
echo "Actual Total Paid: " . number_format($actualTotalPaid) . " VND\n";
