<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Security: Make sure the user is an Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized Access']);
    exit();
}

// Determine Action
$action = $_GET['action'] ?? '';

// Read JSON input for POST requests
$input = json_decode(file_get_contents('php://input'), true);

// Utility function to log actions
function addLog($conn, $action, $details) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $action, $details, $ip_address);
    $stmt->execute();
}

// Broaden announcements and deadlines notifications to all Dean/Office accounts
function notifyAllUnits($conn, $title, $message, $type, $target_url = null, $target_type = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (title, message, type, target_url, target_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $title, $message, $type, $target_url, $target_type);
    if ($stmt->execute()) {
        $notif_id = $conn->insert_id;
        $res = $conn->query("SELECT user_id FROM users WHERE role IN ('Dean', 'Office') AND status = 'Active'");
        if ($res) {
            $stmt_user = $conn->prepare("INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)");
            while ($row = $res->fetch_assoc()) {
                $uid = $row['user_id'];
                $stmt_user->bind_param("ii", $uid, $notif_id);
                $stmt_user->execute();
            }
        }
        return true;
    }
    return false;
}

function is_valid_year($year) {
    return preg_match('/^\d{4}$/', (string)$year) === 1;
}

switch ($action) {
    // --- Academic Years ---
    case 'get_academic_years':
        $result = $conn->query("SELECT * FROM academic_years ORDER BY year_range DESC");
        $years = [];
        while ($row = $result->fetch_assoc()) $years[] = $row;
        echo json_encode(['success' => true, 'data' => $years]);
        break;

    case 'add_academic_year':
        $year = $input['year_range'] ?? '';
        if (!is_valid_year($year)) {
            echo json_encode(['success' => false, 'error' => 'Academic year must be a 4-digit year, e.g. 2026.']);
        } elseif ($year) {
            $stmt = $conn->prepare("INSERT INTO academic_years (year_range) VALUES (?)");
            $stmt->bind_param("s", $year);
            if ($stmt->execute()) {
                addLog($conn, "Added Academic Year", "Added $year");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        }
        break;

    case 'set_active_year':
        $id = $input['ay_id'] ?? 0;
        $conn->query("UPDATE academic_years SET is_active = 0");
        $stmt = $conn->prepare("UPDATE academic_years SET is_active = 1 WHERE ay_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Sync with system_settings for backward compatibility
            $res = $conn->query("SELECT year_range FROM academic_years WHERE ay_id = $id");
            $row = $res->fetch_assoc();
            $year_val = $row['year_range'];
            $stmt2 = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'active_academic_year'");
            $stmt2->bind_param("s", $year_val);
            $stmt2->execute();
            
            addLog($conn, "Set Active Academic Year", "Set to $year_val");
            echo json_encode(['success' => true]);
        }
        break;

    case 'update_active_period':
        $active_ay_val = $input['active_academic_year'] ?? '';
        $active_quarter_val = $input['active_quarter'] ?? '';
        if (!$active_ay_val || !$active_quarter_val) {
            echo json_encode(['success' => false, 'error' => 'Academic Year and Quarter are required.']);
            break;
        }
        
        // 1. Update system_settings
        $stmt1 = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'active_academic_year'");
        $stmt1->bind_param("s", $active_ay_val);
        $stmt1->execute();
        
        $stmt2 = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'active_semester'");
        $stmt2->bind_param("s", $active_quarter_val);
        $stmt2->execute();
        
        // 2. Sync academic_years is_active flag
        $conn->query("UPDATE academic_years SET is_active = 0");
        $stmt3 = $conn->prepare("UPDATE academic_years SET is_active = 1 WHERE year_range = ?");
        $stmt3->bind_param("s", $active_ay_val);
        $stmt3->execute();

        // 3. Sync semesters is_active flag
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt4 = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_name = ?");
        $stmt4->bind_param("s", $active_quarter_val);
        $stmt4->execute();
        
        $notif_title = "Active Period Configured";
        $notif_msg = "The active system period has been updated to AY $active_ay_val · $active_quarter_val. All default views and document submissions are now set to this period.";
        notifyAllUnits($conn, $notif_title, $notif_msg, 'System', 'dashboard/dean/index.php', 'dashboard');

        addLog($conn, "Updated Active Period Settings", "Set Academic Year to $active_ay_val and Active Quarter to $active_quarter_val");
        echo json_encode(['success' => true]);
        break;

    case 'get_system_settings':
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester', 'maintenance_mode')");
        $settings = [];
        while ($row = $res->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        echo json_encode(['success' => true, 'data' => $settings]);
        break;

    case 'edit_academic_year':
        $id = $input['ay_id'] ?? 0;
        $year = $input['year_range'] ?? '';
        if (!is_valid_year($year)) {
            echo json_encode(['success' => false, 'error' => 'Academic year must be a 4-digit year, e.g. 2026.']);
        } elseif ($id && $year) {
            $stmt = $conn->prepare("UPDATE academic_years SET year_range = ? WHERE ay_id = ?");
            $stmt->bind_param("si", $year, $id);
            if ($stmt->execute()) {
                addLog($conn, "Edited Academic Year", "Updated Academic Year ID $id to $year");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing ID or year']);
        }
        break;

    // --- Submission Deadlines ---
    case 'get_submission_deadlines':
        $year = (is_array($input) ? ($input['year'] ?? '') : '') ?: ($_GET['year'] ?? '');
        $where = '';
        if ($year !== '' && is_valid_year($year)) {
            $where = "WHERE ts.year = '" . $conn->real_escape_string($year) . "'";
        }
        $result = $conn->query("
            SELECT ts.target_id, ts.doc_type_id, ts.quarter, ts.year, ts.deadline, dt.doc_code, dt.description
            FROM target_submissions ts
            JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id
            $where
            ORDER BY ts.year DESC,
                FIELD(ts.quarter, '1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'),
                dt.doc_code ASC
        ");
        $deadlines = [];
        while ($row = $result->fetch_assoc()) $deadlines[] = $row;
        echo json_encode(['success' => true, 'data' => $deadlines]);
        break;

    case 'add_submission_deadline':
        $doc_type_id = (int)($input['doc_type_id'] ?? 0);
        $quarter = $input['quarter'] ?? '';
        $year = $input['year'] ?? '';
        $deadline = $input['deadline'] ?? '';
        $valid_quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];

        if (!$doc_type_id || !in_array($quarter, $valid_quarters, true) || !is_valid_year($year) || !$deadline) {
            echo json_encode(['success' => false, 'error' => 'Document type, quarter, 4-digit year, and deadline are required.']);
            break;
        }

        $stmt = $conn->prepare("INSERT INTO target_submissions (doc_type_id, quarter, year, deadline) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $doc_type_id, $quarter, $year, $deadline);
        if ($stmt->execute()) {
            // Retrieve document type code
            $doc_code = "Document";
            $dt_res = $conn->query("SELECT doc_code FROM document_types WHERE doc_type_id = $doc_type_id");
            if ($dt_res && $dt_row = $dt_res->fetch_assoc()) {
                $doc_code = $dt_row['doc_code'];
            }
            $deadline_formatted = date('F d, Y', strtotime($deadline));
            $notif_title = "New Deadline Set: $doc_code";
            $notif_msg = "A new submission deadline has been set for $doc_code ($quarter $year) on $deadline_formatted. Please submit your files accordingly.";
            notifyAllUnits($conn, $notif_title, $notif_msg, 'Deadline', 'dashboard/dean/submit_document.php', 'submission');

            addLog($conn, "Added Submission Deadline", "Added deadline for document type ID $doc_type_id, $quarter $year: $deadline");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;

    case 'edit_submission_deadline':
        $target_id = (int)($input['target_id'] ?? 0);
        $doc_type_id = (int)($input['doc_type_id'] ?? 0);
        $quarter = $input['quarter'] ?? '';
        $year = $input['year'] ?? '';
        $deadline = $input['deadline'] ?? '';
        $valid_quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];

        if (!$target_id || !$doc_type_id || !in_array($quarter, $valid_quarters, true) || !is_valid_year($year) || !$deadline) {
            echo json_encode(['success' => false, 'error' => 'Deadline ID, document type, quarter, 4-digit year, and deadline are required.']);
            break;
        }

        $stmt = $conn->prepare("UPDATE target_submissions SET doc_type_id = ?, quarter = ?, year = ?, deadline = ? WHERE target_id = ?");
        $stmt->bind_param("isssi", $doc_type_id, $quarter, $year, $deadline, $target_id);
        if ($stmt->execute()) {
            // Retrieve document type code
            $doc_code = "Document";
            $dt_res = $conn->query("SELECT doc_code FROM document_types WHERE doc_type_id = $doc_type_id");
            if ($dt_res && $dt_row = $dt_res->fetch_assoc()) {
                $doc_code = $dt_row['doc_code'];
            }
            $deadline_formatted = date('F d, Y', strtotime($deadline));
            $notif_title = "Deadline Updated: $doc_code";
            $notif_msg = "The submission deadline for $doc_code ($quarter $year) has been updated to $deadline_formatted. Please check your submissions.";
            notifyAllUnits($conn, $notif_title, $notif_msg, 'Deadline', 'dashboard/dean/submit_document.php', 'submission');

            addLog($conn, "Edited Submission Deadline", "Updated deadline ID $target_id to $quarter $year: $deadline");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;

    case 'delete_submission_deadline':
        $target_id = (int)($input['target_id'] ?? 0);
        if (!$target_id) {
            echo json_encode(['success' => false, 'error' => 'Missing deadline ID.']);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM target_submissions WHERE target_id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            addLog($conn, "Deleted Submission Deadline", "Deleted deadline ID $target_id");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        break;

    case 'delete_academic_year':
        $id = $input['ay_id'] ?? 0;
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM academic_years WHERE ay_id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                addLog($conn, "Deleted Academic Year", "Deleted Academic Year ID $id");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing ID']);
        }
        break;

    // --- Semesters ---
    case 'get_semesters':
        $result = $conn->query("SELECT * FROM semesters ORDER BY created_at ASC");
        $sems = [];
        while ($row = $result->fetch_assoc()) $sems[] = $row;
        echo json_encode(['success' => true, 'data' => $sems]);
        break;

    case 'add_semester':
        $name = $input['semester_name'] ?? '';
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO semesters (semester_name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                addLog($conn, "Added Semester", "Added $name");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        }
        break;

    case 'set_active_semester':
        $id = $input['sem_id'] ?? 0;
        $conn->query("UPDATE semesters SET is_active = 0");
        $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE sem_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Sync with system_settings
            $res = $conn->query("SELECT semester_name FROM semesters WHERE sem_id = $id");
            $row = $res->fetch_assoc();
            $sem_val = $row['semester_name'];
            $stmt2 = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'active_semester'");
            $stmt2->bind_param("s", $sem_val);
            $stmt2->execute();

            addLog($conn, "Set Active Semester", "Set to $sem_val");
            echo json_encode(['success' => true]);
        }
        break;

    // --- Document Types ---
    case 'get_document_types':
        $result = $conn->query("SELECT * FROM document_types ORDER BY doc_code ASC");
        $types = [];
        while ($row = $result->fetch_assoc()) $types[] = $row;
        echo json_encode(['success' => true, 'data' => $types]);
        break;

    case 'add_document_type':
        $code = $input['doc_code'] ?? '';
        $desc = $input['description'] ?? '';
        $req = $input['required'] ?? 'Yes';
        $freq = $input['frequency'] ?? 'Quarterly';
        if ($code) {
            $stmt = $conn->prepare("INSERT INTO document_types (doc_code, description, required, frequency) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $code, $desc, $req, $freq);
            if ($stmt->execute()) {
                addLog($conn, "Added Document Type", "Added $code: $desc");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        }
        break;

    case 'edit_document_type':
        $id = $input['doc_type_id'] ?? 0;
        $code = $input['doc_code'] ?? '';
        $desc = $input['description'] ?? '';
        $req = $input['required'] ?? 'Yes';
        $freq = $input['frequency'] ?? 'Quarterly';
        if ($id && $code) {
            $stmt = $conn->prepare("UPDATE document_types SET doc_code = ?, description = ?, required = ?, frequency = ? WHERE doc_type_id = ?");
            $stmt->bind_param("ssssi", $code, $desc, $req, $freq, $id);
            if ($stmt->execute()) {
                addLog($conn, "Edited Document Type", "Updated Document Type ID $id to $code ($desc)");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing ID or Document Code']);
        }
        break;

    case 'delete_document_type':
        $id = $input['doc_type_id'] ?? 0;
        $stmt = $conn->prepare("DELETE FROM document_types WHERE doc_type_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            addLog($conn, "Deleted Document Type", "Deleted ID $id");
            echo json_encode(['success' => true]);
        }
        break;

    // --- Offices / Departments ---
    case 'get_offices':
        $result = $conn->query("SELECT o.*, c.category_name FROM offices o LEFT JOIN unit_categories c ON o.category_id = c.category_id ORDER BY o.office_name ASC");
        $offices = [];
        while ($row = $result->fetch_assoc()) $offices[] = $row;
        echo json_encode(['success' => true, 'data' => $offices]);
        break;

    case 'get_categories':
        $result = $conn->query("SELECT * FROM unit_categories ORDER BY category_name ASC");
        $cats = [];
        while ($row = $result->fetch_assoc()) $cats[] = $row;
        echo json_encode(['success' => true, 'data' => $cats]);
        break;

    case 'add_office':
        $name = $input['office_name'] ?? '';
        $code = $input['office_code'] ?? '';
        $cat_id = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO offices (office_name, office_code, category_id, status) VALUES (?, ?, ?, 'Active')");
            $stmt->bind_param("sss", $name, $code, $cat_id);
            if ($stmt->execute()) {
                addLog($conn, "Added Office", "Added $name ($code)");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        }
        break;

    case 'toggle_office_status':
        $id = $input['office_id'] ?? 0;
        $status = $input['status'] ?? 'Active';
        $stmt = $conn->prepare("UPDATE offices SET status = ? WHERE office_id = ?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            addLog($conn, "Toggled Office Status", "Set Office ID $id to $status");
            echo json_encode(['success' => true]);
        }
        break;

    case 'edit_office':
        $id = $input['office_id'] ?? 0;
        $name = $input['office_name'] ?? '';
        $code = $input['office_code'] ?? '';
        $cat_id = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        if ($id && $name) {
            $stmt = $conn->prepare("UPDATE offices SET office_name = ?, office_code = ?, category_id = ? WHERE office_id = ?");
            // use 's' for nulls or 'sssi' because if cat_id is null, bind_param 'i' might cast it to 0. 
            // Wait, bind_param supports nulls, but it's safer to bind as string or handle explicitly.
            $stmt->bind_param("sssi", $name, $code, $cat_id, $id);
            if ($stmt->execute()) {
                addLog($conn, "Edited Office", "Updated Office ID $id to $name ($code)");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing ID or Office Name']);
        }
        break;

    case 'delete_office':
        $id = $input['office_id'] ?? 0;
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM offices WHERE office_id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                addLog($conn, "Deleted Office", "Deleted Office ID $id");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing ID']);
        }
        break;

    // --- Maintenance & Logs (Existing) ---
    case 'toggle_maintenance':
        $val = $input['status'] ? '1' : '0';
        $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'");
        $stmt->bind_param("s", $val);
        $stmt->execute();
        addLog($conn, "Maintenance Mode Toggled", "Maintenance mode set to " . ($input['status'] ? 'Enabled' : 'Disabled'));
        echo json_encode(['success' => true]);
        break;

    case 'get_logs':
        $sql = "SELECT l.*, u.full_name FROM system_logs l LEFT JOIN users u ON l.user_id = u.user_id ORDER BY created_at DESC LIMIT 50";
        $result = $conn->query($sql);
        $logs = [];
        while ($row = $result->fetch_assoc()) $logs[] = $row;
        echo json_encode(['success' => true, 'logs' => $logs]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
