<?php
// FILE: workspace/shared/api_check_alerts.php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json');

// Mark long-inactive users offline without creating break records.
$conn->query("UPDATE users SET status_state = 'Offline', logged_out_at = NOW() WHERE status_state = 'Active' AND TIMESTAMPDIFF(MINUTE, last_seen, NOW()) > 30");

// Stop here if the user isn't logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$user_id = $_SESSION['user_id'];
$query = "SELECT id, message, link FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = " . $row['id']);
}

echo json_encode([
    "status" => "success",
    "has_new" => count($notifications) > 0,
    "notifications" => $notifications
]);
?>