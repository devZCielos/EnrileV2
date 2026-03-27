-- ============================================================
--  MIGRATION: Add Leave Notification System Support
--  Date: 2025
-- ============================================================

-- Add missing columns to leave_types table
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS applicable_gender ENUM('Male','Female','All') DEFAULT 'All';
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS require_married TINYINT(1) DEFAULT 0;
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS advance_notice_days INT DEFAULT 0 COMMENT 'Days in advance employee must file leave (0 = same-day filing allowed)';

-- ============================================================
--  TABLE: leave_notifications
--  Tracks notifications to employees about upcoming leave deadlines and leave reminders
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_notifications (
  notification_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED  NOT NULL,
  request_id        INT UNSIGNED  NULL,                 -- FK to leave_requests (optional reference)
  leave_type_id     INT UNSIGNED  NOT NULL,
  notification_type ENUM('FILING_DEADLINE','LEAVE_REMINDER','LEAVE_ENDING','OTHER') NOT NULL DEFAULT 'OTHER',
  title             VARCHAR(200)  NOT NULL,
  message           TEXT          NOT NULL,
  target_date       DATE          NOT NULL COMMENT 'Date when notification should be shown (e.g., 5 days before filing deadline)',
  is_sent           TINYINT(1)    DEFAULT 0 COMMENT 'Whether notification has been marked as sent/viewed',
  read_at           TIMESTAMP     NULL,
  created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_emp   FOREIGN KEY (employee_id)   REFERENCES employees(employee_id)   ON DELETE CASCADE,
  CONSTRAINT fk_notif_req   FOREIGN KEY (request_id)    REFERENCES leave_requests(request_id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_type  FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE RESTRICT,
  INDEX idx_emp_sent        (employee_id, is_sent),
  INDEX idx_target_date     (target_date),
  INDEX idx_notif_type      (notification_type)
) ENGINE=InnoDB;

-- ============================================================
--  Update seed data to include advance notice requirements
-- ============================================================
-- Vacation Leave requires 5 days advance notice
UPDATE leave_types SET advance_notice_days = 5, applicable_gender = 'All', require_married = 0 WHERE code = 'VL';

-- Mandatory/Forced Leave - same-day filing allowed
UPDATE leave_types SET advance_notice_days = 0, applicable_gender = 'All', require_married = 0 WHERE code = 'MFL';

-- Sick Leave - same-day filing allowed
UPDATE leave_types SET advance_notice_days = 0, applicable_gender = 'All', require_married = 0 WHERE code = 'SL';

-- Maternity Leave requires advance filing
UPDATE leave_types SET advance_notice_days = 30, applicable_gender = 'Female', require_married = 0 WHERE code = 'ML';

-- Paternity Leave requires advance filing
UPDATE leave_types SET advance_notice_days = 14, applicable_gender = 'Male', require_married = 1 WHERE code = 'PL';

-- Special Privilege Leave - same-day filing allowed
UPDATE leave_types SET advance_notice_days = 0, applicable_gender = 'All', require_married = 0 WHERE code = 'SPL';

-- Solo Parent Leave requires advance notice
UPDATE leave_types SET advance_notice_days = 5, applicable_gender = 'All', require_married = 0 WHERE code = 'SOLO';

-- Study Leave requires advance notice
UPDATE leave_types SET advance_notice_days = 30, applicable_gender = 'All', require_married = 0 WHERE code = 'STL';

-- VAWC Leave requires advance notice
UPDATE leave_types SET advance_notice_days = 14, applicable_gender = 'Female', require_married = 0 WHERE code = 'VAWC';

-- Emergency Leave - same-day filing allowed
UPDATE leave_types SET advance_notice_days = 0, applicable_gender = 'All', require_married = 0 WHERE code = 'EL';
