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

// Check if we have a FastAPI job ID or fallback token
$hasJobId = isset($_SESSION['chunk_generation_job_id']) && isset($_SESSION['chunk_generation_customgpt_id']);
$hasToken = isset($_SESSION['chunk_generation_token']) && isset($_SESSION['chunk_generation_customgpt_id']);

if (!$hasJobId && !$hasToken) {
    echo json_encode(['error' => 'No active chunk generation session']);
    exit;
}

$sessionCustomGptId = $_SESSION['chunk_generation_customgpt_id'];

// Verify the session CustomGPT ID matches the requested ID
if ($sessionCustomGptId !== $id) {
    echo json_encode(['error' => 'Invalid session for this Custom GPT']);
    exit;
}

// Handle FastAPI job tracking
if ($hasJobId) {
    $jobId = $_SESSION['chunk_generation_job_id'];
    
    // Get FastAPI configuration
    $fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
    $fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
    $fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";
    
    // Fetch job status from FastAPI
    $statusUrl = "{$fastapiUrl}/api/v1/jobs/{$jobId}/status";
    $logsUrl = "{$fastapiUrl}/api/v1/jobs/{$jobId}/logs";
    
    $statusContext = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true
        ]
    ]);
    
    $statusResponse = @file_get_contents($statusUrl, false, $statusContext);
    
    if ($statusResponse === false) {
        echo json_encode(['error' => 'Failed to fetch job status from FastAPI service']);
        exit;
    }
    
    $statusData = json_decode($statusResponse, true);
    
    if (!$statusData || !isset($statusData['status'])) {
        echo json_encode(['error' => 'Invalid response from FastAPI service']);
        exit;
    }
    
    // Fetch logs
    $logsResponse = @file_get_contents($logsUrl, false, $statusContext);
    $logsData = $logsResponse ? json_decode($logsResponse, true) : null;
    
    $content = '';
    if ($logsData && isset($logsData['file_content'])) {
        $content = $logsData['file_content'];
    } elseif ($logsData && isset($logsData['logs']) && is_array($logsData['logs'])) {
        $content = implode("\n", $logsData['logs']);
    } else {
        $content = "Status: " . $statusData['status'];
        if (isset($statusData['progress'])) {
            $content .= "\nProgress: " . $statusData['progress'];
        }
    }
    
    $completed = ($statusData['status'] === 'completed' || $statusData['status'] === 'failed');
    
    if ($completed) {
        // Clean up session variables
        unset($_SESSION['chunk_generation_job_id']);
        unset($_SESSION['chunk_generation_customgpt_id']);
    }
    
    echo json_encode([
        'content' => $content,
        'completed' => $completed,
        'status' => $statusData['status'],
        'error' => $statusData['error'] ?? null
    ]);
    exit;
}

// Handle fallback token-based tracking
if ($hasToken) {
    $token = $_SESSION['chunk_generation_token'];
    
    // Validate token format (should be 32 character hex string)
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        echo json_encode(['error' => 'Invalid token format']);
        exit;
    }
    
    // Construct the expected log file path
    $expectedFilename = "chunk_generation_{$id}_{$token}.log";
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
    if (strpos($content, '✓ Chunk generation completed!') !== false || 
        strpos($content, '✗ ERROR:') !== false) {
        $completed = true;
        
        // Clean up session variables
        unset($_SESSION['chunk_generation_token']);
        unset($_SESSION['chunk_generation_customgpt_id']);
        
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
    exit;
}
