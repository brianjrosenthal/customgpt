<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

// Set JSON response header
header('Content-Type: application/json');

$jobId = isset($_GET['job_id']) ? trim($_GET['job_id']) : '';

// Validate input
if (empty($jobId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Job ID is required']);
    exit;
}

try {
    // Get FastAPI configuration
    $fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
    $fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
    $fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";
    
    // Make API call to FastAPI to get job status
    $apiUrl = "{$fastapiUrl}/api/v1/query-jobs/{$jobId}/status";
    
    $options = [
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        throw new Exception('Failed to connect to query execution service.');
    }
    
    $result = json_decode($response, true);
    
    if (!$result) {
        throw new Exception('Invalid response from query execution service');
    }
    
    if (isset($result['detail'])) {
        throw new Exception($result['detail']);
    }
    
    // Return status information
    echo json_encode([
        'success' => true,
        'status' => $result['status'],
        'status_message' => $result['status_message'] ?? '',
        'result' => $result['result'] ?? null,
        'error' => $result['error'] ?? null
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
