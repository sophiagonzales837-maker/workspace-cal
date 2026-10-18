<?php
// FILE: agent/../api/v1/inhouse_chat_api.php
session_start();
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Agent');

header('Content-Type: application/json');

// 1. SEND MESSAGE (Agent to Closer or Verifier)
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $recipient_id = (int)($_POST['recipient_id'] ?? 0);
    $recipient_type = trim($_POST['recipient_type'] ?? ''); // 'Closer' or 'Verifier'
    $message = trim($_POST['message'] ?? '');
    $agent_id = $_SESSION['user_id'];
    
    if(empty($message) || $lead_id == 0 || $recipient_id == 0 || !in_array($recipient_type, ['Closer', 'Verifier'])) {
        echo json_encode(['success' => false, 'error' => 'Missing or invalid data']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, agent_id, recipient_id, recipient_type, message, sender_type, is_read) 
                            VALUES (?, ?, ?, ?, ?, 'Agent', 0)");
    $stmt->bind_param("iisis", $lead_id, $agent_id, $recipient_id, $recipient_type, $message);
    
    if($stmt->execute()) {
        // Create notification for recipient
        $notif_msg = "Agent sent you an inhouse message";
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, notification_type, related_id, title, message) 
                                      VALUES (?, 'inhouse_chat', ?, 'New Inhouse Message', ?)");
        $notif_stmt->bind_param("iis", $recipient_id, $lead_id, $notif_msg);
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
if(isset($_GET['action']) && $_GET['action'] == 'get' && isset($_GET['lead_id']) && isset($_GET['recipient_id'])) {
    $lead_id = (int)$_GET['lead_id'];
    $recipient_id = (int)$_GET['recipient_id'];
    $agent_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("SELECT * FROM inhouse_chats 
                            WHERE lead_id = ? AND agent_id = ? AND recipient_id = ?
                            ORDER BY created_at ASC");
    $stmt->bind_param("iii", $lead_id, $agent_id, $recipient_id);
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

// 3. MARK MESSAGES AS READ
if(isset($_GET['action']) && $_GET['action'] == 'mark_read' && isset($_GET['lead_id'])) {
    $lead_id = (int)$_GET['lead_id'];
    $agent_id = $_SESSION['user_id'];
    
    $stmt = $conn->prepare("UPDATE inhouse_chats SET is_read = 1 
                            WHERE lead_id = ? AND agent_id = ? AND sender_type IN ('Closer', 'Verifier')");
    $stmt->bind_param("ii", $lead_id, $agent_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
?>
