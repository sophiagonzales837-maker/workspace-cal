<?php
// FILE: workspace/shared/api_check_callbacks.php
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id'])) {
    exit;
}

$user_id = $_SESSION['user_id'];
$base_url = "https://employees.kingstonsolutions.co.in/workspace/" . strtolower($_SESSION['role']);

// 1. Check Main Leads Table
$stmt1 = $conn->prepare("
    SELECT id, customer_name, phone 
    FROM leads 
    WHERE (agent_id = ? OR closer_id = ? OR verifier_id = ?) 
    AND status LIKE '%CB%' 
    AND (reminder_time <= NOW() OR callback_time <= NOW()) 
    AND cb_notified = 0 LIMIT 1
");
$stmt1->bind_param("iii", $user_id, $user_id, $user_id);
$stmt1->execute();
$res1 = $stmt1->get_result();

if ($lead = $res1->fetch_assoc()) {
    $conn->query("UPDATE leads SET cb_notified = 1 WHERE id = " . $lead['id']);
    header('Content-Type: application/json');
    echo json_encode([
        "id" => $lead['id'],
        "name" => $lead['customer_name'] ?? 'Customer',
        "phone" => $lead['phone'] ?? 'N/A',
        "url" => $base_url . "/manage_lead.php?id=" . $lead['id']
    ]);
    exit;
}
$stmt1->close();

// 2. Check Time_Data Table (Cold Calling Pipeline)
$stmt2 = $conn->prepare("
    SELECT id, first_name, last_name, phone_number 
    FROM time_data 
    WHERE assigned_user_id = ? 
    AND (status = 'CB' OR status2 = 'CB') 
    AND callback_time <= NOW() 
    AND cb_notified = 0 LIMIT 1
");
$stmt2->bind_param("i", $user_id);
$stmt2->execute();
$res2 = $stmt2->get_result();

if ($td = $res2->fetch_assoc()) {
    $conn->query("UPDATE time_data SET cb_notified = 1 WHERE id = " . $td['id']);
    $name = trim(($td['first_name'] ?? '') . ' ' . ($td['last_name'] ?? ''));
    header('Content-Type: application/json');
    echo json_encode([
        "id" => $td['id'],
        "name" => !empty($name) ? $name : 'Customer',
        "phone" => $td['phone_number'] ?? 'N/A',
        "url" => $base_url . "/time_data.php?search=" . urlencode($td['phone_number'])
    ]);
    exit;
}
$stmt2->close();

header('Content-Type: application/json');
echo json_encode(null);
?>