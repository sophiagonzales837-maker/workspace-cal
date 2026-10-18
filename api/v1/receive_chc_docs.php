<?php
// FILE: LOCAL XAMPP - workspace/api/v1/receive_chc_docs.php
require_once '../../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['token'])) {
    $token = $data['token'];
    
    $updates = [];
    $params = [];
    $types = "";

    if (isset($data['id_front_path'])) {
        $updates[] = "id_front_path = ?";
        $params[] = $data['id_front_path'];
        $types .= "s";
    }
    if (isset($data['additional_records'])) {
        $updates[] = "additional_records = ?";
        $params[] = $data['additional_records'];
        $types .= "s";
    }
    if (isset($data['document_path'])) {
        $updates[] = "document_path = ?";
        $params[] = $data['document_path'];
        $types .= "s";
    }
    if (isset($data['status'])) {
        $updates[] = "status = ?";
        $params[] = $data['status'];
        $types .= "s";
    }

    if (!empty($updates)) {
        $sql = "UPDATE leads SET " . implode(', ', $updates) . " WHERE id_upload_token = ?";
        $params[] = $token;
        $types .= "s";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        
        // Ping Pusher to reload the dashboards so Verifiers instantly see the ID pop up
        if (file_exists('../../config/pusher.php')) {
            require_once '../../config/pusher.php';
            if (function_exists('workspace_pusher_server')) {
                try { workspace_pusher_server()->trigger('global-updates', 'lead-updated', []); } catch(Exception $e) {}
            }
        }
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "No updatable fields"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid payload"]);
}
?>