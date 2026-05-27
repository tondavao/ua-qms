<?php
session_start();
include "../../config/db.php";

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

$office_id = $_SESSION['office_id'] ?? 0;

// Fetch submitted and complied documents
$submitted_docs = [];
$sql = "SELECT d.academic_year, d.semester, d.status, dt.doc_code, dt.description, d.submitted_at 
        FROM documents d 
        JOIN document_types dt ON d.doc_type_id = dt.doc_type_id 
        WHERE d.office_id = ? AND d.status != 'Archived'
        ORDER BY d.academic_year DESC, d.semester DESC, d.submitted_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $office_id);
$stmt->execute();
$result = $stmt->get_result();
while($row = $result->fetch_assoc()) {
    $submitted_docs[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
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
        <?php include "../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">Compliance Status</h1>
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


                <!-- Removed charts per user request -->

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
                                <th>Document Code</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($submitted_docs)): ?>
                                <tr><td colspan="6" style="text-align: center; padding: 30px;">No documents submitted yet.</td></tr>
                            <?php else: ?>
                                <?php foreach($submitted_docs as $doc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($doc['semester']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($doc['academic_year']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($doc['doc_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($doc['description']); ?></td>
                                        <td>
                                            <?php if ($doc['status'] === 'Approved'): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-bold rounded-full"><i class="fas fa-check-circle"></i> Complied</span>
                                            <?php elseif ($doc['status'] === 'Awaiting Review'): ?>
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-bold rounded-full"><i class="fas fa-clock"></i> Submitted</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-bold rounded-full"><?php echo htmlspecialchars($doc['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($doc['submitted_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </div>
                </div>
            </div>
        </main>
    </div>

</body>
</html>
