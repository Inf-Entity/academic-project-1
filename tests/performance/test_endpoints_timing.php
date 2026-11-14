<?php
$baseUrl = 'http://localhost/tuition-system/api';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');

// Get cookie and csrf
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
echo "CSRF: {$csrf}\n\n";

$endpoints = [
    'fee-record' => '/fee-records/1',
    'badges' => '/nav/badges',
    'reports' => '/reports/summary',
    'history' => '/history?limit=100',
];

foreach ($endpoints as $name => $path) {
    $t0 = microtime(true);
    $ch = curl_init("{$baseUrl}{$path}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-CSRF-Token: ' . $csrf,
        'X-Tuition-Test-Db: tuition_perf',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $timeMs = round((microtime(true) - $t0) * 1000, 2);
    curl_close($ch);
    echo "{$name} ({$code}): {$timeMs} ms | length: " . strlen($res) . "\n";
}
