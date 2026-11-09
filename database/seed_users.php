<?php
/**
 * CLI-only setup script to securely hash and seed admin users
 * Usage: php database/seed_users.php [--db=tuition_system|tuition_test]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI execution only.\n";
    exit(1);
}

// Parse arguments
$dbName = 'tuition_system';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $dbName = substr($arg, 5);
    }
}

// Load config if available, otherwise use defaults
$configPath = dirname(__DIR__) . '/config/config.php';
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbUser = 'root'; // Setup script can use root or app user
$dbPass = '';

if (file_exists($configPath)) {
    $cfg = require $configPath;
    if (isset($cfg['db'])) {
        $dbHost = $cfg['db']['host'] ?? $dbHost;
        $dbPort = $cfg['db']['port'] ?? $dbPort;
        // If app user has write permission, try using config user first
        $dbUser = $cfg['db']['user'] ?? $dbUser;
        $dbPass = $cfg['db']['pass'] ?? $dbPass;
    }
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Fallback to root without password for fresh XAMPP setup
    try {
        $pdo = new PDO($dsn, 'root', '', $options);
    } catch (PDOException $e2) {
        fwrite(STDERR, "Database connection failed: " . $e2->getMessage() . "\n");
        exit(1);
    }
}

$pdo->exec("SET NAMES utf8mb4");

$adminUsers = [
    [
        'username'  => 'admin',
        'password'  => 'Admin@123',
        'full_name' => 'Quản trị viên Admin',
        'role'      => 'admin',
    ],
    [
        'username'  => 'staff01',
        'password'  => 'Staff@123',
        'full_name' => 'Cán bộ Staff 01',
        'role'      => 'admin',
    ]
];

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, failed_logins, locked_until)
     VALUES (:username, :password_hash, :full_name, :role, 0, NULL)
     ON DUPLICATE KEY UPDATE 
        password_hash = VALUES(password_hash),
        full_name = VALUES(full_name),
        role = VALUES(role),
        failed_logins = 0,
        locked_until = NULL"
);

foreach ($adminUsers as $user) {
    $hash = password_hash($user['password'], PASSWORD_DEFAULT);
    $stmt->execute([
        'username'      => $user['username'],
        'password_hash' => $hash,
        'full_name'     => $user['full_name'],
        'role'          => $user['role'],
    ]);
    echo "User '{$user['username']}' seeded successfully in '{$dbName}'.\n";
}

echo "Seed users script completed successfully.\n";
exit(0);
