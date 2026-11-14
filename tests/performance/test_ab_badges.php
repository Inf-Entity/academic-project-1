<?php
$baseUrl = 'http://localhost/tuition-system/api';
$abPath = 'C:\\xampp\\apache\\bin\\ab.exe';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=tuition_perf;charset=utf8mb4', 'root', '');
$pdo->exec("INSERT INTO user_nav_state (user_id, nav_key, last_seen_entry_id) VALUES (1, 'history', 52000) ON DUPLICATE KEY UPDATE last_seen_entry_id = 52000");

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

$abCmd = "\"{$abPath}\" -n 200 -c 20 -H \"Cookie: {$cookie}\" -H \"X-Tuition-Test-Db: tuition_perf\" -H \"X-CSRF-Token: {$csrf}\" \"{$baseUrl}/nav/badges\" 2>&1";
exec($abCmd, $out, $ret);
echo implode("\n", array_slice($out, -20));
