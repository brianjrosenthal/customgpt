<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

header('Content-Type: text/plain');

echo "=== Environment Test ===\n\n";

// Test 1: Can we write to /tmp?
$testFile = '/tmp/test_write_' . time() . '.txt';
$canWrite = file_put_contents($testFile, 'test');
echo "1. Write to /tmp: " . ($canWrite ? "SUCCESS" : "FAILED") . "\n";
if ($canWrite) {
    @unlink($testFile);
}

// Test 2: What's the PATH?
echo "\n2. PATH: " . ($_SERVER['PATH'] ?? 'NOT SET') . "\n";

// Test 3: Can we find python3?
$pythonPath = trim(shell_exec('which python3 2>&1') ?: 'NOT FOUND');
echo "\n3. Python3 location: " . $pythonPath . "\n";

// Test 4: Can we execute a simple command?
exec('echo "test"', $output, $returnCode);
echo "\n4. exec() test: " . ($returnCode === 0 ? "SUCCESS" : "FAILED (code: $returnCode)") . "\n";
echo "   Output: " . implode(', ', $output) . "\n";

// Test 5: Check configured Python path
$configuredPythonPath = defined('PYTHON_PATH') ? PYTHON_PATH : 'python3';
echo "\n5. Configured Python path: {$configuredPythonPath}\n";
exec("{$configuredPythonPath} --version 2>&1", $pythonOutput, $pythonReturnCode);
echo "   Return code: $pythonReturnCode\n";
echo "   Output: " . implode("\n   ", $pythonOutput) . "\n";

// Test 6: Check script path
$scriptPath = dirname(__DIR__) . '/scripts/generate_chunks.py';
echo "\n6. Script path: $scriptPath\n";
echo "   Exists: " . (file_exists($scriptPath) ? "YES" : "NO") . "\n";
echo "   Readable: " . (is_readable($scriptPath) ? "YES" : "NO") . "\n";
echo "   Executable: " . (is_executable($scriptPath) ? "YES" : "NO") . "\n";

// Test 7: Try the actual command with configured Python
$testLogFile = '/tmp/test_python_' . time() . '.log';
$command = sprintf(
    '%s %s 1 %s 2>&1 &',
    escapeshellcmd($configuredPythonPath),
    escapeshellarg($scriptPath),
    escapeshellarg($testLogFile)
);
echo "\n7. Test command with configured Python: $command\n";
exec($command, $cmdOutput, $cmdReturnCode);
echo "   Return code: $cmdReturnCode\n";
if (!empty($cmdOutput)) {
    echo "   Output: " . implode("\n   ", $cmdOutput) . "\n";
}

// Wait a moment and check if file was created
sleep(2);
if (file_exists($testLogFile)) {
    echo "\n   Log file created! Content:\n";
    echo "   " . str_replace("\n", "\n   ", file_get_contents($testLogFile)) . "\n";
    @unlink($testLogFile);
} else {
    echo "\n   Log file NOT created\n";
}

echo "\n=== End Test ===\n";
