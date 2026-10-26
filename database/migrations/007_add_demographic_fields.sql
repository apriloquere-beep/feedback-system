-- Migration 007: Add respondent demographic fields to `feedbacks`
--
-- form/feedback.php (the PCF-CES survey) collects and requires five
-- demographic answers — edad (age), sex, gender_pref (gender preference),
-- relihiyon (religion), freq (visit frequency) — but submitForm() never
-- included them in the JSON payload sent to api/feedback_submit.php, so
-- they were validated in the browser and then discarded. This migration
-- adds the missing storage.
--
-- Scope: `feedbacks` ONLY, matching the established precedent already in
-- this schema — respondent_type, full_name, email, and contact_number
-- (respondent/identity-adjacent fields) were likewise added only to
-- `feedbacks`, never mirrored to `feedback_entries` (see migration 005's
-- own note: "feedback_entries is intentionally left untouched here").
-- `feedback_entries` remains the leaner workflow/status table.
--
-- Nullable, so existing rows (submitted before this feature existed)
-- remain valid with these fields NULL. No existing data is modified.

ALTER TABLE feedbacks
    ADD COLUMN IF NOT EXISTS age TINYINT UNSIGNED NULL
        COMMENT 'Respondent age (1-99); fb2 survey field "edad"'
        AFTER respondent_type,
    ADD COLUMN IF NOT EXISTS sex VARCHAR(20) NULL
        COMMENT 'Respondent sex (Babaye/Lalaki); fb2 survey field "sex"'
        AFTER age,
    ADD COLUMN IF NOT EXISTS gender_preference VARCHAR(120) NULL
        COMMENT 'Preferred gender of attending staff, incl. free-text "other"; fb2 survey field "gender_pref"'
        AFTER sex,
    ADD COLUMN IF NOT EXISTS religion VARCHAR(120) NULL
        COMMENT 'Respondent religion, incl. free-text "other"; fb2 survey field "relihiyon"'
        AFTER gender_preference,
    ADD COLUMN IF NOT EXISTS visit_frequency VARCHAR(20) NULL
        COMMENT 'How many times the respondent has visited the facility; fb2 survey field "freq"'
        AFTER religion;
