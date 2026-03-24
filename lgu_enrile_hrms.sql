-- ============================================================
--  LGU ENRILE HR MANAGEMENT SYSTEM
--  Database Schema
--  Created for: Zyber John G. Cielos
--  System: Web-Based Employee Information and Leave Management
--  Standard: ISO 25010 | SDLC: Waterfall
-- ============================================================

CREATE DATABASE IF NOT EXISTS lgu_enrile_hrms
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lgu_enrile_hrms;

-- ============================================================
--  TABLE 1: departments
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
  department_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100)  NOT NULL UNIQUE,
  head_name       VARCHAR(150)  NULL,
  description     TEXT          NULL,
  icon            VARCHAR(10)   DEFAULT '🏢',
  color           VARCHAR(7)    DEFAULT '#2563eb',
  employee_count  INT UNSIGNED  DEFAULT 0,
  is_active       TINYINT(1)    DEFAULT 1,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 2: user_accounts
--  Stores login credentials for ALL roles
-- ============================================================
CREATE TABLE IF NOT EXISTS user_accounts (
  user_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(180)  NOT NULL UNIQUE,
  password_hash   VARCHAR(255)  NOT NULL,               -- bcrypt via password_hash()
  role            ENUM('admin','hr_staff','dept_head','employee') NOT NULL DEFAULT 'employee',
  is_active       TINYINT(1)    DEFAULT 1,
  last_login      TIMESTAMP     NULL,
  remember_token  VARCHAR(100)  NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 3: employees
--  Core employee information
-- ============================================================
CREATE TABLE IF NOT EXISTS employees (
  employee_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED  NULL,                 -- FK to user_accounts (NULL if no login yet)
  employee_code     VARCHAR(20)   NOT NULL UNIQUE,      -- e.g. EMP-001
  first_name        VARCHAR(80)   NOT NULL,
  middle_name       VARCHAR(80)   NULL,
  last_name         VARCHAR(80)   NOT NULL,
  gender            ENUM('Male','Female','Other') NOT NULL,
  date_of_birth     DATE          NULL,
  civil_status      ENUM('Single','Married','Widowed','Separated','Other') DEFAULT 'Single',
  home_address      TEXT          NULL,
  contact_number    VARCHAR(20)   NULL,
  email_address     VARCHAR(180)  NULL,
  department_id     INT UNSIGNED  NULL,
  position_title    VARCHAR(120)  NOT NULL,
  employment_type   ENUM('Permanent','Contractual','Casual','Job Order') NOT NULL DEFAULT 'Permanent',
  employment_status ENUM('Active','On Leave','Inactive','Resigned','Retired') NOT NULL DEFAULT 'Active',
  salary_grade      VARCHAR(20)   NULL,                 -- e.g. SG-14
  gsis_number       VARCHAR(30)   NULL,
  pagibig_number    VARCHAR(30)   NULL,
  philhealth_number VARCHAR(30)   NULL,
  tin_number        VARCHAR(30)   NULL,
  date_hired        DATE          NOT NULL,
  date_separated    DATE          NULL,
  profile_photo     VARCHAR(255)  NULL,
  created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_emp_user   FOREIGN KEY (user_id)       REFERENCES user_accounts(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_dept   FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 4: leave_types
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_types (
  leave_type_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)   NOT NULL UNIQUE,        -- Vacation, Sick, Emergency…
  code            VARCHAR(10)   NOT NULL UNIQUE,        -- VL, SL, EL, ML, PL, SPL
  max_days        DECIMAL(6,2)  NOT NULL DEFAULT 15.00,
  description     TEXT          NULL,
  is_active       TINYINT(1)    DEFAULT 1,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 5: leave_balances
--  Per-employee, per-year credits
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_balances (
  balance_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED  NOT NULL,
  leave_type_id   INT UNSIGNED  NOT NULL,
  calendar_year   YEAR          NOT NULL,
  total_credits   DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  used_days       DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  remaining_days  DECIMAL(6,2)  GENERATED ALWAYS AS (total_credits - used_days) STORED,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_type_year (employee_id, leave_type_id, calendar_year),
  CONSTRAINT fk_bal_emp   FOREIGN KEY (employee_id)   REFERENCES employees(employee_id)   ON DELETE CASCADE,
  CONSTRAINT fk_bal_type  FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 6: leave_requests
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_requests (
  request_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED  NOT NULL,
  leave_type_id   INT UNSIGNED  NOT NULL,
  date_from       DATE          NOT NULL,
  date_to         DATE          NOT NULL,
  days_requested  DECIMAL(6,2)  NOT NULL,
  reason          TEXT          NOT NULL,
  status          ENUM('Pending','Approved','Denied','Cancelled') NOT NULL DEFAULT 'Pending',
  admin_remarks   TEXT          NULL,
  reviewed_by     INT UNSIGNED  NULL,                   -- FK to user_accounts (admin who acted)
  reviewed_at     TIMESTAMP     NULL,
  filed_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_req_emp     FOREIGN KEY (employee_id)   REFERENCES employees(employee_id)     ON DELETE CASCADE,
  CONSTRAINT fk_req_type    FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE RESTRICT,
  CONSTRAINT fk_req_reviewer FOREIGN KEY (reviewed_by)  REFERENCES user_accounts(user_id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 7: announcements
-- ============================================================
CREATE TABLE IF NOT EXISTS announcements (
  announcement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title           VARCHAR(200)  NOT NULL,
  body            TEXT          NOT NULL,
  tag             ENUM('INFO','URGENT','NOTICE','WARNING') DEFAULT 'INFO',
  posted_by       INT UNSIGNED  NULL,
  is_active       TINYINT(1)    DEFAULT 1,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ann_user FOREIGN KEY (posted_by) REFERENCES user_accounts(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 8: documents
--  HR documents attached to employees
-- ============================================================
CREATE TABLE IF NOT EXISTS documents (
  document_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED  NOT NULL,
  document_type   VARCHAR(80)   NOT NULL,               -- e.g. Contract, Certificate, ID
  file_name       VARCHAR(255)  NOT NULL,
  file_path       VARCHAR(500)  NOT NULL,
  file_size       INT UNSIGNED  NULL,                   -- bytes
  uploaded_by     INT UNSIGNED  NULL,
  uploaded_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_emp  FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES user_accounts(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 9: activity_logs
--  Audit trail for admin/HR actions
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
  log_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED  NULL,
  action          VARCHAR(200)  NOT NULL,               -- e.g. "Approved leave request #12"
  module          VARCHAR(60)   NULL,                   -- e.g. "Leave Requests"
  reference_id    INT UNSIGNED  NULL,                   -- ID of the affected record
  ip_address      VARCHAR(45)   NULL,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES user_accounts(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  INDEXES  (for common query patterns)
-- ============================================================
CREATE INDEX idx_emp_dept       ON employees (department_id);
CREATE INDEX idx_emp_status     ON employees (employment_status);
CREATE INDEX idx_emp_type       ON employees (employment_type);
CREATE INDEX idx_req_employee   ON leave_requests (employee_id);
CREATE INDEX idx_req_status     ON leave_requests (status);
CREATE INDEX idx_req_dates      ON leave_requests (date_from, date_to);
CREATE INDEX idx_bal_year       ON leave_balances (calendar_year);
CREATE INDEX idx_log_user       ON activity_logs (user_id);
CREATE INDEX idx_log_created    ON activity_logs (created_at);