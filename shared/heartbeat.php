<?php
require_once '../config/db.php';
require_once '../config/security.php';
header('Content-Type: application/json');

$conn->query("UPDATE users SET status_state = 'Offline' WHERE last_seen < NOW() - INTERVAL 4 MINUTE AND status_state != 'Offline'");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("UPDATE users SET last_seen = NOW(), status_state = 'Active' WHERE id = ?");
$stmt->bind_param('i', $user_id);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['status' => $success ? 'success' : 'error']);
?>