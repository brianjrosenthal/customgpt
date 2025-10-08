<?php
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/CustomGPTManagement.php';
Application::init();
require_login();

$me = current_user();
$announcement = Settings::announcement();
$siteTitle = Settings::siteTitle();

// Get CustomGPTs with embeddings for current user
$userId = $me['id'];

$sql = "SELECT c.id, c.name, c.description 
        FROM customgpts c
        INNER JOIN customgpt_vector_embeddings v ON c.id = v.customgpt_id
        WHERE c.created_by = ?
        ORDER BY c.created_at DESC";

$stmt = pdo()->prepare($sql);
$stmt->execute([$userId]);
$customgpts = $stmt->fetchAll();

$hasCustomGPTs = count($customgpts) > 0;
$hasSingleGPT = count($customgpts) === 1;

header_html('Home');
?>

<?php if (trim($announcement) !== ''): ?>
  <p class="announcement"><?=h($announcement)?></p>
<?php endif; ?>

<?php if ($hasCustomGPTs): ?>
  
  <?php if ($hasSingleGPT): ?>
    <!-- Single CustomGPT: Show query interface -->
    <?php 
    $gpt = $customgpts[0];
    $gptId = $gpt['id'];
    ?>
    
    <style>
        .query-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .query-title {
            font-family: ui-sans-serif, -apple-system, system-ui, Segoe UI, Helvetica, Apple Color Emoji, Arial, sans-serif, Segoe UI Emoji, Segoe UI Symbol;
            font-size: 28px;
            color: #0d0d0d;
            margin-bottom: 8px;
        }
        
        .query-description {
            font-size: 15px;
            color: #666;
            line-height: 1.6;
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
    </style>

    <div class="query-container">
        <h1 class="query-title">Custom GPT Demo</h1>
        
        <p class="query-description">
            This is a demonstration of a Custom GPT based on a set of documents. It uses "Retrieval Augmented Generation", which means that it first searches the documents for relevant "chunks" (indexed based on vector embeddings) and then inserts the most relevant chunks and their context into the prompt. Try it out:
        </p>
        
        <h2 style="font-size: 20px; margin-bottom: 12px;">Ask this knowledge base</h2>
        
        <textarea 
            name="query" 
            id="queryTextarea"
            class="query-textarea" 
            placeholder="Enter your question or search query here..."
            required></textarea>
        
        <div class="query-actions">
            <button type="button" class="button primary" id="goButton" onclick="executeQuery()">Go</button>
            <a href="/customgpts/edit.php?id=<?= $gptId ?>" class="button">Add Documents</a>
        </div>
        
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
    const customgptId = <?= $gptId ?>;
    
    // Auto-expand textarea as user types
    const textarea = document.getElementById('queryTextarea');

    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        const newHeight = Math.min(Math.max(this.scrollHeight, 100), 400);
        this.style.height = newHeight + 'px';
    });

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
        
        const goButton = document.getElementById('goButton');
        goButton.disabled = true;
        goButton.textContent = 'Running...';
        
        const statusContainer = document.getElementById('statusContainer');
        statusContainer.classList.add('visible');
        document.getElementById('statusMessage').textContent = 'Starting query execution...';
        
        document.getElementById('resultContainer').classList.remove('visible');
        
        fetch('/customgpts/query_execute_ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                customgpt_id: customgptId,
                query: query,
                csrf: '<?= csrf_token() ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.error || 'Failed to start query execution');
            }
            
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
        pollingInterval = setInterval(checkStatus, 2000);
        checkStatus();
    }

    function checkStatus() {
        if (!currentJobId) return;
        
        fetch('/customgpts/query_execution_realtime_update_ajax.php?job_id=' + currentJobId)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to get status');
                }
                
                document.getElementById('statusMessage').textContent = data.status_message || 'Processing...';
                
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
            });
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
    }

    function processDocumentLinks(text) {
        const docMap = {};
        const mapRegex = /Document #(\d+): \/customgpt_documents\/download_file\.php\?id=(\d+)/g;
        let match;
        
        while ((match = mapRegex.exec(text)) !== null) {
            const docNum = match[1];
            const docId = match[2];
            docMap[docId] = docNum;
        }
        
        return text.replace(
            /\/customgpt_documents\/download_file\.php\?id=(\d+)/g,
            (fullMatch, docId) => {
                const docNum = docMap[docId] || '?';
                return `[[Document #${docNum}]](${fullMatch})`;
            }
        );
    }

    function showResult(result) {
        document.getElementById('statusContainer').classList.remove('visible');
        
        const processedResult = processDocumentLinks(result);
        const htmlResult = marked.parse(processedResult);
        
        document.getElementById('resultText').innerHTML = htmlResult;
        document.getElementById('resultContainer').classList.add('visible');
        
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
    
  <?php else: ?>
    <!-- Multiple CustomGPTs: Show list -->
    <div class="card">
      <h2>Custom GPT Demo</h2>
      <p>This is a demonstration of a Custom GPT based on a set of documents. It uses "Retrieval Augmented Generation", which means that it first searches the documents for relevant "chunks" (indexed based on vector embeddings) and then inserts the most relevant chunks and their context into the prompt.</p>
      
      <h3 style="margin-top: 24px; margin-bottom: 12px;">Select a knowledge base to query:</h3>
      
      <ul style="list-style: none; padding: 0;">
        <?php foreach ($customgpts as $gpt): ?>
          <li style="margin-bottom: 12px;">
            <a href="/customgpts/query.php?id=<?= $gpt['id'] ?>" class="button" style="display: inline-block; text-decoration: none;">
              <?= htmlspecialchars($gpt['name']) ?>
            </a>
            <?php if (!empty($gpt['description'])): ?>
              <p style="margin: 4px 0 0 0; color: #666; font-size: 14px;">
                <?= htmlspecialchars($gpt['description']) ?>
              </p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  
<?php else: ?>
  <!-- No CustomGPTs with embeddings: Show description -->
  <div class="card">
    <h2>Welcome to <?= h($siteTitle) ?></h2>
    <p>Hello, <?= h($me['first_name'] ?? '') ?>!</p>
    <p>This is your RAG (Retrieval Augmented Generation) knowledge base application. Here you can:</p>
    <ul>
      <li>Create Custom GPTs based on your documents</li>
      <li>Upload documents to build a knowledge base</li>
      <li>Query your knowledge base using natural language</li>
      <li>Get AI-powered responses with citations from your documents</li>
    </ul>
    <p style="margin-top: 20px;">
      <a href="/customgpts/list.php" class="button primary">Get Started - Create Your First Custom GPT</a>
    </p>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
