<?php
$baseUrl = 'http://localhost/tuition-system/api';
$mh = curl_multi_init();
$handles = [];

for ($i = 0; $i < 20; $i++) {
    $ch = curl_init("{$baseUrl}/login");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => 'admin', 'password' => 'Admin@123']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Tuition-Test-Db: tuition_perf',
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$codes = [];
$sampleResponses = [];
foreach ($handles as $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $codes[$code] = ($codes[$code] ?? 0) + 1;
    $body = curl_multi_getcontent($ch);
    $sampleResponses[$code] = substr($body, 0, 150);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

print_r($codes);
print_r($sampleResponses);
