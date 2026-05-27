<?php
if (!isset($_SESSION)) {
    session_start();
}
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Dean' && $_SESSION['role'] !== 'Office')) {
    header("Location: ../../index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar -->
<aside id="main-sidebar" class="w-64 bg-white border-r border-gray-200 flex-shrink-0 flex flex-col z-50 shadow-sm transition-transform duration-300 fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0">
    <div class="p-6 flex items-center justify-between border-b border-gray-50">
        <div class="flex items-center gap-3">
            <img src="../../assets/img/ua-qms-logo.jpg" alt="UA Logo" class="w-14 h-14 rounded-full shadow-sm">
            <span class="font-bold text-2xl text-ua-maroon tracking-wide">UA QMS</span>
        </div>
        <button id="close-sidebar-btn" class="md:hidden p-2 text-gray-500 hover:bg-gray-100 rounded-lg transition-colors">
            <i class="fas fa-times text-lg"></i>
        </button>
    </div>
    <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
        <a href="index.php" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'index.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-chart-line w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i>
            <span class="font-medium ml-1">Dashboard</span>
        </a>
        
        <a href="submit_document.php" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'submit_document.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-upload w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i>
            <span class="font-medium ml-1">Submit Documents</span>
        </a>
        <a href="my_submissions.php" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'my_submissions.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-folder-open w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i>
            <span class="font-medium ml-1">My Submissions</span>
        </a>
        <a href="compliance_status.php" class="flex items-center px-3 py-2.5 <?php echo $current_page == 'compliance_status.php' ? 'bg-ua-maroon text-white shadow-md active:bg-red-900' : 'text-gray-700 hover:bg-red-50 hover:text-ua-maroon active:bg-red-100'; ?> rounded-lg group whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ua-maroon/30">
            <i class="fas fa-check-circle w-6 text-center opacity-90 group-hover:opacity-100 transition-opacity"></i>
            <span class="font-medium ml-1">Compliance Status</span>
        </a>

    </nav>
</aside>

<!-- Profile Dropdown Menu Styles -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

    body, aside, aside span, aside p, aside a, input, select, textarea, button, table, th, td, h1, h2, h3, h4, h5, h6 {
        font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    #main-sidebar {
        background-color: transparent !important;
        background-image: linear-gradient(rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.95)), url('../../assets/img/pat.png?v=4') !important;
        background-size: 100% auto;
        background-repeat: no-repeat;
        background-position: bottom left;
    }
    aside a span {
        font-size: 14px !important;
    }
    aside a i {
        font-size: 16px !important;
    }
    aside a:not(.bg-ua-maroon) i {
        color: var(--ua-maroon) !important;
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
        z-index: 50;
        min-width: 200px;
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
    #inbox-btn:focus, #notification-bell:focus, #profile-btn:focus {
        outline: none;
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
</style>

<!-- Sidebar Backdrop for Mobile -->
<div id="sidebar-backdrop" class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-40 hidden transition-opacity duration-300 opacity-0"></div>

<script>
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
    bar.style.opacity = '1';
    bar.style.width = '0%';
    bar.offsetWidth; // Force layout
    bar.style.width = '70%';
}

function hideProgressBar() {
    const bar = document.getElementById('pjax-progress');
    if (bar) {
        bar.style.width = '100%';
        originalSetTimeout(() => {
            bar.style.opacity = '0';
            originalSetTimeout(() => {
                bar.style.width = '0%';
            }, 400);
        }, 300);
    }
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
    scripts.forEach(script => {
        const src = script.getAttribute('src');
        
        // Skip global scripts that we know are already loaded and running
        if (src && (src.includes('messaging.js') || src.includes('notifications.js') || src.includes('sidebar_qmo.php') || src.includes('sidebar_dean.php'))) {
            return;
        }

        // Skip script if it's inside the sidebar
        if (script.closest('aside')) {
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
        
        document.body.appendChild(newScript);
    });
}

// Update sidebar active link
function updateActiveSidebarLink(url) {
    const filename = url.split('/').pop().split('?')[0];
    const sidebarLinks = document.querySelectorAll('#main-sidebar nav a');
    
    sidebarLinks.forEach(link => {
        const href = link.getAttribute('href');
        const isCurrent = (filename === href);
                          
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

// Intercept popstate
window.addEventListener('popstate', e => {
    if (e.state && e.state.url) {
        loadPage(e.state.url, false);
    } else {
        loadPage(window.location.pathname + window.location.search, false);
    }
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
            // Find new content wrapper and make it initial opacity 0 to prevent blinking
            const newContentDiv = newMain.querySelector('.flex-1.overflow-auto') || newMain.querySelector('.flex-1.overflow-hidden') || newMain.querySelector('.flex-1') || newMain;
            if (newContentDiv) {
                newContentDiv.classList.add('pjax-loading-content');
            }

            currentMain.innerHTML = newMain.innerHTML;
            Array.from(newMain.attributes).forEach(attr => {
                currentMain.setAttribute(attr.name, attr.value);
            });
        }
        
        // Update Title
        document.title = doc.title;
        
        // Execute new scripts
        executePageScripts(doc);
        
        // Update URL pushState
        if (pushState) {
            history.pushState({ url }, '', url);
        }
        
        // Update Sidebar active state
        updateActiveSidebarLink(url);
        
        // Re-run global clock check
        initClock();
        setupMobileMenuButton();
 
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

        // Add smooth fade-in transitions
        const contentDiv = currentMain.querySelector('.flex-1.overflow-auto') || currentMain.querySelector('.flex-1.overflow-hidden') || currentMain.querySelector('.flex-1') || currentMain;
        if (contentDiv) {
            contentDiv.offsetWidth; // Force layout
            contentDiv.classList.remove('pjax-loading-content');
            contentDiv.classList.add('pjax-fade-in');
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
            backdrop.classList.remove('hidden');
            originalSetTimeout(() => {
                backdrop.classList.add('opacity-100');
            }, 10);
            
            sidebar.classList.remove('-translate-x-full');
        });
    }
}

document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const closeBtn = document.getElementById('close-sidebar-btn');
    
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
            if (!confirm('Are you sure you want to log out?')) {
                e.preventDefault();
            }
            return;
        }
        if (href.startsWith('..')) {
            return;
        }
        
        e.preventDefault();
        loadPage(href);
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
        }, 300);
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    initClock();
});

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
