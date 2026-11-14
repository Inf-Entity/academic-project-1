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

$stmtAdmin = $pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until)
     VALUES (:username, :password_hash, :full_name, 'admin', NULL, 0, 0, NULL)
     ON DUPLICATE KEY UPDATE 
        password_hash = VALUES(password_hash),
        full_name = VALUES(full_name),
        role = 'admin',
        student_id = NULL,
        must_change_password = 0,
        failed_logins = 0,
        locked_until = NULL"
);

foreach ($adminUsers as $user) {
    $hash = password_hash($user['password'], PASSWORD_DEFAULT);
    $stmtAdmin->execute([
        'username'      => $user['username'],
        'password_hash' => $hash,
        'full_name'     => $user['full_name'],
    ]);
    echo "Admin user '{$user['username']}' seeded successfully in '{$dbName}'.\n";
}

$studentUsers = [
    [
        'student_code'         => 'SV001',
        'username'             => 'sv001',
        'password'             => 'Student@001',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV002',
        'username'             => 'sv002',
        'password'             => 'Student@002',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV003',
        'username'             => 'sv003',
        'password'             => 'Student@003',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV011',
        'username'             => 'sv011',
        'password'             => 'Student@011',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV006',
        'username'             => 'sv006',
        'password'             => 'Student@006',
        'must_change_password' => 1,
    ],
    [
        'student_code'         => 'SV014',
        'username'             => 'sv014',
        'password'             => 'Student@014',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV015',
        'username'             => 'sv015',
        'password'             => 'Student@015',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV018',
        'username'             => 'sv018',
        'password'             => 'Student@018',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV020',
        'username'             => 'sv020',
        'password'             => 'Student@020',
        'must_change_password' => 0,
    ],
    [
        'student_code'         => 'SV023',
        'username'             => 'sv023',
        'password'             => 'Student@023',
        'must_change_password' => 1,
    ],
    [
        'student_code'         => 'SV024',
        'username'             => 'sv024',
        'password'             => 'Student@024',
        'must_change_password' => 0,
        'failed_logins'        => 5,
        'locked_until'         => '2030-01-01 00:00:00',
    ],
];

$findStudentStmt = $pdo->prepare("SELECT id, full_name FROM students WHERE student_code = :code LIMIT 1");
$stmtStudent = $pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, student_id, must_change_password, failed_logins, locked_until)
     VALUES (:username, :password_hash, :full_name, 'student', :student_id, :must_change, :failed, :locked)
     ON DUPLICATE KEY UPDATE 
        password_hash = VALUES(password_hash),
        full_name = VALUES(full_name),
        role = 'student',
        student_id = VALUES(student_id),
        must_change_password = VALUES(must_change_password),
        failed_logins = VALUES(failed_logins),
        locked_until = VALUES(locked_until)"
);

foreach ($studentUsers as $su) {
    $findStudentStmt->execute(['code' => $su['student_code']]);
    $st = $findStudentStmt->fetch();
    if (!$st) {
        echo "Warning: Student '{$su['student_code']}' not found in students table. Skipping user.\n";
        continue;
    }
    $hash = password_hash($su['password'], PASSWORD_DEFAULT);
    $stmtStudent->execute([
        'username'      => $su['username'],
        'password_hash' => $hash,
        'full_name'     => $st['full_name'],
        'student_id'    => $st['id'],
        'must_change'   => $su['must_change_password'],
        'failed'        => $su['failed_logins'] ?? 0,
        'locked'        => $su['locked_until'] ?? null,
    ]);
    echo "Student user '{$su['username']}' seeded successfully in '{$dbName}'.\n";
}

echo "Seed users script completed successfully.\n";
exit(0);
