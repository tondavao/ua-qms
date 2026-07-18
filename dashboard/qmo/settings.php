<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin'){ 
    header("Location: ../../index.php"); 
    exit();
}

function h($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/unit-seed.php";
ua_qms_seed_default_units($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/jpeg" href="../../assets/img/ua-qms-logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Dynamic Academic & Office Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Global error logging for diagnostics
        window.addEventListener('error', function(event) {
            const errorDetails = {
                message: event.message,
                source: event.filename,
                lineno: event.lineno,
                colno: event.colno,
                stack: event.error ? event.error.stack : ''
            };
            fetch('../../api/log_js_error.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(errorDetails)
            }).catch(e => {});
        });
        window.addEventListener('unhandledrejection', function(event) {
            const errorDetails = {
                message: 'Unhandled Rejection: ' + (event.reason ? event.reason.message || event.reason : ''),
                source: '',
                lineno: 0,
                colno: 0,
                stack: event.reason && event.reason.stack ? event.reason.stack : ''
            };
            fetch('../../api/log_js_error.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(errorDetails)
            }).catch(e => {});
        });

        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#8b0000',
                        'primary-light': '#a52a2a',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #F9FAFB; }
        .bg-ua-maroon { background-color: #800000; }
        .text-ua-maroon { color: #800000; }
        .glass-card { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border: 1px solid rgba(229, 231, 235, 0.5); }
        .tab-btn.active { border-bottom: 2px solid #8b0000; color: #8b0000; font-weight: 700; }

        /* Custom Primary Color Utilities (Fallback for PJAX transitions) */
        .bg-primary { background-color: #8b0000 !important; }
        .hover\:bg-primary-light:hover { background-color: #a52a2a !important; }
        .text-primary { color: #8b0000 !important; }
        .focus\:ring-primary:focus { --tw-ring-color: #8b0000 !important; }
        .focus\:border-primary:focus { border-color: #8b0000 !important; }

        @keyframes tabFadeIn {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .tab-content:not(.hidden) {
            animation: tabFadeIn 0.25s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

    /* PROFILE DROPDOWN */
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
    .profile-dropdown-item i {
        margin-right: 12px;
        width: 16px;
        text-align: center;
        color: #6b7280;
    }
    .profile-dropdown-item.logout {
        color: #dc2626;
        border-top: 1px solid #e5e7eb;
    }
    .profile-dropdown-item.logout i {
        color: #dc2626;
    }

    /* Mobile Responsive Tab Navigation */
    @media (max-width: 767px) {
        .tabs-nav {
            flex-wrap: wrap !important;
            gap: 8px !important;
            padding: 12px !important;
            border-radius: 12px !important;
            border-bottom: none !important;
        }
        .tab-btn {
            flex: 1 1 calc(50% - 8px) !important;
            justify-content: center !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 8px !important;
            padding: 10px 8px !important;
            text-align: center !important;
        }
        .tab-btn.active {
            background-color: #8b0000 !important;
            color: white !important;
            border-color: #8b0000 !important;
            border-bottom: 1px solid #8b0000 !important;
        }
    }
    </style>
    <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
</head>
<body class="bg-gray-50 font-sans text-gray-800 flex h-screen overflow-hidden">

    <?php require_once __DIR__ . "/../../includes/sidebar_qmo.php"; ?>

    <main class="flex-1 flex flex-col overflow-hidden">
        <!-- Topbar -->
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0">
            <div class="flex items-center gap-4">
                <div>
                    <h1 class="text-lg font-bold text-gray-900 leading-tight">System Configuration</h1>
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
        <div class="flex-1 overflow-auto p-4 md:p-8">
            <div class="max-w-6xl mx-auto space-y-6">
                


                <!-- Tabs Navigation -->
                <div class="tabs-nav flex flex-nowrap overflow-x-auto border-b border-gray-200 mb-6 bg-white rounded-t-xl px-4" style="scrollbar-width: none;">
                    <button onclick="switchTab('system')" class="tab-btn active px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-system"><i class="fas fa-clock mr-2"></i> Deadline</button>
                    <button onclick="switchTab('announcements')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-announcements"><i class="fas fa-bullhorn mr-2"></i> Announcements</button>
                    <button onclick="switchTab('academic')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-academic"><i class="fas fa-calendar-alt mr-2"></i> Academic Year</button>
                    <button onclick="switchTab('documents')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-documents"><i class="fas fa-file-alt mr-2"></i> Document Types</button>
                    <button onclick="switchTab('offices')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-offices"><i class="fas fa-building mr-2"></i> Office/Department</button>
                </div>

                <!-- Tab Contents -->
                <div id="content-academic" class="tab-content hidden space-y-6">
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Academic Year Management -->
                        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 pb-4 border-b border-gray-150">
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-calendar-alt text-primary"></i> Academic Year Management</h2>
                                <button onclick="showAddAYModal()" class="bg-[#800000] hover:bg-red-950 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all duration-200 transform hover:scale-105 active:scale-95 shadow flex items-center gap-2">
                                    <i class="fas fa-plus"></i> Add Academic Year
                                </button>
                            </div>
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                                <div class="relative w-full sm:w-72">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-search text-sm"></i>
                                    </span>
                                    <input type="text" id="search-ay" oninput="renderAYs()" placeholder="Search academic years..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                                </div>
                            </div>
                            <div class="overflow-hidden border rounded-lg">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-gray-50 border-b">
                                        <tr>
                                            <th class="p-3">Year</th>
                                            <th class="p-3">Status</th>
                                            <th class="p-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ay-list"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="content-documents" class="tab-content hidden space-y-6">
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 pb-4 border-b border-gray-150">
                            <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-file-alt text-primary"></i> Document Type Management</h2>
                            <button onclick="showAddDocModal()" class="bg-[#800000] hover:bg-red-950 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all duration-200 transform hover:scale-105 active:scale-95 shadow flex items-center gap-2">
                                <i class="fas fa-plus"></i> Add Document Type
                            </button>
                        </div>
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div class="relative w-full sm:w-72">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-search text-sm"></i>
                                </span>
                                <input type="text" id="search-doc" oninput="renderDocTypes()" placeholder="Search document types..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="p-3">Code</th>
                                        <th class="p-3">Description</th>
                                        <th class="p-3">Required</th>
                                        <th class="p-3">Frequency</th>
                                        <th class="p-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="doc-type-list"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="content-offices" class="tab-content hidden space-y-6">
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 pb-4 border-b border-gray-150">
                            <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-building text-primary"></i> Office/Department Management</h2>
                            <button onclick="showAddOfficeModal()" class="bg-[#800000] hover:bg-red-950 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all duration-200 transform hover:scale-105 active:scale-95 shadow flex items-center gap-2">
                                <i class="fas fa-plus"></i> Add Office/Department
                            </button>
                        </div>
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div class="flex items-center gap-2 w-full sm:w-auto">
                                <div class="relative w-full sm:w-72">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-search text-sm"></i>
                                    </span>
                                    <input type="text" id="search-office" oninput="renderOffices()" placeholder="Search offices..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                                </div>
                                <select id="filter-office-cat" onchange="renderOffices()" class="p-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                                    <option value="">All Categories</option>
                                </select>
                            </div>
                        </div>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="p-3">Office Name</th>
                                        <th class="p-3">Code</th>
                                        <th class="p-3">Category</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="office-list"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="content-system" class="tab-content space-y-6">
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-5 pb-4 border-b border-gray-150">
                            <div>
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-calendar-check text-primary text-sm"></i> Submission Deadline Management</h2>
                                <p class="text-xs text-gray-500 mt-1">Set quarterly submission deadlines by academic year and document type.</p>
                            </div>
                            <button onclick="showDeadlineModal()" class="bg-[#800000] hover:bg-red-950 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all duration-200 transform hover:scale-105 active:scale-95 shadow flex items-center gap-2">
                                <i class="fas fa-plus"></i> Add Deadline
                            </button>
                        </div>
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div class="flex items-center gap-2 w-full sm:w-auto">
                                <input type="text" id="deadline-year-filter" oninput="loadSubmissionDeadlines()" placeholder="Year" class="w-full sm:w-32 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="p-3">Year</th>
                                        <th class="p-3">Period</th>
                                        <th class="p-3">Document Type</th>
                                        <th class="p-3">Deadline</th>
                                        <th class="p-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="deadline-list"></tbody>
                            </table>
                        </div>
                    </div>
                </div> <!-- End of content-system -->

                <!-- Announcements Tab Content -->
                <div id="content-announcements" class="tab-content hidden space-y-6">
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6 pb-4 border-b border-gray-150">
                            <div>
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-bullhorn text-primary text-sm"></i> Global Announcements</h2>
                                <p class="text-xs text-gray-500 mt-1">Post announcements that will appear in all unit dashboards.</p>
                            </div>
                            <button onclick="openAnnouncementModal()" class="bg-[#800000] hover:bg-red-950 text-white px-4 py-2 rounded-lg text-xs font-bold transition-all duration-200 transform hover:scale-105 active:scale-95 shadow flex items-center gap-2">
                                <i class="fas fa-plus"></i> Post Announcement
                            </button>
                        </div>
                        
                        <!-- List of announcements -->
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="p-3">Title</th>
                                        <th class="p-3">Content</th>
                                        <th class="p-3">Image</th>
                                        <th class="p-3">Posted By</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="announcements-list-tbody">
                                    <tr>
                                        <td colspan="6" class="p-3 text-center text-gray-500">Loading announcements...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>

</main>

    <!-- Modals -->
    <!-- Announcement Modal -->
    <div id="announcementModal" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-[9999] p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center">
                <h3 id="announcement-modal-title" class="font-bold flex items-center gap-2"><i class="fas fa-bullhorn"></i> Post Announcement</h3>
                <button onclick="document.getElementById('announcementModal').classList.add('hidden')" class="text-white hover:text-gray-200 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="announcementForm" class="p-6 space-y-4" enctype="multipart/form-data">
                <input type="hidden" name="announcement_id" id="announcement-id">
                <input type="hidden" name="remove_image" id="remove-image-flag" value="0">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Title</label>
                    <input type="text" name="title" id="announcement-title" required class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Message</label>
                    <textarea name="content" id="announcement-content" rows="4" required class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#800000] outline-none resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Optional Image</label>
                    <input type="file" name="announcement_image" id="announcement-image-input" accept="image/*" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                    <div id="announcement-image-preview-container" class="mt-2 hidden">
                        <p class="text-xs text-gray-500 mb-1">Current Image:</p>
                        <div class="relative inline-block">
                            <img id="announcement-image-preview" src="" alt="Preview" class="max-h-24 rounded border">
                            <button type="button" onclick="removeAnnouncementImage()" class="absolute -top-1.5 -right-1.5 bg-red-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs hover:bg-red-800"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="document.getElementById('announcementModal').classList.add('hidden')" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" id="announcement-submit-btn" class="px-5 py-2 bg-[#800000] text-white rounded-lg text-sm font-bold shadow hover:bg-red-900 transition flex items-center gap-2">
                        <i class="fas fa-paper-plane"></i> Post Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modals -->
    <div id="modal-container" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[9999] p-4">
        <!-- Add AY Modal -->
        <div id="modal-add-ay" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-calendar-alt"></i> Add Academic Year</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Academic Year</label>
                    <input type="text" id="new-ay-range" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. 2026-2027">
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveAddAY()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save</button>
                </div>
            </div>
        </div>

        <!-- Add Doc Type Modal -->
        <div id="modal-doc" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-file-alt"></i> Add Document Type</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Code</label>
                    <input type="text" id="doc-code" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. QOP">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Description</label>
                    <input type="text" id="doc-desc" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-1 text-gray-700">Required</label>
                        <select id="doc-req" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1 text-gray-700">Frequency</label>
                        <select id="doc-freq" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                            <option value="Quarterly">Quarterly</option>
                            <option value="Optional">Optional</option>
                            <option value="Once">Once</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveDocType()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save</button>
                </div>
            </div>
        </div>

        <!-- Edit Doc Type Modal -->
        <div id="modal-edit-doc" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-file-alt"></i> Edit Document Type</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <input type="hidden" id="edit-doc-id">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Code</label>
                    <input type="text" id="edit-doc-code" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. QOP">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Description</label>
                    <input type="text" id="edit-doc-desc" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-1 text-gray-700">Required</label>
                        <select id="edit-doc-req" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1 text-gray-700">Frequency</label>
                        <select id="edit-doc-freq" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                            <option value="Quarterly">Quarterly</option>
                            <option value="Optional">Optional</option>
                            <option value="Once">Once</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveEditDocType()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Add Office Modal -->
        <div id="modal-office" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-building"></i> Add Office/Department</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Office Name</label>
                    <input type="text" id="off-name" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. College of Engineering">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Code</label>
                    <input type="text" id="off-code" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. CE">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Category</label>
                    <select id="off-cat" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon"></select>
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveOffice()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save</button>
                </div>
            </div>
        </div>

        <!-- Edit AY Modal -->
        <div id="modal-edit-ay" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-calendar-alt"></i> Edit Academic Year</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <input type="hidden" id="edit-ay-id">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Academic Year</label>
                    <input type="text" id="edit-ay-range" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. 2026-2027">
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveEditAY()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Edit Office Modal -->
        <div id="modal-edit-office" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-building"></i> Edit Office/Department</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <input type="hidden" id="edit-off-id">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Office Name</label>
                    <input type="text" id="edit-off-name" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. College of Engineering">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Code</label>
                    <input type="text" id="edit-off-code" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon" placeholder="e.g. CE">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Category</label>
                    <select id="edit-off-cat" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon"></select>
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveEditOffice()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Submission Deadline Modal -->
        <div id="modal-deadline" class="bg-white rounded-xl p-6 w-full max-w-md hidden overflow-visible shadow-2xl">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center -mx-6 -mt-6 mb-5 rounded-t-xl">
                <h3 id="deadline-modal-title" class="font-bold flex items-center gap-2"><i class="fas fa-clock"></i> Add Submission Deadline</h3>
                <button onclick="closeModal()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
            </div>
            <div class="space-y-4">
                <input type="hidden" id="deadline-id">
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Academic Year</label>
                    <input type="text" id="deadline-year" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none bg-gray-50 text-gray-500 cursor-not-allowed" readonly placeholder="Academic Year">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Period</label>
                    <select id="deadline-quarter" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                        <option value="1st Quarter">1st Quarter</option>
                        <option value="2nd Quarter">2nd Quarter</option>
                        <option value="3rd Quarter">3rd Quarter</option>
                        <option value="4th Quarter">4th Quarter</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Document Type</label>
                    <select id="deadline-doc-type" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon"></select>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1 text-gray-700">Deadline</label>
                    <input type="date" id="deadline-date" class="w-full p-2.5 border border-gray-300 rounded-lg outline-none focus:ring-2 focus:ring-ua-maroon">
                </div>
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2.5 border border-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button onclick="saveDeadline()" class="flex-1 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition">Save</button>
                </div>
            </div>
        </div>
    </div>

    <script>


        // Tab Management
        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
            document.getElementById('tab-' + tab).classList.add('active');
            document.getElementById('content-' + tab).classList.remove('hidden');
            loadTabData(tab);
        }

        function loadTabData(tab) {
            if (tab === 'academic') { loadAYs(); }
            if (tab === 'documents') loadDocTypes();
            if (tab === 'offices') loadOffices();
            if (tab === 'system') loadSystemConfig();
            if (tab === 'announcements') loadAnnouncements();
        }

        // Academic Years
        var allAYs = allAYs || [];
        async function loadAYs() {
            const res = await fetch('../../api/settings_api.php?action=get_academic_years');
            const data = await res.json();
            allAYs = data.data || [];
            renderAYs();
        }

        function renderAYs() {
            const query = document.getElementById('search-ay')?.value.toLowerCase() || '';
            const filtered = allAYs.filter(ay => ay.year_range.toLowerCase().includes(query));
            const list = document.getElementById('ay-list');
            if (filtered.length === 0) {
                list.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-gray-400">No academic years found.</td></tr>`;
                return;
            }
            list.innerHTML = filtered.map(ay => `
                <tr class="border-b hover:bg-gray-50/50 transition-colors">
                    <td class="p-3 font-semibold text-gray-700">${ay.year_range}</td>
                    <td class="p-3">
                        ${Number(ay.is_active) === 1 ? '<span class="bg-emerald-100 text-emerald-800 px-2.5 py-0.5 rounded-full text-xs font-bold shadow-sm">Active</span>' : '<span class="bg-gray-100 text-gray-600 px-2.5 py-0.5 rounded-full text-xs font-bold">Inactive</span>'}
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            ${Number(ay.is_active) !== 1 ? `
                            <button onclick="setActiveAY(${ay.ay_id})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-check text-[10px]"></i> Set Active
                            </button>` : ''}
                            <button onclick="editAY(${ay.ay_id}, '${ay.year_range}')" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-edit text-[10px]"></i> Edit
                            </button>
                            <button onclick="deleteAY(${ay.ay_id})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-trash text-[10px]"></i> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function showAddAYModal() {
            document.getElementById('new-ay-range').value = '';
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-add-ay').classList.remove('hidden');
        }

        async function saveAddAY() {
            const range = document.getElementById('new-ay-range').value;
            if (!/^\d{4}-\d{4}$/.test(range)) {
                alert('Academic year must be a valid range, e.g. 2026-2027.');
                return;
            }
            const res = await fetch('../../api/settings_api.php?action=add_academic_year', {
                method: 'POST',
                body: JSON.stringify({ year_range: range })
            });
            const data = await res.json();
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }
            closeModal();
            loadAYs();
        }

        async function setActiveAY(id) {
            try {
                const res = await fetch('../../api/settings_api.php?action=set_active_year', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ay_id: id })
                });
                const data = await res.json();
                if (data.success) {
                    loadAYs();
                    loadSystemConfig();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('An error occurred while setting active year.');
            }
        }

        function editAY(id, range) {
            document.getElementById('edit-ay-id').value = id;
            document.getElementById('edit-ay-range').value = range;
            
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-edit-ay').classList.remove('hidden');
        }

        async function saveEditAY() {
            const id = document.getElementById('edit-ay-id').value;
            const range = document.getElementById('edit-ay-range').value;
            if (!/^\d{4}-\d{4}$/.test(range)) {
                alert('Academic year must be a valid range, e.g. 2026-2027.');
                return;
            }
            try {
                const res = await fetch('../../api/settings_api.php?action=edit_academic_year', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ay_id: id, year_range: range })
                });
                const data = await res.json();
                if (data.success) {
                    closeModal();
                    loadAYs();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('An error occurred while saving.');
            }
        }

        async function deleteAY(id) {
            showThemeConfirm('Are you sure you want to delete this Academic Year?', async () => {
                try {
                    const res = await fetch('../../api/settings_api.php?action=delete_academic_year', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ay_id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        loadAYs();
                    } else {
                        alert('Error: ' + data.error);
                    }
                } catch (err) {
                    alert('An error occurred while deleting.');
                }
            });
        }

        // Document Types
        var allDocTypes = allDocTypes || [];
        async function loadDocTypes() {
            const res = await fetch('../../api/settings_api.php?action=get_document_types');
            const data = await res.json();
            allDocTypes = data.data || [];
            renderDocTypes();
        }

        function renderDocTypes() {
            const query = document.getElementById('search-doc')?.value.toLowerCase() || '';
            const filtered = allDocTypes.filter(t => 
                t.doc_code.toLowerCase().includes(query) || 
                t.description.toLowerCase().includes(query)
            );
            const list = document.getElementById('doc-type-list');
            if (filtered.length === 0) {
                list.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-400">No document types found.</td></tr>`;
                return;
            }
            list.innerHTML = filtered.map(t => `
                <tr class="border-b hover:bg-gray-50/50 transition-colors">
                    <td class="p-3 font-bold text-ua-maroon">${escapeHtml(t.doc_code)}</td>
                    <td class="p-3 font-medium text-gray-700">${escapeHtml(t.description)}</td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-xs font-bold ${t.required === 'Yes' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600'}">${t.required}</span>
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-blue-800">${t.frequency}</span>
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <button 
                                data-id="${t.doc_type_id}" 
                                data-code="${escapeHtml(t.doc_code)}" 
                                data-desc="${escapeHtml(t.description)}" 
                                data-req="${escapeHtml(t.required)}" 
                                data-freq="${escapeHtml(t.frequency)}" 
                                onclick="editDocTypeBtn(this)" 
                                class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-edit text-[10px]"></i> Edit
                            </button>
                            <button onclick="deleteDocType(${t.doc_type_id})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-trash text-[10px]"></i> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function editDocTypeBtn(btn) {
            const id = btn.dataset.id;
            const code = btn.dataset.code;
            const desc = btn.dataset.desc;
            const req = btn.dataset.req;
            const freq = btn.dataset.freq;
            editDocType(id, code, desc, req, freq);
        }

        async function saveDocType() {
            const payload = {
                doc_code: document.getElementById('doc-code').value,
                description: document.getElementById('doc-desc').value,
                required: document.getElementById('doc-req').value,
                frequency: document.getElementById('doc-freq').value
            };
            await fetch('../../api/settings_api.php?action=add_document_type', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            closeModal();
            loadDocTypes();
        }

        function editDocType(id, code, desc, req, freq) {
            document.getElementById('edit-doc-id').value = id;
            document.getElementById('edit-doc-code').value = code;
            document.getElementById('edit-doc-desc').value = desc;
            document.getElementById('edit-doc-req').value = req;
            document.getElementById('edit-doc-freq').value = freq;
            
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-edit-doc').classList.remove('hidden');
        }

        async function saveEditDocType() {
            const id = document.getElementById('edit-doc-id').value;
            const code = document.getElementById('edit-doc-code').value;
            const desc = document.getElementById('edit-doc-desc').value;
            const req = document.getElementById('edit-doc-req').value;
            const freq = document.getElementById('edit-doc-freq').value;
            if (!code) return;
            const res = await fetch('../../api/settings_api.php?action=edit_document_type', {
                method: 'POST',
                body: JSON.stringify({ doc_type_id: id, doc_code: code, description: desc, required: req, frequency: freq })
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                loadDocTypes();
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function deleteDocType(id) {
            showThemeConfirm('Are you sure? This may affect existing submissions.', async () => {
                try {
                    const res = await fetch('../../api/settings_api.php?action=delete_document_type', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ doc_type_id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        loadDocTypes();
                    } else {
                        alert('Error: ' + data.error);
                    }
                } catch (err) {
                    alert('An error occurred while deleting.');
                }
            });
        }

        // Offices
        var allOffices = allOffices || [];
        async function loadOffices() {
            const catSelect = document.getElementById('filter-office-cat');
            if (catSelect && catSelect.children.length === 1) {
                const catRes = await fetch('../../api/settings_api.php?action=get_categories');
                const catData = await catRes.json();
                catSelect.innerHTML += catData.data.map(c => `<option value="${c.category_name}">${c.category_name}</option>`).join('');
            }

            const res = await fetch('../../api/settings_api.php?action=get_offices');
            const data = await res.json();
            allOffices = data.data || [];
            renderOffices();
        }

        function renderOffices() {
            const query = document.getElementById('search-office')?.value.toLowerCase() || '';
            const catFilter = document.getElementById('filter-office-cat')?.value || '';
            const filtered = allOffices.filter(o => {
                const matchQuery = o.office_name.toLowerCase().includes(query) || (o.office_code || '').toLowerCase().includes(query);
                const matchCat = !catFilter || o.category_name === catFilter;
                return matchQuery && matchCat;
            });
            const list = document.getElementById('office-list');
            if (filtered.length === 0) {
                list.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-400">No offices or departments found.</td></tr>`;
                return;
            }
            list.innerHTML = filtered.map(o => `
                <tr class="border-b hover:bg-gray-50/50 transition-colors">
                    <td class="p-3 font-semibold text-gray-700">${escapeHtml(o.office_name)}</td>
                    <td class="p-3 font-bold text-gray-600">${escapeHtml(o.office_code || '-')}</td>
                    <td class="p-3 text-gray-600">${escapeHtml(o.category_name || '-')}</td>
                    <td class="p-3">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold ${o.status === 'Active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'}">${o.status}</span>
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <button onclick="toggleOffice(${o.office_id}, '${o.status === 'Active' ? 'Inactive' : 'Active'}')" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white border border-red-200 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm">
                                ${o.status === 'Active' ? '<i class="fas fa-ban text-[10px]"></i> Deactivate' : '<i class="fas fa-check-circle text-[10px]"></i> Activate'}
                            </button>
                            <button 
                                data-id="${o.office_id}" 
                                data-name="${escapeHtml(o.office_name)}" 
                                data-code="${escapeHtml(o.office_code || '')}" 
                                data-cat-id="${o.category_id || ''}" 
                                onclick="editOfficeBtn(this)" 
                                class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-edit text-[10px]"></i> Edit
                            </button>
                            <button onclick="deleteOffice(${o.office_id})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-trash text-[10px]"></i> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function editOfficeBtn(btn) {
            const id = btn.dataset.id;
            const name = btn.dataset.name;
            const code = btn.dataset.code;
            const catId = btn.dataset.catId ? Number(btn.dataset.catId) : null;
            editOffice(id, name, code, catId);
        }

        async function saveOffice() {
            const payload = {
                office_name: document.getElementById('off-name').value,
                office_code: document.getElementById('off-code').value,
                category_id: document.getElementById('off-cat').value
            };
            await fetch('../../api/settings_api.php?action=add_office', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            closeModal();
            loadOffices();
        }

        async function toggleOffice(id, status) {
            await fetch('../../api/settings_api.php?action=toggle_office_status', {
                method: 'POST',
                body: JSON.stringify({ office_id: id, status: status })
            });
            loadOffices();
        }

        async function editOffice(id, name, code, catId) {
            document.getElementById('edit-off-id').value = id;
            document.getElementById('edit-off-name').value = name;
            document.getElementById('edit-off-code').value = code;
            
            const res = await fetch('../../api/settings_api.php?action=get_categories');
            const data = await res.json();
            const selectEl = document.getElementById('edit-off-cat');
            selectEl.innerHTML = data.data.map(c => `<option value="${c.category_id}" ${c.category_id == catId ? 'selected' : ''}>${c.category_name}</option>`).join('');
            
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-edit-office').classList.remove('hidden');
        }

        async function saveEditOffice() {
            const id = document.getElementById('edit-off-id').value;
            const name = document.getElementById('edit-off-name').value;
            const code = document.getElementById('edit-off-code').value;
            const catId = document.getElementById('edit-off-cat').value;
            if (!name) return;
            
            const res = await fetch('../../api/settings_api.php?action=edit_office', {
                method: 'POST',
                body: JSON.stringify({ office_id: id, office_name: name, office_code: code, category_id: catId })
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                loadOffices();
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function deleteOffice(id) {
            showThemeConfirm('Are you sure you want to delete this office? This will delete all documents and user links associated with it.', async () => {
                try {
                    const res = await fetch('../../api/settings_api.php?action=delete_office', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ office_id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        loadOffices();
                    } else {
                        alert('Error: ' + data.error);
                    }
                } catch (err) {
                    alert('An error occurred while deleting.');
                }
            });
        }

        // Submission Deadline
        var allDeadlines = allDeadlines || [];
        var quarters = quarters || ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function(char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            });
        }

        async function ensureDocTypesLoaded() {
            if (allDocTypes.length > 0) return;
            const res = await fetch('../../api/settings_api.php?action=get_document_types');
            const data = await res.json();
            allDocTypes = data.data || [];
        }

        async function loadSystemConfig() {
            try {
                console.log("loadSystemConfig invoked");
                // 1. Fetch available AYs for active AY dropdown
                const ayRes = await fetch('../../api/settings_api.php?action=get_academic_years');
                console.log("get_academic_years HTTP status:", ayRes.status);
                const ayData = await ayRes.json();
                console.log("get_academic_years JSON success:", ayData.success, "Data:", ayData.data);
                
                const aySelect = document.getElementById('active-system-ay');
                if (aySelect && ayData.success) {
                    aySelect.innerHTML = (ayData.data || []).map(ay => `<option value="${ay.year_range}">${ay.year_range}</option>`).join('');
                    console.log("aySelect innerHTML updated, options count:", aySelect.options.length);
                    if (typeof aySelect.rebuildCustomOptions === 'function') {
                        console.log("Calling aySelect.rebuildCustomOptions()");
                        aySelect.rebuildCustomOptions();
                    } else {
                        console.log("Warning: aySelect.rebuildCustomOptions is not a function!");
                    }
                }

                // 2. Fetch current settings from DB
                const settingsRes = await fetch('../../api/settings_api.php?action=get_system_settings');
                console.log("get_system_settings HTTP status:", settingsRes.status);
                const settingsData = await settingsRes.json();
                console.log("get_system_settings JSON success:", settingsData.success, "Data:", settingsData.data);
                
                if (settingsData.success) {
                    window.systemSettings = settingsData.data;
                    const s = settingsData.data;
                    const activeAySelect = document.getElementById('active-system-ay');
                    if (activeAySelect) {
                        activeAySelect.value = s.active_academic_year || '';
                        console.log("Setting activeAySelect.value to:", s.active_academic_year);
                        if (typeof activeAySelect.rebuildCustomOptions === 'function') {
                            activeAySelect.rebuildCustomOptions();
                        }
                    }
                    const activeQuarterSelect = document.getElementById('active-system-quarter');
                    if (activeQuarterSelect) {
                        activeQuarterSelect.value = s.active_semester || '1st Quarter';
                        console.log("Setting activeQuarterSelect.value to:", s.active_semester);
                        if (typeof activeQuarterSelect.rebuildCustomOptions === 'function') {
                            activeQuarterSelect.rebuildCustomOptions();
                        }
                    }
                }

                // 3. Load submission deadlines
                loadSubmissionDeadlines(true);
            } catch (error) {
                console.error("loadSystemConfig error caught:", error);
            }
        }

        async function saveActivePeriod() {
            const ay = document.getElementById('active-system-ay').value;
            const quarter = document.getElementById('active-system-quarter').value;
            
            const res = await fetch('../../api/settings_api.php?action=update_active_period', {
                method: 'POST',
                body: JSON.stringify({ active_academic_year: ay, active_quarter: quarter })
            });
            const data = await res.json();
            if (data.success) {
                alert('Active period updated successfully! Page will refresh to apply settings.');
                window.location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function loadSubmissionDeadlines(forceActiveYear = false) {
            const yearInput = document.getElementById('deadline-year-filter');
            if (yearInput && (forceActiveYear || !yearInput.value)) {
                // Default to active year setting
                const activeAy = window.systemSettings?.active_academic_year || new Date().getFullYear();
                yearInput.value = activeAy;
            }
            const year = yearInput?.value || '';
            const res = await fetch('../../api/settings_api.php?action=get_submission_deadlines&year=' + encodeURIComponent(year));
            const data = await res.json();
            allDeadlines = data.data || [];
            renderSubmissionDeadlines();
        }

        function renderSubmissionDeadlines() {
            const list = document.getElementById('deadline-list');
            if (!list) return;

            if (allDeadlines.length === 0) {
                list.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-gray-400">No submission deadlines found for this year.</td></tr>`;
                return;
            }

            list.innerHTML = allDeadlines.map(item => `
                <tr class="border-b hover:bg-gray-50/50 transition-colors">
                    <td class="p-3 font-semibold text-gray-700">${escapeHtml(item.year)}</td>
                    <td class="p-3"><span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-blue-800">${escapeHtml(item.quarter)}</span></td>
                    <td class="p-3">
                        <div class="font-bold text-ua-maroon">${escapeHtml(item.doc_code)}</div>
                        <div class="text-xs text-gray-500">${escapeHtml(item.description)}</div>
                    </td>
                    <td class="p-3 font-semibold text-gray-700">${escapeHtml(item.deadline)}</td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <button onclick="showDeadlineModal(${Number(item.target_id)})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-edit text-[10px]"></i> Edit
                            </button>
                            <button onclick="deleteDeadline(${Number(item.target_id)})" class="bg-red-50 text-[#800000] hover:bg-[#800000] hover:text-white px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 transition shadow-sm border border-red-200">
                                <i class="fas fa-trash text-[10px]"></i> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        async function populateDeadlineDocTypes(selectedId = '', isEdit = false) {
            await ensureDocTypesLoaded();
            const select = document.getElementById('deadline-doc-type');
            let optionsHtml = '';
            if (!isEdit) {
                optionsHtml += `<option value="all">All Document Types</option>`;
            }
            optionsHtml += allDocTypes.map(type => `
                <option value="${type.doc_type_id}" ${String(type.doc_type_id) === String(selectedId) ? 'selected' : ''}>
                    ${escapeHtml(type.doc_code)} - ${escapeHtml(type.description)}
                </option>
            `).join('');
            select.innerHTML = optionsHtml;
        }

        async function showDeadlineModal(targetId = null) {
            const item = targetId ? allDeadlines.find(deadline => Number(deadline.target_id) === Number(targetId)) : null;
            document.getElementById('deadline-modal-title').textContent = item ? 'Edit Submission Deadline' : 'Add Submission Deadline';
            document.getElementById('deadline-id').value = item?.target_id || '';
            let activeAY = window.systemSettings?.active_academic_year || '';
            document.getElementById('deadline-year').value = item?.year || activeAY || String(new Date().getFullYear());
            document.getElementById('deadline-quarter').value = item?.quarter || '1st Quarter';
            document.getElementById('deadline-date').value = item?.deadline || '';
            await populateDeadlineDocTypes(item?.doc_type_id || '', !!item);

            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-deadline').classList.remove('hidden');
        }

        async function saveDeadline() {
            const targetId = document.getElementById('deadline-id').value;
            const payload = {
                target_id: targetId,
                year: document.getElementById('deadline-year').value,
                quarter: document.getElementById('deadline-quarter').value,
                doc_type_id: document.getElementById('deadline-doc-type').value,
                deadline: document.getElementById('deadline-date').value
            };

            if (!/^\d{4}(-\d{4})?$/.test(payload.year)) {
                alert('Academic year must be a valid 4-digit year or range, e.g. 2026-2027.');
                return;
            }

            const action = targetId ? 'edit_submission_deadline' : 'add_submission_deadline';
            const res = await fetch('../../api/settings_api.php?action=' + action, {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                document.getElementById('deadline-year-filter').value = payload.year;
                loadSubmissionDeadlines();
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function deleteDeadline(targetId) {
            showThemeConfirm('Remove this submission deadline?', async () => {
                try {
                    const res = await fetch('../../api/settings_api.php?action=delete_submission_deadline', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ target_id: targetId })
                    });
                    const data = await res.json();
                    if (data.success) {
                        loadSubmissionDeadlines();
                    } else {
                        alert('Error: ' + data.error);
                    }
                } catch (err) {
                    alert('An error occurred while deleting.');
                }
            });
        }

        // Modal Helpers
        function showAddDocModal() {
            document.getElementById('doc-code').value = '';
            document.getElementById('doc-desc').value = '';
            document.getElementById('doc-req').value = 'Yes';
            document.getElementById('doc-freq').value = 'Quarterly';
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-doc').classList.remove('hidden');
        }

        async function showAddOfficeModal() {
            document.getElementById('off-name').value = '';
            document.getElementById('off-code').value = '';
            const res = await fetch('../../api/settings_api.php?action=get_categories');
            const data = await res.json();
            document.getElementById('off-cat').innerHTML = data.data.map(c => `<option value="${c.category_id}">${c.category_name}</option>`).join('');
            
            document.getElementById('modal-container').classList.remove('hidden');
            document.getElementById('modal-container').classList.add('flex');
            document.getElementById('modal-office').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modal-container').classList.add('hidden');
            document.getElementById('modal-container').classList.remove('flex');
            document.getElementById('modal-doc').classList.add('hidden');
            document.getElementById('modal-office').classList.add('hidden');
            document.getElementById('modal-edit-ay').classList.add('hidden');
            document.getElementById('modal-edit-office').classList.add('hidden');
            document.getElementById('modal-add-ay').classList.add('hidden');
            document.getElementById('modal-edit-doc').classList.add('hidden');
            document.getElementById('modal-deadline').classList.add('hidden');
        }

        // Announcements Configuration
        var announcementsList = [];

        async function loadAnnouncements() {
            try {
                const res = await fetch('../../api/announcements_api.php?action=get_announcements&t=' + new Date().getTime(), {
                    credentials: 'same-origin'
                });
                const data = await res.json();
                const tbody = document.getElementById('announcements-list-tbody');
                if (data.success) {
                    announcementsList = data.announcements || [];
                    if (announcementsList.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="p-3 text-center text-gray-500">No announcements posted yet.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = announcementsList.map(ann => {
                        const dateObj = new Date(ann.created_at);
                        const formattedDate = dateObj.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        
                        const imgHtml = ann.image_path 
                            ? `<img src="../../${ann.image_path}" class="h-10 w-auto rounded border object-cover cursor-pointer hover:opacity-80 mx-auto" onclick="window.open('../../${ann.image_path}', '_blank')">`
                            : '<span class="text-xs text-gray-400 font-medium">None</span>';
                            
                        const contentSnippet = ann.content.length > 80 
                            ? escapeHtml(ann.content.substring(0, 80)) + '...' 
                            : escapeHtml(ann.content);

                        return `
                            <tr class="border-b hover:bg-gray-50 transition-colors">
                                <td class="p-3 font-semibold text-gray-800">${escapeHtml(ann.title)}</td>
                                <td class="p-3 text-xs text-gray-600 max-w-xs truncate" title="${escapeHtml(ann.content)}">${contentSnippet}</td>
                                <td class="p-3 text-center">${imgHtml}</td>
                                <td class="p-3 text-xs text-gray-500 font-medium">${escapeHtml(ann.author_name || 'Admin')}</td>
                                <td class="p-3 text-xs text-gray-500">${formattedDate}</td>
                                <td class="p-3">
                                    <div class="inline-flex items-center gap-2">
                                        <button onclick="openAnnouncementModal('${ann.id}')" class="inline-flex items-center gap-1 bg-red-50 text-[#800000] border border-red-200 hover:bg-[#800000] hover:text-white px-2 py-1 rounded text-xs font-bold transition shadow-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button onclick="deleteAnnouncement('${ann.id}')" class="inline-flex items-center gap-1 bg-red-50 text-[#800000] border border-red-200 hover:bg-[#800000] hover:text-white px-2 py-1 rounded text-xs font-bold transition shadow-sm">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    }).join('');
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="p-3 text-center text-red-500">Error loading announcements: ${data.message}</td></tr>`;
                }
            } catch (err) {
                document.getElementById('announcements-list-tbody').innerHTML = `<tr><td colspan="6" class="p-3 text-center text-red-500">Failed to connect to API</td></tr>`;
            }
        }

        function openAnnouncementModal(announcementId = null) {
            const form = document.getElementById('announcementForm');
            form.reset();
            document.getElementById('announcement-image-input').value = '';
            document.getElementById('remove-image-flag').value = '0';
            document.getElementById('announcement-image-preview-container').classList.add('hidden');
            
            if (announcementId) {
                const ann = announcementsList.find(a => Number(a.id) === Number(announcementId));
                if (ann) {
                    document.getElementById('announcement-modal-title').innerHTML = '<i class="fas fa-edit"></i> Edit Announcement';
                    document.getElementById('announcement-id').value = ann.id;
                    document.getElementById('announcement-title').value = ann.title;
                    document.getElementById('announcement-content').value = ann.content;
                    
                    if (ann.image_path) {
                        document.getElementById('announcement-image-preview').src = '../../' + ann.image_path;
                        document.getElementById('announcement-image-preview-container').classList.remove('hidden');
                    }
                    document.getElementById('announcement-submit-btn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
                }
            } else {
                document.getElementById('announcement-modal-title').innerHTML = '<i class="fas fa-bullhorn"></i> Post Announcement';
                document.getElementById('announcement-id').value = '';
                document.getElementById('announcement-submit-btn').innerHTML = '<i class="fas fa-paper-plane"></i> Post Announcement';
            }
            document.getElementById('announcementModal').classList.remove('hidden');
        }

        function removeAnnouncementImage() {
            document.getElementById('remove-image-flag').value = '1';
            document.getElementById('announcement-image-preview-container').classList.add('hidden');
        }

        async function deleteAnnouncement(announcementId) {
            showThemeConfirm('Are you sure you want to delete this announcement?', async () => {
                try {
                    const res = await fetch('../../api/announcements_api.php?action=delete_announcement', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: announcementId }),
                        credentials: 'same-origin'
                    });
                    const data = await res.json();
                    alert(data.message);
                    if (data.success) {
                        loadAnnouncements();
                    }
                } catch (err) {
                    alert('Failed to delete announcement.');
                }
            });
        }

        // Expose functions to global scope for inline HTML handlers (vital for PJAX execution)
        window.switchTab = switchTab;
        window.loadAYs = loadAYs;
        window.showAddAYModal = showAddAYModal;
        window.saveAddAY = saveAddAY;
        window.setActiveAY = setActiveAY;
        window.editAY = editAY;
        window.saveEditAY = saveEditAY;
        window.deleteAY = deleteAY;

        window.loadDocTypes = loadDocTypes;
        window.showAddDocModal = showAddDocModal;
        window.saveDocType = saveDocType;
        window.editDocType = editDocType;
        window.saveEditDocType = saveEditDocType;
        window.deleteDocType = deleteDocType;

        window.loadOffices = loadOffices;
        window.showAddOfficeModal = showAddOfficeModal;
        window.saveOffice = saveOffice;
        window.editOffice = editOffice;
        window.saveEditOffice = saveEditOffice;
        window.deleteOffice = deleteOffice;
        window.toggleOffice = toggleOffice;

        window.loadSystemConfig = loadSystemConfig;
        window.saveActivePeriod = saveActivePeriod;
        window.loadSubmissionDeadlines = loadSubmissionDeadlines;
        window.showDeadlineModal = showDeadlineModal;
        window.saveDeadline = saveDeadline;
        window.deleteDeadline = deleteDeadline;

        window.loadAnnouncements = loadAnnouncements;
        window.openAnnouncementModal = openAnnouncementModal;
        window.deleteAnnouncement = deleteAnnouncement;

        // Init
        switchTab('system');

        // Form Submit
        document.getElementById('announcementForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('announcementForm'));
            const annId = document.getElementById('announcement-id').value;
            const action = annId ? 'edit_announcement' : 'post_announcement';
            formData.append('action', action);
            
            const submitBtn = document.getElementById('announcement-submit-btn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            fetch('../../api/announcements_api.php?action=' + action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    document.getElementById('announcementModal').classList.add('hidden');
                    const form = document.getElementById('announcementForm');
                    if (form) {
                        form.reset();
                    }
                    document.getElementById('announcement-id').value = '';
                    document.getElementById('remove-image-flag').value = '0';
                    document.getElementById('announcement-image-preview-container').classList.add('hidden');
                    loadAnnouncements();
                }
            })
            .catch(err => {
                alert('An error occurred while saving.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Diagnostic logger
        setTimeout(() => {
            const aySelect = document.getElementById('active-system-ay');
            const customContainer = document.getElementById('custom-container-active-system-ay');
            const logData = {
                url: window.location.href,
                aySelectExists: !!aySelect,
                aySelectHtml: aySelect ? aySelect.innerHTML : 'null',
                aySelectOptionsCount: aySelect ? aySelect.options.length : 0,
                aySelectValue: aySelect ? aySelect.value : 'null',
                customContainerExists: !!customContainer,
                customContainerHtml: customContainer ? customContainer.innerHTML : 'null',
                cookies: document.cookie
            };
            fetch('../../api/debug_helper.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ log: JSON.stringify(logData, null, 2) })
            }).catch(e => {});
        }, 1500);
    </script>
</body>
</html>

