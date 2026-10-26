-- Phase 2: Prepare nullable shadow columns for AES-256-GCM envelopes.
--
-- Additive only: existing plaintext columns and data remain unchanged.
-- Each value will later hold one versioned JSON envelope containing the
-- algorithm, key ID, IV, authentication tag, and ciphertext.

ALTER TABLE feedbacks
    ADD COLUMN respondent_type_enc LONGTEXT NULL,
    ADD COLUMN age_enc LONGTEXT NULL,
    ADD COLUMN sex_enc LONGTEXT NULL,
    ADD COLUMN gender_preference_enc LONGTEXT NULL,
    ADD COLUMN gender_preference_other_enc LONGTEXT NULL,
    ADD COLUMN religion_enc LONGTEXT NULL,
    ADD COLUMN religion_other_enc LONGTEXT NULL,
    ADD COLUMN visit_frequency_enc LONGTEXT NULL,
    ADD COLUMN education_level_enc LONGTEXT NULL,
    ADD COLUMN full_name_enc LONGTEXT NULL,
    ADD COLUMN email_enc LONGTEXT NULL,
    ADD COLUMN contact_number_enc LONGTEXT NULL,
    ADD COLUMN service_subtype_other_enc LONGTEXT NULL,
    ADD COLUMN service_other_enc LONGTEXT NULL,
    ADD COLUMN staff_name_enc LONGTEXT NULL,
    ADD COLUMN subject_enc LONGTEXT NULL,
    ADD COLUMN feedback_enc LONGTEXT NULL,
    ADD COLUMN suggestions_enc LONGTEXT NULL,
    ADD COLUMN appreciation_enc LONGTEXT NULL;

ALTER TABLE feedback_entries
    ADD COLUMN gender_preference_other_enc LONGTEXT NULL,
    ADD COLUMN religion_other_enc LONGTEXT NULL,
    ADD COLUMN service_subtype_other_enc LONGTEXT NULL,
    ADD COLUMN service_other_enc LONGTEXT NULL,
    ADD COLUMN staff_name_enc LONGTEXT NULL,
    ADD COLUMN subject_enc LONGTEXT NULL,
    ADD COLUMN feedback_enc LONGTEXT NULL,
    ADD COLUMN suggestions_enc LONGTEXT NULL,
    ADD COLUMN appreciation_enc LONGTEXT NULL;
