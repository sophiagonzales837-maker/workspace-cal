<?php
// workspace/shared/realtime_check.php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'unauthorized']);
    workspace_close_db_connections();
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// FIX from previous step: Instantly release session lock so pages stay fast
session_write_close(); 

// 1. Fetch the absolute latest unread alert notification for this user
$notif_stmt = $conn->prepare("SELECT id, message, link FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 1");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();

$new_notification = null;
if ($row = $notif_result->fetch_assoc()) {
    $new_notification = $row;
}
$notif_stmt->close();

// 2. Dynamic Count Tracking based on User Role to trigger layout updates
$item_count = 0;

switch ($user_role) {
    case 'Verifier':
        // Track assigned leads waiting for verification
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM leads WHERE verifier_id = ? AND status = 'Pending Verification'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $item_count = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        break;

    case 'Agent':
        // Track the count of active leads submitted by this specific agent
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM leads WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $item_count = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        break;

    case 'Closer':
        // Track leads assigned to this Closer waiting to be worked/closed
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM leads WHERE closer_id = ? AND status = 'Assigned to Closer'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $item_count = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        break;

    case 'Admin':
    case 'Jr_Admin':
        // Admins track the global incoming unassigned queue or system exceptions
        $result = $conn->query("SELECT COUNT(*) as total FROM leads WHERE status = 'Pending Verification' AND verifier_id IS NULL");
        $item_count = $result->fetch_assoc()['total'];
        break;
}

echo json_encode([
    'status' => 'success',
    'notification' => $new_notification,
    'currentCount' => $item_count
]);
workspace_close_db_connections();