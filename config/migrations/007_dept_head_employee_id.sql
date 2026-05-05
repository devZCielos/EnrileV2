-- ============================================================
--  LGU Enrile HRMS — Migration 007
--  Add head_employee_id FK to departments (M-2)
--  head_name kept as display fallback for legacy data
-- ============================================================

-- Add the new relational column
ALTER TABLE departments
    ADD COLUMN IF NOT EXISTS head_employee_id INT UNSIGNED NULL DEFAULT NULL
        AFTER head_name;

-- FK: when an employee is deleted, just clear the reference
ALTER TABLE departments
    ADD CONSTRAINT IF NOT EXISTS fk_dept_head_employee
    FOREIGN KEY (head_employee_id) REFERENCES employees(employee_id)
    ON DELETE SET NULL;

-- Index for fast lookups (e.g. "which depts does employee X head?")
ALTER TABLE departments
    ADD INDEX IF NOT EXISTS idx_dept_head_emp (head_employee_id);
