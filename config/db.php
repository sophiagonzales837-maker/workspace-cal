<?php
// FILE: config/db.php

require_once __DIR__ . '/env.php';

// Securely grab the environment values from the vault
$env = workspace_load_env();

if (!defined('BASE_PATH')) define('BASE_PATH', $env['BASE_PATH'] ?? '/workspace/');
if (!defined('BASE_URL')) define('BASE_URL', 'https://employees.kingstonsolutions.co.in/workspace/');
if (!defined('BASE_DIR')) define('BASE_DIR', realpath(__DIR__ . '/../'));

require_once __DIR__ . '/pusher.php';

// 2. Database Connection
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = new mysqli($env['DB_HOST'], $env['DB_USER'], $env['DB_PASS'], $env['DB_NAME']);
    if ($conn->connect_error) {
        throw new Exception('Main DB connection failed: ' . $conn->connect_error);
    }
    // CRITICAL: Set charset to prevent binary IV corruption during retrieval
    $conn->set_charset("utf8mb4");

    if (!function_exists('workspace_close_db_connections')) {
        function workspace_close_db_connections()
        {
            global $conn;
            if (isset($conn) && $conn instanceof mysqli) {
                @$conn->close();
            }
        }
    }
} catch (Exception $e) {
    error_log('[DB] ' . $e->getMessage());
    header('HTTP/1.1 503 Service Unavailable');
    echo '<h1>Service temporarily unavailable</h1>';
    echo '<p>Database connections are currently unavailable. Please try again in a few minutes.</p>';
    exit();
}

// 3. Start Session with Persistent Cookies
if (session_status() == PHP_SESSION_NONE) {
    $lifetime = 43200;
    ini_set('session.gc_maxlifetime', $lifetime);
    ini_set('session.cookie_lifetime', $lifetime);
    session_set_cookie_params($lifetime);
    session_start();
}

// 4. Team Tracking Functions
if (!function_exists('get_team_schedule')) {
    function get_team_schedule($team)
    {
        global $conn;
        $schedule = ['18:00', '03:00'];
        if ($stmt = mysqli_prepare($conn, "SELECT login_time, logout_time FROM teams WHERE name = ?")) {
            mysqli_stmt_bind_param($stmt, "s", $team);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($result && mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);
                mysqli_stmt_close($stmt);
                return [date('H:i', strtotime($row['login_time'])), date('H:i', strtotime($row['logout_time']))];
            }
            mysqli_stmt_close($stmt);
        }
        if ($team == 'Team2') return ['21:30', '06:30'];
        return $schedule;
    }
}

if (!function_exists('is_on_shift')) {
    function is_on_shift($team)
    {
        date_default_timezone_set('Asia/Kolkata');
        global $conn;
        $team = trim((string)$team);
        if ($team === '') return false;

        $stmt = $conn->prepare('SELECT login_time, logout_time FROM teams WHERE name = ? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('s', $team);
        $stmt->execute();
        $schedule = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$schedule) return false;

        $login = (string)$schedule['login_time'];
        $logout = (string)$schedule['logout_time'];
        if (
            !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $login)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $logout)
        ) {
            return false;
        }

        $login_parts = array_map('intval', explode(':', $login));
        $logout_parts = array_map('intval', explode(':', $logout));
        $login_seconds = ($login_parts[0] * 3600) + ($login_parts[1] * 60) + ($login_parts[2] ?? 0);
        $logout_seconds = ($logout_parts[0] * 3600) + ($logout_parts[1] * 60) + ($logout_parts[2] ?? 0);
        if ($login_seconds === $logout_seconds) return false;

        $now_seconds = ((int)date('G') * 3600) + ((int)date('i') * 60) + (int)date('s');
        if ($login_seconds < $logout_seconds) {
            return $now_seconds >= $login_seconds && $now_seconds < $logout_seconds;
        }
        return $now_seconds >= $login_seconds || $now_seconds < $logout_seconds;
    }
}

// 5. Activity Tracking & Security Boot
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    mysqli_query($conn, "UPDATE users SET last_activity = NOW() WHERE id = '$uid'");

    $check = mysqli_query($conn, "SELECT force_logout FROM users WHERE id = '$uid'");
    if ($check && mysqli_num_rows($check) > 0) {
        $u_data = mysqli_fetch_assoc($check);
        if ($u_data['force_logout'] == 1) {
            mysqli_query($conn, "UPDATE users SET force_logout = 0 WHERE id = '$uid'");
            session_destroy();
            header("Location: " . BASE_PATH . "auth/login.php?error=kicked");
            exit();
        }
    }
}

if (!function_exists('getWebPath')) {
    function getWebPath($db_path)
    {
        if (empty(trim((string)$db_path))) return '';
        $paths = explode(',', $db_path);
        $urls = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if (empty($path)) continue;
            if (strpos($path, 'http') !== 0) $urls[] = 'https://consumerhealthcarecompliance.com/' . ltrim($path, '/');
            else $urls[] = $path;
        }
        return implode(',', $urls);
    }
}
