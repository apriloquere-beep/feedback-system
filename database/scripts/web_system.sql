-- web_system database
-- Paste this into MySQL/phpMyAdmin

CREATE DATABASE IF NOT EXISTS web_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE web_system;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    UNIQUE KEY uq_services_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_subtypes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_subtype_name (service_id, name),
    CONSTRAINT fk_service_subtypes_service
        FOREIGN KEY (service_id)
        REFERENCES services(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    role VARCHAR(120) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    user_type VARCHAR(32) NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
    feedback_type VARCHAR(32) NULL,
    facility VARCHAR(160) NULL,
    gender_preference_other VARCHAR(120) NULL,
    religion_other VARCHAR(120) NULL,
    cc1 VARCHAR(20) NULL,
    cc2 VARCHAR(40) NULL,
    cc3 VARCHAR(40) NULL,
    service_id INT NULL,
    service_subtype_id INT NULL,
    service_subtype_other VARCHAR(120) NULL,
    service_other VARCHAR(120) NULL,
    staff_id INT NULL,
    service VARCHAR(120) NULL,
    is_custom_service TINYINT(1) NOT NULL DEFAULT 0,
    visit_date DATE NULL,
    staff_name VARCHAR(120) NULL,
    subject VARCHAR(255) NULL,
    feedback TEXT NULL,
    suggestions TEXT NULL,
    appreciation TEXT NULL,
    sentiment_label VARCHAR(20) NULL,
    sentiment_score FLOAT NULL,
    sentiment VARCHAR(20) NULL,
    confidence DECIMAL(5,2) NULL,
    priority VARCHAR(10) DEFAULT 'Normal',
    ratings LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
        CHECK (JSON_VALID(ratings)),
    status ENUM('pending','in_review','resolved','archived','verified','adjusted') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_feedback_staff
        FOREIGN KEY (staff_id) REFERENCES staff(id)
        ON DELETE SET NULL,
    INDEX idx_service (service),
    INDEX idx_staff (staff_name),
    INDEX idx_service_id (service_id),
    INDEX idx_staff_id (staff_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_sentiment_label (sentiment_label),
    INDEX idx_priority (priority),
    INDEX idx_is_custom_service (is_custom_service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedbacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    source VARCHAR(20) NOT NULL,
    user_type VARCHAR(32) NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
    feedback_type VARCHAR(32) NULL,
    respondent_type VARCHAR(120) NULL,
    respondent_type_other VARCHAR(120) NULL,
    respondent_type_other_enc LONGTEXT NULL,
    age TINYINT UNSIGNED NULL,
    sex VARCHAR(20) NULL,
    gender_preference VARCHAR(120) NULL,
    gender_preference_other VARCHAR(120) NULL,
    religion VARCHAR(120) NULL,
    religion_other VARCHAR(120) NULL,
    visit_frequency VARCHAR(20) NULL,
    education_level VARCHAR(60) NULL,
    facility VARCHAR(160) NULL,
    cc1 VARCHAR(20) NULL,
    cc2 VARCHAR(40) NULL,
    cc3 VARCHAR(40) NULL,
    full_name VARCHAR(120) NULL,
    email VARCHAR(160) NULL,
    contact_number VARCHAR(40) NULL,
    service_id INT NULL,
    service_subtype_id INT NULL,
    service_subtype_other VARCHAR(120) NULL,
    service_other VARCHAR(120) NULL,
    service VARCHAR(120) NOT NULL,
    is_custom_service TINYINT(1) NOT NULL DEFAULT 0,
    visit_date DATE NULL,
    staff_name VARCHAR(120) NULL,
    subject VARCHAR(255) NULL,
    feedback TEXT NOT NULL,
    suggestions TEXT NULL,
    appreciation TEXT NULL,
    rating DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    ratings_json LONGTEXT NOT NULL,
    sentiment_label VARCHAR(20) NULL,
    sentiment_score FLOAT NULL,
    sentiment VARCHAR(20) NULL,
    confidence DECIMAL(5,2) NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'Normal',
    status ENUM('pending', 'verified', 'resolved', 'adjusted', 'in_review', 'archived') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_feedbacks_source (source),
    INDEX idx_feedbacks_user_type (user_type),
    INDEX idx_feedbacks_service (service),
    INDEX idx_feedbacks_custom_service (is_custom_service),
    INDEX idx_feedbacks_status (status),
    INDEX idx_feedbacks_created_at (submitted_at),
    INDEX idx_feedbacks_sentiment (sentiment_label),
    KEY fk_feedbacks_service (service_id),
    CONSTRAINT fk_feedbacks_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'staff') NOT NULL DEFAULT 'admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('info', 'warning', 'danger', 'success') NOT NULL DEFAULT 'info',
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    reference_id VARCHAR(32) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_type (type),
    INDEX idx_notifications_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_name VARCHAR(120) NOT NULL,
    action VARCHAR(120) NOT NULL,
    reference_id VARCHAR(32) NULL,
    detail TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin (admin_name),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NULL DEFAULT 0,
    user_id INT NULL,
    user_name VARCHAR(120) NULL,
    action VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    must_reset_password TINYINT(1) NOT NULL DEFAULT 0,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    date_registered TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    needs_password_reset TINYINT(1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO services (id, name, status) VALUES
(1, 'Medical Consultation/Procedure', 'active'),
(2, 'Dental Consultation/Procedure', 'active'),
(3, 'Laboratory Test and Imaging', 'active'),
(4, 'Medical Documents', 'active'),
(5, 'Counseling', 'active'),
(6, 'Animal Bite Treatment', 'active'),
(7, 'TB DOTS', 'active'),
(8, 'Family Planning', 'active'),
(9, 'Immunization', 'active'),
(10, 'Newborn Screening', 'active'),
(11, 'Other Health Program-specific Intervention', 'active'),
(12, 'Normal Spontaneous Delivery', 'active'),
(13, 'Dialysis', 'active'),
(14, 'Others', 'active');

ALTER TABLE services AUTO_INCREMENT = 15;

INSERT IGNORE INTO service_subtypes (service_id, name, status) VALUES
(4, 'Sanitary Permit', 'active'),
(4, 'Medical Certificate (MedCert)', 'active'),
(4, 'Death Certificate', 'active'),
(4, 'Other', 'active'),
(5, 'Psychological Counseling', 'active'),
(5, 'Nutrition Counseling', 'active'),
(5, 'HIV/AIDS Counseling', 'active'),
(5, 'Smoking Cessation Counseling', 'active'),
(5, 'Other', 'active'),
(11, 'Dengue', 'active'),
(11, 'Nutrition', 'active'),
(11, 'Mother and Child Care', 'active'),
(11, 'Other', 'active');

INSERT IGNORE INTO staff (id, full_name, role, is_active, archived_at) VALUES
(1, 'Doctor', 'st0', 1, NULL),
(2, 'Dentist', 'st1', 1, NULL),
(3, 'Nurse', 'st2', 1, NULL),
(4, 'Midwife', 'st3', 1, NULL),
(5, 'Sanitary Inspector', 'st4', 1, NULL),
(6, 'Barangay Health Aide/Worker', 'st5', 1, NULL),
(7, 'Medical Technologist or Laboratory Aide', 'st6', 1, NULL),
(8, 'Pharmacist / Pharmacy Assistant', 'st7', 1, NULL),
(9, 'Administrative Aide/Clerk', 'st8', 1, NULL),
(10, 'Ambulance Driver', 'st9', 1, NULL);

INSERT IGNORE INTO admin_users (username, full_name, email, password_hash, role, status)
VALUES
('SFMS_Admin', 'Super Admin', 'admin@web_system.local', '$2y$10$QJ0xgY7NnWkM7xZV0m0vQO1V2Lr9pQ4dN8lVf4h2M7xY6bZ9d1e7K', 'super_admin', 'active');

INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_by)
VALUES
('system_name', 'Web System', 'Super Admin'),
('rating_scale', '1-5', 'Super Admin'),
('anonymous_feedback_enabled', '0', 'Super Admin'),
('admin_facility_name', 'Calape Primary Care Facility', 'Super Admin'),
('admin_satisfaction_benchmark', '80', 'Super Admin'),
('admin_pending_threshold', '10', 'Super Admin'),
('admin_negative_sentiment_alert_percent', '20', 'Super Admin'),
('admin_auto_resolve_positive_feedback', '0', 'Super Admin');

INSERT IGNORE INTO notifications (type, title, message, reference_id, is_read, created_by)
VALUES
('info', 'System Ready', 'The admin panel database is initialized.', NULL, 0, 'System');

SET FOREIGN_KEY_CHECKS = 1;
