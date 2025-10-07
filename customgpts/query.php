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
            <button type="button" class="button" disabled title="Coming soon">Go</button>
            <button type="submit" name="action" value="test_retrieval" class="button primary">Test Retrieval</button>
        </div>
    </form>
</div>

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
</script>

<?php footer_html(); ?>
