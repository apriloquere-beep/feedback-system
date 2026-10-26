-- Preserve the free-text description of Other while storing its selected
-- respondent category as the canonical value. Existing rows are unchanged.
ALTER TABLE feedbacks
    ADD COLUMN respondent_type_other VARCHAR(120) NULL AFTER respondent_type,
    ADD COLUMN respondent_type_other_enc LONGTEXT NULL AFTER respondent_type_other;
