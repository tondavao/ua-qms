-- =====================================================================
-- UA QMS Phase 1 Essential Fixes
-- Non-destructive migration for current document, dashboard, settings,
-- notification, messaging, comparison, and tracking flows.
-- =====================================================================

USE ua_qms;

-- Dynamic settings used by dashboard/qmo/settings.php
CREATE TABLE IF NOT EXISTS academic_years (
    ay_id INT AUTO_INCREMENT PRIMARY KEY,
    year_range VARCHAR(50) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS semesters (
    sem_id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(50) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO academic_years (year_range, is_active) VALUES
('2024-2025', FALSE),
('2025-2026', TRUE),
('2026-2027', FALSE);

INSERT IGNORE INTO semesters (semester_name, is_active) VALUES
('1st Semester', TRUE),
('2nd Semester', FALSE),
('Summer', FALSE);

-- Make document type codes manageable from Settings.
ALTER TABLE document_types MODIFY COLUMN doc_code VARCHAR(50) NOT NULL;

-- Document preview, versioning, and revision pairing support.
ALTER TABLE documents ADD COLUMN IF NOT EXISTS preview_path VARCHAR(255) NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS previous_document_id INT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS version_number INT DEFAULT 1;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS upload_scope TEXT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS upload_limitations TEXT NULL;

-- Notification click-through routing.
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_url VARCHAR(500) NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_type VARCHAR(50) NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS related_document_id INT NULL;

-- Messenger schema expected by assets/js/messaging.js and api/messages_api.php.
CREATE TABLE IF NOT EXISTS conversations (
    conversation_id INT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversation_participants (
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_message_id INT NULL,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NULL,
    subject VARCHAR(255) DEFAULT 'No Subject',
    message_text TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
);

ALTER TABLE messages ADD COLUMN IF NOT EXISTS conversation_id INT NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS receiver_id INT NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS status ENUM('sent', 'delivered', 'read') DEFAULT 'sent';
ALTER TABLE messages MODIFY COLUMN receiver_id INT NULL;

-- Document comparison and tracking tables used by QMO review/reporting.
CREATE TABLE IF NOT EXISTS document_comparisons (
    comparison_id INT AUTO_INCREMENT PRIMARY KEY,
    previous_document_id INT NOT NULL,
    current_document_id INT NOT NULL,
    comparison_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    admin_notes TEXT,
    FOREIGN KEY (previous_document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    FOREIGN KEY (current_document_id) REFERENCES documents(document_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS target_achievements (
    achievement_id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    quarter VARCHAR(20),
    year YEAR,
    target_count INT DEFAULT 1,
    achieved_count INT DEFAULT 0,
    achievement_date DATETIME NULL,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE,
    FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS officer_compliance (
    compliance_record_id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    user_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    quarter VARCHAR(20),
    year YEAR,
    is_complied BOOLEAN DEFAULT FALSE,
    compliance_date DATETIME NULL,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS document_analytics (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    office_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    submission_date DATETIME,
    review_date DATETIME,
    approval_date DATETIME,
    days_to_review INT,
    days_to_approval INT,
    status_history JSON,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE,
    FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS upload_process_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    user_id INT NOT NULL,
    upload_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    file_size INT,
    file_type VARCHAR(50),
    upload_scope VARCHAR(255),
    upload_limitations TEXT,
    ip_address VARCHAR(45),
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS unit_update_history (
    update_id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    updated_by INT NOT NULL,
    update_type VARCHAR(100),
    previous_data JSON,
    new_data JSON,
    update_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    document_id INT,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE SET NULL
);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('maintenance_mode', '0'),
('gemini_api_key', ''),
('gemini_model', 'gemini-1.5-flash'),
('settings_module_name', 'Dynamic Academic & Office Configuration')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
