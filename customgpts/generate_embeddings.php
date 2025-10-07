<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
Application::init();
require_admin();

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

// Check if there's an active generation session
$hasSession = isset($_SESSION['embedding_generation_token']) && 
              isset($_SESSION['embedding_generation_customgpt_id']) &&
              $_SESSION['embedding_generation_customgpt_id'] === $id;

header_html('Generate Vector Embeddings - ' . h($gpt['name']));
?>

<h2>Generate Vector Embeddings: <?=h($gpt['name'])?></h2>

<div class="card">
  <h3>Progress</h3>
  <div id="progressOutput" style="
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 16px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    white-space: pre-wrap;
    word-wrap: break-word;
    max-height: 600px;
    overflow-y: auto;
    line-height: 1.5;
  ">
    <?php if ($hasSession): ?>
      <span style="color: #666;">Initializing vector embeddings generation...</span>
    <?php else: ?>
      <span style="color: #d32f2f;">No active embedding generation session found. Please start the process from the Edit Custom GPT page by clicking Actions → Generate Vector Embeddings.</span>
    <?php endif; ?>
  </div>
  
  <div class="actions" style="margin-top: 16px;">
    <a class="button" href="/customgpts/edit.php?id=<?= $id ?>" id="backButton" style="display:none;">
      Back to Edit Custom GPT
    </a>
  </div>
</div>

<script>
const hasSession = <?= $hasSession ? 'true' : 'false' ?>;
let pollInterval = null;
let isCompleted = false;

function pollProgress() {
  // Don't poll if there's no active session
  if (!hasSession) {
    document.getElementById('backButton').style.display = 'inline-block';
    return;
  }
  
  fetch('/customgpts/generate_embeddings_progress.php?id=<?= $id ?>')
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        document.getElementById('progressOutput').innerHTML = 
          '<span style="color: #d32f2f;">Error: ' + escapeHtml(data.error) + '</span>';
        stopPolling();
        return;
      }
      
      // Update output
      document.getElementById('progressOutput').innerHTML = escapeHtml(data.content);
      
      // Auto-scroll to bottom
      const output = document.getElementById('progressOutput');
      output.scrollTop = output.scrollHeight;
      
      // Check if completed
      if (data.completed && !isCompleted) {
        isCompleted = true;
        stopPolling();
        document.getElementById('backButton').style.display = 'inline-block';
      }
    })
    .catch(error => {
      console.error('Error polling progress:', error);
      document.getElementById('progressOutput').innerHTML = 
        '<span style="color: #d32f2f;">Error checking progress. Please refresh the page.</span>';
      stopPolling();
    });
}

function stopPolling() {
  if (pollInterval) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Start polling immediately and then every 2 seconds
pollProgress();
pollInterval = setInterval(pollProgress, 2000);

// Clean up on page unload
window.addEventListener('beforeunload', stopPolling);
</script>

<?php footer_html(); ?>
