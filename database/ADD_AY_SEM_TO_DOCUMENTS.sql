-- Migration to add Academic Year and Semester columns to documents table
USE ua_qms;

ALTER TABLE documents 
ADD COLUMN academic_year VARCHAR(50) NULL,
ADD COLUMN semester VARCHAR(50) NULL;

-- Initialize existing documents with the current configured academic year and semester
UPDATE documents 
SET academic_year = (SELECT setting_value FROM system_settings WHERE setting_key = 'active_academic_year' LIMIT 1),
    semester = (SELECT setting_value FROM system_settings WHERE setting_key = 'active_semester' LIMIT 1);
