/**
 * UA QMS Notification System
 * Handles notification bell, polling, dropdown, and click-through navigation.
 */

const Notifications = {
    unreadCount: 0,
    apiPath: '../../api/notifications_api.php',
    dropdownOpen: false,
    lastViewedCount: parseInt(localStorage.getItem('notifications_last_viewed_count') || '0'),
    allNotifications: [],

    init: function() {
        this.updateUnreadCount();
        this.createDropdown();
        this.createActivityModal();
        this.bindEvents();
        
        // Check for new notifications every 5 seconds
        setInterval(() => this.updateUnreadCount(), 5000);
    },

    updateUnreadCount: function() {
        fetch(`${this.apiPath}?action=get_unread_count&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.unreadCount = data.unread_count;
                    const badge = document.getElementById('notification-badge');
                    if (badge) {
                        // Badge reset logic: only show count if it's more than what we last "viewed"
                        // or if the dropdown is currently closed and we have unread items.
                        const displayCount = Math.max(0, this.unreadCount - this.lastViewedCount);
                        
                        if (displayCount > 0 && !this.dropdownOpen) {
                            badge.textContent = displayCount;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                    }
                }
            })
            .catch(err => console.error('Error fetching unread count:', err));
    },

    createDropdown: function() {
        if (document.getElementById('notification-dropdown')) return;

        const dropdownHtml = `
            <div id="notification-dropdown" class="fixed top-16 md:top-20 right-20 w-80 bg-white rounded-xl shadow-2xl z-50 hidden border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                    <h3 class="font-bold text-gray-800">Notifications</h3>
                    <button onclick="Notifications.markAllAsRead()" class="text-xs text-ua-maroon hover:underline">Mark all as read</button>
                </div>
                <div id="notification-list" class="max-h-96 overflow-y-auto">
                    <!-- Notifications will be injected here -->
                    <div class="p-4 text-center text-gray-400">
                        <i class="fas fa-bell-slash text-2xl mb-2 block"></i>
                        <p class="text-sm">No notifications</p>
                    </div>
                </div>
                <div class="p-2 border-t border-gray-100 text-center">
                    <button onclick="Notifications.navigateToActivityPage()" class="text-xs text-gray-500 hover:text-ua-maroon transition">View all activity</button>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', dropdownHtml);
    },

    bindEvents: function() {
        document.addEventListener('click', (e) => {
            const bellBtn = e.target.closest('#notification-bell');
            if (bellBtn) {
                e.preventDefault();
                e.stopPropagation();
                this.toggleDropdown();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('notification-dropdown');
            const bellBtn = document.getElementById('notification-bell');
            if (dropdown && !dropdown.contains(e.target) && (!bellBtn || !bellBtn.contains(e.target))) {
                if (this.dropdownOpen) {
                    dropdown.classList.add('hidden');
                    this.dropdownOpen = false;
                }
            }
        });
    },

    toggleDropdown: function() {
        const dropdown = document.getElementById('notification-dropdown');
        if (this.dropdownOpen) {
            dropdown.classList.add('hidden');
            this.dropdownOpen = false;
        } else {
            dropdown.classList.remove('hidden');
            this.dropdownOpen = true;
            
            // Badge reset: when viewing, set lastViewedCount to current unreadCount
            this.lastViewedCount = this.unreadCount;
            localStorage.setItem('notifications_last_viewed_count', this.lastViewedCount);
            
            // Hide badge immediately
            const badge = document.getElementById('notification-badge');
            if (badge) badge.classList.add('hidden');
            
            this.loadNotifications();
        }
    },

    loadNotifications: function() {
        const list = document.getElementById('notification-list');
        list.innerHTML = '<div class="flex justify-center py-6"><i class="fas fa-spinner fa-spin text-xl text-ua-maroon"></i></div>';

        fetch(`${this.apiPath}?action=get_notifications&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Auto-clear: only show unread notifications
                    const unreadNotifications = data.notifications.filter(n => n.is_read == 0);
                    
                    if (unreadNotifications.length === 0) {
                        list.innerHTML = `
                            <div class="p-8 text-center text-gray-400">
                                <i class="fas fa-bell-slash text-3xl mb-2 block"></i>
                                <p class="text-sm">No new notifications</p>
                            </div>
                        `;
                        return;
                    }

                    let html = '';
                    unreadNotifications.forEach(notif => {
                        const iconClass = this.getIconForType(notif.type);
                        const targetUrl = notif.target_url || '';
                        
                        html += `
                            <div id="notif-item-${notif.user_notification_id}" class="p-4 border-b border-gray-50 hover:bg-gray-50 transition cursor-pointer bg-red-50" onclick="Notifications.handleNotificationClick(${notif.user_notification_id}, '${this.escapeQuotes(targetUrl)}')">
                                <div class="flex gap-3">
                                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-white flex items-center justify-center text-ua-maroon shadow-sm">
                                        <i class="${iconClass}"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 truncate">${notif.title}</p>
                                        <p class="text-xs text-gray-600 line-clamp-2">${notif.message}</p>
                                        <p class="text-[10px] text-gray-400 mt-1">${this.formatTime(notif.notification_time)}</p>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    list.innerHTML = html;
                }
            })
            .catch(err => {
                console.error('Error loading notifications:', err);
                list.innerHTML = '<div class="p-4 text-center text-red-500 text-sm">Failed to load notifications</div>';
            });
    },

    escapeQuotes: function(str) {
        return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
    },

    getIconForType: function(type) {
        switch (type) {
            case 'Deadline': return 'fas fa-clock';
            case 'Announcement': return 'fas fa-bullhorn';
            case 'System': return 'fas fa-cog';
            default: return 'fas fa-bell';
        }
    },

    formatTime: function(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleDateString();
    },

    /**
     * Handle notification click: mark as read and navigate to target URL
     */
    handleNotificationClick: function(id, targetUrl) {
        // Mark as read
        this.markAsRead(id);
        
        // Navigate to target URL after a brief delay
        if (targetUrl && targetUrl.trim()) {
            setTimeout(() => {
                const absoluteUrl = this.resolveUrl(targetUrl);
                window.location.href = absoluteUrl;
            }, 300);
        }
    },

    /**
     * Resolve relative URLs to absolute paths
     */
    resolveUrl: function(relativeUrl) {
        if (relativeUrl.startsWith('http')) {
            return relativeUrl;
        }
        
        const pathname = window.location.pathname;
        const dashboardIndex = pathname.indexOf('/dashboard/');
        let appBase = '';
        if (dashboardIndex !== -1) {
            appBase = pathname.substring(0, dashboardIndex);
        } else {
            const apiIndex = pathname.indexOf('/api/');
            if (apiIndex !== -1) {
                appBase = pathname.substring(0, apiIndex);
            } else {
                const parts = pathname.split('/');
                appBase = parts.slice(0, parts.length - 1).join('/');
            }
        }
        
        const cleanRelativeUrl = relativeUrl.startsWith('/') ? relativeUrl.substring(1) : relativeUrl;
        return appBase + '/' + cleanRelativeUrl;
    },

    markAsRead: function(id) {
        const formData = new FormData();
        formData.append('user_notification_id', id);
        formData.append('action', 'mark_as_read');

        // Optimistic UI: remove immediately
        const item = document.getElementById(`notif-item-${id}`);
        if (item) {
            item.style.opacity = '0.5';
            item.style.pointerEvents = 'none';
        }

        fetch(this.apiPath, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Update counts
                this.unreadCount--;
                this.lastViewedCount = Math.max(0, this.lastViewedCount - 1);
                localStorage.setItem('notifications_last_viewed_count', this.lastViewedCount);
                
                // Refresh list to show "No notifications" if empty
                this.loadNotifications();
                this.updateUnreadCount();
            }
        });
    },

    markAllAsRead: function() {
        const formData = new FormData();
        formData.append('action', 'mark_all_as_read');

        fetch(this.apiPath, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.unreadCount = 0;
                this.lastViewedCount = 0;
                localStorage.setItem('notifications_last_viewed_count', '0');
                this.updateUnreadCount();
                this.loadNotifications();
            }
        });
    },

    /**
     * Navigate to the appropriate activity page based on user role
     */
    navigateToActivityPage: function() {
        this.openActivityModal();
    },

    createActivityModal: function() {
        if (document.getElementById('notification-activity-modal')) return;

        const modalHtml = `
            <div id="notification-activity-modal" class="fixed inset-0 bg-black bg-opacity-65 hidden items-center justify-center z-50 p-4 md:p-6 backdrop-blur-sm">
                <style>
                    @keyframes slideUp {
                        from { transform: translateY(30px); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                </style>
                <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full h-[75vh] flex flex-col overflow-hidden" style="animation: slideUp 0.3s ease-out;">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between flex-shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center text-[#800000] text-lg">
                                <i class="fas fa-history"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900 text-base">System Activity Log</h3>
                                <p class="text-xs text-gray-500">View historical notifications and system actions</p>
                            </div>
                        </div>
                        <button onclick="Notifications.closeActivityModal()" class="text-gray-400 hover:text-gray-600 focus:outline-none p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    <!-- Filter Bar -->
                    <div class="px-6 py-3 bg-white border-b border-gray-100 flex flex-col sm:flex-row gap-3 items-center justify-between flex-shrink-0">
                        <div class="relative w-full sm:w-64">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                            <input type="text" id="activity-search" oninput="Notifications.filterActivity()" placeholder="Search activities..." class="w-full pl-9 pr-3 py-1.5 border border-gray-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-[#800000] focus:border-transparent">
                        </div>
                        <div class="flex gap-2 w-full sm:w-auto justify-end">
                            <select id="activity-type-filter" onchange="Notifications.filterActivity()" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-[#800000] focus:border-transparent bg-white">
                                <option value="">All Types</option>
                                <option value="System">System</option>
                                <option value="Deadline">Deadline</option>
                                <option value="Announcement">Announcement</option>
                            </select>
                        </div>
                    </div>
                    <!-- Body (Scrollable List) -->
                    <div id="activity-modal-list" class="flex-1 overflow-y-auto p-6 space-y-3 bg-gray-50/50">
                        <div class="text-center text-gray-400 py-10"><i class="fas fa-spinner fa-spin text-xl text-ua-maroon mb-2"></i><p class="text-xs">Loading activities...</p></div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    openActivityModal: function() {
        const dropdown = document.getElementById('notification-dropdown');
        if (dropdown && !dropdown.classList.contains('hidden')) {
            dropdown.classList.add('hidden');
            this.dropdownOpen = false;
        }

        const modal = document.getElementById('notification-activity-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            const listContainer = document.getElementById('activity-modal-list');
            listContainer.innerHTML = '<div class="text-center text-gray-400 py-10"><i class="fas fa-spinner fa-spin text-xl text-ua-maroon mb-2"></i><p class="text-xs">Loading activities...</p></div>';
            
            fetch(`${this.apiPath}?action=get_notifications&_t=` + Date.now(), { cache: 'no-store' })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.allNotifications = data.notifications;
                        this.filterActivity();
                    } else {
                        listContainer.innerHTML = '<div class="text-center text-red-500 py-10 text-xs">Failed to load activity list.</div>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    listContainer.innerHTML = '<div class="text-center text-red-500 py-10 text-xs">Failed to load activity list.</div>';
                });
        }
    },

    closeActivityModal: function() {
        const modal = document.getElementById('notification-activity-modal');
        if (modal) {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
    },

    filterActivity: function() {
        const searchInput = document.getElementById('activity-search');
        const typeFilter = document.getElementById('activity-type-filter');
        const listContainer = document.getElementById('activity-modal-list');
        
        if (!listContainer || !this.allNotifications) return;
        
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const selectedType = typeFilter ? typeFilter.value : '';
        
        const filtered = this.allNotifications.filter(notif => {
            const matchesSearch = !query || 
                (notif.title && notif.title.toLowerCase().includes(query)) || 
                (notif.message && notif.message.toLowerCase().includes(query));
            const matchesType = !selectedType || notif.type === selectedType;
            return matchesSearch && matchesType;
        });
        
        if (filtered.length === 0) {
            listContainer.innerHTML = `
                <div class="p-8 text-center text-gray-400 bg-white border border-gray-150 rounded-xl shadow-sm">
                    <i class="fas fa-search text-3xl mb-2 block opacity-40"></i>
                    <p class="text-xs font-semibold">No activity logs match your search.</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        filtered.forEach(notif => {
            const iconClass = this.getIconForType(notif.type);
            const targetUrl = notif.target_url || '';
            const isUnread = notif.is_read == 0;
            const unreadBgClass = isUnread ? 'bg-red-50/40 border-l-4 border-l-[#800000]' : 'bg-white';
            
            html += `
                <div class="p-4 ${unreadBgClass} border border-gray-200 hover:border-gray-300 rounded-xl transition cursor-pointer shadow-sm relative group flex items-start gap-4" onclick="Notifications.handleNotificationClick(${notif.user_notification_id}, '${this.escapeQuotes(targetUrl)}')">
                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gray-50 border border-gray-150 flex items-center justify-center text-[#800000] shadow-sm">
                        <i class="${iconClass} text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-gray-900 truncate flex items-center gap-1.5">
                                ${notif.title}
                                ${isUnread ? '<span class="w-2 h-2 rounded-full bg-[#800000] inline-block animate-ping"></span>' : ''}
                            </p>
                            <span class="text-[10px] text-gray-400 font-semibold flex-shrink-0">${this.formatTime(notif.notification_time)}</span>
                        </div>
                        <p class="text-xs text-gray-600 mt-1 leading-relaxed">${notif.message}</p>
                    </div>
                    <div class="absolute right-3 bottom-3 opacity-0 group-hover:opacity-100 transition-opacity text-[#800000] text-xs font-bold flex items-center gap-0.5">
                        View <i class="fas fa-chevron-right text-[10px]"></i>
                    </div>
                </div>
            `;
        });
        
        listContainer.innerHTML = html;
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        Notifications.init();
    });
} else {
    Notifications.init();
}
