<?php
require_once '../config/db.php';
require_once '../config/security.php';

// Ensure only Admins can perform this action
protect('Admin');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_id'])) {
    $lead_id = (int)$_POST['lead_id'];

    // Optional: Fetch lead data first to delete associated files (ID proofs, documents)
    $query = mysqli_query($conn, "SELECT id_front_path, id_back_path, document_path, additional_records FROM leads WHERE id = '$lead_id'");
    if ($row = mysqli_fetch_assoc($query)) {
        // Delete ID Front
        if (!empty($row['id_front_path']) && file_exists('../../' . ltrim($row['id_front_path'], '/'))) {
            unlink('../../' . ltrim($row['id_front_path'], '/'));
        }
        // Delete ID Back
        if (!empty($row['id_back_path']) && file_exists('../../' . ltrim($row['id_back_path'], '/'))) {
            unlink('../../' . ltrim($row['id_back_path'], '/'));
        }
        // Delete Documents
        if (!empty($row['document_path'])) {
            $docs = explode(',', $row['document_path']);
            foreach($docs as $doc) {
                if (!empty($doc) && file_exists('../../' . ltrim($doc, '/'))) {
                    unlink('../../' . ltrim($doc, '/'));
                }
            }
        }
        // Delete Additional Records
        if (!empty($row['additional_records'])) {
            $recs = explode(',', $row['additional_records']);
            foreach($recs as $rec) {
                if (!empty($rec) && file_exists('../../' . ltrim($rec, '/'))) {
                    unlink('../../' . ltrim($rec, '/'));
                }
            }
        }
    }

    // Delete the lead from the database
    $stmt = $conn->prepare("DELETE FROM leads WHERE id = ?");
    $stmt->bind_param("i", $lead_id);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Lead completely deleted."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete lead from database."]);
    }
    
    $stmt->close();
    $conn->close();
    exit;
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
    exit;
}
?>