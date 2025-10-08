<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

// Set JSON response header
header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Manually validate CSRF token (for JSON response)
$token = $input['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$customgptId = isset($input['customgpt_id']) ? (int)$input['customgpt_id'] : 0;
$query = isset($input['query']) ? trim($input['query']) : '';

// Validate inputs
if ($customgptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Custom GPT ID']);
    exit;
}

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Query is required']);
    exit;
}

try {
    // Get FastAPI configuration
    $fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
    $fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
    $fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";
    
    // Prepare request payload
    $payload = json_encode([
        'query' => $query,
        'top_k' => 10 // Default value
    ]);
    
    // Make API call to FastAPI to start query execution
    $apiUrl = "{$fastapiUrl}/api/v1/execute-query/{$customgptId}";
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        throw new Exception('Failed to connect to query execution service. Please ensure the FastAPI service is running.');
    }
    
    $result = json_decode($response, true);
    
    if (!$result) {
        throw new Exception('Invalid response from query execution service');
    }
    
    if (isset($result['detail'])) {
        throw new Exception($result['detail']);
    }
    
    // Return successful response with job ID
    echo json_encode([
        'success' => true,
        'job_id' => $result['job_id'],
        'message' => $result['message'] ?? 'Query execution started'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
