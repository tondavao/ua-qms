-- ======================================
-- Notification Click-Through Navigation
-- Adds routing metadata to notifications
-- ======================================

-- Add target_url column to notifications table for deep linking
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_url VARCHAR(500) NULL COMMENT 'Target URL for notification click-through navigation';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS target_type VARCHAR(50) NULL COMMENT 'Type of target: dashboard, submission, review, etc.';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS related_document_id INT NULL COMMENT 'Related document ID for context';
ALTER TABLE notifications ADD FOREIGN KEY (related_document_id) REFERENCES documents(document_id) ON DELETE SET NULL;

-- Create index for faster queries
CREATE INDEX IF NOT EXISTS idx_notification_target ON notifications(target_url);
CREATE INDEX IF NOT EXISTS idx_notification_document ON notifications(related_document_id);
