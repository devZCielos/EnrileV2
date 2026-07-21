-- Add optional suffix/name extension for employee records (e.g., Jr., Sr., III)
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS suffix_name VARCHAR(30) NULL AFTER middle_name;
