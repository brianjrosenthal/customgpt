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
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$is_public = !empty($_POST['is_public']) ? 1 : 0;

// Validation
$errors = [];
if ($name === '') {
    $errors[] = 'Name is required.';
}

if (!empty($errors)) {
    // Redirect back to form with errors and form data
    $params = [
        'err' => implode(' ', $errors),
        'name' => $name,
        'description' => $description,
        'is_public' => $is_public
    ];
    $query = http_build_query($params);
    header('Location: /customgpts/add.php?' . $query);
    exit;
}

try {
    // Create Custom GPT
    $data = [
        'name' => $name,
        'description' => $description,
        'is_public' => $is_public
    ];
    
    $ctx = UserContext::getLoggedInUserContext();
    $customGptId = CustomGPTManagement::createCustomGPT($ctx, $data);
    
    // Success - redirect to edit page for the new Custom GPT
    header('Location: /customgpts/edit.php?id=' . $customGptId . '&msg=' . urlencode('Custom GPT created successfully.'));
    exit;
    
} catch (Exception $e) {
    // Error creating Custom GPT - redirect back to form
    $params = [
        'err' => 'Error creating Custom GPT: ' . $e->getMessage(),
        'name' => $name,
        'description' => $description,
        'is_public' => $is_public
    ];
    $query = http_build_query($params);
    header('Location: /customgpts/add.php?' . $query);
    exit;
}
