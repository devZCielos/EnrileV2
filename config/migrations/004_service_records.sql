-- ============================================================
--  MIGRATION: service_records + sr_requests tables
--             + profile_photo column on employees
--  Source: was runtime-created in Admin/service_records.php
--          and Employee/Employee_portal.php
-- ============================================================

ALTER TABLE employees ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS service_records (
  sr_id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NOT NULL,
  date_from        DATE         NULL,
  date_to          DATE         NULL,
  designation      VARCHAR(200) NULL,
  status           VARCHAR(100) NULL,
  salary           DECIMAL(14,2) NULL,
  branch           VARCHAR(200) NULL,
  station          VARCHAR(200) NULL,
  lvbs_wo_pay      VARCHAR(200) NULL,
  separation_date  DATE         NULL,
  separation_cause VARCHAR(200) NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sr_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sr_requests (
  req_id       INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  purpose      VARCHAR(300) NULL,
  status       ENUM('Pending','Approved','Denied') DEFAULT 'Pending',
  admin_note   TEXT NULL,
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL,
  INDEX idx_srr_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
