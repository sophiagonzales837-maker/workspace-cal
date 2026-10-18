<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once __DIR__ . '/includes/c2c_sync.php';
require_once '../shared/smart_sync.php';
protect('Closer');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = (int)$_POST['id'];
    $col = trim($_POST['col']);
    $val = trim($_POST['val']);

    // 1. STRICT COLUMN WHITELIST (Expanded for full Closer access)
    $valid_columns = [
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
        'application_text',
        'questions',
        'quick_update',
        'client_name',
        'referred_by',
        'closer_status',
        'vertical',
        'message'
    ];

    if (!in_array($col, $valid_columns)) {
        http_response_code(400);
        exit("Invalid Column: " . htmlspecialchars($col));
    }

    $smart_sync_changes = workspace_prepare_lead_sync_changes($conn, $id, [$col => $val]);

    $sensitive_fields = ['first_name', 'middle_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'application_text', 'questions', 'quick_update', 'client_name', 'referred_by'];

    $freeze_timestamps = ", updated_at = updated_at";
    if ($col === 'updated_at') $freeze_timestamps = "";

    // Encryption Check
    $save_val = $val;
    if (in_array($col, $sensitive_fields) && !empty($val)) {
        $save_val = encryptData($val);
    }

    // -> AUTOMATION ENGINE: CLOSER STATUS UPDATES
    if ($col === 'closer_status') {

        // RULE: If status is 'Revised', push to Verifier
        if (strcasecmp($val, 'Revised') === 0) {
            $stmt = $conn->prepare("UPDATE leads SET closer_status = ?, status = 'Pending Verification', verifier_id = NULL $freeze_timestamps WHERE id = ?");
            $stmt->bind_param("si", $save_val, $id);
            $save_succeeded = $stmt->execute();
            $stmt->close();

            // Notify Verifier
            $vq = $conn->query("SELECT verifier_id, customer_name FROM leads WHERE id = $id");
            if ($vq && $vrow = $vq->fetch_assoc()) {
                $c_name = decryptData($vrow['customer_name']);
                if (!empty($vrow['verifier_id'])) {
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, link) VALUES ({$vrow['verifier_id']}, 'Lead $c_name was Revised by Closer and sent back for Reverification.', '#')");
                }
            }
        } else {
            // RULE: Sync Closer Status to Agent's 'quick_update'
            $stmt = $conn->prepare("UPDATE leads SET closer_status = ?, quick_update = ? $freeze_timestamps WHERE id = ?");
            $stmt->bind_param("ssi", $save_val, $save_val, $id);
            $save_succeeded = $stmt->execute();
            $stmt->close();

            // Notify Agent
            $aq = $conn->query("SELECT agent_id, customer_name FROM leads WHERE id = $id");
            if ($aq && $arow = $aq->fetch_assoc()) {
                $c_name = decryptData($arow['customer_name']);
                if (!empty($arow['agent_id'])) {
                    mysqli_query($conn, "INSERT INTO notifications (user_id, message, link) VALUES ({$arow['agent_id']}, 'Status updated to $val for Lead: $c_name', '#')");
                }
            }
        }

        // RULE: Notify Admin if Closer is editing a Posted Lead
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

    // ========================================================
    // CRITICAL FIX: PHONE NUMBER & HASH SYNC
    // ========================================================
    if ($col === 'phone') {
        $clean_phone = preg_replace('/[^\d+]/', '', $val);
        if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) {
            $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
        }

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
        exit();
    }

    // ========================================================
    // CRITICAL FIX: VERTICAL HASH SYNC
    // ========================================================
    if ($col === 'vertical') {
        $case_hash = createBlindHash($val);
        $stmt = $conn->prepare("UPDATE leads SET vertical = ?, case_hash = ? $freeze_timestamps WHERE id = ?");
        $stmt->bind_param("ssi", $val, $case_hash, $id);

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

    // ========================================================
    // STANDARD UPDATE & SILENT QUESTIONS WEBHOOK
    // ========================================================
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
