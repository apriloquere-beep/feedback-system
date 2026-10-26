-- Persist respondent details that the Feedback Details modal must show exactly.
-- Kept on both active and legacy feedback records because submissions write both.
ALTER TABLE feedbacks
    ADD COLUMN IF NOT EXISTS service_other VARCHAR(120) NULL AFTER service_subtype_other,
    ADD COLUMN IF NOT EXISTS gender_preference_other VARCHAR(120) NULL AFTER gender_preference,
    ADD COLUMN IF NOT EXISTS religion_other VARCHAR(120) NULL AFTER religion,
    ADD COLUMN IF NOT EXISTS facility VARCHAR(160) NULL AFTER education_level,
    ADD COLUMN IF NOT EXISTS cc1 VARCHAR(20) NULL AFTER facility,
    ADD COLUMN IF NOT EXISTS cc2 VARCHAR(40) NULL AFTER cc1,
    ADD COLUMN IF NOT EXISTS cc3 VARCHAR(40) NULL AFTER cc2;

ALTER TABLE feedback_entries
    ADD COLUMN IF NOT EXISTS service_subtype_other VARCHAR(120) NULL AFTER service_subtype_id,
    ADD COLUMN IF NOT EXISTS service_other VARCHAR(120) NULL AFTER service_subtype_other,
    ADD COLUMN IF NOT EXISTS facility VARCHAR(160) NULL AFTER feedback_type,
    ADD COLUMN IF NOT EXISTS gender_preference_other VARCHAR(120) NULL AFTER facility,
    ADD COLUMN IF NOT EXISTS religion_other VARCHAR(120) NULL AFTER gender_preference_other,
    ADD COLUMN IF NOT EXISTS cc1 VARCHAR(20) NULL AFTER religion_other,
    ADD COLUMN IF NOT EXISTS cc2 VARCHAR(40) NULL AFTER cc1,
    ADD COLUMN IF NOT EXISTS cc3 VARCHAR(40) NULL AFTER cc2;
