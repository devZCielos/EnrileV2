-- ============================================================
--  MIGRATION: announcements table + column additions
--  Source: was runtime-created/altered in Admin/announcements.php
-- ============================================================

CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(255) NOT NULL,
  body            TEXT         NOT NULL,
  category        ENUM('General','HR Notice','Policy','Event','Holiday','Urgent') NOT NULL DEFAULT 'General',
  priority        ENUM('Normal','High','Urgent')                                  NOT NULL DEFAULT 'Normal',
  audience        ENUM('All','Permanent','Contractual','Casual','Job Order','Department') NOT NULL DEFAULT 'All',
  department_id   INT          DEFAULT NULL,
  pinned          TINYINT(1)   NOT NULL DEFAULT 0,
  status          ENUM('Draft','Published','Archived')                            NOT NULL DEFAULT 'Draft',
  publish_date    DATE         DEFAULT NULL,
  expiry_date     DATE         DEFAULT NULL,
  created_by      INT          DEFAULT NULL,
  created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ann_status       (status),
  INDEX idx_ann_category     (category),
  INDEX idx_ann_pinned       (pinned),
  INDEX idx_ann_publish_date (publish_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Safe column additions for upgrades from older schema
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS category     ENUM('General','HR Notice','Policy','Event','Holiday','Urgent') NOT NULL DEFAULT 'General' AFTER body;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS priority     ENUM('Normal','High','Urgent')                                  NOT NULL DEFAULT 'Normal'   AFTER category;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS audience     ENUM('All','Permanent','Contractual','Casual','Job Order','Department') NOT NULL DEFAULT 'All' AFTER priority;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS department_id INT DEFAULT NULL                                                                              AFTER audience;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS pinned       TINYINT(1) NOT NULL DEFAULT 0                                                                 AFTER department_id;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS status       ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Draft'                                 AFTER pinned;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS publish_date DATE DEFAULT NULL                                                                              AFTER status;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS expiry_date  DATE DEFAULT NULL                                                                              AFTER publish_date;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS created_by   INT DEFAULT NULL                                                                               AFTER expiry_date;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP                                 AFTER created_at;
