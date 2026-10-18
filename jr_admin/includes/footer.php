<div style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid #E2E8F0; color: #94A3B8; font-size: 13px; padding-bottom: 20px;">
    &copy; <?php echo date("Y"); ?> Consumer Healthcare Compliance Workspace. All rights reserved.
</div>

<!-- PUSHER LIBRARY -->
<script src="https://js.pusher.com/8.0/pusher.min.js"></script>
<?php if (!empty($is_admin_dark_theme)): ?>
    <script src="includes/admin-table-filters.js"></script>
<?php endif; ?>
<script>
    // ==========================================
    // 1. INTERACTION TRACKER (Pauses Auto-Refresh)
    // ==========================================
    let isInteracting = false;
    let interactionTimeout;

    function activeInteraction() {
        isInteracting = true;
        clearTimeout(interactionTimeout);
        interactionTimeout = setTimeout(() => {
            isInteracting = false;
        }, 3000);
    }

    document.addEventListener('input', activeInteraction);
    document.addEventListener('focusin', activeInteraction);
    window.addEventListener('scroll', activeInteraction, true);

    function canRefresh() {
        const hasOpenModals = document.querySelector('.modal-overlay.show') ||
            (document.getElementById('detailsModal') && document.getElementById('detailsModal').style.display === 'flex') ||
            (document.getElementById('inhouseChatModal') && document.getElementById('inhouseChatModal').style.display === 'flex') ||
            document.querySelector('.filter-menu.show') ||
            document.querySelector('.action-dropdown.show');

        const hasSearchTerm = document.getElementById('search-input') && document.getElementById('search-input').value.trim() !== '';
        return !isInteracting && !hasOpenModals && !hasSearchTerm;
    }

    // ==========================================
    // 2. PUSHER REAL-TIME CHAT SETUP
    // ==========================================
    const pusher = new Pusher('41498e992485a94e78b9', {
        cluster: 'ap2'
    });

    const inhouseChannel = pusher.subscribe('inhouse-chat-channel');
    inhouseChannel.bind('new-message', function(data) {
        const currentUserId = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
        if (data.recipient_type !== 'Admin' || Number(data.recipient_id) !== currentUserId) return;
        if (typeof currentChatLeadId !== 'undefined' && Number(currentChatLeadId) === Number(data.lead_id)) {
            if (typeof loadInhouseMessages === 'function') loadInhouseMessages();
        } else {
            let badge = document.getElementById('inhouse-badge-' + data.lead_id);
            if (badge) {
                badge.innerText = parseInt(badge.innerText || 0) + 1;
                badge.style.display = 'block';
            }
        }
    });
</script>

<?php
// Global Tracking
if (file_exists(__DIR__ . '/../../shared/global_tracking.php')) {
    include __DIR__ . '/../../shared/global_tracking.php';
}

// Team Chat Widget Inclusion
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php';
}
?>
<script>
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            const closeBtn = e.target.querySelector('button[onclick^="close"]');
            if (closeBtn) {
                closeBtn.click();
            } else {
                e.target.classList.remove('show');
                e.target.style.display = '';
            }
        }
    });
</script>
</body>

</html>