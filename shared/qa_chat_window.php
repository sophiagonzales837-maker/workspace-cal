<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['lead_id'])) die("Unauthorized.");

$lead_id = (int)$_GET['lead_id'];
$my_id = (int)$_SESSION['user_id'];
$access = $conn->query("SELECT 1 FROM leads l LEFT JOIN users v ON v.id = l.verifier_id WHERE l.id = $lead_id AND (l.verifier_id = $my_id OR v.assigned_qc_id = $my_id) LIMIT 1");
if (!$access || $access->num_rows === 0) die("Unauthorized.");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message']);
    if (!empty($msg)) {
        $stmt = $conn->prepare("INSERT INTO messages (lead_id, sender_id, content) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $lead_id, $my_id, $msg);
        $stmt->execute();
        $stmt->close();
    }
    exit('success');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_messages'])) {
    $msgs = $conn->query("SELECT m.*, u.username FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.lead_id = $lead_id ORDER BY m.created_at ASC");
    $html = "";
    if ($msgs && $msgs->num_rows > 0) {
        while ($m = $msgs->fetch_assoc()) {
            $is_me = ($m['sender_id'] == $my_id);
            $align = $is_me ? "align-self: flex-end; background: rgba(8, 145, 178, 0.24); border: 1px solid rgba(56, 189, 248, 0.3);" : "align-self: flex-start; background: #0f172a; border: 1px solid rgba(148, 163, 184, 0.22);";
            $sender = $is_me ? "You" : htmlspecialchars($m['username']);
            $time = date('h:i A', strtotime($m['created_at']));
            $html .= "<div style='max-width: 80%; padding: 10px; border-radius: 8px; margin-bottom: 10px; $align'>";
            $html .= "<strong style='font-size: 11px; color: #cbd5e1; display: block; margin-bottom: 4px;'>$sender</strong>";
            $html .= "<span style='font-size: 13px; color: #f8fafc;'>" . nl2br(htmlspecialchars($m['content'])) . "</span>";
            $html .= "<div style='font-size: 9px; color: #94a3b8; text-align: right; margin-top: 5px;'>$time</div></div>";
        }
    } else {
        $html = "<div style='text-align:center; color:#94a3b8; padding: 20px; font-size:13px;'>No updates yet.</div>";
    }
    exit($html);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>QA Updates | Lead #<?= $lead_id ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            background: #030712;
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .header {
            background: rgba(15, 23, 42, 0.9);
            color: white;
            padding: 15px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            border-bottom: 1px solid rgba(56, 189, 248, 0.2);
        }

        .chat-box {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            background: #030712;
        }

        .input-area {
            padding: 15px;
            background: #0f172a;
            border-top: 1px solid rgba(56, 189, 248, 0.2);
            display: flex;
            gap: 10px;
        }

        input[type="text"] {
            flex: 1;
            padding: 10px;
            color: #f8fafc;
            background: #0b1220;
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        button {
            background: #0891b2;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="header">QA Lead Updates (Lead #<?= $lead_id ?>)</div>
    <div class="chat-box" id="chat-box">Loading...</div>
    <div class="input-area">
        <input type="text" id="msg-input" placeholder="Type feedback here..." onkeypress="if(event.key === 'Enter') sendMessage()">
        <button onclick="sendMessage()">Send</button>
    </div>
    <script>
        const chatBox = document.getElementById('chat-box');
        const input = document.getElementById('msg-input');

        function fetchMessages() {
            fetch('?lead_id=<?= $lead_id ?>&fetch_messages=1').then(r => r.text()).then(html => {
                const isScrolledToBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 10;
                chatBox.innerHTML = html;
                if (isScrolledToBottom) chatBox.scrollTop = chatBox.scrollHeight;
            });
        }

        function sendMessage() {
            const msg = input.value.trim();
            if (!msg) return;
            const fd = new FormData();
            fd.append('message', msg);
            input.value = '';
            fetch('?lead_id=<?= $lead_id ?>', {
                method: 'POST',
                body: fd
            }).then(() => {
                fetchMessages();
                setTimeout(() => chatBox.scrollTop = chatBox.scrollHeight, 100);
            });
        }
        fetchMessages();
        setTimeout(() => chatBox.scrollTop = chatBox.scrollHeight, 200);
        setInterval(fetchMessages, 5000);
    </script>
</body>

</html>