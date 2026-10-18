<div style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid #E0E0E0; color: #888; font-size: 13px;">
    &copy; <?php echo date("Y"); ?> Consumer Healthcare Compliance Workspace. All rights reserved.
</div>
</div>

<?php
// Global Tracking
if (file_exists(__DIR__ . '/../../shared/global_tracking.php')) {
    include __DIR__ . '/../../shared/global_tracking.php';
}

// NEW: Team Chat Widget Inclusion
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php';
}
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/notepad_widget.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/notepad_widget.php';
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