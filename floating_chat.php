<?php
// FILE: /floating_chat.php
session_start();

// Prevents a white screen if the session expires
if (!isset($_SESSION['user_id'])) {
    echo "<body style='background:#121212; color:white; display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;'>
            <div style='text-align:center;'>
                <h2 style='color:#d4af37;'>Session Expired</h2>
                <p>Please log back into the CRM to re-connect your chat.</p>
            </div>
          </body>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHAT_CLOSED</title>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        html,
        body {
            background-color: transparent !important;
            margin: 0;
            padding: 0;
            overflow: hidden;
            width: 100%;
            height: 100%;
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/shared/team_chat_widget.php'; ?>

</body>

</html>