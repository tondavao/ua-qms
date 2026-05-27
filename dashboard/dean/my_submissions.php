<?php
session_start();
include "../../config/db.php";

if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'Dean' && $_SESSION['role'] != 'Office')) {
    header('Location: ../../index.php');
    exit();
}

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

$selected_ay = $_GET['ay'] ?? $active_year;
$selected_sem = $_GET['sem'] ?? $active_semester;

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
            dr.reviewed_at
        FROM documents d
        JOIN document_types dt 
            ON d.doc_type_id = dt.doc_type_id
        LEFT JOIN document_reviews dr 
            ON d.document_id = dr.document_id
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

$sql .= " ORDER BY d.submitted_at DESC, dr.reviewed_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();

$result = $stmt->get_result();

// Group by document to only show latest review
$docs = [];

while($row = $result->fetch_assoc()) {
    if (!isset($docs[$row['document_id']])) {
        $docs[$row['document_id']] = $row;
    }
}

$submissions = array_values($docs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Submissions | UA QMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <script src="../../assets/js/universal-file-viewer.js" defer></script>
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
        <?php include "../../includes/sidebar_dean.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">My Submissions</h1>
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
                <div class="max-w-7xl mx-auto">


                    <!-- Filter Bar -->
                    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm mb-6 flex flex-col md:flex-row gap-4 justify-between items-center no-print">
                        <form method="GET" class="flex flex-wrap gap-4 items-center w-full md:w-auto">
                            <div class="flex items-center gap-2">
                                <label for="ay-select" class="text-sm font-semibold text-gray-700">Academic Year:</label>
                                <select id="ay-select" name="ay" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                                    <option value="All" <?php echo $selected_ay === 'All' ? 'selected' : ''; ?>>All Years</option>
                                    <?php foreach ($academic_years_list as $ay): ?>
                                        <option value="<?php echo htmlspecialchars($ay); ?>" <?php echo $selected_ay === $ay ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ay); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="sem-select" class="text-sm font-semibold text-gray-700">Period:</label>
                                <select id="sem-select" name="sem" onchange="this.form.submit()" class="px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                                    <option value="All" <?php echo $selected_sem === 'All' ? 'selected' : ''; ?>>All Quarters</option>
                                    <?php foreach ($semesters_list as $sem): ?>
                                        <option value="<?php echo htmlspecialchars($sem); ?>" <?php echo $selected_sem === $sem ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sem); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($selected_ay !== $active_year || $selected_sem !== $active_semester): ?>
                                <a href="my_submissions.php" class="px-3 py-1.5 bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white transition flex items-center justify-center gap-1 shadow-sm" title="Reset Filters">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            <?php endif; ?>
                        </form>
                        <div class="text-xs text-gray-500 font-medium">
                            Viewing: <span class="font-bold text-[#800000]"><?php echo htmlspecialchars($selected_sem); ?> (AY <?php echo htmlspecialchars($selected_ay); ?>)</span>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100 flex justify-between items-center no-print">
                            <h3 class="font-bold text-gray-900">Document Submission History</h3>
                            <a href="submit_document.php" class="px-4 py-2 bg-[#800000] text-white rounded-xl text-sm font-bold hover:bg-[#600000] transition-colors">
                                <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> New Submission
                            </a>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-bold">
                                    <tr>
                                        <th class="px-6 py-4">Document Type</th>
                                        <th class="px-6 py-4">Period</th>
                                        <th class="px-6 py-4">File Name</th>
                                        <th class="px-6 py-4">Submitted At</th>
                                        <th class="px-6 py-4">Status</th>

                                        <th class="px-6 py-4 no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100" id="submissionsTableBody">
                                    <?php if(empty($submissions)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                                No documents submitted yet for the selected period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($submissions as $sub): ?>
                                            <tr class="hover:bg-gray-50/50 transition-colors submission-row" data-doc-id="<?php echo $sub['document_id']; ?>">
                                                <td class="px-6 py-4">
                                                    <span class="font-bold text-gray-900"><?php echo $sub['doc_code']; ?></span>
                                                    <p class="text-[10px] text-gray-400"><?php echo htmlspecialchars($sub['description']); ?></p>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($sub['semester'] ?? 'N/A'); ?></span>
                                                    <p class="text-xs text-gray-400">AY <?php echo htmlspecialchars($sub['academic_year'] ?? 'N/A'); ?></p>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-600">
                                                    <?php echo htmlspecialchars($sub['file_name']); ?>
                                                    <?php if(isset($sub['version_number']) && $sub['version_number'] > 1): ?>
                                                        <span class="bg-[#800000] text-white text-[10px] font-bold px-2 py-0.5 rounded-md ml-2 shadow-sm border border-red-900"><i class="fas fa-code-branch mr-1"></i>Version <?php echo htmlspecialchars($sub['version_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 text-sm text-gray-500">
                                                    <?php echo date('M d, Y', strtotime($sub['submitted_at'])); ?>
                                                    <p class="text-[10px]"><?php echo date('H:i', strtotime($sub['submitted_at'])); ?></p>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <?php 
                                                        $statusClass = 'badge-awaiting';
                                                        if($sub['status'] == 'Approved') $statusClass = 'badge-approved';
                                                        if($sub['status'] == 'Revision') $statusClass = 'badge-revision';
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo $sub['status']; ?>
                                                    </span>
                                                </td>

                                                <td class="px-6 py-4 no-print">
    <div class="flex gap-2">

        <button 
            onclick="openUniversalFileViewer(
                '<?php echo htmlspecialchars($sub['file_path']); ?>',
                '<?php echo htmlspecialchars($sub['preview_path'] ?? $sub['file_path']); ?>',
                '<?php echo htmlspecialchars($sub['file_name']); ?>'
            )"
            class="px-4 py-2 bg-gray-100 text-gray-700 text-xs font-bold rounded-lg hover:bg-[#800000] hover:text-white transition-all">
            PREVIEW
        </button>

        <?php if($sub['status'] == 'Revision'): ?>
            <a href="submit_document.php?re-submit=<?php echo $sub['document_id']; ?>" 
               class="p-2 text-orange-600 hover:bg-orange-50 rounded-lg transition-colors" 
               title="Re-submit">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            </a>
            <button onclick="confirmDeleteSubmission(<?php echo $sub['document_id']; ?>)" 
                    class="p-2 text-[#800000] hover:bg-red-50 rounded-lg transition-colors" 
                    title="Delete Submission">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
        <?php endif; ?>

    </div>
</td>
                                            </tr>
                                            <?php if(!empty($sub['review_remarks'])): ?>
                                                <tr class="bg-gray-50/30">
                                                    <td colspan="6" class="px-6 py-3">
                                                        <div class="flex items-start gap-2 text-xs">
                                                            <span class="font-bold text-gray-500">Reviewer Remarks:</span>
                                                            <span class="text-gray-600 italic"><?php echo htmlspecialchars($sub['review_remarks']); ?></span>
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
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-confirm-modal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-2xl max-w-md w-full mx-4 overflow-hidden shadow-xl transform scale-95 transition-transform duration-300">
            <div class="p-6">
                <div class="w-12 h-12 rounded-full bg-red-50 flex items-center justify-center text-[#800000] mb-4">
                    <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Delete Submission</h3>
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

    <script>
        lucide.createIcons();

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

        // Real-time auto-update submissions list without page refresh
        setInterval(async () => {
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
                }
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } catch (err) {
                console.error('Error auto-refreshing submissions:', err);
            }
        }, 5000);

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
                    showToast(result.message || 'Failed to delete document',);
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
    </script>
</body>
</html>
