-- ======================================
-- Document Repository Module Enhancements
-- ======================================

USE ua_qms;

-- Add repository-specific columns to documents table if they don't exist
ALTER TABLE documents ADD COLUMN IF NOT EXISTS repository_indexed BOOLEAN DEFAULT TRUE;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS archive_date DATETIME NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE;

-- Create repository_access_logs table for audit trail
CREATE TABLE IF NOT EXISTS repository_access_logs (
    access_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    document_id INT NOT NULL,
    access_type ENUM('view', 'download', 'print') DEFAULT 'view',
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_document_id (document_id),
    INDEX idx_accessed_at (accessed_at)
);

-- Create repository_views table to track document popularity
CREATE TABLE IF NOT EXISTS repository_views (
    view_id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    view_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    print_count INT DEFAULT 0,
    last_viewed DATETIME NULL,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE,
    INDEX idx_document_id (document_id)
);

-- Create repository_search_history table for analytics
CREATE TABLE IF NOT EXISTS repository_search_history (
    search_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    search_query VARCHAR(255),
    results_count INT,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_searched_at (searched_at)
);

-- Add indexes for better query performance
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_status_submitted (status, submitted_at);
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_office_doc_type (office_id, doc_type_id);
ALTER TABLE documents ADD INDEX IF NOT EXISTS idx_file_name (file_name);

-- Add indexes for document_types
ALTER TABLE document_types ADD INDEX IF NOT EXISTS idx_doc_code (doc_code);

-- Add indexes for offices
ALTER TABLE offices ADD INDEX IF NOT EXISTS idx_office_code (office_code);
ALTER TABLE offices ADD INDEX IF NOT EXISTS idx_status (status);

-- Create view for repository statistics
CREATE OR REPLACE VIEW repository_stats AS
SELECT 
    COUNT(DISTINCT d.document_id) as total_documents,
    COUNT(DISTINCT d.office_id) as total_offices,
    COUNT(DISTINCT d.doc_type_id) as total_document_types,
    SUM(CASE WHEN d.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as recent_uploads_7days,
    SUM(CASE WHEN d.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_uploads_30days,
    MAX(d.submitted_at) as latest_upload_date
FROM documents d
WHERE d.status = 'Approved' AND d.is_archived = FALSE;

-- Create view for document popularity
CREATE OR REPLACE VIEW document_popularity AS
SELECT 
    d.document_id,
    d.file_name,
    o.office_name,
    dt.doc_code,
    COALESCE(rv.view_count, 0) as view_count,
    COALESCE(rv.download_count, 0) as download_count,
    COALESCE(rv.print_count, 0) as print_count,
    rv.last_viewed,
    (COALESCE(rv.view_count, 0) + COALESCE(rv.download_count, 0) + COALESCE(rv.print_count, 0)) as total_interactions
FROM documents d
LEFT JOIN offices o ON d.office_id = o.office_id
LEFT JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
LEFT JOIN repository_views rv ON d.document_id = rv.document_id
WHERE d.status = 'Approved' AND d.is_archived = FALSE
ORDER BY total_interactions DESC;

-- Create view for office compliance with repository
CREATE OR REPLACE VIEW office_repository_compliance AS
SELECT 
    o.office_id,
    o.office_name,
    o.office_code,
    COUNT(DISTINCT d.document_id) as total_documents,
    COUNT(DISTINCT CASE WHEN d.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN d.document_id END) as recent_documents,
    MAX(d.submitted_at) as last_submission_date
FROM offices o
LEFT JOIN documents d ON o.office_id = d.office_id AND d.status = 'Approved' AND d.is_archived = FALSE
WHERE o.status = 'Active'
GROUP BY o.office_id, o.office_name, o.office_code
ORDER BY total_documents DESC;

-- Insert initial repository statistics
INSERT INTO repository_views (document_id, view_count, download_count, print_count, last_viewed)
SELECT d.document_id, 0, 0, 0, NULL
FROM documents d
WHERE d.status = 'Approved' AND d.document_id NOT IN (SELECT document_id FROM repository_views)
ON DUPLICATE KEY UPDATE view_count = view_count;

-- Add stored procedure for updating document access statistics
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_log_repository_access(
    IN p_user_id INT,
    IN p_document_id INT,
    IN p_access_type VARCHAR(50),
    IN p_ip_address VARCHAR(45)
)
BEGIN
    -- Insert access log
    INSERT INTO repository_access_logs (user_id, document_id, access_type, ip_address)
    VALUES (p_user_id, p_document_id, p_access_type, p_ip_address);
    
    -- Update view statistics
    IF p_access_type = 'view' THEN
        UPDATE repository_views 
        SET view_count = view_count + 1, last_viewed = NOW()
        WHERE document_id = p_document_id;
    ELSEIF p_access_type = 'download' THEN
        UPDATE repository_views 
        SET download_count = download_count + 1, last_viewed = NOW()
        WHERE document_id = p_document_id;
    ELSEIF p_access_type = 'print' THEN
        UPDATE repository_views 
        SET print_count = print_count + 1, last_viewed = NOW()
        WHERE document_id = p_document_id;
    END IF;
END //

DELIMITER ;

-- Add stored procedure for archiving old documents
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS sp_archive_old_documents(
    IN p_days_old INT
)
BEGIN
    UPDATE documents 
    SET is_archived = TRUE, archive_date = NOW()
    WHERE status = 'Approved' 
    AND submitted_at < DATE_SUB(NOW(), INTERVAL p_days_old DAY)
    AND is_archived = FALSE;
    
    SELECT ROW_COUNT() as archived_count;
END //

DELIMITER ;

-- Create trigger to initialize repository_views for new approved documents
DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_init_repository_views
AFTER UPDATE ON documents
FOR EACH ROW
BEGIN
    IF NEW.status = 'Approved' AND OLD.status != 'Approved' THEN
        INSERT INTO repository_views (document_id, view_count, download_count, print_count, last_viewed)
        VALUES (NEW.document_id, 0, 0, 0, NULL)
        ON DUPLICATE KEY UPDATE view_count = view_count;
    END IF;
END //

DELIMITER ;

-- Verify installation
SELECT 'Repository Module Enhancements Installed Successfully' as status;
SELECT COUNT(*) as repository_access_logs_count FROM repository_access_logs;
SELECT COUNT(*) as repository_views_count FROM repository_views;
