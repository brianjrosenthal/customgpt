<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_admin();

// Secure file download for CustomGPT documents
$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($documentId <= 0) { 
    http_response_code(400); 
    exit('Missing document ID'); 
}

// Get document to verify it exists and get file_id
$doc = CustomGPTDocumentManagement::findById($documentId);
if (!$doc) { 
    http_response_code(404); 
    exit('Document not found'); 
}

$fileId = (int)$doc['file_id'];

// Get file data using Files class
$blob = Files::getSecureFileForDownload($fileId);
if (!$blob) { 
    http_response_code(404); 
    exit('File not found'); 
}

$data = (string)$blob['data'];
$len = (int)($blob['byte_length'] ?? strlen($data));
$ctype = (string)($blob['content_type'] ?? '');
if ($ctype === '') $ctype = 'application/octet-stream';
$name = (string)($blob['original_filename'] ?? 'file');

// Helper: rawbasename preserving multibyte while escaping quotes
function rawbasename(string $name): string {
    $name = str_replace('"', '', $name);
    return basename($name);
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . $len);
header('Content-Disposition: inline; filename="' . rawbasename($name) . '"');
header('X-Content-Type-Options: nosniff');
echo $data;
exit;
