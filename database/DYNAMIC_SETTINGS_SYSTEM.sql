-- ======================================
-- Dynamic Settings Management System
-- ======================================

-- 1. Academic Years Management
CREATE TABLE IF NOT EXISTS academic_years (
    ay_id INT AUTO_INCREMENT PRIMARY KEY,
    year_range VARCHAR(50) NOT NULL UNIQUE, -- e.g., "2024-2025"
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Semesters Management
CREATE TABLE IF NOT EXISTS semesters (
    sem_id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(50) NOT NULL UNIQUE, -- e.g., "1st Semester", "2nd Semester", "Summer"
    is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed initial data for Academic Years from current settings
INSERT IGNORE INTO academic_years (year_range, is_active) VALUES 
('2024-2025', FALSE),
('2025-2026', TRUE),
('2026-2027', FALSE);

-- Seed initial data for Semesters from current settings
INSERT IGNORE INTO semesters (semester_name, is_active) VALUES 
('1st Semester', TRUE),
('2nd Semester', FALSE),
('Summer', FALSE);

-- Ensure document_types is fully manageable (it already is, but we'll add a status if not present)
-- ALTER TABLE document_types ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive') DEFAULT 'Active';
-- Note: doc_code is currently an ENUM in the schema. This might be restrictive.
-- Let's change doc_code to VARCHAR to make it truly dynamic.
ALTER TABLE document_types MODIFY COLUMN doc_code VARCHAR(50) NOT NULL;

-- Ensure offices table has everything needed
-- (Already has office_name, office_code, status, category_id)

-- Add a setting to track the module version or name
INSERT INTO system_settings (setting_key, setting_value) 
VALUES ('settings_module_name', 'Dynamic Academic & Office Configuration')
ON DUPLICATE KEY UPDATE setting_value = 'Dynamic Academic & Office Configuration';
