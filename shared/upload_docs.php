<?php
// FILE: consumerhealthcarecompliance.com/workspace/shared/upload_docs.php
require_once '../config/db.php'; // Ensure this points to your active database connection

// Enable cross-origin requests from the customer dashboard
header("Access-Control-Allow-Origin: https://consumerhealthcarecompliance.com");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['token'])) {

    $token = $_POST['token'];

    // Save files directly to the mat-upload folder on the root of consumerhealthcarecompliance.com
    $upload_dir = '../../mat-upload/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $id_file_path = null;
    $proofs_file_paths = [];

    // 1. Process ID Upload
    if (isset($_FILES['id_upload']) && $_FILES['id_upload']['error'] === UPLOAD_ERR_OK) {
        $id_name = time() . '_' . $token . '_ID_' . basename($_FILES['id_upload']['name']);
        $id_dest = $upload_dir . $id_name;

        if (move_uploaded_file($_FILES['id_upload']['tmp_name'], $id_dest)) {
            // Save the clean path for the database
            $id_file_path = $id_dest;
        }
    }

    // 2. Process Additional Proofs
    if (isset($_FILES['proofs_upload'])) {
        $total_files = count($_FILES['proofs_upload']['name']);

        for ($i = 0; $i < $total_files; $i++) {
            if ($_FILES['proofs_upload']['error'][$i] === UPLOAD_ERR_OK) {
                $proof_name = time() . '_' . $token . '_Proof_' . $i . '_' . basename($_FILES['proofs_upload']['name'][$i]);
                $proof_dest = $upload_dir . $proof_name;

                if (move_uploaded_file($_FILES['proofs_upload']['tmp_name'][$i], $proof_dest)) {
                    $proofs_file_paths[] = $proof_dest;
                }
            }
        }
    }

    // Convert paths to JSON if proofs exist
    $proofs_json = !empty($proofs_file_paths) ? json_encode($proofs_file_paths) : null;

    // 3. Update the Database (Using standard mysqli to match your existing architecture)
    try {
        $stmt = $conn->prepare("UPDATE time_data2 SET id_file_path = ?, proofs_file_path = ?, doc_status = 'under_review' WHERE upload_token = ?");
        $stmt->bind_param("sss", $id_file_path, $proofs_json, $token);
        $stmt->execute();
        $stmt->close();

        // 4. Redirect the customer back to the external domain dashboard
        header("Location: https://pulaskikherkherlaw.info/dashboard?token=" . urlencode($token));
        exit();
    } catch (Exception $e) {
        die("Error updating documents: " . $e->getMessage());
    }
} else {
    die("Invalid request.");
}
