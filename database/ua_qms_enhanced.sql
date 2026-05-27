-- ======================================
-- UA QMS Enhanced Database Schema
-- Includes new features for document management
-- ======================================

-- Add new columns to documents table for document management
ALTER TABLE documents ADD COLUMN IF NOT EXISTS previous_document_id INT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS version_number INT DEFAULT 1;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS upload_scope TEXT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS upload_limitations TEXT NULL;
ALTER TABLE documents ADD FOREIGN KEY (previous_document_id) REFERENCES documents(document_id) ON DELETE SET NULL;


-- Create document comparison table for admin to view paired documents
CREATE TABLE IF NOT EXISTS document_comparisons (
    comparison_id INT AUTO_INCREMENT PRIMARY KEY,
    previous_document_id INT NOT NULL,
    current_document_id INT NOT NULL,
    comparison_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    admin_notes TEXT,
    FOREIGN KEY (previous_document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    FOREIGN KEY (current_document_id) REFERENCES documents(document_id) ON DELETE CASCADE
);

-- Create target achievement tracking table
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

-- Create officer compliance tracking table
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

-- Create document analytics table
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

-- Create upload process log table
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

-- Create unit update history table
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

-- Create system configuration table for analytics settings
CREATE TABLE IF NOT EXISTS analytics_configuration (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default analytics configurations
INSERT INTO analytics_configuration (config_key, config_value, description) VALUES
('content_determination_method', 'system_analytics', 'How content is determined: system_analytics, manual, hybrid'),
('analytics_enabled', '1', 'Enable/disable analytics tracking'),
('track_submission_time', '1', 'Track submission timestamps'),
('track_review_time', '1', 'Track review duration'),
('auto_generate_reports', '1', 'Automatically generate compliance reports'),
('compliance_threshold', '80', 'Minimum compliance percentage for green status');
