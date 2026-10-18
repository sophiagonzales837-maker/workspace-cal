<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/pusher.php';
protect('Admin');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $lead_id = (int)$_POST['lead_id'];
    $action = (string)($_POST['action'] ?? 'post');

    if ($action === 'reverify') {
        $verifier_id = (int)($_POST['verifier_id'] ?? 0);
        $verifier_stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'Verifier' AND status_state = 'Active' LIMIT 1");
        $verifier_stmt->bind_param('i', $verifier_id);
        $verifier_stmt->execute();
        $verifier = $verifier_stmt->get_result()->fetch_assoc();
        $verifier_stmt->close();

        if ($lead_id <= 0 || !$verifier) {
            workspace_close_db_connections();
            header('Location: to_post.php?error=verifier');
            exit;
        }

        $reverify_stmt = $conn->prepare("UPDATE leads SET status = 'Pending Verification', verifier_id = ?, verifier_name = ?, closer_status = NULL, updated_at = NOW() WHERE id = ? AND status IN ('NU', 'NU/ID Uploaded', 'Pending Post') AND (admin_status IS NULL OR admin_status != 'Approved')");
        $reverify_stmt->bind_param('isi', $verifier_id, $verifier['username'], $lead_id);
        $reverified = $reverify_stmt->execute() && $reverify_stmt->affected_rows === 1;
        $reverify_stmt->close();

        if ($reverified) {
            $pusher = workspace_pusher_server();
            if ($pusher) {
                try {
                    $pusher->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id]);
                } catch (Exception $e) {
                    error_log('[Admin reverify] Pusher refresh failed: ' . $e->getMessage());
                }
            }
            workspace_close_db_connections();
            header('Location: to_post.php?success=reverify');
        } else {
            workspace_close_db_connections();
            header('Location: to_post.php?error=unavailable');
        }
        exit;
    }

    if ($action !== 'post') {
        http_response_code(400);
        workspace_close_db_connections();
        die('Error: Invalid action.');
    }

    $client_name = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';

    if (empty($client_name)) die("Error: Invalid client name.");

    $enc_client_name = encryptData($client_name);
    $stmt = $conn->prepare("UPDATE leads SET client_name = ?, admin_status = 'Approved', status = 'Posted', updated_at = NOW() WHERE id = ? AND status IN ('NU', 'NU/ID Uploaded', 'Pending Post') AND (admin_status IS NULL OR admin_status != 'Approved')");
    $stmt->bind_param("si", $enc_client_name, $lead_id);

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        workspace_close_db_connections();
        die("Error: This lead is no longer in the NU queue or could not be posted.");
    }

    $stmt->close();
    $sync_stmt = $conn->prepare('SELECT id, phone_hash, phone, vertical, case_type FROM leads WHERE id = ?');
    $sync_stmt->bind_param('i', $lead_id);
    $sync_stmt->execute();
    $sync_lead = $sync_stmt->get_result()->fetch_assoc();
    $sync_stmt->close();
    if ($sync_lead) $sync_lead = decrypt_fields($sync_lead);

    $live_sync_ok = false;
    if ($sync_lead) {
        $sync_payload = json_encode([
            'id' => (int)$sync_lead['id'],
            'phone_hash' => (string)($sync_lead['phone_hash'] ?? ''),
            'phone' => (string)($sync_lead['phone'] ?? ''),
            'vertical' => (string)($sync_lead['vertical'] ?? ''),
            'case_type' => (string)($sync_lead['case_type'] ?? ''),
            'status' => 'Posted',
            'auth_key' => 'MakeMoney$$'
        ]);
        $sync_ch = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
        curl_setopt($sync_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($sync_ch, CURLOPT_POST, true);
        curl_setopt($sync_ch, CURLOPT_POSTFIELDS, $sync_payload);
        curl_setopt($sync_ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($sync_ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($sync_ch, CURLOPT_TIMEOUT, 5);
        $sync_response = curl_exec($sync_ch);
        $sync_http_status = (int)curl_getinfo($sync_ch, CURLINFO_HTTP_CODE);
        $sync_result = json_decode((string)$sync_response, true);
        $live_sync_ok = $sync_response !== false && $sync_http_status >= 200 && $sync_http_status < 300 && ($sync_result['status'] ?? '') === 'success';
        if (!$live_sync_ok) {
            error_log('[Posted lead webhook] Live status sync failed: ' . curl_error($sync_ch) . ' (HTTP ' . $sync_http_status . ')');
        }
        curl_close($sync_ch);
    }

    $res = $conn->prepare('SELECT closer_id FROM leads WHERE id = ?');
    $res->bind_param('i', $lead_id);
    $res->execute();
    $closer_id = $res->get_result()->fetch_assoc()['closer_id'] ?? 0;
    $res->close();
    if ($closer_id) {
        notify($closer_id, "Lead #$lead_id has been successfully posted to Client: $client_name", "manage_lead.php?id=$lead_id", $conn);
    }

    $pusher = workspace_pusher_server();
    if ($pusher) {
        $pusher->trigger('global-updates', 'lead-updated', [
            'message' => 'A lead was just updated or added!'
        ]);
    }

    session_write_close();
    workspace_close_db_connections();
    header('Location: to_post.php?success=1' . ($live_sync_ok ? '' : '&sync_error=1'));

    exit();
}
