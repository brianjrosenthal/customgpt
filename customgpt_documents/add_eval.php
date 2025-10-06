<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /customgpts/list.php');
    exit;
}

require_csrf();

// Get CustomGPT ID
$customgptId = isset($_POST['customgpt_id']) ? (int)$_POST['customgpt_id'] : 0;

if ($customgptId <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Validation
$errors = [];

// Check if file was uploaded
if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    switch ($uploadError) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $errors[] = 'File is too large. Maximum size is 10MB.';
            break;
        case UPLOAD_ERR_NO_FILE:
            $errors[] = 'No file was uploaded.';
            break;
        default:
            $errors[] = 'File upload failed.';
    }
}

// Validate file size (10MB max)
if (empty($errors) && isset($_FILES['document_file']['size'])) {
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($_FILES['document_file']['size'] > $maxSize) {
        $errors[] = 'File is too large. Maximum size is 10MB.';
    }
}

if (!empty($errors)) {
    $query = http_build_query([
        'customgpt_id' => $customgptId,
        'err' => implode(' ', $errors)
    ]);
    header('Location: /customgpt_documents/add.php?' . $query);
    exit;
}

try {
    // Upload document
    $ctx = UserContext::getLoggedInUserContext();
    $documentId = CustomGPTDocumentManagement::createDocument($ctx, $customgptId, $_FILES['document_file']);
    
    // Success - redirect to CustomGPT edit page
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&msg=' . urlencode('Document uploaded successfully.'));
    exit;
    
} catch (Exception $e) {
    // Error uploading document - redirect to CustomGPT edit page
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&err=' . urlencode('Error uploading document: ' . $e->getMessage()));
    exit;
}
