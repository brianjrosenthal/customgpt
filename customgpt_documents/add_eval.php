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

$ctx = UserContext::getLoggedInUserContext();
$maxSize = 10 * 1024 * 1024; // 10MB

// Handle multiple file uploads
$successCount = 0;
$failedFiles = [];
$uploadedFiles = [];

// Check if files were uploaded
if (!isset($_FILES['document_files']) || !is_array($_FILES['document_files']['name'])) {
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&err=' . urlencode('No files were uploaded.'));
    exit;
}

// Process each file
$fileCount = count($_FILES['document_files']['name']);

for ($i = 0; $i < $fileCount; $i++) {
    // Extract file data for this specific file
    $file = [
        'name' => $_FILES['document_files']['name'][$i],
        'type' => $_FILES['document_files']['type'][$i],
        'tmp_name' => $_FILES['document_files']['tmp_name'][$i],
        'error' => $_FILES['document_files']['error'][$i],
        'size' => $_FILES['document_files']['size'][$i]
    ];
    
    $fileName = $file['name'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $failedFiles[] = "$fileName: File is too large (max 10MB)";
                break;
            case UPLOAD_ERR_NO_FILE:
                $failedFiles[] = "$fileName: No file uploaded";
                break;
            default:
                $failedFiles[] = "$fileName: Upload failed";
        }
        continue;
    }
    
    // Validate file size
    if ($file['size'] > $maxSize) {
        $failedFiles[] = "$fileName: File exceeds 10MB limit";
        continue;
    }
    
    // Try to upload the file
    try {
        $documentId = CustomGPTDocumentManagement::createDocument($ctx, $customgptId, $file);
        $successCount++;
        $uploadedFiles[] = $fileName;
    } catch (Exception $e) {
        $failedFiles[] = "$fileName: " . $e->getMessage();
    }
}

// Build result message
if ($successCount > 0 && empty($failedFiles)) {
    // All files succeeded
    $msg = $successCount . ' document' . ($successCount > 1 ? 's' : '') . ' uploaded successfully.';
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&msg=' . urlencode($msg));
} elseif ($successCount > 0 && !empty($failedFiles)) {
    // Some succeeded, some failed
    $msg = $successCount . ' document' . ($successCount > 1 ? 's' : '') . ' uploaded successfully. ' . count($failedFiles) . ' failed: ' . implode('; ', $failedFiles);
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&msg=' . urlencode($msg));
} else {
    // All failed
    $err = 'All uploads failed: ' . implode('; ', $failedFiles);
    header('Location: /customgpts/edit.php?id=' . $customgptId . '&err=' . urlencode($err));
}
exit;
