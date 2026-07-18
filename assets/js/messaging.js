/**
 * UA QMS Messaging System
 * Handles conversations, composing, and viewing messages in a Messenger-like interface.
 * Adjusted for full mobile responsiveness (Kasubay Messenger).
 */

const Messaging = {
    unreadCount: 0,
    apiPath: '../../api/messages_api.php',
    currentConversationId: null,

    init: function() {
        this.updateUnreadCount();
        this.createMessagingModal();
        this.bindEvents();
        this.checkAdminPrivileges();
        
        // Check for unread messages and update conversations every 10 seconds
        setInterval(() => {
            this.updateUnreadCount();
            if (document.getElementById('conversations-list')) {
                this.loadConversations();
            }
            if (this.currentConversationId) {
                this.viewConversation(this.currentConversationId, false); // Reload current conversation without marking read again
            }
        }, 10000);
    },

    updateUnreadCount: function() {
        fetch(`${this.apiPath}?action=get_unread_count&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.unreadCount = data.unread_count;
                    const badge = document.getElementById('message-badge');
                    if (badge) {
                        if (this.unreadCount > 0) {
                            badge.textContent = this.unreadCount;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                    }
                }
            });
    },

    createMessagingModal: function() {
        if (document.getElementById('messaging-modal')) return;

        const modalHtml = `
            <div id="messaging-modal" class="fixed inset-0 bg-black bg-opacity-50 z-[9999] hidden flex items-center justify-center p-0 md:p-4 transition-opacity duration-300 opacity-0">
                <div id="messaging-container" class="bg-white rounded-none md:rounded-xl shadow-2xl w-full max-w-4xl h-full md:h-[620px] flex flex-col overflow-hidden transform scale-95 transition-transform duration-300">
                    <!-- Modal Header -->
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-[#800000] text-white flex-shrink-0">
                        <h2 class="text-lg font-bold flex items-center gap-2">
                            <i class="fas fa-comments"></i> Kasubay Messenger
                        </h2>
                        <button onclick="Messaging.toggleModal()" class="text-white hover:text-gray-200 transition p-1">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div class="flex flex-1 overflow-hidden relative">
                        <!-- Sidebar - Conversations List -->
                        <div id="conversations-sidebar" class="w-full md:w-80 border-r border-gray-200 bg-gray-50 flex flex-col flex-shrink-0">
                            <div class="p-4 border-b border-gray-200 space-y-2 flex-shrink-0">
                                <button onclick="Messaging.showCompose()" class="w-full bg-[#800000] hover:bg-red-950 text-white py-2.5 rounded-lg font-bold shadow hover:opacity-90 transition flex items-center justify-center gap-2 text-sm">
                                    <i class="fas fa-plus"></i> New Message
                                </button>
                                <div id="bulk-msg-btn-container" class="hidden">
                                    <button onclick="Messaging.showBulkCompose()" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg font-bold shadow hover:opacity-90 transition flex items-center justify-center gap-2 text-sm">
                                        <i class="fas fa-bullhorn"></i> Bulk Message
                                    </button>
                                </div>
                            </div>
                            <div id="conversations-list" class="flex-1 overflow-y-auto">
                                <!-- Conversations will be loaded here -->
                                <div class="p-4 text-center text-gray-500">Loading conversations...</div>
                            </div>
                        </div>

                        <!-- Content Area - Chat Thread / Compose -->
                        <div id="messaging-content" class="flex-1 flex flex-col bg-white overflow-y-auto hidden md:flex">
                            <div class="flex flex-col items-center justify-center h-full text-gray-400 p-6 text-center">
                                <i class="fas fa-comments text-6xl mb-4"></i>
                                <p class="text-sm">Select a conversation or start a new one</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    showSidebar: function() {
        const sidebar = document.getElementById('conversations-sidebar');
        const content = document.getElementById('messaging-content');
        if (sidebar && content) {
            sidebar.classList.remove('hidden');
            sidebar.classList.add('flex');
            content.classList.add('hidden');
            content.classList.remove('flex');
        }
        this.currentConversationId = null;
    },

    cancelCompose: function() {
        this.showSidebar();
        const content = document.getElementById('messaging-content');
        if (content) {
            content.innerHTML = `
                <div class="flex flex-col items-center justify-center h-full text-gray-400 p-6 text-center">
                    <i class="fas fa-comments text-6xl mb-4"></i>
                    <p class="text-sm">Select a conversation or start a new one</p>
                </div>
            `;
        }
    },

    previewAttachment: function(input) {
        const preview = document.getElementById('attachment-preview');
        if (input.files && input.files[0]) {
            const file = input.files[0];
            preview.innerHTML = `<i class="fas fa-paperclip"></i> <span>${file.name}</span> <button type="button" onclick="Messaging.clearAttachment()" class="text-red-500 hover:text-red-700 ml-2"><i class="fas fa-times"></i></button>`;
            preview.classList.remove('hidden');
        }
    },

    clearAttachment: function() {
        const input = document.querySelector('input[name="attachment"]');
        const preview = document.getElementById('attachment-preview');
        if (input) input.value = '';
        if (preview) {
            preview.innerHTML = '';
            preview.classList.add('hidden');
        }
    },

    showContentArea: function() {
        if (window.innerWidth < 768) {
            const sidebar = document.getElementById('conversations-sidebar');
            const content = document.getElementById('messaging-content');
            if (sidebar && content) {
                sidebar.classList.remove('flex');
                sidebar.classList.add('hidden');
                content.classList.remove('hidden');
                content.classList.add('flex');
            }
        }
    },

    toggleModal: function() {
        const modal = document.getElementById('messaging-modal');
        const container = document.getElementById('messaging-container');
        if (modal) {
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
                // Trigger reflow
                void modal.offsetWidth;
                modal.classList.remove('opacity-0');
                modal.classList.add('opacity-100');
                if (container) {
                    container.classList.remove('scale-95');
                    container.classList.add('scale-100');
                }
                this.showSidebar();
                this.loadConversations();
            } else {
                modal.classList.remove('opacity-100');
                modal.classList.add('opacity-0');
                if (container) {
                    container.classList.remove('scale-100');
                    container.classList.add('scale-95');
                }
                setTimeout(() => {
                    modal.classList.add('hidden');
                }, 300);
                this.currentConversationId = null;
            }
        }
    },

    bindEvents: function() {
        document.addEventListener('click', (e) => {
            const inboxBtn = e.target.closest('#inbox-btn');
            if (inboxBtn) {
                e.preventDefault();
                this.toggleModal();
            }
        });
    },

    loadConversations: function() {
        const conversationsList = document.getElementById('conversations-list');
        if (!conversationsList) return;

        fetch(`${this.apiPath}?action=get_conversations&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.conversations.length === 0) {
                        conversationsList.innerHTML = '<div class="p-4 text-center text-gray-500 text-sm">No conversations yet.</div>';
                        return;
                    }

                    let html = '';
                    data.conversations.forEach(conv => {
                        const isCurrent = this.currentConversationId == conv.conversation_id;
                        const itemClass = isCurrent 
                            ? 'bg-red-50/50 text-[#800000] border-l-4 border-[#800000] font-semibold' 
                            : (conv.unread_count > 0 
                                ? 'bg-amber-50/40 border-l-4 border-amber-500 font-bold' 
                                : 'hover:bg-gray-100/80 border-l-4 border-transparent');
                        html += `
                            <div onclick="Messaging.viewConversation(${conv.conversation_id}, true)" class="mx-2 my-1.5 p-3.5 rounded-xl cursor-pointer transition-all duration-200 flex flex-col gap-1 border border-gray-100 shadow-sm ${itemClass}">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-900 text-xs font-bold truncate max-w-[150px]">${conv.last_message_sender_name}</span>
                                    <span class="text-[9px] text-gray-400 font-medium">${new Date(conv.last_message_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                                </div>
                                <p class="text-xs text-gray-500 truncate leading-relaxed">${conv.last_message_text}</p>
                                ${conv.unread_count > 0 ? `<div class="flex justify-end"><span class="text-[8px] text-white bg-amber-600 px-1.5 py-0.5 rounded-full font-bold uppercase tracking-wider">${conv.unread_count} New</span></div>` : ''}
                            </div>
                        `;
                    });
                    conversationsList.innerHTML = html;
                }
            });
    },

    viewConversation: function(conversationId, markAsRead = true) {
        this.currentConversationId = conversationId;
        this.showContentArea();
        
        const content = document.getElementById('messaging-content');
        if (!content) return;
        
        const chatThread = document.getElementById('chat-thread');
        const isAlreadyOpen = chatThread && document.getElementById('chat-form') && document.querySelector('input[name="conversation_id"]')?.value == conversationId;
        
        if (!isAlreadyOpen && markAsRead) {
            content.innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-ua-maroon"></i></div>';
        }

        fetch(`${this.apiPath}?action=get_messages&conversation_id=${conversationId}&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let messagesHtml = '';
                    let lastMessageId = 0;
                    const currentUserId = this.getUserId();
                    
                    data.messages.forEach(msg => {
                        const isSender = msg.sender_id == currentUserId;
                        const messageClass = isSender ? 'bg-[#800000] text-white self-end rounded-br-none' : 'bg-gray-100 text-gray-800 self-start rounded-bl-none';
                        const senderName = isSender ? 'You' : msg.sender_name;
                        let attachmentHtml = '';
                        if (msg.attachment_path) {
                            const isImage = msg.attachment_path.match(/\.(jpeg|jpg|gif|png|webp)$/i) != null;
                            if (isImage) {
                                attachmentHtml = `<div class="mt-2 mb-1"><a href="../../${msg.attachment_path}" target="_blank" class="block overflow-hidden rounded-lg hover:opacity-95 transition-opacity"><img src="../../${msg.attachment_path}" class="max-w-full rounded-lg shadow-sm border border-black/10 hover:scale-[1.02] transition-transform duration-200" style="max-height: 200px;" alt="Attachment" /></a></div>`;
                            } else {
                                const fileName = msg.attachment_path.split('/').pop();
                                attachmentHtml = `<div class="mt-2 mb-1"><a href="../../${msg.attachment_path}" target="_blank" class="flex items-center gap-2 bg-black/10 hover:bg-black/20 text-current p-2 rounded-lg text-xs transition"><i class="fas fa-file-alt"></i> ${fileName}</a></div>`;
                            }
                        }
                        messagesHtml += `
                            <div class="flex ${isSender ? 'justify-end' : 'justify-start'} mb-3">
                                <div class="max-w-[75%] md:max-w-md px-4 py-2.5 rounded-xl shadow-sm ${messageClass}">
                                    <p class="font-bold text-xs mb-0.5 opacity-90">${senderName}</p>
                                    <p class="text-sm leading-relaxed">${msg.message_text}</p>
                                    ${attachmentHtml}
                                    <p class="text-[9px] text-right opacity-70 mt-1">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                </div>
                            </div>
                        `;
                        lastMessageId = msg.message_id;
                    });

                    const partnerName = data.partner_name || 'Conversation';

                    if (isAlreadyOpen) {
                        const wasAtBottom = (chatThread.scrollHeight - chatThread.clientHeight - chatThread.scrollTop) < 40;
                        chatThread.innerHTML = messagesHtml;
                        if (wasAtBottom) {
                            chatThread.scrollTop = chatThread.scrollHeight;
                        }
                    } else {
                        content.innerHTML = `
                            <div class="flex flex-col h-full overflow-hidden">
                                <!-- Header / Back button -->
                                <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-3 flex-shrink-0">
                                    <button onclick="Messaging.showSidebar()" class="md:hidden p-1.5 text-[#800000] hover:bg-gray-200 rounded-lg transition-colors flex items-center gap-1 text-sm font-bold">
                                        <i class="fas fa-chevron-left"></i> Back
                                    </button>
                                    <div class="min-w-0">
                                        <span class="font-bold text-sm text-gray-800 block truncate">${partnerName}</span>
                                        <span class="text-[10px] text-green-600 font-semibold flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Connected</span>
                                    </div>
                                </div>
                                
                                <div class="flex-1 overflow-y-auto p-4 md:p-6 space-y-3 bg-gray-50/30" id="chat-thread">
                                    ${messagesHtml}
                                </div>
                                
                                <div class="p-3 border-t border-gray-200 bg-white flex-shrink-0">
                                    <div id="attachment-preview" class="hidden mb-2 p-2 bg-gray-50 rounded-lg border border-gray-200 text-xs flex items-center justify-between text-gray-600"></div>
                                    <form id="chat-form" class="flex gap-2 items-end">
                                        <input type="hidden" name="conversation_id" value="${conversationId}">
                                        <div class="relative group h-9 flex items-center">
                                            <label class="cursor-pointer text-gray-500 hover:text-[#800000] p-2 transition-colors">
                                                <i class="fas fa-paperclip text-lg"></i>
                                                <input type="file" name="attachment" class="hidden" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" onchange="Messaging.previewAttachment(this)">
                                            </label>
                                        </div>
                                        <textarea name="message_text" rows="1" class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none resize-none max-h-24" placeholder="Type your message..." oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea>
                                        <button type="submit" class="px-4 py-2 bg-[#800000] hover:bg-red-950 text-white rounded-lg font-bold text-sm shadow flex items-center gap-1.5 h-9 flex-shrink-0 transition-colors">
                                            <i class="fas fa-paper-plane text-xs"></i> Send
                                        </button>
                                    </form>
                                </div>
                            </div>
                        `;
                        const newChatThread = document.getElementById('chat-thread');
                        if (newChatThread) {
                            newChatThread.scrollTop = newChatThread.scrollHeight;
                        }
                    }

                    if (markAsRead && lastMessageId > 0) {
                        this.markAsRead(conversationId, lastMessageId);
                    }

                    const chatForm = document.getElementById('chat-form');
                    if (chatForm && !isAlreadyOpen) {
                        chatForm.onsubmit = (e) => {
                            e.preventDefault();
                            const formData = new FormData(chatForm);
                            formData.append('action', 'send_message');
                            this.sendMessage(formData);
                            chatForm.reset();
                            this.clearAttachment();
                        };
                    }
                }
            });
    },

    showCompose: function(replyToUserId = null) {
        this.currentConversationId = null;
        this.showContentArea();

        const content = document.getElementById('messaging-content');
        if (!content) return;
        
        content.innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-ua-maroon"></i></div>';

        fetch(`${this.apiPath}?action=get_recipients&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let options = data.recipients.map(r => 
                        `<option value="${r.user_id}" ${replyToUserId == r.user_id ? 'selected' : ''}>${r.full_name} (${r.role})</option>`
                    ).join('');

                    content.innerHTML = `
                        <div class="flex flex-col h-full overflow-hidden">
                            <!-- Mobile Header -->
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-3 flex-shrink-0">
                                <button onclick="Messaging.showSidebar()" class="md:hidden p-1.5 text-[#800000] hover:bg-gray-200 rounded-lg transition-colors flex items-center gap-1 text-sm font-bold">
                                    <i class="fas fa-chevron-left"></i> Back
                                </button>
                                <span class="font-bold text-sm text-gray-800">New Message</span>
                            </div>
                            
                            <form id="compose-form" class="p-6 flex-1 overflow-y-auto space-y-4">
                                <h3 class="text-base font-bold text-gray-800 hidden md:block border-b border-gray-100 pb-2">New Direct Message</h3>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Recipient</label>
                                    <select name="receiver_id" class="w-full border border-gray-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none" required>
                                        <option value="">Select Recipient...</option>
                                        ${options}
                                    </select>
                                </div>
                                <div class="flex-grow flex flex-col">
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message</label>
                                    <textarea name="message_text" rows="6" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none resize-none" required placeholder="Type your message here..."></textarea>
                                </div>
                                <div class="flex justify-end gap-3 pt-2">
                                    <button type="button" onclick="Messaging.cancelCompose()" class="px-5 py-2 border border-gray-300 rounded-lg font-semibold hover:bg-gray-50 text-sm">Cancel</button>
                                    <button type="submit" class="px-5 py-2 bg-[#800000] hover:bg-red-950 text-white rounded-lg font-bold text-sm shadow">Send Message</button>
                                </div>
                            </form>
                        </div>
                    `;

                    document.getElementById('compose-form').onsubmit = (e) => {
                        e.preventDefault();
                        const formData = new FormData(e.target);
                        formData.append('action', 'send_message');
                        this.sendMessage(formData, true);
                        e.target.reset();
                    };
                }
            });
    },

    sendMessage: function(formData, isNewConversation = false) {
        fetch(this.apiPath, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (isNewConversation) {
                    this.loadConversations();
                    this.viewConversation(data.conversation_id, true); // Open the new conversation
                } else {
                    this.viewConversation(formData.get('conversation_id'), false); // Reload current conversation
                }
                this.updateUnreadCount();
            } else {
                alert('Error: ' + data.message);
            }
        });
    },

    markAsRead: function(conversationId, lastMessageId) {
        const formData = new FormData();
        formData.append('action', 'mark_as_read');
        formData.append('conversation_id', conversationId);
        formData.append('last_message_id', lastMessageId);

        fetch(this.apiPath, {
            method: 'POST',
            body: formData
        }).then(() => this.updateUnreadCount());
    },

    checkAdminPrivileges: function() {
        // Check if current user is QMO Admin to show Bulk Message button
        if (window.CURRENT_USER_ROLE === 'QMO Admin') {
            const btn = document.getElementById('bulk-msg-btn-container');
            if (btn) btn.classList.remove('hidden');
        }
    },

    showBulkCompose: function() {
        this.currentConversationId = null;
        this.showContentArea();

        const content = document.getElementById('messaging-content');
        if (!content) return;
        
        content.innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-ua-maroon"></i></div>';

        fetch(`${this.apiPath}?action=get_recipients&_t=` + Date.now(), { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let checkboxes = data.recipients.map(r => `
                        <label class="flex items-center gap-2.5 p-2.5 hover:bg-gray-50 rounded-lg cursor-pointer border border-gray-100 bg-white shadow-sm">
                            <input type="checkbox" name="receiver_ids[]" value="${r.user_id}" class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500">
                            <span class="text-xs font-semibold text-gray-700 truncate">${r.full_name} <span class="text-[10px] text-gray-400 font-normal">(${r.role})</span></span>
                        </label>
                    `).join('');

                    content.innerHTML = `
                        <div class="flex flex-col h-full overflow-hidden">
                            <!-- Mobile Header -->
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-3 flex-shrink-0">
                                <button onclick="Messaging.showSidebar()" class="md:hidden p-1.5 text-blue-600 hover:bg-gray-200 rounded-lg transition-colors flex items-center gap-1 text-sm font-bold">
                                    <i class="fas fa-chevron-left"></i> Back
                                </button>
                                <span class="font-bold text-sm text-gray-800">Broadcast</span>
                            </div>
                            
                            <form id="bulk-compose-form" class="flex-grow flex flex-col overflow-hidden">
                                <div class="p-6 flex-1 overflow-y-auto space-y-4">
                                    <div class="hidden md:block border-b border-gray-100 pb-2">
                                        <h3 class="text-base font-bold text-gray-800">Broadcast / Bulk Message</h3>
                                        <p class="text-xs text-gray-500 mt-0.5">Send a single message to multiple offices simultaneously.</p>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Select Recipients</label>
                                            <div class="space-x-3 text-xs font-bold">
                                                <button type="button" onclick="Messaging.selectAllRecipients(true)" class="text-blue-600 hover:underline">Select All</button>
                                                <button type="button" onclick="Messaging.selectAllRecipients(false)" class="text-gray-400 hover:underline">Clear All</button>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 border border-gray-200 rounded-xl p-3 bg-gray-50/50 max-h-44 overflow-y-auto">
                                            ${checkboxes}
                                        </div>
                                    </div>
                                    <div class="flex-grow flex flex-col">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message Content</label>
                                        <textarea name="message_text" class="flex-grow w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none resize-none min-h-[120px]" required placeholder="Type your broadcast message here..."></textarea>
                                    </div>
                                </div>
                                <div class="p-4 border-t border-gray-200 bg-gray-50 flex justify-end gap-3 flex-shrink-0">
                                    <button type="button" onclick="Messaging.loadConversations()" class="px-5 py-2 border border-gray-300 rounded-lg font-semibold hover:bg-gray-100 text-sm">Cancel</button>
                                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm shadow-md">
                                        <i class="fas fa-paper-plane mr-1.5 text-xs"></i> Send Broadcast
                                    </button>
                                </div>
                            </form>
                        </div>
                    `;

                    document.getElementById('bulk-compose-form').onsubmit = (e) => {
                        e.preventDefault();
                        const formData = new FormData(e.target);
                        formData.append('action', 'send_bulk_message');
                        
                        // Check if at least one recipient is selected
                        const selected = e.target.querySelectorAll('input[name="receiver_ids[]"]:checked');
                        if (selected.length === 0) {
                            alert('Please select at least one recipient.');
                            return;
                        }

                        this.sendBulkMessage(formData);
                    };
                }
            });
    },

    selectAllRecipients: function(select) {
        const checkboxes = document.querySelectorAll('input[name="receiver_ids[]"]');
        checkboxes.forEach(cb => cb.checked = select);
    },

    sendBulkMessage: function(formData) {
        const submitBtn = document.querySelector('#bulk-compose-form button[type="submit"]');
        const originalContent = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Sending...';

        fetch(this.apiPath, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadConversations();
                const content = document.getElementById('messaging-content');
                content.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-full text-green-600 p-6 text-center">
                        <i class="fas fa-check-circle text-6xl mb-4 text-green-500"></i>
                        <p class="text-base font-bold">Broadcast Sent Successfully!</p>
                        <button onclick="Messaging.loadConversations()" class="mt-4 text-blue-600 hover:underline text-sm font-semibold">Back to Conversations</button>
                    </div>
                `;
                this.updateUnreadCount();
            } else {
                alert('Error: ' + data.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalContent;
            }
        })
        .catch(err => {
            alert('An error occurred. Please try again.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalContent;
        });
    },

    getUserId: function() {
        return window.CURRENT_USER_ID; 
    }
};

// Auto-initialize when script loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        Messaging.init();
    });
} else {
    Messaging.init();
}
