<?php
// FILE: workspace/api/v1/inhouse_chat_api.php
session_start();
require_once '../../config/db.php';
require_once '../../config/security.php';

// Basic Auth Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==========================================================
// 1. SEND MESSAGE (Universal Engine)
// ==========================================================
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (empty($message) || $lead_id === 0) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    $lead_stmt = $conn->prepare("SELECT agent_id, closer_id, verifier_id FROM leads WHERE id = ? LIMIT 1");
    $lead_stmt->bind_param("i", $lead_id);
    $lead_stmt->execute();
    $lead = $lead_stmt->get_result()->fetch_assoc();
    $lead_stmt->close();
    if (!$lead) {
        echo json_encode(['success' => false, 'error' => 'Lead not found']);
        exit;
    }
    $agent_id = (int)($lead['agent_id'] ?? 0);
    $assigned_closer_id = (int)($lead['closer_id'] ?? 0);
    $assigned_verifier_id = (int)($lead['verifier_id'] ?? 0);
    $recipient_id = 0;
    $recipient_type = '';

    // Dynamically route the insert based on the sender's role (No more generic notifications!)
    if ($role === 'Admin' || $role === 'Jr. Admin' || $role === 'Jr_Admin') {
        $closer_id = $assigned_closer_id;
        $recipient_id = $closer_id;
        $recipient_type = 'Closer';
        $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, admin_id, closer_id, agent_id, message, sender_type, is_read) VALUES (?, ?, ?, ?, ?, 'Admin', 0)");
        $stmt->bind_param("iiiis", $lead_id, $user_id, $closer_id, $agent_id, $message);
    } elseif ($role === 'Closer') {
        if ($assigned_closer_id !== (int)$user_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Lead access denied']);
            exit;
        }
        $admin_id = (int)($_POST['admin_id'] ?? 0);
        $recipient_id = $admin_id;
        $recipient_type = 'Admin';
        $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, admin_id, closer_id, agent_id, message, sender_type, is_read) VALUES (?, ?, ?, ?, ?, 'Closer', 0)");
        $stmt->bind_param("iiiis", $lead_id, $admin_id, $user_id, $agent_id, $message);
    } elseif ($role === 'Agent') {
        if ($agent_id !== (int)$user_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Lead access denied']);
            exit;
        }
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        $recipient_type = trim($_POST['recipient_type'] ?? '');
        $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, agent_id, recipient_id, recipient_type, message, sender_type, is_read) VALUES (?, ?, ?, ?, ?, 'Agent', 0)");
        $stmt->bind_param("iisis", $lead_id, $user_id, $recipient_id, $recipient_type, $message);
    } elseif ($role === 'Verifier') {
        if ($assigned_verifier_id !== (int)$user_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Lead access denied']);
            exit;
        }
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        $recipient_type = trim($_POST['recipient_type'] ?? '');
        $stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, verifier_id, recipient_id, recipient_type, message, sender_type, is_read) VALUES (?, ?, ?, ?, ?, 'Verifier', 0)");
        $stmt->bind_param("iisis", $lead_id, $user_id, $recipient_id, $recipient_type, $message);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid role']);
        exit;
    }

    if ($stmt->execute()) {
        try {
            require_once '../../config/pusher.php';
            $pusher = workspace_pusher_server();
            if ($pusher) {
                $pusher->trigger('inhouse-chat-channel', 'new-message', [
                    'lead_id' => $lead_id,
                    'recipient_id' => $recipient_id,
                    'recipient_type' => $recipient_type,
                    'sender_type' => $role,
                ]);
            }
        } catch (Throwable $error) {
            error_log('Failed to publish inhouse chat event: ' . $error->getMessage());
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    $stmt->close();
    exit;
}

// ==========================================================
// 2. GET MESSAGES & TRIGGER READ RECEIPT
// ==========================================================
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $lead_id = (int)($_GET['lead_id'] ?? 0);

    $lead_stmt = $conn->prepare("SELECT agent_id, closer_id, verifier_id FROM leads WHERE id = ? LIMIT 1");
    $lead_stmt->bind_param("i", $lead_id);
    $lead_stmt->execute();
    $lead = $lead_stmt->get_result()->fetch_assoc();
    $lead_stmt->close();

    $can_view = $role === 'Admin' || $role === 'Jr. Admin' || $role === 'Jr_Admin';
    if ($lead) {
        $can_view = $can_view || ($role === 'Closer' && (int)$lead['closer_id'] === (int)$user_id);
        $can_view = $can_view || ($role === 'Agent' && (int)$lead['agent_id'] === (int)$user_id);
        $can_view = $can_view || ($role === 'Verifier' && (int)$lead['verifier_id'] === (int)$user_id);
    }
    if (!$lead || !$can_view) {
        http_response_code($lead ? 403 : 404);
        echo json_encode(['success' => false, 'error' => 'Lead access denied']);
        exit;
    }

    $read_stmt = $conn->prepare("UPDATE inhouse_chats SET is_read = 1 WHERE lead_id = ? AND sender_type <> ?");
    $read_stmt->bind_param("is", $lead_id, $role);
    $read_stmt->execute();
    $read_stmt->close();

    $stmt = $conn->prepare("SELECT * FROM inhouse_chats WHERE lead_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $lead_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    echo json_encode($messages);
    $stmt->close();
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
