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

// Get results from session
if (!isset($_SESSION['retrieval_results']) || $_SESSION['retrieval_results']['customgpt_id'] !== $id) {
    header('Location: /customgpts/query.php?id=' . $id . '&err=' . urlencode('No retrieval results found. Please submit a query first.'));
    exit;
}

$resultsData = $_SESSION['retrieval_results'];
$query = $resultsData['query'];
$results = $resultsData['results'];

// Clear session after retrieving
unset($_SESSION['retrieval_results']);

header_html('Retrieval Results - ' . htmlspecialchars($gpt['name']));
?>

<style>
    .results-container {
        max-width: 1000px;
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
    
    .results-summary {
        margin-bottom: 24px;
        font-size: 14px;
        color: #666;
    }
    
    .chunk-result {
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 20px;
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
    
    .chunk-text {
        font-size: 14px;
        line-height: 1.6;
        color: #333;
        margin-bottom: 12px;
        white-space: pre-wrap;
    }
    
    .chunk-metadata {
        display: flex;
        gap: 24px;
        font-size: 12px;
        color: #666;
        padding-top: 12px;
        border-top: 1px solid #e9ecef;
    }
    
    .metadata-item {
        display: flex;
        gap: 4px;
    }
    
    .metadata-label {
        font-weight: 500;
    }
    
    .no-results {
        text-align: center;
        padding: 40px;
        color: #666;
    }
    
    .actions {
        margin-top: 24px;
    }
</style>

<div class="results-container">
    <div class="breadcrumb">
        <a href="/customgpts/list.php">Custom GPTs</a> / 
        <a href="/customgpts/edit.php?id=<?= $id ?>"><?= htmlspecialchars($gpt['name']) ?></a> / 
        <a href="/customgpts/query.php?id=<?= $id ?>">Test Retrieval</a> /
        Results
    </div>
    
    <h2>Retrieval Results</h2>
    
    <div class="query-display">
        <h3>Your Query:</h3>
        <div class="query-text"><?= htmlspecialchars($query) ?></div>
    </div>
    
    <?php if (empty($results['chunks'])): ?>
        <div class="no-results">
            <p>No matching chunks found for your query.</p>
            <p>Try rephrasing your question or ensure that embeddings have been generated for this knowledge base.</p>
        </div>
    <?php else: ?>
        <div class="results-summary">
            Found <strong><?= count($results['chunks']) ?></strong> relevant chunk(s)
        </div>
        
        <?php foreach ($results['chunks'] as $index => $chunk): ?>
            <div class="chunk-result">
                <div class="chunk-header">
                    <div class="chunk-rank">#<?= $index + 1 ?></div>
                    <div class="chunk-scores">
                        <div class="score-item">
                            <span class="score-label">Raw Score</span>
                            <span class="score-value"><?= number_format($chunk['score'], 4) ?></span>
                        </div>
                        <div class="score-item">
                            <span class="score-label">Similarity</span>
                            <span class="score-value"><?= number_format($chunk['similarity_percent'], 1) ?>%</span>
                        </div>
                    </div>
                </div>
                
                <div class="chunk-text"><?= htmlspecialchars($chunk['text']) ?></div>
                
                <div class="chunk-metadata">
                    <div class="metadata-item">
                        <span class="metadata-label">Chunk ID:</span>
                        <span><?= $chunk['chunk_id'] ?></span>
                    </div>
                    <div class="metadata-item">
                        <span class="metadata-label">Document ID:</span>
                        <span><?= $chunk['document_id'] ?></span>
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
    <?php endif; ?>
    
    <div class="actions">
        <a class="button" href="/customgpts/query.php?id=<?= $id ?>">New Query</a>
        <a class="button" href="/customgpts/edit.php?id=<?= $id ?>">Back to Custom GPT</a>
        <?php if (!empty($results['chunks'])): ?>
            <form method="post" action="/customgpts/query_results_reranked.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="customgpt_id" value="<?= $id ?>">
                <input type="hidden" name="query" value="<?= h($query) ?>">
                <input type="hidden" name="results_data" value="<?= h(json_encode($results)) ?>">
                <button type="submit" class="button primary">Re-rank with Cross-Encoder</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php footer_html(); ?>
