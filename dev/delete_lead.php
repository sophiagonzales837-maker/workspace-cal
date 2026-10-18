<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect('Dev');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    // Grab the phone_hash before deleting so we can sync the deletion to the live server
    $phone_hash = null;
    $q = $conn->query("SELECT phone_hash FROM leads WHERE id = $id");
    if ($q && $row = $q->fetch_assoc()) {
        $phone_hash = $row['phone_hash'];
    }

    $stmt = $conn->prepare("DELETE FROM leads WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        // Also clear out related messages to keep DB clean
        $conn->query("DELETE FROM messages WHERE lead_id = $id");
        $conn->query("DELETE FROM inhouse_chats WHERE lead_id = $id");

        // Sync the deletion to the remote CRM
        if ($phone_hash) {
            $payload = [
                'auth_key' => 'MakeMoney$$',
                'phone_hash' => $phone_hash,
                'action' => 'delete'
            ];
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

        echo "Deleted";
    } else {
        echo "Error";
    }
    $stmt->close();
}
