-- Migration 009: Document the `audit_trail` table's schema
--
-- api/feedback_update_status.php already INSERTs into audit_trail, and it already exists
-- on the live database (confirmed via SHOW TABLES / SHOW COLUMNS, with real
-- historical rows) — but no CREATE TABLE for it exists anywhere in this
-- project's SQL files. A fresh install built only from web_system.sql plus
-- migrations 001-008 would be missing this table entirely, and the very
-- first status change would fail with
-- "Table 'web_system.audit_trail' doesn't exist".
--
-- This migration only codifies the table's already-live structure
-- (IF NOT EXISTS — a no-op here, since the table already exists with this
-- exact shape). It does not alter any existing data.
--
-- This is the real, single-admin audit trail. The separate `audit_log` table
-- (defined in web_system.sql) has no writer anywhere in the codebase and
-- stays empty — see this same batch's change to api/admin_modules.php,
-- which now reads the dashboard's "Audit Log" page from this table instead.

CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NULL DEFAULT 0
        COMMENT 'FK-like reference to feedback_entries.id; 0/NULL for non-feedback actions (e.g. staff CRUD)',
    user_id INT NULL,
    user_name VARCHAR(120) NULL,
    action VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_trail_feedback_id (feedback_id),
    INDEX idx_audit_trail_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
