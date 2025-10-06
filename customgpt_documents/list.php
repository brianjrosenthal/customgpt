<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
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

// Load documents
$documents = CustomGPTDocumentManagement::listDocumentsByCustomGPT($customgptId);

header_html('Documents - ' . $customgpt['name']);
?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <div>
        <h2><?= h($customgpt['name']) ?> - Documents</h2>
        <p class="small" style="margin-top:4px;"><?= h($customgpt['description'] ?? '') ?></p>
    </div>
    <div style="display:flex;gap:8px;">
        <a class="button" href="/customgpt_documents/add.php?customgpt_id=<?= $customgptId ?>">Upload Document</a>
        <a class="button" href="/customgpts/edit.php?id=<?= $customgptId ?>">Back to Custom GPT</a>
    </div>
</div>

<?php if (empty($documents)): ?>
  <div class="card">
    <p class="small">No documents uploaded yet.</p>
  </div>
<?php else: ?>
  <div class="card">
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
              <a class="button small" href="/customgpt_documents/edit.php?id=<?= (int)$doc['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
