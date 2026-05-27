-- =====================================================================
-- FIX SCRIPT: Resolve "Office ID not found in session" Error
-- =====================================================================
-- This script ensures that at least one office exists and that the 
-- Dean account is correctly linked to it.
-- =====================================================================

USE ua_qms;

-- 1. Ensure at least one office exists
INSERT IGNORE INTO offices (office_id, office_name, office_code, status) 
VALUES (1, 'Default Academic Unit', 'DAU', 'Active');

-- 2. Link the Dean user to the office (adjust email if necessary)
UPDATE users 
SET office_id = 1 
WHERE email = 'dean@ua.edu.ph' AND role = 'Dean';

-- 3. Verify the link
SELECT u.full_name, u.email, o.office_name 
FROM users u 
JOIN offices o ON u.office_id = o.office_id 
WHERE u.role = 'Dean';
