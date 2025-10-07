-- Add vector embeddings storage table
-- This migration creates the customgpt_vector_embeddings table for storing FAISS indices

USE customgpt;

-- Create vector embeddings table
CREATE TABLE IF NOT EXISTS customgpt_vector_embeddings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customgpt_id INT UNIQUE NOT NULL,
  dim INT NOT NULL COMMENT 'Embedding dimension size',
  embed_model VARCHAR(64) NOT NULL COMMENT 'Model used for embeddings (e.g., text-embedding-3-small)',
  faiss_bytes LONGBLOB NOT NULL COMMENT 'Serialized FAISS index',
  id_map_json JSON NOT NULL COMMENT 'Map of FAISS index to docstore IDs',
  docstore_json JSON NOT NULL COMMENT 'Document store with text and metadata',
  checksum CHAR(64) NOT NULL COMMENT 'SHA256 checksum of chunk contents for cache invalidation',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cve_customgpt FOREIGN KEY (customgpt_id) REFERENCES customgpts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_cve_customgpt ON customgpt_vector_embeddings(customgpt_id);
CREATE INDEX idx_cve_checksum ON customgpt_vector_embeddings(checksum);
CREATE INDEX idx_cve_updated_at ON customgpt_vector_embeddings(updated_at);
