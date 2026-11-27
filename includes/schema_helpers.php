<?php
/**
 * Ensures optional feature tables exist without breaking existing schemas.
 * Uses utf8mb4_unicode_ci for full compatibility with both MySQL 8 and MariaDB (e.g. InfinityFree).
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ua_qms_ensure_mfa_tables($conn) {
    static $checked = false;
    if ($checked || !is_object($conn)) {
        return;
    }
    $checked = true;

    @$conn->query("
        CREATE TABLE IF NOT EXISTS user_mfa (
            user_id INT NOT NULL,
            mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
            preferred_method ENUM('totp','email') NOT NULL DEFAULT 'totp',
            totp_secret VARCHAR(64) DEFAULT NULL,
            totp_verified TINYINT(1) NOT NULL DEFAULT 0,
            backup_codes_json TEXT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_umfa_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    @$conn->query("
        CREATE TABLE IF NOT EXISTS user_trusted_devices (
            device_id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_name VARCHAR(150) NOT NULL,
            user_agent_hash CHAR(64) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            last_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (device_id),
            UNIQUE KEY uq_utd_token (token_hash),
            KEY idx_utd_user (user_id),
            CONSTRAINT fk_utd_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    @$conn->query("
        CREATE TABLE IF NOT EXISTS user_mfa_otps (
            otp_id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (otp_id),
            KEY idx_umo_user (user_id),
            CONSTRAINT fk_umo_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
?>
