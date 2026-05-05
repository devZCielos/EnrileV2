-- ============================================================
--  LGU Enrile HRMS — Migration 006
--  Constraints and Indexes (audit findings DB-2 through DB-10)
-- ============================================================

-- DB-2: UNIQUE constraint on leave_balances (employee, type, year)
-- Prevents duplicate rows that cause double-deduction bugs
ALTER TABLE leave_balances
    ADD UNIQUE INDEX IF NOT EXISTS uq_lb_emp_type_year (employee_id, leave_type_id, calendar_year);

-- DB-3: UNIQUE constraint on user_accounts (employee_id)
-- One employee should only have one user account
ALTER TABLE user_accounts
    ADD UNIQUE INDEX IF NOT EXISTS uq_ua_employee_id (employee_id);

-- DB-4: FK from contracts to employees
ALTER TABLE contracts
    ADD CONSTRAINT IF NOT EXISTS fk_contracts_employee
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
    ON DELETE CASCADE;

-- DB-6: Composite index on saln_records for (employee_id, saln_year)
ALTER TABLE saln_records
    ADD INDEX IF NOT EXISTS idx_saln_emp_year (employee_id, saln_year);

-- DB-7: Indexes on activity_logs for common lookup patterns
ALTER TABLE activity_logs
    ADD INDEX IF NOT EXISTS idx_al_created_at (created_at),
    ADD INDEX IF NOT EXISTS idx_al_user_id (user_id);

-- DB-9: UNIQUE constraint on holidays date to prevent double-counting
ALTER TABLE holidays
    ADD UNIQUE INDEX IF NOT EXISTS uq_holiday_date (holiday_date);

-- DB-10: Cron log table to track scheduled job executions
CREATE TABLE IF NOT EXISTS cron_log (
    log_id       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    job_name     VARCHAR(100)     NOT NULL,
    started_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at  DATETIME                  DEFAULT NULL,
    status       ENUM('running','success','error') NOT NULL DEFAULT 'running',
    message      TEXT                      DEFAULT NULL,
    PRIMARY KEY (log_id),
    INDEX idx_cl_job (job_name),
    INDEX idx_cl_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
