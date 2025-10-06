<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
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

// Get Custom GPT ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /customgpts/list.php');
    exit;
}

// Load Custom GPT data
$gpt = CustomGPTManagement::findById($id);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Get creator info
$creator = CustomGPTManagement::getCreatorInfo($id);

// Load documents for this CustomGPT
$documents = CustomGPTDocumentManagement::listDocumentsByCustomGPT($id);
$documentCount = count($documents);

header_html('Edit Custom GPT');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Edit Custom GPT</h2>
  <div style="display:flex;gap:12px;align-items:center;">
    <div style="position:relative;">
      <button type="button" class="button" onclick="toggleActionsMenu()" id="actionsMenuBtn">
        Actions ▼
      </button>
      <div id="actionsMenu" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:white;border:1px solid #ccc;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.15);min-width:200px;z-index:1000;">
        <a href="/customgpts/generate_chunks_eval.php?id=<?= $id ?>" style="display:block;padding:12px 16px;text-decoration:none;color:#333;border-bottom:1px solid #eee;">
          Generate Chunks from Files
        </a>
      </div>
    </div>
    <a class="button" href="/customgpts/list.php">Custom GPTs</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/customgpts/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=h($id)?>">
    
    <h3>Custom GPT Information</h3>
    <div class="stack">
      <label>Name
        <input type="text" name="name" value="<?=h($gpt['name'] ?? '')?>" required>
      </label>
      <label>Description
        <textarea name="description" rows="4"><?=h($gpt['description'] ?? '')?></textarea>
      </label>
      <label class="inline">
        <input type="checkbox" name="is_public" value="1" <?= !empty($gpt['is_public']) ? 'checked' : '' ?>> 
        Make this Custom GPT publicly accessible
      </label>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <div>
        <strong>Created By:</strong> <?= h(trim(($creator['first_name'] ?? '') . ' ' . ($creator['last_name'] ?? ''))) ?>
      </div>
      <div>
        <strong>Created:</strong> <?= h(date('F j, Y g:i A', strtotime($gpt['created_at']))) ?>
      </div>
      <div>
        <strong>Last Updated:</strong> <?= h(date('F j, Y g:i A', strtotime($gpt['updated_at']))) ?>
      </div>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Update Custom GPT</button>
      <a class="button" href="/customgpt_documents/list.php?customgpt_id=<?= $id ?>">Manage Documents</a>
      <a class="button" href="/customgpts/list.php">Cancel</a>
      <button type="button" class="button danger" onclick="confirmDelete()">Delete</button>
    </div>
  </form>
</div>

<!-- Documents Section -->
<div class="card">
  <h3>Documents (<?= $documentCount ?>)</h3>
  
  <?php if (empty($documents)): ?>
    <p class="small">No documents uploaded yet.</p>
  <?php else: ?>
    <table class="list">
      <thead>
        <tr>
          <th>Filename</th>
          <th>Type</th>
          <th>Size</th>
          <th>Uploaded</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($documents as $doc): ?>
          <tr>
            <td><strong><?= h($doc['original_filename'] ?? 'Unknown') ?></strong></td>
            <td class="small"><?= h($doc['content_type'] ?? 'Unknown') ?></td>
            <td class="small"><?= h(number_format($doc['byte_length'] ?? 0)) ?> bytes</td>
            <td><?= h(date('M j, Y g:i A', strtotime($doc['created_at']))) ?></td>
            <td class="small" style="display:flex;gap:8px;">
              <a class="button small" href="/customgpt_documents/download_file.php?id=<?= (int)$doc['id'] ?>">Download</a>
              <button type="button" class="button small danger" onclick="confirmDocumentDelete(<?= (int)$doc['id'] ?>)">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  
  <!-- Upload Form -->
  <div style="margin-top:24px;">
    <h4>Upload New Document</h4>
    <form method="post" action="/customgpt_documents/add_eval.php" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="customgpt_id" value="<?=h($id)?>">
      
      <div class="stack">
        <label>Select File
          <input type="file" name="document_file" required accept=".txt,.md,.pdf,.doc,.docx,.csv,.json">
        </label>
        <small class="small">
          Supported formats: TXT, MD, PDF, DOC, DOCX, CSV, JSON (Max: 10MB)
        </small>
      </div>
      
      <div class="actions">
        <button class="primary" type="submit">Upload Document</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleActionsMenu() {
  var menu = document.getElementById('actionsMenu');
  if (menu.style.display === 'none' || menu.style.display === '') {
    menu.style.display = 'block';
  } else {
    menu.style.display = 'none';
  }
}

// Close menu when clicking outside
document.addEventListener('click', function(event) {
  var menu = document.getElementById('actionsMenu');
  var btn = document.getElementById('actionsMenuBtn');
  if (menu && btn && !menu.contains(event.target) && !btn.contains(event.target)) {
    menu.style.display = 'none';
  }
});

function confirmDelete() {
  if (confirm('Are you sure you want to delete this Custom GPT? This action cannot be undone.')) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '/customgpts/delete_eval.php';
    
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
    
    document.body.appendChild(form);
    form.submit();
  }
}

function confirmDocumentDelete(documentId) {
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
    idInput.value = documentId;
    form.appendChild(idInput);
    
    var customgptInput = document.createElement('input');
    customgptInput.type = 'hidden';
    customgptInput.name = 'customgpt_id';
    customgptInput.value = '<?=h($id)?>';
    form.appendChild(customgptInput);
    
    document.body.appendChild(form);
    form.submit();
  }
}
</script>

<?php footer_html(); ?>
