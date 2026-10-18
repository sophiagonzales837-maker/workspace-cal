<?php
// FILE: workspace-CAL/api/v1/receive_verified_lead.php
header('Content-Type: application/json');
require_once '../../config/db.php';
require_once '../../config/security.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || ($data['auth_key'] ?? '') !== 'MakeMoney$$') {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$lead_id = (int)($data['lead_id'] ?? 0);
$status = $data['status'] ?? 'NU';
$admin_status = $data['admin_status'] ?? null; // Extract the new admin_status from payload

$closer_status = array_key_exists('closer_status', $data)
    ? trim((string)$data['closer_status'])
    : ($status === 'Processing the Information' ? $status : null);
$verifier_name = $data['verifier_name'] ?? null;
$verifier_id = isset($data['verifier_id']) ? (int)$data['verifier_id'] : null;
$verifier_app_text = $data['verifier_application_text'] ?? '';

if ($lead_id > 0) {
    // Re-encrypt the report for local storage
    $enc_app_text = function_exists('encryptData') ? encryptData($verifier_app_text) : $verifier_app_text;

    // Updated Query: Included admin_status = COALESCE(?, admin_status)
    $stmt = $conn->prepare("UPDATE leads SET status = ?, admin_status = COALESCE(?, admin_status), closer_status = COALESCE(?, closer_status), verifier_id = COALESCE(?, verifier_id), verifier_name = ?, verifier_application_text = ?, updated_at = NOW() WHERE id = ?");

    // Updated Bind Params: Added an 's' for admin_status
    $stmt->bind_param("sssissi", $status, $admin_status, $closer_status, $verifier_id, $verifier_name, $enc_app_text, $lead_id);

    if ($stmt->execute()) {
        // Trigger Pusher so the CAL Admin dashboard refreshes instantly
        if (file_exists(__DIR__ . '/../../config/pusher.php')) {
            require_once __DIR__ . '/../../config/pusher.php';
            if (function_exists('workspace_pusher_server')) {
                try {
                    workspace_pusher_server()->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id]);
                } catch (Exception $e) {
                }
            }
        }
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database Update Failed"]);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Missing lead ID"]);
}
