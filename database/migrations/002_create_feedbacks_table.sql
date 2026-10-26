-- Migration: Create canonical feedbacks table for the modern feedback forms

CREATE TABLE IF NOT EXISTS feedbacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    source VARCHAR(20) NOT NULL,
    user_type VARCHAR(32) NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 1,
    feedback_type VARCHAR(32) NULL,
    respondent_type VARCHAR(120) NULL,
    full_name VARCHAR(120) NULL,
    email VARCHAR(160) NULL,
    contact_number VARCHAR(40) NULL,
    department_id INT NULL,
    service_id INT NULL,
    department VARCHAR(120) NOT NULL,
    service VARCHAR(120) NOT NULL,
    is_custom_service TINYINT(1) NOT NULL DEFAULT 0,
    visit_date DATE NULL,
    staff_name VARCHAR(120) NULL,
    subject VARCHAR(255) NULL,
    feedback TEXT NOT NULL,
    suggestions TEXT NULL,
    rating DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    ratings_json LONGTEXT NOT NULL,
    sentiment_label VARCHAR(20) NULL,
    sentiment_score FLOAT NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'Normal',
    status ENUM('pending', 'verified', 'resolved', 'adjusted', 'in_review', 'archived') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_feedbacks_source (source),
    INDEX idx_feedbacks_user_type (user_type),
    INDEX idx_feedbacks_department (department),
    INDEX idx_feedbacks_service (service),
    INDEX idx_feedbacks_custom_service (is_custom_service),
    INDEX idx_feedbacks_status (status),
    INDEX idx_feedbacks_created_at (submitted_at),
    INDEX idx_feedbacks_sentiment (sentiment_label),
    CONSTRAINT fk_feedbacks_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_feedbacks_service
        FOREIGN KEY (service_id) REFERENCES services(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;