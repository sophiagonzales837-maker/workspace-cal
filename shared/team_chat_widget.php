<style>
    /* 💥 THE BUBBLE & UNREAD BADGE 💥 */
    #team-chat-bubble {
        position: absolute;
        bottom: 4px;
        right: 4px;
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        z-index: 999999;
        cursor: pointer;
        touch-action: none;
    }

    #unread-badge {
        position: absolute;
        top: -2px;
        left: -2px;
        width: 22px;
        height: 22px;
        background-color: #ef4444;
        color: white;
        border-radius: 50%;
        font-size: 12px;
        font-weight: bold;
        display: none;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        border: 2px solid #0f172a;
        z-index: 1000000;
    }

    /* 💥 THE MAIN CHAT WINDOW 💥 */
    #tc-main-window {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 380px;
        height: 490px;
        background: #f8fafc;
        border-radius: 8px;
        display: none;
        flex-direction: column;
        z-index: 999998;
        border: 1px solid #cbd5e1;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        box-sizing: border-box;
        overflow: hidden;
    }

    .tc-header {
        background: #1e3a8a;
        color: white;
        padding: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: default;
        touch-action: none;
    }

    .tc-tabs {
        display: flex;
        flex: 1;
        pointer-events: auto;
    }

    .tc-tab-btn {
        flex: 1;
        background: transparent;
        color: #93c5fd;
        border: none;
        padding: 12px 10px;
        font-weight: bold;
        font-size: 13px;
        cursor: pointer;
        transition: 0.2s;
        outline: none;
    }

    .tc-tab-btn.active {
        color: white;
        border-bottom: 3px solid #60a5fa;
        background: rgba(255, 255, 255, 0.05);
    }

    #tc-close-btn {
        background: none;
        border: none;
        color: white;
        font-size: 22px;
        cursor: pointer;
        padding: 0 15px;
    }

    /* Views */
    .tc-view {
        display: none;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        background: #f8fafc;
    }

    .tc-view.active-view {
        display: flex;
    }

    .tc-user-list-container {
        flex: 1;
        overflow-y: auto;
        background: white;
    }

    /* WhatsApp Chat Rows */
    .tc-chat-row {
        padding: 12px 15px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: 0.2s;
    }

    .tc-chat-row:hover {
        background: #f8fafc;
    }

    .tc-chat-row .tc-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 14px;
    }

    .tc-chat-row .tc-status {
        font-size: 11px;
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 4px;
    }

    .tc-status-active {
        color: #10b981;
        background: #ecfdf5;
    }

    .tc-status-offline {
        color: #94a3b8;
        background: #f1f5f9;
    }

    .tc-unread-pill {
        background: #ef4444;
        color: white;
        font-size: 11px;
        font-weight: bold;
        padding: 2px 8px;
        border-radius: 12px;
    }

    /* Broadcast & History Rows */
    .tc-broadcast-row {
        padding: 10px 15px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
        display: flex;
        align-items: center;
    }

    .tc-broadcast-row.selected {
        background: #eff6ff;
        border-left: 3px solid #3b82f6;
    }

    .tc-checkbox {
        margin-right: 12px;
        pointer-events: none;
    }

    .tc-hist-msg {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        padding-bottom: 5px;
        margin-bottom: 10px;
    }

    .tc-hist-head {
        background: #f1f5f9;
        padding: 6px 10px;
        font-size: 11px;
        color: #475569;
        display: flex;
        justify-content: space-between;
        border-bottom: 1px solid #e2e8f0;
    }

    /* 💥 1-ON-1 SLIDING CONVERSATION VIEW 💥 */
    #tc-conversation-view {
        display: none;
        flex-direction: column;
        flex: 1;
        background: #f0f2f5;
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 10;
    }

    .tc-conv-header {
        background: #1e3a8a;
        color: white;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        cursor: default;
        touch-action: none;
    }

    .tc-conv-back {
        background: transparent;
        border: none;
        color: white;
        font-size: 16px;
        cursor: pointer;
        margin-right: 15px;
        padding: 0 10px 0 0;
    }

    .tc-conv-messages {
        flex: 1;
        overflow-y: auto;
        padding: 15px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .tc-msg-bubble {
        max-width: 80%;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: 13px;
        line-height: 1.4;
        position: relative;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .tc-msg-them {
        align-self: flex-start;
        background: white;
        color: #1e293b;
        border-bottom-left-radius: 0;
    }

    .tc-msg-me {
        align-self: flex-end;
        background: #dcf8c6;
        color: #1e293b;
        border-bottom-right-radius: 0;
    }

    .tc-msg-time {
        display: block;
        font-size: 10px;
        color: #64748b;
        margin-top: 5px;
        text-align: right;
    }

    .tc-load-more {
        text-align: center;
        font-size: 12px;
        color: #3b82f6;
        cursor: pointer;
        padding: 5px;
        margin-bottom: 10px;
        font-weight: bold;
    }

    /* Text Inputs */
    .tc-input-area {
        background: #f0f2f5;
        padding: 10px;
        border-top: 1px solid #cbd5e1;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .tc-textarea {
        width: 100%;
        height: 60px;
        padding: 10px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13px;
        resize: none;
        outline: none;
        box-sizing: border-box;
    }

    .tc-input-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .tc-file-btn {
        background: white;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        padding: 6px 10px;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .tc-send-btn {
        background: #10b981;
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 4px;
        font-weight: bold;
        cursor: pointer;
    }

    /* Notifications */
    .tc-toast-container {
        position: absolute;
        top: 0;
        right: 0;
        width: 100%;
        z-index: 999999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
        padding: 10px;
        box-sizing: border-box;
    }

    .tc-toast {
        background: white;
        border-left: 4px solid #3b82f6;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        padding: 15px;
        border-radius: 6px;
        width: 100%;
        pointer-events: auto;
        cursor: pointer;
        box-sizing: border-box;
    }

    #tc-main-window,
    .tc-view,
    #tc-conversation-view,
    .tc-user-list-container,
    .tc-conv-messages {
        color: #e2e8f0 !important;
        background: #0b0f14 !important;
        border-color: #334155 !important;
    }

    .tc-header,
    .tc-conv-header {
        background: #1e293b !important;
        border-bottom: 1px solid #334155;
    }

    .tc-chat-row,
    .tc-broadcast-row,
    .tc-hist-msg,
    .tc-hist-head {
        color: #e2e8f0 !important;
        background: #111827 !important;
        border-color: #334155 !important;
    }

    .tc-chat-row:hover,
    .tc-broadcast-row.selected {
        color: #ffffff !important;
        background: #1e293b !important;
    }

    .tc-chat-row .tc-name,
    .tc-hist-head,
    .tc-msg-them,
    .tc-msg-me,
    .tc-toast {
        color: #e2e8f0 !important;
    }

    .tc-msg-them,
    .tc-msg-me,
    .tc-toast {
        background: #1e293b !important;
        border: 1px solid #334155 !important;
    }

    .tc-input-area {
        background: #0b0f14 !important;
        border-color: #334155 !important;
    }

    .tc-textarea,
    #tc-main-window select,
    #tc-main-window input:not([type="file"]):not([type="checkbox"]) {
        color: #f8fafc !important;
        background: #111827 !important;
        border-color: #475569 !important;
    }

    .tc-file-btn {
        color: #e2e8f0 !important;
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    .tc-chat-row:hover *,
    .tc-broadcast-row.selected *,
    .tc-chat-row:hover .tc-name {
        color: #ffffff !important;
    }

    .tc-hist-head {
        background: #1e293b !important;
        border-color: #334155 !important;
    }

    .tc-status-active {
        color: #86efac !important;
        background: #14532d !important;
    }

    .tc-status-offline {
        color: #cbd5e1 !important;
        background: #334155 !important;
    }

    .tc-tab-btn,
    #tc-close-btn,
    .tc-conv-back {
        border-radius: 8px !important;
    }

    @media (max-width: 480px) {
        #tc-main-window {
            width: min(380px, calc(100vw - 24px));
            height: min(490px, calc(100vh - 32px));
        }
    }
</style>

<div id="team-chat-bubble">
    <div id="unread-badge">0</div>
    <i class="fas fa-paper-plane" style="pointer-events:none;"></i>
</div>

<div id="tc-main-window">
    <div class="tc-header" id="tc-header-drag">
        <div class="tc-tabs">
            <button class="tc-tab-btn active" id="tab-btn-chats" onclick="window.switchTcTab('chats')">Chats</button>
            <button class="tc-tab-btn" id="tab-btn-broadcast" onclick="window.switchTcTab('broadcast')">Broadcast</button>
            <button class="tc-tab-btn" id="tab-btn-history" onclick="window.switchTcTab('history')">History</button>
        </div>
        <button id="tc-close-btn">&times;</button>
    </div>

    <div id="tc-view-chats" class="tc-view active-view">
        <div id="tc-chats-list" class="tc-user-list-container"></div>
    </div>

    <div id="tc-view-broadcast" class="tc-view">
        <div id="tc-broadcast-list" class="tc-user-list-container"></div>
        <div class="tc-input-area">
            <textarea id="tc-broadcast-textarea" class="tc-textarea" placeholder="Type a message to the group..."></textarea>
            <div class="tc-input-controls">
                <div>
                    <input type="file" id="tc-broadcast-attachment" style="display:none;" onchange="document.getElementById('tc-broadcast-filename').innerText = this.files[0] ? this.files[0].name.substring(0,10)+'...' : 'Attach';">
                    <button class="tc-file-btn" onclick="document.getElementById('tc-broadcast-attachment').click()"><i class="fas fa-paperclip"></i> <span id="tc-broadcast-filename">Attach</span></button>
                </div>
                <button class="tc-send-btn" onclick="window.triggerSendMessage('broadcast')">Send</button>
            </div>
        </div>
    </div>

    <div id="tc-view-history" class="tc-view">
        <div style="padding:10px; background:#e2e8f0; display:flex; gap:5px;">
            <select id="tc-filter-user" onchange="window.applyHistoryFilters()" style="flex:1; padding:6px; border-radius:4px; border:1px solid #cbd5e1; outline:none;">
                <option value="all">All Users</option>
            </select>
            <input type="text" id="tc-search" placeholder="Search..." oninput="window.applyHistoryFilters()" style="flex:1; padding:6px; border-radius:4px; border:1px solid #cbd5e1; outline:none;">
        </div>
        <div class="tc-user-list-container" style="padding:10px;" id="tc-history-list"></div>
    </div>

    <div id="tc-conversation-view">
        <div class="tc-conv-header" id="tc-conv-header-drag">
            <button class="tc-conv-back" onclick="window.closeConversation()"><i class="fas fa-chevron-left"></i></button>
            <strong id="conv-header-name" style="flex:1; pointer-events:none;">User</strong>
        </div>
        <div id="tc-conv-messages" class="tc-conv-messages"></div>
        <div class="tc-input-area">
            <textarea id="tc-conv-textarea" class="tc-textarea" placeholder="Type your message..."></textarea>
            <div class="tc-input-controls">
                <div>
                    <input type="file" id="tc-conv-attachment" style="display:none;" onchange="document.getElementById('tc-conv-filename').innerText = this.files[0] ? this.files[0].name.substring(0,10)+'...' : 'Attach';">
                    <button class="tc-file-btn" onclick="document.getElementById('tc-conv-attachment').click()"><i class="fas fa-paperclip"></i> <span id="tc-conv-filename">Attach</span></button>
                </div>
                <button class="tc-send-btn" onclick="window.triggerSendMessage('conv')">Send</button>
            </div>
        </div>
    </div>
</div>

<div class="tc-toast-container" id="tc-toasts"></div>

<script src="https://js.pusher.com/8.0/pusher.min.js"></script>
<script>
    const isElectronApp = navigator.userAgent.toLowerCase().includes('electron');
    const isFloatingWindow = window.location.pathname.includes('floating_chat');
    if (isElectronApp && !isFloatingWindow) {
        document.write('<style>#team-chat-bubble, #tc-main-window, #tc-toasts { display: none !important; }</style>');
    }

    // ==========================================
    // GLOBAL STATE & API
    // ==========================================
    window.tcUsersData = [];
    window.tcSelectedUsers = new Set();
    window.tcHistoryData = [];
    window.tcMessageCache = {}; // 💥 NEW: MEMORY CACHE FOR INSTANT LOADING

    window.tcApiPath = isFloatingWindow ? 'shared/team_chat_api.php' : '../shared/team_chat_api.php';
    window.myTcUserId = <?php echo isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; ?>;

    // ⚠️ IMPORTANT: PASTE YOUR REAL PUSHER KEYS HERE! ⚠️
    window.workspacePusher = new Pusher('41498e992485a94e78b9', {
        cluster: 'ap2',
        forceTLS: true
    });

    let unreadCount = 0;
    let tcActiveTab = 'chats';
    let tcCurrentChatId = null;
    let tcCurrentChatName = '';
    let tcChatOffset = 0;

    const bubble = document.getElementById('team-chat-bubble');
    const win = document.getElementById('tc-main-window');
    const badge = document.getElementById('unread-badge');

    // ==========================================
    // ELECTRON DRAG & CLICK CONTROLS
    // ==========================================
    if (window.electronAPI && (typeof window.electronAPI.startNativeDrag === 'function' || typeof window.electronAPI.sendNativeDrag === 'function')) {
        let isDragging = false;
        let startScreenX = 0;
        let startScreenY = 0;
        let grabOffsetX = 34;
        let grabOffsetY = 34;
        let activePointerId = null;

        bubble.addEventListener('pointerdown', (e) => {
            if (e.button !== 0) return;
            isDragging = false;
            startScreenX = e.screenX;
            startScreenY = e.screenY;
            grabOffsetX = e.clientX;
            grabOffsetY = e.clientY;
            activePointerId = e.pointerId;

            bubble.setPointerCapture(e.pointerId);
            bubble.addEventListener('pointermove', onPointerMove);
            bubble.addEventListener('pointerup', onPointerUp);
            bubble.addEventListener('pointercancel', onPointerUp);
        });

        function onPointerMove(e) {
            if (!isDragging) {
                const dist = Math.hypot(e.screenX - startScreenX, e.screenY - startScreenY);
                if (dist > 4) {
                    isDragging = true;
                    if (typeof window.electronAPI.startNativeDrag === 'function') {
                        window.electronAPI.startNativeDrag(grabOffsetX, grabOffsetY);
                    }
                }
            }

            if (isDragging && typeof window.electronAPI.startNativeDrag !== 'function') {
                window.electronAPI.sendNativeDrag(grabOffsetX, grabOffsetY);
            }
        }

        function onPointerUp(e) {
            if (typeof window.electronAPI.stopNativeDrag === 'function') {
                window.electronAPI.stopNativeDrag();
            }
            try {
                if (bubble.hasPointerCapture(e.pointerId)) bubble.releasePointerCapture(e.pointerId);
            } catch (_) {}
            bubble.removeEventListener('pointermove', onPointerMove);
            bubble.removeEventListener('pointerup', onPointerUp);
            bubble.removeEventListener('pointercancel', onPointerUp);

            const wasClick = !isDragging && e.type === 'pointerup' && e.pointerId === activePointerId;
            if (wasClick) {
                unreadCount = 0;
                badge.style.display = 'none';
                bubble.style.display = 'none';
                win.style.display = 'flex';
                document.getElementById('tc-toasts').innerHTML = '';
                window.electronAPI.openChat();
                window.loadUsers();
            }
            isDragging = false;
            activePointerId = null;
        }

        document.getElementById('tc-close-btn').addEventListener('click', () => {
            win.style.display = 'none';
            bubble.style.display = 'flex';
            window.electronAPI.closeChat();
        });

    } else {
        bubble.addEventListener('click', () => {
            unreadCount = 0;
            badge.style.display = 'none';
            bubble.style.display = 'none';
            win.style.display = 'flex';
            document.getElementById('tc-toasts').innerHTML = '';
            window.loadUsers();
        });
        document.getElementById('tc-close-btn').addEventListener('click', () => {
            win.style.display = 'none';
            bubble.style.display = 'flex';
        });
    }

    // ==========================================
    // WHATSAPP UI ENGINE
    // ==========================================
    window.switchTcTab = function(tab) {
        tcActiveTab = tab;
        document.querySelectorAll('.tc-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-btn-' + tab).classList.add('active');
        document.querySelectorAll('.tc-view').forEach(v => v.classList.remove('active-view'));
        document.getElementById('tc-view-' + tab).classList.add('active-view');
        if (tab === 'history') window.loadHistory();
    };

    window.loadUsers = function() {
        fetch(window.tcApiPath + '?action=get_users').then(r => r.json()).then(data => {
            window.tcUsersData = data.users;
            if (data.total_unread > 0 && win.style.display !== 'flex') {
                unreadCount = data.total_unread;
                badge.innerText = unreadCount;
                badge.style.display = 'flex';
            }

            let chatsHtml = '';
            let broadcastHtml = `<div class="tc-broadcast-row" style="background:#e0f2fe;" onclick="window.toggleSelectAll()"><input type="checkbox" id="tc-select-all-cb" class="tc-checkbox"><strong>Select All (Group)</strong></div>`;

            window.tcUsersData.forEach(u => {
                let unreadBadge = u.unread > 0 ? `<div class="tc-unread-pill">${u.unread}</div>` : '';
                chatsHtml += `<div class="tc-chat-row" onclick="window.openConversation(${u.id}, '${u.username}')"><div><div class="tc-name">${u.username}</div><span class="tc-status tc-status-${u.status}">${u.status === 'active' ? 'Online' : 'Offline'}</span></div>${unreadBadge}</div>`;

                let isSel = window.tcSelectedUsers.has(String(u.id));
                let isAct = (u.status === 'active');

                // 💥 FIX: Offline Broadcast users are greyed out, but STILL SELECTABLE!
                let textStyle = isAct ? 'color: #1e293b;' : 'color: #94a3b8; font-style: italic;';
                broadcastHtml += `
                <div class="tc-broadcast-row ${isSel ? 'selected' : ''}" style="${textStyle}" onclick="window.toggleUser('${u.id}')">
                    <input type="checkbox" class="tc-checkbox" ${isSel ? 'checked' : ''}>
                    <span>${u.username} ${isAct ? '' : '(Offline)'}</span>
                </div>`;
            });

            document.getElementById('tc-chats-list').innerHTML = chatsHtml;
            document.getElementById('tc-broadcast-list').innerHTML = broadcastHtml;

            let histHtml = '<option value="all">All Users</option>';
            window.tcUsersData.forEach(u => histHtml += `<option value="${u.id}">${u.username}</option>`);
            document.getElementById('tc-filter-user').innerHTML = histHtml;
        });
    };

    // ==========================================
    // 💥 INSTANT CHAT LOADING (MEMORY CACHE) 💥
    // ==========================================
    window.openConversation = function(id, name) {
        tcCurrentChatId = id;
        tcCurrentChatName = name;
        tcChatOffset = 0;
        document.getElementById('conv-header-name').innerText = name;
        document.getElementById('tc-conversation-view').style.display = 'flex';

        const msgContainer = document.getElementById('tc-conv-messages');

        // Check if we have instant local memory for this user!
        if (window.tcMessageCache[id]) {
            window.renderChatHTML(window.tcMessageCache[id]);
            // Still fetch silently in background to make sure we didn't miss anything!
            window.fetchConversation(true);
        } else {
            msgContainer.innerHTML = '<div style="text-align:center; padding:20px; color:#888;">Loading...</div>';
            window.fetchConversation(false);
        }

        window.loadUsers();
    };

    window.closeConversation = function() {
        tcCurrentChatId = null;
        document.getElementById('tc-conversation-view').style.display = 'none';
    };

    window.fetchConversation = function(isBackgroundRefresh = false) {
        fetch(window.tcApiPath + '?action=get_conversation&other_id=' + tcCurrentChatId + '&offset=' + tcChatOffset).then(r => r.json()).then(data => {
            if (data.status === 'success') {
                // Save to memory cache if it's the newest 5 messages
                if (tcChatOffset === 0) window.tcMessageCache[tcCurrentChatId] = data.messages;
                window.renderChatHTML(data.messages);
            }
        });
    };

    // 💥 FIX: Load More Logic perfectly implemented 💥
    window.renderChatHTML = function(messages) {
        // If the server returned exactly 5 messages, there is a chance there are more older ones!
        let html = (messages.length === 5) ? `<div class="tc-load-more" onclick="window.loadMoreMessages()">Load older messages</div>` : '';

        if (messages.length === 0 && tcChatOffset === 0) {
            html = '<div style="text-align:center; padding:20px; color:#888;">No messages yet. Say hi!</div>';
        } else {
            messages.forEach(m => {
                let attach = m.attachment ? `<a href="${m.attachment}" target="_blank" style="display:block; margin-top:5px; font-size:11px; font-weight:bold;"><i class="fas fa-paperclip"></i> View File</a>` : '';
                html += `<div class="tc-msg-bubble ${m.is_me ? 'tc-msg-me' : 'tc-msg-them'}">${m.message} ${attach} <span class="tc-msg-time">${m.time}</span></div>`;
            });
        }

        const msgContainer = document.getElementById('tc-conv-messages');
        if (tcChatOffset === 0) {
            msgContainer.innerHTML = html;
            msgContainer.scrollTop = msgContainer.scrollHeight;
        } else {
            const oldHeight = msgContainer.scrollHeight;
            msgContainer.querySelector('.tc-load-more')?.remove();
            msgContainer.innerHTML = html + msgContainer.innerHTML;
            msgContainer.scrollTop = msgContainer.scrollHeight - oldHeight;
        }
    };

    window.loadMoreMessages = function() {
        tcChatOffset += 5;
        window.fetchConversation();
    };

    window.triggerSendMessage = function(mode) {
        const textInput = document.getElementById(mode === 'conv' ? 'tc-conv-textarea' : 'tc-broadcast-textarea');
        const fileInput = document.getElementById(mode === 'conv' ? 'tc-conv-attachment' : 'tc-broadcast-attachment');
        const msg = textInput.value.trim();

        let receivers = mode === 'conv' ? [tcCurrentChatId] : Array.from(window.tcSelectedUsers);
        if (receivers.length === 0) return alert('Please select at least one user.');
        if (!msg && fileInput.files.length === 0) return alert('Type a message or attach a file.');

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('receivers', JSON.stringify(receivers));
        formData.append('message', msg);
        if (fileInput.files.length > 0) formData.append('attachment', fileInput.files[0]);

        if (mode === 'conv') {
            const msgContainer = document.getElementById('tc-conv-messages');
            msgContainer.querySelector('.tc-load-more')?.remove();
            msgContainer.innerHTML += `<div class="tc-msg-bubble tc-msg-me" style="opacity: 0.7;">${msg} <span class="tc-msg-time">Sending...</span></div>`;
            msgContainer.scrollTop = msgContainer.scrollHeight;
            textInput.value = '';
        }

        fetch(window.tcApiPath, {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.status === 'success') {
                if (mode === 'conv') {
                    tcChatOffset = 0;
                    window.fetchConversation(false);
                } else {
                    textInput.value = '';
                    fileInput.value = '';
                    document.getElementById('tc-broadcast-filename').innerText = 'Attach';
                    window.tcSelectedUsers.clear();
                    window.loadUsers();
                    alert("Broadcast sent successfully!");
                }
            } else {
                alert('Error: ' + data.message);
            }
        });
    };

    window.toggleUser = function(id) {
        const uid = String(id);
        window.tcSelectedUsers.has(uid) ? window.tcSelectedUsers.delete(uid) : window.tcSelectedUsers.add(uid);
        window.loadUsers();
    };
    window.toggleSelectAll = function() {
        if (window.tcSelectedUsers.size === window.tcUsersData.length && window.tcUsersData.length > 0) {
            window.tcSelectedUsers.clear();
        } else {
            window.tcUsersData.forEach(u => window.tcSelectedUsers.add(String(u.id)));
        }
        window.loadUsers();
    };

    window.loadHistory = function() {
        fetch(window.tcApiPath + '?action=get_history').then(r => r.json()).then(data => {
            if (data.status === 'success') {
                window.tcHistoryData = data.history || [];
                window.applyHistoryFilters();
            }
        });
    };

    window.applyHistoryFilters = function() {
        let uF = document.getElementById('tc-filter-user').value;
        let sF = document.getElementById('tc-search').value.toLowerCase();
        let html = '';
        window.tcHistoryData.filter(m => (uF === 'all' || m.sender_id == uF || m.receiver_id == uF) && ((m.message || '').toLowerCase().includes(sF) || (m.sender_name || '').toLowerCase().includes(sF)))
            .forEach(m => {
                // 💥 FIX: Shows Sender ➔ Receiver in History Tab! 💥
                let headerText = (m.receiver_name === 'Global Group') ? `${m.sender_name} ➔ Group` : `${m.sender_name} ➔ ${m.receiver_name}`;
                html += `<div class="tc-hist-msg" data-sender-id="${m.sender_id}" data-sender-name="${m.sender_name}" data-message="${encodeURIComponent(m.message)}" ondblclick="window.handleReplyClick(this)">
                    <div class="tc-hist-head"><span><strong>${headerText}</strong></span><span>${m.display_time}</span></div>
                    <div class="tc-hist-body" style="padding:10px; font-size:13px;">${m.message}</div>
                 </div>`;
            });
        document.getElementById('tc-history-list').innerHTML = html || '<div style="padding:15px;text-align:center;">No history.</div>';
    };

    window.handleReplyClick = function(el) {
        let senderId = el.getAttribute('data-sender-id');
        let senderName = el.getAttribute('data-sender-name');
        let msgText = decodeURIComponent(el.getAttribute('data-message'));

        document.getElementById('tc-toasts').innerHTML = '';
        document.getElementById('tc-main-window').style.display = 'flex';
        document.getElementById('team-chat-bubble').style.display = 'none';
        if (window.electronAPI && typeof window.electronAPI.openChat === 'function') {
            window.electronAPI.openChat();
        } else if (typeof require !== 'undefined') {
            require('electron').ipcRenderer.send('chat-open');
        }

        window.switchTcTab('chats');
        window.openConversation(senderId, senderName);

        let ta = document.getElementById('tc-conv-textarea');
        ta.value = `\n\n${senderName}>> ${msgText}`;
        ta.focus();
        ta.setSelectionRange(0, 0);
    };

    if (window.myTcUserId > 0) {
        window.workspacePusher.subscribe('user-' + window.myTcUserId + '-channel').bind('new-message-event', function(msg) {
            if (!msg) return;

            if (win.style.display !== 'flex') {
                unreadCount++;
                badge.innerText = unreadCount;
                badge.style.display = 'flex';
                if (window.electronAPI) {
                    window.electronAPI.showChatToast();
                }

                const toast = document.createElement('div');
                toast.className = 'tc-toast';
                toast.innerHTML = `
                <div data-sender-id="${msg.sender_id || ''}" data-sender-name="${msg.username || 'Team'}" data-message="${encodeURIComponent(msg.message || '')}" ondblclick="window.handleReplyClick(this)" style="width:100%; height:100%;">
                    <div style="display:flex; justify-content:space-between;"><strong style="color:#1e3a8a;">${msg.username}</strong><button onclick="this.closest('.tc-toast').remove(); if (document.getElementById('tc-toasts').children.length === 0 && window.electronAPI) { window.electronAPI.hideChatToast(); } event.stopPropagation();">&times;</button></div>
                    <div style="margin-top:5px; font-size:13px;">${msg.message}</div><div style="font-size:9px; color:#94a3b8; margin-top:5px;">Double-click to reply</div>
                </div>`;
                document.getElementById('tc-toasts').appendChild(toast);
                setTimeout(() => {
                    if (toast.parentNode) toast.remove();
                    if (document.getElementById('tc-toasts').children.length === 0 && window.electronAPI) {
                        window.electronAPI.hideChatToast();
                    }
                }, 6000);
            } else if (document.getElementById('tc-conversation-view').style.display === 'flex' && String(tcCurrentChatId) === String(msg.sender_id)) {
                tcChatOffset = 0;
                window.fetchConversation(true);
            } else {
                window.loadUsers();
            }
        });
    }
    document.addEventListener("DOMContentLoaded", () => {
        window.loadUsers();
        if (
            isFloatingWindow &&
            window.electronAPI &&
            typeof window.electronAPI.closeChat === 'function' &&
            window.getComputedStyle(win).display === 'none'
        ) {
            window.electronAPI.closeChat();
        }
    });
</script>