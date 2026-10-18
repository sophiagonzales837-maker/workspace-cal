<?php
// FILE: auth/forgot_password.php
session_start();
require_once '../config/db.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];

    // Check if user exists and is an Admin
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();

        if ($row['role'] === 'Admin') {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Save token to DB
            $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $update->bind_param("ssi", $token, $expires, $row['id']);
            $update->execute();

            // Generate link (Adjust domain as needed)
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $reset_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/workspace/auth/reset_password.php?token=" . $token;

            // Send Email
            $to = "kingstonsolutions9@gmail.com";
            $subject = "Admin Password Reset Request";
            $body = "A password reset was requested for the Admin account: $username.\n\nClick here to reset it (valid for 1 hour):\n$reset_link";
            $headers = "From: system@consumerhealthcarecompliance.com\r\n";

            mail($to, $subject, $body, $headers);

            $msg = "<span style='color: green;'>If that admin account exists, a reset link has been sent to the registered email.</span>";
        } else {
            $msg = "<span style='color: red;'>Only Administrators can use the automated reset. Please contact an Admin to unlock your account.</span>";
        }
    } else {
        // Generic message to prevent username enumeration
        $msg = "<span style='color: green;'>If that admin account exists, a reset link has been sent to the registered email.</span>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body class="auth-dark-theme" style="justify-content: center; align-items: center; height: 100vh;">
    <div class="login-box" style="width: 320px; text-align: center;">
        <h2 style="color: var(--primary-navy);">Reset Password</h2>
        <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Only Administrators can use this form. Other users must contact an Admin.</p>

        <?php if ($msg != '') echo "<p style='font-size: 13px; font-weight: bold; margin-bottom: 15px;'>$msg</p>"; ?>

        <form method="POST">
            <input type="text" name="username" placeholder="Enter Admin Username" required>
            <button type="submit" class="btn" style="width: 100%; margin-top: 15px;">Send Reset Link</button>
        </form>
        <a href="login.php" style="display: block; margin-top: 15px; font-size: 12px; color: var(--electric-blue); text-decoration: none;">&larr; Back to Login</a>
    </div>
</body>

</html>