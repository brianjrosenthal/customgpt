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
$rerankedDataJson = isset($_POST['reranked_data']) ? $_POST['reranked_data'] : '';

// Validate inputs
if ($customgptId <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

if (empty($query) || empty($rerankedDataJson)) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Missing query or reranked data.'));
    exit;
}

// Decode reranked data
$rerankedChunks = json_decode($rerankedDataJson, true);
if (!$rerankedChunks) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Invalid reranked data.'));
    exit;
}

// Verify CustomGPT exists
$gpt = CustomGPTManagement::findById($customgptId);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Get FastAPI configuration
$fastapiHost = defined('FASTAPI_HOST') ? FASTAPI_HOST : 'localhost';
$fastapiPort = defined('FASTAPI_PORT') ? FASTAPI_PORT : 8001;
$fastapiUrl = "http://{$fastapiHost}:{$fastapiPort}";

// Prepare request payload for prompt generation
$payload = json_encode([
    'query' => $query,
    'reranked_chunks' => $rerankedChunks
]);

// Make synchronous API call to FastAPI for prompt generation
$apiUrl = "{$fastapiUrl}/api/v1/generate-prompt/{$customgptId}";

$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $payload,
        'timeout' => 60, // Longer timeout for prompt generation
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($apiUrl, false, $context);

if ($response === false) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Failed to connect to prompt generation service. Please ensure the FastAPI service is running.'));
    exit;
}

$result = json_decode($response, true);

if (!$result || isset($result['detail'])) {
    $errorMsg = isset($result['detail']) ? $result['detail'] : 'Invalid response from prompt generation service.';
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode($errorMsg));
    exit;
}

$prompt = $result['prompt'];
$contextDocuments = $result['context_documents'];

header_html('Generated Prompt - ' . htmlspecialchars($gpt['name']));
?>

<style>
    .prompt-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .breadcrumb {
        margin-bottom: 20px;
        font-size: 14px;
        color: #666;
    }
    
    .breadcrumb a {
        color: #007bff;
        text-decoration: none;
    }
    
    .breadcrumb a:hover {
        text-decoration: underline;
    }
    
    .query-display {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 24px;
    }
    
    .query-display h3 {
        margin: 0 0 8px 0;
        font-size: 16px;
        color: #666;
    }
    
    .query-text {
        font-size: 15px;
        color: #333;
        line-height: 1.5;
    }
    
    .context-section {
        margin-bottom: 24px;
    }
    
    .context-section h3 {
        font-size: 18px;
        margin-bottom: 12px;
        color: #333;
    }
    
    .context-documents {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .context-doc {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 12px;
    }
    
    .context-doc-header {
        font-weight: bold;
        color: #007bff;
        margin-bottom: 8px;
        font-size: 14px;
    }
    
    .context-doc-info {
        font-size: 12px;
        color: #666;
        margin-bottom: 8px;
    }
    
    .prompt-section {
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .prompt-section h3 {
        font-size: 18px;
        margin-bottom: 16px;
        color: #333;
    }
    
    .prompt-text {
        font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
        font-size: 13px;
        line-height: 1.6;
        color: #333;
        white-space: pre-wrap;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 16px;
        max-height: 600px;
        overflow-y: auto;
    }
    
    .actions {
        margin-top: 24px;
        text-align: center;
    }
    
    .copy-button {
        margin-left: 12px;
    }
</style>

<div class="prompt-container">
    <div class="breadcrumb">
        <a href="/customgpts/list.php">Custom GPTs</a> / 
        <a href="/customgpts/edit.php?id=<?= $customgptId ?>"><?= htmlspecialchars($gpt['name']) ?></a> / 
        <a href="/customgpts/query.php?id=<?= $customgptId ?>">Test Retrieval</a> /
        Generated Prompt
    </div>
    
    <h2>Generated Prompt</h2>
    
    <div class="query-display">
        <h3>Original Query:</h3>
        <div class="query-text"><?= htmlspecialchars($query) ?></div>
    </div>
    
    <div class="context-section">
        <h3>Context Documents Used (<?= count($contextDocuments) ?>)</h3>
        <div class="context-documents">
            <?php foreach ($contextDocuments as $idx => $doc): ?>
                <div class="context-doc">
                    <div class="context-doc-header">DOCUMENT #<?= $idx + 1 ?> (ID: <?= $doc['document_id'] ?>)</div>
                    <div class="context-doc-info">
                        <?php if (isset($doc['filename'])): ?>
                            File: <?= htmlspecialchars($doc['filename']) ?> | 
                        <?php endif; ?>
                        Chunks: <?= $doc['chunk_count'] ?> | 
                        <a href="/customgpt_documents/download_file.php?id=<?= $doc['document_id'] ?>" target="_blank">Download</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="prompt-section">
        <h3>Generated Prompt</h3>
        <div class="prompt-text" id="promptText"><?= htmlspecialchars($prompt) ?></div>
    </div>
    
    <div class="actions">
        <a class="button" href="/customgpts/query.php?id=<?= $customgptId ?>">New Query</a>
        <a class="button" href="/customgpts/edit.php?id=<?= $customgptId ?>">Back to Custom GPT</a>
        <button class="button primary copy-button" onclick="copyPrompt()">Copy Prompt to Clipboard</button>
    </div>
</div>

<script>
function copyPrompt() {
    const promptText = document.getElementById('promptText').textContent;
    navigator.clipboard.writeText(promptText).then(() => {
        const button = event.target;
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.style.backgroundColor = '#28a745';
        setTimeout(() => {
            button.textContent = originalText;
            button.style.backgroundColor = '';
        }, 2000);
    }).catch(err => {
        alert('Failed to copy prompt to clipboard');
        console.error('Copy failed:', err);
    });
}
</script>

<?php footer_html(); ?>
