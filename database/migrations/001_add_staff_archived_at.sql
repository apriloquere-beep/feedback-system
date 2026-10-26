-- Add archived_at column to staff table for soft delete support
ALTER TABLE staff ADD COLUMN archived_at DATETIME NULL AFTER is_active;
