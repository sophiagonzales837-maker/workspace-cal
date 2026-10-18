<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once __DIR__ . '/includes/c2c_sync.php';
require_once '../shared/smart_sync.php';
protect('Closer');

// ========================================================================
// CRITICAL FIX: RELEASE SESSION LOCK FOR RAPID PARALLEL EDITING
// ========================================================================
session_write_close();

function workspace_trigger_lead_event($event, array $payload)
{
    $pusher = workspace_pusher_server();
    if ($pusher) {
        try {
            $pusher->trigger('lead-updates-channel', $event, $payload);
        } catch (Exception $e) {
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['id'];
    $col = trim($_POST['col'] ?? '');
    $val = trim($_POST['val'] ?? '');

    if ($id <= 0 || empty($col)) die("Invalid request");

    // 1. EXPANDED SECURITY WHITELIST (Matches Admin to prevent 400 Errors)
    $allowed_cols = [
        'vertical',
        'first_name',
        'middle_name',
        'last_name',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip_code',
        'dob',
        'status',
        'admin_status',
        'application_text',
        'questions',
        'agent_name',
        'closer_name',
        'verifier_name',
        'client_name',
        'customer_id',
        'payouts',
        'incentive',
        'created_at',
        'quick_update',
        'referred_by',
        'message',
        'jornaya_leadid',
        'trustedform_cert_url',
        'ip_address',
        'closer_status',
        'alternate_details',
        'customer_name',
        'updated_at'
    ];

    if (!in_array($col, $allowed_cols)) {
        http_response_code(400);
        exit("Invalid Column: " . htmlspecialchars($col));
    }

    $smart_sync_changes = workspace_prepare_lead_sync_changes($conn, $id, [$col => $val]);

    // 2. TIMESTAMP FREEZE LOGIC
    $freeze_timestamps = ", updated_at = updated_at";
    if ($col === 'updated_at') $freeze_timestamps = "";

    // 3. ENCRYPTION ENGINE
    $encrypted_fields = ['first_name', 'middle_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'client_name', 'questions', 'application_text'];
    $save_val = $val;
    if (in_array($col, $encrypted_fields) && !empty($val)) {
        $save_val = encryptData($val);
    }

    // ==========================================
    // -> AUTOMATION ENGINE: CLOSER STATUS SPECIFIC UPDATES
    // ==========================================
    if ($col === 'closer_status') {
        if (strcasecmp($val, 'Revised') === 0) {
            $stmt = $conn->prepare("UPDATE leads SET closer_status = ?, status = 'Pending Verification', verifier_id = NULL $freeze_timestamps WHERE id = ?");
            $stmt->bind_param("si", $save_val, $id);
            $save_succeeded = $stmt->execute();
            $stmt->close();

            $vq = $conn->query("SELECT verifier_id, customer_name FROM leads WHERE id = $id");
            if ($vq && $vrow = $vq->fetch_assoc()) {
                $c_name = decryptData($vrow['customer_name']);
                if (!empty($vrow['verifier_id'])) {
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, link) VALUES ({$vrow['verifier_id']}, 'Lead $c_name was Revised by Closer and sent back for Reverification.', '#')");
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE leads SET closer_status = ?, quick_update = ? $freeze_timestamps WHERE id = ?");
            $stmt->bind_param("ssi", $save_val, $save_val, $id);
            $save_succeeded = $stmt->execute();
            $stmt->close();

            $aq = $conn->query("SELECT agent_id, customer_name FROM leads WHERE id = $id");
            if ($aq && $arow = $aq->fetch_assoc()) {
                $c_name = decryptData($arow['customer_name']);
                if (!empty($arow['agent_id'])) {
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, link) VALUES ({$arow['agent_id']}, 'Status updated to $val for Lead: $c_name', '#')");
                }
            }
        }

        $pq = $conn->query("SELECT status, admin_status, customer_name FROM leads WHERE id = $id");
        if ($pq && $prow = $pq->fetch_assoc()) {
            if ($prow['admin_status'] === 'Approved' || in_array($prow['status'], ['NU', 'Paid'])) {
                $c_name = decryptData($prow['customer_name']);
                $admins = mysqli_query($conn, "SELECT id FROM users WHERE role = 'Admin'");
                while ($adm = mysqli_fetch_assoc($admins)) {
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, link) VALUES ({$adm['id']}, 'Closer updated Posted Lead ($c_name) status to: $val', 'leads.php')");
                }
            }
        }
        if ($save_succeeded) {
            workspace_send_lead_sync_update($id, $smart_sync_changes);
            workspace_sync_c2c_lead_update($conn, $id, $col);
        }
        echo "Saved";
        exit;
    }

    // ==========================================
    // 4. ROUTING: MULTI-CLIENT (C10/C12 & DNQ/Doc)
    // ==========================================
    if ($col === 'client_name' || $col === 'status') {
        $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("si", $save_val, $id);
        $save_succeeded = $stmt->execute();
        $stmt->close();

        if ($save_succeeded) {
            workspace_send_lead_sync_update($id, $smart_sync_changes);
            workspace_sync_c2c_lead_update($conn, $id, $col);
        }
        echo "Saved";
        exit;
    }

    // ==========================================
    // 5. PHONE NUMBER & HASH SYNC 
    // ==========================================
    if ($col === 'phone') {
        $clean_phone = preg_replace('/[^\d+]/', '', $val);
        if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
        $phone_hash = createBlindHash($clean_phone);
        $enc_phone = encryptData($clean_phone);

        $stmt = $conn->prepare("UPDATE leads SET phone = ?, phone_hash = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("ssi", $enc_phone, $phone_hash, $id);
        if ($stmt->execute()) {
            workspace_send_lead_sync_update($id, $smart_sync_changes);
            workspace_sync_c2c_lead_update($conn, $id, $col);
            echo "Saved";
        } else {
            echo "Error";
        }
        $stmt->close();
        exit;
    }

    // ==========================================
    // 6. VERTICAL HASH SYNC
    // ==========================================
    if ($col === 'vertical') {
        $status_stmt = $conn->prepare("SELECT status FROM leads WHERE id = ? LIMIT 1");
        if (!$status_stmt) {
            http_response_code(500);
            exit("Error");
        }
        $status_stmt->bind_param("i", $id);
        if (!$status_stmt->execute()) {
            $status_stmt->close();
            http_response_code(500);
            exit("Error");
        }
        $current_status = $status_stmt->get_result()->fetch_assoc()['status'] ?? null;
        $status_stmt->close();
        $set_under_process = $val !== '' && $current_status === 'New';

        if ($set_under_process) {
            $smart_sync_changes = workspace_prepare_lead_sync_changes($conn, $id, [
                'vertical' => $val,
                'quick_update' => 'Under Process',
            ]);
            $smart_sync_changes['vertical'] = $val;
            $smart_sync_changes['quick_update'] = 'Under Process';
        }

        $case_hash = createBlindHash($val);
        $set_under_process_flag = $set_under_process ? 1 : 0;
        $stmt = $conn->prepare("UPDATE leads SET vertical = ?, case_hash = ?, quick_update = IF(status = 'New' AND ? = 1, 'Under Process', quick_update) $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("ssii", $val, $case_hash, $set_under_process_flag, $id);

        if ($stmt->execute()) {
            workspace_send_lead_sync_update($id, $smart_sync_changes);
            workspace_sync_c2c_lead_update($conn, $id, $col);
            echo "Saved";
        } else {
            echo "Error";
        }
        $stmt->close();
        exit();
    }

    // ==========================================
    // 7. ROLE MAPPING Logic
    // ==========================================
    if (in_array($col, ['agent_name', 'closer_name', 'verifier_name'])) {
        $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("si", $save_val, $id);
        $save_succeeded = $stmt->execute();
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

        if ($save_succeeded) {
            workspace_send_lead_sync_update($id, $smart_sync_changes);
            workspace_sync_c2c_lead_update($conn, $id, $col);
        }

        echo "Saved";
        exit;
    }

    // ==========================================
    // 8. STANDARD UPDATE FOR EVERYTHING ELSE
    // ==========================================
    if ($col === 'created_at') {
        $parsed_time = strtotime($save_val);
        if ($parsed_time) $save_val = date('Y-m-d H:i:s', $parsed_time);
    }

    $stmt = $conn->prepare("UPDATE leads SET `$col` = ? $freeze_timestamps WHERE id = ?");
    $stmt->bind_param("si", $save_val, $id);

    if ($stmt->execute()) {
        workspace_send_lead_sync_update($id, $smart_sync_changes);
        workspace_sync_c2c_lead_update($conn, $id, $col);
        echo "Saved";
    } else {
        echo "Error";
    }
    $stmt->close();
}
