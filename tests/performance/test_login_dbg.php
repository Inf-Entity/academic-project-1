<?php
$cookieFile = sys_get_temp_dir() . '/test_c.txt';
$ch = curl_init('http://localhost/tuition-system/api/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => 'admin', 'password' => 'Admin@123']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-Tuition-Test-Db: tuition_perf',
]);
$raw = curl_exec($ch);
curl_close($ch);
echo "Raw: " . $raw . "\n";
echo "Cookie content:\n" . (file_exists($cookieFile) ? file_get_contents($cookieFile) : 'NO FILE') . "\n";
