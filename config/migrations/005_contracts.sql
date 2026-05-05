-- ============================================================
--  MIGRATION: contracts table
--  Source: was runtime-created in Admin/contracts.php
--  Note: Also present in lgu_enrile_hrms.sql (added in P2-13)
-- ============================================================

CREATE TABLE IF NOT EXISTS contracts (
  contract_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED  NOT NULL,
  contract_type   ENUM('Permanent','Contractual','Casual','Job Order','Co-Terminus') NOT NULL DEFAULT 'Contractual',
  contract_number VARCHAR(100)  NULL,
  position_title  VARCHAR(150)  NOT NULL,
  department_id   INT UNSIGNED  NULL,
  salary_grade    VARCHAR(50)   NULL,
  monthly_salary  DECIMAL(12,2) NULL,
  start_date      DATE          NOT NULL,
  end_date        DATE          NULL,
  status          ENUM('Active','Expired','Terminated','Renewed') NOT NULL DEFAULT 'Active',
  remarks         TEXT          NULL,
  created_by      INT UNSIGNED  NULL,
  created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_con_employee  (employee_id),
  INDEX idx_con_status    (status),
  INDEX idx_con_end_date  (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
