-- =========================================================================
-- UA QMS Supabase/PostgreSQL Migration Database Schema and Seed Data
-- =========================================================================

-- Drop tables if they exist to allow clean replay
DROP TABLE IF EXISTS user_notifications CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS system_logs CASCADE;
DROP TABLE IF EXISTS system_settings CASCADE;
DROP TABLE IF EXISTS target_achievements CASCADE;
DROP TABLE IF EXISTS target_submissions CASCADE;
DROP TABLE IF EXISTS document_reviews CASCADE;
DROP TABLE IF EXISTS documents CASCADE;
DROP TABLE IF EXISTS document_types CASCADE;
DROP TABLE IF EXISTS officer_compliance CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS offices CASCADE;
DROP TABLE IF EXISTS unit_categories CASCADE;
DROP TABLE IF EXISTS academic_years CASCADE;
DROP TABLE IF EXISTS semesters CASCADE;
DROP TABLE IF EXISTS messages CASCADE;

-- 1. Unit Categories table
CREATE TABLE unit_categories (
    category_id SERIAL PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL
);

-- 2. Offices table
CREATE TABLE offices (
    office_id SERIAL PRIMARY KEY,
    office_name VARCHAR(150) NOT NULL,
    office_code VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
    category_id INTEGER REFERENCES unit_categories(category_id) ON DELETE SET NULL,
    is_archived BOOLEAN DEFAULT FALSE
);

-- 3. Users table
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('QMO Admin', 'Dean', 'Admin', 'Office', 'RMO', 'HMO')),
    office_id INTEGER REFERENCES offices(office_id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive', 'Suspended', 'Pending')),
    last_active TIMESTAMP DEFAULT NULL,
    password_reset_token VARCHAR(255) DEFAULT NULL,
    token_expiry TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Document Types table
CREATE TABLE document_types (
    doc_type_id SERIAL PRIMARY KEY,
    doc_code VARCHAR(20) NOT NULL CHECK (doc_code IN ('QOP','QMD','MLR','MLF','MLE','MLP','MLW','RISK','CAR')),
    description VARCHAR(255) DEFAULT NULL,
    required VARCHAR(10) DEFAULT 'Yes' CHECK (required IN ('Yes', 'No')),
    frequency VARCHAR(20) DEFAULT 'Quarterly' CHECK (frequency IN ('Quarterly', 'Optional', 'Once'))
);

-- 5. Documents table
CREATE TABLE documents (
    document_id SERIAL PRIMARY KEY,
    office_id INTEGER NOT NULL REFERENCES offices(office_id) ON DELETE CASCADE,
    doc_type_id INTEGER NOT NULL REFERENCES document_types(doc_type_id) ON DELETE CASCADE,
    file_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    preview_path VARCHAR(255) DEFAULT NULL,
    status VARCHAR(255) DEFAULT 'Awaiting Review' CHECK (status IN ('Awaiting Review', 'Revision', 'Approved', 'Rejected', 'Not Submitted')),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    version_number INTEGER DEFAULT 1,
    academic_year VARCHAR(20) DEFAULT NULL,
    semester VARCHAR(50) DEFAULT NULL
);

-- 6. Document Reviews table
CREATE TABLE document_reviews (
    review_id SERIAL PRIMARY KEY,
    document_id INTEGER NOT NULL REFERENCES documents(document_id) ON DELETE CASCADE,
    reviewed_by VARCHAR(50) NOT NULL CHECK (reviewed_by IN ('RMO', 'HMO', 'QMO Admin')),
    remarks TEXT DEFAULT NULL,
    review_status VARCHAR(20) CHECK (review_status IN ('Approved', 'Revision', 'Rejected')),
    reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Target Submissions table
CREATE TABLE target_submissions (
    target_id SERIAL PRIMARY KEY,
    doc_type_id INTEGER NOT NULL REFERENCES document_types(doc_type_id) ON DELETE CASCADE,
    quarter VARCHAR(20) DEFAULT NULL,
    year INTEGER DEFAULT NULL, -- Postgres uses INTEGER instead of YEAR type
    deadline DATE DEFAULT NULL
);

-- 8. Target Achievements table
CREATE TABLE target_achievements (
    achievement_id SERIAL PRIMARY KEY,
    office_id INTEGER NOT NULL REFERENCES offices(office_id) ON DELETE CASCADE,
    doc_type_id INTEGER NOT NULL REFERENCES document_types(doc_type_id) ON DELETE CASCADE,
    quarter VARCHAR(20) DEFAULT NULL,
    year INTEGER DEFAULT NULL,
    target_count INTEGER DEFAULT 1,
    achieved_count INTEGER DEFAULT 0,
    achievement_date TIMESTAMP DEFAULT NULL
);

-- 9. Officer Compliance table
CREATE TABLE officer_compliance (
    compliance_record_id SERIAL PRIMARY KEY,
    office_id INTEGER NOT NULL REFERENCES offices(office_id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    doc_type_id INTEGER NOT NULL REFERENCES document_types(doc_type_id) ON DELETE CASCADE,
    quarter VARCHAR(20) DEFAULT NULL,
    year INTEGER DEFAULT NULL,
    is_complied BOOLEAN DEFAULT FALSE,
    compliance_date TIMESTAMP DEFAULT NULL
);

-- 10. System Settings table
CREATE TABLE system_settings (
    setting_id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 11. System Logs table
CREATE TABLE system_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 12. Notifications table
CREATE TABLE notifications (
    notification_id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(20) DEFAULT 'System' CHECK (type IN ('System', 'Deadline', 'Announcement')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    target_url VARCHAR(500) DEFAULT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    related_document_id INTEGER REFERENCES documents(document_id) ON DELETE SET NULL
);

-- 13. User Notifications table
CREATE TABLE user_notifications (
    user_notification_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    notification_id INTEGER NOT NULL REFERENCES notifications(notification_id) ON DELETE CASCADE,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. Academic Years table
CREATE TABLE academic_years (
    ay_id SERIAL PRIMARY KEY,
    year_range VARCHAR(50) UNIQUE NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 15. Semesters table
CREATE TABLE semesters (
    sem_id SERIAL PRIMARY KEY,
    semester_name VARCHAR(50) UNIQUE NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 16. Messages table
CREATE TABLE messages (
    message_id SERIAL PRIMARY KEY,
    sender_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    recipient_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    message_text TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read BOOLEAN DEFAULT FALSE,
    attachment_path VARCHAR(255) DEFAULT NULL
);

-- =========================================================================
-- Seed Data Configuration
-- =========================================================================

-- Seed Categories
INSERT INTO unit_categories (category_name) VALUES ('Academic'), ('Non-Academic');

-- Seed Default Offices (Linked to Categories: Academic = 1, Non-Academic = 2)
INSERT INTO offices (office_name, office_code, status, category_id) VALUES 
('College of Computing and Information Sciences', 'CCIS', 'Active', 1),
('College of Engineering', 'CE', 'Active', 1),
('Administration and Finance Office', 'ADM', 'Active', 2),
('Alumni Unit', 'ALU', 'Active', 2),
('Admission/Registrarship Unit', 'ARU', 'Active', 2),
('Accounting Unit', 'AU', 'Active', 2);

-- Seed Default Users
-- Default passwords:
-- QMO Admin: '$2y$10$tZ2yD0MhDlhg4Jt13Z95/up4b17vR6wJ3L3v91Vw468K9t1Z7M2bK' (admin123)
-- CCS Dean: '$2y$10$tZ2yD0MhDlhg4Jt13Z95/up4b17vR6wJ3L3v91Vw468K9t1Z7M2bK' (admin123)
INSERT INTO users (full_name, email, password, role, office_id, status) VALUES
('QMO Administrator', 'qmo.admin@antiquespride.edu.ph', '$2y$10$tZ2yD0MhDlhg4Jt13Z95/up4b17vR6wJ3L3v91Vw468K9t1Z7M2bK', 'QMO Admin', NULL, 'Active'),
('MAY LYNN M. FARREN', 'ccs@antiquespride.edu.ph', '$2y$10$tZ2yD0MhDlhg4Jt13Z95/up4b17vR6wJ3L3v91Vw468K9t1Z7M2bK', 'Dean', 1, 'Active');

-- Seed Default Document Types
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

-- Seed Academic Years
INSERT INTO academic_years (year_range, is_active) VALUES 
('2024-2025', FALSE),
('2025-2026', TRUE),
('2026-2027', FALSE);

-- Seed Semesters / Quarters
INSERT INTO semesters (semester_name, is_active) VALUES 
('1st Quarter', TRUE),
('2nd Quarter', FALSE),
('3rd Quarter', FALSE),
('4th Quarter', FALSE);

-- Seed System Settings
INSERT INTO system_settings (setting_key, setting_value) VALUES
('active_academic_year', '2025-2026'),
('active_semester', '1st Quarter'),
('maintenance_mode', '0'),
('gemini_api_key', ''),
('gemini_model', 'gemini-1.5-flash'),
('gemini_thinking_budget', '1024'),
('late_submission_threshold', '3'),
('reminder_nudge_7d', '1'),
('reminder_nudge_3d', '1'),
('reminder_nudge_24h', '1'),
('system_status', 'Live');
