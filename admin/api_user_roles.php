<?php
// FILE: workspace/admin/api_user_roles.php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// Ensure caller is Admin
$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// 1. UPDATE USER SECONDARY ROLE
if ($action === 'update_secondary_settings') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $secondary_role = trim($_POST['secondary_role'] ?? 'None');

    if ($user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid user ID']);
        exit();
    }

    if (!in_array($secondary_role, ['Quality Verifier', 'Quality Checker', 'None'])) {
        $secondary_role = 'None';
    }

    $stmt = $conn->prepare("UPDATE users SET secondary_role = ? WHERE id = ?");
    $stmt->bind_param("si", $secondary_role, $user_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'User settings updated']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
    $stmt->close();
    exit();
}

// 2. TOGGLE USER-SPECIFIC DASHBOARD MODE (Primary <-> Secondary)
if ($action === 'toggle_user_dashboard') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $mode = trim($_POST['mode'] ?? 'primary');

    if (!in_array($mode, ['primary', 'secondary'])) {
        $mode = 'primary';
    }

    $stmt = $conn->prepare("UPDATE users SET active_dashboard = ? WHERE id = ?");
    $stmt->bind_param("si", $mode, $user_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'new_mode' => $mode]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update user dashboard']);
    }
    $stmt->close();
    exit();
}

// 3. GLOBAL TOGGLE: SHIFT ALL WORKSPACE USERS TO PRIMARY OR SECONDARY
if ($action === 'toggle_global_dashboard') {
    $target_mode = trim($_POST['mode'] ?? 'primary');
    if (!in_array($target_mode, ['primary', 'secondary'])) {
        $target_mode = 'primary';
    }

    // Update global setting
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('global_dashboard_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $target_mode, $target_mode);
    $stmt->execute();
    $stmt->close();

    // Mass update active_dashboard on all non-admin operational users
    $stmt2 = $conn->prepare("UPDATE users SET active_dashboard = ? WHERE role NOT IN ('Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin', 'Dev')");
    $stmt2->bind_param("s", $target_mode);
    $stmt2->execute();
    $stmt2->close();

    // Broadcast cache/session invalidation via Pusher if configured
    if (file_exists('../config/pusher.php')) {
        require_once '../config/pusher.php';
        if (function_exists('workspace_pusher_server')) {
            try {
                workspace_pusher_server()->trigger('global-updates', 'dashboard-mode-switched', ['mode' => $target_mode]);
            } catch (Exception $e) {}
        }
    }

    echo json_encode(['status' => 'success', 'global_mode' => $target_mode]);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);