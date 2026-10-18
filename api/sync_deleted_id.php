<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals('MakeMoney$$', (string)($data['auth_key'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$lead_id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
if ($lead_id === false || $lead_id === null || $lead_id <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'A valid lead ID is required']);
    exit;
}

$stmt = $conn->prepare('UPDATE leads SET id_upload_token = NULL, id_front_path = NULL, id_back_path = NULL WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to prepare update']);
    exit;
}
$stmt->bind_param('i', $lead_id);
if (!$stmt->execute()) {
    error_log('[ID deletion sync] Local update failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to update lead']);
    exit;
}
$stmt->close();

if (file_exists(__DIR__ . '/../config/pusher.php')) {
    require_once __DIR__ . '/../config/pusher.php';
    if (function_exists('workspace_pusher_server')) {
        try {
            workspace_pusher_server()->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id]);
        } catch (Throwable $error) {
            error_log('[ID deletion sync] Pusher event failed: ' . $error->getMessage());
        }
    }
}

echo json_encode(['status' => 'success', 'lead_id' => $lead_id]);
if (function_exists('workspace_close_db_connections')) workspace_close_db_connections();
