<div style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid #E0E0E0; color: #888; font-size: 13px;">
    &copy; <?php echo date("Y"); ?> Consumer Healthcare Compliance Workspace. All rights reserved.
</div>
</div> <!-- Close .main wrapper -->

<?php
// Inject Global Tracking & Idle Timeout.
// Because we updated heartbeat.php, the Dev role will never trigger the idle screen.
if (file_exists(__DIR__ . '/../../shared/global_tracking.php')) {
    include __DIR__ . '/../../shared/global_tracking.php';
}

// Inject Team Chat Widget (Floating WhatsApp-style chat)
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php')) {
    include $_SERVER['DOCUMENT_ROOT'] . '/workspace/shared/team_chat_widget.php';
}
?>
</body>

</html>