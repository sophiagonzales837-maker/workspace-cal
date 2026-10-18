<?php
// FILE: admin/client_chat_api.php
session_start();
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin');

if(isset($_GET['action']) && $_GET['action'] == 'get_clients') {
    $clients_arr = [];
    $q = mysqli_query($conn, "SELECT id, client_code FROM clients ORDER BY client_code ASC");
    while($c = mysqli_fetch_assoc($q)) {
        $cid = $c['id'];
        $unread_q = mysqli_query($conn, "SELECT COUNT(*) as unread FROM client_chats WHERE client_id='$cid' AND sender='Client' AND is_read=0 AND lead_id IS NULL");
        $c['unread'] = (int)mysqli_fetch_assoc($unread_q)['unread'];
        $clients_arr[] = $c;
    }
    header('Content-Type: application/json');
    echo json_encode($clients_arr);
    exit();
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['msg'])) {
    $client_id = (int)$_POST['client_id'];
    $lead_id = !empty($_POST['lead_id']) ? (int)$_POST['lead_id'] : null;
    $msg = trim($_POST['msg']);
    if(empty($msg)) exit();

    if ($lead_id) {
        $stmt = $conn->prepare("INSERT INTO client_chats (client_id, lead_id, sender, message) VALUES (?, ?, 'Admin', ?)");
        $stmt->bind_param("iis", $client_id, $lead_id, $msg);
    } else {
        $stmt = $conn->prepare("INSERT INTO client_chats (client_id, lead_id, sender, message) VALUES (?, NULL, 'Admin', ?)");
        $stmt->bind_param("is", $client_id, $msg);
    }
    $stmt->execute();
    $stmt->close();
    exit("Sent");
}

if(isset($_GET['client_id'])) {
    $client_id = (int)$_GET['client_id'];
    $lead_id = !empty($_GET['lead_id']) ? (int)$_GET['lead_id'] : null;
    
    if ($lead_id) {
        $conn->query("UPDATE client_chats SET is_read=1 WHERE client_id=$client_id AND lead_id=$lead_id AND sender='Client'");
        $stmt = $conn->prepare("SELECT * FROM client_chats WHERE client_id = ? AND lead_id = ? ORDER BY created_at ASC");
        $stmt->bind_param("ii", $client_id, $lead_id);
    } else {
        $conn->query("UPDATE client_chats SET is_read=1 WHERE client_id=$client_id AND lead_id IS NULL AND sender='Client'");
        $stmt = $conn->prepare("SELECT * FROM client_chats WHERE client_id = ? AND lead_id IS NULL ORDER BY created_at ASC");
        $stmt->bind_param("i", $client_id);
    }
    
    $stmt->execute();
    $msgs = $stmt->get_result();
    $stmt->close();
    
    if($msgs->num_rows == 0) { echo "<div style='text-align:center; color:#999; margin-top:50px;'>No messages yet.</div>"; }
    
    while($m = $msgs->fetch_assoc()) {
        $align = ($m['sender'] == 'Admin') ? 'text-align:right;' : 'text-align:left;';
        $bg = ($m['sender'] == 'Admin') ? '#cce5ff' : '#e2e3e5';
        $sender_label = ($m['sender'] == 'Admin') ? '' : '<small style="display:block; color:#888; font-size:10px; margin-bottom:2px;">Client</small>';
        $time = date('M d, g:i A', strtotime($m['created_at']));
        echo "<div style='$align margin-bottom:12px;'>$sender_label<span style='background:$bg; padding:8px 12px; border-radius:8px; display:inline-block; max-width:85%; word-wrap:break-word; text-align:left;'>" . htmlspecialchars($m['message']) . "</span><div style='font-size:9px; color:#888; margin-top:2px;'>$time</div></div>";
    }
}
?>
