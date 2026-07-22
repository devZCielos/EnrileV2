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
  is_active              TINYINT(1)    DEFAULT 1,
  last_login             TIMESTAMP     NULL,
  remember_token         VARCHAR(100)  NULL,
  reset_token            VARCHAR(100)  NULL,
  reset_expires          DATETIME      NULL,
  failed_login_attempts  INT           NOT NULL DEFAULT 0,
  locked_until           DATETIME      NULL,
  must_change_password   TINYINT(1)    NOT NULL DEFAULT 0,
  created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
  suffix_name       VARCHAR(30)   NULL,
  last_name         VARCHAR(80)   NOT NULL,
  gender            ENUM('Male','Female','Other') NOT NULL,
  date_of_birth     DATE          NULL,
  civil_status      ENUM('Single','Married','Widowed','Separated','Other') DEFAULT 'Single',
  home_address      TEXT          NULL,
  contact_number    VARCHAR(20)   NULL,
  email_address     VARCHAR(180)  NULL,
  department_id     INT UNSIGNED  NULL,
  position_title    VARCHAR(120)  NOT NULL,
  employment_type   ENUM('Permanent','Co-Terminus','Elective','Contractual','Casual','Job Order') NOT NULL DEFAULT 'Permanent',
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
  applicable_gender ENUM('Male','Female','All') DEFAULT 'All',
  require_married TINYINT(1)    DEFAULT 0,
  advance_notice_days INT        DEFAULT 0 COMMENT 'Days in advance employee must file leave (0 = same-day filing allowed)',
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 4b: holidays
--  Non-working days used for business-day leave calculation.
--  Populate annually via admin or import from official IATF/CSC calendars.
-- ============================================================
CREATE TABLE IF NOT EXISTS holidays (
  holiday_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date  DATE         NOT NULL UNIQUE,
  description   VARCHAR(255) NOT NULL,
  is_recurring  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'If 1, the month-day recurs every year',
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
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
--  TABLE 11: chat_messages
--  Stores messages between admins and employees
-- ============================================================
CREATE TABLE IF NOT EXISTS chat_messages (
  message_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id       INT UNSIGNED  NOT NULL,
  receiver_id     INT UNSIGNED  NOT NULL,
  message         TEXT          NOT NULL,
  is_read         TINYINT(1)    DEFAULT 0,
  is_unsent       TINYINT(1)    DEFAULT 0,
  is_edited       TINYINT(1)    DEFAULT 0,
  sent_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  edited_at       TIMESTAMP     NULL,
  CONSTRAINT fk_chat_sender FOREIGN KEY (sender_id) REFERENCES user_accounts(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_receiver FOREIGN KEY (receiver_id) REFERENCES user_accounts(user_id) ON DELETE CASCADE,
  INDEX idx_sender_receiver (sender_id, receiver_id),
  INDEX idx_receiver_unread (receiver_id, is_read)
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 12: pds_records
--  Stores employee Personal Data Sheets (CS Form 212, Revised 2025)
-- ============================================================
CREATE TABLE IF NOT EXISTS pds_records (
  pds_id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  employee_id        INT UNSIGNED  NOT NULL,
  personal_info      LONGTEXT      NULL COMMENT 'JSON: all personal info fields',
  family_background  LONGTEXT      NULL COMMENT 'JSON: spouse + parents + children',
  education          LONGTEXT      NULL COMMENT 'JSON array: education levels',
  civil_service      LONGTEXT      NULL COMMENT 'JSON array: eligibilities',
  work_experience    LONGTEXT      NULL COMMENT 'JSON array',
  voluntary_work     LONGTEXT      NULL COMMENT 'JSON array',
  trainings          LONGTEXT      NULL COMMENT 'JSON array',
  other_info         LONGTEXT      NULL COMMENT 'JSON: skills, distinctions, memberships',
  q4_questions       LONGTEXT      NULL COMMENT 'JSON array: Q34-Q40 integrity questions',
  pds_references     LONGTEXT      NULL COMMENT 'JSON array: character references',
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pds_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
  UNIQUE KEY uk_pds_employee (employee_id)
) ENGINE=InnoDB;

-- ============================================================
--  TABLE 13: saln_records
--  Stores employee SALN (2025 form, per CSC Resolution No. 2500632)
-- ============================================================
CREATE TABLE IF NOT EXISTS saln_records (
  saln_id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  employee_id        INT UNSIGNED  NOT NULL,
  saln_year          SMALLINT      NOT NULL,
  as_of_date         DATE          NULL,
  filing_type        VARCHAR(20)   NULL COMMENT 'Annual, Assumption, Exit',
  filing_mode        VARCHAR(15)   NULL COMMENT 'Joint, Separate, NA',
  multi_spouse       VARCHAR(255)  NULL,
  declarant_info     LONGTEXT      NULL COMMENT 'JSON: lastname, firstname, mi, position, agency, office_addr',
  spouse_info        LONGTEXT      NULL COMMENT 'JSON: lastname, firstname, mi, position, agency, office_addr',
  children_info      LONGTEXT      NULL COMMENT 'JSON array: name, age',
  real_properties    LONGTEXT      NULL COMMENT 'JSON array: description, kind, location, assessed_value, market_value, acq_year, acq_mode, acq_cost',
  personal_properties LONGTEXT     NULL COMMENT 'JSON array: description, acq_year, acq_cost',
  liabilities        LONGTEXT      NULL COMMENT 'JSON array: nature, creditor, balance',
  biz_interests      LONGTEXT      NULL COMMENT 'JSON array: entity_name, biz_address, nature, date_acquired',
  relatives_govt     LONGTEXT      NULL COMMENT 'JSON array: name, relationship, position, agency_address',
  no_biz             TINYINT(1)    DEFAULT 0,
  no_rel             TINYINT(1)    DEFAULT 0,
  real_subtotal      DECIMAL(18,2) NULL,
  personal_subtotal  DECIMAL(18,2) NULL,
  total_assets       DECIMAL(18,2) NULL,
  total_liabilities  DECIMAL(18,2) NULL,
  net_worth          DECIMAL(18,2) NULL,
  as1_real_properties     LONGTEXT      NULL COMMENT 'JSON array: AS-1 additional declarant real properties',
  as1_personal_properties LONGTEXT      NULL COMMENT 'JSON array: AS-1 additional declarant personal properties',
  as1_liabilities         LONGTEXT      NULL COMMENT 'JSON array: AS-1 additional declarant liabilities',
  as1_biz_interests       LONGTEXT      NULL COMMENT 'JSON array: AS-1 additional declarant business interests',
  as1_real_subtotal       DECIMAL(18,2) NULL,
  as1_personal_subtotal   DECIMAL(18,2) NULL,
  as1_total_assets        DECIMAL(18,2) NULL,
  as1_total_liabilities   DECIMAL(18,2) NULL,
  as2_name_info           LONGTEXT      NULL COMMENT 'JSON: AS-2 name, position, agency/office',
  as2_real_properties     LONGTEXT      NULL COMMENT 'JSON array: AS-2 spouse/children real properties',
  as2_personal_properties LONGTEXT      NULL COMMENT 'JSON array: AS-2 spouse/children personal properties',
  as2_liabilities         LONGTEXT      NULL COMMENT 'JSON array: AS-2 spouse/children liabilities',
  as2_biz_interests       LONGTEXT      NULL COMMENT 'JSON array: AS-2 spouse/children business interests',
  gov_id             VARCHAR(100)  NULL,
  gov_id_no          VARCHAR(100)  NULL,
  gov_id_issued      DATE          NULL,
  created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_saln_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
  UNIQUE KEY uk_saln_emp_year (employee_id, saln_year)
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
-- P3-7: Additional indexes for common lookup patterns
CREATE INDEX idx_user_remember_token ON user_accounts (remember_token);
CREATE INDEX idx_doc_employee_id     ON documents (employee_id);

-- ============================================================
--  TABLE: contracts  (P2-13 — was absent from master schema)
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
) ENGINE=InnoDB;

-- ============================================================
--  TABLE: leave_notifications  (P2-13 — was absent from master schema)
-- ============================================================
CREATE TABLE IF NOT EXISTS leave_notifications (
  notification_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED  NOT NULL,
  request_id        INT UNSIGNED  NULL,
  leave_type_id     INT UNSIGNED  NOT NULL,
  notification_type ENUM('FILING_DEADLINE','LEAVE_REMINDER','LEAVE_ENDING','OTHER') NOT NULL DEFAULT 'OTHER',
  title             VARCHAR(200)  NOT NULL,
  message           TEXT          NOT NULL,
  target_date       DATE          NOT NULL,
  is_sent           TINYINT(1)    DEFAULT 0,
  read_at           TIMESTAMP     NULL,
  created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_emp  FOREIGN KEY (employee_id)   REFERENCES employees(employee_id)   ON DELETE CASCADE,
  CONSTRAINT fk_notif_req  FOREIGN KEY (request_id)    REFERENCES leave_requests(request_id) ON DELETE SET NULL,
  CONSTRAINT fk_notif_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id) ON DELETE RESTRICT,
  INDEX idx_notif_emp_sent  (employee_id, is_sent),
  INDEX idx_notif_target    (target_date)
) ENGINE=InnoDB;