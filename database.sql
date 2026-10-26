-- Smart Feedback Management System (SFMS)
-- Clean thesis deployment schema
--
-- Target verified against: MariaDB 10.4.32
-- Import this file into an empty database selected/provided by the host.
-- This script intentionally does not CREATE or DROP a database and does not
-- assume that the deployment database is named `web_system`.
--
-- Included data is limited to the service catalog, service subtypes, stable
-- staff rating-role definitions, and required system settings. Feedback,
-- notifications, audit history, administrator credentials, environment
-- values, encryption keys, SMTP credentials, and other secrets are excluded.
--
-- PHP configures Asia/Manila and each PDO connection sets MySQL to +08:00.
-- No global or session timezone setting is required in this deployment SQL.

-- --------------------------------------------------------------------------
-- 1. Character-set and session setup
-- --------------------------------------------------------------------------

SET @SFMS_OLD_CHARACTER_SET_CLIENT = @@CHARACTER_SET_CLIENT;
SET @SFMS_OLD_CHARACTER_SET_RESULTS = @@CHARACTER_SET_RESULTS;
SET @SFMS_OLD_COLLATION_CONNECTION = @@COLLATION_CONNECTION;
SET @SFMS_OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------------
-- 2. Catalog and configuration tables
-- --------------------------------------------------------------------------

CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_subtypes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_subtype_name` (`service_id`,`name`),
  CONSTRAINT `fk_service_subtypes_service`
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `role` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_role` (`role`),
  CONSTRAINT `chk_staff_is_active` CHECK (`is_active` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','staff') NOT NULL DEFAULT 'admin',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` varchar(120) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 3. Feedback tables
-- --------------------------------------------------------------------------
-- Both tables are required by the current application. `feedback_entries`
-- remains the admin workflow source, while `feedbacks` stores the fuller
-- canonical/demographic record. Current PHP writes them in one transaction.

CREATE TABLE `feedbacks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_id` varchar(32) NOT NULL,
  `source` varchar(20) NOT NULL,
  `user_type` varchar(32) DEFAULT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 1,
  `feedback_type` varchar(32) DEFAULT NULL,
  `respondent_type` varchar(120) DEFAULT NULL,
  `respondent_type_other` varchar(120) DEFAULT NULL,
  `respondent_type_other_enc` longtext DEFAULT NULL,
  `age` tinyint(3) unsigned DEFAULT NULL COMMENT 'Respondent age (1-99); fb2 survey field "edad"',
  `sex` varchar(20) DEFAULT NULL COMMENT 'Respondent sex (Babaye/Lalaki); fb2 survey field "sex"',
  `gender_preference` varchar(120) DEFAULT NULL COMMENT 'Preferred gender of attending staff, incl. free-text "other"; fb2 survey field "gender_pref"',
  `gender_preference_other` varchar(120) DEFAULT NULL,
  `religion` varchar(120) DEFAULT NULL COMMENT 'Respondent religion, incl. free-text "other"; fb2 survey field "relihiyon"',
  `religion_other` varchar(120) DEFAULT NULL,
  `visit_frequency` varchar(20) DEFAULT NULL COMMENT 'How many times the respondent has visited the facility; fb2 survey field "freq"',
  `education_level` varchar(60) DEFAULT NULL COMMENT 'Highest educational attainment; fb2 survey field "educ"',
  `facility` varchar(160) DEFAULT NULL,
  `cc1` varchar(20) DEFAULT NULL,
  `cc2` varchar(40) DEFAULT NULL,
  `cc3` varchar(40) DEFAULT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `service_subtype_id` int(11) DEFAULT NULL,
  `service_subtype_other` varchar(120) DEFAULT NULL,
  `service_other` varchar(120) DEFAULT NULL,
  `service` varchar(120) NOT NULL,
  `is_custom_service` tinyint(1) NOT NULL DEFAULT 0,
  `visit_date` date DEFAULT NULL,
  `staff_name` varchar(120) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `feedback` text NOT NULL,
  `suggestions` text DEFAULT NULL,
  `appreciation` text DEFAULT NULL,
  `rating` decimal(3,1) NOT NULL DEFAULT 0.0,
  `ratings_json` longtext NOT NULL,
  `sentiment_label` varchar(20) DEFAULT NULL,
  `sentiment_score` float DEFAULT NULL,
  `sentiment` varchar(20) DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `priority` varchar(10) NOT NULL DEFAULT 'Normal',
  `status` enum('pending','verified','resolved','adjusted','in_review','archived') NOT NULL DEFAULT 'pending',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `respondent_type_enc` longtext DEFAULT NULL,
  `age_enc` longtext DEFAULT NULL,
  `sex_enc` longtext DEFAULT NULL,
  `gender_preference_enc` longtext DEFAULT NULL,
  `gender_preference_other_enc` longtext DEFAULT NULL,
  `religion_enc` longtext DEFAULT NULL,
  `religion_other_enc` longtext DEFAULT NULL,
  `visit_frequency_enc` longtext DEFAULT NULL,
  `education_level_enc` longtext DEFAULT NULL,
  `full_name_enc` longtext DEFAULT NULL,
  `email_enc` longtext DEFAULT NULL,
  `contact_number_enc` longtext DEFAULT NULL,
  `service_subtype_other_enc` longtext DEFAULT NULL,
  `service_other_enc` longtext DEFAULT NULL,
  `staff_name_enc` longtext DEFAULT NULL,
  `subject_enc` longtext DEFAULT NULL,
  `feedback_enc` longtext DEFAULT NULL,
  `suggestions_enc` longtext DEFAULT NULL,
  `appreciation_enc` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_id` (`reference_id`),
  KEY `idx_feedbacks_source` (`source`),
  KEY `idx_feedbacks_user_type` (`user_type`),
  KEY `idx_feedbacks_service` (`service`),
  KEY `idx_feedbacks_status` (`status`),
  KEY `idx_feedbacks_created_at` (`submitted_at`),
  KEY `idx_feedbacks_sentiment` (`sentiment_label`),
  KEY `fk_feedbacks_service` (`service_id`),
  KEY `idx_feedbacks_custom_service` (`is_custom_service`),
  KEY `idx_feedbacks_service_subtype` (`service_subtype_id`),
  CONSTRAINT `fk_feedbacks_service`
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_feedbacks_service_subtype`
    FOREIGN KEY (`service_subtype_id`) REFERENCES `service_subtypes` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `chk_feedbacks_ratings_json` CHECK (json_valid(`ratings_json`)),
  CONSTRAINT `chk_feedbacks_age` CHECK (`age` IS NULL OR `age` BETWEEN 1 AND 99),
  CONSTRAINT `chk_feedbacks_rating` CHECK (`rating` BETWEEN 0 AND 5),
  CONSTRAINT `chk_feedbacks_sentiment_score` CHECK (`sentiment_score` IS NULL OR `sentiment_score` BETWEEN 0 AND 1),
  CONSTRAINT `chk_feedbacks_confidence` CHECK (`confidence` IS NULL OR `confidence` BETWEEN 0 AND 1),
  CONSTRAINT `chk_feedbacks_is_anonymous` CHECK (`is_anonymous` IN (0,1)),
  CONSTRAINT `chk_feedbacks_is_custom_service` CHECK (`is_custom_service` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `feedback_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_id` varchar(32) NOT NULL,
  `user_type` varchar(32) DEFAULT NULL,
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 1,
  `feedback_type` varchar(32) DEFAULT NULL,
  `facility` varchar(160) DEFAULT NULL,
  `gender_preference_other` varchar(120) DEFAULT NULL,
  `religion_other` varchar(120) DEFAULT NULL,
  `cc1` varchar(20) DEFAULT NULL,
  `cc2` varchar(40) DEFAULT NULL,
  `cc3` varchar(40) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `service_subtype_id` int(11) DEFAULT NULL,
  `service_subtype_other` varchar(120) DEFAULT NULL,
  `service_other` varchar(120) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `service` varchar(120) DEFAULT NULL,
  `is_custom_service` tinyint(1) NOT NULL DEFAULT 0,
  `visit_date` date DEFAULT NULL,
  `staff_name` varchar(120) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `suggestions` text DEFAULT NULL,
  `appreciation` text DEFAULT NULL,
  `sentiment_label` varchar(20) DEFAULT NULL COMMENT 'NLP classification: Positive, Neutral, or Negative',
  `sentiment_score` float DEFAULT NULL COMMENT 'Sentiment confidence score between 0.0 and 1.0',
  `sentiment` varchar(20) DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `priority` varchar(10) DEFAULT 'Normal' COMMENT 'Priority: Normal or High (High = Negative sentiment AND rating <= 2)',
  `ratings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `status` enum('pending','in_review','resolved','archived','verified','adjusted') NOT NULL DEFAULT 'pending' COMMENT 'Workflow status',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `gender_preference_other_enc` longtext DEFAULT NULL,
  `religion_other_enc` longtext DEFAULT NULL,
  `service_subtype_other_enc` longtext DEFAULT NULL,
  `service_other_enc` longtext DEFAULT NULL,
  `staff_name_enc` longtext DEFAULT NULL,
  `subject_enc` longtext DEFAULT NULL,
  `feedback_enc` longtext DEFAULT NULL,
  `suggestions_enc` longtext DEFAULT NULL,
  `appreciation_enc` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_id` (`reference_id`),
  KEY `idx_service` (`service`),
  KEY `idx_staff` (`staff_name`),
  KEY `idx_service_id` (`service_id`),
  KEY `idx_staff_id` (`staff_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_sentiment_label` (`sentiment_label`),
  KEY `idx_priority` (`priority`),
  KEY `idx_is_custom_service` (`is_custom_service`),
  KEY `idx_feedback_entries_service_subtype` (`service_subtype_id`),
  CONSTRAINT `fk_feedback_service`
    FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_service_subtype`
    FOREIGN KEY (`service_subtype_id`) REFERENCES `service_subtypes` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_feedback_staff`
    FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `chk_feedback_entries_ratings` CHECK (json_valid(`ratings`)),
  CONSTRAINT `chk_feedback_entries_sentiment_score` CHECK (`sentiment_score` IS NULL OR `sentiment_score` BETWEEN 0 AND 1),
  CONSTRAINT `chk_feedback_entries_confidence` CHECK (`confidence` IS NULL OR `confidence` BETWEEN 0 AND 1),
  CONSTRAINT `chk_feedback_entries_is_anonymous` CHECK (`is_anonymous` IN (0,1)),
  CONSTRAINT `chk_feedback_entries_is_custom_service` CHECK (`is_custom_service` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 4. Notification and audit tables
-- --------------------------------------------------------------------------

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('info','warning','danger','success') NOT NULL DEFAULT 'info',
  `title` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `reference_id` varchar(32) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_read` (`is_read`),
  KEY `idx_notifications_type` (`type`),
  KEY `idx_notifications_created_at` (`created_at`),
  KEY `idx_notifications_reference_id` (`reference_id`),
  CONSTRAINT `chk_notifications_is_read` CHECK (`is_read` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_trail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `feedback_id` int(11) DEFAULT 0,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_trail_feedback_id` (`feedback_id`),
  KEY `idx_audit_trail_user_id` (`user_id`),
  KEY `idx_audit_trail_created_at` (`created_at`),
  KEY `idx_audit_trail_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No feedback_id foreign key is added to audit_trail: the current settings
-- audit path intentionally records feedback_id = 0 for non-feedback events.

-- --------------------------------------------------------------------------
-- 5. Required reference seeds
-- --------------------------------------------------------------------------

INSERT INTO `services` (`id`, `name`, `status`) VALUES
  (1,  'Medical Consultation/Procedure', 'active'),
  (2,  'Dental Consultation/Procedure', 'active'),
  (3,  'Laboratory Test and Imaging', 'active'),
  (4,  'Medical Documents', 'active'),
  (5,  'Counseling', 'active'),
  (6,  'Animal Bite Treatment', 'active'),
  (7,  'TB DOTS', 'active'),
  (8,  'Family Planning', 'active'),
  (9,  'Immunization', 'active'),
  (10, 'Newborn Screening', 'active'),
  (11, 'Other Health Program-specific Intervention', 'active'),
  (12, 'Normal Spontaneous Delivery', 'active'),
  (13, 'Dialysis', 'active'),
  (14, 'Others', 'active');

INSERT INTO `service_subtypes` (`id`, `service_id`, `name`, `status`) VALUES
  (14, 4,  'Sanitary Permit', 'active'),
  (15, 4,  'Medical Certificate (MedCert)', 'active'),
  (16, 4,  'Death Certificate', 'active'),
  (17, 4,  'Other', 'active'),
  (18, 5,  'Psychological Counseling', 'active'),
  (19, 5,  'Nutrition Counseling', 'active'),
  (20, 5,  'HIV/AIDS Counseling', 'active'),
  (21, 5,  'Smoking Cessation Counseling', 'active'),
  (22, 5,  'Other', 'active'),
  (23, 11, 'Dengue', 'active'),
  (24, 11, 'Nutrition', 'active'),
  (25, 11, 'Mother and Child Care', 'active'),
  (26, 11, 'Other', 'active');

INSERT INTO `staff` (`id`, `full_name`, `role`, `is_active`, `archived_at`) VALUES
  (1,  'Doctor', 'st0', 1, NULL),
  (2,  'Dentist', 'st1', 1, NULL),
  (3,  'Nurse', 'st2', 1, NULL),
  (4,  'Midwife', 'st3', 1, NULL),
  (5,  'Sanitary Inspector', 'st4', 1, NULL),
  (6,  'Barangay Health Aide/Worker', 'st5', 1, NULL),
  (7,  'Medical Technologist or Laboratory Aide', 'st6', 1, NULL),
  (8,  'Pharmacist / Pharmacy Assistant', 'st7', 1, NULL),
  (9,  'Administrative Aide/Clerk', 'st8', 1, NULL),
  (10, 'Ambulance Driver', 'st9', 1, NULL);

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_by`) VALUES
  ('admin_facility_name', 'Calape Primary Care Facility', NULL),
  ('admin_satisfaction_benchmark', '80', NULL),
  ('admin_pending_threshold', '10', NULL),
  ('admin_negative_sentiment_alert_percent', '20', NULL),
  ('admin_auto_resolve_positive_feedback', '0', NULL);

INSERT INTO `admin_users` (`username`, `full_name`, `email`, `password_hash`, `role`, `status`) VALUES
  ('SFMS_Admin', 'Super Admin', 'admin@web_system.local', '$2y$10$QJ0xgY7NnWkM7xZV0m0vQO1V2Lr9pQ4dN8lVf4h2M7xY6bZ9d1e7K', 'super_admin', 'active');

-- --------------------------------------------------------------------------
-- 6. Administrator provisioning
-- --------------------------------------------------------------------------
-- A clean import creates one active `super_admin` account from the seed above.
-- Change its credentials immediately after the first production login.

-- Production encryption configuration is also external to SQL. Generate a
-- separate production AES key and configure SFMS_AES_ACTIVE_KEY_ID plus its
-- matching SFMS_AES_KEY_<NORMALIZED_KEY_ID> environment variable. Never put
-- encryption key material in this file.

-- --------------------------------------------------------------------------
-- 7. Restore session settings
-- --------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = @SFMS_OLD_FOREIGN_KEY_CHECKS;
SET CHARACTER_SET_CLIENT = @SFMS_OLD_CHARACTER_SET_CLIENT;
SET CHARACTER_SET_RESULTS = @SFMS_OLD_CHARACTER_SET_RESULTS;
SET COLLATION_CONNECTION = @SFMS_OLD_COLLATION_CONNECTION;

-- --------------------------------------------------------------------------
-- 8. Optional post-import verification (run manually)
-- --------------------------------------------------------------------------
-- SHOW TABLES;
-- SELECT COUNT(*) FROM services;          -- expected: 14
-- SELECT COUNT(*) FROM service_subtypes;  -- expected: 13
-- SELECT COUNT(*) FROM staff;             -- expected: 10
-- SELECT COUNT(*) FROM system_settings;   -- expected: 5
-- SELECT COUNT(*) FROM admin_users;       -- expected: 1 active super_admin
-- SELECT COUNT(*) FROM feedbacks;         -- expected: 0
-- SELECT COUNT(*) FROM feedback_entries;  -- expected: 0
-- SELECT COUNT(*) FROM notifications;     -- expected: 0
-- SELECT COUNT(*) FROM audit_trail;       -- expected: 0
