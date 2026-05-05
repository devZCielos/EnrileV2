-- ============================================================
--  MIGRATION: system_settings table
--  Source: was runtime-created in Admin/settings.php
-- ============================================================

CREATE TABLE IF NOT EXISTS system_settings (
  setting_id    INT AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT,
  setting_group VARCHAR(50)  NOT NULL DEFAULT 'general',
  label         VARCHAR(150),
  description   VARCHAR(255),
  input_type    ENUM('text','number','email','textarea','select','toggle','password') NOT NULL DEFAULT 'text',
  options_json  TEXT,
  updated_by    INT DEFAULT NULL,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
