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

// Get Document ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$customgptId = isset($_POST['customgpt_id']) ? (int)$_POST['customgpt_id'] : 0;

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid document ID.'));
    exit;
}

try {
    // Delete document
    $ctx = UserContext::getLoggedInUserContext();
    $success = CustomGPTDocumentManagement::deleteDocument($ctx, $id);
    
    if ($success) {
        // Success - redirect to CustomGPT edit page
        $redirectUrl = $customgptId > 0 
            ? '/customgpts/edit.php?id=' . $customgptId 
            : '/customgpts/list.php';
        header('Location: ' . $redirectUrl . '&msg=' . urlencode('Document deleted successfully.'));
    } else {
        // Failed to delete
        $redirectUrl = $customgptId > 0 
            ? '/customgpts/edit.php?id=' . $customgptId 
            : '/customgpts/list.php';
        header('Location: ' . $redirectUrl . '&err=' . urlencode('Failed to delete document.'));
    }
    exit;
    
} catch (Exception $e) {
    // Error deleting document
    $redirectUrl = $customgptId > 0 
        ? '/customgpts/edit.php?id=' . $customgptId 
        : '/customgpts/list.php';
    header('Location: ' . $redirectUrl . '&err=' . urlencode('Error deleting document: ' . $e->getMessage()));
    exit;
}
