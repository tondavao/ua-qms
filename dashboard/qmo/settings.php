<?php
session_start();
if(!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin'){ 
    header("Location: ../../index.php"); 
    exit();
}
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../includes/unit-seed.php";
ua_qms_seed_default_units($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UA QMS | Dynamic Academic & Office Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
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
    </style>
    <script>
        window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
    </script>
    <script src="../../assets/js/messaging.js" defer></script>
    <script src="../../assets/js/notifications.js" defer></script>
</head>
<body class="bg-gray-50 font-sans text-gray-800 flex h-screen overflow-hidden">

    <?php include "../../includes/sidebar_qmo.php"; ?>

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
        <div class="flex-1 overflow-auto p-4 md:p-8">
            <div class="max-w-6xl mx-auto space-y-6">
                


                <!-- Tabs Navigation -->
                <div class="flex flex-nowrap overflow-x-auto border-b border-gray-200 mb-6 bg-white rounded-t-xl px-4" style="scrollbar-width: none;">
                    <button onclick="switchTab('academic')" class="tab-btn active px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-academic"><i class="fas fa-calendar-alt mr-2"></i> Academic Year</button>
                    <button onclick="switchTab('documents')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-documents"><i class="fas fa-file-alt mr-2"></i> Document Types</button>
                    <button onclick="switchTab('offices')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-offices"><i class="fas fa-building mr-2"></i> Office/Department</button>
                    <button onclick="switchTab('system')" class="tab-btn px-4 md:px-6 py-4 text-sm font-medium transition-all whitespace-nowrap" id="tab-system"><i class="fas fa-cog mr-2"></i> System Config</button>
                </div>

                <!-- Tab Contents -->
                <div id="content-academic" class="tab-content space-y-6">
                    <div class="grid grid-cols-1 gap-6">
                        <!-- Academic Year Management -->
                        <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-calendar-alt text-primary"></i> Academic Year Management</h2>
                            </div>
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                                <div class="relative w-full sm:w-72">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                        <i class="fas fa-search text-sm"></i>
                                    </span>
                                    <input type="text" id="search-ay" oninput="renderAYs()" placeholder="Search academic years..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                                </div>
                                <button onclick="showAddAYModal()" class="w-full sm:w-auto bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-primary-light transition shadow-sm flex items-center justify-center gap-2">
                                    <i class="fas fa-plus"></i> Add Academic Year
                                </button>
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
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                            <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-file-alt text-primary"></i> Document Type Management</h2>
                        </div>
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                            <div class="relative w-full sm:w-72">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-search text-sm"></i>
                                </span>
                                <input type="text" id="search-doc" oninput="renderDocTypes()" placeholder="Search document types..." class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                            </div>
                            <button onclick="showAddDocModal()" class="w-full sm:w-auto bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-primary-light transition shadow-sm flex items-center justify-center gap-2">
                                <i class="fas fa-plus"></i> Add Document Type
                            </button>
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
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                            <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-building text-primary"></i> Office/Department Management</h2>
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
                            <button onclick="showAddOfficeModal()" class="w-full sm:w-auto bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-primary-light transition shadow-sm flex items-center justify-center gap-2">
                                <i class="fas fa-plus"></i> Add Office
                            </button>
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

                <div id="content-system" class="tab-content hidden space-y-6">
                    <!-- Active Period Settings -->
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800 mb-2">
                            <i class="fas fa-sliders-h text-primary"></i> Active Period Configuration
                        </h2>
                        <p class="text-xs text-gray-500 mb-6">Set the active Academic Year and Quarter. This setting determines which repository directories uploads are stored in and controls all default system dashboard views and reports.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Active Academic Year</label>
                                <select id="active-system-ay" class="w-full p-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                                    <!-- Populated via javascript -->
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Active Quarter</label>
                                <select id="active-system-quarter" class="w-full p-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none">
                                    <option value="1st Quarter">1st Quarter</option>
                                    <option value="2nd Quarter">2nd Quarter</option>
                                    <option value="3rd Quarter">3rd Quarter</option>
                                    <option value="4th Quarter">4th Quarter</option>
                                </select>
                            </div>
                            <div>
                                <button onclick="saveActivePeriod()" class="w-full bg-primary text-white px-4 py-2.5 rounded-lg text-sm font-bold hover:bg-primary-light transition shadow-sm flex items-center justify-center gap-2">
                                    <i class="fas fa-save"></i> Save Period Configuration
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200">
                        <div class="flex flex-col lg:flex-row justify-between gap-4 mb-5">
                            <div>
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-calendar-check text-primary text-sm"></i> Submission Deadline Management</h2>
                                <p class="text-xs text-gray-500 mt-1">Set quarterly submission deadlines by academic year and document type.</p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3 w-full lg:w-auto">
                                <input type="number" id="deadline-year-filter" min="2000" max="2100" oninput="loadSubmissionDeadlines()" placeholder="Year" class="w-full sm:w-32 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary">
                                <button onclick="showDeadlineModal()" class="w-full sm:w-auto bg-primary text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-primary-light transition shadow-sm flex items-center justify-center gap-2">
                                    <i class="fas fa-plus"></i> Add Deadline
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 border-b">
                                    <tr>
                                        <th class="p-3">Year</th>
                                        <th class="p-3">Quarter</th>
                                        <th class="p-3">Document Type</th>
                                        <th class="p-3">Deadline</th>
                                        <th class="p-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="deadline-list"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 mt-6">
                        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                            <div>
                                <h2 class="text-lg font-bold flex items-center gap-2 text-gray-800"><i class="fas fa-bullhorn text-primary text-sm"></i> Global Announcements</h2>
                                <p class="text-xs text-gray-500 mt-1">Post announcements that will appear in all unit dashboards.</p>
                            </div>
                            <button onclick="document.getElementById('announcementModal').classList.remove('hidden')" class="w-full lg:w-auto bg-[#800000] text-white px-4 py-2 rounded-lg font-bold text-sm shadow hover:bg-red-900 transition flex items-center justify-center gap-2">
                                <i class="fas fa-paper-plane"></i> Post Announcement
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Modals -->
    <div id="announcementModal" class="fixed inset-0 bg-black/60 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center">
                <h3 class="font-bold flex items-center gap-2"><i class="fas fa-bullhorn"></i> Post Announcement</h3>
                <button onclick="document.getElementById('announcementModal').classList.add('hidden')" class="text-white hover:text-gray-200 transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="announcementForm" class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Title</label>
                    <input type="text" name="title" required class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-600 uppercase mb-2">Message</label>
                    <textarea name="content" rows="4" required class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#800000] outline-none resize-none"></textarea>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" onclick="document.getElementById('announcementModal').classList.add('hidden')" class="px-5 py-2 border border-gray-300 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-[#800000] text-white rounded-lg text-sm font-bold shadow hover:bg-red-900 transition flex items-center gap-2">
                        <i class="fas fa-paper-plane"></i> Post Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modals -->
    <div id="modal-container" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
        <!-- Add AY Modal -->
        <div id="modal-add-ay" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Add Academic Year</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1">Year</label>
                    <input type="number" id="new-ay-range" min="2000" max="2100" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. 2026">
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveAddAY()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save</button>
                </div>
            </div>
        </div>

        <!-- Add Doc Type Modal -->
        <div id="modal-doc" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Add Document Type</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1">Code</label>
                    <input type="text" id="doc-code" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. QOP">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Description</label>
                    <input type="text" id="doc-desc" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">Required</label>
                        <select id="doc-req" class="w-full p-2 border rounded">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">Frequency</label>
                        <select id="doc-freq" class="w-full p-2 border rounded">
                            <option value="Quarterly">Quarterly</option>
                            <option value="Optional">Optional</option>
                            <option value="Once">Once</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveDocType()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save</button>
                </div>
            </div>
        </div>

        <!-- Edit Doc Type Modal -->
        <div id="modal-edit-doc" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Edit Document Type</h2>
            <div class="space-y-4">
                <input type="hidden" id="edit-doc-id">
                <div>
                    <label class="block text-sm font-bold mb-1">Code</label>
                    <input type="text" id="edit-doc-code" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. QOP">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Description</label>
                    <input type="text" id="edit-doc-desc" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">Required</label>
                        <select id="edit-doc-req" class="w-full p-2 border rounded">
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">Frequency</label>
                        <select id="edit-doc-freq" class="w-full p-2 border rounded">
                            <option value="Quarterly">Quarterly</option>
                            <option value="Optional">Optional</option>
                            <option value="Once">Once</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveEditDocType()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Add Office Modal -->
        <div id="modal-office" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Add Office/Department</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold mb-1">Office Name</label>
                    <input type="text" id="off-name" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. College of Engineering">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Code</label>
                    <input type="text" id="off-code" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. CE">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Category</label>
                    <select id="off-cat" class="w-full p-2 border rounded"></select>
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveOffice()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save</button>
                </div>
            </div>
        </div>

        <!-- Edit AY Modal -->
        <div id="modal-edit-ay" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Edit Academic Year</h2>
            <div class="space-y-4">
                <input type="hidden" id="edit-ay-id">
                <div>
                    <label class="block text-sm font-bold mb-1">Year</label>
                    <input type="number" id="edit-ay-range" min="2000" max="2100" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. 2026">
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveEditAY()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Edit Office Modal -->
        <div id="modal-edit-office" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 class="text-xl font-bold mb-4">Edit Office/Department</h2>
            <div class="space-y-4">
                <input type="hidden" id="edit-off-id">
                <div>
                    <label class="block text-sm font-bold mb-1">Office Name</label>
                    <input type="text" id="edit-off-name" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. College of Engineering">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Code</label>
                    <input type="text" id="edit-off-code" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. CE">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Category</label>
                    <select id="edit-off-cat" class="w-full p-2 border rounded"></select>
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveEditOffice()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Submission Deadline Modal -->
        <div id="modal-deadline" class="bg-white rounded-xl p-6 w-full max-w-md hidden">
            <h2 id="deadline-modal-title" class="text-xl font-bold mb-4">Add Submission Deadline</h2>
            <div class="space-y-4">
                <input type="hidden" id="deadline-id">
                <div>
                    <label class="block text-sm font-bold mb-1">Academic Year</label>
                    <input type="number" id="deadline-year" min="2000" max="2100" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. 2026">
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Quarter</label>
                    <select id="deadline-quarter" class="w-full p-2 border rounded">
                        <option value="1st Quarter">1st Quarter</option>
                        <option value="2nd Quarter">2nd Quarter</option>
                        <option value="3rd Quarter">3rd Quarter</option>
                        <option value="4th Quarter">4th Quarter</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Document Type</label>
                    <select id="deadline-doc-type" class="w-full p-2 border rounded"></select>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Deadline</label>
                    <input type="date" id="deadline-date" class="w-full p-2 border rounded outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div class="flex gap-2 pt-2">
                    <button onclick="closeModal()" class="flex-1 py-2 border rounded font-bold hover:bg-gray-50">Cancel</button>
                    <button onclick="saveDeadline()" class="flex-1 py-2 bg-primary text-white rounded font-bold hover:bg-primary-light">Save</button>
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
            if (tab === 'system') {
                loadSystemConfig();
            }
        }

        // Academic Years
        let allAYs = [];
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
                        ${ay.is_active ? '<span class="bg-emerald-100 text-emerald-800 px-2.5 py-0.5 rounded-full text-xs font-bold shadow-sm">Active</span>' : '<span class="bg-gray-100 text-gray-600 px-2.5 py-0.5 rounded-full text-xs font-bold">Inactive</span>'}
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            ${!ay.is_active ? `
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
            if (!/^\d{4}$/.test(range)) {
                alert('Academic year must be a 4-digit year, e.g. 2026.');
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
            await fetch('../../api/settings_api.php?action=set_active_year', {
                method: 'POST',
                body: JSON.stringify({ ay_id: id })
            });
            loadAYs();
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
            if (!/^\d{4}$/.test(range)) {
                alert('Academic year must be a 4-digit year, e.g. 2026.');
                return;
            }
            const res = await fetch('../../api/settings_api.php?action=edit_academic_year', {
                method: 'POST',
                body: JSON.stringify({ ay_id: id, year_range: range })
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                loadAYs();
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function deleteAY(id) {
            if (!confirm('Are you sure you want to delete this Academic Year?')) return;
            const res = await fetch('../../api/settings_api.php?action=delete_academic_year', {
                method: 'POST',
                body: JSON.stringify({ ay_id: id })
            });
            const data = await res.json();
            if (data.success) {
                loadAYs();
            } else {
                alert('Error: ' + data.error);
            }
        }

        // Document Types
        let allDocTypes = [];
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
            if (!confirm('Are you sure? This may affect existing submissions.')) return;
            await fetch('../../api/settings_api.php?action=delete_document_type', {
                method: 'POST',
                body: JSON.stringify({ doc_type_id: id })
            });
            loadDocTypes();
        }

        // Offices
        let allOffices = [];
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
            if (!confirm('Are you sure you want to delete this office? This will delete all documents and user links associated with it.')) return;
            const res = await fetch('../../api/settings_api.php?action=delete_office', {
                method: 'POST',
                body: JSON.stringify({ office_id: id })
            });
            const data = await res.json();
            if (data.success) {
                loadOffices();
            } else {
                alert('Error: ' + data.error);
            }
        }

        // Submission Deadline
        let allDeadlines = [];
        const quarters = ['1st Quarter', '2nd Quarter', '3rd Quarter', '4th Quarter'];

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
            // 1. Fetch available AYs for active AY dropdown
            const ayRes = await fetch('../../api/settings_api.php?action=get_academic_years');
            const ayData = await ayRes.json();
            const aySelect = document.getElementById('active-system-ay');
            if (aySelect && ayData.success) {
                aySelect.innerHTML = (ayData.data || []).map(ay => `<option value="${ay.year_range}">${ay.year_range}</option>`).join('');
            }

            // 2. Fetch current settings from DB
            const settingsRes = await fetch('../../api/settings_api.php?action=get_system_settings');
            const settingsData = await settingsRes.json();
            if (settingsData.success) {
                const s = settingsData.data;
                if (document.getElementById('active-system-ay')) {
                    document.getElementById('active-system-ay').value = s.active_academic_year || '';
                }
                if (document.getElementById('active-system-quarter')) {
                    document.getElementById('active-system-quarter').value = s.active_semester || '1st Quarter';
                }
            }

            // 3. Load submission deadlines
            loadSubmissionDeadlines();
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

        async function loadSubmissionDeadlines() {
            const yearInput = document.getElementById('deadline-year-filter');
            if (yearInput && !yearInput.value) {
                // Default to active year setting
                const activeAy = document.getElementById('active-system-ay')?.value || new Date().getFullYear();
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

        async function populateDeadlineDocTypes(selectedId = '') {
            await ensureDocTypesLoaded();
            const select = document.getElementById('deadline-doc-type');
            select.innerHTML = allDocTypes.map(type => `
                <option value="${type.doc_type_id}" ${String(type.doc_type_id) === String(selectedId) ? 'selected' : ''}>
                    ${escapeHtml(type.doc_code)} - ${escapeHtml(type.description)}
                </option>
            `).join('');
        }

        async function showDeadlineModal(targetId = null) {
            const item = targetId ? allDeadlines.find(deadline => Number(deadline.target_id) === Number(targetId)) : null;
            document.getElementById('deadline-modal-title').textContent = item ? 'Edit Submission Deadline' : 'Add Submission Deadline';
            document.getElementById('deadline-id').value = item?.target_id || '';
            document.getElementById('deadline-year').value = item?.year || document.getElementById('deadline-year-filter')?.value || new Date().getFullYear();
            document.getElementById('deadline-quarter').value = item?.quarter || '1st Quarter';
            document.getElementById('deadline-date').value = item?.deadline || '';
            await populateDeadlineDocTypes(item?.doc_type_id || '');

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

            if (!/^\d{4}$/.test(payload.year)) {
                alert('Academic year must be a 4-digit year, e.g. 2026.');
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
            if (!confirm('Remove this submission deadline?')) return;
            const res = await fetch('../../api/settings_api.php?action=delete_submission_deadline', {
                method: 'POST',
                body: JSON.stringify({ target_id: targetId })
            });
            const data = await res.json();
            if (data.success) {
                loadSubmissionDeadlines();
            } else {
                alert('Error: ' + data.error);
            }
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

        // Init
        loadAYs();
    </script>

    <script>
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
    <script>
        document.getElementById('announcementForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'post_announcement');
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';

            fetch('../../api/announcements_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    document.getElementById('announcementModal').classList.add('hidden');
                    this.reset();
                }
            })
            .catch(err => {
                alert('An error occurred while posting.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    </script>
</body>
</html>

