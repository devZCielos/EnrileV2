-- ============================================================
--  MIGRATION: Multi-Stage Leave Approval (Dept Head + HR/Admin)
--  Date: 2026-04-08
-- ============================================================

-- 1. Update status ENUM to support multi-stage approval
ALTER TABLE leave_requests 
  MODIFY COLUMN status ENUM(
    'Pending Dept Head',
    'Approved by Dept Head',
    'Denied by Dept Head',
    'Pending HR',
    'Approved',
    'Denied',
    'Cancelled'
  ) NOT NULL DEFAULT 'Pending Dept Head';

-- 2. Add department head review fields
ALTER TABLE leave_requests 
  ADD COLUMN dept_head_id INT UNSIGNED NULL AFTER status,
  ADD COLUMN dept_head_remarks TEXT NULL AFTER dept_head_id,
  ADD COLUMN dept_head_action_date TIMESTAMP NULL AFTER dept_head_remarks,
  ADD CONSTRAINT fk_req_dept_head FOREIGN KEY (dept_head_id) REFERENCES user_accounts(user_id) ON DELETE SET NULL;

-- (Optional) Update all existing 'Pending' requests to 'Pending Dept Head' for consistency
UPDATE leave_requests SET status = 'Pending Dept Head' WHERE status = 'Pending';
