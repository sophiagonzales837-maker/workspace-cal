<?php
require_once '../config/db.php';
if (!isset($_GET['id'])) die("No review ID received");
$id = (int)$_GET['id'];
$stmt = $conn->prepare("UPDATE reviews SET status='rejected' WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
session_write_close();
header("Location: admin_reviews.php");
exit;
?>
