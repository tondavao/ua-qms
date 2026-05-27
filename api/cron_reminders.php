<?php
/**
 * Automated Reminders & Deadline Scanner Engine
 * Scans upcoming deadlines, checks compliance, and sends notifications
 */

// If not running in CLI, start session
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

// Check authorization if not running in CLI and not a silent run
$is_cli = (php_sapi_name() === 'cli');
$is_force = isset($_GET['force']) || isset($_POST['force']);
$is_silent = isset($_GET['silent']) || isset($_POST['silent']);

if (!$is_cli && $is_force) {
    // Only QMO Admin can force run
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
        echo json_encode(["success" => false, "message" => "Unauthorized access"]);
        exit;
    }
}

// Check cooldown to prevent excessive execution (except when forced)
if (!$is_force) {
    $sql_cooldown = "SELECT setting_value FROM system_settings WHERE setting_key = 'last_reminder_run'";
    $res_cooldown = $conn->query($sql_cooldown);
    $last_run = 0;
    if ($res_cooldown && $row = $res_cooldown->fetch_assoc()) {
        $last_run = (int)$row['setting_value'];
    }
    
    // 1 hour cooldown (3600 seconds)
    if (time() - $last_run < 3600) {
        if ($is_silent) {
            echo json_encode(["success" => true, "message" => "Skipped (cooldown active)"]);
            exit;
        }
    }
}

// Update last run timestamp
$current_time = time();
$sql_update_last_run = "INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('last_reminder_run', ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?";
$stmt_ulr = $conn->prepare($sql_update_last_run);
$stmt_ulr->bind_param("ss", $current_time, $current_time);
$stmt_ulr->execute();

// 1. Fetch active configurations
$sql_settings = "SELECT setting_key, setting_value FROM system_settings 
                 WHERE setting_key IN ('active_academic_year', 'active_semester', 'reminder_nudge_7d', 'reminder_nudge_3d', 'reminder_nudge_24h')";
$res_settings = $conn->query($sql_settings);
$active_ay = '2025-2026';
$active_sem = '1st Semester';
$nudge_7d = '1';
$nudge_3d = '1';
$nudge_24h = '1';

if ($res_settings) {
    while ($row = $res_settings->fetch_assoc()) {
        switch ($row['setting_key']) {
            case 'active_academic_year': $active_ay = $row['setting_value']; break;
            case 'active_semester': $active_sem = $row['setting_value']; break;
            case 'reminder_nudge_7d': $nudge_7d = $row['setting_value']; break;
            case 'reminder_nudge_3d': $nudge_3d = $row['setting_value']; break;
            case 'reminder_nudge_24h': $nudge_24h = $row['setting_value']; break;
        }
    }
}

// Determine calendar target year
$year_parts = explode('-', $active_ay);
$start_year = (int)$year_parts[0];
$end_year = isset($year_parts[1]) ? (int)$year_parts[1] : $start_year + 1;
$target_year = ($active_sem === '1st Semester') ? $start_year : $end_year;

// 2. Fetch all upcoming target submissions for this calendar year
$sql_targets = "SELECT ts.target_id, ts.doc_type_id, ts.deadline, ts.quarter, ts.year, dt.doc_code, dt.description 
                FROM target_submissions ts
                JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id
                WHERE ts.year = ? AND ts.deadline >= CURDATE()";
$stmt_targets = $conn->prepare($sql_targets);
$stmt_targets->bind_param("i", $target_year);
$stmt_targets->execute();
$targets_res = $stmt_targets->get_result();

$reminders_sent = 0;
$processed_targets = 0;
$logs = [];

// Fetch active offices
$offices_res = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'Active' AND is_archived = FALSE");
$offices = [];
while ($row = $offices_res->fetch_assoc()) {
    $offices[] = $row;
}

while ($target = $targets_res->fetch_assoc()) {
    $processed_targets++;
    $target_id = $target['target_id'];
    $doc_type_id = $target['doc_type_id'];
    $doc_code = $target['doc_code'];
    $deadline = $target['deadline'];
    
    // Calculate days remaining
    $deadline_time = strtotime($deadline);
    $today_time = strtotime(date('Y-m-d'));
    $days_left = round(($deadline_time - $today_time) / 86400);
    
    // Determine nudge label
    $nudge_label = '';
    $nudge_days_text = '';
    
    if ($days_left == 7 && $nudge_7d === '1') {
        $nudge_label = '7d';
        $nudge_days_text = '7 days';
    } elseif ($days_left == 3 && $nudge_3d === '1') {
        $nudge_label = '3d';
        $nudge_days_text = '3 days';
    } elseif ($days_left == 1 && $nudge_24h === '1') {
        $nudge_label = '24h';
        $nudge_days_text = '24 hours';
    } elseif ($days_left == 0 && $nudge_24h === '1') {
        $nudge_label = 'today';
        $nudge_days_text = 'today';
    }
    
    // If no matching nudge is active for this remaining time, skip
    if (empty($nudge_label)) {
        continue;
    }
    
    // Check compliance of each office
    foreach ($offices as $office) {
        $office_id = $office['office_id'];
        
        // Query if document of this type has been submitted by this office for active period
        $sql_check = "SELECT status FROM documents 
                      WHERE office_id = ? AND doc_type_id = ? 
                      AND academic_year = ? AND semester = ? 
                      AND status IN ('Approved', 'Awaiting Review', 'Revision')";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("iiss", $office_id, $doc_type_id, $active_ay, $active_sem);
        $stmt_check->execute();
        $check_res = $stmt_check->get_result();
        
        // If not submitted, send reminder!
        if ($check_res->num_rows === 0) {
            // Find active Dean/Office users of this office
            $sql_users = "SELECT user_id, full_name FROM users 
                          WHERE office_id = ? AND role IN ('Dean', 'Office') AND status = 'Active'";
            $stmt_users = $conn->prepare($sql_users);
            $stmt_users->bind_param("i", $office_id);
            $stmt_users->execute();
            $users_res = $stmt_users->get_result();
            
            while ($user = $users_res->fetch_assoc()) {
                $user_id = $user['user_id'];
                
                // Check if notification has already been sent to this user for this target and nudge
                $notif_title = "Deadline Reminder: " . $doc_code;
                $notif_msg = "Reminder: The deadline for submitting your " . $doc_code . " (" . $target['description'] . ") is in " . $nudge_days_text . " (Deadline: " . date('M d, Y', $deadline_time) . "). Please submit it as soon as possible.";
                if ($nudge_label === 'today') {
                    $notif_msg = "Urgent: The deadline for submitting your " . $doc_code . " (" . $target['description'] . ") is TODAY (Deadline: " . date('M d, Y', $deadline_time) . "). Please submit it immediately.";
                }
                
                $target_url = "dashboard/dean/submit_document.php?target_id=" . $target_id;
                $target_type = "submission";
                
                // Check for existing user notification
                $sql_dup = "SELECT un.user_notification_id FROM user_notifications un
                            JOIN notifications n ON un.notification_id = n.notification_id
                            WHERE un.user_id = ? AND n.target_url = ? AND n.message LIKE ?";
                $stmt_dup = $conn->prepare($sql_dup);
                $search_like = "%" . $nudge_days_text . "%";
                $stmt_dup->bind_param("iss", $user_id, $target_url, $search_like);
                $stmt_dup->execute();
                $dup_res = $stmt_dup->get_result();
                
                if ($dup_res->num_rows === 0) {
                    // Send notification
                    $sql_insert_notif = "INSERT INTO notifications (title, message, type, target_url, target_type) VALUES (?, ?, 'Deadline', ?, ?)";
                    $stmt_in = $conn->prepare($sql_insert_notif);
                    $stmt_in->bind_param("ssss", $notif_title, $notif_msg, $target_url, $target_type);
                    if ($stmt_in->execute()) {
                        $notif_id = $conn->insert_id;
                        $sql_insert_user = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
                        $stmt_iu = $conn->prepare($sql_insert_user);
                        $stmt_iu->bind_param("ii", $user_id, $notif_id);
                        $stmt_iu->execute();
                        
                        $reminders_sent++;
                        $logs[] = "Sent " . $nudge_label . " reminder to " . $user['full_name'] . " (" . $office['office_name'] . ") for " . $doc_code;
                    }
                }
            }
        }
    }
}

// Log execution details to system logs if running forced or any reminders sent
if ($reminders_sent > 0 || $is_force) {
    $log_user_id = $_SESSION['user_id'] ?? null;
    $log_action = "Deadline Reminders Check";
    $log_details = "Scanned " . $processed_targets . " upcoming deadlines. Sent " . $reminders_sent . " reminders.";
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    $sql_log = "INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bind_param("isss", $log_user_id, $log_action, $log_details, $ip_address);
    $stmt_log->execute();
}

echo json_encode([
    "success" => true,
    "processed_targets" => $processed_targets,
    "reminders_sent" => $reminders_sent,
    "logs" => $logs
]);
