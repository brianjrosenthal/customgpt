<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

// Verify CSRF token
require_csrf();

// Get form data
$customgptId = isset($_POST['customgpt_id']) ? (int)$_POST['customgpt_id'] : 0;
$query = isset($_POST['query']) ? trim($_POST['query']) : '';
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Validate inputs
if ($customgptId <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

if (empty($query)) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Please enter a query.'));
    exit;
}

// Verify CustomGPT exists
$gpt = CustomGPTManagement::findById($customgptId);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Handle Test Retrieval action
if ($action === 'test_retrieval') {
    // Get FastAPI configuration
    $fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
    $fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
    $fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";
    
    // Prepare request payload
    $payload = json_encode([
        'query' => $query,
        'top_k' => 10
    ]);
    
    // Make synchronous API call to FastAPI
    $apiUrl = "{$fastapiUrl}/api/v1/retrieve/{$customgptId}";
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($apiUrl, false, $context);
    
    if ($response === false) {
        header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Failed to connect to retrieval service. Please ensure the FastAPI service is running.'));
        exit;
    }
    
    $result = json_decode($response, true);
    
    if (!$result || isset($result['detail'])) {
        $errorMsg = isset($result['detail']) ? $result['detail'] : 'Invalid response from retrieval service.';
        header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode($errorMsg));
        exit;
    }
    
    // Store results in session for display
    $_SESSION['retrieval_results'] = [
        'query' => $query,
        'customgpt_id' => $customgptId,
        'results' => $result
    ];
    
    // Redirect to results page
    header('Location: /customgpts/query_results.php?id=' . $customgptId);
    exit;
}

// Unknown action
header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Invalid action.'));
exit;
