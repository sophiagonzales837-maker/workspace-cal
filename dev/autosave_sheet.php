<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect('Dev');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = (int)$_POST['id'];
    $col = $_POST['col'];
    $val = trim($_POST['val']);

    // Core encrypted fields
    $sensitive_fields = ['first_name', 'middle_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'application_text', 'questions', 'quick_update', 'client_name', 'referred_by'];

    // Smart Linking (Agent, Closer, Verifier)
    $smart_roles = [
        'agent_name' => 'agent_id',
        'closer_name' => 'closer_id',
        'verifier_name' => 'verifier_id'
    ];

    if (array_key_exists($col, $smart_roles)) {
        $id_col = $smart_roles[$col];

        $u_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $u_stmt->bind_param("s", $val);
        $u_stmt->execute();
        $u_res = $u_stmt->get_result();

        if ($u_res->num_rows > 0) {
            $user_id = $u_res->fetch_assoc()['id'];
            $stmt = $conn->prepare("UPDATE leads SET `$col` = ?, `$id_col` = ? WHERE id = ?");
            $stmt->bind_param("sii", $val, $user_id, $id);
        } else {
            $stmt = $conn->prepare("UPDATE leads SET `$col` = ?, `$id_col` = NULL WHERE id = ?");
            $stmt->bind_param("si", $val, $id);
        }

        if ($stmt->execute()) {
            triggerWebhookSync($id, $col, $val);
            echo "Saved";
        }
        $stmt->close();
        $u_stmt->close();
        exit();
    }

    // Phone formatting & hash sync
    if ($col === 'phone') {
        $clean_phone = preg_replace('/[^\d+]/', '', $val);
        if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
        $phone_hash = function_exists('createBlindHash') ? createBlindHash($clean_phone) : md5($clean_phone);

        $enc_phone = function_exists('encryptData') ? encryptData($clean_phone) : $clean_phone;

        $stmt = $conn->prepare("UPDATE leads SET phone = ?, phone_hash = ? WHERE id = ?");
        $stmt->bind_param("ssi", $enc_phone, $phone_hash, $id);
        if ($stmt->execute()) {
            triggerWebhookSync($id, 'phone', $clean_phone);
            triggerWebhookSync($id, 'phone_hash', $phone_hash);
            echo "Saved";
        } else {
            echo "Error";
        }
        $stmt->close();
        exit;
    }

    // Vertical hash sync
    if ($col === 'vertical') {
        $case_hash = function_exists('createBlindHash') ? createBlindHash($val) : md5($val);

        $stmt = $conn->prepare("UPDATE leads SET vertical = ?, case_hash = ? WHERE id = ?");
        $stmt->bind_param("ssi", $val, $case_hash, $id);

        if ($stmt->execute()) {
            triggerWebhookSync($id, 'vertical', $val);
            triggerWebhookSync($id, 'case_hash', $case_hash);
            echo "Saved";
        } else {
            echo "Error";
        }
        $stmt->close();
        exit;
    }

    // Standard Encryption handler
    $save_val = $val;
    if (in_array($col, $sensitive_fields) && !empty($val)) {
        if (function_exists('encryptData')) {
            $save_val = encryptData($val);
        }
    }

    // Standard update
    $stmt = $conn->prepare("UPDATE leads SET `$col` = ? WHERE id = ?");
    $stmt->bind_param("si", $save_val, $id);
    if ($stmt->execute()) {
        triggerWebhookSync($id, $col, $val); // Pass raw value to webhook
        echo "Saved";
    }
    $stmt->close();
}

// Function to sync changes to remote CRM
function triggerWebhookSync($id, $col, $val)
{
    global $conn;
    // Get the phone_hash to match the lead on the live server
    $q = $conn->query("SELECT phone_hash FROM leads WHERE id = $id");
    if ($q && $row = $q->fetch_assoc()) {
        $phone_hash = $row['phone_hash'];

        $payload = [
            'auth_key' => 'MakeMoney$$',
            'phone_hash' => $phone_hash,
            'updates' => [
                $col => $val
            ]
        ];

        // Replace with your live web-sync endpoint
        $ch_sync = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
        curl_setopt($ch_sync, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_sync, CURLOPT_POST, true);
        curl_setopt($ch_sync, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch_sync, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch_sync, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch_sync, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch_sync);
        curl_close($ch_sync);
    }
}
