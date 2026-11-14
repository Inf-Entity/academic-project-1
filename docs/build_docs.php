<?php
/**
 * PHP Wrapper for Documentation Build Pipeline
 * Invokes python docs/build_docs.py and verifies all 5 artifacts
 * Usage: php docs/build_docs.php
 */

declare(strict_types=1);

echo "====================================================================\n";
echo " DOCUMENTATION BUILD RUNNER (PHP + Python)\n";
echo "====================================================================\n";

$baseDir = dirname(__DIR__);
$pyScript = $baseDir . '/docs/build_docs.py';

if (!file_exists($pyScript)) {
    die("Error: Python build script not found at {$pyScript}\n");
}

$cmd = "python \"{$pyScript}\" 2>&1";
echo "Executing: {$cmd}\n\n";

exec($cmd, $output, $returnCode);

echo implode("\n", $output) . "\n\n";

if ($returnCode !== 0) {
    echo "\033[31m[ERROR] Documentation generation failed with exit code {$returnCode}\033[0m\n";
    exit($returnCode);
}

// Verify output files
$expectedFiles = [
    'Requirements_Analysis.docx' => $baseDir . '/docs/Requirements_Analysis.docx',
    'ERD.pdf'                    => $baseDir . '/docs/ERD.pdf',
    'UseCase_Diagram.pdf'        => $baseDir . '/docs/UseCase_Diagram.pdf',
    'Installation_Guide.docx'    => $baseDir . '/docs/Installation_Guide.docx',
    'Functional_Test_Report.docx'=> $baseDir . '/docs/Functional_Test_Report.docx',
];

echo "Verifying generated deliverables:\n";
$allValid = true;

foreach ($expectedFiles as $name => $path) {
    if (file_exists($path) && filesize($path) > 1024) {
        $sizeKb = round(filesize($path) / 1024, 1);
        echo "\033[32m  [✓] {$name} ({$sizeKb} KB)\033[0m\n";
    } else {
        echo "\033[31m  [✗] {$name} (Missing or invalid file)\033[0m\n";
        $allValid = false;
    }
}

echo "====================================================================\n";
if ($allValid) {
    echo "\033[32m\033[1m 🎉 ALL 5 DOCUMENTATION ARTIFACTS VERIFIED SUCCESSFULLY!\033[0m\n";
    exit(0);
} else {
    echo "\033[31m\033[1m ⚠️ ONE OR MORE DOCUMENTS FAILED VERIFICATION!\033[0m\n";
    exit(1);
}
