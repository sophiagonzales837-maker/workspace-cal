<?php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$conn->query("CREATE TABLE IF NOT EXISTS user_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) DEFAULT 'Untitled Note',
    content LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'load') {
    $stmt = $conn->prepare("SELECT id, title, content, updated_at FROM user_notes WHERE user_id = ? ORDER BY updated_at DESC");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not load notes']);
        exit;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'notes' => $notes]);
    exit;
}

if ($action === 'save') {
    $note_id = (int)($_POST['note_id'] ?? 0);
    $title = trim($_POST['title'] ?? 'Untitled Note');
    $content = trim($_POST['content'] ?? '');
    if ($title === '') $title = 'Untitled Note';

    if ($note_id > 0) {
        $stmt = $conn->prepare("UPDATE user_notes SET title = ?, content = ? WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false]);
            exit;
        }
        $stmt->bind_param("ssii", $title, $content, $note_id, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO user_notes (user_id, title, content) VALUES (?, ?, ?)");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false]);
            exit;
        }
        $stmt->bind_param("iss", $user_id, $title, $content);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'note_id' => $note_id > 0 ? $note_id : $stmt->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false]);
    }
    $stmt->close();
    exit;
}

if ($action === 'delete') {
    $note_id = (int)($_POST['note_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM user_notes WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false]);
        exit;
    }
    $stmt->bind_param("ii", $note_id, $user_id);
    $success = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $success]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action']);
