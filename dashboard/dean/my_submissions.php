<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";
require_once __DIR__ . "/../../includes/schema_helpers.php";
ua_qms_ensure_document_annotations_table($conn);

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$office_id = $_SESSION['office_id'] ?? 0;

// Load active academic year and active semester from system configurations
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];

// Get list of academic years and semesters for dropdown filters
$academic_years_list = [];
$res_ay = $conn->query("SELECT year_range FROM academic_years ORDER BY year_range DESC");
while ($row = $res_ay->fetch_assoc()) {
    $academic_years_list[] = $row['year_range'];
}

$semesters_list = [];
$res_sem = $conn->query("SELECT semester_name FROM semesters ORDER BY semester_name ASC");
while ($row = $res_sem->fetch_assoc()) {
    $semesters_list[] = $row['semester_name'];
}

$doc_types_list = [];
$res_dt = $conn->query("SELECT doc_type_id, doc_code, description FROM document_types ORDER BY doc_code ASC");
if ($res_dt) {
    while ($row = $res_dt->fetch_assoc()) {
        $doc_types_list[] = $row;
    }
}

$selected_ay = $_GET['ay'] ?? $active_year;
$selected_sem = $_GET['sem'] ?? $active_semester;
$selected_status = $_GET['status'] ?? 'All';
$selected_dt = $_GET['dt'] ?? 'All';
$search_query = $_GET['search'] ?? '';

$sql = "SELECT 
            d.document_id,
            d.doc_type_id,
            d.office_id,
            d.file_name,
            d.file_path,
            d.preview_path,
            d.status,
            d.submitted_at,
            d.academic_year,
            d.semester,
            d.version_number,
            dt.doc_code,
            dt.description,
            dr.remarks AS review_remarks,
            dr.reviewed_at,
            (da.annotation_id IS NOT NULL) AS has_edits
        FROM documents d
        JOIN document_types dt 
            ON d.doc_type_id = dt.doc_type_id
        LEFT JOIN document_reviews dr 
            ON d.document_id = dr.document_id
        LEFT JOIN document_annotations da
            ON d.document_id = da.document_id
        WHERE d.office_id = ?";

$params = [$office_id];
$types = "i";

if ($selected_ay !== 'All') {
    $sql .= " AND d.academic_year = ?";
    $params[] = $selected_ay;
    $types .= "s";
}

if ($selected_sem !== 'All') {
    $sql .= " AND d.semester = ?";
    $params[] = $selected_sem;
    $types .= "s";
}

if ($selected_status !== 'All') {
    if ($selected_status === 'Archived') {
        $sql .= " AND d.status = 'Archived'";
    } else {
        $sql .= " AND (d.status = ? OR d.status = 'Archived')";
        $params[] = $selected_status;
        $types .= "s";
    }
}

if ($selected_dt !== 'All') {
    $sql .= " AND d.doc_type_id = ?";
    $params[] = $selected_dt;
    $types .= "i";
}

if (!empty($search_query)) {
    $sql .= " AND (d.file_name LIKE ? OR dt.doc_code LIKE ? OR dt.description LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$sql .= " ORDER BY d.submitted_at DESC, dr.reviewed_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();

if (!function_exists('getRevisionText')) {
    function getRevisionText($verNumber) {
        $ver = intval($verNumber ?? 1);
        if ($ver <= 1) {
            return 'Original Submission';
        }
        return 'Revision ' . ($ver - 1);
    }
}

// Group by document series (doc_type_id + academic_year + semester) to support expandable version history
$unique_doc_rows = [];
while($row = $result->fetch_assoc()) {
    if (!isset($unique_doc_rows[$row['document_id']])) {
        $unique_doc_rows[$row['document_id']] = $row;
    }
}

$series_groups = [];
foreach ($unique_doc_rows as $doc_row) {
    $series_key = $doc_row['doc_type_id'] . '_' . md5(($doc_row['academic_year'] ?? '') . '_' . ($doc_row['semester'] ?? ''));
    if (!isset($series_groups[$series_key])) {
        $series_groups[$series_key] = [];
    }
    $series_groups[$series_key][] = $doc_row;
}

$submissions = [];
foreach ($series_groups as $s_key => $versions) {
    usort($versions, function($a, $b) {
        $vA = intval($a['version_number'] ?? 1);
        $vB = intval($b['version_number'] ?? 1);
        if ($vA === $vB) {
            return strtotime($b['submitted_at']) <=> strtotime($a['submitted_at']);
        }
        return $vB <=> $vA;
    });

    $primary = $versions[0];

    // When status filter is active (and not 'All' or 'Archived'), ensure the primary row matches the status
    if ($selected_status !== 'All' && $selected_status !== 'Archived') {
        if ($primary['status'] !== $selected_status) {
            $matching_index = null;
            foreach ($versions as $idx => $v) {
                if ($v['status'] === $selected_status) {
                    $matching_index = $idx;
                    break;
                }
            }
            if ($matching_index === null) {
                continue;
            }
            $primary = $versions[$matching_index];
            $prev_versions = [];
            foreach ($versions as $idx => $v) {
                if ($idx !== $matching_index) {
                    $prev_versions[] = $v;
                }
            }
            $primary['previous_versions'] = $prev_versions;
            $primary['series_id'] = $s_key;
            $submissions[] = $primary;
            continue;
        }
    }

    $primary['previous_versions'] = array_slice($versions, 1);
    $primary['series_id'] = $s_key;
    $submissions[] = $primary;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Submissions | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <script src="../../assets/js/universal-file-viewer.js" defer></script>
    <script src="../../assets/js/document-editor.js" defer></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        :root {
            --ua-maroon: #800000;
        }
        .bg-ua-maroon { background-color: var(--ua-maroon); }
        .text-ua-maroon { color: var(--ua-maroon); }
        
        /* Status Badges */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        .badge-awaiting { background-color: #FEF3C7; color: #92400E; }
        .badge-approved { background-color: #D1FAE5; color: #065F46; }
        .badge-revision { background-color: #FEE2E2; color: #991B1B; }
        


        /* Print styles */
        @media print {
            .no-print { display: none !important; }
            table { width: 100%; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <?php require_once __DIR__ . "/../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 md:px-8 flex-shrink-0 sticky top-0 z-50">
                <div class="flex items-center gap-3 min-w-0">
                    <h1 class="text-base md:text-lg font-bold text-gray-900 leading-tight truncate">My Submissions</h1>
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
                <div class="max-w-7xl mx-auto">


                    <!-- Filter Bar -->
                    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm mb-6 no-print">
                        <form method="GET" class="flex flex-col xl:flex-row gap-4 items-end w-full">
                            <!-- Search Input -->
                            <div class="w-full xl:w-48">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Search</label>
                                <div class="relative">
                                    <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-400"></i>
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search submissions..." class="w-full pl-8 pr-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                </div>
                            </div>

                            <!-- Document Type Filter -->
                            <div class="w-full xl:flex-1 min-w-[120px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Document Type</label>
                                <select id="dt-select" name="dt" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs bg-white">
                                    <option value="All" <?php echo $selected_dt === 'All' ? 'selected' : ''; ?>>All Types</option>
                                    <?php foreach ($doc_types_list as $dt): ?>
                                        <option value="<?php echo $dt['doc_type_id']; ?>" <?php echo $selected_dt == $dt['doc_type_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dt['doc_code'] . ' - ' . $dt['description']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Academic Year Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Academic Year</label>
                                <select id="ay-select" name="ay" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs bg-white">
                                    <option value="All" <?php echo $selected_ay === 'All' ? 'selected' : ''; ?>>All Years</option>
                                    <?php foreach ($academic_years_list as $ay): ?>
                                        <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $selected_ay === $ay ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ay); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Period Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Period</label>
                                <select id="sem-select" name="sem" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs bg-white">
                                    <option value="All" <?php echo $selected_sem === 'All' ? 'selected' : ''; ?>>All Periods</option>
                                    <?php foreach ($semesters_list as $sem): ?>
                                        <option value="<?php echo htmlspecialchars($sem); ?>" <?php echo $selected_sem === $sem ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sem); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Status</label>
                                <select id="status-select" name="status" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs bg-white">
                                    <option value="All" <?php echo $selected_status === 'All' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="Awaiting Review" <?php echo $selected_status === 'Awaiting Review' ? 'selected' : ''; ?>>Awaiting Review</option>
                                    <option value="Approved" <?php echo $selected_status === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="Revision" <?php echo $selected_status === 'Revision' ? 'selected' : ''; ?>>Revision</option>
                                    <option value="Archived" <?php echo $selected_status === 'Archived' ? 'selected' : ''; ?>>Archived (Superseded)</option>
                                </select>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-1.5 shrink-0 mt-2 xl:mt-0 w-full xl:w-auto justify-end">
                                <a href="my_submissions.php" class="px-3 py-1.5 bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white transition flex items-center justify-center gap-1 shadow-sm" title="Reset Filters">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                                <button type="submit" class="px-3 py-1.5 bg-[#800000] text-white rounded-lg text-xs font-bold hover:bg-red-900 transition flex items-center justify-center gap-1 shadow-sm">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100 flex justify-between items-center no-print">
                            <h3 class="font-bold text-gray-900">Document Submission History</h3>
                            <a href="submit_document.php" class="px-4 py-2 bg-[#800000] text-white rounded-xl text-sm font-bold hover:bg-[#600000] transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-plus text-xs"></i> New Submission
                            </a>
                        </div>
                        
                        <div class="overflow-x-auto w-full">
                            <table class="w-full text-left border-collapse min-w-[650px] md:min-w-full">
                                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold border-b border-gray-100">
                                    <tr>
                                        <th class="px-3.5 py-3 whitespace-nowrap">Document Type</th>
                                        <th class="px-3.5 py-3 whitespace-nowrap">Period</th>
                                        <th class="px-3.5 py-3 whitespace-nowrap">File Name</th>
                                        <th class="px-3.5 py-3 whitespace-nowrap">Submitted At</th>
                                        <th class="px-3.5 py-3 whitespace-nowrap">Status</th>
                                        <th class="px-3.5 py-3 no-print text-left whitespace-nowrap">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-xs md:text-sm" id="submissionsTableBody">
                                    <?php if(empty($submissions)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                                No documents submitted yet for the selected period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($submissions as $sub): ?>
                                            <?php 
                                                $rowBgClass = $sub['status'] === 'Revision' ? 'bg-red-50/40 hover:bg-red-50/60 border-l-4 border-l-[#800000]' : 'hover:bg-gray-50/50';
                                            ?>
                                            <tr class="transition-colors submission-row <?php echo $rowBgClass; ?>" data-doc-id="<?php echo $sub['document_id']; ?>">
                                                <td class="px-3.5 py-3 max-w-[150px] md:max-w-[220px]">
                                                    <span class="font-bold text-gray-900 leading-snug block text-xs md:text-sm break-words"><?php echo htmlspecialchars($sub['description'] . ' (' . $sub['doc_code'] . ')'); ?></span>
                                                </td>
                                                <td class="px-3.5 py-3 text-gray-600 whitespace-nowrap">
                                                    <span class="font-semibold text-gray-800 text-xs md:text-sm"><?php echo htmlspecialchars(($sub['semester'] ?? 'N/A') . ' ' . ($sub['academic_year'] ?? 'N/A')); ?></span>
                                                </td>
                                                <td class="px-3.5 py-3 text-gray-600">
                                                    <div class="flex flex-wrap md:inline-flex items-center gap-1.5 md:whitespace-nowrap">
                                                        <span class="font-medium text-gray-900 truncate max-w-[120px] md:max-w-[160px] inline-block align-middle" title="<?php echo htmlspecialchars($sub['file_name']); ?>"><?php echo htmlspecialchars($sub['file_name']); ?></span>
                                                        <?php if(isset($sub['version_number']) && $sub['version_number'] > 1): ?>
                                                            <span class="bg-[#800000] text-white text-[10px] font-bold px-2 py-0.5 rounded-md shadow-sm border border-red-900 whitespace-nowrap shrink-0" title="Version <?php echo htmlspecialchars($sub['version_number']); ?>">
                                                                <i class="fas fa-history mr-1 text-[9px]"></i><?php echo getRevisionText($sub['version_number']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="bg-gray-100 text-gray-700 text-[10px] font-bold px-2 py-0.5 rounded-md border border-gray-200 whitespace-nowrap shrink-0">
                                                                <i class="fas fa-file-alt mr-1 text-[9px]"></i>Original Submission
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="px-3.5 py-3 text-gray-600 text-xs whitespace-nowrap">
                                                    <?php echo date('M d, Y, g:i A', strtotime($sub['submitted_at'])); ?>
                                                </td>
                                                <td class="px-3.5 py-3 whitespace-nowrap">
                                                    <?php 
                                                        $statusClass = 'badge-awaiting';
                                                        if($sub['status'] == 'Approved') $statusClass = 'badge-approved';
                                                        if($sub['status'] == 'Revision') $statusClass = 'badge-revision';
                                                        if($sub['status'] == 'Archived') $statusClass = 'bg-gray-100 text-gray-700 border border-gray-200';
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?> whitespace-nowrap">
                                                        <?php echo $sub['status'] === 'Archived' ? 'Archived (Superseded)' : $sub['status']; ?>
                                                     </span>
                                                </td>

                                                <td class="px-3.5 py-3 no-print">
                                                    <div class="flex flex-wrap md:inline-flex items-center gap-1.5 md:flex-nowrap">
                                                        <button 
                                                            onclick="openUniversalFileViewer(
                                                                '<?php echo htmlspecialchars($sub['file_path']); ?>',
                                                                '<?php echo htmlspecialchars($sub['preview_path'] ?? $sub['file_path']); ?>',
                                                                '<?php echo htmlspecialchars($sub['file_name']); ?>'
                                                            )"
                                                            class="px-2.5 py-1.5 bg-[#800000] text-white text-[11px] font-bold rounded-lg hover:bg-red-950 hover:shadow-sm transition-all uppercase inline-flex items-center justify-center gap-1 whitespace-nowrap">
                                                            <i class="fas fa-eye text-[9px]"></i> PREVIEW
                                                        </button>

                                                        <?php if(!empty($sub['has_edits'])): ?>
                                                            <button type="button"
                                                                onclick="openDocumentEditor(<?php echo (int)$sub['document_id']; ?>, 'view')"
                                                                class="px-2.5 py-1.5 bg-amber-100 text-amber-900 border border-amber-300 text-[11px] font-bold rounded-lg hover:bg-amber-200 transition-all uppercase inline-flex items-center justify-center gap-1 whitespace-nowrap"
                                                                title="View QMO reviewer highlights and edits">
                                                                <i class="fas fa-highlighter text-[9px]"></i> VIEW EDITS
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if($sub['status'] == 'Revision'): ?>
                                                            <a href="submit_document.php?re-submit=<?php echo $sub['document_id']; ?>" 
                                                               class="px-2.5 py-1.5 bg-[#800000] text-white text-[11px] font-bold rounded-lg hover:bg-red-950 hover:shadow-sm transition-all uppercase inline-flex items-center justify-center gap-1 whitespace-nowrap">
                                                                <i class="fas fa-edit text-[9px]"></i> RE-SUBMIT
                                                            </a>
                                                        <?php elseif($sub['status'] == 'Awaiting Review'): ?>
                                                            <button onclick="confirmDeleteSubmission(<?php echo $sub['document_id']; ?>)" 
                                                                    class="px-2.5 py-1.5 bg-red-50 text-[#800000] border border-red-200 text-[11px] font-bold rounded-lg hover:bg-[#800000] hover:text-white transition-all uppercase inline-flex items-center justify-center gap-1 whitespace-nowrap">
                                                                <i class="fas fa-trash-alt text-[9px]"></i> DELETE
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if(!empty($sub['previous_versions'])): ?>
                                                            <button type="button" 
                                                                    onclick="toggleVersionHistory('<?php echo htmlspecialchars($sub['series_id']); ?>')" 
                                                                    class="px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 text-[11px] font-bold rounded-lg transition-all uppercase inline-flex items-center justify-center gap-1 shadow-xs cursor-pointer whitespace-nowrap">
                                                                <i class="fas fa-history text-[9px] text-amber-700"></i> REVISIONS (<?php echo count($sub['previous_versions']); ?>)
                                                                <i class="fas fa-chevron-down text-[8px] transition-transform duration-200" id="chevron-<?php echo htmlspecialchars($sub['series_id']); ?>"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php if(!empty($sub['review_remarks'])): ?>
                                                <tr class="bg-gray-50/30">
                                                    <td colspan="6" class="px-3.5 py-2.5">
                                                        <div class="flex items-start gap-2 text-xs">
                                                            <span class="font-bold text-gray-500">Reviewer Remarks:</span>
                                                            <span class="text-gray-600 italic"><?php echo htmlspecialchars($sub['review_remarks']); ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>

                                            <?php if(!empty($sub['previous_versions'])): ?>
                                                <tr id="history-<?php echo htmlspecialchars($sub['series_id']); ?>" class="hidden bg-slate-50/90 border-t border-b border-gray-200 version-history-row" data-series-id="<?php echo htmlspecialchars($sub['series_id']); ?>">
                                                    <td colspan="6" class="px-4 py-4">
                                                        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden p-4">
                                                            <div class="flex items-center justify-between pb-3 border-b border-gray-100 mb-3">
                                                                <span class="text-xs font-bold text-gray-800 flex items-center gap-2">
                                                                    <i class="fas fa-history text-ua-maroon"></i> Previous Version History — <?php echo htmlspecialchars($sub['description'] . ' (' . $sub['doc_code'] . ')'); ?>
                                                                </span>
                                                                <span class="text-[11px] font-semibold text-gray-500">
                                                                    <?php echo count($sub['previous_versions']); ?> Archived <?php echo count($sub['previous_versions']) === 1 ? 'Revision' : 'Revisions'; ?>
                                                                </span>
                                                            </div>
                                                            
                                                            <div class="overflow-x-auto">
                                                                <table class="w-full text-left">
                                                                    <thead>
                                                                        <tr class="text-[10px] font-bold text-gray-400 uppercase tracking-wider border-b border-gray-100">
                                                                            <th class="pb-2">Revision / Version</th>
                                                                            <th class="pb-2">File Name</th>
                                                                            <th class="pb-2">Submitted At</th>
                                                                            <th class="pb-2">Status</th>
                                                                            <th class="pb-2 text-right">Action</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody class="divide-y divide-gray-100 text-xs">
                                                                        <?php foreach($sub['previous_versions'] as $prev): ?>
                                                                            <tr class="hover:bg-amber-50/30 transition-colors">
                                                                                <td class="py-2.5 font-bold text-gray-800">
                                                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-[10px] border border-gray-200">
                                                                                        <i class="fas fa-file-invoice text-gray-500 text-[9px]"></i>
                                                                                        <?php echo getRevisionText($prev['version_number']); ?> (v<?php echo htmlspecialchars($prev['version_number']); ?>)
                                                                                    </span>
                                                                                </td>
                                                                                <td class="py-2.5 font-medium text-gray-700 max-w-[200px] truncate" title="<?php echo htmlspecialchars($prev['file_name']); ?>">
                                                                                    <?php echo htmlspecialchars($prev['file_name']); ?>
                                                                                </td>
                                                                                <td class="py-2.5 text-gray-500 whitespace-nowrap">
                                                                                    <?php echo date('M d, Y, g:i A', strtotime($prev['submitted_at'])); ?>
                                                                                </td>
                                                                                <td class="py-2.5">
                                                                                    <span class="bg-gray-100 text-gray-600 border border-gray-200 text-[10px] font-bold px-2 py-0.5 rounded-full inline-flex items-center gap-1">
                                                                                        <i class="fas fa-archive text-gray-400 text-[9px]"></i> Archived (Superseded)
                                                                                    </span>
                                                                                </td>
                                                                                <td class="py-2.5 text-right">
                                                                                    <div class="inline-flex items-center gap-1.5">
                                                                                        <?php if (!empty($prev['has_edits'])): ?>
                                                                                            <button type="button" onclick="openDocumentEditor(<?php echo (int)$prev['document_id']; ?>, 'view')" class="px-2.5 py-1 bg-amber-100 text-amber-900 border border-amber-300 text-[10px] font-bold rounded-lg hover:bg-amber-200 transition uppercase inline-flex items-center gap-1">
                                                                                                <i class="fas fa-highlighter text-[8px]"></i> Edits
                                                                                            </button>
                                                                                        <?php endif; ?>
                                                                                        <button onclick="openUniversalFileViewer(
                                                                                            '<?php echo htmlspecialchars($prev['file_path']); ?>',
                                                                                            '<?php echo htmlspecialchars($prev['preview_path'] ?? $prev['file_path']); ?>',
                                                                                            '<?php echo htmlspecialchars($prev['file_name']); ?>'
                                                                                        )" class="px-2.5 py-1 bg-[#800000] text-white text-[10px] font-bold rounded-lg hover:bg-red-950 transition uppercase inline-flex items-center gap-1">
                                                                                            <i class="fas fa-eye text-[8px]"></i> Preview
                                                                                        </button>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div id="delete-confirm-modal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
                <div class="bg-white rounded-xl max-w-md w-full mx-4 overflow-hidden shadow-xl transform scale-95 transition-transform duration-300">
                    <div class="px-6 py-4 bg-[#800000] text-white flex items-center justify-between">
                        <h3 class="font-bold flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i> Delete Submission</h3>
                        <button onclick="closeDeleteModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="p-6">
                        <p class="text-sm text-gray-500 mb-6">Are you sure you want to delete this document submission? This action is permanent, and both the database record and uploaded file will be deleted.</p>
                        <div class="flex justify-end gap-3">
                            <button onclick="closeDeleteModal()" class="px-4 py-2 border border-gray-200 text-gray-700 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                                Cancel
                            </button>
                            <button id="confirm-delete-btn" class="px-4 py-2 bg-[#800000] text-white text-sm font-semibold rounded-xl hover:bg-[#600000] transition">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Universal File Viewer Modal will be injected here by universal-file-viewer.js -->
        </main>
    </div>

    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        function printDocument(filePath, fileName) {
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            if (['pdf'].includes(fileExt)) {
                // Open PDF in new window for printing
                window.open(filePath, '_blank');
            } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt)) {
                // Open image in new window for printing
                const printWindow = window.open(filePath, '_blank');
                printWindow.addEventListener('load', function() {
                    printWindow.print();
                });
            } else {
                alert('Print preview not available for this file type. Please download and print manually.');
            }
        }

        // Real-time auto-update submissions list without page refresh (guarded for PJAX)
        if (window._deanSubmissionsInterval) clearInterval(window._deanSubmissionsInterval);
        window._deanSubmissionsInterval = setInterval(async () => {
            if (!document.getElementById('submissionsTableBody')) {
                clearInterval(window._deanSubmissionsInterval);
                window._deanSubmissionsInterval = null;
                return;
            }
            try {
                // If modal is currently open, don't auto-refresh to prevent flickering or losing state
                const deleteModal = document.getElementById('delete-confirm-modal');
                if (deleteModal && !deleteModal.classList.contains('hidden')) {
                    return;
                }

                const url = window.location.href;
                const cacheBuster = (url.indexOf('?') !== -1 ? '&' : '?') + '_t=' + Date.now();
                const res = await fetch(url + cacheBuster, { cache: 'no-store' });
                const text = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                const newTbody = doc.getElementById('submissionsTableBody');
                const oldTbody = document.getElementById('submissionsTableBody');
                if (newTbody && oldTbody) {
                    oldTbody.innerHTML = newTbody.innerHTML;
                    restoreExpandedVersionRows();
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } catch (err) {
                console.error('Error auto-refreshing submissions:', err);
            }
        }, 5000);

        // Version History Expand/Collapse State Handler
        window.expandedVersionRows = window.expandedVersionRows || {};

        function toggleVersionHistory(seriesId) {
            const targetRow = document.getElementById('history-' + seriesId);
            const chevronIcon = document.getElementById('chevron-' + seriesId);
            if (!targetRow) return;

            if (targetRow.classList.contains('hidden')) {
                targetRow.classList.remove('hidden');
                if (chevronIcon) chevronIcon.classList.add('rotate-180');
                window.expandedVersionRows[seriesId] = true;
            } else {
                targetRow.classList.add('hidden');
                if (chevronIcon) chevronIcon.classList.remove('rotate-180');
                window.expandedVersionRows[seriesId] = false;
            }
        }

        function restoreExpandedVersionRows() {
            if (!window.expandedVersionRows) return;
            Object.keys(window.expandedVersionRows).forEach(seriesId => {
                if (window.expandedVersionRows[seriesId]) {
                    const targetRow = document.getElementById('history-' + seriesId);
                    const chevronIcon = document.getElementById('chevron-' + seriesId);
                    if (targetRow) {
                        targetRow.classList.remove('hidden');
                        if (chevronIcon) chevronIcon.classList.add('rotate-180');
                    }
                }
            });
        }

        // Restore any open version history rows on initial page load
        document.addEventListener('DOMContentLoaded', restoreExpandedVersionRows);

        // Delete Revision Submission Handlers
        let documentIdToDelete = null;

        function confirmDeleteSubmission(docId) {
            documentIdToDelete = docId;
            const modal = document.getElementById('delete-confirm-modal');
            if (!modal) return;
            
            modal.classList.remove('hidden');
            // Force reflow
            modal.offsetHeight;
            modal.classList.remove('opacity-0');
            modal.querySelector('.transform').classList.remove('scale-95');
            modal.querySelector('.transform').classList.add('scale-100');
            
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeDeleteModal() {
            const modal = document.getElementById('delete-confirm-modal');
            if (!modal) return;
            
            modal.classList.add('opacity-0');
            modal.querySelector('.transform').classList.remove('scale-100');
            modal.querySelector('.transform').classList.add('scale-95');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                documentIdToDelete = null;
            }, 300);
        }

        document.getElementById('confirm-delete-btn').addEventListener('click', async () => {
            if (!documentIdToDelete) return;
            
            const confirmBtn = document.getElementById('confirm-delete-btn');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Deleting...';
            
            try {
                const formData = new FormData();
                formData.append('document_id', documentIdToDelete);
                
                const response = await fetch('../../api/document_update_api.php?action=delete_revision_document', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('Document submission deleted successfully', 'success');
                    
                    // Remove row from table immediately for instant visual response
                    const row = document.querySelector(`.submission-row[data-doc-id="${documentIdToDelete}"]`);
                    if (row) {
                        row.style.transition = 'all 0.5s ease-out';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(-20px)';
                        setTimeout(() => {
                            row.remove();
                        }, 500);
                    }
                } else {
                    showToast(result.message || 'Failed to delete document');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Delete';
                closeDeleteModal();
            }
        });

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `fixed top-5 right-5 z-[9999] flex items-center gap-3 px-6 py-4 rounded-xl shadow-xl transition-all duration-300 transform translate-x-12 opacity-0 ${
                type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-700 text-white'
            }`;
            
            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-check-circle text-lg' : 'fas fa-exclamation-circle text-lg';
            toast.appendChild(icon);
            
            const text = document.createElement('span');
            text.className = 'text-sm font-semibold';
            text.textContent = message;
            toast.appendChild(text);
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-12', 'opacity-0');
            }, 50);
            
            setTimeout(() => {
                toast.classList.add('translate-x-12', 'opacity-0');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }

        // Expose functions to global scope for inline HTML handlers (vital for PJAX execution)
        window.confirmDeleteSubmission = confirmDeleteSubmission;
        window.closeDeleteModal = closeDeleteModal;
        window.toggleVersionHistory = toggleVersionHistory;
        window.restoreExpandedVersionRows = restoreExpandedVersionRows;
    </script>
</body>
</html>
