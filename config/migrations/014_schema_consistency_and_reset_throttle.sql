-- ============================================================
-- Migration 014: Schema consistency and password-reset throttle
-- ============================================================

ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS is_mayor TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE leave_types
  ADD COLUMN IF NOT EXISTS applicable_gender ENUM('All','Male','Female') NOT NULL DEFAULT 'All',
  ADD COLUMN IF NOT EXISTS require_married TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE user_accounts
  ADD COLUMN IF NOT EXISTS reset_requested_at DATETIME NULL AFTER reset_expires;

-- uq_emp_type_year is retained as the canonical employee/type/year key.
ALTER TABLE leave_balances DROP INDEX uq_lb_emp_type_year;