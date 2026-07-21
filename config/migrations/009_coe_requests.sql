-- ============================================================
--  Migration 009: Certificate of Employment Requests
--  Adds employee COE request workflow for Employee and Admin portals.
-- ============================================================

CREATE TABLE IF NOT EXISTS coe_requests (
  coe_request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  purpose        TEXT NOT NULL,
  status         ENUM('Pending','Approved','Denied','Cancelled') NOT NULL DEFAULT 'Pending',
  admin_remarks  TEXT NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    TIMESTAMP NULL,
  is_deleted     TINYINT(1) NOT NULL DEFAULT 0,
  filed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_coe_req_emp FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_coe_req_reviewer FOREIGN KEY (reviewed_by) REFERENCES user_accounts(user_id) ON DELETE SET NULL,
  INDEX idx_coe_status (status),
  INDEX idx_coe_employee_filed (employee_id, filed_at),
  INDEX idx_coe_deleted_status (is_deleted, status)
) ENGINE=InnoDB;
