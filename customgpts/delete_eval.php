<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /customgpts/list.php');
    exit;
}

require_csrf();

// Get Custom GPT ID
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

try {
    // Delete Custom GPT
    $ctx = UserContext::getLoggedInUserContext();
    $success = CustomGPTManagement::deleteCustomGPT($ctx, $id);
    
    if ($success) {
        // Success - redirect to list page with success message
        header('Location: /customgpts/list.php?msg=' . urlencode('Custom GPT deleted successfully.'));
    } else {
        // Failed to delete
        header('Location: /customgpts/list.php?err=' . urlencode('Failed to delete Custom GPT.'));
    }
    exit;
    
} catch (Exception $e) {
    // Error deleting Custom GPT - redirect to list page with error
    header('Location: /customgpts/list.php?err=' . urlencode('Error deleting Custom GPT: ' . $e->getMessage()));
    exit;
}
