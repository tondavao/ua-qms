<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin'){ 
    header("Location: ../../index.php"); 
    exit();
}

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/system_settings.php";

$period = ua_qms_get_active_period($conn);
$active_year = $period['active_year'];
$active_semester = $period['active_semester'];
$active_year_display = $active_year;
if (preg_match_all('/\d{4}/', (string)$active_year, $matches)) {
    $active_year_display = (string)max(array_map('intval', $matches[0]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
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
            @page {
                size: legal landscape; /* Long Bondpaper (8.5in x 14in) */
                margin: 15mm 15mm 15mm 15mm;
            }
            body { background: white !important; color: black !important; font-size: 11px; }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            main { padding: 0 !important; margin: 0 !important; overflow: visible !important; width: 100% !important; max-width: 100% !important; }
            .flex-1 { overflow: visible !important; }
            .overflow-auto { overflow: visible !important; }
            div.overflow-x-auto { overflow: visible !important; }
            
            /* Remove shadows, borders, and rounded corners on container */
            .bg-white, .rounded-xl, .shadow-sm, .border, .overflow-hidden {
                border-radius: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }
            
            /* Plain and formal table styles */
            table { width: 100% !important; border-collapse: collapse !important; margin-top: 10px !important; border-radius: 0 !important; box-shadow: none !important; }
            th, td { border: 1px solid #64748b !important; padding: 8px !important; text-align: left !important; color: black !important; border-radius: 0 !important; }
            th { font-weight: bold !important; text-transform: uppercase !important; font-size: 10px !important; background-color: #f1f5f9 !important; border-bottom: 2px solid black !important; }
            td { font-size: 10px !important; }
            span { background: transparent !important; color: black !important; padding: 0 !important; font-weight: normal !important; }
            td.font-bold, td span.font-bold, td.text-right, td.text-right span { font-weight: bold !important; }
        }
        .print-only { display: none; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php require_once __DIR__ . "/../../includes/sidebar_qmo.php"; ?>

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
                            <button id="profile-btn" class="w-10 h-10 rounded-full bg-[#800000] flex items-center justify-center text-white font-bold cursor-pointer hover:opacity-80 transition overflow-hidden">
                            <?php if (!empty($_SESSION['profile_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($_SESSION['profile_photo']); ?>" class="w-full h-full object-cover rounded-full" alt="Profile" />
                            <?php else: ?>
                                <?php echo h(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
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
            <div class="flex-1 overflow-auto p-8">
                <div class="max-w-7xl mx-auto">

                    <!-- Print Only Header -->
                    <div hidden class="print-only mb-6 text-center">
                        <div class="flex flex-col items-center justify-center mb-4">
                            <img src="<?php echo $_SESSION['logo_base64'] ?? ''; ?>" alt="UA Logo" class="w-20 h-20 rounded-full mb-3 border border-gray-300">
                            <h2 class="text-xl font-bold text-gray-900 uppercase tracking-wide">UNIVERSITY OF ANTIQUE</h2>
                            <p class="text-sm font-bold text-gray-700">Tario-Lim Memorial Campus</p>
                            <p class="text-sm text-gray-600">Tibiao, Antique</p>
                            <p class="text-sm font-bold text-gray-700">Quality Management Office</p>
                            
                            <h3 class="text-lg font-bold text-gray-900 mt-6 tracking-wide uppercase">QMS Document Repository Report</h3>
                            <p class="text-xs text-gray-500 mt-2" id="print-metadata">
                                <!-- Will be populated dynamically by JS before print -->
                            </p>
                        </div>
                        <hr class="border-t border-black my-4">
                    </div>


                     <!-- Statistics Cards -->
                    <div class="grid grid-cols-2 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
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

                            <!-- Academic Year Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Academic Year</label>
                                <select id="ay-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Years</option>
                                </select>
                            </div>

                            <!-- Period Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Period</label>
                                <select id="semester-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Periods</option>
                                </select>
                            </div>

                            <!-- Office / Unit Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Office / Unit</label>
                                <select id="office-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Offices</option>
                                </select>
                            </div>

                            <!-- Document Type Filter -->
                            <div class="w-full xl:flex-1 min-w-[100px]">
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Document Type</label>
                                <select id="doc-type-filter" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none text-xs">
                                    <option value="">All Types</option>
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
                                        <th class="px-6 py-4 text-right no-print">Actions</th>
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

            <!-- Delete Confirmation Modal -->
            <div id="delete-confirm-modal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden opacity-0 transition-opacity duration-300">
                <div class="bg-white rounded-xl max-w-md w-full mx-4 overflow-hidden shadow-xl transform scale-95 transition-transform duration-300">
                    <div class="px-6 py-4 bg-[#800000] text-white flex items-center justify-between">
                        <h3 class="font-bold flex items-center gap-2"><i class="fas fa-exclamation-triangle"></i> Delete Document</h3>
                        <button onclick="closeDeleteModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="p-6">
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
        </main>
    </div>

    <script>
    {
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
        async function loadDocuments(page = 1, isSilent = false) {
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

            if (!isSilent) {
                document.getElementById('loading-state').classList.remove('hidden');
                document.getElementById('documents-container').innerHTML = '';
                document.getElementById('empty-state').classList.add('hidden');
                document.getElementById('pagination-container').classList.add('hidden');
            }

            try {
                const response = await fetch(`../../api/repository_api.php?${params}`);
                const data = await response.json();

                if (!isSilent) {
                    document.getElementById('loading-state').classList.add('hidden');
                }

                if (data.success && data.documents.length > 0) {
                    renderDocuments(data.documents);
                    renderPagination(data.pagination);
                } else {
                    if (!isSilent) {
                        document.getElementById('empty-state').classList.remove('hidden');
                    } else {
                        document.getElementById('documents-container').innerHTML = '';
                        document.getElementById('empty-state').classList.remove('hidden');
                        document.getElementById('pagination-container').classList.add('hidden');
                    }
                }
            } catch (error) {
                console.error('Error loading documents:', error);
                if (!isSilent) {
                    document.getElementById('loading-state').classList.add('hidden');
                    document.getElementById('empty-state').classList.remove('hidden');
                }
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
                    <td class="px-6 py-4 text-right no-print">
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

        function applyFilters() {
            currentFilters.search = document.getElementById('search-input').value;
            currentFilters.doc_type = document.getElementById('doc-type-filter').value;
            currentFilters.office = document.getElementById('office-filter').value;
            currentFilters.academic_year = document.getElementById('ay-filter').value;
            currentFilters.semester = document.getElementById('semester-filter').value;
            loadDocuments(1);
        }

        // Event listeners
        document.getElementById('apply-filters-btn').addEventListener('click', applyFilters);

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
                applyFilters();
            }
        });

        // Auto-apply filters on dropdown change
        document.getElementById('doc-type-filter').addEventListener('change', applyFilters);
        document.getElementById('office-filter').addEventListener('change', applyFilters);
        document.getElementById('ay-filter').addEventListener('change', applyFilters);
        document.getElementById('semester-filter').addEventListener('change', applyFilters);

        // Auto-apply filters on search input with debounce
        let searchTimeout;
        document.getElementById('search-input').addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 300);
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
                loadDocuments(currentPage, true);
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

        // Expose functions called by inline event handlers
        window.confirmDeleteDocument = confirmDeleteDocument;
        window.closeDeleteModal = closeDeleteModal;

        // Populate print metadata before print
        window.addEventListener('beforeprint', () => {
            const ay = document.getElementById('ay-filter').value || 'All Years';
            const sem = document.getElementById('semester-filter').value || 'All Periods';
            const officeSelect = document.getElementById('office-filter');
            const officeText = officeSelect.options[officeSelect.selectedIndex]?.text || 'All Offices';
            const docTypeSelect = document.getElementById('doc-type-filter');
            const docTypeText = docTypeSelect.options[docTypeSelect.selectedIndex]?.text || 'All Types';
            
            const printMetadata = document.getElementById('print-metadata');
            if (printMetadata) {
                printMetadata.innerHTML = `
                    <strong>Academic Year:</strong> ${escapeHtml(ay)} 
                    &middot; 
                    <strong>Period:</strong> ${escapeHtml(sem)} 
                    &middot; 
                    <strong>Unit/Office:</strong> ${escapeHtml(officeText)} 
                    &middot; 
                    <strong>Document Type:</strong> ${escapeHtml(docTypeText)}
                    <br>
                    <strong>Generated:</strong> ${new Date().toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' })}
                `;
            }
        });
    }
    </script>
</body>
</html>

