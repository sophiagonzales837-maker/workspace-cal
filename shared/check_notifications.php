<?php
require_once '../config/db.php'; 
require_once '../config/security.php'; 
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    workspace_close_db_connections();
    exit();
}
$user_id = $_SESSION['user_id'];
$res = mysqli_query($conn, "SELECT id, message, link FROM notifications WHERE user_id = '$user_id' AND is_read = 0");
$data = [];
if ($res) {
    while($row = mysqli_fetch_assoc($res)) {
        $data[] = $row;
        $notif_id = $row['id'];
        mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = '$notif_id'");
    }
}
header('Content-Type: application/json');
echo json_encode($data);
workspace_close_db_connections();
?>
