<?php
// Prevent errors if session isn't started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en" class="app-dark-theme">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Portal | CHC Workspace</title>

    <!-- Using the standard CRM stylesheet -->
    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="app-dark-theme">
    <?php include 'sidebar.php'; ?>

    <div class="main">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
            <h1 style="color: var(--primary-navy); margin: 0; font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;">Developer Operations</h1>

        </div>