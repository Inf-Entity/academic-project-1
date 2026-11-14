<?php
/**
 * Functional Test Framework & Recorder
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/api/lib/db.php';
require_once dirname(__DIR__, 2) . '/api/lib/calc.php';
require_once dirname(__DIR__, 2) . '/api/lib/validate.php';
require_once dirname(__DIR__, 2) . '/api/lib/response.php';
require_once dirname(__DIR__, 2) . '/api/lib/auth.php';

class FunctionalTestRecorder {
    private static array $results = [];

    public static function record(
        string $id,
        string $module,
        string $title,
        string $requirement,
        string $steps,
        string $expected,
        string $actual,
        bool $passed
    ): void {
        global $totalTests, $passedTests, $failedTests;
        $totalTests++;
        if ($passed) {
            $passedTests++;
            echo "\033[32m  [✓] \033[0m" . "[{$id}] {$title}\n";
        } else {
            $failedTests++;
            echo "\033[31m  [✗] \033[1m" . "[{$id}] {$title}\033[0m\n";
            echo "      Expected: {$expected}\n";
            echo "      Actual:   {$actual}\n";
        }

        self::$results[] = [
            'id'          => $id,
            'module'      => $module,
            'title'       => $title,
            'requirement' => $requirement,
            'steps'       => $steps,
            'expected'    => $expected,
            'actual'      => $actual,
            'status'      => $passed ? 'PASS' : 'FAIL',
        ];
    }

    public static function getResults(): array {
        return self::$results;
    }

    public static function export(string $jsonPath, string $txtPath): void {
        $jsonDir = dirname($jsonPath);
        if (!is_dir($jsonDir)) {
            @mkdir($jsonDir, 0777, true);
        }
        file_put_contents($jsonPath, json_encode(self::$results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $out = "========================================================================================\n";
        $out .= "                  FUNCTIONAL TEST EXECUTION REPORT (FT-001 ONWARD)                     \n";
        $out .= "========================================================================================\n\n";
        $out .= sprintf("%-8s | %-16s | %-6s | %-45s\n", "ID", "MODULE", "STATUS", "TITLE");
        $out .= str_repeat("-", 88) . "\n";
        $passedCount = 0;
        foreach (self::$results as $r) {
            if ($r['status'] === 'PASS') $passedCount++;
            $out .= sprintf("%-8s | %-16s | %-6s | %-45s\n", 
                $r['id'], 
                substr($r['module'], 0, 16), 
                $r['status'], 
                mb_strimwidth($r['title'], 0, 45, '...')
            );
        }
        $out .= str_repeat("-", 88) . "\n";
        $out .= sprintf("Total Tests: %d | Passed: %d | Failed: %d\n", count(self::$results), $passedCount, count(self::$results) - $passedCount);
        file_put_contents($txtPath, $out);
    }
}

function test_ft(
    string $id,
    string $module,
    string $title,
    string $requirement,
    string $steps,
    string $expected,
    string $actual,
    bool $passed
): void {
    FunctionalTestRecorder::record($id, $module, $title, $requirement, $steps, $expected, $actual, $passed);
}
