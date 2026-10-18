<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notepad</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
            height: 100vh;
            background: transparent;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* Force Notepad to fill the Electron Window */
        #chc-notepad-panel {
            display: flex !important;
            position: static !important;
            width: 100% !important;
            max-width: 100% !important;
            height: 100vh !important;
            max-height: 100vh !important;
            box-shadow: none !important;
            border-radius: 12px !important;
            border: 1px solid #3b82f6 !important;
        }

        /* OS dragging controls */
        .np-header {
            -webkit-app-region: drag;
            border-radius: 12px 12px 0 0;
        }

        .np-btn,
        .np-title-input,
        .np-textarea,
        .np-item {
            -webkit-app-region: no-drag;
        }
    </style>
</head>

<body>

    <?php include 'shared/notepad_widget.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Trigger the internal widget to "open" instantly
            if (typeof openMostRecentNote === 'function') {
                openMostRecentNote();
            }

            const closeBtn = document.querySelector('.np-btn[title="Close"]');
            if (closeBtn) {
                closeBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.electronAPI) window.electronAPI.closeCurrentWindow(); // <-- FIXED
                };
            }
        });
    </script>
</body>

</html>