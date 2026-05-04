-- Migration: Add is_deleted column to leave_requests for soft delete support
ALTER TABLE leave_requests ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0;
