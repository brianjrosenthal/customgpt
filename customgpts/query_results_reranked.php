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
$resultsDataJson = isset($_POST['results_data']) ? $_POST['results_data'] : '';

// Validate inputs
if ($customgptId <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

if (empty($query) || empty($resultsDataJson)) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Missing query or results data.'));
    exit;
}

// Decode results data
$originalResults = json_decode($resultsDataJson, true);
if (!$originalResults || empty($originalResults['chunks'])) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Invalid results data.'));
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

// Prepare request payload for re-ranking
$payload = json_encode([
    'query' => $query,
    'chunks' => $originalResults['chunks']
]);

// Make synchronous API call to FastAPI for re-ranking
$apiUrl = "{$fastapiUrl}/api/v1/rerank/{$customgptId}";

$options = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $payload,
        'timeout' => 30,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($apiUrl, false, $context);

if ($response === false) {
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode('Failed to connect to re-ranking service. Please ensure the FastAPI service is running.'));
    exit;
}

$rerankedResult = json_decode($response, true);

if (!$rerankedResult || isset($rerankedResult['detail'])) {
    $errorMsg = isset($rerankedResult['detail']) ? $rerankedResult['detail'] : 'Invalid response from re-ranking service.';
    header('Location: /customgpts/query.php?id=' . $customgptId . '&err=' . urlencode($errorMsg));
    exit;
}

// Get reranked chunks
$rerankedChunks = $rerankedResult['reranked_chunks'];

header_html('Re-ranked Results - ' . htmlspecialchars($gpt['name']));
?>

<style>
    .results-container {
        max-width: 1400px;
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
    
    .comparison-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .column-header {
        text-align: center;
        padding: 12px;
        background-color: #f8f9fa;
        border-radius: 6px;
        margin-bottom: 16px;
    }
    
    .column-header h3 {
        margin: 0;
        font-size: 18px;
    }
    
    .column-header .subtitle {
        font-size: 13px;
        color: #666;
        margin-top: 4px;
    }
    
    .chunk-result {
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 16px;
        position: relative;
    }
    
    .chunk-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e9ecef;
    }
    
    .chunk-rank {
        font-size: 18px;
        font-weight: bold;
        color: #007bff;
    }
    
    .rank-change {
        display: inline-flex;
        align-items: center;
        margin-left: 8px;
        font-size: 14px;
        padding: 2px 8px;
        border-radius: 4px;
    }
    
    .rank-up {
        background-color: #d4edda;
        color: #155724;
    }
    
    .rank-down {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .rank-same {
        background-color: #e2e3e5;
        color: #383d41;
    }
    
    .chunk-scores {
        display: flex;
        gap: 16px;
        font-size: 13px;
    }
    
    .score-item {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }
    
    .score-label {
        color: #666;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .score-value {
        font-weight: bold;
        color: #28a745;
    }
    
    .score-value.cross-encoder {
        color: #dc3545;
    }
    
    .chunk-text {
        font-size: 13px;
        line-height: 1.5;
        color: #333;
        margin-bottom: 12px;
        white-space: pre-wrap;
        max-height: 150px;
        overflow: hidden;
        position: relative;
    }
    
    .chunk-metadata {
        display: flex;
        gap: 16px;
        font-size: 11px;
        color: #666;
        padding-top: 8px;
        border-top: 1px solid #e9ecef;
    }
    
    .metadata-item {
        display: flex;
        gap: 4px;
    }
    
    .metadata-label {
        font-weight: 500;
    }
    
    .actions {
        margin-top: 24px;
        text-align: center;
    }
    
    @media (max-width: 1200px) {
        .comparison-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="results-container">
    <div class="breadcrumb">
        <a href="/customgpts/list.php">Custom GPTs</a> / 
        <a href="/customgpts/edit.php?id=<?= $customgptId ?>"><?= htmlspecialchars($gpt['name']) ?></a> / 
        <a href="/customgpts/query.php?id=<?= $customgptId ?>">Test Retrieval</a> /
        Re-ranked Results
    </div>
    
    <h2>Re-ranked Results Comparison</h2>
    
    <div class="query-display">
        <h3>Your Query:</h3>
        <div class="query-text"><?= htmlspecialchars($query) ?></div>
    </div>
    
    <div class="comparison-grid">
        <!-- Original Results Column -->
        <div>
            <div class="column-header">
                <h3>Original Bi-Encoder Ranking</h3>
                <div class="subtitle">Sorted by embedding similarity</div>
            </div>
            
            <?php foreach ($originalResults['chunks'] as $index => $chunk): ?>
                <div class="chunk-result">
                    <div class="chunk-header">
                        <div class="chunk-rank">#<?= $index + 1 ?></div>
                        <div class="chunk-scores">
                            <div class="score-item">
                                <span class="score-label">Similarity</span>
                                <span class="score-value"><?= number_format($chunk['similarity_percent'], 1) ?>%</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chunk-text"><?= htmlspecialchars(substr($chunk['text'], 0, 200)) ?><?= strlen($chunk['text']) > 200 ? '...' : '' ?></div>
                    
                    <div class="chunk-metadata">
                        <div class="metadata-item">
                            <span class="metadata-label">ID:</span>
                            <span><?= $chunk['chunk_id'] ?></span>
                        </div>
                        <?php if (isset($chunk['filename'])): ?>
                            <div class="metadata-item">
                                <span class="metadata-label">File:</span>
                                <span><?= htmlspecialchars($chunk['filename']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Re-ranked Results Column -->
        <div>
            <div class="column-header">
                <h3>Cross-Encoder Re-ranking</h3>
                <div class="subtitle">Sorted by relevance score</div>
            </div>
            
            <?php foreach ($rerankedChunks as $index => $chunk): 
                $originalRank = $chunk['original_rank'];
                $newRank = $index + 1;
                $rankChange = $originalRank - $newRank;
                
                if ($rankChange > 0) {
                    $rankClass = 'rank-up';
                    $rankSymbol = '↑ +' . $rankChange;
                } elseif ($rankChange < 0) {
                    $rankClass = 'rank-down';
                    $rankSymbol = '↓ ' . $rankChange;
                } else {
                    $rankClass = 'rank-same';
                    $rankSymbol = '=';
                }
            ?>
                <div class="chunk-result">
                    <div class="chunk-header">
                        <div class="chunk-rank">
                            #<?= $newRank ?>
                            <span class="rank-change <?= $rankClass ?>"><?= $rankSymbol ?></span>
                        </div>
                        <div class="chunk-scores">
                            <div class="score-item">
                                <span class="score-label">Cross-Encoder</span>
                                <span class="score-value cross-encoder"><?= number_format($chunk['cross_encoder_score'] * 100, 1) ?>%</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chunk-text"><?= htmlspecialchars(substr($chunk['text'], 0, 200)) ?><?= strlen($chunk['text']) > 200 ? '...' : '' ?></div>
                    
                    <div class="chunk-metadata">
                        <div class="metadata-item">
                            <span class="metadata-label">ID:</span>
                            <span><?= $chunk['chunk_id'] ?></span>
                        </div>
                        <?php if (isset($chunk['filename'])): ?>
                            <div class="metadata-item">
                                <span class="metadata-label">File:</span>
                                <span><?= htmlspecialchars($chunk['filename']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="metadata-item">
                            <span class="metadata-label">Was:</span>
                            <span>#<?= $originalRank ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="actions">
        <a class="button" href="/customgpts/query.php?id=<?= $customgptId ?>">New Query</a>
        <a class="button" href="/customgpts/edit.php?id=<?= $customgptId ?>">Back to Custom GPT</a>
        
        <form method="post" action="/customgpts/query_generate_prompt.php" style="display:inline; margin-left: 12px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="customgpt_id" value="<?= $customgptId ?>">
            <input type="hidden" name="query" value="<?= h($query) ?>">
            <input type="hidden" name="reranked_data" value="<?= h(json_encode($rerankedChunks)) ?>">
            <button type="submit" class="button primary">Generate Prompt</button>
        </form>
    </div>
</div>

<?php footer_html(); ?>
