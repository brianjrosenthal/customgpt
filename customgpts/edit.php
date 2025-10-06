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

header_html('Edit Custom GPT');
?>

<h2>Edit Custom GPT</h2>
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
      <a class="button" href="/customgpts/list.php">Cancel</a>
      <button type="button" class="button danger" onclick="confirmDelete()">Delete</button>
    </div>
  </form>
</div>

<script>
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
</script>

<?php footer_html(); ?>
