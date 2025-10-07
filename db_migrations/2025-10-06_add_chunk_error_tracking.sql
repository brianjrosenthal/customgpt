-- Add error tracking to chunks table
-- This migration adds columns to track embedding errors for each chunk

USE customgpt;

-- Add error tracking columns to chunks table
ALTER TABLE customgpt_document_chunks 
ADD COLUMN embedding_error TEXT DEFAULT NULL COMMENT 'Error message if embedding failed',
ADD COLUMN embedding_attempted_at DATETIME DEFAULT NULL COMMENT 'When embedding was last attempted';

CREATE INDEX idx_cgdc_embedding_error ON customgpt_document_chunks(embedding_error(100));
CREATE INDEX idx_cgdc_embedding_attempted ON customgpt_document_chunks(embedding_attempted_at);
