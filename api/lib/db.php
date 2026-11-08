<?php
/**
 * Database Connection & Transaction Management
 */

declare(strict_types=1);

function get_db_config(?string $overrideDbName = null): array {
    static $config = null;
    if ($config === null) {
        $configPath = dirname(__DIR__, 2) . '/config/config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        } else {
            $config = require dirname(__DIR__, 2) . '/config/config.example.php';
        }
    }

    $dbCfg = $config['db'];
    if ($overrideDbName !== null) {
        $dbCfg['name'] = $overrideDbName;
    } elseif (getenv('TUITION_DB')) {
        $dbCfg['name'] = getenv('TUITION_DB');
    } elseif (isset($_SERVER['HTTP_X_TUITION_TEST_DB']) && $_SERVER['HTTP_X_TUITION_TEST_DB'] === 'tuition_test') {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (in_array($remote, ['127.0.0.1', '::1', 'localhost'], true)) {
            $dbCfg['name'] = 'tuition_test';
        }
    }
    return $dbCfg;
}

function get_db_connection(?string $overrideDbName = null): PDO {
    static $connections = [];

    $key = $overrideDbName ?? 'default';
    if (isset($connections[$key])) {
        return $connections[$key];
    }

    $cfg = get_db_config($overrideDbName);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    // Đồng bộ múi giờ phiên làm việc MySQL sang Asia/Ho_Chi_Minh (+07:00)
    $pdo->exec("SET time_zone = '+07:00'");

    $connections[$key] = $pdo;
    return $pdo;
}

/**
 * Execute a callback inside a transaction with automatic deadlock retry
 */
function db_transaction(callable $callback, ?PDO $pdo = null) {
    $pdo = $pdo ?? get_db_connection();
    $maxAttempts = 2;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Check for deadlock error code: 1213 (ER_LOCK_DEADLOCK) or SQLSTATE 40001
            $isDeadlock = ($e->getCode() === '40001' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1213));
            if ($isDeadlock && $attempt < $maxAttempts) {
                usleep(50000); // Wait 50ms before retrying
                continue;
            }
            throw $e;
        }
    }
}
