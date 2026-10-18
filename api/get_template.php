<?php
// FILE: workspace/api/get_template.php
session_start();
require_once '../config/db.php';

// Prevent HTML errors from breaking the JSON
error_reporting(0);
header('Content-Type: application/json');

// Allow Closers and Admins
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Closer', 'Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$case_name = mysqli_real_escape_string($conn, $_POST['case_name'] ?? '');

$query = mysqli_query($conn, "SELECT document_body, qa_section FROM generator_templates WHERE case_name = '$case_name' LIMIT 1");

if ($row = mysqli_fetch_assoc($query)) {
    echo json_encode([
        'status' => 'success',
        'document_body' => $row['document_body'],
        'qa_section' => $row['qa_section']
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Template not found for: ' . $case_name]);
}
?>