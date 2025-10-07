<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/CustomGPTManagement.php';
require_once __DIR__ . '/../lib/CustomGPTDocumentManagement.php';
Application::init();
require_admin();

// Get Custom GPT ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: /customgpts/list.php?err=' . urlencode('Invalid Custom GPT ID.'));
    exit;
}

// Verify CustomGPT exists
$gpt = CustomGPTManagement::findById($id);
if (!$gpt) {
    header('Location: /customgpts/list.php?err=' . urlencode('Custom GPT not found.'));
    exit;
}

// Check if we have a valid session (either job_id for FastAPI or token for fallback)
$hasJobId = isset($_SESSION['chunk_generation_job_id']) && isset($_SESSION['chunk_generation_customgpt_id']);
$hasToken = isset($_SESSION['chunk_generation_token']) && isset($_SESSION['chunk_generation_customgpt_id']);

if (!$hasJobId && !$hasToken) {
    // No active chunk generation, redirect to start it
    header('Location: /customgpts/generate_chunks_eval.php?id=' . $id);
    exit;
}

// Determine which tracking method to use
$usingFastAPI = $hasJobId;
$jobId = $hasJobId ? $_SESSION['chunk_generation_job_id'] : null;
$token = $hasToken ? $_SESSION['chunk_generation_token'] : null;
$sessionCustomGptId = $_SESSION['chunk_generation_customgpt_id'];

// Verify the session CustomGPT ID matches the requested ID
if ($sessionCustomGptId !== $id) {
    header('Location: /customgpts/edit.php?id=' . $id . '&err=' . urlencode('Invalid chunk generation session.'));
    exit;
}

header_html('Generate Chunks - ' . htmlspecialchars($gpt['name']));
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>Generate Chunks from Files</h2>
  <a class="button" href="/customgpts/edit.php?id=<?= $id ?>">Back to Custom GPT</a>
</div>

<div class="card">
  <h3><?= h($gpt['name']) ?></h3>
  <p>Processing documents and generating chunks using LangChain...</p>
  
  <div id="status" style="margin:20px 0;">
    <div style="padding:12px;background:#f0f0f0;border-radius:4px;margin-bottom:12px;">
      <strong>Status:</strong> <span id="statusText">Running...</span>
    </div>
  </div>
  
  <div style="margin:20px 0;">
    <h4>Progress Log:</h4>
    <div id="logOutput" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;font-family:monospace;font-size:13px;line-height:1.5;max-height:500px;overflow-y:auto;white-space:pre-wrap;">
      Initializing...
    </div>
  </div>
  
  <div id="completionActions" style="display:none;margin-top:20px;">
    <div class="actions">
      <a class="button primary" href="/customgpts/edit.php?id=<?= $id ?>">Return to Custom GPT</a>
    </div>
  </div>
</div>

<script>
let pollingInterval;
let lastContent = '';
let pollCount = 0;
const maxPolls = 300; // 5 minutes max (300 * 1 second)

function updateProgress() {
  pollCount++;
  
  // Stop polling after max attempts
  if (pollCount > maxPolls) {
    document.getElementById('statusText').textContent = 'Timeout - Process may still be running';
    document.getElementById('statusText').style.color = '#ff6b6b';
    clearInterval(pollingInterval);
    document.getElementById('completionActions').style.display = 'block';
    return;
  }
  
  fetch('/customgpts/generate_chunks_progress.php?id=<?= $id ?>&t=' + Date.now())
    .then(response => response.json())
    .then(data => {
      if (data.error) {
        document.getElementById('statusText').textContent = 'Error: ' + data.error;
        document.getElementById('statusText').style.color = '#ff6b6b';
        document.getElementById('logOutput').textContent = data.error;
        clearInterval(pollingInterval);
        document.getElementById('completionActions').style.display = 'block';
        return;
      }
      
      if (data.content && data.content !== lastContent) {
        lastContent = data.content;
        document.getElementById('logOutput').textContent = data.content;
        
        // Auto-scroll to bottom
        const logDiv = document.getElementById('logOutput');
        logDiv.scrollTop = logDiv.scrollHeight;
      }
      
      if (data.completed) {
        document.getElementById('statusText').textContent = 'Completed!';
        document.getElementById('statusText').style.color = '#51cf66';
        clearInterval(pollingInterval);
        document.getElementById('completionActions').style.display = 'block';
      }
    })
    .catch(error => {
      console.error('Error fetching progress:', error);
    });
}

// Start polling every second
pollingInterval = setInterval(updateProgress, 1000);

// Do initial update immediately
updateProgress();
</script>

<?php footer_html(); ?>
