<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
Application::init();
require_admin();

// Get Custom GPT ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Verify CustomGPT exists
$gpt = CustomGPTManagement::findById($id);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Check if any chunks exist
try {
    $conn = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare(
        "SELECT COUNT(*) as count 
         FROM customgpt_document_chunks c
         JOIN customgpt_documents d ON c.customgpt_document_id = d.id
         WHERE d.customgpt_id = ?"
    );
    $stmt->execute([$id]);
    $chunkCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    if ($chunkCount === 0) {
        header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('No chunks found. Please run "Generate Chunks from Files" first.'));
        exit;
    }
} catch (PDOException $e) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('Database error: ' . $e->getMessage()));
    exit;
}

// Generate a unique token for this session
$token = md5(session_id() . time() . $id . bin2hex(random_bytes(8)));
$_SESSION['embedding_generation_token'] = $token;
$_SESSION['embedding_generation_customgpt_id'] = $id;

// Create log file path
$logFile = "/tmp/embedding_generation_{$id}_{$token}.log";

// Get the Python executable path from config, with fallback
$pythonPath = defined('PYTHON_PATH') ? PYTHON_PATH : 'python3';

// Get the script path
$scriptPath = dirname(__DIR__) . '/scripts/generate_embeddings.py';

// Try to create the log file and see if it succeeds
$logCreated = @file_put_contents($logFile, "=== Vector Embeddings Generation Debug Info ===\n");
if ($logCreated === false) {
    // If log file creation fails, redirect with error
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('Failed to create log file. Check /tmp permissions.'));
    exit;
}
file_put_contents($logFile, "CustomGPT ID: {$id}\n", FILE_APPEND);
file_put_contents($logFile, "Python Path: {$pythonPath}\n", FILE_APPEND);
file_put_contents($logFile, "Script Path: {$scriptPath}\n", FILE_APPEND);
file_put_contents($logFile, "Log File: {$logFile}\n", FILE_APPEND);
file_put_contents($logFile, "Script exists: " . (file_exists($scriptPath) ? 'yes' : 'NO') . "\n", FILE_APPEND);
file_put_contents($logFile, "Script readable: " . (is_readable($scriptPath) ? 'yes' : 'NO') . "\n", FILE_APPEND);
file_put_contents($logFile, "===================================\n\n", FILE_APPEND);

// Build the command to run in background
// Redirect stderr to the log file as well so we can see errors
$command = sprintf(
    '%s %s %d %s 2>&1 &',
    escapeshellcmd($pythonPath),
    escapeshellarg($scriptPath),
    $id,
    escapeshellarg($logFile)
);

file_put_contents($logFile, "Executing command:\n{$command}\n\n", FILE_APPEND);

// Execute the command in background
exec($command, $output, $returnCode);

file_put_contents($logFile, "Command return code: {$returnCode}\n", FILE_APPEND);
if (!empty($output)) {
    file_put_contents($logFile, "Command output: " . implode("\n", $output) . "\n", FILE_APPEND);
}
file_put_contents($logFile, "\n=== Python Script Output ===\n\n", FILE_APPEND);

// Redirect to progress page
header('Location: /customgpts/generate_embeddings.php?id=' . $id);
exit;
