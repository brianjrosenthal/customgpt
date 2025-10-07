<?php
/**
 * Modal - Class for generating configuration modals
 * 
 * This class provides methods to generate modal dialogs with forms,
 * validation, and AJAX submission.
 */
class Modal {
    
    /**
     * Generate a chunk configuration modal
     * 
     * @param int $customgptId The CustomGPT ID
     * @return string HTML for the modal
     */
    public static function chunkConfigModal($customgptId) {
        // Get defaults from configuration
        $chunkSize = defined('CHUNK_SIZE_DEFAULT') ? CHUNK_SIZE_DEFAULT : 1000;
        $chunkOverlap = defined('CHUNK_OVERLAP_DEFAULT') ? CHUNK_OVERLAP_DEFAULT : 200;
        $separators = defined('CHUNK_SEPARATORS') ? CHUNK_SEPARATORS : "\n\n|\n|. | |";
        
        // Convert pipe-separated separators to display format
        $separatorDisplay = str_replace('|', ', ', $separators);
        $separatorDisplay = str_replace('\n\n', '\\n\\n (double newline)', $separatorDisplay);
        $separatorDisplay = str_replace('\n', '\\n (newline)', $separatorDisplay);
        
        $minSize = defined('CHUNK_SIZE_MIN') ? CHUNK_SIZE_MIN : 100;
        $maxSize = defined('CHUNK_SIZE_MAX') ? CHUNK_SIZE_MAX : 5000;
        $minOverlap = defined('CHUNK_OVERLAP_MIN') ? CHUNK_OVERLAP_MIN : 0;
        $maxOverlap = defined('CHUNK_OVERLAP_MAX') ? CHUNK_OVERLAP_MAX : 1000;
        
        $modalId = 'chunkConfigModal';
        
        ob_start();
        ?>
        <div id="<?= $modalId ?>" class="modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Chunk Generation Configuration</h3>
                    <button class="modal-close" onclick="closeModal('<?= $modalId ?>')">&times;</button>
                </div>
                
                <div class="modal-body">
                    <form id="chunkConfigForm" onsubmit="return false;">
                        <input type="hidden" name="customgpt_id" value="<?= $customgptId ?>">
                        
                        <div class="form-group">
                            <label for="chunk_size">
                                Chunk Size (characters)
                                <span class="field-hint">Range: <?= $minSize ?>-<?= $maxSize ?></span>
                            </label>
                            <input 
                                type="number" 
                                id="chunk_size" 
                                name="chunk_size" 
                                value="<?= $chunkSize ?>"
                                min="<?= $minSize ?>"
                                max="<?= $maxSize ?>"
                                required
                                class="form-control">
                            <div class="field-description">
                                The maximum number of characters in each chunk. Larger chunks preserve more context but may reduce precision.
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="chunk_overlap">
                                Chunk Overlap (characters)
                                <span class="field-hint">Range: <?= $minOverlap ?>-<?= $maxOverlap ?></span>
                            </label>
                            <input 
                                type="number" 
                                id="chunk_overlap" 
                                name="chunk_overlap" 
                                value="<?= $chunkOverlap ?>"
                                min="<?= $minOverlap ?>"
                                max="<?= $maxOverlap ?>"
                                required
                                class="form-control">
                            <div class="field-description">
                                The number of characters that overlap between consecutive chunks. Overlap helps maintain context across chunk boundaries.
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Text Separators (read-only)</label>
                            <div class="read-only-field">
                                <?= htmlspecialchars($separatorDisplay) ?>
                            </div>
                            <div class="field-description">
                                The text will be split at these separators, in order of preference.
                            </div>
                        </div>
                        
                        <div id="modalErrorMessage" class="error-message" style="display:none;"></div>
                    </form>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="button" onclick="closeModal('<?= $modalId ?>')">Cancel</button>
                    <button type="button" class="button primary" onclick="submitChunkConfig()">Generate Chunks</button>
                </div>
            </div>
        </div>
        
        <style>
            .modal {
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .modal-content {
                background-color: white;
                border-radius: 8px;
                width: 90%;
                max-width: 600px;
                max-height: 90vh;
                display: flex;
                flex-direction: column;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            
            .modal-header {
                padding: 20px;
                border-bottom: 1px solid #e0e0e0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .modal-header h3 {
                margin: 0;
                font-size: 20px;
            }
            
            .modal-close {
                background: none;
                border: none;
                font-size: 28px;
                cursor: pointer;
                color: #666;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .modal-close:hover {
                color: #000;
            }
            
            .modal-body {
                padding: 20px;
                overflow-y: auto;
            }
            
            .modal-footer {
                padding: 20px;
                border-top: 1px solid #e0e0e0;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 500;
            }
            
            .field-hint {
                font-size: 12px;
                color: #666;
                font-weight: normal;
            }
            
            .form-control {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 14px;
            }
            
            .form-control:focus {
                outline: none;
                border-color: #007bff;
            }
            
            .field-description {
                font-size: 13px;
                color: #666;
                margin-top: 5px;
            }
            
            .read-only-field {
                padding: 8px 12px;
                background-color: #f5f5f5;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                font-family: monospace;
                font-size: 13px;
            }
            
            .error-message {
                padding: 12px;
                background-color: #fee;
                border: 1px solid #fcc;
                border-radius: 4px;
                color: #c00;
                margin-top: 15px;
            }
        </style>
        
        <script>
            function openModal(modalId) {
                document.getElementById(modalId).style.display = 'flex';
            }
            
            function closeModal(modalId) {
                document.getElementById(modalId).style.display = 'none';
                // Clear any error messages
                const errorDiv = document.getElementById('modalErrorMessage');
                if (errorDiv) {
                    errorDiv.style.display = 'none';
                    errorDiv.textContent = '';
                }
            }
            
            function submitChunkConfig() {
                const form = document.getElementById('chunkConfigForm');
                const formData = new FormData(form);
                const errorDiv = document.getElementById('modalErrorMessage');
                
                // Clear previous errors
                errorDiv.style.display = 'none';
                errorDiv.textContent = '';
                
                // Convert FormData to URLSearchParams
                const params = new URLSearchParams(formData);
                
                // Validate via AJAX
                fetch('/customgpts/validate_chunk_config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: params
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to generate_chunks_eval.php with parameters
                        const redirectUrl = '/customgpts/generate_chunks_eval.php?' + params.toString();
                        window.location.href = redirectUrl;
                    } else {
                        // Display error message
                        errorDiv.textContent = data.error || 'Validation failed. Please check your inputs.';
                        errorDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    errorDiv.textContent = 'An error occurred while validating your configuration. Please try again.';
                    errorDiv.style.display = 'block';
                });
            }
        </script>
        <?php
        return ob_get_clean();
    }
}
