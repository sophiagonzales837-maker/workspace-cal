<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    mysqli_query($conn, "UPDATE users SET status_state = 'Offline', logged_out_at = NOW() WHERE id = $user_id");
}

// Destroy session after marking logout
$_SESSION = array();
session_destroy();
session_write_close();
header("Location: login.php");
exit();
?>
