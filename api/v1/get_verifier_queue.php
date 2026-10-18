<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';

protect('Verifier');

$verifier_id = (int)($_SESSION['user_id'] ?? 0);
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "SELECT id, customer_name, first_name, last_name, vertical, phone, updated_at, status, reminder_time
          FROM leads
          WHERE verifier_id = ?
          AND (status LIKE 'Pending Verification%' OR status LIKE 'Pending%')
          ORDER BY created_at DESC";

if ($search === '') {
    $query .= " LIMIT 20 OFFSET $offset";
}

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query.']);
    $conn->close();
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $verifier_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Failed to execute query.']);
    $stmt->close();
    $conn->close();
    exit;
}

$rows = [];
$skipped = 0;

while ($lead = mysqli_fetch_assoc($result)) {
    $lead = decrypt_fields($lead);

    if ($search !== '') {
        $customer_name = trim(($lead['customer_name'] ?? '') . ' ' . ($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $vertical = trim($lead['vertical'] ?? '');
        $phone = $lead['phone'] ?? '';
        $status = $lead['status'] ?? '';

        $searchable_text = strtolower($customer_name . ' ' . $vertical . ' ' . $phone . ' ' . $status);
        if (strpos($searchable_text, strtolower($search)) === false) {
            continue;
        }

        if ($skipped < $offset) {
            $skipped++;
            continue;
        }
    }

    $rows[] = $lead;

    if ($search !== '' && count($rows) >= 20) {
        break;
    }
}

mysqli_free_result($result);
$stmt->close();
workspace_close_db_connections();

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'rows' => $rows]);
exit;
