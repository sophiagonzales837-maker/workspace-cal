<?php
// FILE: export_backup.php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    session_write_close();
    header("Location: ../auth/login.php");
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="chc_secure_backup_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

fputcsv($output, ['Lead ID', 'Customer ID', 'Full Name', 'Email', 'Phone', 'Address', 'City', 'State', 'Zip', 'DOB', 'Vertical', 'Application Text', 'Status', 'Date Submitted']);

$query = "SELECT * FROM leads ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);

while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        $row['id'],
        $row['customer_id'],
        decryptData($row['customer_name'] ?? ''),
        decryptData($row['email'] ?? ''),
        decryptData($row['phone'] ?? ''),
        decryptData($row['address'] ?? ''),
        decryptData($row['city'] ?? ''),
        decryptData($row['state'] ?? ''),
        decryptData($row['zip_code'] ?? ''),
        decryptData($row['dob'] ?? ''),
        $row['vertical'] ?? '',
        decryptData($row['application_text'] ?? ''), 
        decryptData($row['questions'] ?? ''),
        decryptData($row['status'] ?? ''),
        $row['created_at']
    ]);
}
fclose($output);
exit();
?>
