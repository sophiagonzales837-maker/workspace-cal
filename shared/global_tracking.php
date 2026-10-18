<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])):
    $shift_out_of_hours = false;
    if (($_SESSION['role'] ?? '') !== 'Admin') {
        $shift_user_id = (int)$_SESSION['user_id'];
        $shift_user_stmt = $conn->prepare('SELECT team FROM users WHERE id = ? LIMIT 1');
        if ($shift_user_stmt) {
            $shift_user_stmt->bind_param('i', $shift_user_id);
            $shift_user_stmt->execute();
            $shift_user = $shift_user_stmt->get_result()->fetch_assoc();
            $shift_user_stmt->close();
            $shift_out_of_hours = !$shift_user || !is_on_shift($shift_user['team'] ?? '');
        } else {
            $shift_out_of_hours = true;
        }
    }
?>
    <?php if ($shift_out_of_hours): ?>
        <div role="status" aria-live="polite" style="position: fixed; right: 16px; bottom: 16px; z-index: 999998; padding: 9px 13px; border: 1px solid #fecaca; border-radius: 6px; background: #991b1b; color: #fff; font: 700 12px/1.4 system-ui, sans-serif; box-shadow: 0 4px 14px rgba(127, 29, 29, .25); pointer-events: none;">
            Out of Hours
        </div>
    <?php endif; ?>
    <div id="global-toast-container" aria-live="polite" style="position: fixed; top: 16px; right: 16px; z-index: 999999; display: flex; flex-direction: column; gap: 8px; pointer-events: none;"></div>
    <script src="https://js.pusher.com/8.0/pusher.min.js"></script>
    <script>
        (function() {
            // ==========================================
            // 1. UNIVERSAL TABLE SYNC (Pusher Account B)
            // ==========================================
            window.tablePusher = window.tablePusher || new Pusher('<?php echo htmlspecialchars(defined('WORKSPACE_PUSHER_TABLE_KEY') ? WORKSPACE_PUSHER_TABLE_KEY : WORKSPACE_PUSHER_KEY, ENT_QUOTES, "UTF-8"); ?>', {
                cluster: '<?php echo htmlspecialchars(defined('WORKSPACE_PUSHER_TABLE_CLUSTER') ? WORKSPACE_PUSHER_TABLE_CLUSTER : WORKSPACE_PUSHER_CLUSTER, ENT_QUOTES, "UTF-8"); ?>',
                forceTLS: true
            });

            const globalChannel = window.tablePusher.subscribe('global-updates');

            globalChannel.bind('lead-updated', function(data) {
                // Find any active DataTables on the screen and reload them silently
                if (typeof $ !== 'undefined' && $.fn.dataTable) {
                    var tables = $.fn.dataTable.tables(true);
                    if (tables.length > 0) {
                        $(tables).DataTable().ajax.reload(null, false);
                    }
                }

                // Fallback dashboard refreshers
                if (typeof window.loadAdminLeads === 'function') window.loadAdminLeads();
                if (typeof window.fetchDashboardStats === 'function') window.fetchDashboardStats();
                if (typeof window.loadToPostLeads === 'function') window.loadToPostLeads();
            });

            globalChannel.bind('cache-invalidate', function(payload) {
                if (!payload || !payload.section) {
                    return;
                }

                if (payload.section === 'scripts' || payload.section === 'all') {
                    localStorage.removeItem('floating-script:agent');
                    localStorage.removeItem('floating-script:closer');
                    if (typeof window.invalidateFloatingScriptCache === 'function') {
                        window.invalidateFloatingScriptCache();
                    }
                }

                if (payload.section === 'criteria' || payload.section === 'all') {
                    var clientId = new URLSearchParams(window.location.search).get('client_id') || 'all';
                    localStorage.removeItem('client-criteria:' + clientId);
                    if (typeof window.invalidateClientCriteriaCache === 'function') {
                        window.invalidateClientCriteriaCache();
                    }
                }
            });

            // ==========================================
            // 2. PRESENCE HEARTBEAT
            // ==========================================
            const HEARTBEAT_INTERVAL_MS = 180000;

            const myUserId = <?php echo json_encode($_SESSION['user_id']); ?>;

            function handleUserNotification(data) {
                let payload = data;
                if (typeof data === 'string') {
                    try {
                        payload = JSON.parse(data);
                    } catch (e) {
                        payload = {
                            message: data
                        };
                    }
                }

                const messageBody = payload.message || 'You have a new notification.';
                const title = payload.title || 'CHC CRM Alert';

                // 1. Trigger Native Windows Popup (via Electron)
                if (window.electronAPI) {
                    window.electronAPI.showNativeNotification(title, messageBody);
                }

                // 2. Show an in-app toast for five seconds.
                const toastContainer = document.getElementById('global-toast-container');
                if (toastContainer) {
                    const toast = document.createElement('div');
                    toast.setAttribute('role', 'status');
                    toast.style.cssText = 'min-width:240px;max-width:360px;padding:12px 16px;border-radius:6px;background:#142030;color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.25);font:14px/1.4 system-ui,sans-serif;';
                    const toastTitle = document.createElement('strong');
                    toastTitle.style.display = 'block';
                    toastTitle.style.marginBottom = '4px';
                    toastTitle.textContent = title;
                    const toastMessage = document.createElement('span');
                    toastMessage.textContent = messageBody;
                    toast.append(toastTitle, toastMessage);
                    toastContainer.appendChild(toast);
                    setTimeout(() => toast.remove(), 5000);
                }

                // 3. Update the event's target badge, or the shared fallback badge.
                const fallbackBadgeSelector = '.general-unread-badge';
                const targetBadge = typeof payload.target_badge === 'string' ? payload.target_badge.trim() : '';
                const badgeSelector = targetBadge ?
                    (/^[.#[]/.test(targetBadge) ? targetBadge : '.' + targetBadge) :
                    fallbackBadgeSelector;
                let badges = [];
                try {
                    badges = Array.from(document.querySelectorAll(badgeSelector));
                } catch (e) {}
                if (!badges.length && badgeSelector !== fallbackBadgeSelector) {
                    badges = Array.from(document.querySelectorAll(fallbackBadgeSelector));
                }
                badges.forEach(badge => {
                    const current = parseInt(badge.innerText, 10) || 0;
                    badge.innerText = current + 1;
                    badge.style.display = 'inline-block';
                });
            }

            const userNotificationsChannel = window.tablePusher.subscribe('user-' + myUserId + '-notifications');

            userNotificationsChannel.bind('new-notification', handleUserNotification);

            window.tablePusher.connection.bind('connected', function() {
                // Ensure the user notification channel stays subscribed after reconnects
                window.tablePusher.subscribe('user-' + myUserId + '-notifications');
            });

            function sendHeartbeat() {
                let formData = new FormData();
                fetch('../shared/heartbeat.php', {
                    method: 'POST',
                    body: formData
                }).catch(() => {});
            }
            sendHeartbeat();
            setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);

            setInterval(function() {
                if (!window.tablePusher || window.tablePusher.connection.state !== 'connected') {
                    fetch('../shared/realtime_check.php', {
                            method: 'GET'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.status === 'success') {
                                if (data.notification) {
                                    handleUserNotification(data.notification);
                                }
                            }
                        }).catch(() => {});
                }
            }, HEARTBEAT_INTERVAL_MS);


            setInterval(() => {
                if (window.electronAPI && typeof window.electronAPI.triggerCallbackPopup === 'function') {
                    fetch('../shared/api_check_callbacks.php')
                        .then(r => r.json())
                        .then(lead => {
                            if (lead && lead.id) {
                                window.electronAPI.triggerCallbackPopup(lead);
                            }
                        }).catch(() => {});
                }
            }, 60000); // Checks every 1 minute

        })();
    </script>
<?php endif; ?>