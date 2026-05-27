<?php
session_start();
include "../../config/db.php";

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$dean_id = $_SESSION['user_id']; 
$dean_name = $_SESSION['full_name'];
$office_id = $_SESSION['office_id'] ?? 0;

// Load active academic year and active semester from system configurations
$sql_settings = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')";
$result_settings = $conn->query($sql_settings);
$settings = [];
while ($row = $result_settings->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_semester = $settings['active_semester'] ?? '1st Semester';

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

// Calculate completion rate (simplified)
$total_docs = $stats['approved'] + $stats['pending'] + $stats['revision'];
if($total_docs > 0) {
    $stats['completion_rate'] = round(($stats['approved'] / $total_docs) * 100);
}

// Fetch Upcoming Deadlines from target_submissions filtered by target year
$deadlines = [];
$sql_deadlines = "SELECT ts.deadline, dt.doc_code, dt.description 
                  FROM target_submissions ts 
                  JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id 
                  WHERE ts.year = ? AND ts.deadline >= CURDATE() 
                  ORDER BY ts.deadline ASC LIMIT 3";
$stmt_deadlines = $conn->prepare($sql_deadlines);
$stmt_deadlines->bind_param("i", $target_year);
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
$sql_ann = "SELECT a.*, u.full_name as author_name FROM announcements a JOIN users u ON a.posted_by = u.user_id ORDER BY a.created_at DESC LIMIT 3";
$res_ann = $conn->query($sql_ann);
if ($res_ann) {
    while($row = $res_ann->fetch_assoc()) $announcements[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Dashboard | UA QMS</title>
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
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <div class="flex h-screen overflow-hidden">
        <?php include "../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Unit Dashboard</h1>
                    <?php
                    $year_display = $active_year;
                    if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
                        $year_display = (string)max(array_map('intval', $matches[0]));
                    }
                    ?>
                    <span class="bg-red-50 text-[#800000] border border-red-100 px-3 py-1 rounded-full text-xs font-bold shadow-sm inline-flex items-center gap-1.5"><i class="fas fa-calendar-check"></i> Active Period: AY <?= htmlspecialchars($active_year) ?> &middot; <?= htmlspecialchars($active_semester) ?></span>
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
                            <button id="profile-btn" class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition">
                                <?php echo isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'; ?>
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
            <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
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



            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Revision Inbox -->
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm lg:col-span-2">
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

                <!-- Announcements -->
                <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                        <i class="fas fa-bullhorn text-ua-maroon"></i>
                        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Announcements</h2>
                    </div>
                    <div id="deanAnnouncementsBody" class="space-y-4">
                        <?php if(empty($announcements)): ?>
                            <p class="text-gray-500 text-sm text-center py-4">No new announcements.</p>
                        <?php else: ?>
                            <?php foreach($announcements as $ann): ?>
                                <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm relative overflow-hidden group">
                                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#800000]"></div>
                                    <div class="pl-2">
                                        <h3 class="font-bold text-gray-900 text-sm"><?php echo htmlspecialchars($ann['title']); ?></h3>
                                        <p class="text-xs text-gray-500 mt-1 mb-2">By <?php echo htmlspecialchars($ann['author_name']); ?> &bull; <?php echo date('M d, Y h:i A', strtotime($ann['created_at'])); ?></p>
                                        <p class="text-sm text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($ann['content']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
        lucide.createIcons();

        // Profile Dropdown Toggle
        const profileBtn = document.getElementById('profile-btn');
        const profileDropdown = document.getElementById('profile-dropdown');
        
        profileBtn.addEventListener('click', function() {
            profileDropdown.classList.toggle('active');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('#profile-btn') && !event.target.closest('#profile-dropdown')) {
                profileDropdown.classList.remove('active');
            }
        });

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
    </script>
    </div>

</body>
</html>
