<?php
require_once __DIR__ . '/../partials.php';
Application::init();
require_admin();

header('Content-Type: application/json');

// Get parameters
$customgptId = isset($_POST['customgpt_id']) ? (int)$_POST['customgpt_id'] : 0;
$chunkSize = isset($_POST['chunk_size']) ? (int)$_POST['chunk_size'] : 0;
$chunkOverlap = isset($_POST['chunk_overlap']) ? (int)$_POST['chunk_overlap'] : 0;

// Get configuration limits
$minSize = defined('CHUNK_SIZE_MIN') ? CHUNK_SIZE_MIN : 100;
$maxSize = defined('CHUNK_SIZE_MAX') ? CHUNK_SIZE_MAX : 5000;
$minOverlap = defined('CHUNK_OVERLAP_MIN') ? CHUNK_OVERLAP_MIN : 0;
$maxOverlap = defined('CHUNK_OVERLAP_MAX') ? CHUNK_OVERLAP_MAX : 1000;

// Validation
$errors = [];

// Validate CustomGPT ID
if ($customgptId <= 0) {
    $errors[] = 'Invalid CustomGPT ID.';
}

// Validate chunk size
if ($chunkSize < $minSize || $chunkSize > $maxSize) {
    $errors[] = "Chunk size must be between {$minSize} and {$maxSize} characters.";
}

// Validate chunk overlap
if ($chunkOverlap < $minOverlap || $chunkOverlap > $maxOverlap) {
    $errors[] = "Chunk overlap must be between {$minOverlap} and {$maxOverlap} characters.";
}

// Validate overlap is not greater than chunk size
if ($chunkOverlap >= $chunkSize) {
    $errors[] = 'Chunk overlap must be less than chunk size.';
}

// If there are errors, return them
if (!empty($errors)) {
    echo json_encode([
        'success' => false,
        'error' => implode(' ', $errors)
    ]);
    exit;
}

// Validation successful
echo json_encode([
    'success' => true,
    'message' => 'Configuration validated successfully.'
]);
