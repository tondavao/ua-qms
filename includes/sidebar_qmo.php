<?php
if (!isset($_SESSION)) {
    session_start();
}
if (!isset($_SESSION['logo_base64'])) {
    $logo_path = __DIR__ . '/../assets/img/ua-qms-logo.jpg';
    if (file_exists($logo_path)) {
        $_SESSION['logo_base64'] = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logo_path));
    } else {
        $_SESSION['logo_base64'] = '../../assets/img/ua-qms-logo.jpg';
    }
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin') {
    header("Location: ../../index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar -->
<aside id="main-sidebar" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 flex flex-col z-30 shadow-sm transition-all duration-300 fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0">
    
    <!-- Logo & Branding -->
    <div class="p-6 flex items-center justify-between border-b border-gray-50 sidebar-header-box relative">
        <div class="flex items-center gap-3 logo-container">
            <img src="<?php echo $_SESSION['logo_base64']; ?>" alt="UA Logo" class="w-14 h-14 rounded-full shadow-sm flex-shrink-0 transition-none" loading="eager" decoding="async" fetchpriority="high">
            <div class="flex flex-col text-white font-black text-[12px] leading-tight tracking-wide uppercase sidebar-logo-text font-sans">
                <span class="block whitespace-nowrap">UA-TLMC</span>
                <span class="block whitespace-nowrap">QUALITY MANAGEMENT</span>
            </div>
        </div>
        <button id="close-sidebar-btn" class="md:hidden p-2 text-gray-500 hover:bg-gray-100 rounded-lg transition-colors">
            <i class="fas fa-times text-lg"></i>
        </button>
        <button id="desktop-sidebar-toggle-btn" class="hidden md:flex absolute -right-3 top-[32px] w-6 h-6 bg-white border border-gray-200 rounded-full items-center justify-center text-gray-300 hover:text-gray-500 shadow-sm focus:outline-none z-50 transition-all duration-300 hover:scale-110" title="Toggle Sidebar">
            <i class="fas fa-chevron-left text-[9px]" id="desktop-toggle-icon"></i>
        </button>
    </div>
    
    <!-- Navigation Links -->
    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
        
        <a href="index.php" data-label="Dashboard" class="flex items-center px-3 py-2.5 <?php echo in_array($current_page, ['index.php', 'analytics_dashboard.php']) ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-chart-line w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Dashboard</span>
        </a>
        
        <a href="review_submissions.php" data-label="Submissions" class="flex items-center px-3 py-2.5 <?php echo in_array($current_page, ['review_submissions.php', 'monitoring.php']) ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-inbox w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Submissions</span>
        </a>

        <a href="repository.php" data-label="Repository" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'repository.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-database w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Repository</span>
        </a>

        <a href="reports.php" data-label="Reports" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'reports.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-file-contract w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Reports</span>
        </a>

        <a href="target_achievement.php" data-label="Target Achievement" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'target_achievement.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-bullseye w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Target Achievement</span>
        </a>
 
        <a href="manage_accounts.php" data-label="Manage Accounts" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'manage_accounts.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-users w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">Manage Accounts</span>
        </a>

        <a href="settings.php" data-label="System Configuration" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'settings.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-cog w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i> 
            <span class="font-medium ml-1 sidebar-text">System Configuration</span>
        </a>
    </nav>

    <!-- Sidebar Profile Info (Always shows account & role) -->
    <div class="p-4 border-t border-gray-100 bg-gray-50/50 mt-auto flex items-center gap-3 sidebar-profile-box whitespace-nowrap overflow-hidden">
        <div class="w-10 h-10 rounded-full bg-ua-maroon flex items-center justify-center text-white font-bold flex-shrink-0">
            <?php echo htmlspecialchars(isset($_SESSION['full_name']) ? substr($_SESSION['full_name'], 0, 1) : 'U'); ?>
        </div>
        <div class="sidebar-text flex-1 min-w-0 transition-opacity duration-300">
            <p class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></p>
            <p class="text-xs text-gray-500 truncate">QMO Admin</p>
        </div>
    </div>
</aside>

<!-- Profile Dropdown Menu Styles -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

    body, aside, aside span, aside p, aside a, input, select, textarea, button, table, th, td, h1, h2, h3, h4, h5, h6 {
        font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    body {
        opacity: 1;
        transition: opacity 0.25s ease;
    }
    body.qmo-page-loading {
        opacity: 1;
    }
    main {
        opacity: 0;
        transform: translateY(8px);
        transition: opacity 0.35s cubic-bezier(0.22, 1, 0.36, 1), transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
    }
    body.qmo-page-ready main {
        opacity: 1;
        transform: translateY(0);
    }
    #main-sidebar {
        background-color: #800000 !important;
        background-image: none !important;
        transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1), width 0.32s cubic-bezier(0.22, 1, 0.36, 1), padding 0.32s cubic-bezier(0.22, 1, 0.36, 1);
        will-change: transform, width;
        backface-visibility: hidden;
        border-right: 1px solid #600000 !important;
    }
    #main-sidebar::before {
        content: "" !important;
        position: absolute !important;
        inset: 0 !important;
        background-image: linear-gradient(rgba(128, 0, 0, 0.70), rgba(128, 0, 0, 0.70)), url('../../assets/img/man.png?v=4') !important;
        background-size: 140% auto !important;
        background-repeat: no-repeat !important;
        background-position: bottom right !important;
        background-blend-mode: overlay !important;
        transform: scaleX(-1) !important;
        pointer-events: none !important;
        z-index: 0 !important;
    }
    #main-sidebar .sidebar-header-box,
    #main-sidebar nav,
    #main-sidebar .sidebar-profile-box {
        position: relative !important;
        z-index: 10 !important;
    }
    aside a span {
        font-size: 14px !important;
    }
    aside a i {
        font-size: 16px !important;
    }
    
    /* Dark sidebar links and active states */
    #main-sidebar nav a {
        color: rgba(255, 255, 255, 0.85) !important;
        background-color: transparent !important;
        box-shadow: none !important;
        transition: all 0.2s ease !important;
    }
    #main-sidebar nav a:focus,
    #main-sidebar nav a:focus-visible,
    #main-sidebar nav a:active {
        outline: none !important;
        --tw-ring-color: transparent !important;
        --tw-ring-width: 0px !important;
        --tw-ring-offset-width: 0px !important;
    }
    #main-sidebar nav a i {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    #main-sidebar nav a:hover {
        background-color: rgba(255, 255, 255, 0.08) !important;
        color: #ffffff !important;
    }
    #main-sidebar nav a:hover i {
        color: #ffffff !important;
    }
    #main-sidebar nav a.bg-ua-maroon {
        background-color: #FFD700 !important; /* UA Gold */
        color: #800000 !important; /* Maroon text */
        font-weight: 700 !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
    }
    #main-sidebar nav a.bg-ua-maroon i {
        color: #800000 !important;
    }
    #main-sidebar .sidebar-header-box {
        border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
    }
    #main-sidebar .sidebar-logo-text {
        color: #ffffff !important;
    }
    #main-sidebar #close-sidebar-btn {
        color: rgba(255, 255, 255, 0.8) !important;
    }
    #main-sidebar #close-sidebar-btn:hover {
        background-color: rgba(255, 255, 255, 0.1) !important;
    }
    #main-sidebar #desktop-sidebar-toggle-btn {
        background-color: #800000 !important;
        border-color: rgba(255, 255, 255, 0.3) !important;
        color: rgba(255, 255, 255, 0.9) !important;
        z-index: 9999 !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    #main-sidebar #desktop-sidebar-toggle-btn:hover {
        color: #ffffff !important;
    }
    #main-sidebar .sidebar-profile-box {
        border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
        background-color: rgba(0, 0, 0, 0.18) !important;
    }
    #main-sidebar .sidebar-profile-box p {
        color: #ffffff !important;
    }
    #main-sidebar .sidebar-profile-box p.text-xs {
        color: rgba(255, 255, 255, 0.6) !important;
    }
    #main-sidebar .sidebar-profile-box .bg-ua-maroon {
        background-color: #FFD700 !important;
        color: #800000 !important;
    }

    :root {
        --ua-maroon: #800000;
        --ua-gold: #FFD700;
        --ua-green: #006400;
    }
    .bg-ua-maroon {
        background-color: var(--ua-maroon) !important;
    }
    .text-ua-maroon {
        color: var(--ua-maroon) !important;
    }
    .border-ua-maroon {
        border-color: var(--ua-maroon) !important;
    }

    .profile-dropdown {
        display: none;
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        min-width: 220px;
        margin-top: 8px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px) scale(0.98);
        transform-origin: top right;
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
    }
    .profile-dropdown.active {
        display: block !important;
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1);
    }
    .logo-container,
    .logo-container img,
    .sidebar-logo-text {
        will-change: auto;
        animation: none !important;
        transform: none !important;
        transition: none !important;
    }

    #notification-dropdown {
        display: block !important;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px) scale(0.95);
        transform-origin: top right;
        pointer-events: none;
        transition: opacity 0.15s cubic-bezier(0.16, 1, 0.3, 1), transform 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    #notification-dropdown:not(.hidden) {
        opacity: 1 !important;
        visibility: visible !important;
        transform: translateY(0) scale(1) !important;
        pointer-events: auto !important;
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
        outline: none;
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
    .logo-container,
    .logo-container img,
    .sidebar-logo-text {
        will-change: auto;
        animation: none !important;
        transform: none !important;
        transition: none !important;
    }
    .qmo-page-transition {
        opacity: 0;
        transform: translateY(8px);
        transition: opacity 0.35s cubic-bezier(0.22, 1, 0.36, 1), transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .qmo-page-transition.active {
        opacity: 1;
        transform: translateY(0);
    }
    #page-transition-overlay {
        position: fixed;
        inset: 0;
        background: linear-gradient(135deg, #fff9f9 0%, #ffffff 100%);
        opacity: 0;
        pointer-events: none;
        z-index: 50;
        transition: opacity 0.35s ease;
    }
    #page-transition-overlay.active {
        opacity: 1;
        pointer-events: auto;
    }
    @media (min-width: 768px) {
        #page-transition-overlay {
            left: 16rem;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.35s ease;
        }
        #main-sidebar.collapsed ~ #page-transition-overlay {
            left: 5rem;
        }
    }
    #inbox-btn:focus, #notification-bell:focus, #profile-btn:focus {
        outline: none;
    }

    /* Ensure header does not clip profile dropdown */
    header {
        overflow: visible !important;
    }

    /* Mobile responsive adjustments */
    @media (max-width: 767px) {
        #clock, header span.bg-red-50 {
            display: none !important;
        }
        header h1 {
            font-size: 1.1rem !important;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
        }
        header .gap-6 {
            gap: 0.5rem !important;
        }
        main > div.p-8, main > div.p-6, .p-8 {
            padding: 1rem !important;
        }
        header {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
            height: 4rem !important;
        }
        /* Fix data tables on mobile */
        table {
            white-space: nowrap;
        }
    }

    /* PJAX / Smooth Loading Page Transition Styles */
    #pjax-progress {
        position: fixed;
        top: 0;
        left: 0;
        height: 3px;
        background: #FFD700;
        z-index: 9999;
        width: 0;
        transition: width 0.4s ease, opacity 0.4s ease;
        opacity: 0;
    }
    .pjax-loading-content {
        opacity: 0 !important;
    }
    .pjax-fade-in {
        animation: pjaxFadeIn 0.3s ease-out forwards;
    }
    @keyframes pjaxFadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes modalBackdropFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes modalContentZoomIn {
        from {
            opacity: 0;
            transform: scale(0.95) translateY(12px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    /* Apply animations to modal overlays — modals must use z-index >= 50 to cover sidebar (z-30) */
    .fixed.inset-0:not(.hidden):not(#sidebar-backdrop), div[id="modal-container"]:not(.hidden) {
        animation: modalBackdropFadeIn 0.2s ease-out forwards;
    }
    /* Apply springy scale animation to inner modal content boxes */
    .fixed.inset-0:not(.hidden):not(#sidebar-backdrop) > div, div[id="modal-container"]:not(.hidden) > div:not(.hidden) {
        animation: modalContentZoomIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }
    /* Ensure all modal overlays cover the sidebar */
    #announcementModal, #modal-container, #scannerModal, #delete-confirm-modal, #accountModal, #profile-settings-modal, #messaging-modal, #notification-activity-modal {
        z-index: 9999 !important;
    }

    /* Enhance outline and shadows in all card divisions/containers for better separation */
    .bg-white.rounded-xl, 
    .bg-white.rounded-lg, 
    .bg-white.border, 
    div[class*="bg-white"][class*="rounded-"],
    section[class*="bg-white"][class*="rounded-"],
    form[class*="bg-white"][class*="rounded-"] {
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.05), 0 0 0 1px rgba(0, 0, 0, 0.05) !important;
        border: 1px solid #d1d5db !important; /* border-gray-300 */
        transition: box-shadow 0.2s ease, border-color 0.2s ease;
    }

    /* Ensure native select dropdown labels and options are highly visible */
    select, select option, .custom-select-container button {
        color: #374151 !important; /* text-gray-700 */
        background-color: #ffffff !important;
    }

    /* Header action buttons tooltips */
    .has-tooltip {
        position: relative;
    }
    .has-tooltip::after {
        content: attr(data-label);
        position: absolute;
        top: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: #1e1e1e;
        color: #ffffff;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.15s ease, visibility 0.15s ease;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        z-index: 10000;
    }
    .has-tooltip::before {
        content: '';
        position: absolute;
        top: calc(100% + 4px);
        left: 50%;
        transform: translateX(-50%);
        border-width: 4px;
        border-style: solid;
        border-color: transparent transparent #1e1e1e transparent;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity 0.15s ease, visibility 0.15s ease;
        z-index: 10000;
    }
    .has-tooltip:hover::after,
    .has-tooltip:hover::before {
        opacity: 1;
        visibility: visible;
    }

    /* Hide scrollbars on sidebar and navigation to keep it standard and proper */
    #main-sidebar nav::-webkit-scrollbar,
    #main-sidebar::-webkit-scrollbar {
        display: none !important;
    }
    #main-sidebar nav,
    #main-sidebar {
        -ms-overflow-style: none !important;  /* IE/Edge */
        scrollbar-width: none !important;  /* Firefox */
    }

    .sidebar-text {
        display: inline-block !important;
        opacity: 1;
        max-width: 180px;
        transition: opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.2s cubic-bezier(0.4, 0, 0.2, 1), margin 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        overflow: hidden;
        white-space: nowrap;
    }

    .sidebar-logo-text {
        display: flex !important;
        flex-direction: column !important;
        opacity: 1;
        max-width: 180px;
        transition: opacity 0.2s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.2s cubic-bezier(0.4, 0, 0.2, 1), margin 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        overflow: hidden;
        white-space: normal;
    }

    /* Desktop Collapsible Sidebar transitions and states */
    @media (min-width: 768px) {
        #main-sidebar {
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            width: 16rem; /* 256px */
            z-index: 60 !important;
            position: relative !important;
            overflow: visible !important;
        }
        #main-sidebar.collapsed {
            width: 5rem !important; /* 80px */
            overflow: visible !important;
        }
        #main-sidebar.collapsed nav {
            overflow: visible !important;
        }
        /* Fade out and shrink text/logo when collapsed */
        #main-sidebar.collapsed .sidebar-text,
        #main-sidebar.collapsed .sidebar-logo-text {
            opacity: 0 !important;
            max-width: 0 !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
            pointer-events: none !important;
        }
        /* Center content of header when collapsed */
        #main-sidebar.collapsed .sidebar-header-box {
            padding: 1.25rem 0.5rem !important;
            align-items: center !important;
            overflow: visible !important;
        }
        #main-sidebar.collapsed .logo-container {
            width: 100% !important;
            justify-content: center !important;
            overflow: visible !important;
        }
        /* Center navigation elements */
        #main-sidebar.collapsed nav {
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }
        #main-sidebar.collapsed nav a {
            justify-content: center !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            position: relative;
        }
        #main-sidebar.collapsed nav a i {
            margin-right: 0 !important;
            margin-left: 0 !important;
            font-size: 1.25rem !important;
        }
        /* Collapsed profile box */
        #main-sidebar.collapsed .sidebar-profile-box {
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
            justify-content: center !important;
        }
        /* Tooltip hover labels when collapsed */
        #main-sidebar.collapsed nav a::after {
            content: attr(data-label);
            position: absolute;
            left: calc(100% + 12px);
            top: 50%;
            transform: translateY(-50%);
            background: #1e1e1e;
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
            z-index: 100;
        }
        #main-sidebar.collapsed nav a::before {
            content: '';
            position: absolute;
            left: calc(100% + 6px);
            top: 50%;
            transform: translateY(-50%);
            border-width: 4px;
            border-style: solid;
            border-color: transparent #1e1e1e transparent transparent;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            z-index: 100;
        }
        #main-sidebar.collapsed nav a:hover::after,
        #main-sidebar.collapsed nav a:hover::before {
            opacity: 1;
            visibility: visible;
        }
    }
</style>

<!-- Sidebar Backdrop for Mobile -->
<div id="sidebar-backdrop" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-[35] hidden transition-opacity duration-300 opacity-0"></div>

<!-- Profile Settings Modal -->
<div id="profile-settings-modal" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl border border-gray-200/80 w-full max-w-md overflow-hidden transform transition-all flex flex-col">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-user-cog text-[#800000]"></i> Profile Settings
            </h3>
            <button onclick="closeProfileSettingsModal()" class="text-gray-400 hover:text-gray-600 transition">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        
        <form id="profile-settings-form" class="p-6 space-y-4" onsubmit="handleProfileSettingsSubmit(event)">
            <div id="profile-settings-error" class="p-3 bg-red-50 border border-red-100 text-red-800 text-xs font-semibold rounded-lg hidden"></div>
            <div id="profile-settings-success" class="p-3 bg-green-50 border border-green-100 text-green-800 text-xs font-semibold rounded-lg hidden"></div>
            
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Full Name</label>
                <input type="text" id="profile-full-name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email Address</label>
                <input type="email" id="profile-email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Profile Photo</label>
                <div class="flex items-center gap-3">
                    <div id="profile-photo-preview" class="w-12 h-12 rounded-full bg-red-50 border border-gray-200 flex items-center justify-center text-[#800000] text-sm overflow-hidden flex-shrink-0 font-bold">
                        <i class="fas fa-user text-lg"></i>
                    </div>
                    <input type="file" id="profile-photo-file" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-[#800000] hover:file:bg-red-100 cursor-pointer">
                </div>
            </div>
            
            <div class="border-t border-gray-100 pt-4">
                <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-3">Change Password (Optional)</h4>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">New Password</label>
                        <input type="password" id="profile-new-password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Confirm New Password</label>
                        <input type="password" id="profile-confirm-password" placeholder="Confirm new password" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
                    </div>
                </div>
            </div>
            
            <div class="border-t border-gray-100 pt-4">
                <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Current Password <span class="text-red-500">*</span></label>
                <input type="password" id="profile-current-password" required placeholder="Required to save changes" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent">
            </div>
            
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeProfileSettingsModal()" class="px-4 py-2 border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg text-sm font-semibold transition">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-[#800000] hover:bg-red-950 text-white rounded-lg text-sm font-semibold transition shadow-sm flex items-center gap-2">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Page Transition Overlay -->
<div id="page-transition-overlay" class="hidden"></div>

<!-- Custom Themed Alert Modal -->
<div id="theme-alert-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-[10000] backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><i class="fas fa-info-circle"></i> System Message</h3>
            <button onclick="closeThemeAlert()" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <p id="theme-alert-text" class="text-sm text-gray-600 mb-6 text-center whitespace-pre-line"></p>
            <button onclick="closeThemeAlert()" class="w-full py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg font-bold transition shadow-sm">
                OK
            </button>
        </div>
    </div>
</div>

<!-- Custom Themed Confirm Modal -->
<div id="theme-confirm-modal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-[10000] backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden transform scale-95 transition-transform duration-300">
        <div class="px-6 py-4 bg-[#800000] text-white flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><i class="fas fa-question-circle"></i> Confirm Action</h3>
            <button onclick="closeThemeConfirm(false)" class="text-white hover:text-gray-200 transition"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <p id="theme-confirm-text" class="text-sm text-gray-600 mb-6 text-center whitespace-pre-line"></p>
            <div class="flex gap-3">
                <button onclick="closeThemeConfirm(false)" class="flex-1 py-2 border border-gray-200 hover:bg-gray-50 text-gray-700 font-bold rounded-lg transition">
                    Cancel
                </button>
                <button onclick="closeThemeConfirm(true)" class="flex-1 py-2 bg-[#800000] hover:bg-red-900 text-white font-bold rounded-lg transition shadow-sm">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>


<script id="sidebar-script">
window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
window.CURRENT_USER_ROLE = <?php echo json_encode($_SESSION['role']); ?>;
window.CURRENT_USER_PHOTO = <?php echo json_encode($_SESSION['profile_photo'] ?? ''); ?>;
// SPA Routing and Clock Configuration
window.pageIntervals = window.pageIntervals || [];
window.pageTimeouts = window.pageTimeouts || [];

// Track and clear intervals/timeouts during SPA page switches to prevent memory leaks and API conflicts
const originalSetInterval = window.setInterval;
window.setInterval = function(fn, delay, ...args) {
    const id = originalSetInterval(fn, delay, ...args);
    window.pageIntervals.push(id);
    return id;
};

const originalSetTimeout = window.setTimeout;
window.setTimeout = function(fn, delay, ...args) {
    const id = originalSetTimeout(fn, delay, ...args);
    window.pageTimeouts.push(id);
    return id;
};

function clearPageTimers() {
    window.pageIntervals.forEach(clearInterval);
    window.pageTimeouts.forEach(clearTimeout);
    window.pageIntervals = [];
    window.pageTimeouts = [];
}

// Global Custom Themed Dialogs
let themeConfirmCallback = null;

window.showThemeAlert = function(message) {
    const modal = document.getElementById('theme-alert-modal');
    const text = document.getElementById('theme-alert-text');
    if (modal && text) {
        text.textContent = message;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
};

window.closeThemeAlert = function() {
    const modal = document.getElementById('theme-alert-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
};

window.showThemeConfirm = function(message, callback) {
    const modal = document.getElementById('theme-confirm-modal');
    const text = document.getElementById('theme-confirm-text');
    if (modal && text) {
        text.textContent = message;
        themeConfirmCallback = callback;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
};

window.closeThemeConfirm = function(result) {
    const modal = document.getElementById('theme-confirm-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    if (result && themeConfirmCallback) {
        themeConfirmCallback();
    }
    themeConfirmCallback = null;
};

// Override default window.alert
window.alert = function(msg) {
    window.showThemeAlert(String(msg ?? ''));
};

window.logoutUser = function() {
    window.showThemeConfirm('Are you sure you want to log out?', function() {
        window.location.href = '../../logout.php';
    });
};


// Global clock runner
function initClock() {
    const clockEl = document.getElementById('clock');
    if (clockEl) {
        const updateClock = () => {
            clockEl.innerText = new Date().toLocaleString();
        };
        updateClock();
        originalSetInterval(updateClock, 1000); // Using original to avoid clearing it
    }
}

// Progress bar functions
function showProgressBar() {
    let bar = document.getElementById('pjax-progress');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'pjax-progress';
        document.body.appendChild(bar);
    }
    bar.style.transition = 'width 0.35s ease, opacity 0.35s ease';
    bar.style.opacity = '1';
    bar.style.width = '0%';
    bar.offsetWidth;
    bar.style.width = '80%';
}

function hideProgressBar() {
    const bar = document.getElementById('pjax-progress');
    if (bar) {
        bar.style.transition = 'width 0.35s ease, opacity 0.35s ease';
        bar.style.width = '100%';
        originalSetTimeout(() => {
            bar.style.opacity = '0';
            originalSetTimeout(() => {
                bar.style.width = '0%';
            }, 350);
        }, 220);
    }
}

function showPageTransitionOverlay() {
    const overlay = document.getElementById('page-transition-overlay');
    if (!overlay) return;
    overlay.classList.add('active');
    overlay.style.opacity = '1';
    overlay.style.pointerEvents = 'auto';
}

function hidePageTransitionOverlay() {
    const overlay = document.getElementById('page-transition-overlay');
    if (!overlay) return;
    overlay.style.opacity = '0';
    overlay.style.pointerEvents = 'none';
    originalSetTimeout(() => overlay.classList.remove('active'), 350);
}

function applyPageTransition() {
    const target = document.querySelector('main') || document.body;
    if (!target) return;
    target.classList.remove('qmo-page-transition');
    target.classList.add('qmo-page-transition');
    document.body.classList.add('qmo-page-loading');
    originalSetTimeout(() => {
        document.body.classList.remove('qmo-page-loading');
        document.body.classList.add('qmo-page-ready');
        target.classList.add('active');
    }, 20);
}

// Merge new head links and scripts
function mergeHead(doc) {
    const currentHead = document.head;
    
    // Clear old page-specific style blocks
    document.querySelectorAll('.pjax-style').forEach(el => el.remove());

    // Merge inline style blocks
    const newStyles = doc.querySelectorAll('head style, body style');
    newStyles.forEach(style => {
        // Skip styles that are inside the sidebar to avoid duplicates
        if (style.closest('aside')) {
            return;
        }
        const newStyle = document.createElement('style');
        newStyle.classList.add('pjax-style');
        newStyle.textContent = style.textContent;
        currentHead.appendChild(newStyle);
    });

    // Merge stylesheets
    const newStylesheets = doc.querySelectorAll('head link[rel="stylesheet"]');
    newStylesheets.forEach(link => {
        const href = link.getAttribute('href');
        if (href && !currentHead.querySelector(`link[href="${href}"]`)) {
            const newLink = document.createElement('link');
            newLink.rel = 'stylesheet';
            newLink.href = href;
            currentHead.appendChild(newLink);
        }
    });

    // Merge scripts
    const newHeadScripts = doc.querySelectorAll('head script');
    newHeadScripts.forEach(script => {
        const src = script.getAttribute('src');
        if (src && !currentHead.querySelector(`script[src="${src}"]`)) {
            const newScript = document.createElement('script');
            newScript.src = src;
            if (script.hasAttribute('defer')) newScript.defer = true;
            currentHead.appendChild(newScript);
        }
    });
}

// Execute scripts in page body
function executePageScripts(doc) {
    // Clear old pjax scripts
    document.querySelectorAll('.pjax-script').forEach(el => el.remove());

    const scripts = doc.body.querySelectorAll('script');
    
    function runScripts() {
        scripts.forEach(script => {
            const src = script.getAttribute('src');
            
            // Skip global scripts that we know are already loaded and running
            if (src && (src.includes('messaging.js') || src.includes('notifications.js') || src.includes('sidebar_dean.php') || src.includes('sidebar_qmo.php') || src.includes('custom-select.js'))) {
                return;
            }

            // Skip script if it has id="sidebar-script" or is inside the sidebar
            if (script.id === 'sidebar-script' || script.closest('aside')) {
                return;
            }

            const newScript = document.createElement('script');
            newScript.classList.add('pjax-script');
            if (src) {
                newScript.src = src;
            } else {
                newScript.textContent = script.textContent;
            }
            
            Array.from(script.attributes).forEach(attr => {
                if (attr.name !== 'src' && attr.name !== 'class') {
                    newScript.setAttribute(attr.name, attr.value);
                }
            });
            
            try {
                document.body.appendChild(newScript);
            } catch (error) {
                console.error("PJAX script execution error:", error);
                window.pjaxErrors = window.pjaxErrors || [];
                window.pjaxErrors.push({ error: error.message, stack: error.stack });
            }
        });
    }

    // If any script content references Chart.js or "new Chart" and window.Chart is not defined yet, load it first!
    let needsChart = false;
    scripts.forEach(script => {
        if (!script.getAttribute('src') && script.textContent.includes('new Chart')) {
            needsChart = true;
        }
    });

    if (needsChart && !window.Chart) {
        console.log("Chart.js is required but not loaded yet. Loading now...");
        const chartScript = document.createElement('script');
        chartScript.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        chartScript.onload = function() {
            console.log("Chart.js loaded successfully. Running inline page scripts...");
            runScripts();
        };
        document.head.appendChild(chartScript);
    } else {
        runScripts();
    }
}

// Update sidebar active link
function updateActiveSidebarLink(url) {
    const filename = url.split('/').pop().split('?')[0];
    const sidebarLinks = document.querySelectorAll('#main-sidebar nav a');
    
    sidebarLinks.forEach(link => {
        const href = link.getAttribute('href');
        const isCurrent = (filename === href) || 
                          (filename === 'index.php' && href === 'index.php') ||
                          (href === 'index.php' && filename === 'analytics_dashboard.php') ||
                          (href === 'review_submissions.php' && filename === 'monitoring.php');
                          
        if (isCurrent) {
            link.className = link.className.replace(/text-gray-700|hover:bg-red-50|hover:text-ua-maroon/g, '').trim();
            if (!link.classList.contains('bg-ua-maroon')) {
                link.classList.add('bg-ua-maroon', 'text-white', 'shadow-md', 'active:bg-red-900');
            }
        } else {
            link.classList.remove('bg-ua-maroon', 'text-white', 'shadow-md', 'active:bg-red-900');
            if (!link.classList.contains('text-gray-700')) {
                link.classList.add('text-gray-700', 'hover:bg-red-50', 'hover:text-ua-maroon');
            }
        }
    });
}

// Intercept popstate - use standard browser navigation
window.addEventListener('popstate', e => {
    window.location.reload();
});

// Main PJAX Loader function
async function loadPage(url, pushState = true) {
    showProgressBar();
    
    try {
        const cacheBuster = (url.indexOf('?') !== -1 ? '&' : '?') + '_t=' + Date.now();
        const response = await fetch(url + cacheBuster, { cache: 'no-store' });
        if (!response.ok) throw new Error("Load failed");
        
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Clear previous page specific timers
        clearPageTimers();
        
        // Merge header scripts/stylesheets
        mergeHead(doc);
        
        // Replace main element
        const newMain = doc.querySelector('main');
        const currentMain = document.querySelector('main');
        if (newMain && currentMain) {
            currentMain.innerHTML = newMain.innerHTML;
            Array.from(newMain.attributes).forEach(attr => {
                currentMain.setAttribute(attr.name, attr.value);
            });
        }
        
        // Update Title
        document.title = doc.title;
        
        // Execute new scripts
        executePageScripts(doc);

        // Re-trigger Tailwind CDN to re-scan and compile classes on the new content
        if (window.tailwind && typeof window.tailwind.refresh === 'function') {
            window.tailwind.refresh();
        }

        // Re-run custom select initialization
        if (typeof initCustomSelects === 'function') {
            initCustomSelects();
        }
        
        // Update URL pushState
        if (pushState) {
            history.pushState({ url }, '', url);
        }
        
        // Update Sidebar active state
        updateActiveSidebarLink(url);
        
        // Re-run global clock check
        initClock();
        setupMobileMenuButton();
        setupProfileDropdown();
        initHeaderTooltips();
 
        // Mobile sidebar auto-close on navigate
        const backdrop = document.getElementById('sidebar-backdrop');
        const sidebar = document.getElementById('main-sidebar');
        if (backdrop && !backdrop.classList.contains('hidden')) {
            backdrop.classList.remove('opacity-100');
            backdrop.classList.add('opacity-0');
            sidebar.classList.add('-translate-x-full');
            setTimeout(() => {
                backdrop.classList.add('hidden');
                backdrop.classList.remove('opacity-0');
            }, 300);
        }

        // Add smooth fade-in to main content
        if (currentMain) {
            currentMain.classList.add('pjax-fade-in');
            currentMain.addEventListener('animationend', () => {
                currentMain.classList.remove('pjax-fade-in');
            }, { once: true });
        }
        
    } catch (e) {
        console.error("SPA load failed, falling back to window redirect:", e);
        window.location.href = url;
    } finally {
        hideProgressBar();
    }
}

// Setup mobile menu button
function setupMobileMenuButton() {
    const header = document.querySelector('header');
    const sidebar = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (header && sidebar && backdrop && !document.getElementById('mobile-menu-toggle')) {
        const mobileMenuBtn = document.createElement('button');
        mobileMenuBtn.className = 'md:hidden p-2 text-gray-600 hover:bg-gray-100 rounded-lg mr-4 focus:outline-none';
        mobileMenuBtn.innerHTML = '<i class="fas fa-bars text-xl"></i>';
        mobileMenuBtn.id = 'mobile-menu-toggle';
        
        header.insertBefore(mobileMenuBtn, header.firstChild);
        
        mobileMenuBtn.addEventListener('click', function() {
            // Temporarily raise sidebar above modals while it's open on mobile
            sidebar.style.zIndex = '9998';
            backdrop.classList.remove('hidden');
            originalSetTimeout(() => {
                backdrop.classList.add('opacity-100');
            }, 10);
            
            sidebar.classList.remove('-translate-x-full');
        });
    }
}

function openProfileSettingsModal() {
    const modal = document.getElementById('profile-settings-modal');
    if (!modal) return;
    
    // Clear forms and messages
    document.getElementById('profile-settings-form').reset();
    document.getElementById('profile-settings-error').classList.add('hidden');
    document.getElementById('profile-settings-success').classList.add('hidden');
    
    const preview = document.getElementById('profile-photo-preview');
    if (preview) {
        preview.innerHTML = '<i class="fas fa-user text-lg"></i>';
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    // Fetch current user details
    fetch('../../api/update_profile.php')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('profile-full-name').value = data.data.full_name;
                document.getElementById('profile-email').value = data.data.email;
                if (data.data.profile_photo && preview) {
                    preview.innerHTML = `<img src="${data.data.profile_photo}" class="w-full h-full object-cover" />`;
                } else if (preview) {
                    preview.innerHTML = `<span class="font-bold text-[#800000]">${data.data.full_name.trim().charAt(0).toUpperCase()}</span>`;
                }
            } else {
                console.error("Failed to load profile:", data.message);
            }
        })
        .catch(err => console.error("Error loading profile:", err));
}

// Bind live photo preview change handler
document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'profile-photo-file') {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(evt) {
                const preview = document.getElementById('profile-photo-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${evt.target.result}" class="w-full h-full object-cover" />`;
                }
            };
            reader.readAsDataURL(file);
        }
    }
});

function closeProfileSettingsModal() {
    const modal = document.getElementById('profile-settings-modal');
    if (modal) {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }
}

function handleProfileSettingsSubmit(event) {
    event.preventDefault();
    const errorDiv = document.getElementById('profile-settings-error');
    const successDiv = document.getElementById('profile-settings-success');
    
    errorDiv.classList.add('hidden');
    successDiv.classList.add('hidden');
    
    const fullName = document.getElementById('profile-full-name').value;
    const email = document.getElementById('profile-email').value;
    const currentPassword = document.getElementById('profile-current-password').value;
    const newPassword = document.getElementById('profile-new-password').value;
    const confirmPassword = document.getElementById('profile-confirm-password').value;
    const photoFile = document.getElementById('profile-photo-file').files[0];
    
    if (newPassword && newPassword !== confirmPassword) {
        errorDiv.textContent = "New passwords do not match.";
        errorDiv.classList.remove('hidden');
        return;
    }
    
    const formData = new FormData();
    formData.append('full_name', fullName);
    formData.append('email', email);
    formData.append('current_password', currentPassword);
    if (newPassword) {
        formData.append('new_password', newPassword);
        formData.append('confirm_password', confirmPassword);
    }
    if (photoFile) {
        formData.append('profile_photo', photoFile);
    }
    
    fetch('../../api/update_profile.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = data.message;
            successDiv.classList.remove('hidden');
            
            if (data.profile_photo) {
                window.CURRENT_USER_PHOTO = data.profile_photo;
                updateHeaderProfilePhoto();
            } else {
                // If photo is not uploaded, just use the letter initial
                const profileBtn = document.getElementById('profile-btn');
                if (profileBtn) {
                    profileBtn.innerHTML = fullName.trim().charAt(0).toUpperCase();
                    profileBtn.classList.remove('overflow-hidden');
                }
            }
            
            // Clear current/new password fields
            document.getElementById('profile-current-password').value = '';
            document.getElementById('profile-new-password').value = '';
            document.getElementById('profile-confirm-password').value = '';
            
            // Auto close after 1.5s
            setTimeout(closeProfileSettingsModal, 1500);
        } else {
            errorDiv.textContent = data.message;
            errorDiv.classList.remove('hidden');
        }
    })
    .catch(err => {
        errorDiv.textContent = "An error occurred. Please try again.";
        errorDiv.classList.remove('hidden');
        console.error("Error updating profile:", err);
    });
}

function setupProfileDropdown() {
    const profileBtn = document.getElementById('profile-btn');
    const dropdown = document.getElementById('profile-dropdown');
    if (profileBtn && dropdown) {
        profileBtn.onclick = function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('active');
        };

        if (!document.getElementById('profile-settings-btn')) {
            const logoutLink = dropdown.querySelector('.logout, a[href*="logout.php"], button[data-action="logout"]');
            if (logoutLink) {
                const settingsBtn = document.createElement('button');
                settingsBtn.id = 'profile-settings-btn';
                settingsBtn.type = 'button';
                settingsBtn.className = 'profile-dropdown-item';
                settingsBtn.style.cssText = 'border-top: 1px solid #f3f4f6; cursor: pointer; display: flex; align-items: center; padding: 12px 16px; width: 100%; text-align: left; font-size: 14px; color: #374151; background: none; border: none; outline: none; transition: background-color 0.2s;';
                settingsBtn.onmouseover = function() { this.style.backgroundColor = '#f3f4f6'; };
                settingsBtn.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
                settingsBtn.innerHTML = '<i class="fas fa-cog text-gray-500 mr-3 w-4 text-center"></i> Profile Settings';
                settingsBtn.onclick = function(e) {
                    e.stopPropagation();
                    dropdown.classList.remove('active');
                    openProfileSettingsModal();
                };
                dropdown.insertBefore(settingsBtn, logoutLink);
            }
        }

        const logoutTarget = dropdown.querySelector('.logout, a[href*="logout.php"], button[data-action="logout"]');
        if (logoutTarget) {
            logoutTarget.onclick = function(e) {
                e.stopPropagation();
                e.preventDefault();
                window.logoutUser();
            };
            if (logoutTarget.tagName === 'A') {
                logoutTarget.setAttribute('href', '../../logout.php');
            }
        }
    }
}

function setupDesktopSidebarToggle() {
    const sidebar = document.getElementById('main-sidebar');
    const toggleBtn = document.getElementById('desktop-sidebar-toggle-btn');
    const toggleIcon = document.getElementById('desktop-toggle-icon');
    
    if (!sidebar || !toggleBtn) return;
    
    const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
        if (toggleIcon) {
            toggleIcon.classList.remove('fa-chevron-left');
            toggleIcon.classList.add('fa-chevron-right');
        }
    } else {
        sidebar.classList.remove('collapsed');
        if (toggleIcon) {
            toggleIcon.classList.remove('fa-chevron-right');
            toggleIcon.classList.add('fa-chevron-left');
        }
    }
    
    toggleBtn.onclick = function(e) {
        e.stopPropagation();
        sidebar.classList.toggle('collapsed');
        const nowCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebar-collapsed', nowCollapsed);
        
        if (toggleIcon) {
            if (nowCollapsed) {
                toggleIcon.classList.remove('fa-chevron-left');
                toggleIcon.classList.add('fa-chevron-right');
            } else {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        }
    };

    // Expand sidebar if clicked while collapsed
    sidebar.addEventListener('click', function(e) {
        if (sidebar.classList.contains('collapsed')) {
            if (e.target.closest('#desktop-sidebar-toggle-btn')) return;
            
            sidebar.classList.remove('collapsed');
            localStorage.setItem('sidebar-collapsed', 'false');
            if (toggleIcon) {
                toggleIcon.classList.remove('fa-chevron-right');
                toggleIcon.classList.add('fa-chevron-left');
            }
        }
    });
}

function updateHeaderProfilePhoto() {
    const profileBtn = document.getElementById('profile-btn');
    if (profileBtn) {
        if (window.CURRENT_USER_PHOTO) {
            profileBtn.innerHTML = `<img src="${window.CURRENT_USER_PHOTO}" class="w-full h-full object-cover rounded-full" alt="Profile" />`;
            profileBtn.classList.add('overflow-hidden');
        }
    }
}

function initHeaderTooltips() {
    const inboxBtn = document.getElementById('inbox-btn');
    if (inboxBtn) {
        inboxBtn.classList.add('has-tooltip');
        inboxBtn.setAttribute('data-label', 'Messenger');
    }
    const notifBtn = document.getElementById('notification-bell') || document.getElementById('notification-btn');
    if (notifBtn) {
        notifBtn.classList.add('has-tooltip');
        notifBtn.setAttribute('data-label', 'Notifications');
    }
    const profileBtn = document.getElementById('profile-btn');
    if (profileBtn) {
        profileBtn.classList.add('has-tooltip');
        profileBtn.setAttribute('data-label', 'Profile Settings');
    }
    updateHeaderProfilePhoto();
}

function initQmoSidebar() {
    if (window.qmoSidebarInitialized) return;
    window.qmoSidebarInitialized = true;
    
    initHeaderTooltips();

    const sidebar = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const closeBtn = document.getElementById('close-sidebar-btn');
    
    // Bind profile dropdown click handler
    setupProfileDropdown();
    
    // Setup desktop sidebar collapsible behavior
    setupDesktopSidebarToggle();

    // Close dropdown when clicking outside
    document.addEventListener('click', e => {
        const dropdown = document.getElementById('profile-dropdown');
        if (dropdown && !e.target.closest('#profile-btn') && !e.target.closest('#profile-dropdown')) {
            dropdown.classList.remove('active');
        }
    });

    // Intercept clicks on links for smooth PJAX load
    document.addEventListener('click', e => {
        const anchor = e.target.closest('a');
        if (!anchor) return;
        
        const href = anchor.getAttribute('href');
        if (!href) return;
        
        // Skip external links, hashes, js calls, and logout script
        if (href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#') || href.startsWith('javascript:')) {
            return;
        }
        if (href.includes('logout.php')) {
            e.preventDefault();
            window.logoutUser();
            return;
        }
        
        e.preventDefault();
        showProgressBar();
        showPageTransitionOverlay();
        originalSetTimeout(() => {
            window.location.href = href;
        }, 240);
    });

    setupMobileMenuButton();
    
    function closeSidebar() {
        if (!backdrop || !sidebar) return;
        backdrop.classList.remove('opacity-100');
        backdrop.classList.add('opacity-0');
        
        sidebar.classList.add('-translate-x-full');
        
        originalSetTimeout(() => {
            backdrop.classList.add('hidden');
            backdrop.classList.remove('opacity-0');
            // Restore normal z-index so modals can cover sidebar when closed
            sidebar.style.zIndex = '';
        }, 300);
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    initClock();
}

function initQmoSidebarOnLoad() {
    initQmoSidebar();
    applyPageTransition();
    const overlay = document.getElementById('page-transition-overlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.opacity = '0';
        overlay.style.pointerEvents = 'none';
    }
    showProgressBar();
    window.addEventListener('load', () => {
        hideProgressBar();
        hidePageTransitionOverlay();
    }, { once: true });
    setTimeout(() => {
        hideProgressBar();
        hidePageTransitionOverlay();
    }, 450);
}

if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", initQmoSidebarOnLoad);
} else {
    initQmoSidebarOnLoad();
}

// Auto-dismiss alerts after 5 seconds system-wide
(function() {
    function autoDismissAlerts() {
        const alerts = document.querySelectorAll('.alert, [class^="alert-"], [class*=" alert-"], .error, .qms-alert, .toast, .dismissible-alert');
        alerts.forEach(el => {
            if (el.dataset.dismissed === 'true') return;
            el.dataset.dismissed = 'true';
            
            setTimeout(() => {
                el.style.transition = 'opacity 0.5s ease-out, max-height 0.5s ease-out, padding 0.5s ease-out, margin 0.5s ease-out';
                el.style.opacity = '0';
                setTimeout(() => {
                    el.style.maxHeight = '0px';
                    el.style.paddingTop = '0px';
                    el.style.paddingBottom = '0px';
                    el.style.marginTop = '0px';
                    el.style.marginBottom = '0px';
                    el.style.overflow = 'hidden';
                    setTimeout(() => el.remove(), 500);
                }, 500);
            }, 5000);
        });
    }
    document.addEventListener('DOMContentLoaded', autoDismissAlerts);
    setInterval(autoDismissAlerts, 1000);
})();
</script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../../assets/js/custom-select.js?v=1.3"></script>
<script src="../../assets/js/mfa-settings.js?v=1.0"></script>
