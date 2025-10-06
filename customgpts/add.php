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

// For repopulating form after errors - get from URL parameters
$form = [];
$formFields = ['name', 'description', 'is_public'];
foreach ($formFields as $field) {
    if (isset($_GET[$field])) {
        $form[$field] = $_GET[$field];
    }
}

header_html('Add Custom GPT');
?>

<h2>Add Custom GPT</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/customgpts/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <h3>Custom GPT Information</h3>
    <div class="stack">
      <label>Name
        <input type="text" name="name" value="<?=h($form['name'] ?? '')?>" required placeholder="e.g., Event Planning Assistant">
      </label>
      <label>Description
        <textarea name="description" rows="4" placeholder="Describe the purpose and capabilities of this Custom GPT"><?=h($form['description'] ?? '')?></textarea>
      </label>
      <label class="inline">
        <input type="checkbox" name="is_public" value="1" <?= !empty($form['is_public']) ? 'checked' : '' ?>> 
        Make this Custom GPT publicly accessible
      </label>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Create Custom GPT</button>
      <a class="button" href="/customgpts/list.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
