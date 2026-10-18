<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Verifier', 'Quality Checker', 'Auditor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) { exit('unauthorized'); }

$lead_id = (int)($_POST['lead_id'] ?? 0);
$qa_status = $_POST['qa_status'] ?? '';
$allowed_statuses = ['uploaded', 'reviewed', 'correction_needed'];

if ($lead_id <= 0 || !in_array($qa_status, $allowed_statuses, true)) exit('invalid');

$user_id = (int)$_SESSION['user_id'];
$access = $conn->query("SELECT 1 FROM leads l LEFT JOIN users v ON v.id = l.verifier_id WHERE l.id = $lead_id AND (l.verifier_id = $user_id OR v.assigned_qc_id = $user_id) LIMIT 1");
if (!$access || $access->num_rows === 0) exit('unauthorized');

$stmt = $conn->prepare("UPDATE leads SET qa_status = ? WHERE id = ?");
$stmt->bind_param('si', $qa_status, $lead_id);
if ($stmt->execute() && $stmt->affected_rows >= 0) exit('success');

exit('error');