<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

header('Content-Type: application/json');

// Get Custom GPT ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo json_encode(['error' => 'Invalid Custom GPT ID']);
    exit;
}

// Verify we have a valid session token
if (!isset($_SESSION['embedding_generation_token']) || !isset($_SESSION['embedding_generation_customgpt_id'])) {
    echo json_encode(['error' => 'No active embedding generation session']);
    exit;
}

$token = $_SESSION['embedding_generation_token'];
$sessionCustomGptId = $_SESSION['embedding_generation_customgpt_id'];

// Verify the session CustomGPT ID matches the requested ID
if ($sessionCustomGptId !== $id) {
    echo json_encode(['error' => 'Invalid session for this Custom GPT']);
    exit;
}

// Validate token format (should be 32 character hex string)
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    echo json_encode(['error' => 'Invalid token format']);
    exit;
}

// Construct the expected log file path
$expectedFilename = "embedding_generation_{$id}_{$token}.log";
$logPath = "/tmp/{$expectedFilename}";

// Security check: Ensure the resolved path is still in /tmp
$realPath = realpath($logPath);
if ($realPath === false) {
    // File doesn't exist yet, which is okay
    echo json_encode([
        'content' => 'Waiting for process to start...',
        'completed' => false
    ]);
    exit;
}

// Verify the real path is actually in /tmp (prevent directory traversal)
if (dirname($realPath) !== '/tmp' && dirname($realPath) !== '/private/tmp') {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Read the log file
if (!file_exists($logPath)) {
    echo json_encode([
        'content' => 'Waiting for process to start...',
        'completed' => false
    ]);
    exit;
}

$content = file_get_contents($logPath);

// Check if the process has completed
$completed = false;
if (strpos($content, '✓ Vector embeddings generation completed!') !== false || 
    strpos($content, '✗ ERROR:') !== false) {
    $completed = true;
    
    // Clean up session variables
    unset($_SESSION['embedding_generation_token']);
    unset($_SESSION['embedding_generation_customgpt_id']);
    
    // Schedule log file cleanup (delete after reading if completed)
    // We'll keep it for a minute in case of page refreshes
    $fileAge = time() - filemtime($logPath);
    if ($fileAge > 60) {
        @unlink($logPath);
    }
}

echo json_encode([
    'content' => $content,
    'completed' => $completed
]);
