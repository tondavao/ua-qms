<?php
session_start();
include "../../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header('Location: ../../index.php');
    exit();
}

$conn->set_charset("utf8mb4");

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_year($value) {
    if (preg_match('/\d{4}/', (string)$value, $matches)) {
        return $matches[0];
    }
    return date('Y');
}

function dashboard_quarter_bounds($year, $quarter) {
    switch ($quarter) {
        case '1st Quarter': return [1, 3];
        case '2nd Quarter': return [4, 6];
        case '3rd Quarter': return [7, 9];
        case '4th Quarter': return [10, 12];
        default: return [1, 12];
    }
}

$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_quarter_setting = $settings['active_semester'] ?? '1st Quarter';

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

$stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'revision' => 0, 'rejected' => 0];
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'Revision' THEN 1 ELSE 0 END) AS revision,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected
    FROM documents
    WHERE academic_year = ? AND semester = ?
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$stats = array_merge($stats, $stmt->get_result()->fetch_assoc() ?: []);
$stmt->close();

$avg_review_days = 0;
$stmt = $conn->prepare("
    SELECT ROUND(AVG(DATEDIFF(dr.reviewed_at, d.submitted_at)), 1) AS avg_review_days
    FROM document_reviews dr
    JOIN documents d ON dr.document_id = d.document_id
    WHERE dr.reviewed_at IS NOT NULL
        AND d.submitted_at IS NOT NULL
        AND d.academic_year = ?
        AND d.semester = ?
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$avg_review_days = $stmt->get_result()->fetch_assoc()['avg_review_days'] ?? 0;
$stmt->close();

$doc_type_rows = [];
$stmt = $conn->prepare("
    SELECT
        dt.doc_code,
        dt.description,
        COUNT(DISTINCT o.office_id) AS required_offices,
        COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN o.office_id END) AS approved_offices,
        SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) AS submissions,
        SUM(CASE WHEN d.status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending_docs,
        SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) AS revision_docs,
        SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_docs,
        ROUND(
            (
                COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN o.office_id END)
                / NULLIF(COUNT(DISTINCT o.office_id), 0)
            ) * 100
        ) AS compliance_rate
    FROM document_types dt
    CROSS JOIN offices o
    LEFT JOIN documents d
        ON d.doc_type_id = dt.doc_type_id
        AND d.office_id = o.office_id
        AND d.academic_year = ?
        AND d.semester = ?
    WHERE dt.required = 'Yes' AND o.status = 'Active' AND o.is_archived = FALSE
    GROUP BY dt.doc_type_id, dt.doc_code, dt.description
    ORDER BY compliance_rate ASC, (pending_docs + revision_docs + rejected_docs) DESC, dt.doc_code ASC
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $doc_type_rows[] = $row;
}
$stmt->close();

$office_rows = [];
$stmt = $conn->prepare("
    SELECT
        o.office_name,
        o.office_code,
        COUNT(DISTINCT dt.doc_type_id) AS required_docs,
        COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END) AS approved_docs,
        SUM(CASE WHEN d.status = 'Approved' THEN 1 ELSE 0 END) AS submissions,
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
");
$stmt->bind_param("ss", $selected_ay_range, $selected_quarter);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $office_rows[] = $row;
}
$stmt->close();

$total_required_slots = 0;
$total_approved_slots = 0;
foreach ($office_rows as $office) {
    $total_required_slots += (int)$office['required_docs'];
    $total_approved_slots += (int)$office['approved_docs'];
}
$overall_compliance = $total_required_slots > 0 ? round(($total_approved_slots / $total_required_slots) * 100) : 0;
$approval_rate = (int)$stats['total'] > 0 ? round(((int)$stats['approved'] / (int)$stats['total']) * 100) : 0;
$open_issues = (int)$stats['pending'] + (int)$stats['revision'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Analytics | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <style>
        :root { --ua-maroon: #800000; }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }
        .profile-dropdown {
            position: absolute;
            right: 0;
            top: 55px;
            width: 220px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            display: none;
            z-index: 100;
        }
        .profile-dropdown.active { display: block; }
        .profile-dropdown-header { padding: 16px; border-bottom: 1px solid #eee; }
        .profile-dropdown-item { display: flex; gap: 10px; padding: 14px 16px; color: #333; text-decoration: none; }
        .profile-dropdown-item:hover { background: #f5f5f5; }
    </style>
</head>
<body class="bg-gray-50 font-sans" data-user-role="<?php echo h($_SESSION['role']); ?>">
<script>localStorage.setItem('userRole', '<?php echo h($_SESSION['role']); ?>');</script>
<div class="flex h-screen overflow-hidden">
    <?php include "../../includes/sidebar_qmo.php"; ?>

    <main class="flex-1 flex flex-col overflow-hidden">
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
            <div>
                <h1 class="text-lg font-bold text-gray-900 leading-tight">Detailed Analytics</h1>
                <p class="text-sm text-gray-500"><?php echo h($selected_year); ?> &middot; <?php echo h($selected_quarter); ?></p>
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
                        <button id="profile-btn" class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition">
                            <?php echo h(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
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
            <div class="flex flex-wrap gap-4 items-center justify-between">
                <form method="GET" class="flex flex-wrap gap-3 items-center">
                    <select name="year" class="border border-gray-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-red-200">
                        <?php foreach ($academic_years as $year_option): ?>
                            <option value="<?php echo h($year_option); ?>" <?php echo $selected_year === $year_option ? 'selected' : ''; ?>>
                                <?php echo h($year_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="quarter" class="border border-gray-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-red-200">
                        <?php foreach ($quarters as $quarter_option): ?>
                            <option value="<?php echo h($quarter_option); ?>" <?php echo $selected_quarter === $quarter_option ? 'selected' : ''; ?>>
                                <?php echo h($quarter_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-[#800000] text-white px-4 py-2 rounded-lg hover:bg-red-900 transition">
                        <i class="fas fa-filter mr-2"></i>Apply
                    </button>
                </form>
                <div class="flex bg-white p-1 rounded-lg border border-gray-200 shadow-sm">
                    <a href="index.php?year=<?php echo urlencode($selected_year); ?>&quarter=<?php echo urlencode($selected_quarter); ?>" class="px-4 py-2 font-bold text-xs rounded-md text-gray-500 hover:text-gray-700">Overview</a>
                    <a href="analytics_dashboard.php?year=<?php echo urlencode($selected_year); ?>&quarter=<?php echo urlencode($selected_quarter); ?>" class="px-4 py-2 font-bold text-xs rounded-md bg-[#800000] text-white">Detailed Analytics</a>
                </div>
            </div>

            <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Document Compliance</p>
                    <p id="analyticsCompliance" class="mt-2 text-3xl font-bold text-gray-900"><?php echo $overall_compliance; ?>%</p>
                    <p class="mt-2 text-xs text-gray-500">Approved required documents by office</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Approval Rate</p>
                    <p id="analyticsApprovalRate" class="mt-2 text-3xl font-bold text-green-700"><?php echo $approval_rate; ?>%</p>
                    <p id="analyticsApprovalText" class="mt-2 text-xs text-gray-500"><?php echo (int)$stats['approved']; ?> of <?php echo (int)$stats['total']; ?> submissions approved</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Pending Items</p>
                    <p id="analyticsOpenIssues" class="mt-2 text-3xl font-bold text-[#800000]"><?php echo $open_issues; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Awaiting review and revision docs</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <p class="text-sm font-medium text-gray-500">Average Review Time</p>
                    <p id="analyticsReviewTime" class="mt-2 text-3xl font-bold text-blue-700"><?php echo h($avg_review_days ?: 0); ?>d</p>
                    <p class="mt-2 text-xs text-gray-500">From submission to QMO review</p>
                </div>
            </section>

            <section class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-base font-bold text-gray-900 mb-4">Required Documents</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-3 pr-4 font-semibold">Document Type</th>
                                <th class="py-3 pr-4 font-semibold">Approved Offices</th>
                                <th class="py-3 pr-4 font-semibold">Approved Submissions</th>
                                <th class="py-3 pr-4 font-semibold">Pending Items</th>
                                <th class="py-3 font-semibold">Compliance</th>
                            </tr>
                        </thead>
                        <tbody id="docTypeAnalyticsBody" class="divide-y divide-gray-100">
                            <?php if ((int)$stats['total'] === 0): ?>
                                <tr><td colspan="5" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>
                            <?php endif; ?>
                            <?php if ((int)$stats['total'] > 0 && !$doc_type_rows): ?>
                                <tr><td colspan="5" class="py-6 text-center text-gray-500">No required document types found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ((int)$stats['total'] > 0 ? $doc_type_rows : [] as $doc): ?>
                                <?php $issues = (int)$doc['pending_docs'] + (int)$doc['revision_docs']; ?>
                                <tr>
                                    <td class="py-3 pr-4">
                                        <div class="font-semibold text-gray-900"><?php echo h($doc['doc_code']); ?></div>
                                        <div class="text-xs text-gray-500 max-w-[360px]"><?php echo h($doc['description']); ?></div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo (int)$doc['approved_offices']; ?> of <?php echo (int)$doc['required_offices']; ?></td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo (int)$doc['submissions']; ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="<?php echo $issues > 0 ? 'text-[#800000]' : 'text-gray-700'; ?> font-semibold"><?php echo $issues; ?></span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex items-center gap-3 min-w-[170px]">
                                            <div class="h-2 flex-1 rounded-full bg-gray-200 overflow-hidden">
                                                <div class="h-full bg-[#800000]" style="width: <?php echo max(0, min(100, (int)$doc['compliance_rate'])); ?>%"></div>
                                            </div>
                                            <span class="font-bold text-gray-900"><?php echo (int)$doc['compliance_rate']; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white border border-gray-200 rounded-lg p-6">
                <h2 class="text-base font-bold text-gray-900 mb-4">Office Compliance</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-3 pr-4 font-semibold">Office</th>
                                <th class="py-3 pr-4 font-semibold">Approved Required Docs</th>
                                <th class="py-3 pr-4 font-semibold">Approved Submissions</th>
                                <th class="py-3 pr-4 font-semibold">Pending Items</th>
                                <th class="py-3 font-semibold">Compliance</th>
                            </tr>
                        </thead>
                        <tbody id="officeAnalyticsBody" class="divide-y divide-gray-100">
                            <?php if ((int)$stats['total'] === 0): ?>
                                <tr><td colspan="5" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>
                            <?php endif; ?>
                            <?php if ((int)$stats['total'] > 0 && !$office_rows): ?>
                                <tr><td colspan="5" class="py-6 text-center text-gray-500">No active offices found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ((int)$stats['total'] > 0 ? $office_rows : [] as $office): ?>
                                <?php $issues = (int)$office['pending_docs'] + (int)$office['revision_docs']; ?>
                                <tr>
                                    <td class="py-3 pr-4">
                                        <div class="font-semibold text-gray-900"><?php echo h($office['office_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo h($office['office_code']); ?></div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo (int)$office['approved_docs']; ?> of <?php echo (int)$office['required_docs']; ?></td>
                                    <td class="py-3 pr-4 text-gray-700"><?php echo (int)$office['submissions']; ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="<?php echo $issues > 0 ? 'text-[#800000]' : 'text-gray-700'; ?> font-semibold"><?php echo $issues; ?></span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex items-center gap-3 min-w-[170px]">
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
const profileBtn = document.getElementById('profile-btn');
const profileDropdown = document.getElementById('profile-dropdown');
if (profileBtn && profileDropdown) {
    profileBtn.addEventListener('click', function() {
        profileDropdown.classList.toggle('active');
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('#profile-btn') && !event.target.closest('#profile-dropdown')) {
            profileDropdown.classList.remove('active');
        }
    });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
}

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
}

function progressCell(rate) {
    const cleanRate = Math.max(0, Math.min(100, Number(rate || 0)));
    return `
        <div class="flex items-center gap-3 min-w-[170px]">
            <div class="h-2 flex-1 rounded-full bg-gray-200 overflow-hidden">
                <div class="h-full bg-[#800000]" style="width: ${cleanRate}%"></div>
            </div>
            <span class="font-bold text-gray-900">${cleanRate}%</span>
        </div>
    `;
}

function renderDocumentTypes(rows, hasSubmissions) {
    const body = document.getElementById('docTypeAnalyticsBody');
    if (!body) return;
    if (!hasSubmissions) {
        body.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>';
        return;
    }
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500">No required document types found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(function(doc) {
        const issues = Number(doc.pending_docs || 0) + Number(doc.revision_docs || 0);
        return `
            <tr>
                <td class="py-3 pr-4">
                    <div class="font-semibold text-gray-900">${escapeHtml(doc.doc_code)}</div>
                    <div class="text-xs text-gray-500 max-w-[360px]">${escapeHtml(doc.description)}</div>
                </td>
                <td class="py-3 pr-4 text-gray-700">${Number(doc.approved_offices || 0)} of ${Number(doc.required_offices || 0)}</td>
                <td class="py-3 pr-4 text-gray-700">${Number(doc.submissions || 0)}</td>
                <td class="py-3 pr-4">
                    <span class="${issues > 0 ? 'text-[#800000]' : 'text-gray-700'} font-semibold">${issues}</span>
                </td>
                <td class="py-3">${progressCell(doc.compliance_rate)}</td>
            </tr>
        `;
    }).join('');
}

function renderOffices(rows, hasSubmissions) {
    const body = document.getElementById('officeAnalyticsBody');
    if (!body) return;
    if (!hasSubmissions) {
        body.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500">No submissions for this period yet.</td></tr>';
        return;
    }
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500">No active offices found.</td></tr>';
        return;
    }

    body.innerHTML = rows.map(function(office) {
        const issues = Number(office.pending_docs || 0) + Number(office.revision_docs || 0);
        return `
            <tr>
                <td class="py-3 pr-4">
                    <div class="font-semibold text-gray-900">${escapeHtml(office.office_name)}</div>
                    <div class="text-xs text-gray-500">${escapeHtml(office.office_code)}</div>
                </td>
                <td class="py-3 pr-4 text-gray-700">${Number(office.approved_docs || 0)} of ${Number(office.required_docs || 0)}</td>
                <td class="py-3 pr-4 text-gray-700">${Number(office.submissions || 0)}</td>
                <td class="py-3 pr-4">
                    <span class="${issues > 0 ? 'text-[#800000]' : 'text-gray-700'} font-semibold">${issues}</span>
                </td>
                <td class="py-3">${progressCell(office.compliance_rate)}</td>
            </tr>
        `;
    }).join('');
}

async function refreshAnalytics() {
    try {
        const year = <?php echo json_encode($selected_year); ?>;
        const quarter = <?php echo json_encode($selected_quarter); ?>;
        const response = await fetch(`dashboard_data.php?year=${encodeURIComponent(year)}&quarter=${encodeURIComponent(quarter)}&_t=${Date.now()}`, { cache: 'no-store' });
        const data = await response.json();
        if (data.success) {
            setText('analyticsCompliance', `${data.overall_compliance}%`);
            setText('analyticsApprovalRate', `${data.approval_rate}%`);
            setText('analyticsApprovalText', `${data.approved} of ${data.total} submissions approved`);
            setText('analyticsOpenIssues', data.open_issues);
            setText('analyticsReviewTime', `${data.avg_review_days || 0}d`);
            renderDocumentTypes(data.doc_type_rows || [], data.has_submissions);
            renderOffices(data.office_rows || [], data.has_submissions);
        }
    } catch (error) {
        console.error(error);
    }
}

refreshAnalytics();
setInterval(refreshAnalytics, 5000);
</script>
</body>
</html>
