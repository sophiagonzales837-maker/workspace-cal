<?php
// FILE: workspace/agent/api_update_status.php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// Allow both Agents and Closers (and Admins for testing)
$allowed_roles = ['Agent', 'Closer', 'Admin', 'Jr. Admin', 'Jr_Admin', 'Jr Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    http_response_code(401);
    exit("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = (int)$_POST['lead_id'];
    $status = trim($_POST['status'] ?? '');
    
    if ($lead_id === 0 || empty($status)) {
        http_response_code(400);
        exit("Invalid Data: Missing Lead ID or Status");
    }

    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'User';
    
    // ==========================================
    // 1. HANDLE CALLBACK (CB)
    // ==========================================
    if ($status === 'CB') {
        $cb_time_raw = $_POST['callback_time'] ?? '';
        $timezone = $_POST['timezone'] ?? 'America/New_York';
        
        if (!empty($cb_time_raw)) {
            try {
                // Take the customer's time and convert it to the Server's base timezone
                $dt = new DateTime($cb_time_raw, new DateTimeZone($timezone));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get())); // Auto-detects server timezone
                $server_cb_time = $dt->format('Y-m-d H:i:s');
                
                // Update BOTH time columns for compatibility, and arm the popup trigger (cb_notified = 0)
                $stmt = $conn->prepare("UPDATE leads SET status = ?, closer_status = ?, callback_time = ?, reminder_time = ?, cb_notified = 0, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssi", $status, $status, $server_cb_time, $server_cb_time, $lead_id);
                $stmt->execute();
                $stmt->close();
                
                // Log the action in the Live Chat / History with the timezone they picked
                $display_time = $dt->format('M d, Y h:i A');
                $msg = "System: $username scheduled a Callback for $display_time ($timezone).";
                $conn->query("INSERT INTO messages (lead_id, sender_id, content) VALUES ($lead_id, $user_id, '$msg')");
                
                echo "Success";
            } catch (Exception $e) {
                http_response_code(500);
                echo "Timezone Error: " . $e->getMessage();
            }
        } else {
            http_response_code(400);
            echo "Missing callback time.";
        }
        exit;
    }
    
    // ==========================================
    // 2. HANDLE RETRANSFERS
    // ==========================================
    if ($status === 'Retransfer') {
        $retransfers = (int)($_POST['retransfer_count'] ?? 0);
        
        $stmt = $conn->prepare("UPDATE leads SET status = ?, closer_status = ?, retransfer_count = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssii", $status, $status, $retransfers, $lead_id);
        $stmt->execute();
        $stmt->close();
        
        $msg = "System: $username marked lead as Retransfer (Total Transfers: $retransfers).";
        $conn->query("INSERT INTO messages (lead_id, sender_id, content) VALUES ($lead_id, $user_id, '$msg')");
        
        echo "Success";
        exit;
    }
    
    // ==========================================
    // 3. HANDLE STANDARD STATUSES (VM, NI, New)
    // ==========================================
    $stmt = $conn->prepare("UPDATE leads SET status = ?, closer_status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $status, $status, $lead_id);
    $stmt->execute();
    $stmt->close();
    
    $msg = "System: $username updated status to $status.";
    $conn->query("INSERT INTO messages (lead_id, sender_id, content) VALUES ($lead_id, $user_id, '$msg')");
    
    echo "Success";
    exit;
} else {
    http_response_code(405);
    exit("Method Not Allowed");
}
?>