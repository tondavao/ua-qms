<?php
session_start();
require_once "../../config/db.php";
require_once "../../includes/system_settings.php";

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$office_id = $_SESSION['office_id'] ?? 0;

// Load active academic period settings
$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];

// Get list of academic years, semesters, and document types for filters
$academic_years_list = [];
$res_ay = $conn->query("SELECT year_range FROM academic_years ORDER BY year_range DESC");
if ($res_ay) {
    while ($row = $res_ay->fetch_assoc()) {
        $academic_years_list[] = $row['year_range'];
    }
}

$semesters_list = [];
$res_sem = $conn->query("SELECT semester_name FROM semesters ORDER BY semester_name ASC");
if ($res_sem) {
    while ($row = $res_sem->fetch_assoc()) {
        $semesters_list[] = $row['semester_name'];
    }
}

$doc_types_list = [];
$res_dt = $conn->query("SELECT doc_type_id, doc_code, description FROM document_types ORDER BY doc_code ASC");
if ($res_dt) {
    while ($row = $res_dt->fetch_assoc()) {
        $doc_types_list[] = $row;
    }
}

// Get filter inputs
$selected_ay = $_GET['ay'] ?? $active_year;
$selected_sem = $_GET['sem'] ?? $active_semester;
$selected_dt = $_GET['dt'] ?? 'All';
$search_query = $_GET['search'] ?? '';

// Fetch submitted and complied documents with filters
$submitted_docs = [];
$sql = "SELECT d.academic_year, d.semester, d.status, dt.doc_code, dt.description, d.submitted_at, 
               dr.reviewed_at AS approved_at
        FROM documents d 
        JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
        LEFT JOIN (
            SELECT document_id, MAX(reviewed_at) AS reviewed_at 
            FROM document_reviews 
            WHERE review_status = 'Approved' 
            GROUP BY document_id
        ) dr ON d.document_id = dr.document_id
        WHERE d.office_id = ? AND d.status != 'Archived'";

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

if ($selected_dt !== 'All') {
    $sql .= " AND d.doc_type_id = ?";
    $params[] = $selected_dt;
    $types .= "i";
}

if (!empty($search_query)) {
    $sql .= " AND (dt.doc_code LIKE ? OR dt.description LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

$sql .= " ORDER BY d.academic_year DESC, d.semester DESC, d.submitted_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    while($row = $result->fetch_assoc()) {
        $submitted_docs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Status | UA QMS</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        <?php require_once __DIR__ . "/../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-4 md:px-8 flex-shrink-0 sticky top-0 z-50">
                <div class="min-w-0">
                    <h1 class="text-base md:text-lg font-bold text-gray-900 leading-tight truncate">Compliance Status</h1>
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

                <!-- Filter Bar -->
                <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm mb-6 no-print">
                    <form method="GET" class="flex flex-col xl:flex-row gap-4 items-end w-full">
                        <!-- Search Input -->
                        <div class="w-full xl:w-48">
                            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Search</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-400"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search history..." class="w-full pl-8 pr-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
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

                        <!-- Action Buttons -->
                        <div class="flex gap-1.5 shrink-0 mt-2 xl:mt-0 w-full xl:w-auto justify-end">
                            <a href="compliance_status.php" class="px-3 py-1.5 bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white transition flex items-center justify-center gap-1 shadow-sm" title="Reset Filters">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                            <button type="submit" class="px-3 py-1.5 bg-[#800000] text-white rounded-lg text-xs font-bold hover:bg-red-900 transition flex items-center justify-center gap-1 shadow-sm">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                        <i class="fas fa-check-circle text-ua-maroon"></i>
                        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Compliance History</h2>
                    </div>
                
                    <div class="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Year</th>
                                <th>Document Type</th>
                                <th>Status</th>
                                <th>Date Approved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($submitted_docs)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 30px;">No documents submitted yet.</td></tr>
                            <?php else: ?>
                                <?php foreach($submitted_docs as $doc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($doc['semester']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($doc['academic_year']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($doc['doc_code']); ?></strong> - <?php echo htmlspecialchars($doc['description']); ?></td>
                                        <td>
                                            <?php if ($doc['status'] === 'Approved'): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-bold rounded-full"><i class="fas fa-check-circle"></i> Complied</span>
                                            <?php elseif ($doc['status'] === 'Awaiting Review'): ?>
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-bold rounded-full"><i class="fas fa-clock"></i> Submitted</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-bold rounded-full"><?php echo htmlspecialchars($doc['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($doc['status'] === 'Approved' && !empty($doc['approved_at'])) {
                                                echo date('M d, Y h:i A', strtotime($doc['approved_at']));
                                            } else {
                                                echo '—';
                                            }
                                            ?>
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

</body>
</html>
