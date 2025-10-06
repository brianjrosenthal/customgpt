<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

$me = current_user();

$msg = null;
$err = null;

// Handle messages
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// Handle search
$search = trim($_GET['q'] ?? '');
$customgpts = CustomGPTManagement::listCustomGPTs($search);

header_html('Custom GPTs');
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Custom GPTs</h2>
  <a class="button" href="/customgpts/add.php">Add Custom GPT</a>
</div>

<div class="card">
  <form method="get" class="stack">
    <label>Search
      <input type="text" name="q" value="<?=h($search)?>" placeholder="Name or description">
    </label>
  </form>
  
  <script>
    (function(){
      var form = document.querySelector('form[method="get"]');
      if (!form) return;
      var q = form.querySelector('input[name="q"]');
      var t;
      function submitNow() {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
      if (q) {
        q.addEventListener('input', function(){
          if (t) clearTimeout(t);
          t = setTimeout(submitNow, 600);
        });
      }
    })();
  </script>
</div>

<?php if (empty($customgpts)): ?>
  <p class="small">No custom GPTs found.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr>
          <th>Name</th>
          <th>Description</th>
          <th>Created By</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($customgpts as $gpt): ?>
          <tr>
            <td><strong><?= h($gpt['name'] ?? '') ?></strong></td>
            <td class="small"><?= h(mb_substr($gpt['description'] ?? '', 0, 100)) ?><?= mb_strlen($gpt['description'] ?? '') > 100 ? '...' : '' ?></td>
            <td><?= h(trim(($gpt['first_name'] ?? '') . ' ' . ($gpt['last_name'] ?? ''))) ?></td>
            <td class="small">
              <a class="button small" href="/customgpts/edit.php?id=<?= (int)$gpt['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
