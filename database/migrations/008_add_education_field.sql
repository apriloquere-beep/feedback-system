-- Migration 008: Add respondent education field to `feedbacks`
--
-- form/feedback.php's Section 1 also collects and requires "educ" (Highest
-- Educational Attainment), but — like the five fields fixed by migration
-- 007 — it was never added to submitForm()'s JSON payload, so it never
-- reached the API or the database.
--
-- Scope: `feedbacks` ONLY, same precedent as migration 007 (age, sex,
-- gender_preference, religion, visit_frequency) and the earlier
-- respondent_type/full_name/email/contact_number columns — demographic /
-- respondent-adjacent fields live on `feedbacks`, not on the leaner
-- workflow/status table `feedback_entries`.
--
-- Nullable, so existing rows remain valid with this field NULL. No
-- existing data is modified.

ALTER TABLE feedbacks
    ADD COLUMN IF NOT EXISTS education_level VARCHAR(60) NULL
        COMMENT 'Highest educational attainment; fb2 survey field "educ"'
        AFTER visit_frequency;
