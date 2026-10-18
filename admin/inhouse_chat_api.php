<?php
// FILE: admin/../api/v1/inhouse_chat_api.php
session_start();
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin');

header('Content-Type: application/json');

// 1. SEND MESSAGE (Admin to Closer)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $closer_id = (int)($_POST['closer_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $admin_id = $_SESSION['user_id'];
    
    if(empty($message) || $lead_id == 0 || $closer_id == 0) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, admin_id, closer_id, message, sender_type, is_read) 
                            VALUES (?, ?, ?, ?, 'Admin', 0)");
    $stmt->bind_param("iiis", $lead_id, $admin_id, $closer_id, $message);
    
    if($stmt->execute()) {
        // FIXED: Matched exact columns in the notifications table to prevent SQL crash
        $notif_msg = "Admin sent you an inhouse message regarding Lead #$lead_id";
        $notif_link = "posted_leads.php";
        
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        $notif_stmt->bind_param("iss", $closer_id, $notif_msg, $notif_link);
        $notif_stmt->execute();
        $notif_stmt->close();
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    $stmt->close();
    exit;
}

// 2. GET MESSAGES (Fetch chat history)
if(isset($_GET['action']) && $_GET['action'] == 'get' && isset($_GET['lead_id']) && isset($_GET['closer_id'])) {
    $lead_id = (int)$_GET['lead_id'];
    $closer_id = (int)$_GET['closer_id'];
    $admin_id = $_SESSION['user_id'];
    
    // Mark messages from Closer as read
    $conn->query("UPDATE inhouse_chats SET is_read = 1 WHERE lead_id = $lead_id AND closer_id = $closer_id AND sender_type = 'Closer'");

    $stmt = $conn->prepare("SELECT * FROM inhouse_chats WHERE lead_id = ? AND closer_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("ii", $lead_id, $closer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    echo json_encode($messages);
    $stmt->close();
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>