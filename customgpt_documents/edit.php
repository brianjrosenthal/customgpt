<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
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

// Get Document ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /customgpts/list.php');
    exit;
}

// Load document with file details
$doc = CustomGPTDocumentManagement::getDocumentWithFile($id);
if (!$doc) {
    header('Location: /customgpts/list.php?err=' . urlencode('Document not found.'));
    exit;
}

// Load CustomGPT
$customgpt = CustomGPTManagement::findById((int)$doc['customgpt_id']);
if (!$customgpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

header_html('Edit Document');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Document Details</h2>
  <a class="button" href="/customgpt_documents/list.php?customgpt_id=<?= (int)$doc['customgpt_id'] ?>">Back to Documents</a>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <div class="stack">
    <h3>Document Information</h3>
    
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <div>
        <strong>Custom GPT:</strong> <?= h($customgpt['name']) ?>
      </div>
      <div>
        <strong>Filename:</strong> <?= h($doc['original_filename'] ?? 'Unknown') ?>
      </div>
      <div>
        <strong>Type:</strong> <?= h($doc['content_type'] ?? 'Unknown') ?>
      </div>
      <div>
        <strong>Size:</strong> <?= h(number_format($doc['byte_length'] ?? 0)) ?> bytes
      </div>
      <div>
        <strong>SHA256:</strong> <span class="small"><?= h(substr($doc['sha256'] ?? '', 0, 16)) ?>...</span>
      </div>
      <div>
        <strong>Uploaded:</strong> <?= h(date('F j, Y g:i A', strtotime($doc['created_at']))) ?>
      </div>
    </div>

    <div class="actions">
      <a class="button" href="/customgpt_documents/download_file.php?id=<?= $id ?>">Download File</a>
      <a class="button" href="/customgpt_documents/list.php?customgpt_id=<?= (int)$doc['customgpt_id'] ?>">Cancel</a>
      <button type="button" class="button danger" onclick="confirmDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
function confirmDelete() {
  if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/customgpt_documents/delete_eval.php';
    
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf';
    csrfInput.value = '<?=h(csrf_token())?>';
    form.appendChild(csrfInput);
    
    var idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'id';
    idInput.value = '<?=h($id)?>';
    form.appendChild(idInput);
    
    var customgptInput = document.createElement('input');
    customgptInput.type = 'hidden';
    customgptInput.name = 'customgpt_id';
    customgptInput.value = '<?=h($doc['customgpt_id'])?>';
    form.appendChild(customgptInput);
    
    document.body.appendChild(form);
    form.submit();
  }
}
</script>

<?php footer_html(); ?>
