-- ======================================
-- UA QMS Database: Minimal Users Setup
-- ======================================

-- Create database
CREATE DATABASE IF NOT EXISTS ua_qms;
USE ua_qms;

-- Offices table
CREATE TABLE IF NOT EXISTS offices (
    office_id INT AUTO_INCREMENT PRIMARY KEY,
    office_name VARCHAR(150) NOT NULL,
    office_code VARCHAR(50),
    status ENUM('Active','Inactive') DEFAULT 'Active'
);

-- Users table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('QMO Admin','Dean','Admin','Office','RMO','HMO') NOT NULL,
    office_id INT NULL,
    status ENUM('Active','Inactive','Suspended','Pending') DEFAULT 'Active',
    last_active DATETIME NULL,
    password_reset_token VARCHAR(255) NULL,
    token_expiry DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE SET NULL
);

-- Document types table
CREATE TABLE IF NOT EXISTS document_types (
    doc_type_id INT AUTO_INCREMENT PRIMARY KEY,
    doc_code ENUM('QOP','QMD','MLR','MLF','MLE','MLP','MLW','RISK','CAR') NOT NULL,
    description VARCHAR(255),
    required ENUM('Yes','No') DEFAULT 'Yes',
    frequency ENUM('Quarterly','Optional','Once')
);

-- Documents table
CREATE TABLE IF NOT EXISTS documents (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    file_name VARCHAR(255),
    file_path VARCHAR(255),
    preview_path VARCHAR(255) NULL,
    status ENUM('Awaiting Review','Revision','Approved','Rejected','Not Submitted') DEFAULT 'Awaiting Review',
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE,
    FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id) ON DELETE CASCADE
);

-- Document reviews table
CREATE TABLE IF NOT EXISTS document_reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    reviewed_by ENUM('RMO','HMO') NOT NULL,
    remarks TEXT,
    review_status ENUM('Approved','Revision','Rejected'),
    reviewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
);

-- Compliance tracking table
CREATE TABLE IF NOT EXISTS compliance_tracking (
    compliance_id INT AUTO_INCREMENT PRIMARY KEY,
    office_id INT NOT NULL,
    total_required INT NOT NULL,
    total_submitted INT NOT NULL,
    quarter VARCHAR(20),
    year YEAR,
    FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE
);

-- Master forms table
CREATE TABLE IF NOT EXISTS master_forms (
    form_id INT AUTO_INCREMENT PRIMARY KEY,
    form_name VARCHAR(150),
    version VARCHAR(20),
    effective_date DATE,
    status ENUM('Active','Archived') DEFAULT 'Active'
);

-- Target submissions table
CREATE TABLE IF NOT EXISTS target_submissions (
    target_id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type_id INT NOT NULL,
    quarter VARCHAR(20),
    year YEAR,
    deadline DATE,
    FOREIGN KEY (doc_type_id) REFERENCES document_types(doc_type_id) ON DELETE CASCADE
);

-- ======================================
-- New Tables for Control Tower Features
-- ======================================

-- System Settings table
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- System Logs table (Immutable Audit Trail)
CREATE TABLE IF NOT EXISTS system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Notifications table for "Deadline Blasting"
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('System','Deadline','Announcement') DEFAULT 'System',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User Notifications (to track read status)
CREATE TABLE IF NOT EXISTS user_notifications (
    user_notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    notification_id INT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (notification_id) REFERENCES notifications(notification_id) ON DELETE CASCADE
);

-- Unit Categories
CREATE TABLE IF NOT EXISTS unit_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL
);

-- Update offices table for categorization and archiving
ALTER TABLE offices ADD COLUMN category_id INT NULL;
ALTER TABLE offices ADD COLUMN is_archived BOOLEAN DEFAULT FALSE;
ALTER TABLE offices ADD FOREIGN KEY (category_id) REFERENCES unit_categories(category_id) ON DELETE SET NULL;

-- Activity Subscriptions
CREATE TABLE IF NOT EXISTS activity_subscriptions (
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ======================================
-- Initial Data
-- ======================================

-- Insert sample offices
INSERT INTO offices (office_name, office_code, status) VALUES 
('College of Computing and Information Sciences', 'CCS', 'Active'),
('College of Engineering', 'CE', 'Active');

-- Insert initial users (Linking Dean to College of Computing and Information Sciences - office_id 1)
INSERT INTO users (full_name, email, password, role, office_id, status) VALUES
('QMO Administrator', 'qmo.admin@ua.edu.ph', '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'QMO Admin', NULL, 'Active'),
('Dean of Faculty', 'dean@ua.edu.ph', '$2y$10$yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy', 'Dean', 1, 'Active');

-- Insert sample document types
INSERT INTO document_types (doc_code, description, required, frequency) VALUES
('QOP', 'Quality Operating Procedures', 'Yes', 'Quarterly'),
('QMD', 'Quality Management Documents', 'Yes', 'Quarterly'),
('MLR', 'Master List of Records', 'Yes', 'Quarterly'),
('MLF', 'Master List of Forms', 'Yes', 'Quarterly'),
('MLE', 'Master List of External Documents', 'Yes', 'Quarterly'),
('MLP', 'Master List of Procedures', 'Yes', 'Quarterly'),
('MLW', 'Master List of Work Instructions', 'Yes', 'Quarterly'),
('RISK', 'Risk Register', 'Yes', 'Quarterly'),
('CAR', 'Corrective Action Report', 'No', 'Optional');

-- Insert sample target submissions
INSERT INTO target_submissions (doc_type_id, quarter, year, deadline) VALUES
(1, '1st Quarter', 2025, '2025-03-31'),
(2, 'Annual', 2025, '2025-01-15'),
(3, '1st Quarter', 2025, '2025-03-15');

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('active_academic_year', '2025-2026'),
('active_semester', '1st Semester'),
('maintenance_mode', '0'),
('gemini_api_key', ''),
('gemini_model', 'gemini-1.5-flash'),
('gemini_thinking_budget', '1024'),
('late_submission_threshold', '3'),
('reminder_nudge_7d', '1'),
('reminder_nudge_3d', '1'),
('reminder_nudge_24h', '1'),
('system_status', 'Live');

-- Insert unit categories
INSERT INTO unit_categories (category_name) VALUES ('Academic'), ('Non-Academic');
