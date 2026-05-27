<?php
/**
 * Monitoring API
 * Provides real-time data for the QMO Admin Monitoring Dashboard
 */

session_start();
include "../config/db.php";

header('Content-Type: application/json');

// Verify user is authenticated and is QMO Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'QMO Admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'get_monitoring_data') {
    // Get active academic year and active semester from configurations
    $sql_settings = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')";
    $res_settings = $conn->query($sql_settings);
    $active_ay = '2025-2026';
    $active_sem = '1st Semester';
    if ($res_settings) {
        while ($row = $res_settings->fetch_assoc()) {
            if ($row['setting_key'] === 'active_academic_year') $active_ay = $row['setting_value'];
            if ($row['setting_key'] === 'active_semester') $active_sem = $row['setting_value'];
        }
    }

    // 1. Get Stats for the active period
    $sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) as revision,
                    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
                  FROM documents
                  WHERE academic_year = ? AND semester = ?";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->bind_param("ss", $active_ay, $active_sem);
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();

    // 2. Get Overdue Count (Simplified: documents submitted after deadline or not submitted)
    // For this implementation, we'll count documents where the current date is past the deadline in target_submissions
    $sql_overdue = "SELECT COUNT(*) as overdue 
                    FROM target_submissions ts
                    LEFT JOIN documents d ON ts.doc_type_id = d.doc_type_id 
                    WHERE ts.deadline < CURDATE() AND (d.document_id IS NULL OR d.status = 'Not Submitted')";
    $result_overdue = $conn->query($sql_overdue);
    $overdue = $result_overdue->fetch_assoc();
    $stats['overdue'] = $overdue['overdue'];

    // 3. Get Recent Activity for the active period
    $sql_activity = "SELECT 
                        d.document_id,
                        o.office_name as unit,
                        o.office_code,
                        dt.doc_code,
                        dt.description as doc_name,
                        d.submitted_at,
                        d.status
                     FROM documents d
                     JOIN offices o ON d.office_id = o.office_id
                     JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                     WHERE d.academic_year = ? AND d.semester = ?
                     ORDER BY d.submitted_at DESC
                     LIMIT 10";
    $stmt_act = $conn->prepare($sql_activity);
    $stmt_act->bind_param("ss", $active_ay, $active_sem);
    $stmt_act->execute();
    $result_activity = $stmt_act->get_result();
    
    $activity = [];
    while ($row = $result_activity->fetch_assoc()) {
        // Calculate time ago
        $submitted_at = strtotime($row['submitted_at']);
        $diff = time() - $submitted_at;
        
        if ($diff < 60) {
            $time_ago = "Just now";
        } elseif ($diff < 3600) {
            $time_ago = floor($diff / 60) . " mins ago";
        } elseif ($diff < 86400) {
            $time_ago = floor($diff / 3600) . " hours ago";
        } else {
            $time_ago = floor($diff / 86400) . " days ago";
        }
        
        $row['time_ago'] = $time_ago;
        $activity[] = $row;
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'activity' => $activity,
        'timestamp' => date('H:i:s')
    ]);
    exit();
}

// Default response
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
