-- Migration 005: Apply respondent_type to `feedbacks` (live-database gap fix)
--
-- database/migrations/004_add_respondent_type.sql already defines this
-- column for both `feedbacks` and `feedback_entries`, but it was never
-- actually applied to the live database (confirmed via SHOW COLUMNS).
-- This migration applies ONLY the `feedbacks` half, matching the explicit
-- scope of the respondent-type persistence task — `feedback_entries` is
-- intentionally left untouched here.
--
-- Nullable, so existing rows (submitted before this feature existed)
-- remain valid with respondent_type = NULL. No existing data is modified.

ALTER TABLE feedbacks
    ADD COLUMN IF NOT EXISTS respondent_type VARCHAR(120) NULL AFTER feedback_type;
