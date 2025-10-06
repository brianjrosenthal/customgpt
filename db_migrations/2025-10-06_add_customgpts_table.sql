-- Migration: Add customgpts table
-- Date: 2025-10-06
-- Description: Creates the customgpts table for managing custom GPT instances

-- Custom GPTs table
CREATE TABLE IF NOT EXISTS customgpts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  created_by INT NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customgpts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_customgpts_created_by ON customgpts(created_by);
CREATE INDEX idx_customgpts_is_public ON customgpts(is_public);
CREATE INDEX idx_customgpts_created_at ON customgpts(created_at);
