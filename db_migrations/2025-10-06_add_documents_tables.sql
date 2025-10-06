-- Migration: Add secure_files and customgpt_documents tables
-- Date: 2025-10-06
-- Description: Creates tables for secure file storage and document management

-- Secure files table (private document storage)
CREATE TABLE IF NOT EXISTS secure_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_sf_sha256 ON secure_files(sha256);
CREATE INDEX idx_sf_created_by ON secure_files(created_by_user_id);
CREATE INDEX idx_sf_created_at ON secure_files(created_at);

-- CustomGPT documents table (links documents to CustomGPTs)
CREATE TABLE IF NOT EXISTS customgpt_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customgpt_id INT NOT NULL,
  file_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cgd_customgpt FOREIGN KEY (customgpt_id) REFERENCES customgpts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cgd_file FOREIGN KEY (file_id) REFERENCES secure_files(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_cgd_customgpt ON customgpt_documents(customgpt_id);
CREATE INDEX idx_cgd_file ON customgpt_documents(file_id);
CREATE INDEX idx_cgd_created_at ON customgpt_documents(created_at);
