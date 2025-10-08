<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

// Get Custom GPT ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Load Custom GPT data
$gpt = CustomGPTManagement::findById($id);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

$msg = isset($_GET['msg']) ? $_GET['msg'] : null;
$err = isset($_GET['err']) ? $_GET['err'] : null;

header_html('Query Knowledge Base - ' . htmlspecialchars($gpt['name']));
?>

<?php if ($msg): ?><p class="flash"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif; ?>

<style>
    .query-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .query-title {
        font-family: ui-sans-serif, -apple-system, system-ui, Segoe UI, Helvetica, Apple Color Emoji, Arial, sans-serif, Segoe UI Emoji, Segoe UI Symbol;
        font-size: 28px;
        color: #0d0d0d;
        margin-bottom: 24px;
    }
    
    .query-textarea {
        width: 100%;
        min-height: 100px;
        max-height: 400px;
        padding: 12px;
        font-size: 15px;
        line-height: 1.5;
        border: 1px solid #ccc;
        border-radius: 6px;
        resize: vertical;
        font-family: inherit;
    }
    
    .query-textarea:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
    }
    
    .query-actions {
        display: flex;
        gap: 12px;
        margin-top: 16px;
    }
    
    .button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .status-container {
        margin-top: 24px;
        padding: 16px;
        background-color: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #dee2e6;
        display: none;
    }
    
    .status-container.visible {
        display: block;
    }
    
    .status-title {
        font-weight: bold;
        margin-bottom: 8px;
    }
    
    .status-message {
        color: #666;
        font-size: 14px;
    }
    
    .result-container {
        margin-top: 24px;
        display: none;
    }
    
    .result-container.visible {
        display: block;
    }
    
    .result-section {
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 24px;
        margin-bottom: 16px;
    }
    
    .result-section h3 {
        margin-top: 0;
        margin-bottom: 12px;
    }
    
    .result-text {
        font-size: 15px;
        line-height: 1.6;
        color: #333;
    }
    
    .result-text h1,
    .result-text h2,
    .result-text h3,
    .result-text h4 {
        margin-top: 24px;
        margin-bottom: 12px;
        font-weight: 600;
        color: #2c3e50;
    }
    
    .result-text h1 { font-size: 24px; }
    .result-text h2 { font-size: 20px; }
    .result-text h3 { font-size: 18px; }
    .result-text h4 { font-size: 16px; }
    
    .result-text p {
        margin-bottom: 12px;
    }
    
    .result-text ul,
    .result-text ol {
        margin-bottom: 12px;
        padding-left: 24px;
    }
    
    .result-text li {
        margin-bottom: 6px;
    }
    
    .result-text strong {
        font-weight: 600;
    }
    
    .result-text em {
        font-style: italic;
    }
    
    .result-text code {
        background-color: #f4f4f4;
        padding: 2px 6px;
        border-radius: 3px;
        font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
        font-size: 14px;
    }
    
    .result-text pre {
        background-color: #f4f4f4;
        padding: 12px;
        border-radius: 6px;
        overflow-x: auto;
        margin-bottom: 12px;
    }
    
    .result-text pre code {
        background-color: transparent;
        padding: 0;
    }
    
    .result-text a {
        color: #007bff;
        text-decoration: none;
    }
    
    .result-text a:hover {
        text-decoration: underline;
    }
    
    .result-text blockquote {
        border-left: 4px solid #ddd;
        padding-left: 16px;
        margin-left: 0;
        color: #666;
        font-style: italic;
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
</style>

<div class="query-container">
    <div class="breadcrumb">
        <a href="/customgpts/list.php">Custom GPTs</a> / 
        <a href="/customgpts/edit.php?id=<?= $id ?>"><?= htmlspecialchars($gpt['name']) ?></a> / 
        Test Retrieval
    </div>
    
    <h1 class="query-title">Ask this knowledge base</h1>
    
    <form method="post" action="/customgpts/query_eval.php" id="queryForm">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="customgpt_id" value="<?= $id ?>">
        
        <textarea 
            name="query" 
            id="queryTextarea"
            class="query-textarea" 
            placeholder="Enter your question or search query here..."
            required></textarea>
        
        <div class="query-actions">
            <button type="button" class="button primary" id="goButton" onclick="executeQuery()">Go</button>
            <button type="submit" name="action" value="test_retrieval" class="button">Test Retrieval</button>
        </div>
    </form>
    
    <!-- Status Container -->
    <div id="statusContainer" class="status-container">
        <div class="status-title">Processing Query...</div>
        <div class="status-message" id="statusMessage">Initializing...</div>
    </div>
    
    <!-- Result Container -->
    <div id="resultContainer" class="result-container">
        <div class="result-section">
            <h3>Response</h3>
            <div class="result-text" id="resultText"></div>
        </div>
        <div style="text-align: center; margin-top: 16px;">
            <button class="button primary" onclick="copyResult()">Copy to Clipboard</button>
            <button class="button" onclick="resetQuery()">New Query</button>
        </div>
    </div>
</div>

<!-- Include marked.js for markdown rendering -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<script>
// Auto-expand textarea as user types
const textarea = document.getElementById('queryTextarea');

textarea.addEventListener('input', function() {
    // Reset height to auto to get the correct scrollHeight
    this.style.height = 'auto';
    
    // Set height based on scroll height, but respect min/max
    const newHeight = Math.min(Math.max(this.scrollHeight, 100), 400);
    this.style.height = newHeight + 'px';
});

// Focus textarea on page load
textarea.focus();

// Query execution state
let currentJobId = null;
let pollingInterval = null;

function executeQuery() {
    const query = textarea.value.trim();
    if (!query) {
        alert('Please enter a query');
        return;
    }
    
    // Disable button and update text (double-click protection)
    const goButton = document.getElementById('goButton');
    goButton.disabled = true;
    goButton.textContent = 'Running...';
    
    // Show status container
    const statusContainer = document.getElementById('statusContainer');
    statusContainer.classList.add('visible');
    document.getElementById('statusMessage').textContent = 'Starting query execution...';
    
    // Hide result container
    document.getElementById('resultContainer').classList.remove('visible');
    
    // Call PHP endpoint to start job
    fetch('/customgpts/query_execute_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            customgpt_id: <?= $id ?>,
            query: query,
            csrf: '<?= csrf_token() ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            throw new Error(data.error || 'Failed to start query execution');
        }
        
        // Store job ID and start polling
        currentJobId = data.job_id;
        startPolling();
    })
    .catch(error => {
        alert('Error: ' + error.message);
        resetButton();
        statusContainer.classList.remove('visible');
    });
}

function startPolling() {
    // Poll every 2 seconds
    pollingInterval = setInterval(checkStatus, 2000);
    checkStatus(); // Check immediately
}

function checkStatus() {
    if (!currentJobId) return;
    
    fetch('/customgpts/query_execution_realtime_update_ajax.php?job_id=' + currentJobId)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to get status');
            }
            
            // Update status message
            document.getElementById('statusMessage').textContent = data.status_message || 'Processing...';
            
            // Check if job is complete
            if (data.status === 'completed') {
                stopPolling();
                showResult(data.result);
            } else if (data.status === 'failed') {
                stopPolling();
                alert('Query execution failed: ' + (data.error || 'Unknown error'));
                resetButton();
                document.getElementById('statusContainer').classList.remove('visible');
            }
        })
        .catch(error => {
            console.error('Polling error:', error);
            // Continue polling on error
        });
}

function stopPolling() {
    if (pollingInterval) {
        clearInterval(pollingInterval);
        pollingInterval = null;
    }
}

function processDocumentLinks(text) {
    // Build a map of document IDs to document numbers from the text
    const docMap = {};
    const mapRegex = /Document #(\d+): \/customgpt_documents\/download_file\.php\?id=(\d+)/g;
    let match;
    
    while ((match = mapRegex.exec(text)) !== null) {
        const docNum = match[1];
        const docId = match[2];
        docMap[docId] = docNum;
    }
    
    // Replace document URLs with Markdown links
    return text.replace(
        /\/customgpt_documents\/download_file\.php\?id=(\d+)/g,
        (fullMatch, docId) => {
            const docNum = docMap[docId] || '?';
            return `[[Document #${docNum}]](${fullMatch})`;
        }
    );
}

function showResult(result) {
    // Hide status container
    document.getElementById('statusContainer').classList.remove('visible');
    
    // Process document links first
    const processedResult = processDocumentLinks(result);
    
    // Parse markdown to HTML
    const htmlResult = marked.parse(processedResult);
    
    // Show result with rendered markdown
    document.getElementById('resultText').innerHTML = htmlResult;
    document.getElementById('resultContainer').classList.add('visible');
    
    // Reset button
    resetButton();
}

function resetButton() {
    const goButton = document.getElementById('goButton');
    goButton.disabled = false;
    goButton.textContent = 'Go';
}

function resetQuery() {
    document.getElementById('resultContainer').classList.remove('visible');
    textarea.value = '';
    textarea.focus();
}

function copyResult() {
    const resultText = document.getElementById('resultText').textContent;
    navigator.clipboard.writeText(resultText).then(() => {
        const button = event.target;
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.style.backgroundColor = '#28a745';
        setTimeout(() => {
            button.textContent = originalText;
            button.style.backgroundColor = '';
        }, 2000);
    }).catch(err => {
        alert('Failed to copy to clipboard');
        console.error('Copy failed:', err);
    });
}
</script>

<?php footer_html(); ?>
