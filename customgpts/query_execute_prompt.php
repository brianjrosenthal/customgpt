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
$prompt = isset($input['prompt']) ? $input['prompt'] : '';

// Validate inputs
if ($customgptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Custom GPT ID']);
    exit;
}

if (empty($prompt)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Prompt is required']);
    exit;
}

try {
    // Get FastAPI configuration
    $fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
    $fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
    $fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";
    
    // Prepare request payload
    $payload = json_encode([
        'prompt' => $prompt
    ]);
    
    // Make API call to FastAPI
    $apiUrl = "{$fastapiUrl}/api/v1/query-chatgpt/{$customgptId}";
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 120, // Longer timeout for ChatGPT API
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        throw new Exception('Failed to connect to ChatGPT service. Please ensure the FastAPI service is running.');
    }
    
    $result = json_decode($response, true);
    
    if (!$result) {
        throw new Exception('Invalid response from ChatGPT service');
    }
    
    if (isset($result['detail'])) {
        throw new Exception($result['detail']);
    }
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'response' => $result['response'],
        'model' => $result['model'],
        'tokens_used' => $result['tokens_used'] ?? null
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
