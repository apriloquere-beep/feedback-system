-- Migration: Add respondent_type column to feedback tables
-- Adds a nullable `respondent_type` to both `feedbacks` and `feedback_entries`.
-- Run this on the target database to make the column available for new submissions.

ALTER TABLE feedbacks
    ADD COLUMN respondent_type VARCHAR(120) NULL AFTER feedback_type;

ALTER TABLE feedback_entries
    ADD COLUMN respondent_type VARCHAR(120) NULL AFTER feedback_type;

-- Optional: backfill respondent_type from any existing metadata if available.
-- Example (requires an admin decision):
-- UPDATE feedbacks SET respondent_type = 'Unknown' WHERE respondent_type IS NULL;
