-- RETIRED: legacy department-based migration. Do not execute against final SFMS.

ALTER TABLE feedbacks
    ADD COLUMN is_custom_service TINYINT(1) NOT NULL DEFAULT 0 AFTER service,
    ADD INDEX idx_feedbacks_custom_service (is_custom_service);

ALTER TABLE feedback_entries
    ADD COLUMN is_custom_service TINYINT(1) NOT NULL DEFAULT 0 AFTER service,
    ADD INDEX idx_is_custom_service (is_custom_service);

-- Backfill historical rows to avoid mixing custom vs normal analytics.
UPDATE feedbacks
SET is_custom_service = CASE
    WHEN LOWER(TRIM(department)) = 'other' THEN 1
    ELSE 0
END;

UPDATE feedback_entries
SET is_custom_service = CASE
    WHEN LOWER(TRIM(department)) = 'other' THEN 1
    ELSE 0
END;
