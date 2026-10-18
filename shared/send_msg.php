<?php
ob_start(); // Prevents "headers already sent" errors
require_once '../config/db.php'; 
require_once '../config/security.php'; 
require_once '../config/pusher.php'; // Required for real-time alerts

// Security: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    session_write_close();
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['message'])) {
    $lead_id = (int)$_POST['lead_id'];
    $sender_id = $_SESSION['user_id'];
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    $role = $_SESSION['role'];

    // Insert message into the database permanently
    $query = "INSERT INTO messages (lead_id, sender_id, content) VALUES ('$lead_id', '$sender_id', '$message')";
    
    if (mysqli_query($conn, $query)) {
        // Fetch lead info for notification routing
        $lead_res = mysqli_query($conn, "SELECT customer_name, agent_id, closer_id FROM leads WHERE id = '$lead_id'");
        $lead = mysqli_fetch_assoc($lead_res);
        $name = $lead['customer_name'];

        // --- REAL-TIME DESKTOP ALERTS (PUSHER) ---
        $pusher = workspace_pusher_server();
        if ($pusher) {
            $pusher->trigger('lead-updates-channel', 'lead-note-added', [
                'lead_id' => $lead_id,
                'customer_id' => $name,
                'author' => $_SESSION['username'] ?? $role,
                'note_text' => htmlspecialchars($_POST['message'])
            ]);
        }
        // -----------------------------------------

        // Routing Logic (Database Notifications)
        if ($role == 'Verifier') {
            notify($lead['closer_id'], "Verifier feedback on: $name", "manage_lead.php?id=$lead_id", $conn);
        } 
        elseif ($role == 'Closer') {
            if (!empty($lead['agent_id'])) {
                notify($lead['agent_id'], "Update on lead: $name", "lead_comments.php?id=$lead_id", $conn);
            }
        } 
        elseif ($role == 'Agent') {
            notify($lead['closer_id'], "New Agent message on: $name", "manage_lead.php?id=$lead_id", $conn);
        }

        // Redirect back to the referring page seamlessly
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../index.php';
        session_write_close();
        workspace_close_db_connections();
        header("Location: " . $referer);
        exit();
    } else {
        workspace_close_db_connections();
        die("Query Failed: " . mysqli_error($conn));
    }
} else {
    session_write_close();
    workspace_close_db_connections();
    header("Location: ../index.php");
    exit();
}
?>