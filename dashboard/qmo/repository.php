<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin'){ 
    header("Location: ../../index.php"); 
    exit();
}
require_once "../../config/db.php";

$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
if ($settings_result) {
    while ($row = $settings_result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$active_year = $settings['active_academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
$active_semester = $settings['active_semester'] ?? '1st Quarter';
$active_year_display = $active_year;
if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
    $active_year_display = (string)max(array_map('intval', $matches[0]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Document Repository</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
    <script src="../../assets/js/universal-file-viewer.js" defer></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #F9FAFB;
        }
        .bg-ua-maroon { background-color: #800000; }
        .text-ua-maroon { color: #800000; }
        
        .status-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 9999px;
            text-transform: uppercase;
        }
        .status-approved { background-color: #f0fdf4; color: #16a34a; }
        .status-pending { background-color: #fff7ed; color: #ea580c; }
        .status-revision { background-color: #fefce8; color: #ca8a04; }
        
        .loading-spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #800000;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include "../../includes/sidebar_qmo.php"; ?>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden">
            <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0 no-print">
                <div class="flex items-center gap-4">
                    <div>
                        <h1 class="text-lg font-bold text-gray-900 leading-tight">Document Repository</h1>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <!-- Clock -->
                    <div id="clock" class="text-sm text-gray-500 font-medium"></div>
                    <div class="flex items-center gap-2">
                        <!-- Message Inbox -->
                        <button id="inbox-btn" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-envelope text-lg"></i>
                            <span id="message-badge" class="absolute top-1 right-1 w-4 h-4 bg-[#800000] text-white text-[10px] flex items-center justify-center rounded-full hidden">0</span>
                        </button>
                        <!-- Notifications -->
                        <button id="notification-bell" class="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition">
                            <i class="fas fa-bell text-lg"></i>
                            <span id="notification-badge" class="absolute top-1 right-1 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center hidden">0</span>
                        </button>
                        <!-- Profile Dropdown -->
                        <div class="relative">
                            <button id="profile-btn" class="w-10 h-10 rounded-full bg-[#800000] flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition">
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
                <div class="max-w-7xl mx-auto">


                     <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
                        <div class="bg-white border border-gray-200 rounded-lg p-5">
                            <p class="text-sm font-medium text-gray-500">Total Documents</p>
                            <p id="stat-total" class="mt-2 text-3xl font-bold text-gray-900">0</p>
                            <p class="mt-2 text-xs text-gray-500">In repository</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-5">
                            <p class="text-sm font-medium text-gray-500">Recent (7 Days)</p>
                            <p id="stat-recent" class="mt-2 text-3xl font-bold text-green-700">0</p>
                            <p class="mt-2 text-xs text-gray-500">New uploads</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-5">
                            <p class="text-sm font-medium text-gray-500">Document Types</p>
                            <p id="stat-types" class="mt-2 text-3xl font-bold text-blue-700">0</p>
                            <p class="mt-2 text-xs text-gray-500">Categories</p>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-5">
                            <p class="text-sm font-medium text-gray-500">Contributing Offices</p>
                            <p id="stat-offices" class="mt-2 text-3xl font-bold text-purple-700">0</p>
                            <p class="mt-2 text-xs text-gray-500">Units</p>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-6 no-print">
                        <div class="flex flex-col xl:flex-row gap-2 items-end">
                            <!-- Search Input -->
                            <div class="w-full xl:w-40">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Search</label>
                                <div class="relative">
                                    <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-400"></i>
                                    <input type="text" id="search-input" placeholder="Search files..." class="w-full pl-8 pr-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                </div>
                            </div>

                            <!-- Document Type Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Doc Type</label>
                                <select id="doc-type-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Types</option>
                                </select>
                            </div>

                            <!-- Office Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Office</label>
                                <select id="office-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Offices</option>
                                </select>
                            </div>

                            <!-- Academic Year Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Year</label>
                                <select id="ay-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Years</option>
                                </select>
                            </div>

                            <!-- Quarter Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Period</label>
                                <select id="semester-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Quarters</option>
                                </select>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex gap-1.5 shrink-0 mt-2 xl:mt-0">
                                <button id="reset-filters-btn" class="px-3 py-1.5 bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold hover:bg-[#800000] hover:text-white transition flex items-center justify-center gap-1 shadow-sm" title="Reset Filters">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                                <button id="apply-filters-btn" class="px-3 py-1.5 bg-[#800000] text-white rounded-lg text-xs font-bold hover:bg-red-900 transition flex items-center justify-center gap-1 shadow-sm">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                                <button onclick="window.print()" class="px-3 py-1.5 bg-gray-800 text-white rounded-lg text-xs font-bold hover:bg-black transition flex items-center justify-center gap-1 shadow-sm">
                                    <i class="fas fa-print"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Table -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="font-bold text-gray-900">Submitted Documents</h3>
                        </div>

                        <!-- Loading State -->
                        <div id="loading-state" class="p-12 text-center hidden">
                            <div class="flex justify-center mb-4">
                                <div class="loading-spinner"></div>
                            </div>
                            <p class="text-gray-500">Loading documents...</p>
                        </div>

                        <!-- Empty State -->
                        <div id="empty-state" class="p-12 text-center hidden">
                            <i class="fas fa-inbox text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">No documents found matching your criteria</p>
                        </div>

                        <!-- Documents Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-left" id="documents-table">
                                <thead>
                                    <tr class="bg-gray-50 text-gray-400 text-[10px] uppercase tracking-wider font-bold border-b border-gray-100">
                                        <th class="px-6 py-4">Document Name</th>
                                        <th class="px-6 py-4">Type</th>
                                        <th class="px-6 py-4">Office</th>
                                        <th class="px-6 py-4">Period</th>
                                        <th class="px-6 py-4">Status</th>
                                        <th class="px-6 py-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="documents-container" class="divide-y divide-gray-100">
                                    <!-- Documents will be loaded here -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div id="pagination-container" class="p-6 border-t border-gray-100 flex items-center justify-between hidden">
                            <div class="text-sm text-gray-600">
                                Page <span id="current-page">1</span> of <span id="total-pages">1</span>
                            </div>
                            <div class="flex gap-2">
                                <button id="prev-page-btn" class="px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium hover:bg-gray-50 transition">Previous</button>
                                <button id="next-page-btn" class="px-4 py-2 border border-gray-200 rounded-lg text-sm font-medium hover:bg-gray-50 transition">Next</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Profile Dropdown Styles -->
    <style>
        .profile-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            z-index: 50;
            min-width: 220px;
            margin-top: 8px;
        }
        .profile-dropdown.active {
            display: block;
        }
        .profile-dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        .profile-dropdown-header p {
            margin: 0;
            font-weight: 600;
            color: #111827;
            font-size: 14px;
        }
        .profile-dropdown-header span {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        .profile-dropdown-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: #374151;
            text-decoration: none;
            transition: background-color 0.2s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
        }
        .profile-dropdown-item:hover {
            background-color: #f3f4f6;
        }
        .profile-dropdown-item.logout {
            color: #dc2626;
            border-top: 1px solid #e5e7eb;
        }
        .profile-dropdown-item.logout:hover {
            background-color: #fee2e2;
        }
        .profile-dropdown-item i {
            margin-right: 12px;
            width: 16px;
            text-align: center;
        }
    </style>

    <!-- Delete Confirmation Modal -->
    <div id="delete-confirm-modal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-2xl max-w-md w-full mx-4 overflow-hidden shadow-xl transform scale-95 transition-transform duration-300">
            <div class="p-6">
                <div class="w-12 h-12 rounded-full bg-red-50 flex items-center justify-center text-[#800000] mb-4">
                    <i data-lucide="alert-triangle" class="w-6 h-6"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Delete Document</h3>
                <p class="text-sm text-gray-500 mb-6">Are you sure you want to delete this document from the repository? This action is permanent, and both the database record and uploaded file will be deleted.</p>
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
        let currentPage = 1;
        let currentFilters = {
            search: '',
            doc_type: '',
            office: '',
            status: 'Approved',
            academic_year: '',
            semester: ''
        };

        // Initialize repository
        async function initializeRepository() {
            await loadCategories();
            await loadStatistics();
            await loadDocuments();
        }

        // Load filter categories
        async function loadCategories() {
            try {
                const response = await fetch('../../api/repository_api.php?action=get_categories&type=all');
                const data = await response.json();

                if (data.success) {
                    // Populate document type filter
                    const docTypeSelect = document.getElementById('doc-type-filter');
                    data.doc_types.forEach(type => {
                        const option = document.createElement('option');
                        option.value = type.doc_code;
                        option.textContent = `${type.doc_code} - ${type.description}`;
                        docTypeSelect.appendChild(option);
                    });

                    // Populate office filter
                    const officeSelect = document.getElementById('office-filter');
                    data.offices.forEach(office => {
                        const option = document.createElement('option');
                        option.value = office.office_id;
                        option.textContent = `${office.office_code} - ${office.office_name}`;
                        officeSelect.appendChild(option);
                    });

                    // Populate academic years
                    const aySelect = document.getElementById('ay-filter');
                    if (aySelect && data.academic_years) {
                        data.academic_years.forEach(ay => {
                            const option = document.createElement('option');
                            option.value = ay.year_range;
                            option.textContent = ay.year_range;
                            aySelect.appendChild(option);
                        });
                    }

                    // Populate semesters
                    const semSelect = document.getElementById('semester-filter');
                    if (semSelect && data.semesters) {
                        data.semesters.forEach(sem => {
                            const option = document.createElement('option');
                            option.value = sem.semester_name;
                            option.textContent = sem.semester_name;
                            semSelect.appendChild(option);
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading categories:', error);
            }
        }

        // Load repository statistics
        async function loadStatistics() {
            try {
                const response = await fetch('../../api/repository_api.php?action=get_repository_stats');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('stat-total').textContent = data.total_documents;
                    document.getElementById('stat-recent').textContent = data.recent_uploads_7days;
                    document.getElementById('stat-types').textContent = data.by_type.length;
                    document.getElementById('stat-offices').textContent = data.by_office.length;
                }
            } catch (error) {
                console.error('Error loading statistics:', error);
            }
        }

        // Load documents with filters
        async function loadDocuments(page = 1) {
            currentPage = page;
            const params = new URLSearchParams({
                action: 'get_repository_documents',
                search: currentFilters.search,
                doc_type: currentFilters.doc_type,
                office: currentFilters.office,
                status: currentFilters.status,
                academic_year: currentFilters.academic_year,
                semester: currentFilters.semester,
                page: page,
                limit: 20
            });

            document.getElementById('loading-state').classList.remove('hidden');
            document.getElementById('documents-container').innerHTML = '';
            document.getElementById('empty-state').classList.add('hidden');
            document.getElementById('pagination-container').classList.add('hidden');

            try {
                const response = await fetch(`../../api/repository_api.php?${params}`);
                const data = await response.json();

                document.getElementById('loading-state').classList.add('hidden');

                if (data.success && data.documents.length > 0) {
                    renderDocuments(data.documents);
                    renderPagination(data.pagination);
                } else {
                    document.getElementById('empty-state').classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error loading documents:', error);
                document.getElementById('loading-state').classList.add('hidden');
                document.getElementById('empty-state').classList.remove('hidden');
            }
        }

        // Render documents list
        function renderDocuments(documents) {
            const container = document.getElementById('documents-container');
            container.innerHTML = '';

            documents.forEach(doc => {
                const cleanedFilePath = doc.file_path ? doc.file_path.replace(/\\/g, '/') : '';
                const cleanedPreviewPath = doc.preview_path ? doc.preview_path.replace(/\\/g, '/') : '';
                const escapedFileName = escapeHtml(doc.file_name);

                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50 transition-colors';
                row.innerHTML = `
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-file text-gray-400"></i>
                            <div>
                                <div class="text-sm font-medium text-gray-900">${escapedFileName}</div>
                                <div class="text-xs text-gray-500">${formatDate(doc.submitted_at)}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="text-sm font-medium text-gray-900">${doc.doc_code}</span>
                        <div class="text-xs text-gray-500">${doc.description}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">${doc.office_code}</div>
                        <div class="text-xs text-gray-500">${doc.office_name}</div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="text-sm font-medium text-gray-900">${doc.semester || 'N/A'}</span>
                        <div class="text-xs text-gray-500">AY ${doc.academic_year || 'N/A'}</div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="status-badge ${getStatusClass(doc.status)}">${doc.status}</span>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex justify-end gap-2">
                            <button onclick="openUniversalFileViewer(
                                '${cleanedFilePath.replace(/'/g, "\\'")}',
                                '${cleanedPreviewPath.replace(/'/g, "\\'")}',
                                '${escapedFileName.replace(/'/g, "\\'")}'
                            )"
                                class="p-2 text-gray-400 hover:text-[#800000] transition" title="Preview">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="confirmDeleteDocument(${doc.document_id})"
                                class="p-2 text-gray-400 hover:text-red-600 transition" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>
                `;
                container.appendChild(row);
            });
        }

        // Render pagination controls
        function renderPagination(pagination) {
            if (pagination.total_pages > 1) {
                document.getElementById('pagination-container').classList.remove('hidden');
                document.getElementById('current-page').textContent = pagination.current_page;
                document.getElementById('total-pages').textContent = pagination.total_pages;

                document.getElementById('prev-page-btn').disabled = pagination.current_page === 1;
                document.getElementById('next-page-btn').disabled = pagination.current_page === pagination.total_pages;
            }
        }

        // Event listeners
        document.getElementById('apply-filters-btn').addEventListener('click', () => {
            currentFilters.search = document.getElementById('search-input').value;
            currentFilters.doc_type = document.getElementById('doc-type-filter').value;
            currentFilters.office = document.getElementById('office-filter').value;
            currentFilters.academic_year = document.getElementById('ay-filter').value;
            currentFilters.semester = document.getElementById('semester-filter').value;
            loadDocuments(1);
        });

        document.getElementById('reset-filters-btn').addEventListener('click', () => {
            document.getElementById('search-input').value = '';
            document.getElementById('doc-type-filter').value = '';
            document.getElementById('office-filter').value = '';
            document.getElementById('ay-filter').value = '';
            document.getElementById('semester-filter').value = '';
            currentFilters = { search: '', doc_type: '', office: '', status: 'Approved', academic_year: '', semester: '' };
            loadDocuments(1);
        });

        document.getElementById('prev-page-btn').addEventListener('click', () => {
            if (currentPage > 1) loadDocuments(currentPage - 1);
        });

        document.getElementById('next-page-btn').addEventListener('click', () => {
            loadDocuments(currentPage + 1);
        });

        // Enter key to search
        document.getElementById('search-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                document.getElementById('apply-filters-btn').click();
            }
        });

        // Helper functions
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        // HTML escaping helper
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        function getStatusClass(status) {
            if (status === 'Approved') return 'status-approved';
            if (status === 'Revision') return 'status-revision';
            return 'status-pending';
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

        // Initialize on page load
        initializeRepository();

        // Real-time auto-update document repository list and stats
        setInterval(() => {
            // If delete modal is open, don't refresh to prevent flickering or losing state
            const deleteModal = document.getElementById('delete-confirm-modal');
            if (deleteModal && !deleteModal.classList.contains('hidden')) {
                return;
            }
            if (typeof loadDocuments === 'function') {
                loadDocuments(currentPage);
            }
            if (typeof loadStatistics === 'function') {
                loadStatistics();
            }
        }, 5000);

        // Delete Document Confirmation Handlers
        let documentIdToDelete = null;

        function confirmDeleteDocument(docId) {
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
                
                const response = await fetch('../../api/repository_api.php?action=delete_document', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('Document deleted successfully', 'success');
                    loadDocuments(currentPage);
                    loadStatistics();
                } else {
                    showToast(result.message || 'Failed to delete document', 'error');
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

