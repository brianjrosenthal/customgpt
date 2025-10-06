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

// Get form data
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$is_public = !empty($_POST['is_public']) ? 1 : 0;

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Name is required.';
}

if (!empty($errors)) {
    // Redirect back to edit page with errors
    $query = http_build_query(['id' => $id, 'err' => implode(' ', $errors)]);
    header('Location: /customgpts/edit.php?' . $query);
    exit;
}

try {
    // Update Custom GPT
    $fields = [
        'name' => $name,
        'description' => $description,
        'is_public' => $is_public
    ];
    
    $ctx = UserContext::getLoggedInUserContext();
    $success = CustomGPTManagement::updateCustomGPT($ctx, $id, $fields);
    
    if ($success) {
        // Success - redirect to edit page with success message
        header('Location: /customgpts/edit.php?id=' . $id . '&msg=' . urlencode('Custom GPT updated successfully.'));
    } else {
        // No changes made
        header('Location: /customgpts/edit.php?id=' . $id . '&msg=' . urlencode('No changes were made.'));
    }
    exit;
    
} catch (Exception $e) {
    // Error updating Custom GPT - redirect back to edit page
    $query = http_build_query([
        'id' => $id,
        'err' => 'Error updating Custom GPT: ' . $e->getMessage()
    ]);
    header('Location: /customgpts/edit.php?' . $query);
    exit;
}
