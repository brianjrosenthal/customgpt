<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

$msg = null;
$err = null;

// Handle messages from evaluation script
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Get CustomGPT ID
$customgptId = isset($_GET['customgpt_id']) ? (int)$_GET['customgpt_id'] : 0;
if ($customgptId <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Load CustomGPT
$customgpt = CustomGPTManagement::findById($customgptId);
if (!$customgpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

header_html('Upload Document');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Upload Document - <?= h($customgpt['name']) ?></h2>
  <a class="button" href="/customgpt_documents/list.php?customgpt_id=<?= $customgptId ?>">Back to Documents</a>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/customgpt_documents/add_eval.php" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="customgpt_id" value="<?=h($customgptId)?>">
    
    <h3>Document Upload</h3>
    <div class="stack">
      <label>Select File
        <input type="file" name="document_file" required accept=".txt,.md,.pdf,.doc,.docx,.csv,.json">
      </label>
      <small class="small">
        Supported formats: TXT, MD, PDF, DOC, DOCX, CSV, JSON<br>
        Maximum file size: 10MB
      </small>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Upload Document</button>
      <a class="button" href="/customgpt_documents/list.php?customgpt_id=<?= $customgptId ?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
