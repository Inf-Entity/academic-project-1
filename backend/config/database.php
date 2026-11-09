<?php
/**
 * Database Configuration & Connection Provider
 * Student Tuition Management System
 */

declare(strict_types=1);

class Database {
    private static ?array $config = null;
    private static array $connections = [];

    public static function getConfig(?string $overrideDbName = null): array {
        if (self::$config === null) {
            $rootConfig = dirname(__DIR__, 2) . '/config/config.php';
            if (file_exists($rootConfig)) {
                $loaded = require $rootConfig;
                self::$config = $loaded['db'] ?? [];
            } else {
                self::$config = [
                    'host'    => '127.0.0.1',
                    'port'    => 3306,
                    'name'    => 'tuition_system',
                    'user'    => 'tuition_user',
                    'pass'    => 'TuitionApp2026!Sec',
                    'charset' => 'utf8mb4',
                ];
            }
        }

        $cfg = self::$config;
        if ($overrideDbName !== null) {
            $cfg['name'] = $overrideDbName;
        } elseif (getenv('TUITION_DB')) {
            $cfg['name'] = getenv('TUITION_DB');
        } elseif (isset($_SERVER['HTTP_X_TUITION_TEST_DB']) && $_SERVER['HTTP_X_TUITION_TEST_DB'] === 'tuition_test') {
            $remote = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($remote, ['127.0.0.1', '::1', 'localhost'], true)) {
                $cfg['name'] = 'tuition_test';
            }
        }
        return $cfg;
    }

    public static function getConnection(?string $overrideDbName = null): PDO {
        $key = $overrideDbName ?? 'default';
        if (isset(self::$connections[$key])) {
            return self::$connections[$key];
        }

        $cfg = self::getConfig($overrideDbName);
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ];

        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $pdo->exec("SET NAMES utf8mb4");
        // Đồng bộ múi giờ phiên làm việc MySQL sang Asia/Ho_Chi_Minh (+07:00)
        $pdo->exec("SET time_zone = '+07:00'");

        self::$connections[$key] = $pdo;
        return $pdo;
    }
}

return Database::getConfig();
