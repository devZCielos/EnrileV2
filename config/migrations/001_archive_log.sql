-- ============================================================
--  MIGRATION: archive_log table + employee archive columns
--  Source: was runtime-created in Admin/archives.php
-- ============================================================

ALTER TABLE employees ADD COLUMN IF NOT EXISTS archived_at    DATETIME     DEFAULT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS archive_log (
  log_id       INT AUTO_INCREMENT PRIMARY KEY,
  record_type  ENUM('employee','contract','leave_request','document','announcement') NOT NULL,
  record_id    INT NOT NULL,
  action       ENUM('archived','restored','deleted') NOT NULL,
  reason       VARCHAR(255) DEFAULT NULL,
  performed_by INT DEFAULT NULL,
  performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_record       (record_type, record_id),
  INDEX idx_performed_by (performed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
