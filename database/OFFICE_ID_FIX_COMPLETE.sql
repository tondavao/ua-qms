-- =====================================================================
-- COMPLETE FIX SCRIPT: Resolve "Office ID not found in session" Error
-- =====================================================================
-- This comprehensive script ensures proper office setup and Dean assignment
-- =====================================================================

USE ua_qms;

-- 1. Ensure offices table has proper structure
ALTER TABLE offices MODIFY COLUMN office_id INT AUTO_INCREMENT PRIMARY KEY;
ALTER TABLE offices MODIFY COLUMN office_name VARCHAR(150) NOT NULL;
ALTER TABLE offices MODIFY COLUMN status ENUM('Active','Inactive') DEFAULT 'Active';

-- 2. Create default offices if they don't exist
INSERT IGNORE INTO offices (office_id, office_name, office_code, status) VALUES 
(1, 'College of Computing and Information Sciences', 'CCS', 'Active'),
(2, 'College of Engineering', 'CE', 'Active'),
(3, 'Quality Management Office', 'QMO', 'Active'),
(4, 'Default Academic Unit', 'DAU', 'Active');

-- 3. Ensure users table has proper foreign key constraints
-- Drop existing foreign key if it exists
ALTER TABLE users DROP FOREIGN KEY IF EXISTS users_ibfk_1;

-- Add the foreign key back
ALTER TABLE users ADD CONSTRAINT users_ibfk_1 
FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE SET NULL;

-- 4. Update any Dean users without office assignment
UPDATE users 
SET office_id = 1 
WHERE role = 'Dean' AND office_id IS NULL 
LIMIT 1;

-- 5. Ensure at least one Dean has an office
INSERT IGNORE INTO users (user_id, full_name, email, password, role, office_id, status) VALUES
(1, 'Dean of Faculty', 'dean@ua.edu.ph', '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'Dean', 1, 'Active');

-- 6. Ensure QMO Admin exists
INSERT IGNORE INTO users (user_id, full_name, email, password, role, office_id, status) VALUES
(2, 'QMO Administrator', 'qmo.admin@ua.edu.ph', '$2y$10$yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy', 'QMO Admin', NULL, 'Active');

-- 7. Verify document types exist
INSERT IGNORE INTO document_types (doc_code, description, required, frequency) VALUES
('QOP', 'Quality Operating Procedures', 'Yes', 'Quarterly'),
('QMD', 'Quality Management Documents', 'Yes', 'Quarterly'),
('MLR', 'Master List of Records', 'Yes', 'Quarterly'),
('MLF', 'Master List of Forms', 'Yes', 'Quarterly'),
('MLE', 'Master List of External Documents', 'Yes', 'Quarterly'),
('MLP', 'Master List of Procedures', 'Yes', 'Quarterly'),
('MLW', 'Master List of Work Instructions', 'Yes', 'Quarterly'),
('RISK', 'Risk Register', 'Yes', 'Quarterly'),
('CAR', 'Corrective Action Report', 'No', 'Optional');

-- 8. Verify target submissions exist
INSERT IGNORE INTO target_submissions (doc_type_id, quarter, year, deadline) VALUES
(1, '1st Quarter', 2025, '2025-03-31'),
(2, 'Annual', 2025, '2025-01-15'),
(3, '1st Quarter', 2025, '2025-03-15');

-- 9. Verify unit categories exist
INSERT IGNORE INTO unit_categories (category_name) VALUES 
('Academic'), 
('Non-Academic');

-- 10. Update offices with categories if not set
UPDATE offices 
SET category_id = 1 
WHERE category_id IS NULL AND office_name LIKE '%College%';

UPDATE offices 
SET category_id = 2 
WHERE category_id IS NULL AND (office_name LIKE '%Office%' OR office_name LIKE '%QMO%');

-- 11. Verify system settings exist
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('active_academic_year', '2025-2026'),
('active_semester', '1st Semester'),
('maintenance_mode', '0'),
('late_submission_threshold', '3'),
('system_status', 'Live');

-- 12. Verification queries to confirm fix
SELECT 'Office Setup Verification' as Check_Type;
SELECT COUNT(*) as Total_Offices FROM offices WHERE status = 'Active';
SELECT COUNT(*) as Dean_Users_With_Office FROM users WHERE role = 'Dean' AND office_id IS NOT NULL;
SELECT u.user_id, u.full_name, u.email, u.role, o.office_name 
FROM users u 
LEFT JOIN offices o ON u.office_id = o.office_id 
WHERE u.role = 'Dean';

-- 13. Create a helper view for debugging
CREATE OR REPLACE VIEW user_office_assignment AS
SELECT 
    u.user_id,
    u.full_name,
    u.email,
    u.role,
    u.office_id,
    o.office_name,
    o.office_code,
    o.status as office_status,
    CASE 
        WHEN u.role = 'Dean' AND u.office_id IS NULL THEN 'ERROR: Dean without office'
        WHEN u.office_id IS NOT NULL AND o.office_id IS NULL THEN 'ERROR: Office not found'
        WHEN u.office_id IS NOT NULL AND o.status = 'Inactive' THEN 'WARNING: Office inactive'
        ELSE 'OK'
    END as assignment_status
FROM users u
LEFT JOIN offices o ON u.office_id = o.office_id
ORDER BY u.role, u.full_name;

-- 14. Final verification
SELECT 'Final Verification' as Status;
SELECT COUNT(*) as Users_Without_Office FROM users WHERE office_id IS NULL AND role IN ('Dean', 'Office');
SELECT COUNT(*) as Orphaned_Documents FROM documents WHERE office_id NOT IN (SELECT office_id FROM offices);
