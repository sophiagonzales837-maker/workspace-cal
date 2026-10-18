<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');

// ========================================================================
// CRITICAL FIX: RELEASE SESSION LOCK FOR RAPID PARALLEL EDITING
// ========================================================================
session_write_close();

function workspace_trigger_lead_event($event, array $payload) {
    $pusher = workspace_pusher_server();
    if ($pusher) {
        try { $pusher->trigger('lead-updates-channel', $event, $payload); } catch (Exception $e) {}
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $col = $_POST['col'];
    $val = trim($_POST['val'] ?? '');

    if ($id <= 0 || empty($col)) die("Invalid request");

    // 1. SECURITY: Whitelist ALL table columns
    $allowed_cols = [
        'vertical', 'first_name', 'last_name', 'phone', 'email',
        'address', 'city', 'state', 'zip_code', 'dob', 'status', 'admin_status',
        'application_text', 'questions', 'agent_name', 'closer_name', 'verifier_name',
        'client_name', 'customer_id', 'payouts', 'incentive', 'created_at', 
        'quick_update', 'referred_by', 'message', 
        'jornaya_leadid', 'trustedform_cert_url', 'ip_address',
        'closer_status', 'alternate_details', 'updated_at'
    ];

    if (!in_array($col, $allowed_cols)) {
        die("Unauthorized column update attempted: " . htmlspecialchars($col));
    }

    // 2. TIMESTAMP FREEZE LOGIC
    $freeze_timestamps = ", updated_at = updated_at";
    if ($col === 'updated_at') $freeze_timestamps = "";

    // 3. ENCRYPTION ENGINE
    $encrypted_fields = ['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'client_name', 'questions', 'application_text'];
    $save_val = $val;
    if (in_array($col, $encrypted_fields) && !empty($val)) {
        $save_val = encryptData($val);
    }

    // 4. ROUTING: MULTI-CLIENT (C10/C12 & DNQ/Doc)
    if ($col === 'client_name' || $col === 'status') {
        $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("si", $save_val, $id);
        if ($stmt->execute()) echo "Saved"; else echo "Error";
        $stmt->close();
        exit; 
    }

    // 5. PHONE NUMBER & HASH SYNC 
    if ($col === 'phone') {
        $clean_phone = preg_replace('/[^\d+]/', '', $val);
        if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
        $phone_hash = createBlindHash($clean_phone);
        $enc_phone = encryptData($clean_phone);
        
        $stmt = $conn->prepare("UPDATE leads SET phone = ?, phone_hash = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("ssi", $enc_phone, $phone_hash, $id);
        if ($stmt->execute()) echo "Saved"; else echo "Error";
        $stmt->close();
        exit;
    }

    // ==========================================
    // 6. VERTICAL HASH SYNC
    // ==========================================
    if ($col === 'vertical') {
        $case_hash = createBlindHash($val);
        $stmt = $conn->prepare("UPDATE leads SET vertical = ?, case_hash = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("ssi", $val, $case_hash, $id);
        
        if ($stmt->execute()) echo "Saved"; else echo "Error";
        $stmt->close();
        exit();
    }

    
    // 6. ROLE MAPPING Logic
    if (in_array($col, ['agent_name', 'closer_name', 'verifier_name'])) {
        $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("si", $save_val, $id);
        $stmt->execute();
        $stmt->close();
        
        $role_target = ucfirst(explode('_', $col)[0]);
        $id_col = strtolower($role_target) . '_id';

        if (!empty($val)) {
            $role_query = $conn->prepare("SELECT id FROM users WHERE username = ? AND role = ? LIMIT 1");
            $role_query->bind_param("ss", $val, $role_target);
            $role_query->execute();
            $role_res = $role_query->get_result();
            
            if ($role_res->num_rows > 0) {
                $user_id = $role_res->fetch_assoc()['id'];
                $id_update = $conn->prepare("UPDATE leads SET `$id_col` = ? $freeze_timestamps WHERE id = ?");
                $id_update->bind_param("ii", $user_id, $id);
                $id_update->execute();
                $id_update->close();
            } else {
                mysqli_query($conn, "UPDATE leads SET `$id_col` = NULL $freeze_timestamps WHERE id = $id");
            }
            $role_query->close();
        } else {
            mysqli_query($conn, "UPDATE leads SET `$id_col` = NULL $freeze_timestamps WHERE id = $id");
        }
        
        echo "Saved";
        exit;
    } 
    
    // 7. STANDARD UPDATE FOR EVERYTHING ELSE
    if ($col === 'created_at') { 
        $parsed_time = strtotime($save_val); 
        if ($parsed_time) $save_val = date('Y-m-d H:i:s', $parsed_time); 
    }

    $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
    $stmt->bind_param("si", $save_val, $id);
    if ($stmt->execute()) echo "Saved"; else echo "Error";
    $stmt->close();
}
?>