<?php
// FILE: workspace/shared/team_chat_api.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../config/db.php';
require_once '../config/pusher.php';

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    workspace_close_db_connections();
    exit;
}

$my_id = (int)$_SESSION['user_id'];
session_write_close(); 

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==========================================
// 1. FETCH USERS (Includes Unread Badge Counting!)
// ==========================================
if ($action === 'get_users') {
    // This fetches all active users AND counts how many unread messages they have sent YOU.
    $query = "SELECT u.id, u.username, u.status_state, 
              (SELECT COUNT(id) FROM team_chats WHERE sender_id = u.id AND receiver_id = $my_id AND is_read = 0) as unread_count
              FROM users u WHERE u.id != $my_id AND u.status = 'active' AND u.role != 'Verifier'";
    $result = mysqli_query($conn, $query);
    
    $users = [];
    $total_unread = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $state = ucfirst(strtolower($row['status_state'] ?? 'Offline'));
        if (!in_array($state, ['Active', 'Break'])) { $state = 'Offline'; }
        
        $unread = (int)$row['unread_count'];
        $total_unread += $unread;
        
        $users[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'status' => strtolower($state),
            'unread' => $unread
        ];
    }
    
    // Sort the list so people with Unread Messages float to the very top!
    usort($users, function($a, $b) {
        if ($a['unread'] !== $b['unread']) return $b['unread'] - $a['unread'];
        if ($a['status'] === 'active' && $b['status'] !== 'active') return -1;
        if ($b['status'] === 'active' && $a['status'] !== 'active') return 1;
        return strcmp($a['username'], $b['username']);
    });
    
    echo json_encode(['status' => 'success', 'users' => $users, 'my_id' => $my_id, 'total_unread' => $total_unread]);
    workspace_close_db_connections();
    exit;
}

// ==========================================
// 2. FETCH 1-ON-1 CONVERSATION (WhatsApp Style)
// ==========================================
if ($action === 'get_conversation') {
    $other_id = (int)($_GET['other_id'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    // Since you just opened their chat, instantly mark all their messages to you as READ!
    mysqli_query($conn, "UPDATE team_chats SET is_read = 1 WHERE sender_id = $other_id AND receiver_id = $my_id");
    
    // Fetch the 5 most recent messages between YOU and THEM
    $query = "SELECT tc.*, u.username as sender_name FROM team_chats tc 
              JOIN users u ON tc.sender_id = u.id 
              WHERE (tc.sender_id = $my_id AND tc.receiver_id = $other_id) 
                 OR (tc.sender_id = $other_id AND tc.receiver_id = $my_id) 
              ORDER BY tc.created_at DESC LIMIT 5 OFFSET $offset";
    
    $result = mysqli_query($conn, $query);
    $messages = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $messages[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['sender_name'],
            'message' => htmlspecialchars($row['message']),
            'attachment' => $row['attachment_path'] ? '/' . $row['attachment_path'] : null,
            'is_me' => ($row['sender_id'] == $my_id),
            'time' => date('h:i A', strtotime($row['created_at']))
        ];
    }
    // Reverse the array so the oldest message is at the top of the screen
    echo json_encode(['status' => 'success', 'messages' => array_reverse($messages)]);
    workspace_close_db_connections();
    exit;
}

// ==========================================
// 3. SEND MESSAGE (Handles both 1-on-1 and Broadcasts)
// ==========================================
if ($action === 'send_message') {
    $receivers = json_decode($_POST['receivers'] ?? '[]');
    $raw_message = trim($_POST['message'] ?? '');
    
    if (empty($receivers) || empty($raw_message)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select at least one user and enter a message.']);
        exit;
    }
    
    $message = mysqli_real_escape_string($conn, $raw_message);
    $attachment_path = NULL;
    
    // Handle File Uploads
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/team_chats/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', $_FILES['attachment']['name']);
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $filename)) {
            $attachment_path = 'workspace/uploads/team_chats/' . $filename;
        }
    }
    
    $sender_q = mysqli_query($conn, "SELECT username FROM users WHERE id = $my_id LIMIT 1");
    $sender_row = mysqli_fetch_assoc($sender_q);
    $sender_name = $sender_row['username'] ?? 'Team';
    $timestamp = date('Y-m-d H:i:s');
    
    $pusher = function_exists('workspace_pusher_server') ? workspace_pusher_server() : null;
    $receiver_ids = [];
    $target_channels = []; 

    // Loop through all selected users and save the message to the database
    foreach ($receivers as $rid) {
        $rid = (int)$rid;
        $file_sql = $attachment_path ? "'$attachment_path'" : "NULL";
        // is_read = 0 ensures it shows up as an Unread Badge for them!
        mysqli_query($conn, "INSERT INTO team_chats (sender_id, receiver_id, message, attachment_path, is_read) VALUES ($my_id, $rid, '$message', $file_sql, 0)");
        $receiver_ids[] = $rid;
        $target_channels[] = 'user-' . $rid . '-channel'; 
    }

    // Trigger Real-time Notifications via Pusher
    if ($pusher && count($target_channels) > 0) {
        try {
            $pusher->trigger($target_channels, 'new-message-event', [
                'sender_id' => $my_id,
                'username' => $sender_name,
                'message' => $raw_message,
                'attachment' => $attachment_path ? '/' . $attachment_path : null,
                'timestamp' => $timestamp
            ]);
        } catch (\Throwable $e) { /* Ignore Pusher crash to keep DB running */ }
    }
    
    echo json_encode(['status' => 'success', 'msg_obj' => [
        'message' => htmlspecialchars($raw_message),
        'attachment' => $attachment_path ? '/' . $attachment_path : null,
        'time' => date('h:i A')
    ]]);
    workspace_close_db_connections();
    exit;
}

// ==========================================
// 4. FETCH HISTORY (For the global History Tab)
// ==========================================
if ($action === 'get_history') {
    $query = "SELECT tc.id, tc.message, tc.created_at, tc.attachment_path, tc.sender_id, tc.receiver_id, u_sender.username as sender_name, u_receiver.username as receiver_name 
              FROM team_chats tc 
              JOIN users u_sender ON tc.sender_id = u_sender.id 
              LEFT JOIN users u_receiver ON tc.receiver_id = u_receiver.id 
              WHERE tc.sender_id = $my_id OR tc.receiver_id = $my_id OR tc.receiver_id IS NULL 
              ORDER BY tc.created_at DESC LIMIT 50";
    $result = mysqli_query($conn, $query);
    $history = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $history[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['sender_name'],
            'receiver_id' => $row['receiver_id'],
            'receiver_name' => $row['receiver_name'] ?: 'Global Group',
            'message' => htmlspecialchars($row['message']),
            'attachment' => $row['attachment_path'] ? '/' . $row['attachment_path'] : null,
            'created_at' => $row['created_at'], 
            'display_time' => date('M d, h:i A', strtotime($row['created_at']))
        ];
    }
    echo json_encode(['status' => 'success', 'history' => $history]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
exit;
?>