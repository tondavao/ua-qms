<?php
session_start();
include "../../config/db.php";
require_once "../../includes/workflow-tracking.php";

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

// Load active academic year and active semester from system configurations
$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_semester = $settings['active_semester'] ?? '1st Quarter';
$active_year_display = $active_year;
if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
    $active_year_display = (string)max(array_map('intval', $matches[0]));
}

// Handle Approval, Rejection, or Hardcopy Scan upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $doc_id = $_POST['document_id'] ?? null;
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    
    if ($action === 'upload_scan') {
        $file = $_FILES['scanned_document'] ?? null;
        header('Content-Type: application/json');
        if (!$doc_id || !$file) {
            echo json_encode(['success' => false, 'message' => 'Invalid document or scan file.']);
            exit();
        } else {
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_ext !== 'pdf') {
                echo json_encode(['success' => false, 'message' => 'Only PDF documents are allowed for scanning.']);
                exit();
            } else {
                $sql_doc = "SELECT academic_year, semester FROM documents WHERE document_id = ?";
                $stmt_doc = $conn->prepare($sql_doc);
                $stmt_doc->bind_param("i", $doc_id);
                $stmt_doc->execute();
                $doc_res = $stmt_doc->get_result()->fetch_assoc();
                
                if (!$doc_res) {
                    echo json_encode(['success' => false, 'message' => 'Document not found.']);
                    exit();
                } else {
                    $safe_year = preg_replace('/[^A-Za-z0-9_-]/', '_', $doc_res['academic_year']);
                    $safe_quarter = preg_replace('/[^A-Za-z0-9_-]/', '_', $doc_res['semester']);
                    
                    $upload_dir = "../../uploads/documents/" . $safe_year . "/" . $safe_quarter . "/";
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    $new_filename = time() . "_" . bin2hex(random_bytes(6)) . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $target_path)) {
                        $sql_update = "UPDATE documents SET file_path = ?, file_name = ? WHERE document_id = ?";
                        $stmt_upd = $conn->prepare($sql_update);
                        $stmt_upd->bind_param("ssi", $target_path, $file['name'], $doc_id);
                        
                        if ($stmt_upd->execute()) {
                            ua_qms_track_document_analytics($conn, (int)$doc_id, 'hardcopy_scan');
                            echo json_encode(['success' => true, 'message' => 'Hardcopy document scanned and uploaded successfully!']);
                            exit();
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Database update failed.']);
                            exit();
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to save scan file.']);
                        exit();
                    }
                }
            }
        }
    }
    
    if (!$doc_id) {
        $message = "Error: No document selected for review.";
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

            if ($status === 'Approved') {
                // Tracking handles are now purely delegated to ua_qms_update_review_tracking() 
                // to avoid redundant queries and schema mismatch fatal errors.
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

// Fetch documents awaiting review for active period
$sql_queue = "SELECT d.*, dt.doc_code, dt.description as doc_type_desc, o.office_name, u.full_name as submitter_name 
              FROM documents d 
              JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
              JOIN offices o ON d.office_id = o.office_id
              LEFT JOIN users u ON o.office_id = u.office_id AND u.role = 'Dean'
              WHERE d.status = 'Awaiting Review'
                AND d.academic_year = ?
                AND d.semester = ?
                AND d.document_id IN (
                    SELECT MAX(document_id) 
                    FROM documents 
                    WHERE status = 'Awaiting Review' 
                      AND academic_year = ?
                      AND semester = ?
                    GROUP BY office_id, doc_type_id
                )
              ORDER BY d.submitted_at ASC";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
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
        <?php include "../../includes/sidebar_qmo.php"; ?>

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
                            <button id="profile-btn" class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition">
                                <?php echo isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'; ?>
                            </button>
                            <div id="profile-dropdown" class="profile-dropdown">
                                <div class="profile-dropdown-content">
                                    <p class="text-sm font-bold text-gray-800 px-4 py-2"><?php echo $_SESSION['full_name'] ?? 'User'; ?></p>
                                    <p class="text-xs text-gray-500 px-4 py-1"><?php echo $_SESSION['role'] ?? 'Role'; ?></p>
                                    <hr class="my-2">
                                    <a href="../../logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition">Logout</a>
                                </div>
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



            <div class="flex-1 overflow-y-auto p-4 md:p-8">
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 no-print">
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
                <div class="flex flex-col xl:flex-row gap-6 min-h-[calc(100vh-14rem)]">
                <!-- Queue Panel -->
                <div class="w-full xl:w-96 h-80 xl:h-auto bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col overflow-hidden flex-shrink-0 no-print">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h2 class="font-bold text-lg text-gray-900 flex items-center gap-2">
                            <i data-lucide="inbox" class="w-5 h-5 text-[#800000]"></i>
                            Review Queue
                            <span id="reviewQueueCount" class="ml-auto bg-[#800000] text-white text-xs px-2 py-1 rounded-full font-bold">
                                <?php echo $queue_count; ?>
                            </span>
                        </h2>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto" id="reviewQueueContainer">
                        <?php if ($queue_count === 0): ?>
                            <div class="p-8 text-center text-gray-500">
                                <i data-lucide="check-circle" class="w-12 h-12 mx-auto mb-2 text-green-500"></i>
                                
                                <p class="text-sm font-medium mt-1">Review Queue Empty</p>
                                <p class="text-xs">No documents awaiting review.</p>
                            </div>
                        <?php else: ?>
                            <?php while ($row = $result_queue->fetch_assoc()): ?>
                                <a href="review_submissions.php?id=<?php echo $row['document_id']; ?>" 
                                   class="block p-4 border-b border-gray-100 hover:bg-gray-50 transition cursor-pointer <?php echo ($selected_doc && $selected_doc['document_id'] == $row['document_id']) ? 'bg-blue-50 border-l-4 border-l-[#800000]' : ''; ?>">
                                    <div class="mb-2">
                                        <p class="text-xs font-bold text-gray-500 uppercase"><?php echo htmlspecialchars($row['doc_code']); ?> <?php if(isset($row['version_number']) && $row['version_number'] > 1) echo '<span class="bg-[#800000] text-white text-[10px] font-bold px-2 py-0.5 rounded-md ml-2 shadow-sm border border-red-900"><i class="fas fa-code-branch mr-1"></i>Version '.$row['version_number'].'</span>'; ?></p>
                                        <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($row['office_name']); ?></p>
                                    </div>
                                    <p class="text-xs text-gray-500">Submitted: <?php echo date('M d, Y', strtotime($row['submitted_at'])); ?></p>
                                </a>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Details Panel -->
                <div class="flex-1 bg-white rounded-lg shadow-sm border border-gray-200 flex flex-col overflow-hidden">
                    <div class="p-6 border-b border-gray-200 bg-gray-50">
                        <h2 class="text-xl font-bold text-gray-900">Document Details</h2>
                        <p class="text-sm text-gray-500 mt-1">Review and approve or request revisions</p>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto p-6">
                        <?php if ($selected_doc): ?>
                            <div class="space-y-8">
                                <!-- Document Info Card -->
                                <div class="bg-gray-50 rounded-xl p-6 border border-gray-200">
                                    <div class="flex justify-between items-start mb-6">
                                        <div>
                                            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded-full uppercase tracking-wider mb-2 inline-block">
                                                <?php echo htmlspecialchars(($selected_doc['doc_type_desc'] ?? 'Quality Document') . ' (' . $selected_doc['doc_code'] . ')'); ?>
                                            </span>
                                            <h3 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($selected_doc['office_name']); ?></h3>
                                        </div>
                                        <div class="text-right">
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
 
                                <!-- Review Form -->
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
        </main>
    </div>

    <script>
        lucide.createIcons();

        // Profile Dropdown Toggle
        const profileBtn = document.getElementById('profile-btn');
        const profileDropdown = document.getElementById('profile-dropdown');
        
        if (profileBtn && profileDropdown) {
            profileBtn.addEventListener('click', function() {
                profileDropdown.classList.toggle('active');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('#profile-btn') && !event.target.closest('#profile-dropdown')) {
                    profileDropdown.classList.remove('active');
                }
            });
        }

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
                if (newQueue && oldQueue) {
                    oldQueue.innerHTML = newQueue.innerHTML;
                }
                if (newCount && oldCount) {
                    oldCount.innerHTML = newCount.innerHTML;
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } catch (err) {
                console.error('Error auto-refreshing review queue:', err);
            }
        }, 5000);

    // --- MULTI-PAGE SCANNER MODULE ---
    let modalStream = null;
    let pages = [];
    let pageCounter = 0;

    function openScannerModal() {
        pages = [];
        pageCounter = 0;
        const docNameBase = <?php echo json_encode(isset($selected_doc) ? str_replace('.pdf', '', $selected_doc['file_name']) : 'Scanned_Doc'); ?>;
        document.getElementById('scan-doc-name').value = docNameBase + '_scanned';
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
        const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
        
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
                    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const data = imgData.data;
                    for (let i = 0; i < data.length; i += 4) {
                        const r = data[i];
                        const g = data[i+1];
                        const b = data[i+2];
                        let v = 0.2126 * r + 0.7152 * g + 0.0722 * b;
                        v = v > 120 ? 255 : v * 0.5;
                        data[i] = v;
                        data[i+1] = v;
                        data[i+2] = v;
                    }
                    ctx.putImageData(imgData, 0, 0);
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
                <div class="w-14 h-18 bg-black rounded overflow-hidden flex items-center justify-center border border-gray-300 flex-shrink-0">
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
            const doc = new jsPDF('p', 'mm', 'a4');
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
            
            // Submit PDF to database via AJAX/FormData
            const formData = new FormData();
            formData.append('action', 'upload_scan');
            formData.append('document_id', <?php echo json_encode($selected_doc['document_id'] ?? null); ?>);
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

<?php if ($selected_doc): ?>
<!-- Floating Action Button for Scanning -->
<button id="floating-scan-btn" onclick="openScannerModal()" class="fixed bottom-6 right-6 w-14 h-14 bg-[#800000] hover:bg-red-900 text-white rounded-full flex items-center justify-center shadow-2xl z-40 transition-all duration-300 transform hover:scale-110 active:scale-95 group focus:outline-none no-print" title="Scan Document">
    <i class="fas fa-camera text-xl group-hover:rotate-12 transition-transform duration-300"></i>
    <span class="absolute right-16 bg-gray-900 text-white text-xs px-2.5 py-1.5 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none whitespace-nowrap font-medium">Multi-Page Scanner</span>
</button>
<?php endif; ?>

<!-- Modern Scanner Modal Overlay -->
<div id="scannerModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50 p-4 md:p-6 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full h-[85vh] flex flex-col overflow-hidden animate-slide-up">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center text-[#800000] text-lg">
                    <i class="fas fa-print"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900">Multi-Page Document Scanner</h3>
                    <p class="text-xs text-gray-500">Scan multiple pages, apply rotation/contrast, and save as PDF</p>
                </div>
            </div>
            <button onclick="closeScannerModal()" class="text-gray-400 hover:text-gray-600 focus:outline-none p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div class="flex-1 min-h-0 flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-gray-200">
            <!-- Camera Capture Area -->
            <div class="flex-1 p-6 flex flex-col justify-between min-h-0 bg-gray-900 text-white relative">
                <div class="flex-1 flex items-center justify-center bg-black/40 rounded-lg overflow-hidden relative" id="scanner-viewfinder">
                    <video id="modal-video" autoplay playsinline muted class="w-full h-full object-contain hidden"></video>
                    <div id="scanner-helper" class="absolute inset-8 border-2 border-dashed border-white/50 rounded-lg pointer-events-none flex items-center justify-center hidden">
                        <span class="text-xs text-white/50 bg-black/60 px-3 py-1 rounded-full uppercase tracking-wider font-semibold">Align Document Here</span>
                    </div>
                    <div id="camera-standby" class="flex flex-col items-center gap-3">
                        <i class="fas fa-video-slash text-4xl text-gray-500"></i>
                        <p class="text-sm text-gray-400 font-medium">Camera is offline</p>
                        <button onclick="startModalCamera()" class="px-4 py-2 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition shadow-md flex items-center gap-1.5">
                            <i class="fas fa-power-off"></i> Turn Camera On
                        </button>
                    </div>
                </div>
                
                <div class="mt-4 flex gap-3 justify-center">
                    <button type="button" id="modal-capture-btn" onclick="capturePage()" class="px-6 py-3 bg-[#800000] hover:bg-red-900 text-white rounded-xl text-sm font-bold transition shadow-lg flex items-center gap-2" disabled>
                        <i class="fas fa-camera text-base"></i> Capture Page
                    </button>
                </div>
            </div>
            
            <!-- Staged Pages Area -->
            <div class="w-full md:w-96 p-6 flex flex-col justify-between bg-white min-h-0">
                <div class="flex-1 flex flex-col min-h-0">
                    <div class="flex items-center justify-between mb-4 flex-shrink-0">
                        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Scanned Pages</span>
                        <span id="page-count" class="bg-gray-100 text-gray-800 text-xs px-2.5 py-1 rounded-full font-bold">0 Pages</span>
                    </div>
                    
                    <div id="pages-gallery" class="flex-1 overflow-y-auto space-y-3 pr-1 min-h-0">
                        <div class="h-full flex flex-col items-center justify-center text-gray-400 py-12">
                            <i class="fas fa-file-invoice text-3xl mb-2 text-gray-300"></i>
                            <p class="text-xs text-center">No pages scanned yet.<br>Click "Capture Page" to start adding pages.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 border-t border-gray-100 pt-4 flex-shrink-0 space-y-4">
                    <div>
                         <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Save Scanned Document As</label>
                         <div class="relative">
                             <input type="text" id="scan-doc-name" placeholder="e.g. QOP_UnitReport" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
                             <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-gray-400 pointer-events-none">.pdf</span>
                         </div>
                    </div>
                    
                    <button type="button" onclick="stageAndSaveScan()" id="save-scan-btn" class="w-full py-3 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-sm font-bold transition shadow-lg flex items-center justify-center gap-2" disabled>
                        <i class="fas fa-file-pdf"></i> Compile PDF & Upload
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<canvas id="enhance-canvas" class="hidden"></canvas>
</body>
</html>
