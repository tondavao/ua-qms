<?php
/**
 * Ensures optional feature tables exist without breaking existing schemas.
 */

function ua_qms_ensure_target_office_table($conn) {
    static $checked = false;
    if ($checked || !is_object($conn)) {
        return;
    }
    $checked = true;
    @$conn->query("
        CREATE TABLE IF NOT EXISTS target_submission_offices (
            id INT NOT NULL AUTO_INCREMENT,
            target_id INT NOT NULL,
            office_id INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_target_office (target_id, office_id),
            KEY idx_tso_office (office_id),
            CONSTRAINT fk_tso_target FOREIGN KEY (target_id) REFERENCES target_submissions(target_id) ON DELETE CASCADE,
            CONSTRAINT fk_tso_office FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
}

function ua_qms_ensure_document_annotations_table($conn) {
    static $checked = false;
    if ($checked || !is_object($conn)) {
        return;
    }
    $checked = true;
    @$conn->query("
        CREATE TABLE IF NOT EXISTS document_annotations (
            annotation_id INT NOT NULL AUTO_INCREMENT,
            document_id INT NOT NULL,
            edited_html LONGTEXT DEFAULT NULL,
            annotations_json LONGTEXT DEFAULT NULL,
            editor_mode VARCHAR(30) DEFAULT 'rich_text',
            updated_by INT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (annotation_id),
            UNIQUE KEY uq_doc_annotation (document_id),
            CONSTRAINT fk_da_document FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
}
?>
