<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
Application::init();
require_admin();

// Get Custom GPT ID
$id = isset($_POST['customgpt_id']) ? (int)$_POST['customgpt_id'] : (isset($_GET['customgpt_id']) ? (int)$_GET['customgpt_id'] : 0);

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Get chunk configuration parameters with defaults
$chunkSize = isset($_POST['chunk_size']) ? (int)$_POST['chunk_size'] : (isset($_GET['chunk_size']) ? (int)$_GET['chunk_size'] : (defined('CHUNK_SIZE_DEFAULT') ? CHUNK_SIZE_DEFAULT : 1000));
$chunkOverlap = isset($_POST['chunk_overlap']) ? (int)$_POST['chunk_overlap'] : (isset($_GET['chunk_overlap']) ? (int)$_GET['chunk_overlap'] : (defined('CHUNK_OVERLAP_DEFAULT') ? CHUNK_OVERLAP_DEFAULT : 200));

// Validate chunk parameters
$minSize = defined('CHUNK_SIZE_MIN') ? CHUNK_SIZE_MIN : 100;
$maxSize = defined('CHUNK_SIZE_MAX') ? CHUNK_SIZE_MAX : 5000;
$minOverlap = defined('CHUNK_OVERLAP_MIN') ? CHUNK_OVERLAP_MIN : 0;
$maxOverlap = defined('CHUNK_OVERLAP_MAX') ? CHUNK_OVERLAP_MAX : 1000;

if ($chunkSize < $minSize || $chunkSize > $maxSize) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode("Invalid chunk size. Must be between {$minSize} and {$maxSize}."));
    exit;
}

if ($chunkOverlap < $minOverlap || $chunkOverlap > $maxOverlap) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode("Invalid chunk overlap. Must be between {$minOverlap} and {$maxOverlap}."));
    exit;
}

if ($chunkOverlap >= $chunkSize) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('Chunk overlap must be less than chunk size.'));
    exit;
}

// Verify CustomGPT exists
$gpt = CustomGPTManagement::findById($id);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Check if any documents exist
$documentCount = CustomGPTDocumentManagement::countDocumentsByCustomGPT($id);
if ($documentCount === 0) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('No documents to process. Please upload documents first.'));
    exit;
}

// Check if FastAPI service is available
$fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
$fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
$fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";

// Try to check service health
$healthCheck = @file_get_contents("{$fastapiUrl}/api/v1/health", false, stream_context_create([
    'http' => [
        'timeout' => 2,
        'ignore_errors' => true
    ]
]));

$useFastAPI = ($healthCheck !== false);

if ($useFastAPI) {
    // Use FastAPI service
    $apiUrl = "{$fastapiUrl}/api/v1/chunks/generate/{$id}";
    
    // Prepare payload with chunk configuration
    $payload = json_encode([
        'chunk_size' => $chunkSize,
        'chunk_overlap' => $chunkOverlap
    ]);
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        // FastAPI call failed, fall back to direct script execution
        $useFastAPI = false;
    } else {
        $result = json_decode($response, true);
        
        if (isset($result['job_id'])) {
            // Store job ID in session for progress tracking
            $_SESSION['chunk_generation_job_id'] = $result['job_id'];
            $_SESSION['chunk_generation_customgpt_id'] = $id;
            
            // Redirect to progress page
            header('Location: /customgpts/generate_chunks.php?id=' . $id);
            exit;
        } else {
            // Unexpected response format, fall back to direct script execution
            $useFastAPI = false;
        }
    }
}

// Fallback to direct script execution if FastAPI is not available
if (!$useFastAPI) {
    // Generate a unique token for this session
    $token = md5(session_id() . time() . $id . bin2hex(random_bytes(8)));
    $_SESSION['chunk_generation_token'] = $token;
    $_SESSION['chunk_generation_customgpt_id'] = $id;

    // Create log file path
    $logFile = "/tmp/chunk_generation_{$id}_{$token}.log";

    // Get the Python executable path from config, with fallback
    $pythonPath = defined('PYTHON_PATH') ? PYTHON_PATH : 'python3';

    // Get the script path
    $scriptPath = dirname(__DIR__) . '/scripts/generate_chunks.py';

    // Try to create the log file and see if it succeeds
    $logCreated = @file_put_contents($logFile, "=== Chunk Generation Debug Info ===\n");
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
    file_put_contents($logFile, "NOTE: FastAPI service not available, using direct script execution\n", FILE_APPEND);
    file_put_contents($logFile, "===================================\n\n", FILE_APPEND);

    // Build the command to run in background with chunk configuration
    // Redirect stderr to the log file as well so we can see errors
    $command = sprintf(
        '%s %s %d %s %d %d 2>&1 &',
        escapeshellcmd($pythonPath),
        escapeshellarg($scriptPath),
        $id,
        escapeshellarg($logFile),
        $chunkSize,
        $chunkOverlap
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
    header('Location: /customgpts/generate_chunks.php?id=' . $id);
    exit;
}
