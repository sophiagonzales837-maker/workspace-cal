<?php
// FILE: workspace/api/v1/receive_web_lead.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../config/db.php';
require_once '../../config/security.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data) || !hash_equals('MakeMoney$$', (string)($data['auth_key'] ?? ''))) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

if (!$data) {
    echo json_encode(["status" => "error", "message" => "No data received"]);
    exit;
}

// 1. Extract Data
$first_name = trim($data['first_name'] ?? '');
$last_name = trim($data['last_name'] ?? '');
$customer_name = trim("$first_name $last_name");
$phone = trim($data['phone'] ?? ''); // This will be formatted: +1 (123) 456-7890
$email = trim($data['email'] ?? '');
$dob = trim($data['dob'] ?? '');
$address = trim($data['address'] ?? '');
$city = trim($data['city'] ?? '');
$state = trim($data['state'] ?? '');
$zip_code = trim($data['zip_code'] ?? '');
$vertical = trim($data['vertical'] ?? '');
$message = trim($data['message'] ?? '');
$trustedform_cert_url = trim($data['trustedform_url'] ?? '');
$jornaya_leadid = trim($data['leadid_token'] ?? '');
$ip_address = trim($data['ip_address'] ?? '');
$phone_hash = trim($data['phone_hash'] ?? '');

if (empty($phone) || empty($vertical) || empty($phone_hash)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

// 2. Format & Hash Phone for Duplicate Checking
$clean_phone = preg_replace('/[^\d+]/', '', $phone);
if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) {
    $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
}
// 3. Encrypt Sensitive Data (Saving the beautifully formatted phone number!)
$enc_customer_name = encryptData($customer_name);
$enc_first_name = encryptData($first_name);
$enc_last_name = encryptData($last_name);
$enc_phone = encryptData($phone); // Stores: +1 (123) 456-7890
$enc_email = encryptData($email);
$enc_dob = encryptData($dob);
$enc_address = encryptData($address);
$enc_city = encryptData($city);
$enc_state = encryptData($state);
$enc_zip_code = encryptData($zip_code);
$enc_message = encryptData($message);

// 4. SMART DUPLICATE CHECKING
$existing_customer_id = null;
$existing_password = null;
$existing_match_id = null;
$existing_verifier_id = null;

$stmt_check = $conn->prepare("SELECT id, customer_id, password, verifier_id FROM leads WHERE phone_hash = ? LIMIT 1");
$stmt_check->bind_param("s", $phone_hash);
$stmt_check->execute();
$result = $stmt_check->get_result();

if ($row = $result->fetch_assoc()) {
    $existing_customer_id = $row['customer_id'] ?? null;
    $existing_password = $row['password'] ?? null;
    $existing_match_id = (int)$row['id'];
    $existing_verifier_id = $row['verifier_id'] ?? null;
}
$stmt_check->close();

// 5. UPDATE OR INSERT
if ($existing_match_id) {
    // UPDATE EXISTING LEAD
    $update_stmt = $conn->prepare("UPDATE leads SET status = 'Pending Verification', closer_status = 'Pending Verification', vertical = ?, jornaya_leadid = ?, trustedform_cert_url = ?, ip_address = ? WHERE phone_hash = ?");
    $update_stmt->bind_param("sssss", $vertical, $jornaya_leadid, $trustedform_cert_url, $ip_address, $phone_hash);
    if (!$update_stmt->execute()) {
        $update_stmt->close();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Lead update failed"]);
        exit;
    }
    $update_stmt->close();

    $lead_id = $existing_match_id;
} else {
    // INSERT FRESH LEAD
    $insert_stmt = $conn->prepare("INSERT INTO leads (
        customer_name, first_name, last_name, phone, phone_hash, email, dob, 
        address, city, state, zip_code, vertical, message, 
        trustedform_cert_url, jornaya_leadid, ip_address, status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Verification', NOW())");

    $insert_stmt->bind_param(
        "ssssssssssssssss",
        $enc_customer_name,
        $enc_first_name,
        $enc_last_name,
        $enc_phone,
        $phone_hash,
        $enc_email,
        $enc_dob,
        $enc_address,
        $enc_city,
        $enc_state,
        $enc_zip_code,
        $vertical,
        $enc_message,
        $trustedform_cert_url,
        $jornaya_leadid,
        $ip_address
    );

    $insert_stmt->execute();
    $lead_id = $insert_stmt->insert_id;
    $insert_stmt->close();
}

// Trigger Real-Time Dashboard Refresh for Admins/Jr Admins
if (file_exists(__DIR__ . '/../../config/pusher.php')) {
    require_once __DIR__ . '/../../config/pusher.php';
    if (function_exists('workspace_pusher_server')) {
        try {
            workspace_pusher_server()->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id]);
        } catch (Exception $e) {
        }
    }
}

echo json_encode(["status" => "success", "lead_id" => $lead_id]);

if (function_exists('workspace_close_db_connections')) workspace_close_db_connections();
