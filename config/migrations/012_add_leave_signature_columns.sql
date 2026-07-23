-- ============================================================
--  Migration 012: Employee e-signature for leave forms
--  Adds reusable profile signature fields and leave-request snapshot fields
-- ============================================================

-- Store employee profile signature (uploaded once, reused for leave filing)
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS signature_file_path VARCHAR(255) NULL AFTER profile_photo,
  ADD COLUMN IF NOT EXISTS signature_original_name VARCHAR(255) NULL AFTER signature_file_path,
  ADD COLUMN IF NOT EXISTS signature_uploaded_at DATETIME NULL AFTER signature_original_name;

-- Snapshot the signature used when a leave request is filed
ALTER TABLE leave_requests
  ADD COLUMN IF NOT EXISTS signature_file_path VARCHAR(255) NULL AFTER reason,
  ADD COLUMN IF NOT EXISTS signature_original_name VARCHAR(255) NULL AFTER signature_file_path,
  ADD COLUMN IF NOT EXISTS signature_uploaded_at DATETIME NULL AFTER signature_original_name;
