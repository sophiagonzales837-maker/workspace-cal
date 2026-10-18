<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM inhouse_criteria WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}
session_write_close();
header("Location: inhouse_criteria.php");
exit();
?>