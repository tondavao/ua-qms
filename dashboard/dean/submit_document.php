<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";
require_once __DIR__ . "/../../includes/file-type-detector.php";
require_once __DIR__ . "/../../includes/convert-to-pdf.php";
require_once __DIR__ . "/../../includes/workflow-tracking.php";

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

// Initialize submission token to prevent double-post on refresh
if (!isset($_SESSION['submit_token'])) {
    $_SESSION['submit_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$office_id = $_SESSION['office_id'] ?? null;
$re_submit_id = $_POST['re_submit_id'] ?? $_GET['re-submit'] ?? null;
$re_submit_id = $re_submit_id ? (int)$re_submit_id : null;
$previous_version = null;
$maintenance_mode = false;
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];

$settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
if ($settings_res) {
    while ($settings_row = $settings_res->fetch_assoc()) {
        if ($settings_row['setting_key'] === 'maintenance_mode') {
            $maintenance_mode = $settings_row['setting_value'] === '1';
        }
    }
}

// CRITICAL FIX: Handle missing office_id
// If office_id is not set, attempt to retrieve it from the database
if (!$office_id) {
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $sql_user = "SELECT office_id FROM users WHERE user_id = ?";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $office_id = $user_row['office_id'];
            // Update session with retrieved office_id
            $_SESSION['office_id'] = $office_id;
        }
    }
}

$office_code = "";
if ($office_id) {
    $stmt_off = $conn->prepare("SELECT office_code FROM offices WHERE office_id = ?");
    $stmt_off->bind_param("i", $office_id);
    $stmt_off->execute();
    $res_off = $stmt_off->get_result()->fetch_assoc();
    if ($res_off) {
        $office_code = $res_off['office_code'];
    }
    $stmt_off->close();
}

// If re-submitting, fetch previous version details for Comparison Mode
if ($re_submit_id) {
    $sql_prev = "SELECT d.*, dt.doc_code, dt.description, dr.remarks 
                 FROM documents d 
                 JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                 LEFT JOIN document_reviews dr ON d.document_id = dr.document_id
                 WHERE d.document_id = ? AND d.office_id = ?
                 ORDER BY dr.review_id DESC LIMIT 1";
    $stmt_prev = $conn->prepare($sql_prev);
    $stmt_prev->bind_param("ii", $re_submit_id, $office_id);
    $stmt_prev->execute();
    $previous_version = $stmt_prev->get_result()->fetch_assoc();
}

// Fetch available document types
$doc_types = [];
$res_types = $conn->query("SELECT * FROM document_types ORDER BY doc_code ASC");
while($row = $res_types->fetch_assoc()) {
    $doc_types[] = $row;
}

// Handle Form Submission
$message = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check for duplicate submission via token
    if (!isset($_POST['submit_token']) || $_POST['submit_token'] !== $_SESSION['submit_token']) {
        $message = "Error: Duplicate submission detected or session expired.";
    } elseif ($maintenance_mode) {
        $message = "Error: Submissions are temporarily locked for system maintenance.";
    } else {
        // Refresh token for next submission
        $_SESSION['submit_token'] = bin2hex(random_bytes(32));
        
        $doc_type_id = $_POST['doc_type_id'];
        if ($previous_version) {
            $doc_type_id = $previous_version['doc_type_id'];
        }
        $file = $_FILES['document_file'];
    
    // Server-side validation
    $allowed_exts = ['pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $max_size = 10 * 1024 * 1024; // 10MB

    if (!in_array($file_ext, $allowed_exts)) {
        $message = "Error: Invalid file format. Only PDF, DOCX, XLSX, JPG, PNG are allowed.";
    } elseif ($file['size'] > $max_size) {
        $message = "Error: File size exceeds 10MB limit.";
    } else {
        // Process upload
        $safe_year = preg_replace('/[^A-Za-z0-9_-]/', '_', $active_year);
        $safe_quarter = preg_replace('/[^A-Za-z0-9_-]/', '_', $active_semester);
        
        $upload_dir = "../../uploads/documents/" . $safe_year . "/" . $safe_quarter . "/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $safe_original_name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
        $new_filename = time() . "_" . bin2hex(random_bytes(6)) . "_" . $safe_original_name;
        $target_path = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            // CRITICAL FIX: Enhanced office_id validation with detailed error handling
            if (!$office_id) {
                // Attempt to auto-assign default office if available
                $sql_default_office = "SELECT office_id FROM offices WHERE status = 'Active' LIMIT 1";
                $result_default = $conn->query($sql_default_office);
                if ($result_default && $result_default->num_rows > 0) {
                    $default_office = $result_default->fetch_assoc();
                    $office_id = $default_office['office_id'];
                    $_SESSION['office_id'] = $office_id;
                    $message = "Warning: Office ID was not set. Auto-assigned to default office. Please contact administrator to verify.";
                } else {
                    $message = "Error: Office ID not found in session and no default office available. Please contact administrator to set up your office assignment.";
                    // Log this critical error
                    error_log("CRITICAL: User {$_SESSION['user_id']} ({$_SESSION['full_name']}) attempted to submit document without office assignment.");
                }
            }
            
            if ($office_id) {
                $preview_path = NULL;
                $fileInfo = FileTypeDetector::detectFileType($target_path);
 
                // If it's an office document, convert to PDF
                if ($fileInfo['type'] === 'office') {
                    $preview_dir = "../../uploads/previews/" . $safe_year . "/" . $safe_quarter . "/";
                    if (!is_dir($preview_dir)) mkdir($preview_dir, 0777, true);
                    $converted_pdf_path = PdfConverter::convertToPdf($target_path, $preview_dir);
                    if ($converted_pdf_path) {
                        $preview_path = $converted_pdf_path;
                    } else {
                        // Log error, but still proceed with original file
                        error_log("Failed to convert Office document to PDF: " . $target_path);
                    }
                }

                // Detect duplicate/previous submission if not already set by re-submit
                if (!$previous_version) {
                    $sql_dup = "SELECT * FROM documents WHERE office_id = ? AND doc_type_id = ? AND academic_year = ? AND semester = ? AND status != 'Archived' ORDER BY document_id DESC LIMIT 1";
                    $stmt_dup = $conn->prepare($sql_dup);
                    $stmt_dup->bind_param("iiss", $office_id, $doc_type_id, $active_year, $active_semester);
                    $stmt_dup->execute();
                    $previous_version = $stmt_dup->get_result()->fetch_assoc();
                    $stmt_dup->close();
                }

                $can_link_revision = $previous_version
                    && ua_qms_column_exists($conn, 'documents', 'previous_document_id')
                    && ua_qms_column_exists($conn, 'documents', 'version_number');

                if ($can_link_revision) {
                    $previous_document_id = (int)$previous_version['document_id'];
                    $version_number = ((int)($previous_version['version_number'] ?? 1)) + 1;
                    $sql_insert = "INSERT INTO documents (office_id, doc_type_id, file_name, file_path, preview_path, status, previous_document_id, version_number, academic_year, semester) VALUES (?, ?, ?, ?, ?, 'Awaiting Review', ?, ?, ?, ?)";
                    $stmt_ins = $conn->prepare($sql_insert);
                    $stmt_ins->bind_param("iisssiiss", $office_id, $doc_type_id, $file['name'], $target_path, $preview_path, $previous_document_id, $version_number, $active_year, $active_semester);
                } else {
                    $sql_insert = "INSERT INTO documents (office_id, doc_type_id, file_name, file_path, preview_path, status, academic_year, semester) VALUES (?, ?, ?, ?, ?, 'Awaiting Review', ?, ?)";
                    $stmt_ins = $conn->prepare($sql_insert);
                    $stmt_ins->bind_param("iisssss", $office_id, $doc_type_id, $file['name'], $target_path, $preview_path, $active_year, $active_semester);
                }
                
                if ($stmt_ins->execute()) {
                    $message = "Success: Document submitted successfully!";
                    
                    // Get the newly inserted document ID for routing
                    $new_document_id = $stmt_ins->insert_id;

                    ua_qms_track_document_analytics($conn, $new_document_id, 'submission');
                    if ($can_link_revision) {
                        ua_qms_create_document_comparison($conn, $previous_document_id, $new_document_id, "Auto-linked from unit re-submission.");
                        // Also update previous document status to 'Archived' so it doesn't duplicate in active metrics
                        $sql_upd_prev = "UPDATE documents SET status = 'Archived' WHERE document_id = ?";
                        $stmt_upd_prev = $conn->prepare($sql_upd_prev);
                        $stmt_upd_prev->bind_param("i", $previous_document_id);
                        $stmt_upd_prev->execute();
                        $stmt_upd_prev->close();
                    }
                    
                    // Trigger Notification for QMO Admin
                    $office_name = "Unknown Unit";
                    $sql_off_name = "SELECT office_name FROM offices WHERE office_id = ?";
                    $stmt_off_name = $conn->prepare($sql_off_name);
                    $stmt_off_name->bind_param("i", $office_id);
                    $stmt_off_name->execute();
                    $off_res = $stmt_off_name->get_result()->fetch_assoc();
                    if ($off_res) $office_name = $off_res['office_name'];

                    $doc_type_code = "Document";
                    $sql_dt_code = "SELECT doc_code FROM document_types WHERE doc_type_id = ?";
                    $stmt_dt_code = $conn->prepare($sql_dt_code);
                    $stmt_dt_code->bind_param("i", $doc_type_id);
                    $stmt_dt_code->execute();
                    $dt_res = $stmt_dt_code->get_result()->fetch_assoc();
                    if ($dt_res) $doc_type_code = $dt_res['doc_code'];

                    if ($can_link_revision) {
                        $notif_title = "Revision Submitted: " . $office_name;
                        $notif_msg = "Version " . $version_number . " of " . $doc_type_code . " has been resubmitted and is awaiting review.";
                    } else {
                        $notif_title = "New Submission: " . $office_name;
                        $notif_msg = "A new " . $doc_type_code . " has been submitted and is awaiting review.";
                    }
                    
                    // Find QMO Admin users
                    $sql_qmo = "SELECT user_id FROM users WHERE role = 'QMO Admin' AND status = 'Active'";
                    $qmo_res = $conn->query($sql_qmo);
                    while ($qmo = $qmo_res->fetch_assoc()) {
                        // Create notification with routing metadata
                        $sql_notif = "INSERT INTO notifications (title, message, type, target_url, target_type, related_document_id) VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_notif = $conn->prepare($sql_notif);
                        $target_url = $can_link_revision ? "dashboard/qmo/repository.php" : ("dashboard/qmo/review_submissions.php?id=" . $new_document_id);
                        $target_type = $can_link_revision ? "repository" : "review";
                        $notif_type = "System";
                        $stmt_notif->bind_param("sssssi", $notif_title, $notif_msg, $notif_type, $target_url, $target_type, $new_document_id);
                        
                        if ($stmt_notif->execute()) {
                            $notification_id = $conn->insert_id;
                            $sql_user_notif = "INSERT INTO user_notifications (user_id, notification_id) VALUES (?, ?)";
                            $stmt_user_notif = $conn->prepare($sql_user_notif);
                            $stmt_user_notif->bind_param("ii", $qmo['user_id'], $notification_id);
                            $stmt_user_notif->execute();
                        }
                    }
                } else {
                    $message = "Error: Database error - " . $conn->error;
                }
            }
        } else {
            $message = "Error: Failed to upload file.";
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Document | UA QMS</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script>
        window.CURRENT_OFFICE_CODE = <?php echo json_encode($office_code); ?>;
        window.PREVIOUS_VERSION = <?php echo json_encode($previous_version); ?>;
        window.ACTIVE_YEAR = <?php echo json_encode($active_year); ?>;
        window.ACTIVE_SEMESTER = <?php echo json_encode($active_semester); ?>;
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="../../assets/js/scanner-processor.js"></script>
    <style>
        :root {
            --ua-maroon: #800000;
            --ua-gold: #FFD700;
            --ua-green: #006400;
        }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }
        .border-ua-maroon { border-color: var(--ua-maroon); }
        
        /* Modal Styles */
        #successModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-icon {
            font-size: 60px;
            color: var(--ua-green);
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: bold;
            color: var(--ua-maroon);
            margin-bottom: 10px;
        }

        .modal-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .modal-button {
            background-color: var(--ua-maroon);
            color: white;
            padding: 12px 40px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }

        .modal-button:hover {
            background-color: #600000;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <div class="flex h-screen overflow-hidden">
        <?php require_once __DIR__ . "/../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar with Profile Icon -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 md:px-8 flex-shrink-0 sticky top-0 z-50">
                <div class="flex items-center gap-3 min-w-0">
                    <h1 class="text-base md:text-lg font-bold text-gray-900 leading-tight truncate">Submit Document</h1>
                </div>
                <div class="flex items-center gap-6">
                    <!-- Clock -->
                    <div id="clock" class="text-sm text-gray-500 font-medium"></div>
                    <div class="flex items-center gap-2">
                        <!-- Message Inbox -->
                        <button id="inbox-btn" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-envelope text-lg"></i>
                            <span id="message-badge" class="absolute top-1 right-1 w-4 h-4 bg-ua-maroon text-white text-[10px] flex items-center justify-center rounded-full hidden">0</span>
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
                                <?php echo htmlspecialchars(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
                            <?php endif; ?>
                        </button>
                            <div id="profile-dropdown" class="profile-dropdown">
                                <div class="profile-dropdown-header">
                                    <p><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></p>
                                    <span><?php echo htmlspecialchars($_SESSION['role'] ?? 'Role'); ?></span>
                                </div>
                                <a href="../../logout.php" class="profile-dropdown-item logout">
                                    <i class="fas fa-sign-out-alt"></i> Sign Out
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="flex-1 overflow-auto p-4 md:p-8">


                <!-- Original Content Area -->
                <div class="w-full animate-fade-in">
                    <div class="app flex-col h-auto">
                        <main class="content flex-1">
                            
                            <?php if($message): ?>
                                <?php if(strpos($message, 'Success') !== false): ?>
                                    <script>
                                        if (document.readyState === 'loading') {
                                            document.addEventListener('DOMContentLoaded', function() {
                                                showSuccessModal('<?php echo htmlspecialchars($message, ENT_QUOTES); ?>');
                                            });
                                        } else {
                                            showSuccessModal('<?php echo htmlspecialchars($message, ENT_QUOTES); ?>');
                                        }
                                    </script>
                                <?php else: ?>
                                    <div class="p-4 mb-6 rounded-xl border text-sm font-medium flex items-center gap-3 bg-red-50 border-red-200 text-red-800 dismissible-alert">
                                        <i class="fas fa-times-circle text-lg flex-shrink-0"></i>
                                        <span><?php echo $message; ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- CRITICAL FIX: Display office assignment status -->
                            <?php if(!$office_id): ?>
                                <div class="p-4 mb-6 rounded-xl border border-red-200 bg-red-50 text-red-800 text-sm font-medium flex items-start gap-3">
                                    <i class="fas fa-times-circle text-lg mt-0.5 animate-pulse flex-shrink-0"></i>
                                    <div>
                                        <strong class="font-bold">Critical Error:</strong> Your office assignment is not configured. Please contact the QMO Administrator to assign you to an office before submitting documents.
                                    </div>
                                </div>
                            <?php elseif($maintenance_mode): ?>
                                <div class="p-4 mb-6 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 text-sm font-medium flex items-start gap-3">
                                    <i class="fas fa-exclamation-triangle text-lg mt-0.5 animate-bounce flex-shrink-0"></i>
                                    <div>
                                        <strong class="font-bold">Submissions Locked:</strong> The system is currently in maintenance mode. Please try again later.
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Office ID display removed per request -->
                            <?php endif; ?>

                            <?php if ($office_id && !$maintenance_mode): ?>
                            <div class="<?php echo $previous_version ? 'grid grid-cols-1 lg:grid-cols-2 gap-8' : 'max-w-3xl mx-auto'; ?>">
                                <!-- Left: Form -->
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col justify-between">
                                    <form action="submit_document.php<?php echo $re_submit_id ? '?re-submit=' . $re_submit_id : ''; ?>" method="POST" enctype="multipart/form-data" id="submissionForm" onsubmit="return preventDoubleSubmit();">
                                        <input type="hidden" name="submit_token" value="<?php echo $_SESSION['submit_token']; ?>">
                                        <?php if ($re_submit_id): ?>
                                            <input type="hidden" name="re_submit_id" value="<?php echo htmlspecialchars($re_submit_id); ?>">
                                        <?php endif; ?>
                                        
                                        <div class="mb-6 border-b border-gray-100 pb-4">
                                            <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                                <i class="fas <?php echo $previous_version ? 'fa-edit text-amber-600' : 'fa-paper-plane text-[#800000]'; ?>"></i>
                                                <?php echo $previous_version ? 'Submit Document Revision' : 'Submit New Document'; ?>
                                            </h2>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Active Period: <span class="font-semibold text-gray-700">AY <?php echo htmlspecialchars($active_year . ' · ' . $active_semester); ?></span>
                                            </p>
                                        </div>

                                        <!-- Selection Dropdown -->
                                        <div class="mb-6">
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Document Type</label>
                                            <?php if ($previous_version): ?>
                                                <div class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg text-gray-700 flex items-center justify-between shadow-sm">
                                                    <span class="text-sm font-semibold"><?php echo htmlspecialchars($previous_version['doc_code'] . ' - ' . ($previous_version['title'] ?? 'Document')); ?></span>
                                                    <span class="bg-amber-100 text-amber-800 text-[10px] uppercase px-2 py-0.5 rounded font-bold border border-amber-200 flex items-center gap-1">
                                                        <i class="fas fa-sync-alt animate-spin"></i> Revision
                                                    </span>
                                                </div>
                                                <input type="hidden" name="doc_type_id" value="<?php echo htmlspecialchars($previous_version['doc_type_id']); ?>">
                                            <?php else: ?>
                                                <div class="relative">
                                                    <select name="doc_type_id" id="doc_type_id" required class="w-full px-4 py-3 bg-white border border-gray-300 rounded-lg text-sm text-gray-800 focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none appearance-none transition-all pr-10 shadow-sm font-medium">
                                                        <option value="" disabled <?php echo !isset($_GET['doc_type_id']) ? 'selected' : ''; ?>>-- Select Document Type --</option>
                                                        <?php foreach($doc_types as $type): ?>
                                                            <option value="<?php echo $type['doc_type_id']; ?>" <?php echo (isset($_GET['doc_type_id']) && $_GET['doc_type_id'] == $type['doc_type_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($type['doc_code'] . ' - ' . $type['description']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                         <!-- Submission Method Tabs -->
                                         <div class="flex border-b border-gray-200 mb-6 bg-gray-50/50 rounded-lg p-1.5 gap-1.5 no-print">
                                             <button type="button" id="upload-tab-btn" onclick="switchSubmitTab('upload')" class="flex-1 py-2 text-xs font-bold text-center rounded-md bg-[#800000] text-white shadow-sm transition">
                                                 <i class="fas fa-file-upload mr-1"></i> Upload File
                                             </button>
                                             <button type="button" id="scan-tab-btn" onclick="switchSubmitTab('scan')" class="flex-1 py-2 text-xs font-bold text-center rounded-md hover:bg-gray-100 text-gray-600 transition">
                                                 <i class="fas fa-camera mr-1"></i> Scan Document
                                             </button>
                                         </div>

                                         <!-- Upload Tab Content -->
                                         <div id="upload-tab" class="tab-content block">
                                             <div id="dropzone" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-[#800000] hover:bg-red-50/10 transition-all cursor-pointer relative group shadow-sm bg-gray-50/50">
                                                 <input type="file" name="document_file" id="document_file" class="hidden">
                                                 
                                                 <div id="dropzone-prompt" class="space-y-4">
                                                     <div class="w-14 h-14 mx-auto bg-red-50 text-[#800000] rounded-full flex items-center justify-center group-hover:scale-110 transition-transform shadow-inner">
                                                         <i class="fas fa-cloud-upload-alt text-xl"></i>
                                                     </div>
                                                     <div>
                                                         <p class="text-sm font-bold text-gray-700">Drag & drop your file here, or <span class="text-[#800000] hover:underline font-extrabold">browse</span></p>
                                                         <p class="text-[11px] text-gray-400 mt-1">Accepts PDF, DOCX, XLSX, JPG, PNG up to 10MB</p>
                                                     </div>
                                                 </div>

                                                 <!-- Selected File Indicator -->
                                                 <div id="file-staged-container" class="hidden p-4 bg-green-50 border border-green-200 rounded-lg flex items-center justify-between shadow-sm">
                                                     <div class="flex items-center gap-3 text-green-700 min-w-0">
                                                         <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                                             <i class="fas fa-file-alt text-green-600"></i>
                                                         </div>
                                                         <div class="text-left min-w-0">
                                                             <p id="staged-file-name" class="text-xs font-bold truncate text-green-800">filename.pdf</p>
                                                             <p class="text-[10px] text-green-600 font-semibold">File staged successfully</p>
                                                         </div>
                                                     </div>
                                                     <button type="button" id="remove-file-btn" class="text-gray-400 hover:text-red-600 p-1.5 rounded-lg hover:bg-red-50 transition-colors" title="Clear selection">
                                                         <i class="fas fa-times-circle text-lg"></i>
                                                     </button>
                                                 </div>
                                             </div>
                                             <div id="fileError" class="mt-3 text-xs text-red-600 font-bold bg-red-50 border border-red-100 p-2.5 rounded-lg hidden flex items-center gap-1.5">
                                                 <i class="fas fa-exclamation-circle text-sm flex-shrink-0"></i>
                                                 <span id="file-error-text">Error message</span>
                                             </div>
                                         </div>

                                         <!-- Scan Tab Content -->
                                         <div id="scan-tab" class="tab-content hidden">
                                             <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center bg-gray-50/50 flex flex-col items-center justify-center gap-4">
                                                 <div class="w-14 h-14 bg-red-50 text-[#800000] rounded-full flex items-center justify-center shadow-inner">
                                                     <i class="fas fa-camera text-xl"></i>
                                                 </div>
                                                 <div>
                                                     <p class="text-sm font-bold text-gray-700">Scan via Webcam / Document Camera</p>
                                                     <p class="text-[11px] text-gray-400 mt-1">Capture page-by-page, enhance clarity, rotate, and stage directly as PDF.</p>
                                                 </div>
                                                 <button type="button" onclick="openScannerModal()" class="px-5 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition shadow flex items-center gap-1.5">
                                                     <i class="fas fa-power-off"></i> Open Scanner
                                                 </button>
                                             </div>
                                         </div>

                                        <!-- Action Button -->
                                        <div class="mt-8 border-t border-gray-100 pt-6 flex justify-end">
                                            <button type="submit" class="px-6 py-3 bg-[#800000] hover:bg-red-950 text-white text-sm font-bold rounded-lg shadow-md hover:shadow-lg transition duration-200 flex items-center gap-2 transform active:scale-95">
                                                <i class="fas fa-paper-plane text-xs"></i> Submit Document
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Right: Previous Version & Feedback -->
                                <?php if ($previous_version): ?>
                                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-6 flex flex-col justify-between">
                                    <div class="space-y-6">
                                        <div class="border-b border-gray-100 pb-4">
                                            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                                                <i class="fas fa-history text-gray-500"></i> Previous Submission Details
                                            </h3>
                                            <p class="text-xs text-gray-500 mt-1">Review QMO remarks and submission details.</p>
                                        </div>
                                         <!-- Feedback Callout -->
                                         <div class="p-4 bg-red-50 border-l-4 border-red-600 rounded-r-lg shadow-sm">
                                             <p class="text-xs font-bold text-red-800 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                                                 <i class="fas fa-exclamation-circle text-red-600 text-sm"></i> QMO Feedback Remarks
                                             </p>
                                             <p class="text-sm text-red-950 font-bold leading-relaxed italic">
                                                 "<?php echo htmlspecialchars($previous_version['remarks'] ?? 'No feedback remarks provided by reviewer.'); ?>"
                                             </p>
                                         </div>

                                         <!-- Clean Details Table -->
                                         <div class="space-y-3 bg-gray-50 p-4 rounded-xl border border-gray-150">
                                             <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Previous Version Metadata</h4>
                                             <div class="grid grid-cols-3 gap-2 text-xs py-2 border-b border-gray-200">
                                                 <span class="text-gray-500 font-medium col-span-1">File Name:</span>
                                                 <span class="text-gray-800 font-bold col-span-2 break-all" title="<?php echo htmlspecialchars($previous_version['file_name']); ?>">
                                                     <?php echo htmlspecialchars($previous_version['file_name']); ?>
                                                 </span>
                                             </div>
                                             <div class="grid grid-cols-3 gap-2 text-xs py-2 border-b border-gray-200">
                                                 <span class="text-gray-500 font-medium col-span-1">Doc Type:</span>
                                                 <span class="text-gray-800 font-extrabold col-span-2 text-[#800000] bg-red-50 border border-red-100 px-2 py-0.5 rounded inline-block w-fit">
                                                     <?php echo htmlspecialchars(($previous_version['description'] ?? 'Document') . ' (' . $previous_version['doc_code'] . ')'); ?>
                                                 </span>
                                             </div>
                                             <div class="grid grid-cols-3 gap-2 text-xs py-2 border-b border-gray-200">
                                                 <span class="text-gray-500 font-medium col-span-1">Version:</span>
                                                 <span class="text-amber-800 font-extrabold col-span-2 bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded inline-block w-fit shadow-inner">
                                                     Version <?php echo htmlspecialchars($previous_version['version_number'] ?? 1); ?>
                                                 </span>
                                             </div>
                                             <div class="grid grid-cols-3 gap-2 text-xs py-2">
                                                 <span class="text-gray-500 font-medium col-span-1">Period:</span>
                                                 <span class="text-gray-800 font-bold col-span-2">AY <?php echo htmlspecialchars($previous_version['academic_year'] . ' · ' . $previous_version['semester']); ?></span>
                                             </div>
                                         </div>
                                    </div>

                                    <!-- Guidelines Tip Box -->
                                    <div class="mt-6 p-4 bg-blue-50 border border-blue-100 rounded-xl flex gap-3 shadow-inner flex-shrink-0">
                                        <div class="text-blue-600 text-base flex-shrink-0 mt-0.5">
                                            <i class="fas fa-lightbulb"></i>
                                        </div>
                                        <div>
                                            <h5 class="text-xs font-bold text-blue-900 uppercase tracking-wider">Revision Guidelines</h5>
                                            <ul class="text-[11px] text-blue-700 space-y-1.5 list-disc pl-4 mt-2 leading-relaxed">
                                                <li>Verify that you have addressed all comments from the QMO admin.</li>
                                                <li>Your submission will automatically save as version <strong><?php echo htmlspecialchars(($previous_version['version_number'] ?? 1) + 1); ?></strong> and link to this history.</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                        </main>
                    </div>
                </div>

    <script>
        function switchTab(event, tabName) {
            event.preventDefault();
            
            // Toggle tab content visibility
            const uploadTab = document.getElementById('upload-tab');
            const scannerTab = document.getElementById('scanner-tab');
            
            if (tabName === 'upload') {
                uploadTab.classList.remove('hidden');
                scannerTab.classList.add('hidden');
            } else {
                uploadTab.classList.add('hidden');
                scannerTab.classList.remove('hidden');
            }
            
            // Reset all tab button styles
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('border-[#800000]', 'text-[#800000]', 'font-bold');
                btn.classList.add('border-transparent', 'text-gray-500', 'font-medium');
            });
            
            // Highlight selected tab button
            const activeBtn = event.target.closest('.tab-btn');
            if (activeBtn) {
                activeBtn.classList.remove('border-transparent', 'text-gray-500', 'font-medium');
                activeBtn.classList.add('border-[#800000]', 'text-[#800000]', 'font-bold');
            }
        }

        // File selection helper
        function handleFileSelection(file) {
            const errorEl = document.getElementById('fileError');
            const errorText = document.getElementById('file-error-text');
            const stagedContainer = document.getElementById('file-staged-container');
            const stagedFileName = document.getElementById('staged-file-name');
            const dropzonePrompt = document.getElementById('dropzone-prompt');
            
            if (!file) {
                stagedContainer.classList.add('hidden');
                if (dropzonePrompt) dropzonePrompt.classList.remove('hidden');
                return;
            }
            
            const maxSize = 10 * 1024 * 1024;
            const allowedExts = ['pdf', 'docx', 'xlsx', 'jpg', 'jpeg', 'png'];
            const fileExt = file.name.split('.').pop().toLowerCase();
            
            if (!allowedExts.includes(fileExt)) {
                if (errorEl && errorText) {
                    errorText.textContent = 'Invalid file format. Only PDF, DOCX, XLSX, JPG, PNG are allowed.';
                    errorEl.classList.remove('hidden');
                }
                stagedContainer.classList.add('hidden');
                if (dropzonePrompt) dropzonePrompt.classList.remove('hidden');
                document.getElementById('document_file').value = '';
            } else if (file.size > maxSize) {
                if (errorEl && errorText) {
                    errorText.textContent = 'File size exceeds 10MB limit.';
                    errorEl.classList.remove('hidden');
                }
                stagedContainer.classList.add('hidden');
                if (dropzonePrompt) dropzonePrompt.classList.remove('hidden');
                document.getElementById('document_file').value = '';
            } else {
                if (errorEl) errorEl.classList.add('hidden');
                if (stagedFileName) stagedFileName.textContent = file.name;
                stagedContainer.classList.remove('hidden');
                if (dropzonePrompt) dropzonePrompt.classList.add('hidden');
            }
        }

        // File validation listener
        document.getElementById('document_file')?.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                handleFileSelection(this.files[0]);
            } else {
                handleFileSelection(null);
            }
        });

        // Drag and Drop implementation
        var dropzone = dropzone || document.getElementById('dropzone');
        var fileInput = fileInput || document.getElementById('document_file');
        
        if (dropzone && fileInput) {
            // Click to browse
            dropzone.addEventListener('click', function(e) {
                if (e.target.closest('#file-staged-container') || e.target.closest('#remove-file-btn')) {
                    return;
                }
                fileInput.click();
            });
            
            // Dragover / Dragenter
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('border-[#800000]', 'bg-red-50/10');
                }, false);
            });
            
            // Dragleave / Drop
            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('border-[#800000]', 'bg-red-50/10');
                }, false);
            });
            
            // Drop file
            dropzone.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files && files.length > 0) {
                    fileInput.files = files;
                    handleFileSelection(files[0]);
                }
            }, false);
            
            // Remove file button
            const removeBtn = document.getElementById('remove-file-btn');
            if (removeBtn) {
                removeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    fileInput.value = '';
                    handleFileSelection(null);
                });
            }
        }

        // Scanner functions (Backward Compatible)
        var stream = stream || null;
        async function startScanner() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                const video = document.getElementById('scanner-video');
                if (video) {
                    video.srcObject = stream;
                }
                const scannerContainer = document.getElementById('scanner-container');
                if (scannerContainer) scannerContainer.style.display = 'block';
                const startBtn = document.getElementById('start-scanner-btn');
                if (startBtn) startBtn.style.display = 'none';
                const captureBtn = document.getElementById('capture-btn');
                if (captureBtn) captureBtn.style.display = 'block';
                const stopBtn = document.getElementById('stop-scanner-btn');
                if (stopBtn) stopBtn.style.display = 'block';
            } catch (err) {
                alert('Unable to access camera: ' + err.message);
            }
        }

        function stopScanner() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            const scannerContainer = document.getElementById('scanner-container');
            if (scannerContainer) scannerContainer.style.display = 'none';
            const startBtn = document.getElementById('start-scanner-btn');
            if (startBtn) startBtn.style.display = 'block';
            const captureBtn = document.getElementById('capture-btn');
            if (captureBtn) captureBtn.style.display = 'none';
            const stopBtn = document.getElementById('stop-scanner-btn');
            if (stopBtn) stopBtn.style.display = 'none';
        }

        function captureFrame() {
            const video = document.getElementById('scanner-video');
            const canvas = document.getElementById('scanner-canvas');
            if (!video || !canvas) return;
            const ctx = canvas.getContext('2d');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            
            const preview = document.getElementById('scanner-preview');
            if (preview) {
                preview.innerHTML = '<img src="' + canvas.toDataURL() + '" style="max-width: 100%; border-radius: 8px;">';
            }
            
            canvas.toBlob(blob => {
                const file = new File([blob], 'scanned_' + Date.now() + '.png', { type: 'image/png' });
                const dt = new DataTransfer();
                dt.items.add(file);
                document.getElementById('document_file').files = dt.files;
                handleFileSelection(file);
            });
            
            stopScanner();
        }

        // Display office info if available
        var officeId = officeId || <?php echo json_encode($office_id); ?>;
        if (officeId) {
            const officeInfo = document.getElementById('officeInfo');
            if (officeInfo) officeInfo.style.display = 'flex';
            const officeDisplay = document.getElementById('officeDisplay');
            if (officeDisplay) officeDisplay.textContent = 'Office ID: ' + officeId;
        }

        function switchSubmitTab(tab) {
            const uploadTab = document.getElementById('upload-tab');
            const scanTab = document.getElementById('scan-tab');
            const uploadBtn = document.getElementById('upload-tab-btn');
            const scanBtn = document.getElementById('scan-tab-btn');
            
            if (tab === 'upload') {
                if (uploadTab) uploadTab.classList.replace('hidden', 'block');
                if (scanTab) scanTab.classList.replace('block', 'hidden');
                
                if (uploadBtn) {
                    uploadBtn.classList.add('bg-[#800000]', 'text-white', 'shadow-sm');
                    uploadBtn.classList.remove('hover:bg-gray-100', 'text-gray-600');
                }
                if (scanBtn) {
                    scanBtn.classList.remove('bg-[#800000]', 'text-white', 'shadow-sm');
                    scanBtn.classList.add('hover:bg-gray-100', 'text-gray-600');
                }
            } else if (tab === 'scan') {
                if (uploadTab) uploadTab.classList.replace('block', 'hidden');
                if (scanTab) scanTab.classList.replace('hidden', 'block');
                
                if (scanBtn) {
                    scanBtn.classList.add('bg-[#800000]', 'text-white', 'shadow-sm');
                    scanBtn.classList.remove('hover:bg-gray-100', 'text-gray-600');
                }
                if (uploadBtn) {
                    uploadBtn.classList.remove('bg-[#800000]', 'text-white', 'shadow-sm');
                    uploadBtn.classList.add('hover:bg-gray-100', 'text-gray-600');
                }
            }
        }

        // Prevent double submission
        var isSubmitting = isSubmitting || false;
        function preventDoubleSubmit() {
            if (isSubmitting) return false;
            isSubmitting = true;
            const submitBtn = document.querySelector('#submissionForm button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }
            return true;
        }

        // Modal functions
        function showSuccessModal(message) {
            const modal = document.getElementById('successModal');
            const modalMessage = document.getElementById('modalMessage');
            modalMessage.textContent = message;
            modal.style.display = 'flex';
        }

        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            modal.style.display = 'none';
            // Redirect to my submissions page
            window.location.href = 'my_submissions.php';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('successModal');
            if (event.target === modal) {
                closeSuccessModal();
            }
        });

        // --- FLOATING MULTI-PAGE SCANNER MODULE ---
        var modalStream = modalStream || null;
        var pages = pages || [];
        var pageCounter = pageCounter || 0;

        function generateAutoFilename() {
            const officeCode = window.CURRENT_OFFICE_CODE || '';
            const activeYear = (window.ACTIVE_YEAR || '').replace(/[^A-Za-z0-9_-]/g, '_');
            const activeSem = (window.ACTIVE_SEMESTER || '').replace(/[^A-Za-z0-9_-]/g, '_');
            
            let baseName = '';
            
            // Check if it is a revision submission (Comparison Mode)
            if (window.PREVIOUS_VERSION) {
                const docCode = window.PREVIOUS_VERSION.doc_code || '';
                const nextVer = parseInt(window.PREVIOUS_VERSION.version_number || 1) + 1;
                baseName = `${officeCode}_${docCode}_${activeYear}_${activeSem}_v${nextVer}`;
            } else {
                // For new submission, check what document type is selected
                const docTypeSelect = document.getElementById('doc_type_id');
                if (docTypeSelect && docTypeSelect.value) {
                    const selectedOptionText = docTypeSelect.options[docTypeSelect.selectedIndex].text;
                    const docCode = selectedOptionText.split(' ')[0] || '';
                    baseName = `${officeCode}_${docCode}_${activeYear}_${activeSem}`;
                } else {
                    baseName = 'Scanned_Doc_' + Math.floor(Date.now() / 1000);
                }
            }
            
            // Deduplicate (check if this name is already staged in the files list)
            let finalName = baseName;
            const fileInput = document.getElementById('document_file');
            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                const existingName = fileInput.files[0].name.replace('.pdf', '');
                if (existingName === finalName) {
                    finalName += '_' + Math.floor(Math.random() * 1000);
                }
            }
            return finalName;
        }

        // Auto update filename when Document Type selector changes
        document.getElementById('doc_type_id')?.addEventListener('change', () => {
            const nameInput = document.getElementById('scan-doc-name');
            if (nameInput) {
                nameInput.value = generateAutoFilename();
            }
        });

        function openScannerModal() {
            pages = [];
            pageCounter = 0;
            document.getElementById('scan-doc-name').value = generateAutoFilename();
            updatePageCount();
            renderGallery();
            
            const modal = document.getElementById('scannerModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            startModalCamera();
        }

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
            video.srcObject = null;
            video.classList.add('hidden');
            helper.classList.add('hidden');
            standby.classList.remove('hidden');
            captureBtn.setAttribute('disabled', 'true');
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
                docName = docName.replace(/[^A-Za-z0-9_-]/g, '_') + '.pdf';
                
                const pdfBlob = doc.output('blob');
                const pdfFile = new File([pdfBlob], docName, { type: 'application/pdf' });
                
                const dt = new DataTransfer();
                dt.items.add(pdfFile);
                
                const fileInput = document.getElementById('document_file');
                fileInput.files = dt.files;
                
                // Update file staged container
                handleFileSelection(pdfFile);
                
                // Switch back to upload tab view
                const uploadTabBtn = document.getElementById('upload-tab-btn');
                if (uploadTabBtn) {
                    uploadTabBtn.click();
                }
                
                closeScannerModal();
                alert('Document scanned and staged successfully! You can now click "Submit Document" to complete submission.');
            } catch (err) {
                console.error("PDF generation failed: ", err);
                alert('Error compiling document: ' + err.message);
            } finally {
                saveBtn.removeAttribute('disabled');
                saveBtn.innerHTML = originalHtml;
            }
        }
    </script>

    <!-- Floating Action Button for Scanning -->
    <button id="floating-scan-btn" onclick="openScannerModal()" class="fixed bottom-6 right-6 w-14 h-14 bg-[#800000] hover:bg-red-900 text-white rounded-full flex items-center justify-center shadow-2xl z-40 transition-all duration-300 transform hover:scale-110 active:scale-95 group focus:outline-none" title="Scan Document">
        <i class="fas fa-camera text-xl group-hover:rotate-12 transition-transform duration-300"></i>
        <span class="absolute right-16 bg-gray-900 text-white text-xs px-2.5 py-1.5 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none whitespace-nowrap font-medium">Multi-Page Scanner</span>
    </button>

    <canvas id="enhance-canvas" class="hidden"></canvas>

    <div id="successModal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="modal-title">Success!</div>
            <div class="modal-message" id="modalMessage"></div>
            <button class="modal-button" onclick="closeSuccessModal()">OK</button>
        </div>
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
                        <p class="text-[10px] md:text-xs text-red-200">Scan multiple pages, apply rotation/contrast, and bundle as PDF</p>
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
                            <div class="h-full flex flex-col items-center justify-center text-gray-400 py-12">
                                <i class="fas fa-file-invoice text-3xl mb-2 text-gray-300"></i>
                                <p class="text-xs text-center">No pages scanned yet.<br>Click "Capture Page" to start adding pages.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3 border-t border-gray-100 pt-3 flex-shrink-0 space-y-3 font-sans">
                        <div>
                             <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Save Scanned Document As</label>
                             <div class="relative">
                                 <input type="text" id="scan-doc-name" placeholder="e.g. QOP_UnitReport" class="w-full px-2.5 py-1.5 border border-gray-300 rounded-lg text-xs outline-none focus:ring-1 focus:ring-[#800000] focus:border-transparent">
                                 <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-gray-400 pointer-events-none">.pdf</span>
                             </div>
                        </div>
                        
                        <button type="button" onclick="stageAndSaveScan()" id="save-scan-btn" class="w-full py-2 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition shadow-lg flex items-center justify-center gap-2" disabled>
                            <i class="fas fa-file-pdf"></i> Compile PDF & Stage
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

