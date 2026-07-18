<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$dean_id = $_SESSION['user_id']; 
$dean_name = $_SESSION['full_name'];
$office_id = $_SESSION['office_id'] ?? 0;

$user_office_name = "";
if ($office_id > 0) {
    $office_stmt = $conn->prepare("SELECT office_code FROM offices WHERE office_id = ?");
    $office_stmt->bind_param("i", $office_id);
    $office_stmt->execute();
    $office_res = $office_stmt->get_result();
    if ($office_res->num_rows > 0) {
        $office_row = $office_res->fetch_assoc();
        $user_office_name = $office_row['office_code'];
    }
    $office_stmt->close();
}

// Load active academic year and active semester from system configurations
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];

// Parse active period into date range
$year_parts = explode('-', $active_year);
$start_year = (int)$year_parts[0];
$end_year = isset($year_parts[1]) ? (int)$year_parts[1] : $start_year + 1;

if ($active_semester === '1st Quarter') {
    $start_date = "$start_year-01-01 00:00:00";
    $end_date = "$start_year-03-31 23:59:59";
    $target_year = $start_year;
} elseif ($active_semester === '2nd Quarter') {
    $start_date = "$start_year-04-01 00:00:00";
    $end_date = "$start_year-06-30 23:59:59";
    $target_year = $start_year;
} elseif ($active_semester === '3rd Quarter') {
    $start_date = "$start_year-07-01 00:00:00";
    $end_date = "$start_year-09-30 23:59:59";
    $target_year = $start_year;
} elseif ($active_semester === '4th Quarter') {
    $start_date = "$start_year-10-01 00:00:00";
    $end_date = "$start_year-12-31 23:59:59";
    $target_year = $start_year;
} elseif ($active_semester === '1st Semester') {
    $start_date = "$start_year-06-01 00:00:00";
    $end_date = "$start_year-12-31 23:59:59";
    $target_year = $start_year;
} elseif ($active_semester === '2nd Semester') {
    $start_date = "$end_year-01-01 00:00:00";
    $end_date = "$end_year-05-31 23:59:59";
    $target_year = $end_year;
} else { // default fallback
    $start_date = "$start_year-01-01 00:00:00";
    $end_date = "$start_year-12-31 23:59:59";
    $target_year = $start_year;
}

// Fetch Unit Statistics
$stats = [
    'completion_rate' => 0,
    'approved' => 0,
    'pending' => 0,
    'revision' => 0,
    'rejected' => 0
];

// Get document counts for active configured period
$sql_counts = "SELECT status, COUNT(*) as count FROM documents WHERE office_id = ? AND academic_year = ? AND semester = ? GROUP BY status";
$stmt = $conn->prepare($sql_counts);
$stmt->bind_param("iss", $office_id, $active_year, $active_semester);
$stmt->execute();
$result = $stmt->get_result();
while($row = $result->fetch_assoc()) {
    if($row['status'] == 'Approved') $stats['approved'] = $row['count'];
    if($row['status'] == 'Awaiting Review') $stats['pending'] = $row['count'];
    if($row['status'] == 'Revision') $stats['revision'] = $row['count'];
    if($row['status'] == 'Rejected') $stats['rejected'] = $row['count'];
}

// Calculate completion rate based on QMO compliance logic: (Approved Required Doc Types / Total Required Doc Types)
$res_req = $conn->query("SELECT COUNT(*) as total_req FROM document_types WHERE required = 'Yes'");
$total_required_docs = ($res_req && $row_req = $res_req->fetch_assoc()) ? (int)$row_req['total_req'] : 8;

$stmt_app = $conn->prepare("
    SELECT COUNT(DISTINCT d.doc_type_id) as approved_count 
    FROM documents d
    JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
    WHERE d.office_id = ? 
      AND d.status = 'Approved' 
      AND d.academic_year = ? 
      AND d.semester = ? 
      AND dt.required = 'Yes'
");
$stmt_app->bind_param("iss", $office_id, $active_year, $active_semester);
$stmt_app->execute();
$res_app = $stmt_app->get_result()->fetch_assoc();
$approved_required_count = (int)($res_app['approved_count'] ?? 0);
$stmt_app->close();

$stats['completion_rate'] = $total_required_docs > 0 ? round(($approved_required_count / $total_required_docs) * 100) : 0;

// Fetch Upcoming Deadlines from target_submissions filtered by target year
$deadlines = [];
$sql_deadlines = "SELECT ts.deadline, dt.doc_code, dt.description 
                  FROM target_submissions ts 
                  JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id 
                  WHERE ts.year = ? AND ts.deadline >= CURDATE() 
                  ORDER BY ts.deadline ASC LIMIT 3";
$stmt_deadlines = $conn->prepare($sql_deadlines);
$stmt_deadlines->bind_param("s", $active_year);
$stmt_deadlines->execute();
$res_deadlines = $stmt_deadlines->get_result();
while($row = $res_deadlines->fetch_assoc()) {
    $deadlines[] = $row;
}

// Fetch Revision Inbox (Documents with Revision status and latest comment)
$revisions = [];
$sql_revisions = "SELECT d.document_id, d.file_name, dt.doc_code, dr.remarks, dr.reviewed_at 
                  FROM documents d
                  JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
                  LEFT JOIN document_reviews dr ON d.document_id = dr.document_id
                  WHERE d.office_id = ? AND d.status = 'Revision'
                  AND dr.review_id = (SELECT MAX(review_id) FROM document_reviews WHERE document_id = d.document_id)
                  ORDER BY dr.reviewed_at DESC";
$stmt_rev = $conn->prepare($sql_revisions);
$stmt_rev->bind_param("i", $office_id);
$stmt_rev->execute();
$res_rev = $stmt_rev->get_result();
while($row = $res_rev->fetch_assoc()) {
    $revisions[] = $row;
}
// Fetch Announcements
$announcements = [];
$sql_ann = "SELECT a.*, u.full_name as author_name FROM announcements a LEFT JOIN users u ON a.posted_by = u.user_id ORDER BY a.created_at DESC LIMIT 3";
$res_ann = $conn->query($sql_ann);
if ($res_ann) {
    while($row = $res_ann->fetch_assoc()) $announcements[] = $row;
}

// Fetch missing required documents for the active period
$missing_documents = [];
$sql_missing = "
    SELECT dt.doc_type_id, dt.doc_code, dt.description 
    FROM document_types dt 
    WHERE dt.required = 'Yes' 
      AND dt.doc_type_id NOT IN (
          SELECT d.doc_type_id 
          FROM documents d 
          WHERE d.office_id = ? 
            AND d.academic_year = ? 
            AND d.semester = ? 
            AND d.status = 'Approved'
      )
    ORDER BY dt.doc_code ASC
";
$stmt_missing = $conn->prepare($sql_missing);
if ($stmt_missing) {
    $stmt_missing->bind_param("iss", $office_id, $active_year, $active_semester);
    $stmt_missing->execute();
    $res_missing = $stmt_missing->get_result();
    while ($row = $res_missing->fetch_assoc()) {
        $missing_documents[] = $row;
    }
    $stmt_missing->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($user_office_name ? $user_office_name . ' Dashboard' : 'Unit Dashboard') ?> | UA QMS</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/lucide@latest"></script>
        <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION["user_id"]); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION["role"]); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --ua-maroon: #800000;
            --ua-gold: #FFD700;
            --ua-green: #006400;
        }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }
        .border-ua-maroon { border-color: var(--ua-maroon); }
        @keyframes zoomIn {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .animate-zoom-in {
            animation: zoomIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <div class="flex h-screen overflow-hidden">
        <?php require_once __DIR__ . "/../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 md:px-8 flex-shrink-0 sticky top-0 z-50">
                <div class="flex items-center gap-3 min-w-0">
                    <h1 class="text-base md:text-lg font-bold text-gray-900 leading-tight truncate"><?= htmlspecialchars($user_office_name ? $user_office_name : 'Unit') ?><span class="hidden sm:inline"> Dashboard</span></h1>
                    <?php
                    $year_display = $active_year;
                    if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
                        $year_display = (string)max(array_map('intval', $matches[0]));
                    }
                    ?>
                    <span class="hidden xl:inline-flex bg-red-50 text-[#800000] border border-red-100 px-3 py-1 rounded-full text-xs font-bold shadow-sm items-center gap-1.5"><i class="fas fa-calendar-check"></i> Active Period: AY <?= htmlspecialchars($active_year) ?> &middot; <?= htmlspecialchars($active_semester) ?></span>
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

            <!-- Personal Compliance Dashboard -->
            <section class="grid grid-cols-2 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Completion Rate</p>
                    <p id="deanCompletionRate" class="mt-2 text-3xl font-bold text-gray-900"><?php echo $stats['completion_rate']; ?>%</p>
                    <div class="w-full bg-gray-200 h-2 rounded-full mt-3 overflow-hidden">
                        <div id="deanCompletionRateBar" class="h-full bg-green-600 rounded-full" style="width: <?php echo $stats['completion_rate']; ?>%"></div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Approved</p>
                    <p id="deanApprovedCount" class="mt-2 text-3xl font-bold text-green-700"><?php echo $stats['approved']; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Documents verified successfully</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Awaiting Review</p>
                    <p id="deanPendingCount" class="mt-2 text-3xl font-bold text-orange-600"><?php echo $stats['pending']; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Documents waiting for verification</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Revisions Needed</p>
                    <p id="deanRevisionCount" class="mt-2 text-3xl font-bold text-[#800000]"><?php echo $stats['revision']; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Documents needing updates</p>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Revision Inbox -->
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                        <i class="fas fa-exclamation-circle text-red-600"></i>
                        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Revision Inbox</h2>
                    </div>
                    <div id="deanRevisionInboxBody" class="overflow-y-auto" style="max-height: 400px;">
                        <?php if(empty($revisions)): ?>
                            <p style="text-align: center; padding: 20px; color: var(--text-muted);">No documents currently require revision. Great job!</p>
                        <?php else: ?>
                            <div class="revision-list">
                                <?php foreach($revisions as $rev): ?>
                                    <div class="activity" style="border-left: 4px solid #dc2626; padding-left: 15px; margin-bottom: 15px; background: #fffafa;">
                                        <div style="display: flex; justify-content: space-between;">
                                            <strong><?php echo htmlspecialchars($rev['doc_code'] . ' - ' . $rev['file_name']); ?></strong>
                                            <span class="badge badge-revision">Revision</span>
                                        </div>
                                        <p style="margin-top: 8px; font-size: 0.95rem;">
                                            <strong>Admin Comment:</strong> <span style="color: #b91c1c; font-weight: 600;"><?php echo htmlspecialchars($rev['remarks']); ?></span>
                                        </p>
                                        <div style="margin-top: 10px;">
                                            <a href="submit_document.php?re-submit=<?php echo $rev['document_id']; ?>" class="btn btn-primary" style="font-size: 0.8rem; padding: 5px 12px;">
                                                <i data-lucide="upload-cloud" style="width: 14px;"></i> Re-submit Version
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Deadlines -->
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                        <i class="fas fa-clock text-orange-500"></i>
                        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Deadlines</h2>
                    </div>
                    <div id="deanDeadlinesBody" class="space-y-4">
                        <?php if(empty($deadlines)): ?>
                            <p style="color: var(--text-muted);">No upcoming deadlines found.</p>
                        <?php else: ?>
                            <?php foreach($deadlines as $dl): ?>
                                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-weight: 600; color: var(--ua-maroon);"><?php echo $dl['doc_code']; ?></span>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($dl['deadline'])); ?></span>
                                    </div>
                                    <p style="font-size: 0.85rem; margin: 5px 0;"><?php echo htmlspecialchars($dl['description']); ?></p>
                                    <div class="countdown-timer" data-deadline="<?php echo $dl['deadline']; ?>">
                                        <div class="timer-unit"><span class="timer-value days">00</span><span class="timer-label">Days</span></div>
                                        <div class="timer-unit"><span class="timer-value hours">00</span><span class="timer-label">Hrs</span></div>
                                        <div class="timer-unit"><span class="timer-value mins">00</span><span class="timer-label">Min</span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Announcements -->
            <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-6">
                <div class="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                    <i class="fas fa-bullhorn text-ua-maroon"></i>
                    <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Announcements</h2>
                </div>
                <div id="deanAnnouncementsBody" class="space-y-4">
                    <?php if(empty($announcements)): ?>
                        <p class="text-gray-500 text-sm text-center py-4">No new announcements.</p>
                    <?php else: ?>
                        <?php foreach($announcements as $ann): ?>
                            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm relative overflow-hidden group flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                                <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#800000]"></div>
                                <div class="pl-2 flex-1">
                                    <h3 class="font-bold text-gray-900 text-sm"><?php echo htmlspecialchars($ann['title']); ?></h3>
                                    <p class="text-xs text-gray-500 mt-1 mb-2">By <?php echo htmlspecialchars($ann['author_name'] ?? 'Admin'); ?> &bull; <?php echo date('M d, Y h:i A', strtotime($ann['created_at'])); ?></p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($ann['content']); ?></p>
                                </div>
                                <?php if(!empty($ann['image_path'])): ?>
                                    <div class="flex-shrink-0 self-center md:self-auto">
                                        <img src="../../<?php echo htmlspecialchars($ann['image_path']); ?>" alt="Announcement Image" class="max-h-48 w-auto max-w-full md:max-w-[18rem] rounded-lg border object-contain cursor-pointer hover:opacity-90 transition" onclick="openLightboxModal('../../<?php echo htmlspecialchars($ann['image_path'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($ann['title'], ENT_QUOTES); ?>')">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Smart Missing Documents Checklist -->
            <?php if (!empty($missing_documents)): ?>
            <section class="bg-white border border-red-200 rounded-xl p-6 shadow-sm mb-6 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-[4px] bg-red-600"></div>
                <div class="flex items-center justify-between mb-4 border-b border-gray-100 pb-3">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
                        <div>
                            <h2 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Required Actions: Missing Submissions</h2>
                            <p class="text-xs text-gray-500 mt-0.5">The following required quality documents have not been submitted/approved for the current active period (<?php echo htmlspecialchars($active_year . ' · ' . $active_semester); ?>).</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 bg-red-50 text-red-700 text-xs font-semibold rounded-full border border-red-200"><?php echo count($missing_documents); ?> Missing</span>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($missing_documents as $doc): ?>
                        <div class="flex items-center justify-between p-3.5 bg-gray-50 border border-gray-200 rounded-lg hover:border-red-200 transition-colors group">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0 font-bold text-xs uppercase">
                                    <?php echo htmlspecialchars(substr($doc['doc_code'], 0, 3)); ?>
                                </div>
                                <div>
                                    <h4 class="font-bold text-gray-900 text-xs tracking-wide uppercase"><?php echo htmlspecialchars($doc['doc_code']); ?></h4>
                                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-1" title="<?php echo htmlspecialchars($doc['description']); ?>"><?php echo htmlspecialchars($doc['description']); ?></p>
                                </div>
                            </div>
                            <a href="submit_document.php?doc_type_id=<?php echo $doc['doc_type_id']; ?>" class="p-1.5 bg-[#800000] hover:bg-red-900 text-white rounded-md transition shadow-sm flex items-center gap-1 text-xs font-bold px-2.5" title="Quick Submit">
                                <i class="fas fa-upload text-[10px]"></i> Submit
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php else: ?>
            <section class="bg-white border border-green-200 rounded-xl p-6 shadow-sm mb-6 relative overflow-hidden">
                <div class="absolute top-0 left-0 right-0 h-[4px] bg-green-600"></div>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-green-50 text-green-600 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-check-circle text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-gray-800 uppercase tracking-wider">All Requirements Submitted!</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Your unit has successfully submitted and received approval for all required quality documents for the active period (<?php echo htmlspecialchars($active_year . ' · ' . $active_semester); ?>).</p>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>

    <!-- Toast Notification for Revisions -->
    <?php if(!empty($revisions)): ?>
    <div class="toast" id="revisionToast">
        <i data-lucide="bell-ring"></i>
        <div>
            <strong>Action Required!</strong><br>
            You have <?php echo count($revisions); ?> document(s) that need revision.
        </div>
        <button onclick="document.getElementById('revisionToast').style.display='none'" style="background:none; border:none; color:white; cursor:pointer; margin-left:10px;">✖</button>
    </div>
    <?php endif; ?>

    <script>
        // Initialize Lucide Icons
        

        // Countdown Timer Logic
        function updateCountdowns() {
            const timers = document.querySelectorAll('.countdown-timer');
            timers.forEach(timer => {
                const deadline = new Date(timer.dataset.deadline).getTime();
                const now = new Date().getTime();
                const distance = deadline - now;

                if (distance < 0) {
                    timer.innerHTML = "<span style='color: #dc2626; font-weight: bold;'>OVERDUE</span>";
                    return;
                }

                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));

                timer.querySelector('.days').innerText = days.toString().padStart(2, '0');
                timer.querySelector('.hours').innerText = hours.toString().padStart(2, '0');
                timer.querySelector('.mins').innerText = minutes.toString().padStart(2, '0');
            });
        }

        setInterval(updateCountdowns, 60000);
        updateCountdowns();
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // Auto-hide toast after 8 seconds
        setTimeout(() => {
            const toast = document.getElementById('revisionToast');
            if(toast) toast.style.display = 'none';
        }, 8000);

        // Silent background deadline reminders check
        fetch('../../api/cron_reminders.php?silent=1&_t=' + Date.now(), { cache: 'no-store' })
            .catch(err => console.error('Reminders check failed:', err));

        function escapeHtml(text) {
            return String(text ?? '').replace(/[&<>"']/g, function(char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            });
        }

        function animateValue(obj, start, end, duration) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const easeProgress = 1 - Math.pow(1 - progress, 4);
                obj.innerHTML = Math.floor(easeProgress * (end - start) + start);
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                } else {
                    obj.innerHTML = end;
                }
            };
            window.requestAnimationFrame(step);
        }

        function updateAnimatedText(id, value) {
            const element = document.getElementById(id);
            if (element) {
                const numValue = Number(String(value).replace('%', ''));
                if (!isNaN(numValue) && numValue >= 0) {
                    const startStr = element.innerText.replace('%', '');
                    const start = Number(startStr) || 0;
                    if (start !== numValue) {
                        const isPercentage = String(value).includes('%');
                        let startTimestamp = null;
                        const duration = 1000;
                        const step = (timestamp) => {
                            if (!startTimestamp) startTimestamp = timestamp;
                            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                            const easeProgress = 1 - Math.pow(1 - progress, 4);
                            const currentVal = Math.floor(easeProgress * (numValue - start) + start);
                            element.innerHTML = currentVal + (isPercentage ? '%' : '');
                            if (progress < 1) {
                                window.requestAnimationFrame(step);
                            } else {
                                element.innerHTML = value;
                            }
                        };
                        window.requestAnimationFrame(step);
                    }
                } else {
                    element.textContent = value;
                }
            }
        }

        async function refreshDeanDashboard() {
            try {
                const response = await fetch('dashboard_data.php?_t=' + Date.now(), { cache: 'no-store' });
                const data = await response.json();
                if (data.success) {
                    // Update stats
                    updateAnimatedText('deanCompletionRate', data.stats.completion_rate + '%');
                    
                    const completionRateBar = document.getElementById('deanCompletionRateBar');
                    if (completionRateBar) completionRateBar.style.width = data.stats.completion_rate + '%';
                    
                    updateAnimatedText('deanApprovedCount', data.stats.approved);
                    updateAnimatedText('deanPendingCount', data.stats.pending);
                    updateAnimatedText('deanRevisionCount', data.stats.revision);

                    const unitProgressBar = document.getElementById('unitComplianceProgressBar');
                    if (unitProgressBar) {
                        unitProgressBar.style.width = data.stats.completion_rate + '%';
                        unitProgressBar.textContent = data.stats.completion_rate + '%';
                    }

                    if (window.statusChart) {
                        window.statusChart.data.datasets[0].data = [
                            data.stats.approved,
                            data.stats.pending,
                            data.stats.revision,
                            data.stats.rejected || 0
                        ];
                        window.statusChart.update();
                    }
                    
                    // Update Revision Inbox
                    const inboxBody = document.getElementById('deanRevisionInboxBody');
                    if (inboxBody) {
                        if (data.revisions.length === 0) {
                            inboxBody.innerHTML = '<p style="text-align: center; padding: 20px; color: var(--text-muted);">No documents currently require revision. Great job!</p>';
                        } else {
                            let html = '<div class="revision-list">';
                            data.revisions.forEach(rev => {
                                html += `
                                    <div class="activity" style="border-left: 4px solid #dc2626; padding-left: 15px; margin-bottom: 15px; background: #fffafa;">
                                        <div style="display: flex; justify-content: space-between;">
                                            <strong>${escapeHtml(rev.doc_code)} - ${escapeHtml(rev.file_name)}</strong>
                                            <span class="badge badge-revision">Revision</span>
                                        </div>
                                        <p style="margin-top: 8px; font-size: 0.95rem;">
                                            <strong>Admin Comment:</strong> <span style="color: #b91c1c; font-weight: 600;">${escapeHtml(rev.remarks)}</span>
                                        </p>
                                        <div style="margin-top: 10px;">
                                            <a href="submit_document.php?re-submit=${encodeURIComponent(rev.document_id)}" class="btn btn-primary" style="font-size: 0.8rem; padding: 5px 12px;">
                                                <i data-lucide="upload-cloud" style="width: 14px;"></i> Re-submit Version
                                            </a>
                                        </div>
                                    </div>
                                `;
                            });
                            html += '</div>';
                            inboxBody.innerHTML = html;
                        }
                    }
                    
                    // Update Deadlines
                    const deadlinesBody = document.getElementById('deanDeadlinesBody');
                    if (deadlinesBody) {
                        if (data.deadlines.length === 0) {
                            deadlinesBody.innerHTML = '<p style="color: var(--text-muted);">No upcoming deadlines found.</p>';
                        } else {
                            let html = '';
                            data.deadlines.forEach(dl => {
                                const dlDate = new Date(dl.deadline).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                                html += `
                                    <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-weight: 600; color: var(--ua-maroon);">${escapeHtml(dl.doc_code)}</span>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">${dlDate}</span>
                                        </div>
                                        <p style="font-size: 0.85rem; margin: 5px 0;">${escapeHtml(dl.description)}</p>
                                        <div class="countdown-timer" data-deadline="${escapeHtml(dl.deadline)}">
                                            <div class="timer-unit"><span class="timer-value days">00</span><span class="timer-label">Days</span></div>
                                            <div class="timer-unit"><span class="timer-value hours">00</span><span class="timer-label">Hrs</span></div>
                                            <div class="timer-unit"><span class="timer-value mins">00</span><span class="timer-label">Min</span></div>
                                        </div>
                                    </div>
                                `;
                            });
                            deadlinesBody.innerHTML = html;
                        }
                    }
                    
                    // Update Toast
                    const toast = document.getElementById('revisionToast');
                    if (data.revisions.length > 0) {
                        if (!toast) {
                            const toastHtml = `
                                <div class="toast" id="revisionToast" style="display: flex;">
                                    <i data-lucide="bell-ring"></i>
                                    <div>
                                        <strong>Action Required!</strong><br>
                                        You have <span id="toastRevisionCount">${data.revisions.length}</span> document(s) that need revision.
                                    </div>
                                    <button onclick="document.getElementById('revisionToast').style.display='none'" style="background:none; border:none; color:white; cursor:pointer; margin-left:10px;">✖</button>
                                </div>
                            `;
                            document.body.insertAdjacentHTML('beforeend', toastHtml);
                        } else {
                            const span = document.getElementById('toastRevisionCount');
                            if (span) span.textContent = data.revisions.length;
                            toast.style.display = 'flex';
                        }
                    } else if (toast) {
                        toast.style.display = 'none';
                    }
                    
                    // Refresh lucide icons and countdown timers
                    lucide.createIcons();
                    updateCountdowns();
                }
            } catch (error) {
                console.error('Error refreshing dashboard:', error);
            }
        }
        
        // Start dashboard updates polling
        setInterval(refreshDeanDashboard, 5000);

        // Lightbox Modal Logic
        function openLightboxModal(src, title) {
            const modal = document.getElementById('imageLightboxModal');
            const img = document.getElementById('lightboxImage');
            const caption = document.getElementById('lightboxCaption');
            if (modal && img) {
                img.src = src;
                if (caption) {
                    caption.textContent = title || 'Announcement Image';
                }
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            }
        }

        function closeLightboxModal() {
            const modal = document.getElementById('imageLightboxModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = ''; // Restore background scrolling
            }
        }

        window.openLightboxModal = openLightboxModal;
        window.closeLightboxModal = closeLightboxModal;
    </script>
    </div>

    <!-- Lightbox Image Modal -->
    <div id="imageLightboxModal" class="fixed inset-0 bg-black/85 hidden items-center justify-center z-[9999] p-4 backdrop-blur-sm transition-all duration-300" onclick="closeLightboxModal()">
        <div class="relative max-w-4xl max-h-[90vh] flex flex-col items-center justify-center animate-zoom-in" onclick="event.stopPropagation()">
            <button onclick="closeLightboxModal()" class="absolute -top-10 right-0 text-white hover:text-gray-300 text-3xl font-extrabold focus:outline-none transition-colors" title="Close">
                &times;
            </button>
            <img id="lightboxImage" src="" alt="View Announcement Image" class="max-w-full max-h-[75vh] rounded-lg shadow-2xl object-contain border border-white/10">
            <p id="lightboxCaption" class="text-white text-xs md:text-sm font-bold mt-4 text-center select-none bg-black/50 px-4 py-1.5 rounded-full border border-white/10"></p>
        </div>
    </div>

</body>
</html>
