<?php

function ua_qms_table_exists($conn, $table_name) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $stmt->bind_param("s", $table_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int)($row['count'] ?? 0)) > 0;
}

function ua_qms_column_exists($conn, $table_name, $column_name) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table_name, $column_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int)($row['count'] ?? 0)) > 0;
}

function ua_qms_quarter_label($date_string = null) {
    $timestamp = $date_string ? strtotime($date_string) : time();
    $month = (int)date('n', $timestamp);

    if ($month <= 3) return '1st Quarter';
    if ($month <= 6) return '2nd Quarter';
    if ($month <= 9) return '3rd Quarter';
    return '4th Quarter';
}

function ua_qms_quarter_month_bounds($quarter) {
    switch ($quarter) {
        case '1st Quarter': return [1, 3];
        case '2nd Quarter': return [4, 6];
        case '3rd Quarter': return [7, 9];
        case '4th Quarter': return [10, 12];
        default: return [1, 12];
    }
}

function ua_qms_create_document_comparison($conn, $previous_document_id, $current_document_id, $admin_notes = '') {
    if (!$previous_document_id || !$current_document_id || !ua_qms_table_exists($conn, 'document_comparisons')) {
        return false;
    }

    $stmt_check = $conn->prepare("
        SELECT comparison_id
        FROM document_comparisons
        WHERE previous_document_id = ?
        AND current_document_id = ?
        LIMIT 1
    ");
    $stmt_check->bind_param("ii", $previous_document_id, $current_document_id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        return true;
    }

    $stmt = $conn->prepare("
        INSERT INTO document_comparisons (previous_document_id, current_document_id, admin_notes, comparison_date)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $previous_document_id, $current_document_id, $admin_notes);
    return $stmt->execute();
}

function ua_qms_track_document_analytics($conn, $document_id, $event_type) {
    if (!ua_qms_table_exists($conn, 'document_analytics')) {
        return false;
    }

    $stmt_doc = $conn->prepare("
        SELECT document_id, office_id, doc_type_id, submitted_at, status
        FROM documents
        WHERE document_id = ?
    ");
    $stmt_doc->bind_param("i", $document_id);
    $stmt_doc->execute();
    $doc = $stmt_doc->get_result()->fetch_assoc();
    if (!$doc) {
        return false;
    }

    $stmt_check = $conn->prepare("SELECT analytics_id FROM document_analytics WHERE document_id = ? LIMIT 1");
    $stmt_check->bind_param("i", $document_id);
    $stmt_check->execute();
    $exists = $stmt_check->get_result()->num_rows > 0;

    if (!$exists) {
        $history = json_encode([
            ['status' => $doc['status'], 'timestamp' => date('Y-m-d H:i:s')]
        ]);
        $doc_id = (int)$doc['document_id'];
        $office_id = (int)$doc['office_id'];
        $doc_type_id = (int)$doc['doc_type_id'];
        $submission_date = $doc['submitted_at'];
        $stmt_insert = $conn->prepare("
            INSERT INTO document_analytics (document_id, office_id, doc_type_id, submission_date, status_history)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt_insert->bind_param("iiiss", $doc_id, $office_id, $doc_type_id, $submission_date, $history);
        $stmt_insert->execute();
    }

    if ($event_type === 'review' || $event_type === 'approval') {
        $stmt_update = $conn->prepare("
            UPDATE document_analytics
            SET review_date = COALESCE(review_date, NOW()),
                approval_date = IF(? = 'approval', COALESCE(approval_date, NOW()), approval_date),
                days_to_review = IF(review_date IS NOT NULL, DATEDIFF(review_date, submission_date), days_to_review),
                days_to_approval = IF(approval_date IS NOT NULL, DATEDIFF(approval_date, submission_date), days_to_approval)
            WHERE document_id = ?
        ");
        $stmt_update->bind_param("si", $event_type, $document_id);
        return $stmt_update->execute();
    }

    return true;
}

function ua_qms_sync_compliance_tracking($conn, $office_id, $year, $quarter) {
    if (!ua_qms_table_exists($conn, 'compliance_tracking')) {
        return false;
    }

    $required_res = $conn->query("SELECT COUNT(*) AS total_required FROM document_types WHERE required = 'Yes'");
    $total_required = (int)($required_res->fetch_assoc()['total_required'] ?? 0);
    [$start_month, $end_month] = ua_qms_quarter_month_bounds($quarter);

    $stmt_submitted = $conn->prepare("
        SELECT COUNT(DISTINCT doc_type_id) AS total_submitted
        FROM documents
        WHERE office_id = ?
        AND status = 'Approved'
        AND YEAR(submitted_at) = ?
        AND MONTH(submitted_at) BETWEEN ? AND ?
    ");
    $stmt_submitted->bind_param("isii", $office_id, $year, $start_month, $end_month);
    $stmt_submitted->execute();
    $total_submitted = (int)($stmt_submitted->get_result()->fetch_assoc()['total_submitted'] ?? 0);

    $stmt_check = $conn->prepare("
        SELECT compliance_id
        FROM compliance_tracking
        WHERE office_id = ?
        AND quarter = ?
        AND year = ?
        LIMIT 1
    ");
    $stmt_check->bind_param("iss", $office_id, $quarter, $year);
    $stmt_check->execute();
    $row = $stmt_check->get_result()->fetch_assoc();

    if ($row) {
        $compliance_id = (int)$row['compliance_id'];
        $stmt_update = $conn->prepare("
            UPDATE compliance_tracking
            SET total_required = ?, total_submitted = ?
            WHERE compliance_id = ?
        ");
        $stmt_update->bind_param("iii", $total_required, $total_submitted, $compliance_id);
        return $stmt_update->execute();
    }

    $stmt_insert = $conn->prepare("
        INSERT INTO compliance_tracking (office_id, total_required, total_submitted, quarter, year)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt_insert->bind_param("iiiss", $office_id, $total_required, $total_submitted, $quarter, $year);
    return $stmt_insert->execute();
}

function ua_qms_update_review_tracking($conn, $document_id, $status) {
    $stmt_doc = $conn->prepare("
        SELECT document_id, office_id, doc_type_id, submitted_at
        FROM documents
        WHERE document_id = ?
    ");
    $stmt_doc->bind_param("i", $document_id);
    $stmt_doc->execute();
    $doc = $stmt_doc->get_result()->fetch_assoc();
    if (!$doc) {
        return false;
    }

    $year = date('Y', strtotime($doc['submitted_at'] ?? 'now'));
    $quarter = ua_qms_quarter_label($doc['submitted_at'] ?? null);

    ua_qms_track_document_analytics($conn, $document_id, $status === 'Approved' ? 'approval' : 'review');

    if ($status === 'Approved' && ua_qms_table_exists($conn, 'target_achievements')) {
        $office_id = (int)$doc['office_id'];
        $doc_type_id = (int)$doc['doc_type_id'];
        [$start_month, $end_month] = ua_qms_quarter_month_bounds($quarter);
        $stmt_count = $conn->prepare("
            SELECT COUNT(DISTINCT document_id) AS achieved_count
            FROM documents
            WHERE office_id = ?
            AND doc_type_id = ?
            AND status = 'Approved'
            AND YEAR(submitted_at) = ?
            AND MONTH(submitted_at) BETWEEN ? AND ?
        ");
        $stmt_count->bind_param("iisii", $office_id, $doc_type_id, $year, $start_month, $end_month);
        $stmt_count->execute();
        $achieved_count = (int)($stmt_count->get_result()->fetch_assoc()['achieved_count'] ?? 0);

        $stmt_check = $conn->prepare("
            SELECT achievement_id
            FROM target_achievements
            WHERE office_id = ?
            AND doc_type_id = ?
            AND quarter = ?
            AND year = ?
            LIMIT 1
        ");
        $stmt_check->bind_param("iiss", $office_id, $doc_type_id, $quarter, $year);
        $stmt_check->execute();
        $achievement = $stmt_check->get_result()->fetch_assoc();

        if ($achievement) {
            $achievement_id = (int)$achievement['achievement_id'];
            $stmt_update = $conn->prepare("
                UPDATE target_achievements
                SET achieved_count = ?, achievement_date = NOW()
                WHERE achievement_id = ?
            ");
            $stmt_update->bind_param("ii", $achieved_count, $achievement_id);
            $stmt_update->execute();
        } else {
            $target_count = 1;
            $stmt_insert = $conn->prepare("
                INSERT INTO target_achievements (office_id, doc_type_id, quarter, year, target_count, achieved_count, achievement_date)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt_insert->bind_param("iissii", $office_id, $doc_type_id, $quarter, $year, $target_count, $achieved_count);
            $stmt_insert->execute();
        }
    }

    if ($status === 'Approved' && ua_qms_table_exists($conn, 'officer_compliance')) {
        $office_id = (int)$doc['office_id'];
        $doc_type_id = (int)$doc['doc_type_id'];
        $stmt_users = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE office_id = ?
            AND role IN ('Dean', 'Office')
            AND status = 'Active'
        ");
        $stmt_users->bind_param("i", $office_id);
        $stmt_users->execute();
        $users = $stmt_users->get_result();

        while ($user = $users->fetch_assoc()) {
            $user_id = (int)$user['user_id'];
            $stmt_check = $conn->prepare("
                SELECT compliance_record_id
                FROM officer_compliance
                WHERE user_id = ?
                AND office_id = ?
                AND doc_type_id = ?
                AND quarter = ?
                AND year = ?
                LIMIT 1
            ");
            $stmt_check->bind_param("iiiss", $user_id, $office_id, $doc_type_id, $quarter, $year);
            $stmt_check->execute();
            $compliance = $stmt_check->get_result()->fetch_assoc();

            if ($compliance) {
                $compliance_record_id = (int)$compliance['compliance_record_id'];
                $stmt_update = $conn->prepare("
                    UPDATE officer_compliance
                    SET is_complied = 1, compliance_date = NOW()
                    WHERE compliance_record_id = ?
                ");
                $stmt_update->bind_param("i", $compliance_record_id);
                $stmt_update->execute();
            } else {
                $stmt_insert = $conn->prepare("
                    INSERT INTO officer_compliance (user_id, office_id, doc_type_id, quarter, year, is_complied, compliance_date)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ");
                $stmt_insert->bind_param("iiiss", $user_id, $office_id, $doc_type_id, $quarter, $year);
                $stmt_insert->execute();
            }
        }
    }

    if ($status === 'Approved') {
        ua_qms_sync_compliance_tracking($conn, (int)$doc['office_id'], $year, $quarter);
    }

    return true;
}

?>
