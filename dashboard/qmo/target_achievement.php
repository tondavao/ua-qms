<?php
/**
 * Target Achievement Dashboard
 * Feature 3: Target achievement display (e.g., 1/8) per office
 * Feature 7: Officer compliance tracking
 */

session_start();
include "../../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header('Location: ../../index.php');
    exit();
}

$quarter = $_GET['quarter'] ?? '1st Quarter';
$office_id = $_GET['office_id'] ?? null;

// Fetch academic years
$academic_years = [];
$sql_ay = "SELECT year_range, is_active FROM academic_years ORDER BY year_range DESC";
$res_ay = $conn->query($sql_ay);
$active_ay = date('Y') . '-' . (date('Y') + 1);
if ($res_ay) {
    while ($row = $res_ay->fetch_assoc()) {
        $academic_years[] = $row;
        if ($row['is_active']) {
            $active_ay = $row['year_range'];
        }
    }
}

$academic_year = $_GET['academic_year'] ?? $active_ay;
$office_id = $_GET['office_id'] ?? null;

// Fetch all offices
$offices = [];
$sql_offices = "SELECT office_id, office_name, office_code FROM offices WHERE status = 'Active' ORDER BY office_name";
$result_offices = $conn->query($sql_offices);
while ($row = $result_offices->fetch_assoc()) {
    $offices[] = $row;
}

// Fetch achievements for all offices
$achievements = [];
if ($office_id) {
    // Single office view
    $sql = "SELECT dt.doc_type_id, dt.doc_code, dt.description, dt.required, dt.frequency,
                   1 as target_count,
                   (SELECT CASE WHEN COUNT(d.document_id) > 0 THEN 1 ELSE 0 END 
                    FROM documents d 
                    WHERE d.office_id = ? 
                      AND d.doc_type_id = dt.doc_type_id 
                      AND d.academic_year = ? 
                      AND d.semester = ? 
                      AND d.status = 'Approved') as achieved_count,
                   ta.achievement_date
            FROM document_types dt
            LEFT JOIN target_achievements ta ON dt.doc_type_id = ta.doc_type_id 
                                              AND ta.office_id = ? 
                                              AND ta.quarter = ? 
                                              AND ta.year = ?
            WHERE dt.required = 'Yes'
            ORDER BY dt.doc_type_id";
    
    $stmt = $conn->prepare($sql);
    // Since target_achievements year column stores a single year, we extract the first 4 chars of academic_year for ta.year
    $ta_year = substr($academic_year, 0, 4);
    $stmt->bind_param("ississ", $office_id, $academic_year, $quarter, $office_id, $quarter, $ta_year);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_target = 0;
    $total_achieved = 0;

    while ($row = $result->fetch_assoc()) {
        $target_count = $row['target_count'] ?? 1;
        $achieved_count = $row['achieved_count'] ?? 0;
        
        $total_target += $target_count;
        $total_achieved += $achieved_count;

        $achievements[] = [
            'doc_type_id' => $row['doc_type_id'],
            'doc_code' => $row['doc_code'],
            'description' => $row['description'],
            'target' => $target_count,
            'achieved' => $achieved_count,
            'achievement_date' => $row['achievement_date'],
            'status' => $achieved_count >= $target_count ? 'completed' : 'pending'
        ];
    }
} else {
    // All offices view
    $sql = "SELECT o.office_id, o.office_name, o.office_code,
                   (SELECT COUNT(*) FROM document_types WHERE required = 'Yes') as total_target,
                   (SELECT COUNT(DISTINCT d.doc_type_id) 
                    FROM documents d 
                    JOIN document_types dtt ON d.doc_type_id = dtt.doc_type_id
                    WHERE d.office_id = o.office_id 
                      AND d.academic_year = ? 
                      AND d.semester = ? 
                      AND d.status = 'Approved'
                      AND dtt.required = 'Yes') as total_achieved
            FROM offices o
            WHERE o.status = 'Active'
            ORDER BY (total_achieved / NULLIF((SELECT COUNT(*) FROM document_types WHERE required = 'Yes'), 0)) DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $academic_year, $quarter);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $total_target = $row['total_target'] ?? 0;
        $total_achieved = $row['total_achieved'] ?? 0;
        $completion = $total_target > 0 ? round(($total_achieved / $total_target) * 100) : 0;

        $achievements[] = [
            'office_id' => $row['office_id'],
            'office_name' => $row['office_name'],
            'office_code' => $row['office_code'],
            'achievement_display' => "$total_achieved/$total_target",
            'total_achieved' => $total_achieved,
            'total_target' => $total_target,
            'completion_percentage' => $completion
        ];
    }
}

// Get officer compliance if office_id is selected
$officer_compliance = null;
$total_officers = 0;
$complied_officers = 0;

if ($office_id) {
    $sql_officers = "SELECT u.user_id, u.full_name, u.email, u.role,
                            (SELECT COUNT(DISTINCT d.doc_type_id) 
                             FROM documents d 
                             JOIN document_types dtt ON d.doc_type_id = dtt.doc_type_id
                             WHERE d.office_id = u.office_id 
                               AND d.academic_year = ? 
                               AND d.semester = ? 
                               AND d.status = 'Approved'
                               AND dtt.required = 'Yes') as complied_docs,
                            (SELECT COUNT(*) FROM document_types WHERE required = 'Yes') as target_docs
                     FROM users u
                     WHERE u.office_id = ? AND u.role IN ('Dean', 'Office')
                     ORDER BY u.full_name";
    
    $stmt_officers = $conn->prepare($sql_officers);
    $stmt_officers->bind_param("ssi", $academic_year, $quarter, $office_id);
    $stmt_officers->execute();
    $result_officers = $stmt_officers->get_result();

    $officer_compliance = [];
    while ($officer = $result_officers->fetch_assoc()) {
        $total_officers++;
        if ($officer['complied_docs'] >= $officer['target_docs'] && $officer['target_docs'] > 0) {
            $complied_officers++;
        }
        $officer_compliance[] = $officer;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Target Achievement | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
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
<body class="bg-gray-50 font-sans" data-user-role="<?php echo htmlspecialchars($_SESSION['role']); ?>">
    <script>localStorage.setItem('userRole', '<?php echo htmlspecialchars($_SESSION['role']); ?>');</script>
    <div class="flex h-screen overflow-hidden">
        <?php include "../../includes/sidebar_qmo.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Target Achievement</h1>
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
            <div class="flex-1 overflow-auto p-8">


                <!-- Filters -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Quarter</label>
                            <select onchange="updateFilters()" id="quarterSelect" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                                <option value="1st Quarter" <?php echo $quarter === '1st Quarter' ? 'selected' : ''; ?>>1st Quarter</option>
                                <option value="2nd Quarter" <?php echo $quarter === '2nd Quarter' ? 'selected' : ''; ?>>2nd Quarter</option>
                                <option value="3rd Quarter" <?php echo $quarter === '3rd Quarter' ? 'selected' : ''; ?>>3rd Quarter</option>
                                <option value="4th Quarter" <?php echo $quarter === '4th Quarter' ? 'selected' : ''; ?>>4th Quarter</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Academic Year</label>
                            <select onchange="updateFilters()" id="yearSelect" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                                <?php foreach ($academic_years as $ay): ?>
                                    <option value="<?php echo htmlspecialchars($ay['year_range']); ?>" <?php echo $academic_year === $ay['year_range'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ay['year_range']); ?>
                                        <?php if ($ay['is_active']) echo " (Active)"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Office</label>
                            <select onchange="updateFilters()" id="officeSelect" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-ua-maroon outline-none">
                                <option value="">All Offices</option>
                                <?php foreach ($offices as $off): ?>
                                    <option value="<?php echo $off['office_id']; ?>" <?php echo $office_id == $off['office_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($off['office_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button onclick="resetFilters()" class="w-full px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition font-semibold">
                                <i class="fas fa-redo mr-2"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($office_id && !empty($achievements)): ?>
                    <!-- Single Office Detail View -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                        <!-- Achievement Summary -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">ACHIEVEMENT</h3>
                            <div class="text-4xl font-bold text-ua-maroon mb-2">
                                <?php 
                                $total_target = array_sum(array_column($achievements, 'target'));
                                $total_achieved = array_sum(array_column($achievements, 'achieved'));
                                echo "$total_achieved/$total_target";
                                ?>
                            </div>
                            <p class="text-sm text-gray-600">Documents Submitted</p>
                            <div class="mt-4 w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-ua-maroon h-2 rounded-full" style="width: <?php echo $total_target > 0 ? ($total_achieved / $total_target * 100) : 0; ?>%"></div>
                            </div>
                        </div>

                        <!-- Completion Percentage -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="text-sm font-semibold text-gray-600 mb-2">COMPLETION</h3>
                            <div class="text-4xl font-bold text-green-600 mb-2">
                                <?php echo $total_target > 0 ? round(($total_achieved / $total_target) * 100) : 0; ?>%
                            </div>
                            <p class="text-sm text-gray-600">Overall Progress</p>
                        </div>

                        <!-- Officer Compliance -->
                        <?php if ($officer_compliance !== null): ?>
                            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                                <h3 class="text-sm font-semibold text-gray-600 mb-2">OFFICER COMPLIANCE</h3>
                                <div class="text-4xl font-bold text-blue-600 mb-2">
                                    <?php echo "$complied_officers/$total_officers"; ?>
                                </div>
                                <p class="text-sm text-gray-600">Officers Complied</p>
                                <p class="text-xs text-gray-500 mt-2">
                                    <?php echo $total_officers > 0 ? round(($complied_officers / $total_officers) * 100) : 0; ?>% Compliance Rate
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Document Achievement Details -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-6">Document Achievement Details</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200">
                                        <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Document Type</th>
                                        <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Description</th>
                                        <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Target</th>
                                        <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Achieved</th>
                                        <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($achievements as $ach): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4">
                                                <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($ach['doc_code']); ?></span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="text-gray-600"><?php echo htmlspecialchars($ach['description']); ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <span class="font-semibold text-gray-900"><?php echo $ach['target']; ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <span class="font-semibold text-gray-900"><?php echo $ach['achieved']; ?></span>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <?php if ($ach['status'] === 'completed'): ?>
                                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                        <i class="fas fa-check-circle"></i> Completed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800">
                                                        <i class="fas fa-clock"></i> Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Officer Compliance Details -->
                    <?php if ($officer_compliance !== null && !empty($officer_compliance)): ?>
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-6">Officer Compliance Status</h2>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-200">
                                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Officer Name</th>
                                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Email</th>
                                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Role</th>
                                            <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Complied Docs</th>
                                            <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($officer_compliance as $officer): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4">
                                                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($officer['full_name']); ?></span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($officer['email']); ?></span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <span class="text-gray-600"><?php echo htmlspecialchars($officer['role']); ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <span class="font-semibold text-gray-900"><?php echo $officer['complied_docs']; ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <?php if ($officer['complied_docs'] >= $officer['target_docs'] && $officer['target_docs'] > 0): ?>
                                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                            <i class="fas fa-check"></i> Complied
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800" title="<?php echo $officer['complied_docs']; ?> out of <?php echo $officer['target_docs']; ?> required documents submitted">
                                                            <i class="fas fa-times"></i> Not Complied (<?php echo $officer['complied_docs']; ?>/<?php echo $officer['target_docs']; ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- All Offices Overview -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-bold text-gray-800 mb-6">Office Achievement Overview</h2>
                        
                        <?php if (empty($achievements)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-inbox text-gray-400 text-4xl mb-4 block"></i>
                                <p class="text-gray-600 font-semibold">No achievement data found.</p>
                                <p class="text-gray-500 text-sm mt-1">Achievement data will appear here when documents are submitted and reviewed.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-200">
                                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Office</th>
                                            <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Achievement</th>
                                            <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Completion %</th>
                                            <th class="px-6 py-3 text-left text-sm font-semibold text-gray-700">Progress</th>
                                            <th class="px-6 py-3 text-center text-sm font-semibold text-gray-700">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($achievements as $ach): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4">
                                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($ach['office_name']); ?></div>
                                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($ach['office_code']); ?></div>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <span class="text-2xl font-bold text-ua-maroon"><?php echo $ach['achievement_display']; ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <span class="text-lg font-semibold <?php echo $ach['completion_percentage'] >= 80 ? 'text-green-600' : 'text-orange-600'; ?>">
                                                        <?php echo $ach['completion_percentage']; ?>%
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                                        <div class="bg-ua-maroon h-2 rounded-full" style="width: <?php echo $ach['completion_percentage']; ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                    <a href="target_achievement.php?office_id=<?php echo $ach['office_id']; ?>&quarter=<?php echo urlencode($quarter); ?>&academic_year=<?php echo urlencode($academic_year); ?>" class="inline-flex items-center gap-2 px-3 py-1 bg-ua-maroon text-white rounded hover:bg-opacity-90 transition text-sm">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function updateFilters() {
            const quarter = document.getElementById('quarterSelect').value;
            const academic_year = document.getElementById('yearSelect').value;
            const office = document.getElementById('officeSelect').value;
            
            let url = 'target_achievement.php?quarter=' + encodeURIComponent(quarter) + '&academic_year=' + encodeURIComponent(academic_year);
            if (office) {
                url += '&office_id=' + office;
            }
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = 'target_achievement.php';
        }
    

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
    </script>
</body>
</html>

