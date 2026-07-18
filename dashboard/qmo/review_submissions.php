<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";
require_once __DIR__ . "/../../includes/workflow-tracking.php";

function h($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Helper function to trigger a notification
 */
function triggerNotification($user_id, $title, $message, $conn, $type = 'System') {
    $sql_notif = "INSERT INTO notifications (title, message, type) VALUES (?, ?, ?)";
    $stmt_notif = $conn->prepare($sql_notif);
    $stmt_notif->bind_param("sss", $title, $message, $type);
    
    if ($stmt_notif->execute()) {
        $notification_id = $conn->insert_id;
        $sql_user_notif = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
        $stmt_user_notif = $conn->prepare($sql_user_notif);
        $stmt_user_notif->bind_param("ii", $user_id, $notification_id);
        return $stmt_user_notif->execute();
    }
    return false;
}

if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin'){ 
    header("Location: ../../index.php"); 
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_next_version') {
    header('Content-Type: application/json');
    $office_id = (int)($_GET['office_id'] ?? 0);
    $doc_type_id = (int)($_GET['doc_type_id'] ?? 0);
    $ay = $_GET['ay'] ?? '';
    $semester = $_GET['semester'] ?? '';
    
    $sql_existing = "SELECT version_number FROM documents WHERE office_id = ? AND doc_type_id = ? AND academic_year = ? AND semester = ? AND status != 'Archived' ORDER BY document_id DESC LIMIT 1";
    $stmt_exist = $conn->prepare($sql_existing);
    $stmt_exist->bind_param("iiss", $office_id, $doc_type_id, $ay, $semester);
    $stmt_exist->execute();
    $exist_res = $stmt_exist->get_result()->fetch_assoc();
    $stmt_exist->close();
    
    $next_ver = 1;
    if ($exist_res) {
        $next_ver = (int)$exist_res['version_number'] + 1;
    }
    echo json_encode(['success' => true, 'next_version' => $next_ver]);
    exit();
}

// Load active academic year and active semester from system configurations
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];
$active_year_display = $active_year;
if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
    $active_year_display = (string)max(array_map('intval', $matches[0]));
}

// Fetch active offices and document types for the Scanner form dropdowns
$scanner_offices = [];
$res_off = $conn->query("SELECT office_id, office_name, office_code FROM offices WHERE status = 'Active' AND is_archived = FALSE ORDER BY office_name ASC");
if ($res_off) {
    while ($r = $res_off->fetch_assoc()) {
        $scanner_offices[] = $r;
    }
}

$scanner_doc_types = [];
$res_dts = $conn->query("SELECT doc_type_id, doc_code, description FROM document_types ORDER BY doc_code ASC");
if ($res_dts) {
    while ($r = $res_dts->fetch_assoc()) {
        $scanner_doc_types[] = $r;
    }
}

$all_academic_years = [];
$res_ay = $conn->query("SELECT year_range FROM academic_years ORDER BY year_range DESC");
if ($res_ay) {
    while ($r = $res_ay->fetch_assoc()) {
        $all_academic_years[] = $r['year_range'];
    }
}
if (empty($all_academic_years)) {
    $all_academic_years = [$active_year];
}

$all_semesters = [];
$res_sem = $conn->query("SELECT semester_name FROM semesters ORDER BY semester_name ASC");
if ($res_sem) {
    while ($r = $res_sem->fetch_assoc()) {
        $all_semesters[] = $r['semester_name'];
    }
}
if (empty($all_semesters)) {
    $all_semesters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter', '1st Semester', '2nd Semester'];
}


// Handle Approval, Rejection, or Hardcopy Scan upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $doc_id = $_POST['document_id'] ?? null;
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    
    if ($action === 'upload_scan') {
        $file = $_FILES['scanned_document'] ?? null;
        $ay = $_POST['academic_year'] ?? null;
        $semester = $_POST['semester'] ?? null;
        $office_id = $_POST['office_id'] ?? null;
        $doc_type_id = $_POST['doc_type_id'] ?? null;
        
        header('Content-Type: application/json');
        
        if (!$file || !$ay || !$semester || !$office_id || !$doc_type_id) {
            echo json_encode(['success' => false, 'message' => 'Missing required scanner fields.']);
            exit();
        }
        
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'pdf') {
            echo json_encode(['success' => false, 'message' => 'Only PDF files are supported.']);
            exit();
        }
        
        // Find existing non-archived submission to handle versioning
        $sql_existing = "SELECT document_id, version_number FROM documents WHERE office_id = ? AND doc_type_id = ? AND academic_year = ? AND semester = ? AND status != 'Archived' ORDER BY document_id DESC LIMIT 1";
        $stmt_exist = $conn->prepare($sql_existing);
        $stmt_exist->bind_param("iiss", $office_id, $doc_type_id, $ay, $semester);
        $stmt_exist->execute();
        $exist_res = $stmt_exist->get_result()->fetch_assoc();
        $stmt_exist->close();
        
        $version_number = 1;
        $previous_document_id = null;
        
        if ($exist_res) {
            $previous_document_id = (int)$exist_res['document_id'];
            $version_number = (int)$exist_res['version_number'] + 1;
            
            // Archive the previous version
            $sql_archive = "UPDATE documents SET status = 'Archived' WHERE document_id = ?";
            $stmt_arch = $conn->prepare($sql_archive);
            $stmt_arch->bind_param("i", $previous_document_id);
            $stmt_arch->execute();
            $stmt_arch->close();
        }
        
        $safe_year = preg_replace('/[^A-Za-z0-9_-]/', '_', $ay);
        $safe_quarter = preg_replace('/[^A-Za-z0-9_-]/', '_', $semester);
        
        $upload_dir = "../../uploads/documents/" . $safe_year . "/" . $safe_quarter . "/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $new_filename = time() . "_" . bin2hex(random_bytes(6)) . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
        $target_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            // Check if column version_number and previous_document_id exist
            $has_ver_cols = ua_qms_column_exists($conn, 'documents', 'version_number') && ua_qms_column_exists($conn, 'documents', 'previous_document_id');
            
            if ($has_ver_cols) {
                $sql_ins = "INSERT INTO documents (office_id, doc_type_id, file_name, file_path, status, academic_year, semester, version_number, previous_document_id) VALUES (?, ?, ?, ?, 'Approved', ?, ?, ?, ?)";
                $stmt_ins = $conn->prepare($sql_ins);
                $stmt_ins->bind_param("iissssii", $office_id, $doc_type_id, $file['name'], $target_path, $ay, $semester, $version_number, $previous_document_id);
            } else {
                $sql_ins = "INSERT INTO documents (office_id, doc_type_id, file_name, file_path, status, academic_year, semester) VALUES (?, ?, ?, ?, 'Approved', ?, ?)";
                $stmt_ins = $conn->prepare($sql_ins);
                $stmt_ins->bind_param("iissss", $office_id, $doc_type_id, $file['name'], $target_path, $ay, $semester);
            }
            
            if ($stmt_ins->execute()) {
                $new_doc_id = $stmt_ins->insert_id;
                $stmt_ins->close();
                
                // Add review log
                $remarks = 'Scanned and uploaded directly by QMO Admin.';
                $sql_review = "INSERT INTO document_reviews (document_id, reviewed_by, remarks, review_status) VALUES (?, 'RMO', ?, 'Approved')";
                $stmt_rev = $conn->prepare($sql_review);
                $stmt_rev->bind_param("is", $new_doc_id, $remarks);
                $stmt_rev->execute();
                $stmt_rev->close();
                
                // Trigger achievements, compliance tracking, and other updates
                ua_qms_update_review_tracking($conn, $new_doc_id, 'Approved');
                
                echo json_encode(['success' => true, 'message' => 'Document scanned, approved, and repository successfully updated!']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save document record: ' . $conn->error]);
                exit();
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save scanned document file.']);
            exit();
        }
    }
    
    if (!$doc_id) {
        $message = "Error: No document selected for review.";
    } else {
        // Verify current status is 'Awaiting Review'
        $sql_check = "SELECT status FROM documents WHERE document_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("i", $doc_id);
        $stmt_check->execute();
        $curr_status = $stmt_check->get_result()->fetch_assoc()['status'] ?? '';
        $stmt_check->close();

        if ($curr_status !== 'Awaiting Review') {
            $message = "Error: This document cannot be reviewed because its current status is '" . $curr_status . "'.";
        } else {
            $status = ($action === 'approve') ? 'Approved' : 'Revision';
            
            // Update document status
            $sql_update = "UPDATE documents SET status = ? WHERE document_id = ?";
            $stmt_upd = $conn->prepare($sql_update);
            $stmt_upd->bind_param("si", $status, $doc_id);
            
            if ($stmt_upd->execute()) {
                // Record review
                $sql_review = "INSERT INTO document_reviews (document_id, reviewed_by, remarks, review_status) VALUES (?, 'RMO', ?, ?)";
                $stmt_rev = $conn->prepare($sql_review);
                $stmt_rev->bind_param("iss", $doc_id, $remarks, $status);
                $stmt_rev->execute();
                $stmt_rev->close();

                ua_qms_update_review_tracking($conn, (int)$doc_id, $status);
                
                $message = "Success: Document " . ($status === 'Approved' ? 'approved' : 'sent for revision') . " successfully.";
                
                // Get document details for notification and targets
                $sql_doc_info = "SELECT d.office_id, d.doc_type_id, d.academic_year, d.semester, dt.doc_code 
                                 FROM documents d 
                                 JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
                                 WHERE d.document_id = ?";
                $stmt_doc = $conn->prepare($sql_doc_info);
                $stmt_doc->bind_param("i", $doc_id);
                $stmt_doc->execute();
                $doc_info = $stmt_doc->get_result()->fetch_assoc();
                $doc_code = $doc_info['doc_code'] ?? 'Document';
                $target_office_id = $doc_info['office_id'];
                $target_doc_type_id = $doc_info['doc_type_id'];
                $target_year = $doc_info['academic_year'];
                $target_quarter = $doc_info['semester'];
                $stmt_doc->close();

                if ($status === 'Approved') {
                    // Tracking handles are now purely delegated to ua_qms_update_review_tracking() 
                }

                // Trigger Notification for the Submitter (Dean)
                $notif_title = ($status === 'Approved') ? "Document Approved" : "Revision Required";
            $notif_msg = ($status === 'Approved') 
                ? "Your submission for " . $doc_code . " has been approved by QMO."
                : "Your submission for " . $doc_code . " requires revision. Remarks: " . $remarks;

            // Find the Unit/Office Head (Dean or Office role) of that office
            $sql_heads = "SELECT user_id FROM users WHERE office_id = ? AND role IN ('Dean', 'Office') AND status = 'Active'";
            $stmt_heads = $conn->prepare($sql_heads);
            $stmt_heads->bind_param("i", $target_office_id);
            $stmt_heads->execute();
            $heads_res = $stmt_heads->get_result();
            
            while ($head = $heads_res->fetch_assoc()) {
                // Create notification with routing metadata
                $sql_notif = "INSERT INTO notifications (title, message, type, target_url, target_type, related_document_id) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_notif = $conn->prepare($sql_notif);
                $target_url = "dashboard/dean/my_submissions.php";
                $target_type = ($status === 'Approved') ? "approval" : "revision";
                $notif_type = "System";
                $stmt_notif->bind_param("sssssi", $notif_title, $notif_msg, $notif_type, $target_url, $target_type, $doc_id);
                
                if ($stmt_notif->execute()) {
                    $notification_id = $conn->insert_id;
                    $sql_user_notif = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
                    $stmt_user_notif = $conn->prepare($sql_user_notif);
                    $stmt_user_notif->bind_param("ii", $head['user_id'], $notification_id);
                    $stmt_user_notif->execute();
                }
            }
        } else {
            $message = "Error: Failed to update document status.";
        }
        }
        
        if (isset($message) && strpos($message, 'Success') !== false) {
            header("Location: review_submissions.php?msg=" . urlencode($message));
            exit();
        }
    }
}

// Get selected document ID from GET parameter (from monitoring page)
$selected_id = $_GET['id'] ?? null;
$selected_doc = null;

if ($selected_id) {
    $sql_selected = "SELECT d.*, dt.doc_code, dt.description as doc_type_desc, o.office_name, u.full_name as submitter_name 
                     FROM documents d 
                     JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
                     JOIN offices o ON d.office_id = o.office_id
                     LEFT JOIN users u ON o.office_id = u.office_id AND u.role = 'Dean'
                     WHERE d.document_id = ?";
    $stmt_sel = $conn->prepare($sql_selected);
    $stmt_sel->bind_param("i", $selected_id);
    $stmt_sel->execute();
    $selected_doc = $stmt_sel->get_result()->fetch_assoc();
}

// Fetch active review queue documents (Awaiting Review or Revision status) for active period - sorted by latest first
$sql_queue = "SELECT d.*, dt.doc_code, dt.description as doc_type_desc, o.office_name, u.full_name as submitter_name 
              FROM documents d 
              JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
              JOIN offices o ON d.office_id = o.office_id
              LEFT JOIN users u ON o.office_id = u.office_id AND u.role = 'Dean'
              WHERE d.status IN ('Awaiting Review', 'Revision')
                AND d.academic_year = ?
                AND d.semester = ?
                AND d.document_id IN (
                    SELECT MAX(document_id) 
                    FROM documents 
                    WHERE status IN ('Awaiting Review', 'Revision')
                      AND academic_year = ?
                      AND semester = ?
                    GROUP BY office_id, doc_type_id
                )
              ORDER BY d.submitted_at DESC";
$stmt_queue = $conn->prepare($sql_queue);
$stmt_queue->bind_param("ssss", $active_year, $active_semester, $active_year, $active_semester);
$stmt_queue->execute();
$result_queue = $stmt_queue->get_result();
$queue_count = $result_queue->num_rows;

$submission_stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'revision' => 0,
];
$stmt_stats = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) AS revision
    FROM documents
    WHERE academic_year = ? AND semester = ?
");
$stmt_stats->bind_param("ss", $active_year, $active_semester);
$stmt_stats->execute();
$stats_res = $stmt_stats->get_result();
if ($stats_res) {
    $submission_stats = array_merge($submission_stats, $stats_res->fetch_assoc() ?: []);
}
$stmt_stats->close();

// If no document selected via GET, but there are items in queue, pick the first one
if (!$selected_doc && $queue_count > 0) {
    $result_queue->data_seek(0);
    $selected_doc = $result_queue->fetch_assoc();
    $result_queue->data_seek(0); // Reset for the while loop below
}

// Group submissions into New (Version 1, Awaiting Review) and Revisions/Resubmissions (Version > 1 or Revision)
$new_submissions = [];
$revision_submissions = [];
while ($row = $result_queue->fetch_assoc()) {
    if ($row['status'] === 'Revision' || (int)$row['version_number'] > 1) {
        $revision_submissions[] = $row;
    } else {
        $new_submissions[] = $row;
    }
}

$versions_list = [];
if ($selected_doc) {
    $sql_versions = "
        SELECT d.*, dr.remarks, dr.reviewed_at 
        FROM documents d
        LEFT JOIN (
            SELECT dr1.document_id, dr1.remarks, dr1.reviewed_at
            FROM document_reviews dr1
            JOIN (
                SELECT document_id, MAX(review_id) as max_id 
                FROM document_reviews 
                GROUP BY document_id
            ) dr2 ON dr1.review_id = dr2.max_id
        ) dr ON d.document_id = dr.document_id
        WHERE d.office_id = ?
          AND d.doc_type_id = ?
          AND d.academic_year = ?
          AND d.semester = ?
        ORDER BY d.version_number DESC, d.document_id DESC
    ";
    $stmt_ver = $conn->prepare($sql_versions);
    $stmt_ver->bind_param("iiss", $selected_doc['office_id'], $selected_doc['doc_type_id'], $selected_doc['academic_year'], $selected_doc['semester']);
    $stmt_ver->execute();
    $res_ver = $stmt_ver->get_result();
    while ($v_row = $res_ver->fetch_assoc()) {
        $versions_list[] = $v_row;
    }
    $stmt_ver->close();
}
$has_selected_id = isset($_GET['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Submissions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <link rel="stylesheet" href="../../assets/css/file-viewer.css">
    <script src="../../assets/js/file-viewer.js" defer></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @media print {
            .no-print { display: none; }
            .print-only { display: block !important; }
        }
        .print-only { display: none; }
        .bg-ua-maroon { background-color: #800000; }
        .hover-bg-ua-maroon:hover { background-color: #600000; }
        /* File Viewer Modal Styles */
        #file-viewer-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            align-items: center;
            justify-content: center;
        }
        #file-viewer-modal.active {
            display: flex;
        }
        .file-viewer-container {
            background-color: #fff;
            border-radius: 0.75rem;
            width: 95%;
            height: 95vh;
            max-width: 1200px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .file-viewer-header {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f9fafb;
        }
        .file-viewer-header h3 {
            font-weight: bold;
            color: #1f2937;
            margin: 0;
            word-break: break-word;
        }
        .file-viewer-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
            transition: color 0.3s;
        }
        .file-viewer-close:hover {
            color: #1f2937;
        }
        .file-viewer-content {
            flex: 1;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f3f4f6;
        }
        .file-viewer-content iframe,
        .file-viewer-content img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
        }
        .file-viewer-error {
            color: #dc2626;
            font-weight: 500;
            text-align: center;
            padding: 2rem;
        }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php require_once __DIR__ . "/../../includes/sidebar_qmo.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0 no-print">
                <div class="flex items-center gap-4">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900 leading-tight">Submissions</h1>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <!-- Clock -->
                    <div id="clock" class="text-sm text-gray-500 font-medium"></div>
                    <div class="flex items-center gap-2">
                        <!-- Message Inbox -->
                        <button id="inbox-btn" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-envelope text-lg"></i>
                            <span id="message-badge" class="absolute top-1 right-1 w-4 h-4 bg-[#800000] text-white text-[10px] flex items-center justify-center rounded-full hidden">0</span>
                        </button>
                        <!-- Notifications -->
                        <button id="notification-bell" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-bell text-lg"></i>
                            <span id="notification-badge" class="absolute top-1 right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center hidden">0</span>
                        </button>
                        <!-- Profile Dropdown -->
                        <div class="relative">
                            <button id="profile-btn" class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition overflow-hidden">
                            <?php if (!empty($_SESSION['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_photo']); ?>" class="w-full h-full object-cover rounded-full" alt="Profile" />
                            <?php else: ?>
                                <?php echo h(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
                            <?php endif; ?>
                        </button>
                            <div id="profile-dropdown" class="profile-dropdown">
                                <div class="profile-dropdown-header">
                                    <p><?php echo h($_SESSION['full_name'] ?? 'User'); ?></p>
                                    <span><?php echo h($_SESSION['role'] ?? 'Role'); ?></span>
                                </div>
                                <button id="profile-settings-btn" type="button" class="profile-dropdown-item" onclick="event.stopPropagation(); document.getElementById('profile-dropdown').classList.remove('active'); openProfileSettingsModal();">
                                    <i class="fas fa-cog text-gray-500 mr-3 w-4 text-center"></i> Profile Settings
                                </button>
                                <a href="../../logout.php" class="profile-dropdown-item logout" onclick="event.stopPropagation(); event.preventDefault(); window.logoutUser();">
                                    <i class="fas fa-sign-out-alt"></i> Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (isset($message)): ?>
                <div class="qms-alert px-8 py-4 <?php echo strpos($message, 'Error') !== false ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800'; ?> border-b text-sm font-medium">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>



            <div class="flex-1 overflow-y-auto xl:overflow-hidden flex flex-col p-4 md:p-6 min-h-0">
                <div class="grid grid-cols-2 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 no-print">
                    <div class="bg-white border border-gray-200 rounded-lg p-5">
                        <p class="text-sm font-medium text-gray-500">Total Submissions</p>
                        <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int)$submission_stats['total']; ?></p>
                        <p class="mt-2 text-xs text-gray-500">All submissions in this period</p>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-5">
                        <p class="text-sm font-medium text-gray-500">Awaiting Review</p>
                        <p class="mt-2 text-3xl font-bold text-orange-600"><?php echo (int)$submission_stats['pending']; ?></p>
                        <p class="mt-2 text-xs text-gray-500">Pending QMO validation</p>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-5">
                        <p class="text-sm font-medium text-gray-500">Approved</p>
                        <p class="mt-2 text-3xl font-bold text-green-700"><?php echo (int)$submission_stats['approved']; ?></p>
                        <p class="mt-2 text-xs text-gray-500">Successfully validated and archived</p>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-5">
                        <p class="text-sm font-medium text-gray-500">Needs Revision</p>
                        <p class="mt-2 text-3xl font-bold text-[#800000]"><?php echo (int)$submission_stats['revision']; ?></p>
                        <p class="mt-2 text-xs text-gray-500">Returned to units for modifications</p>
                    </div>
                </div>

                <!-- Queue and Details Container -->
                <div class="flex-1 flex flex-col xl:flex-row gap-6 min-h-0">
                <!-- Queue Panel -->
                <div class="w-full xl:w-[480px] h-[450px] xl:h-auto bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col overflow-hidden flex-shrink-0 no-print <?php echo $has_selected_id ? 'hidden xl:flex' : 'flex'; ?>">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h2 class="font-bold text-lg text-gray-900 flex items-center gap-2">
                            <i data-lucide="inbox" class="w-5 h-5 text-[#800000]"></i>
                            Review Queue
                            <span id="reviewQueueCount" class="ml-auto bg-[#800000] text-white text-xs px-2 py-1 rounded-full font-bold">
                                <?php echo $queue_count; ?>
                            </span>
                        </h2>
                    </div>

                    <!-- Queue Category Tabs -->
                    <div class="flex border-b border-gray-200 bg-gray-50 flex-shrink-0" id="reviewQueueTabs">
                        <button type="button" id="tab-new-btn" onclick="switchQueueTab('new')" class="flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-[#800000] text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5">
                            <i class="fas fa-plus-circle text-blue-600"></i> New (<?php echo count($new_submissions); ?>)
                        </button>
                        <button type="button" id="tab-rev-btn" onclick="switchQueueTab('rev')" class="flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5">
                            <i class="fas fa-undo-alt text-amber-600"></i> Revisions (<?php echo count($revision_submissions); ?>)
                        </button>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto" id="reviewQueueContainer">
                        <?php if ($queue_count === 0): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i data-lucide="check-circle" class="w-12 h-12 mx-auto mb-2 text-green-500"></i>
                                <p class="text-sm font-medium mt-1">Review Queue Empty</p>
                                <p class="text-xs">No documents awaiting review.</p>
                            </div>
                        <?php else: ?>
                            <!-- Section 1: New Submissions -->
                            <div id="queue-section-new" class="p-3">
                                <div class="space-y-2">
                                    <?php if (empty($new_submissions)): ?>
                                        <p class="text-xs text-gray-400 text-center py-4 bg-white border border-gray-200 rounded-lg">No new submissions.</p>
                                    <?php else: ?>
                                        <?php foreach ($new_submissions as $row): ?>
                                            <a href="review_submissions.php?id=<?php echo $row['document_id']; ?>" 
                                               class="block p-3 bg-white border border-gray-200 rounded-lg hover:border-[#800000] hover:shadow-sm transition cursor-pointer <?php echo ($selected_doc && $selected_doc['document_id'] == $row['document_id']) ? 'ring-2 ring-[#800000] border-transparent' : ''; ?>">
                                                <div class="flex justify-between items-start gap-3 mb-2">
                                                    <span class="text-[10px] font-bold text-gray-700 uppercase tracking-wider bg-gray-100 px-2 py-0.5 rounded border border-gray-250 leading-relaxed"><?php echo htmlspecialchars(($row['doc_type_desc'] ?? 'Document') . ' (' . $row['doc_code'] . ')'); ?></span>
                                                    <span class="text-[9px] text-gray-400 flex-shrink-0 mt-0.5"><?php echo date('M d, g:i A', strtotime($row['submitted_at'])); ?></span>
                                                </div>
                                                <p class="text-xs font-bold text-gray-900 break-words leading-relaxed"><?php echo htmlspecialchars($row['office_name']); ?></p>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Section 2: Revisions & Resubmissions -->
                            <div id="queue-section-rev" class="p-3 hidden">
                                <div class="space-y-2">
                                    <?php if (empty($revision_submissions)): ?>
                                        <p class="text-xs text-gray-400 text-center py-4 bg-white border border-gray-250 rounded-lg">No active revisions.</p>
                                    <?php else: ?>
                                        <?php foreach ($revision_submissions as $row): ?>
                                            <a href="review_submissions.php?id=<?php echo $row['document_id']; ?>" 
                                               class="block p-3 bg-white border border-gray-200 rounded-lg hover:border-[#800000] hover:shadow-sm transition cursor-pointer <?php echo ($selected_doc && $selected_doc['document_id'] == $row['document_id']) ? 'ring-2 ring-[#800000] border-transparent' : ''; ?>">
                                                <div class="flex justify-between items-start gap-3 mb-2">
                                                    <span class="text-[10px] font-bold text-gray-700 uppercase tracking-wider bg-gray-100 px-2 py-0.5 rounded border border-gray-250 leading-relaxed"><?php echo htmlspecialchars(($row['doc_type_desc'] ?? 'Document') . ' (' . $row['doc_code'] . ')'); ?></span>
                                                    <span class="text-[9px] text-gray-400 flex-shrink-0 mt-0.5"><?php echo date('M d, g:i A', strtotime($row['submitted_at'])); ?></span>
                                                </div>
                                                <div class="flex items-center justify-between gap-2 flex-wrap">
                                                    <p class="text-xs font-bold text-gray-900 break-words flex-1 min-w-[200px] leading-relaxed"><?php echo htmlspecialchars($row['office_name']); ?></p>
                                                    <?php if ($row['status'] === 'Revision'): ?>
                                                        <span class="bg-red-100 text-[#800000] border border-red-300 text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1 shadow-sm"><i class="fas fa-undo-alt text-[8px]"></i> Returned</span>
                                                    <?php else: ?>
                                                        <span class="bg-amber-100 text-amber-800 border border-amber-300 text-[10px] font-bold px-2 py-0.5 rounded-md flex items-center gap-1 shadow-sm"><i class="fas fa-code-branch text-[8px]"></i> V<?php echo $row['version_number']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Details Panel -->
                <div class="flex-grow md:flex-1 bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col overflow-hidden min-h-[500px] xl:h-auto xl:min-h-0 <?php echo $has_selected_id ? 'flex' : 'hidden xl:flex'; ?>">
                    <?php if ($has_selected_id): ?>
                        <div class="xl:hidden px-6 pt-4 bg-gray-50 flex-shrink-0">
                            <a href="review_submissions.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-[#800000] hover:text-red-900 transition-colors uppercase tracking-wider">
                                <i class="fas fa-chevron-left text-[10px]"></i> Back to Queue
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="p-6 border-b border-gray-200 bg-gray-50">
                        <h2 class="text-xl font-bold text-gray-900">Document Details</h2>
                        <p class="text-sm text-gray-500 mt-1">Review and approve or request revisions</p>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto p-6">
                        <?php if ($selected_doc): ?>
                            <div class="space-y-8">
                                <!-- Document Info Card -->
                                <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                                    <div class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-6">
                                        <div>
                                            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded-full uppercase tracking-wider mb-2 inline-block">
                                                <?php echo htmlspecialchars(($selected_doc['doc_type_desc'] ?? 'Quality Document') . ' (' . $selected_doc['doc_code'] . ')'); ?>
                                            </span>
                                            <h3 class="text-xl sm:text-2xl font-bold text-gray-900 leading-tight"><?php echo htmlspecialchars($selected_doc['office_name']); ?></h3>
                                        </div>
                                        <div class="text-left sm:text-right flex-shrink-0">
                                            <p class="text-xs font-bold text-gray-400 uppercase">Submitted By</p>
                                            <p class="font-bold text-gray-900"><?php echo htmlspecialchars($selected_doc['submitter_name'] ?? 'Unit Head'); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('F d, Y h:i A', strtotime($selected_doc['submitted_at'])); ?></p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-4 p-4 bg-white rounded-lg border border-gray-200 group hover:border-[#800000] transition-colors">
                                        <div class="w-12 h-12 bg-red-50 rounded-lg flex items-center justify-center text-red-600">
                                            <i data-lucide="file-text" class="w-6 h-6"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-bold text-gray-900 truncate"><?php echo htmlspecialchars($selected_doc['file_name']); ?> <?php if(isset($selected_doc['version_number']) && $selected_doc['version_number'] > 1) echo '<span class="bg-[#800000] text-white text-[10px] font-bold px-2 py-0.5 rounded-md ml-2 shadow-sm border border-red-900"><i class="fas fa-code-branch mr-1"></i>Version '.$selected_doc['version_number'].'</span>'; ?></p>
                                            <p class="text-xs text-gray-500">Click to preview document</p>
                                        </div>
                                        <div class="flex flex-col sm:flex-row gap-2">
                                            <button onclick="openFileViewer('<?php echo htmlspecialchars($selected_doc['file_path']); ?>', '<?php echo htmlspecialchars($selected_doc['file_name']); ?>')" 
                                                    class="px-4 py-2 bg-[#800000] text-white border border-[#800000] text-xs font-bold rounded-lg hover:bg-red-950 transition-all inline-flex items-center gap-1">
                                                <i class="fas fa-eye text-[10px]"></i> PREVIEW
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Version History Timeline -->
                                <div class="bg-white rounded-xl border border-gray-200 p-6">
                                    <h4 class="text-xs font-bold text-gray-500 uppercase mb-4 flex items-center gap-2">
                                        <i class="fas fa-history text-[#800000]"></i> Submission Version History
                                    </h4>
                                    <div class="space-y-4">
                                        <?php foreach ($versions_list as $ver): ?>
                                            <div class="flex items-start gap-4 p-3.5 bg-gray-50/50 hover:bg-gray-50 border border-gray-150 rounded-xl transition">
                                                <!-- Version indicator badge -->
                                                <div class="flex-shrink-0 flex flex-col items-center justify-center w-12 h-12 rounded-lg <?php echo ($selected_doc['document_id'] == $ver['document_id']) ? 'bg-red-50 text-[#800000] border border-red-200' : 'bg-gray-100 text-gray-500 border border-gray-200'; ?> font-bold shadow-sm">
                                                    <span class="text-[10px] uppercase text-gray-400 font-bold leading-none">Ver</span>
                                                    <span class="text-lg leading-none mt-0.5"><?php echo (int)($ver['version_number'] ?: 1); ?></span>
                                                </div>
                                                
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                                        <p class="text-sm font-bold text-gray-900 truncate max-w-[200px] sm:max-w-[300px] md:max-w-[400px]"><?php echo htmlspecialchars($ver['file_name']); ?></p>
                                                        <div>
                                                            <?php if ($ver['status'] === 'Approved'): ?>
                                                                <span class="bg-green-50 text-green-700 border border-green-200 text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm"><i class="fas fa-check-circle mr-0.5"></i> Approved</span>
                                                            <?php elseif ($ver['status'] === 'Revision'): ?>
                                                                <span class="bg-red-50 text-[#800000] border border-red-200 text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm"><i class="fas fa-exclamation-circle mr-0.5"></i> Revision Requested</span>
                                                            <?php elseif ($ver['status'] === 'Awaiting Review'): ?>
                                                                <span class="bg-orange-50 text-orange-700 border border-orange-200 text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm"><i class="fas fa-clock mr-0.5"></i> Awaiting Review</span>
                                                            <?php elseif ($ver['status'] === 'Archived'): ?>
                                                                <span class="bg-gray-100 text-gray-500 border border-gray-200 text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm"><i class="fas fa-archive mr-0.5"></i> Archived (Superseded)</span>
                                                            <?php else: ?>
                                                                <span class="bg-gray-50 text-gray-500 border border-gray-200 text-[9px] font-bold px-2 py-0.5 rounded-full shadow-sm"><?php echo htmlspecialchars($ver['status']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <p class="text-xs text-gray-500">Submitted: <?php echo date('M d, Y h:i A', strtotime($ver['submitted_at'])); ?></p>
                                                    
                                                    <?php if (!empty($ver['remarks'])): ?>
                                                        <div class="mt-2 text-xs bg-white border border-gray-150 p-2.5 rounded-lg text-gray-600 flex items-start gap-1.5 shadow-sm">
                                                            <i class="fas fa-comment-dots text-gray-400 mt-0.5"></i>
                                                            <span><strong>Review Comments:</strong> <?php echo htmlspecialchars($ver['remarks']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="flex-shrink-0">
                                                    <button onclick="openFileViewer('<?php echo htmlspecialchars($ver['file_path']); ?>', '<?php echo htmlspecialchars($ver['file_name']); ?>')" 
                                                            class="p-2 text-gray-500 hover:text-[#800000] hover:bg-red-50 rounded-lg transition" title="Preview version">
                                                        <i class="fas fa-eye text-base"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Review Form -->
                                <?php if ($selected_doc['status'] === 'Awaiting Review'): ?>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-3">Review Remarks</label>
                                    <form method="POST" id="review-form" class="space-y-4">
                                        <input type="hidden" name="document_id" value="<?php echo $selected_doc['document_id']; ?>">
                                        <textarea id="reviewRemarks" name="remarks" class="w-full p-4 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none transition-all" 
                                                  placeholder="Add remarks for the submitter regarding approval or required revisions..." rows="5"></textarea>
                                        
                                        <div class="flex gap-4 pt-2">
                                            <button type="submit" name="action" value="revision" onclick="document.getElementById('reviewRemarks').required = true;" class="flex-1 px-6 py-4 border-2 border-[#800000] text-[#800000] font-bold rounded-xl hover:bg-red-50 transition-all flex items-center justify-center gap-2">
                                                <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                                                Request Revisions
                                            </button>
                                            <button type="submit" name="action" value="approve" onclick="document.getElementById('reviewRemarks').required = false;" class="flex-1 px-6 py-4 bg-[#800000] text-white font-bold rounded-xl hover:bg-red-900 transition-all shadow-lg flex items-center justify-center gap-2">
                                                <i data-lucide="check-circle" class="w-5 h-5"></i>
                                                Approve Document
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <?php elseif ($selected_doc['status'] === 'Revision'): ?>
                                <div class="p-4 bg-red-50 border border-red-200 text-red-800 text-sm font-semibold rounded-xl flex items-center gap-2.5 shadow-sm">
                                    <i class="fas fa-exclamation-triangle text-red-600 text-base"></i>
                                    <span>This version has already been returned for revisions. You cannot review or approve it until the unit uploads a new version.</span>
                                </div>
                                <?php elseif ($selected_doc['status'] === 'Approved'): ?>
                                <div class="p-4 bg-green-50 border border-green-200 text-green-800 text-sm font-semibold rounded-xl flex items-center gap-2.5 shadow-sm">
                                    <i class="fas fa-check-circle text-green-600 text-base"></i>
                                    <span>This document is already Approved and cataloged in the repository.</span>
                                </div>
                                <?php else: ?>
                                <div class="p-4 bg-gray-50 border border-gray-200 text-gray-700 text-sm font-semibold rounded-xl flex items-center gap-2.5 shadow-sm">
                                    <i class="fas fa-info-circle text-gray-600 text-base"></i>
                                    <span>Current Status: <?php echo htmlspecialchars($selected_doc['status']); ?>. No review actions are needed.</span>
                                </div>
                                <?php endif; ?></div>
                            </div>
                        <?php else: ?>
                            <div class="h-full flex flex-col items-center justify-center text-gray-400">
                                <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                    <i data-lucide="mouse-pointer-2" class="w-10 h-10"></i>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900">No Document Selected</h3>
                                <p class="text-sm">Select a document from the queue to begin review.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                </div>
            </div>

    <script src="../../assets/js/scanner-processor.js"></script>
    <script>
        function switchQueueTab(tab) {
            const newSec = document.getElementById('queue-section-new');
            const revSec = document.getElementById('queue-section-rev');
            const newBtn = document.getElementById('tab-new-btn');
            const revBtn = document.getElementById('tab-rev-btn');
            if (!newSec || !revSec || !newBtn || !revBtn) return;
            
            if (tab === 'new') {
                newSec.classList.remove('hidden');
                revSec.classList.add('hidden');
                newBtn.className = "flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-[#800000] text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5";
                revBtn.className = "flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5";
                localStorage.setItem('qmo-active-queue-tab', 'new');
            } else {
                newSec.classList.add('hidden');
                revSec.classList.remove('hidden');
                newBtn.className = "flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-transparent text-gray-500 hover:text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5";
                revBtn.className = "flex-1 py-2.5 text-xs font-bold text-center border-b-2 border-[#800000] text-[#800000] hover:bg-gray-100 transition focus:outline-none flex items-center justify-center gap-1.5";
                localStorage.setItem('qmo-active-queue-tab', 'rev');
            }
        }
        
        function restoreQueueTabState() {
            const activeTab = localStorage.getItem('qmo-active-queue-tab') || 'new';
            switchQueueTab(activeTab);
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            restoreQueueTabState();
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // Real-time auto-update review queue list without page refresh
        setInterval(async () => {
            try {
                const url = window.location.href;
                const cacheBuster = (url.indexOf('?') !== -1 ? '&' : '?') + '_t=' + Date.now();
                const res = await fetch(url + cacheBuster, { cache: 'no-store' });
                const text = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                
                const newQueue = doc.getElementById('reviewQueueContainer');
                const oldQueue = document.getElementById('reviewQueueContainer');
                const newCount = doc.getElementById('reviewQueueCount');
                const oldCount = document.getElementById('reviewQueueCount');
                const newTabs = doc.getElementById('reviewQueueTabs');
                const oldTabs = document.getElementById('reviewQueueTabs');
                
                if (newQueue && oldQueue) {
                    oldQueue.innerHTML = newQueue.innerHTML;
                }
                if (newCount && oldCount) {
                    oldCount.innerHTML = newCount.innerHTML;
                }
                if (newTabs && oldTabs) {
                    oldTabs.innerHTML = newTabs.innerHTML;
                }
                
                restoreQueueTabState();
                
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } catch (err) {
                console.error('Error auto-refreshing review queue:', err);
            }
        }, 5000);

    // --- MULTI-PAGE SCANNER MODULE ---
    var modalStream = modalStream || null;
    var pages = pages || [];
    var pageCounter = pageCounter || 0;

    function openScannerModal() {
        pages = [];
        pageCounter = 0;
        document.getElementById('scan-doc-name').value = '';
        document.getElementById('scan-office-id').value = '';
        document.getElementById('scan-doc-type-id').value = '';
        
        updatePageCount();
        renderGallery();
        
        const modal = document.getElementById('scannerModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        startModalCamera();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const updateScannerFilename = async () => {
            const officeSelect = document.getElementById('scan-office-id');
            const docTypeSelect = document.getElementById('scan-doc-type-id');
            const aySelect = document.getElementById('scan-ay');
            const semSelect = document.getElementById('scan-semester');
            
            if (!officeSelect || !docTypeSelect || !aySelect || !semSelect) return;
            
            const officeId = officeSelect.value;
            const docTypeId = docTypeSelect.value;
            const ay = aySelect.value;
            const sem = semSelect.value;
            
            const officeText = officeSelect.selectedIndex > 0 ? officeSelect.options[officeSelect.selectedIndex].text : '';
            const docTypeText = docTypeSelect.selectedIndex > 0 ? docTypeSelect.options[docTypeSelect.selectedIndex].text : '';
            
            if (officeText && docTypeText) {
                const officeCodeMatch = officeText.match(/\(([^)]+)\)/);
                const officeCode = officeCodeMatch ? officeCodeMatch[1] : 'UNIT';
                
                const docCodeMatch = docTypeText.match(/\(([^)]+)\)/);
                const docCode = docCodeMatch ? docCodeMatch[1] : 'DOC';
                
                const safeAy = ay.replace(/[^A-Za-z0-9_-]/g, '_');
                const safeSem = sem.replace(/[^A-Za-z0-9_-]/g, '_');
                
                let nextVersion = 1;
                try {
                    const response = await fetch(`review_submissions.php?action=get_next_version&office_id=${officeId}&doc_type_id=${docTypeId}&ay=${encodeURIComponent(ay)}&semester=${encodeURIComponent(sem)}`);
                    const resJson = await response.json();
                    if (resJson.success) {
                        nextVersion = resJson.next_version;
                    }
                } catch (e) {
                    console.error("Failed to query version number:", e);
                }
                
                let fileName = `${officeCode}_${docCode}_${safeAy}_${safeSem}`;
                if (nextVersion > 1) {
                    fileName += `_v${nextVersion}`;
                }
                document.getElementById('scan-doc-name').value = fileName;
            }
        };
        
        document.getElementById('scan-office-id')?.addEventListener('change', updateScannerFilename);
        document.getElementById('scan-doc-type-id')?.addEventListener('change', updateScannerFilename);
        document.getElementById('scan-ay')?.addEventListener('change', updateScannerFilename);
        document.getElementById('scan-semester')?.addEventListener('change', updateScannerFilename);
    });

    function closeScannerModal() {
        stopModalCamera();
        const modal = document.getElementById('scannerModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    async function startModalCamera() {
        const video = document.getElementById('modal-video');
        const helper = document.getElementById('scanner-helper');
        const standby = document.getElementById('camera-standby');
        const captureBtn = document.getElementById('modal-capture-btn');
        
        try {
            modalStream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'environment',
                    width: { ideal: 1920 },
                    height: { ideal: 1080 }
                } 
            });
            video.srcObject = modalStream;
            video.classList.remove('hidden');
            helper.classList.remove('hidden');
            standby.classList.add('hidden');
            captureBtn.removeAttribute('disabled');
        } catch (err) {
            console.error("Camera access failed: ", err);
            alert('Unable to access camera: ' + err.message);
            video.classList.add('hidden');
            helper.classList.add('hidden');
            standby.classList.remove('hidden');
            captureBtn.setAttribute('disabled', 'true');
        }
    }

    function stopModalCamera() {
        const video = document.getElementById('modal-video');
        const helper = document.getElementById('scanner-helper');
        const standby = document.getElementById('camera-standby');
        const captureBtn = document.getElementById('modal-capture-btn');
        
        if (modalStream) {
            modalStream.getTracks().forEach(track => track.stop());
            modalStream = null;
        }
        if (video) video.srcObject = null;
        if (video) video.classList.add('hidden');
        if (helper) helper.classList.add('hidden');
        if (standby) standby.classList.remove('hidden');
        if (captureBtn) captureBtn.setAttribute('disabled', 'true');
    }

    function capturePage() {
        const video = document.getElementById('modal-video');
        if (!video || video.paused || video.ended) return;
        
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Auto detect borders and warp to landscape rectangular sheet
        const corners = ScannerProcessor.detectDocumentCorners(canvas);
        const warpedCanvas = document.createElement('canvas');
        warpedCanvas.width = 1200;
        warpedCanvas.height = 848;
        ScannerProcessor.warpDocument(canvas, warpedCanvas, corners);
        
        const dataUrl = warpedCanvas.toDataURL('image/jpeg', 0.9);
        
        pageCounter++;
        pages.push({
            id: pageCounter,
            originalDataUrl: dataUrl,
            rotation: 0,
            enhanced: false
        });
        
        updatePageCount();
        renderGallery();
        
        const viewfinder = document.getElementById('scanner-viewfinder');
        if (viewfinder) {
            viewfinder.style.transition = 'none';
            viewfinder.style.backgroundColor = 'rgba(255, 255, 255, 0.8)';
            setTimeout(() => {
                viewfinder.style.transition = 'background-color 0.4s';
                viewfinder.style.backgroundColor = 'transparent';
            }, 50);
        }
    }

    function updatePageCount() {
        document.getElementById('page-count').textContent = pages.length + (pages.length === 1 ? ' Page' : ' Pages');
        const saveBtn = document.getElementById('save-scan-btn');
        if (pages.length === 0) {
            saveBtn.setAttribute('disabled', 'true');
        } else {
            saveBtn.removeAttribute('disabled');
        }
    }

    function deletePage(id) {
        pages = pages.filter(p => p.id !== id);
        updatePageCount();
        renderGallery();
    }

    function rotatePage(id) {
        const page = pages.find(p => p.id === id);
        if (page) {
            page.rotation = (page.rotation + 90) % 360;
            renderGallery();
        }
    }

    function toggleEnhance(id) {
        const page = pages.find(p => p.id === id);
        if (page) {
            page.enhanced = !page.enhanced;
            renderGallery();
        }
    }

    function renderPageCanvas(page, canvas, maxDimension = 300) {
        return new Promise((resolve) => {
            const img = new Image();
            img.src = page.originalDataUrl;
            img.onload = function() {
                const isRotated90or270 = page.rotation === 90 || page.rotation === 270;
                const origWidth = img.width;
                const origHeight = img.height;
                
                let width = origWidth;
                let height = origHeight;
                
                if (width > maxDimension || height > maxDimension) {
                    const scale = Math.min(maxDimension / width, maxDimension / height);
                    width = width * scale;
                    height = height * scale;
                }
                
                const canvasWidth = isRotated90or270 ? height : width;
                const canvasHeight = isRotated90or270 ? width : height;
                
                canvas.width = canvasWidth;
                canvas.height = canvasHeight;
                
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                ctx.save();
                ctx.translate(canvas.width / 2, canvas.height / 2);
                ctx.rotate((page.rotation * Math.PI) / 180);
                ctx.drawImage(img, -width / 2, -height / 2, width, height);
                ctx.restore();
                
                if (page.enhanced) {
                    ScannerProcessor.applySmartEnhancement(canvas);
                }
                resolve();
            };
        });
    }

    async function renderGallery() {
        const gallery = document.getElementById('pages-gallery');
        if (!gallery) return;
        
        if (pages.length === 0) {
            gallery.innerHTML = `
                <div class="h-full flex flex-col items-center justify-center text-gray-400 py-12">
                    <i class="fas fa-file-invoice text-3xl mb-2 text-gray-300"></i>
                    <p class="text-xs text-center">No pages scanned yet.<br>Click "Capture Page" to start adding pages.</p>
                </div>
            `;
            return;
        }
        
        gallery.innerHTML = '';
        
        for (let idx = 0; idx < pages.length; idx++) {
            const p = pages[idx];
            const item = document.createElement('div');
            item.className = "flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg group";
            item.innerHTML = `
                <div class="w-18 h-14 bg-black rounded overflow-hidden flex items-center justify-center border border-gray-300 flex-shrink-0">
                    <canvas id="thumb-canvas-${p.id}" class="w-full h-full object-contain"></canvas>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-bold text-gray-900">Page ${idx + 1}</p>
                    <div class="flex gap-1.5 mt-1.5">
                        <button type="button" onclick="rotatePage(${p.id})" class="p-1 bg-white hover:bg-gray-100 border border-gray-200 rounded text-gray-600 hover:text-[#800000] transition-colors text-[10px] font-bold inline-flex items-center gap-0.5" title="Rotate 90°">
                            <i class="fas fa-redo"></i> Rotate
                        </button>
                        <button type="button" onclick="toggleEnhance(${p.id})" class="p-1 ${p.enhanced ? 'bg-[#800000] text-white border border-[#800000] hover:bg-red-950' : 'bg-white hover:bg-gray-100 border border-gray-200 text-gray-600'} rounded transition-colors text-[10px] font-bold inline-flex items-center gap-0.5" title="Auto-Enhance Contrast">
                            <i class="fas fa-magic"></i> ${p.enhanced ? 'Enhanced' : 'Enhance'}
                        </button>
                    </div>
                </div>
                <button type="button" onclick="deletePage(${p.id})" class="p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors flex-shrink-0">
                    <i class="fas fa-trash-alt"></i>
                </button>
            `;
            gallery.appendChild(item);
            
            const canvas = document.getElementById(`thumb-canvas-${p.id}`);
            if (canvas) {
                await renderPageCanvas(p, canvas, 150);
            }
        }
    }

    async function stageAndSaveScan() {
        if (pages.length === 0) return;
        
        const officeId = document.getElementById('scan-office-id').value;
        const docTypeId = document.getElementById('scan-doc-type-id').value;
        const ay = document.getElementById('scan-ay').value;
        const semester = document.getElementById('scan-semester').value;

        if (!officeId) {
            alert('Please select an Office/Unit.');
            return;
        }
        if (!docTypeId) {
            alert('Please select a Document Type.');
            return;
        }
        
        const saveBtn = document.getElementById('save-scan-btn');
        const originalHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Compiling PDF...';
        
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('l', 'mm', 'a4');
            const pdfWidth = doc.internal.pageSize.getWidth();
            const pdfHeight = doc.internal.pageSize.getHeight();
            
            for (let idx = 0; idx < pages.length; idx++) {
                const p = pages[idx];
                if (idx > 0) {
                    doc.addPage();
                }
                
                const tempCanvas = document.createElement('canvas');
                await renderPageCanvas(p, tempCanvas, 1200);
                
                const imgData = tempCanvas.toDataURL('image/jpeg', 0.85);
                doc.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
            }
            
            let docName = document.getElementById('scan-doc-name').value.trim();
            if (!docName) {
                docName = 'Scanned_Doc_' + Math.floor(Date.now() / 1000);
            }
            if (!docName.toLowerCase().endsWith('.pdf')) {
                docName = docName.replace(/[^A-Za-z0-9_-]/g, '_') + '.pdf';
            }
            
            const pdfBlob = doc.output('blob');
            
            // Submit PDF to database via AJAX/FormData
            const formData = new FormData();
            formData.append('action', 'upload_scan');
            formData.append('academic_year', ay);
            formData.append('semester', semester);
            formData.append('office_id', officeId);
            formData.append('doc_type_id', docTypeId);
            formData.append('scanned_document', pdfBlob, docName);
            
            const response = await fetch('review_submissions.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success) {
                closeScannerModal();
                alert(data.message);
                window.location.reload(); // Reload page to reflect changes
            } else {
                alert('Failed to upload scanned document: ' + data.message);
            }
        } catch (err) {
            console.error("PDF generation/upload failed: ", err);
            alert('Error compiling/uploading document: ' + err.message);
        } finally {
            saveBtn.removeAttribute('disabled');
            saveBtn.innerHTML = originalHtml;
        }
    }
</script>

<!-- Floating Action Button for Scanning -->
<button id="floating-scan-btn" onclick="openScannerModal()" class="fixed bottom-6 right-6 w-14 h-14 bg-[#800000] hover:bg-red-900 text-white rounded-full flex items-center justify-center shadow-2xl z-40 transition-all duration-300 transform hover:scale-110 active:scale-95 group focus:outline-none no-print shadow-red-950/20" title="Scan Document">
    <i class="fas fa-camera text-xl group-hover:rotate-12 transition-transform duration-300"></i>
    <span class="absolute right-16 bg-gray-900 text-white text-xs px-2.5 py-1.5 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none whitespace-nowrap font-medium font-sans">Scan New Document</span>
</button>

<canvas id="enhance-canvas" class="hidden"></canvas>
        </main>
    </div>

<!-- Modern Scanner Modal Overlay (Moved outside main to cover sidebar) -->
<div id="scannerModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-[9999] p-4 md:p-6 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full h-[90vh] md:h-[85vh] flex flex-col overflow-hidden animate-slide-up">
        <!-- Modal Header -->
        <div class="px-6 py-4 bg-[#800000] text-white flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                <i class="fas fa-print text-lg"></i>
                <div>
                    <h3 class="font-bold text-sm md:text-base">Multi-Page Document Scanner</h3>
                    <p class="text-[10px] md:text-xs text-red-200">Scan multiple pages, apply rotation/contrast, and save as PDF</p>
                </div>
            </div>
            <button onclick="closeScannerModal()" class="text-white hover:text-gray-200 focus:outline-none p-1.5 rounded-lg hover:bg-red-900 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div class="flex-1 min-h-0 flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-gray-200 overflow-y-auto md:overflow-hidden">
            <!-- Camera Capture Area -->
            <div class="w-full md:flex-1 h-[260px] md:h-auto p-4 md:p-6 flex flex-col bg-gray-900 text-white relative flex-shrink-0">
                <div class="flex-1 flex items-center justify-center bg-black/40 rounded-lg overflow-hidden relative" id="scanner-viewfinder">
                    <video id="modal-video" autoplay playsinline muted class="w-full h-full object-contain hidden"></video>
                    <div id="scanner-helper" class="absolute inset-8 border-2 border-dashed border-white/50 rounded-lg pointer-events-none flex items-center justify-center hidden">
                        <span class="text-xs text-white/50 bg-black/60 px-3 py-1 rounded-full uppercase tracking-wider font-semibold">Align Document Here</span>
                    </div>
                    <div id="camera-standby" class="flex flex-col items-center gap-3">
                        <i class="fas fa-video-slash text-2xl md:text-4xl text-gray-500"></i>
                        <p class="text-xs md:text-sm text-gray-400 font-medium">Camera is offline</p>
                        <button onclick="startModalCamera()" class="px-3 py-1.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-[10px] md:text-xs font-bold transition shadow-md flex items-center gap-1.5">
                            <i class="fas fa-power-off"></i> Turn Camera On
                        </button>
                    </div>
                </div>
                
                <div class="mt-3 flex justify-center flex-shrink-0">
                    <button type="button" id="modal-capture-btn" onclick="capturePage()" class="px-5 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-xl text-xs font-bold transition shadow-lg flex items-center gap-2" disabled>
                        <i class="fas fa-camera text-sm"></i> Capture Page
                    </button>
                </div>
            </div>
            
            <!-- Staged Pages Area -->
            <div class="w-full md:w-96 p-4 md:p-6 flex flex-col justify-between bg-white min-h-0 flex-shrink-0 md:flex-shrink md:overflow-hidden">
                <div class="flex-shrink-0 flex flex-col min-h-0 md:flex-1 md:overflow-hidden">
                    <div class="flex items-center justify-between mb-3 flex-shrink-0">
                        <span class="text-[10px] md:text-xs font-bold text-gray-500 uppercase tracking-wider">Scanned Pages</span>
                        <span id="page-count" class="bg-gray-100 text-gray-800 text-xs px-2.5 py-1 rounded-full font-bold">0 Pages</span>
                    </div>
                    
                    <div id="pages-gallery" class="w-full h-40 md:h-auto md:flex-1 overflow-y-auto space-y-2 pr-1 border border-gray-100 rounded-lg p-2 bg-gray-50/50">
                        <div class="flex flex-col items-center justify-center text-gray-400 py-6">
                            <i class="fas fa-file-invoice text-xl mb-1 text-gray-300"></i>
                            <p class="text-[10px] text-center">No pages scanned yet.<br>Click "Capture Page" to start.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3 border-t border-gray-100 pt-3 flex-shrink-0 space-y-2.5 font-sans">
                    <h4 class="text-[10px] md:text-xs font-bold text-[#800000] uppercase tracking-wider">Document Parameters</h4>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                             <label class="block text-[9px] font-bold text-gray-500 uppercase mb-0.5">Academic Year</label>
                             <select id="scan-ay" class="w-full px-2 py-1 border border-gray-300 rounded-lg text-[10px] outline-none focus:ring-1 focus:ring-[#800000]">
                                 <?php foreach($all_academic_years as $ay): ?>
                                     <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $ay === $active_year ? 'selected' : ''; ?>><?php echo htmlspecialchars($ay); ?></option>
                                 <?php endforeach; ?>
                             </select>
                        </div>
                        
                        <div>
                             <label class="block text-[9px] font-bold text-gray-500 uppercase mb-0.5">Period / Semester</label>
                             <select id="scan-semester" class="w-full px-2 py-1 border border-gray-300 rounded-lg text-[10px] outline-none focus:ring-1 focus:ring-[#800000]">
                                 <?php foreach($all_semesters as $sem): ?>
                                     <option value="<?php echo htmlspecialchars($sem); ?>" <?php echo $sem === $active_semester ? 'selected' : ''; ?>><?php echo htmlspecialchars($sem); ?></option>
                                 <?php endforeach; ?>
                             </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                             <label class="block text-[9px] font-bold text-gray-500 uppercase mb-0.5">Office / Unit</label>
                             <select id="scan-office-id" class="w-full px-2 py-1 border border-gray-300 rounded-lg text-[10px] outline-none focus:ring-1 focus:ring-[#800000]">
                                 <option value="" disabled selected>-- Select Office --</option>
                                 <?php foreach($scanner_offices as $off): ?>
                                     <option value="<?php echo $off['office_id']; ?>"><?php echo htmlspecialchars($off['office_name'] . ' (' . $off['office_code'] . ')'); ?></option>
                                 <?php endforeach; ?>
                             </select>
                        </div>
    
                        <div>
                             <label class="block text-[9px] font-bold text-gray-500 uppercase mb-0.5">Document Type</label>
                             <select id="scan-doc-type-id" class="w-full px-2 py-1 border border-gray-300 rounded-lg text-[10px] outline-none focus:ring-1 focus:ring-[#800000]">
                                 <option value="" disabled selected>-- Select Type --</option>
                                 <?php foreach($scanner_doc_types as $dt): ?>
                                     <option value="<?php echo $dt['doc_type_id']; ?>"><?php echo htmlspecialchars($dt['description'] . ' (' . $dt['doc_code'] . ')'); ?></option>
                                 <?php endforeach; ?>
                             </select>
                        </div>
                    </div>

                    <div>
                         <label class="block text-[9px] font-bold text-gray-500 uppercase mb-0.5">Save Scanned Document As</label>
                         <div class="relative">
                             <input type="text" id="scan-doc-name" placeholder="e.g. QOP_Report" class="w-full px-2 py-1 border border-gray-300 rounded-lg text-[10px] outline-none focus:ring-1 focus:ring-[#800000] focus:border-transparent">
                             <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-gray-400 pointer-events-none">.pdf</span>
                         </div>
                    </div>
                    
                    <button type="button" onclick="stageAndSaveScan()" id="save-scan-btn" class="w-full py-2 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition shadow-lg flex items-center justify-center gap-2" disabled>
                        <i class="fas fa-file-pdf"></i> Compile PDF & Upload
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
        </div>
    </div>
</div>
</body>
</html>
