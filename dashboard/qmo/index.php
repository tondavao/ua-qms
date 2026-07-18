<?php
try {
    session_start();
    require_once __DIR__ . "/../../config/db.php";
    require_once __DIR__ . "/../../includes/system_settings.php";
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
        header("Location: ../../index.php");
        exit();
    }
    
    $conn->set_charset("utf8mb4");
    
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

function normalize_year($value) {
    if (preg_match_all('/\d{4}/', (string)$value, $matches)) {
        return (string)max(array_map('intval', $matches[0]));
    }
    return date('Y');
}

function dashboard_quarter_bounds($year, $quarter) {
    switch ($quarter) {
        case '1st Quarter': return ["$year-01-01", "$year-03-31", 1, 3];
        case '2nd Quarter': return ["$year-04-01", "$year-06-30", 4, 6];
        case '3rd Quarter': return ["$year-07-01", "$year-09-30", 7, 9];
        case '4th Quarter': return ["$year-10-01", "$year-12-31", 10, 12];
        default: return ["$year-01-01", "$year-12-31", 1, 12];
    }
}

$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_quarter_setting = $period['active_semester'];
$active_year_display = $active_year;

$academic_years = [];
$academic_years_result = $conn->query("SELECT year_range FROM academic_years ORDER BY year_range DESC");
if ($academic_years_result) {
    while ($row = $academic_years_result->fetch_assoc()) {
        $academic_years[] = $row['year_range'];
    }
}
if (!$academic_years) {
    $academic_years = [$active_year];
}
$academic_years = array_values(array_unique($academic_years));

$quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];
$selected_year = $_GET['year'] ?? $active_year;
$selected_quarter = in_array(($_GET['quarter'] ?? ''), $quarters, true) ? $_GET['quarter'] : $active_quarter_setting;
$selected_ay_range = $selected_year;

$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'revision' => 0, 'rejected' => 0];
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) AS revision,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected
    FROM documents
    WHERE academic_year = ? AND semester = ?
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$stats = array_merge($stats, $stmt->get_result()->fetch_assoc() ?: []);
$stmt->close();

$overall_compliance = 0;
$stmt = $conn->prepare("
    SELECT ROUND(AVG(rate)) AS avg_rate
    FROM (
        SELECT
            o.office_id,
            (
                COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END)
                / NULLIF(COUNT(DISTINCT dt.doc_type_id), 0)
            ) * 100 AS rate
        FROM offices o
        CROSS JOIN document_types dt
        LEFT JOIN documents d
            ON d.office_id = o.office_id
            AND d.doc_type_id = dt.doc_type_id
            AND d.academic_year = ?
            AND d.semester = ?
        WHERE o.status = 'Active' AND o.is_archived = FALSE AND dt.required = 'Yes'
        GROUP BY o.office_id
    ) office_rates
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$overall_compliance = (int)($stmt->get_result()->fetch_assoc()['avg_rate'] ?? 0);
$stmt->close();

$review_queue = [];
$stmt = $conn->prepare("
    SELECT d.document_id, d.file_name, d.submitted_at, o.office_name, o.office_code, dt.doc_code
    FROM documents d
    JOIN offices o ON d.office_id = o.office_id
    JOIN document_types dt ON d.doc_type_id = dt.doc_type_id
    WHERE d.academic_year = ?
        AND d.semester = ?
        AND d.status = 'Awaiting Review'
    ORDER BY d.submitted_at ASC
    LIMIT 8
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $review_queue[] = $row;
}
$stmt->close();

$office_attention = [];
$stmt = $conn->prepare("
    SELECT
        o.office_name,
        o.office_code,
        COUNT(DISTINCT dt.doc_type_id) AS required_docs,
        COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END) AS approved_docs,
        SUM(CASE WHEN d.status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending_docs,
        SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) AS revision_docs,
        SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_docs,
        ROUND(
            (
                COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END)
                / NULLIF(COUNT(DISTINCT dt.doc_type_id), 0)
            ) * 100
        ) AS compliance_rate
    FROM offices o
    CROSS JOIN document_types dt
    LEFT JOIN documents d
        ON d.office_id = o.office_id
        AND d.doc_type_id = dt.doc_type_id
        AND d.academic_year = ?
        AND d.semester = ?
    WHERE o.status = 'Active' AND o.is_archived = FALSE AND dt.required = 'Yes'
    GROUP BY o.office_id, o.office_name, o.office_code
    ORDER BY compliance_rate ASC, (pending_docs + revision_docs + rejected_docs) DESC, o.office_name ASC
    LIMIT 8
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $office_attention[] = $row;
}
$stmt->close();

$upcoming_deadlines = [];
$stmt = $conn->prepare("
    SELECT ts.deadline, ts.quarter, dt.doc_code, dt.description
    FROM target_submissions ts
    JOIN document_types dt ON ts.doc_type_id = dt.doc_type_id
    WHERE ts.year = ?
    ORDER BY ts.deadline ASC
    LIMIT 6
");
$stmt->bind_param("s", $selected_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $upcoming_deadlines[] = $row;
}
$stmt->close();

    $needs_action = (int)$stats['pending'] + (int)$stats['revision'] + (int)$stats['rejected'];
} catch (Throwable $e) {
    file_put_contents(__DIR__ . '/error.txt', $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    throw $e;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QMO Overview | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ua-maroon: #800000; }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }

    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 font-sans" data-user-role="<?php echo h($_SESSION['role']); ?>">
<script>localStorage.setItem('userRole', '<?php echo h($_SESSION['role']); ?>');</script>
<div class="flex h-screen overflow-hidden">
    <?php require_once __DIR__ . "/../../includes/sidebar_qmo.php"; ?>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
            <div class="flex items-center gap-4">
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">QMO Overview Dashboard</h1>
                    <p class="text-sm text-gray-500"><?php echo h($selected_year); ?> &middot; <?php echo h($selected_quarter); ?></p>
                </div>
                <span class="bg-red-50 text-[#800000] border border-red-100 px-3 py-1 rounded-full text-xs font-bold shadow-sm inline-flex items-center gap-1.5"><i class="fas fa-calendar-check"></i> Active Period: AY <?php echo htmlspecialchars($active_year_display); ?> &middot; <?php echo htmlspecialchars($active_quarter_setting); ?></span>
            </div>
            <div class="flex items-center gap-6">
                <div id="clock" class="text-sm text-gray-500 font-medium"></div>
                <div class="flex items-center gap-2">
                    <button id="inbox-btn" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                        <i class="fas fa-envelope text-lg"></i>
                        <span id="message-badge" class="absolute top-1 right-1 w-4 h-4 bg-ua-maroon text-white text-[10px] flex items-center justify-center rounded-full hidden">0</span>
                    </button>
                    <button id="notification-bell" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                        <i class="fas fa-bell text-lg"></i>
                        <span id="notification-badge" class="absolute top-1 right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center hidden">0</span>
                    </button>
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
                                <p class="font-semibold"><?php echo h($_SESSION['full_name'] ?? 'User'); ?></p>
                                <span class="text-sm text-gray-500"><?php echo h($_SESSION['role'] ?? 'Role'); ?></span>
                            </div>
                            <a href="../../logout.php" class="profile-dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-4 md:p-8 space-y-6">
            <div class="bg-white p-4 rounded-xl border border-gray-200/80 shadow-sm flex flex-col md:flex-row gap-4 items-stretch md:items-center justify-between">
                <form method="GET" class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center w-full md:w-auto">
                    <select name="year" onchange="this.form.submit()" class="w-full sm:w-44 border border-gray-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-red-200 text-sm">
                        <?php foreach ($academic_years as $year_option): ?>
                            <option value="<?php echo h($year_option); ?>" <?php echo $selected_year === $year_option ? 'selected' : ''; ?>>
                                <?php echo h($year_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="quarter" onchange="this.form.submit()" class="w-full sm:w-44 border border-gray-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-red-200 text-sm">
                        <?php foreach ($quarters as $quarter_option): ?>
                            <option value="<?php echo h($quarter_option); ?>" <?php echo $selected_quarter === $quarter_option ? 'selected' : ''; ?>>
                                <?php echo h($quarter_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-[#800000] text-white px-4 py-2 sm:py-1.5 rounded-lg hover:bg-red-900 transition text-xs font-semibold h-[40px] sm:h-[38px] flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-filter mr-2 text-[10px]"></i>Apply
                    </button>
                </form>
                <div class="flex bg-white p-1 rounded-lg border border-gray-200 shadow-sm w-full md:w-auto justify-center">
                    <a href="index.php?year=<?php echo urlencode($selected_year); ?>&quarter=<?php echo urlencode($selected_quarter); ?>" class="flex-1 md:flex-initial text-center px-4 py-2 font-bold text-xs rounded-md bg-[#800000] text-white">Overview</a>
                    <a href="analytics_dashboard.php?year=<?php echo urlencode($selected_year); ?>&quarter=<?php echo urlencode($selected_quarter); ?>" class="flex-1 md:flex-initial text-center px-4 py-2 font-bold text-xs rounded-md text-gray-500 hover:text-gray-700">Detailed Analytics</a>
                </div>
            </div>

            <section class="grid grid-cols-2 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Overall Compliance</p>
                    <p id="overallCompliance" class="mt-2 text-3xl font-bold text-gray-900"><?php echo $overall_compliance; ?>%</p>
                    <p class="mt-2 text-xs text-gray-500">Approved required documents across active offices</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Needs QMO Action</p>
                    <p id="needsActionCount" class="mt-2 text-3xl font-bold text-[#800000]"><?php echo $needs_action; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Pending, revision, and rejected submissions</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Awaiting Review</p>
                    <p id="pendingCount" class="mt-2 text-3xl font-bold text-orange-600"><?php echo (int)$stats['pending']; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Documents waiting for validation</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Approved</p>
                    <p id="approvedCount" class="mt-2 text-3xl font-bold text-green-700"><?php echo (int)$stats['approved']; ?></p>
                    <p id="totalSubmissionsText" class="mt-2 text-xs text-gray-500"><?php echo (int)$stats['total']; ?> total submissions this period</p>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white border border-gray-200 rounded-lg p-6">
                    <h2 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-chart-pie text-[#800000]"></i> Document Status Breakdown</h2>
                    <div style="position: relative; height: 220px;" class="w-full">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-6">
                    <h2 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fas fa-chart-bar text-[#800000]"></i> Unit Compliance Progress</h2>
                    <div style="position: relative; height: 220px;" class="w-full">
                        <canvas id="complianceChart"></canvas>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 bg-white border border-gray-200 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-gray-900">Review Queue</h2>
                        <a href="review_submissions.php" class="text-sm font-semibold text-[#800000] hover:underline">Open reviews</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-gray-500">
                                    <th class="py-3 pr-4 font-semibold">Document</th>
                                    <th class="py-3 pr-4 font-semibold">Office</th>
                                    <th class="py-3 pr-4 font-semibold">Submitted</th>
                                    <th class="py-3 text-right font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody id="reviewQueueBody" class="divide-y divide-gray-100">
                                <?php if (!$review_queue): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-gray-500">No documents are awaiting review.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($review_queue as $item): ?>
                                    <tr>
                                        <td class="py-3 pr-4">
                                            <div class="font-semibold text-gray-900"><?php echo h($item['doc_code']); ?></div>
                                            <div class="text-xs text-gray-500 truncate max-w-[260px]"><?php echo h($item['file_name']); ?></div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <div class="font-medium text-gray-800"><?php echo h($item['office_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo h($item['office_code']); ?></div>
                                        </td>
                                        <td class="py-3 pr-4 text-gray-600"><?php echo h(date('M d, Y', strtotime($item['submitted_at']))); ?></td>
                                        <td class="py-3 text-right">
                                            <a href="review_submissions.php?id=<?php echo (int)$item['document_id']; ?>" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[#800000] text-white font-semibold">
                                                <i class="fas fa-eye"></i> Review
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded-lg p-6">
                    <h2 class="text-base font-bold text-gray-900 mb-4">Deadlines This Period</h2>
                    <div id="deadlinesList" class="space-y-3">
                        <?php if (!$upcoming_deadlines): ?>
                            <p class="text-sm text-gray-500">No target deadlines configured for this period.</p>
                        <?php endif; ?>
                        <?php foreach ($upcoming_deadlines as $deadline): ?>
                            <div class="border border-gray-200 rounded-lg p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-bold text-gray-900"><?php echo h($deadline['doc_code']); ?></span>
                                    <span class="text-xs font-semibold text-gray-600"><?php echo h(date('M d, Y', strtotime($deadline['deadline']))); ?></span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500"><?php echo h($deadline['description']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="bg-white border border-gray-200 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-bold text-gray-900">Offices Needing Follow-Up</h2>
                    <a href="analytics_dashboard.php?year=<?php echo urlencode($selected_year); ?>&quarter=<?php echo urlencode($selected_quarter); ?>" class="text-sm font-semibold text-[#800000] hover:underline">View details</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-3 pr-4 font-semibold">Office</th>
                                <th class="py-3 pr-4 font-semibold">Approved Required Docs</th>
                                <th class="py-3 pr-4 font-semibold">Open Issues</th>
                                <th class="py-3 font-semibold">Compliance</th>
                            </tr>
                        </thead>
                        <tbody id="officeAttentionBody" class="divide-y divide-gray-100">
                            <?php if ((int)$stats['total'] === 0): ?>
                                <tr><td colspan="4" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ((int)$stats['total'] > 0 ? $office_attention : [] as $office): ?>
                                <?php $issue_count = (int)$office['pending_docs'] + (int)$office['revision_docs'] + (int)$office['rejected_docs']; ?>
                                <tr>
                                    <td class="py-3 pr-4">
                                        <div class="font-semibold text-gray-900"><?php echo h($office['office_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo h($office['office_code']); ?></div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo (int)$office['approved_docs']; ?> of <?php echo (int)$office['required_docs']; ?></td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo $issue_count; ?></td>
                                    <td class="py-3">
                                        <div class="flex items-center gap-3 min-w-[160px]">
                                            <div class="h-2 flex-1 rounded-full bg-gray-200 overflow-hidden">
                                                <div class="h-full bg-[#800000]" style="width: <?php echo max(0, min(100, (int)$office['compliance_rate'])); ?>%"></div>
                                            </div>
                                            <span class="font-bold text-gray-900"><?php echo (int)$office['compliance_rate']; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
(function() {
fetch('../../api/cron_reminders.php?silent=1&_t=' + Date.now(), { cache: 'no-store' })
    .catch(error => console.error('Reminders check failed:', error));

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
}

function formatDate(value) {
    if (!value) return '';
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return escapeHtml(value);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[Number(match[2]) - 1]} ${match[3]}, ${match[1]}`;
}

function setText(id, value) {
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

function renderReviewQueue(rows) {
    const body = document.getElementById('reviewQueueBody');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-gray-500">No documents are awaiting review.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(function(item) {
        return `
            <tr>
                <td class="py-3 pr-4">
                    <div class="font-semibold text-gray-900">${escapeHtml(item.doc_code)}</div>
                    <div class="text-xs text-gray-500 truncate max-w-[260px]">${escapeHtml(item.file_name)}</div>
                </td>
                <td class="py-3 pr-4">
                    <div class="font-medium text-gray-800">${escapeHtml(item.office_name)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(item.office_code)}</div>
                </td>
                <td class="py-3 pr-4 text-gray-600">${formatDate(item.submitted_at)}</td>
                <td class="py-3 text-right">
                    <a href="review_submissions.php?id=${encodeURIComponent(item.document_id)}" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[#800000] text-white font-semibold">
                        <i class="fas fa-eye"></i> Review
                    </a>
                </td>
            </tr>
        `;
    }).join('');
}

function renderDeadlines(rows) {
    const list = document.getElementById('deadlinesList');
    if (!list) return;
    if (!rows.length) {
        list.innerHTML = '<p class="text-sm text-gray-500">No target deadlines configured for this period.</p>';
        return;
    }

    list.innerHTML = rows.map(function(item) {
        return `
            <div class="border border-gray-200 rounded-lg p-3">
                <div class="flex items-center justify-between gap-3">
                    <span class="font-bold text-gray-900">${escapeHtml(item.doc_code)}</span>
                    <span class="text-xs font-semibold text-gray-600">${formatDate(item.deadline)}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500">${escapeHtml(item.description)}</p>
            </div>
        `;
    }).join('');
}

function renderOfficeAttention(rows, hasSubmissions) {
    const body = document.getElementById('officeAttentionBody');
    if (!body) return;
    if (!hasSubmissions) {
        body.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>';
        return;
    }
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-gray-500">No active offices found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(function(office) {
        const issues = Number(office.pending_docs || 0) + Number(office.revision_docs || 0) + Number(office.rejected_docs || 0);
        const rate = Math.max(0, Math.min(100, Number(office.compliance_rate || 0)));
        return `
            <tr>
                <td class="py-3 pr-4">
                    <div class="font-semibold text-gray-900">${escapeHtml(office.office_name)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(office.office_code)}</div>
                </td>
                <td class="py-3 pr-4 text-gray-700">${Number(office.approved_docs || 0)} of ${Number(office.required_docs || 0)}</td>
                <td class="py-3 pr-4 text-gray-700">${issues}</td>
                <td class="py-3">
                    <div class="flex items-center gap-3 min-w-[160px]">
                        <div class="h-2 flex-1 rounded-full bg-gray-200 overflow-hidden">
                            <div class="h-full bg-[#800000]" style="width: ${rate}%"></div>
                        </div>
                        <span class="font-bold text-gray-900">${rate}%</span>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

let statusChart = null;
let complianceChart = null;

if (typeof Chart !== 'undefined') {
    Chart.register({
        id: 'emptyDoughnut',
        afterDraw(chart) {
            if (chart.config.type === 'doughnut') {
                const hasData = chart.data.datasets[0].data.some(val => val > 0);
                if (!hasData) {
                    const ctx = chart.ctx;
                    const width = chart.width;
                    const height = chart.height;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.font = '12px "Plus Jakarta Sans", sans-serif';
                    ctx.fillStyle = '#9ca3af'; // gray-400
                    ctx.fillText('No data available', width / 2, height / 2 - 10);
                    ctx.restore();
                }
            }
        }
    });
}

function initCharts(approved, pending, revision, rejected, offices) {
    if (typeof Chart === 'undefined') {
        console.warn("Chart.js is not loaded. Skipping chart initialization.");
        return;
    }
    const canvasStatus = document.getElementById('statusChart');
    const canvasCompliance = document.getElementById('complianceChart');
    if (!canvasStatus || !canvasCompliance) return;

    if (!Array.isArray(offices)) {
        offices = [];
    }

    const ctxStatus = canvasStatus.getContext('2d');
    statusChart = new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Awaiting Review', 'Revision Required', 'Rejected'],
            datasets: [{
                data: [approved, pending, revision, rejected],
                backgroundColor: ['#16a34a', '#ea580c', '#ca8a04', '#dc2626'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        font: { size: 11, family: 'Plus Jakarta Sans' }
                    }
                }
            }
        }
    });

    const ctxCompliance = canvasCompliance.getContext('2d');
    const officeLabels = offices.map(o => o.office_code);
    const officeData = offices.map(o => o.compliance_rate);
    complianceChart = new Chart(ctxCompliance, {
        type: 'bar',
        data: {
            labels: officeLabels,
            datasets: [{
                label: 'Compliance Rate (%)',
                data: officeData,
                backgroundColor: '#800000',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        font: { size: 10, family: 'Plus Jakarta Sans' }
                    }
                },
                x: {
                    ticks: {
                        font: { size: 10, family: 'Plus Jakarta Sans' }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

function updateCharts(approved, pending, revision, rejected, offices) {
    if (typeof Chart === 'undefined') return;
    if (!statusChart || !complianceChart) {
        initCharts(approved, pending, revision, rejected, offices);
        return;
    }
    if (!Array.isArray(offices)) {
        offices = [];
    }
    statusChart.data.datasets[0].data = [approved, pending, revision, rejected];
    statusChart.update();

    complianceChart.data.labels = offices.map(o => o.office_code);
    complianceChart.data.datasets[0].data = offices.map(o => o.compliance_rate);
    complianceChart.update();
}

async function refreshDashboard() {
    try {
        const year = <?php echo json_encode($selected_year); ?>;
        const quarter = <?php echo json_encode($selected_quarter); ?>;
        const response = await fetch(`dashboard_data.php?year=${encodeURIComponent(year)}&quarter=${encodeURIComponent(quarter)}&_t=${Date.now()}`, { cache: 'no-store' });
        const data = await response.json();
        if (data.success) {
            setText('overallCompliance', `${data.overall_compliance}%`);
            setText('needsActionCount', data.open_issues);
            setText('pendingCount', data.pending);
            setText('approvedCount', data.approved);
            setText('totalSubmissionsText', `${data.total} total submissions this period`);
            renderReviewQueue(data.review_queue || []);
            renderDeadlines(data.deadlines || []);
            renderOfficeAttention(data.office_attention || [], data.has_submissions);
            updateCharts(data.approved, data.pending, data.revision, data.rejected, data.office_attention || []);
        }
    } catch (error) {
        console.error(error);
    }
}

function runInit() {
    initCharts(
        <?php echo (int)$stats['approved']; ?>,
        <?php echo (int)$stats['pending']; ?>,
        <?php echo (int)$stats['revision']; ?>,
        <?php echo (int)$stats['rejected']; ?>,
        <?php echo json_encode($office_attention); ?>
    );
    refreshDashboard();
    if (window.dashboardInterval) {
        clearInterval(window.dashboardInterval);
    }
    window.dashboardInterval = setInterval(refreshDashboard, 5000);
}

if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", runInit);
} else {
    runInit();
}
})();
</script>
</body>
</html>
