<?php
// FILE: auth/reset_password.php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$msg = '';
$token = $_GET['token'] ?? '';
$valid_token = false;

if (empty($token)) {
    die("Invalid access: No token provided.");
}

// 1. Verify the token and expiration
$stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    $user = $res->fetch_assoc();
    $valid_token = true;
} else {
    $msg = "<span style='color: red;'>This reset link is invalid or has expired. Please request a new one.</span>";
}

// 2. Handle Password Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $valid_token) {
    $new_pw = $_POST['password'];
    $confirm_pw = $_POST['confirm_password'];

    if ($new_pw !== $confirm_pw) {
        $msg = "<span style='color: red;'>Passwords do not match.</span>";
    } else {
        // Hash the new password using your system's method
        $hashed_pw = password_hash($new_pw, PASSWORD_DEFAULT);

        // Update user, clear the token, and unlock account if it was locked
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL, failed_attempts = 0, is_locked = 0 WHERE id = ?");
        $update->bind_param("si", $hashed_pw, $user['id']);

        if ($update->execute()) {
            $msg = "<span style='color: green;'>Password successfully reset! You can now <a href='login.php'>login</a>.</span>";
            $valid_token = false; // Hide form after success
        } else {
            $msg = "<span style='color: red;'>Database error. Please try again later.</span>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Set New Password</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body class="auth-dark-theme" style="justify-content: center; align-items: center; height: 100vh; display: flex; background: #0f172a;">
    <div class="login-box" style="width: 400px; text-align: center; background: white; padding: 40px; border-radius: 16px;">
        <h2 style="color: var(--primary-navy); margin-bottom: 10px;">Create New Password</h2>

        <?php if ($msg != '') echo "<p style='font-size: 14px; margin-bottom: 20px;'>$msg</p>"; ?>

        <?php if ($valid_token): ?>
            <form method="POST">
                <div style="margin-bottom: 15px; text-align: left; position: relative;">
                    <label class="form-label">New Password</label>
                    <input type="password" name="password" id="new_password" class="input-style" required style="width: 100%; padding-right: 40px;">
                    <i class="fas fa-eye" id="toggleNewPw" style="position: absolute; right: 15px; top: 40px; cursor: pointer; color: #666;"></i>
                </div>

                <div style="margin-bottom: 25px; text-align: left;">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="input-style" required style="width: 100%;">
                </div>

                <button type="submit" class="btn" style="width: 100%;">Update Admin Password</button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="login.php" style="font-size: 13px; color: var(--electric-blue); text-decoration: none;">&larr; Return to Login</a>
        </div>
    </div>

    <script>
        document.getElementById('toggleNewPw').addEventListener('click', function() {
            const pwInput = document.getElementById('new_password');
            const type = pwInput.getAttribute('type') === 'password' ? 'text' : 'password';
            pwInput.setAttribute('type', type);
            this.classList.toggle('fa-eye-slash');
        });
    </script>
</body>

</html>