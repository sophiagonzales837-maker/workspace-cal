<?php
// FILE: auth/login.php
ini_set('session.gc_maxlifetime', 43200);
ini_set('session.cookie_lifetime', 43200);
session_set_cookie_params(43200);
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

function getUserIP()
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ipList[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (in_array($_SESSION['role'], ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'], true)) {
        $admin_folder = $_SESSION['role'] === 'Admin' ? 'admin' : 'jr_admin';
        header("Location: ../$admin_folder/index.php");
    } elseif ($_SESSION['role'] === 'Verifier') header("Location: ../verifier/index.php");
    elseif (in_array($_SESSION['role'], ['Quality Checker', 'Quality Check', 'QC'])) header("Location: ../quality_checker/index.php");
    else header("Location: ../" . strtolower($_SESSION['role']) . "/index.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $user_ip = getUserIP();

    // Fetch ALL users matching the provided username
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $login_success = false;
    $locked_error = '';

    if ($res && $res->num_rows > 0) {
        $matching_users = [];
        while ($row = $res->fetch_assoc()) {
            $matching_users[] = $row;
        }

        // Loop through each matching user to test their specific password hash
        foreach ($matching_users as $row) {

            // If this specific account is locked, save the error but keep checking other accounts
            if ($row['is_locked'] == 1) {
                $locked_error = "Account locked due to multiple failed attempts. Please contact an Admin.";
                continue;
            }

            if (password_verify($password, $row['password']) || $password === $row['password']) {
                $login_success = true;

                // START LATE LOGIN TRACKING LOGIC
                date_default_timezone_set('Asia/Kolkata');
                $now = time();
                $current_hour = (int)date('H');
                $is_late = 0;
                $mins_late = 0;

                if ($row['team'] == 'Team1') {
                    $shift_time = strtotime('today 18:00');
                } elseif ($row['team'] == 'Team2') {
                    $shift_time = strtotime('today 21:30');
                } else {
                    $shift_time = null;
                }

                if ($shift_time && $current_hour >= 14) {
                    if ($now > ($shift_time + 600)) { // 10 minute grace period
                        $is_late = 1;
                        $mins_late = floor(($now - $shift_time) / 60);
                    }
                    $user_id_log = $row['id'];
                    mysqli_query($conn, "INSERT INTO login_logs (user_id, login_time, is_late, mins_late) VALUES ('$user_id_log', NOW(), '$is_late', '$mins_late')");
                }

                // Login successful - Set Sessions
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['team'] = $row['team'];
                $_SESSION['username'] = $row['username'];

                // Clear logout timestamp and reset failed attempts
                mysqli_query($conn, "UPDATE users SET status_state = 'Active', last_seen = NOW(), logged_out_at = NULL, failed_attempts = 0, is_locked = 0 WHERE id = " . $row['id']);

                // Routing
                $roleFolders = workspace_role_folders();

                $folder = $roleFolders[$row['role']] ?? strtolower(str_replace([' ', '_'], ['', '.'], $row['role']));
                session_write_close();
                header("Location: ../$folder/index.php");
                exit();
            }
        }

        // If we finish the loop and NO password matched
        if (!$login_success) {
            if ($locked_error !== '') {
                $error = $locked_error;
            } else {
                // Increment failed attempts for ALL accounts with this username
                foreach ($matching_users as $row) {
                    $attempts = $row['failed_attempts'] + 1;
                    if ($attempts >= 3 && $row['role'] !== 'Admin') {
                        $conn->query("UPDATE users SET failed_attempts = $attempts, is_locked = 1 WHERE id = " . $row['id']);
                        $error = "Account locked due to 3 failed attempts. Please contact Admin.";
                    } else {
                        $conn->query("UPDATE users SET failed_attempts = $attempts WHERE id = " . $row['id']);
                        $error = "Invalid password. Attempt $attempts of 3.";
                    }
                }
            }
        }
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Secure Workspace Login</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            /* High-tech abstract network background image blended with dark gradients */
            background: linear-gradient(135deg, rgba(3, 7, 18, 0.92), rgba(15, 23, 42, 0.88)),
                url('https://usagcservices.com/assets/images/bg/main-bg-mobile.webp?rev=cb347ae9ffefc691d44c09e987cf67fe') center/cover no-repeat;
            color: #f8fafc;
            font-family: 'Inter', sans-serif;
            overflow: hidden;
            position: relative;
        }

        /* High-tech glowing ambient tech grid lines overlay */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(to right, rgba(56, 189, 248, 0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(56, 189, 248, 0.05) 1px, transparent 1px);
            background-size: 40px 40px;
            z-index: 0;
            pointer-events: none;
        }

        /* Glowing light source behind the login box */
        .tech-glow {
            position: absolute;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.18) 0%, rgba(37, 99, 235, 0) 70%);
            border-radius: 50%;
            z-index: 0;
            filter: blur(50px);
            pointer-events: none;
        }

        .auth-box {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 44px 40px;
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.82);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.8), 0 0 40px rgba(14, 165, 233, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.2);
        }

        .auth-logo {
            width: auto;
            height: 60px;
            margin: 0 auto 22px auto;
            display: block;
            filter: brightness(0) invert(1) drop-shadow(0 0 10px rgba(56, 189, 248, 0.5));
        }

        .auth-title {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 8px 0;
            color: #ffffff;
            letter-spacing: -0.03em;
            text-align: center;
        }

        .auth-copy {
            margin: 0 0 30px;
            color: #94a3b8;
            font-size: 13.5px;
            text-align: center;
            line-height: 1.5;
        }

        .input-group {
            position: relative;
            width: 100%;
            margin-bottom: 16px;
        }

        .auth-input {
            width: 100%;
            padding: 15px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(30, 41, 59, 0.6);
            color: #ffffff;
            font-size: 14px;
            outline: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .auth-input:focus {
            border-color: #38bdf8;
            background: rgba(30, 41, 59, 0.9);
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.2);
        }

        .auth-input::placeholder {
            color: #64748b;
        }

        .auth-button {
            width: 100%;
            margin-top: 6px;
            padding: 15px 18px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
            color: white;
            font-size: 14.5px;
            font-weight: 700;
            letter-spacing: -0.01em;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 20px rgba(14, 165, 233, 0.4);
        }

        .auth-button:hover {
            background: linear-gradient(135deg, #0284c7 100%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(14, 165, 233, 0.6);
        }

        .auth-button:active {
            transform: translateY(0);
        }

        .error-banner {
            color: #fca5a5;
            background: rgba(239, 68, 68, 0.12);
            border-left: 3px solid #ef4444;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-link {
            margin-top: 24px;
            font-size: 13.5px;
            color: #94a3b8;
            text-align: center;
        }

        .footer-link a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        .footer-link a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="tech-glow"></div>

    <div class="auth-box">
        <img src="../assets/logo.png" alt="CHC Logo" class="auth-logo" onerror="this.src=''; this.alt='CHC WORKSPACE';">
        <h1 class="auth-title">Workspace Portal</h1>
        <p class="auth-copy">Enter your credentials to access your secure operations dashboard.</p>

        <?php if ($error != ''): ?>
            <div class="error-banner">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <input class="auth-input" type="text" name="username" placeholder="Username" required autocomplete="username">
            </div>

            <div class="input-group">
                <input type="password" id="user_password" name="password" class="auth-input" placeholder="Password" required style="padding-right: 48px;" autocomplete="current-password">

                <button type="button" id="toggleBtn" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; z-index: 10;" aria-label="Toggle password visibility">
                    <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path id="eyePath" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle id="eyeCircle" cx="12" cy="12" r="3"></circle>
                        <line id="eyeSlash" x1="1" y1="1" x2="23" y2="23" style="display:none; stroke: #fca5a5; stroke-width: 2.5;"></line>
                    </svg>
                </button>
            </div>

            <button type="submit" class="auth-button">Sign In</button>
        </form>

        <div class="footer-link">
            <a href="forgot_password.php">Forgot password?</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggleBtn');
            const passwordField = document.getElementById('user_password');
            const eyeSlash = document.getElementById('eyeSlash');

            if (toggleBtn && passwordField) {
                toggleBtn.addEventListener('click', function() {
                    const isPassword = passwordField.getAttribute('type') === 'password';
                    passwordField.setAttribute('type', isPassword ? 'text' : 'password');
                    eyeSlash.style.display = isPassword ? 'block' : 'none';
                });
            }
        });
    </script>
</body>

</html>