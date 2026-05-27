<?php
session_start();
require_once "../../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header("Location: ../../index.php");
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

// Fetch system settings
$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_quarter_setting = $settings['active_semester'] ?? '1st Quarter';

// Fetch Academic Years for filter dropdown
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

// Fetch Offices list for filter dropdown
$offices_list = [];
$offices_res = $conn->query("SELECT office_id, office_code, office_name FROM offices WHERE status='Active' AND is_archived = FALSE ORDER BY office_name ASC");
while ($row = $offices_res->fetch_assoc()) {
    $offices_list[] = $row;
}

// Fetch Document Types list for filter dropdown
$doc_types_list = [];
$dt_res = $conn->query("SELECT doc_type_id, doc_code, description FROM document_types ORDER BY doc_code ASC");
while ($row = $dt_res->fetch_assoc()) {
    $doc_types_list[] = $row;
}

$quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];

// Get selected filters
$selected_year = $_GET['year'] ?? $active_year;
$selected_quarter = in_array(($_GET['quarter'] ?? ''), $quarters, true) ? $_GET['quarter'] : $active_quarter_setting;
$selected_category = $_GET['category'] ?? '';
$selected_office = $_GET['office'] ?? '';

// Construct compliance query with dynamic filters
$where_clauses = ["o.status = 'Active'", "o.is_archived = FALSE", "dt.required = 'Yes'"];
$params = [$selected_year, $selected_quarter];
$types = "ss";

if ($selected_category !== '') {
    $where_clauses[] = "c.category_name = ?";
    $params[] = $selected_category;
    $types .= "s";
}
if ($selected_office !== '') {
    $where_clauses[] = "o.office_id = ?";
    $params[] = (int)$selected_office;
    $types .= "i";
}
$selected_doc_type = $_GET['doc_type'] ?? '';
if ($selected_doc_type !== '') {
    $where_clauses[] = "dt.doc_type_id = ?";
    $params[] = (int)$selected_doc_type;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

$sql = "
SELECT
    o.office_id,
    o.office_name,
    o.office_code,
    c.category_name,
    COUNT(DISTINCT dt.doc_type_id) AS required_docs,
    COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END) AS approved_docs,
    SUM(CASE WHEN d.status = 'Awaiting Review' THEN 1 ELSE 0 END) AS pending_docs,
    SUM(CASE WHEN d.status = 'Revision' THEN 1 ELSE 0 END) AS revision_docs,
    SUM(CASE WHEN d.status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_docs,
    COUNT(DISTINCT d.doc_type_id) AS submitted_docs,
    ROUND(
        (
            COUNT(DISTINCT CASE WHEN d.status = 'Approved' THEN dt.doc_type_id END)
            / NULLIF(COUNT(DISTINCT dt.doc_type_id), 0)
        ) * 100
    ) AS compliance_rate
FROM offices o
LEFT JOIN unit_categories c ON o.category_id = c.category_id
CROSS JOIN document_types dt
LEFT JOIN documents d
    ON d.office_id = o.office_id
    AND d.doc_type_id = dt.doc_type_id
    AND d.academic_year = ?
    AND d.semester = ?
WHERE $where_sql
GROUP BY o.office_id, o.office_name, o.office_code, c.category_name
ORDER BY o.office_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$report_rows = [];
while ($row = $result->fetch_assoc()) {
    $report_rows[] = $row;
}
$stmt->close();

// Compute overall compliance metrics
$total_required = 0;
$total_approved = 0;
$total_pending = 0;
$total_revision = 0;
$total_rejected = 0;

foreach ($report_rows as $row) {
    $total_required += (int)$row['required_docs'];
    $total_approved += (int)$row['approved_docs'];
    $total_pending += (int)$row['pending_docs'];
    $total_revision += (int)$row['revision_docs'];
    $total_rejected += (int)$row['rejected_docs'];
}
$total_submissions = $total_approved + $total_pending + $total_revision + $total_rejected;
$overall_rate = $total_required > 0 ? round(($total_approved / $total_required) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Reports | UA QMS</title>
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
        .profile-dropdown-header { padding: 12px 16px; border-bottom: 1px solid #e5e7eb; }
        .profile-dropdown-item { display: flex; gap: 10px; padding: 12px 16px; color: #374151; text-decoration: none; }
        .profile-dropdown-item:hover { background: #f3f4f6; }
        .profile-dropdown-item.logout { color: #dc2626; border-top: 1px solid #e5e7eb; }

        @media print {
            body { background: white !important; color: black !important; font-size: 11px; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            main { padding: 0 !important; margin: 0 !important; overflow: visible !important; }
            .flex-1 { overflow: visible !important; }
            .overflow-auto { overflow: visible !important; }
            .bg-white { border: none !important; box-shadow: none !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #e5e7eb !important; padding: 6px !important; }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans" data-user-role="<?php echo h($_SESSION['role']); ?>">
<script>localStorage.setItem('userRole', '<?php echo h($_SESSION['role']); ?>');</script>
<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <?php include "../../includes/sidebar_qmo.php"; ?>

    <main class="flex-1 flex flex-col overflow-hidden">
        <!-- Topbar -->
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0 no-print">
            <div>
                <h1 class="text-lg font-bold text-gray-900 leading-tight">Compliance Reports</h1>
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
                                <p class="font-semibold text-gray-900"><?php echo h($_SESSION['full_name'] ?? 'User'); ?></p>
                                <span class="text-xs text-gray-500"><?php echo h($_SESSION['role'] ?? 'Role'); ?></span>
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
        <div class="flex-1 overflow-auto p-4 md:p-8 space-y-6">
            
            <!-- Print Only Header -->
            <div class="hidden print-only mb-6">
                <div class="flex items-center justify-between pb-4 border-b-2 border-[#800000]">
                    <div class="flex items-center gap-4">
                        <img src="../../assets/img/ua-qms-logo.jpg" alt="UA Logo" class="w-16 h-16 rounded-full border border-gray-150">
                        <div>
                            <h2 class="text-xl font-bold text-[#800000] tracking-wide">UNIVERSITY OF ANTIQUE</h2>
                            <p class="text-xs font-semibold text-gray-600">Tario-Lim Memorial Campus | Quality Management Office</p>
                            <h3 class="text-sm font-bold text-gray-800 mt-2">QMS Submissions Compliance Report</h3>
                        </div>
                    </div>
                    <div class="text-right text-xs text-gray-500">
                        <p><strong>Generated:</strong> <?php echo date('F d, Y h:i A'); ?></p>
                        <p><strong>Period:</strong> <?php echo h($selected_quarter); ?> (AY <?php echo h($selected_year); ?>)</p>
                    </div>
                </div>
            </div>

            <!-- Report Summary Metrics -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Filtered Compliance</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $overall_rate; ?>%</p>
                    <p class="mt-2 text-xs text-gray-500">Average rate of approved target submissions</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Required Slots</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900"><?php echo $total_required; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Total targets assigned to selected filters</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Approved Files</p>
                    <p class="mt-2 text-3xl font-bold text-green-700"><?php echo $total_approved; ?></p>
                    <p class="mt-2 text-xs text-gray-500">Total compliant documents processed</p>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
                    <p class="text-sm font-medium text-gray-500">Open Review Issues</p>
                    <p class="mt-2 text-3xl font-bold text-[#800000]"><?php echo ($total_pending + $total_revision + $total_rejected); ?></p>
                    <p class="mt-2 text-xs text-gray-500">Pending review, revision, or rejected</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 no-print mb-6">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Academic Year</label>
                        <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                            <?php foreach ($academic_years as $y): ?>
                                <option value="<?php echo h($y); ?>" <?php echo $selected_year === $y ? 'selected' : ''; ?>><?php echo h($y); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Quarter</label>
                        <select name="quarter" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                            <?php foreach ($quarters as $q): ?>
                                <option value="<?php echo h($q); ?>" <?php echo $selected_quarter === $q ? 'selected' : ''; ?>><?php echo h($q); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Category</label>
                        <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                            <option value="">All Categories</option>
                            <option value="Academic" <?php echo $selected_category === 'Academic' ? 'selected' : ''; ?>>Academic</option>
                            <option value="Non-Academic" <?php echo $selected_category === 'Non-Academic' ? 'selected' : ''; ?>>Non-Academic</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Office / Unit</label>
                        <select name="office" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                            <option value="">All Offices</option>
                            <?php foreach ($offices_list as $off): ?>
                                <option value="<?php echo $off['office_id']; ?>" <?php echo $selected_office == $off['office_id'] ? 'selected' : ''; ?>>
                                    [<?php echo h($off['office_code']); ?>] <?php echo h($off['office_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Doc Type</label>
                        <select name="doc_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none text-sm">
                            <option value="">All Types</option>
                            <?php foreach ($doc_types_list as $dt): ?>
                                <option value="<?php echo $dt['doc_type_id']; ?>" <?php echo (isset($_GET['doc_type']) && $_GET['doc_type'] == $dt['doc_type_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($dt['doc_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <a href="reports.php" class="px-3 py-1.5 bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white transition flex items-center justify-center gap-1 shadow-sm" title="Reset Filters">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <button type="submit" class="px-3 py-1.5 bg-[#800000] text-white rounded-lg text-xs font-bold hover:bg-red-900 transition flex items-center justify-center gap-1 shadow-sm">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200 flex justify-between items-center no-print">
                    <h3 class="font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-file-invoice text-ua-maroon"></i> Submissions Table</h3>
                    <div class="flex gap-2">
                        <button onclick="exportCSV()" class="bg-red-50 border border-red-200 text-[#800000] px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white flex items-center gap-1.5 transition shadow-sm">
                            <i class="fas fa-file-csv text-sm"></i> Export CSV
                        </button>
                        <button onclick="window.print()" class="bg-[#800000] text-white px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-red-900 flex items-center gap-1.5 transition shadow-sm">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table id="report-table" class="w-full text-sm text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold">
                            <tr>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider">Office Code</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider">Office/Unit Name</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider">Category</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider text-center">Required</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider text-center">Submitted</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider text-center">Approved</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider text-center">Pending</th>
                                <th class="px-5 py-3 text-xs uppercase tracking-wider text-right">Compliance Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            <?php if (empty($report_rows)): ?>
                                <tr>
                                    <td colspan="8" class="px-5 py-8 text-center text-gray-400">
                                        <i class="fas fa-file-excel text-2xl mb-2 block opacity-30"></i>
                                        No data matching selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_rows as $row): ?>
                                    <tr class="hover:bg-gray-50/50 transition">
                                        <td class="px-5 py-3.5 font-bold text-gray-900"><?php echo h($row['office_code']); ?></td>
                                        <td class="px-5 py-3.5 font-medium"><?php echo h($row['office_name']); ?></td>
                                        <td class="px-5 py-3.5 text-xs font-semibold">
                                            <span class="px-2 py-0.5 rounded-full <?php echo $row['category_name'] === 'Academic' ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700'; ?>">
                                                <?php echo h($row['category_name'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5 text-center font-semibold text-gray-500"><?php echo (int)$row['required_docs']; ?></td>
                                        <td class="px-5 py-3.5 text-center font-medium"><?php echo (int)$row['submitted_docs']; ?></td>
                                        <td class="px-5 py-3.5 text-center font-medium text-green-600"><?php echo (int)$row['approved_docs']; ?></td>
                                        <td class="px-5 py-3.5 text-center font-medium text-orange-600"><?php echo (int)$row['pending_docs']; ?></td>
                                        <td class="px-5 py-3.5 text-right font-bold text-gray-900">
                                            <span class="<?php echo $row['compliance_rate'] >= 80 ? 'text-green-700' : ($row['compliance_rate'] >= 50 ? 'text-orange-600' : 'text-red-700'); ?>">
                                                <?php echo (int)$row['compliance_rate']; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
    // Profile dropdown toggle
    const profileBtn = document.getElementById('profile-btn');
    const profileDropdown = document.getElementById('profile-dropdown');
    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
        });
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#profile-btn') && !e.target.closest('#profile-dropdown')) {
                profileDropdown.classList.remove('active');
            }
        });
    }

    // Client-side CSV export
    function exportCSV() {
        const table = document.getElementById('report-table');
        if (!table) return;
        let csv = [];
        const rows = table.querySelectorAll('tr');
        for (let i = 0; i < rows.length; i++) {
            const cols = rows[i].querySelectorAll('th, td');
            let row = [];
            for (let j = 0; j < cols.length; j++) {
                // Remove badges styles spaces and quotes
                let val = cols[j].textContent.replace(/\s+/g, ' ').trim();
                // Escape quotes
                val = val.replace(/"/g, '""');
                row.push('"' + val + '"');
            }
            csv.push(row.join(','));
        }
        
        const csvString = csv.join('\n');
        const filename = 'QMS_Compliance_Report_' + '<?php echo $selected_quarter; ?>'.replace(' ', '_') + '_' + '<?php echo $selected_year; ?>' + '.csv';
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        
        if (navigator.msSaveBlob) { // IE 10+
            navigator.msSaveBlob(blob, filename);
        } else {
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    }
</script>
</body>
</html>
