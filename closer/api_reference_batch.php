<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Closer');

header('Content-Type: application/json');

function reference_sheet_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reference_sheet_response(['error' => 'POST required.'], 405);
}

$closer_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['reference_sheet_csrf']) || !hash_equals($_SESSION['reference_sheet_csrf'], $csrf_token)) {
    reference_sheet_response(['error' => 'Request verification failed.'], 403);
}

$payload = json_decode(file_get_contents('php://input'), true);
$rows = $payload['rows'] ?? null;
if (!is_array($rows) || count($rows) > 50) {
    reference_sheet_response(['error' => 'Invalid row data.'], 400);
}

$create_table = "CREATE TABLE IF NOT EXISTS closer_reference_sheet_rows (
    closer_id INT NOT NULL,
    row_number SMALLINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    notes MEDIUMTEXT NULL,
    saved_at DATETIME NOT NULL,
    PRIMARY KEY (closer_id, row_number),
    UNIQUE KEY unique_reference_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if (!$conn->query($create_table)) {
    reference_sheet_response(['error' => 'Unable to initialize the reference sheet.'], 500);
}

$insert_lead = $conn->prepare("INSERT INTO leads (
    customer_name, first_name, last_name, phone, phone_hash, email, address, city, state,
    zip_code, dob, vertical, referred_by, alternate_details, closer_id, status, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', NOW())");
$check_phone = $conn->prepare('SELECT id FROM leads WHERE phone_hash = ? LIMIT 1');
$check_slot = $conn->prepare('SELECT lead_id FROM closer_reference_sheet_rows WHERE closer_id = ? AND row_number = ? LIMIT 1');
$save_slot = $conn->prepare('INSERT INTO closer_reference_sheet_rows (closer_id, row_number, lead_id, notes, saved_at) VALUES (?, ?, ?, ?, NOW())');
if (!$insert_lead || !$check_phone || !$check_slot || !$save_slot) {
    reference_sheet_response(['error' => 'Unable to prepare lead storage.'], 500);
}

$results = [];
$inserted_any = false;
$sync_payloads = [];
foreach ($rows as $row) {
    $row_number = (int)($row['row_number'] ?? 0);
    if ($row_number < 1 || $row_number > 50) {
        $results[] = ['row_number' => $row_number, 'status' => 'invalid'];
        continue;
    }

    $first_name = trim((string)($row['first_name'] ?? ''));
    $last_name = trim((string)($row['last_name'] ?? ''));
    $raw_phone = trim((string)($row['phone'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $vertical = trim((string)($row['vertical'] ?? ''));
    $notes = trim((string)($row['notes'] ?? ''));
    if ($first_name === '' && $last_name === '' && $raw_phone === '' && $email === '' && $vertical === '' && $notes === '') {
        continue;
    }

    $clean_phone = preg_replace('/[^\d+]/', '', $raw_phone);
    if (strpos($clean_phone, '+1') !== 0) {
        $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
    }
    $digits = preg_replace('/\D/', '', $clean_phone);
    if (strlen($digits) !== 11 || $first_name === '' || $raw_phone === '') {
        $results[] = ['row_number' => $row_number, 'status' => 'invalid'];
        continue;
    }

    $check_slot->bind_param('ii', $closer_id, $row_number);
    $check_slot->execute();
    if ($check_slot->get_result()->num_rows > 0) {
        $results[] = ['row_number' => $row_number, 'status' => 'locked'];
        continue;
    }

    $phone_hash = createBlindHash($clean_phone);
    $check_phone->bind_param('s', $phone_hash);
    $check_phone->execute();
    if ($check_phone->get_result()->num_rows > 0) {
        $results[] = ['row_number' => $row_number, 'status' => 'duplicate'];
        continue;
    }

    $customer_name = trim($first_name . ' ' . $last_name);
    $enc_customer = encryptData($customer_name !== '' ? $customer_name : 'Unknown Customer');
    $enc_first = encryptData($first_name);
    $enc_last = encryptData($last_name);
    $enc_phone = encryptData($clean_phone);
    $enc_email = encryptData($email);
    $enc_empty = encryptData('');

    $conn->begin_transaction();
    $insert_lead->bind_param(
        'ssssssssssssssi',
        $enc_customer,
        $enc_first,
        $enc_last,
        $enc_phone,
        $phone_hash,
        $enc_email,
        $enc_empty,
        $enc_empty,
        $enc_empty,
        $enc_empty,
        $enc_empty,
        $vertical,
        $enc_empty,
        $enc_empty,
        $closer_id
    );
    if (!$insert_lead->execute()) {
        $conn->rollback();
        $results[] = ['row_number' => $row_number, 'status' => 'error'];
        continue;
    }

    $lead_id = $insert_lead->insert_id;
    $enc_notes = encryptData($notes);
    $save_slot->bind_param('iiis', $closer_id, $row_number, $lead_id, $enc_notes);
    if (!$save_slot->execute()) {
        $conn->rollback();
        $results[] = ['row_number' => $row_number, 'status' => 'error'];
        continue;
    }
    $conn->commit();
    $inserted_any = true;
    $sync_payloads[] = json_encode([
        'id' => $lead_id,
        'customer_name' => $customer_name,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'phone' => $clean_phone,
        'email' => $email,
        'address' => '',
        'city' => '',
        'state' => '',
        'zip_code' => '',
        'dob' => '',
        'vertical' => $vertical,
        'referred_by' => '',
        'alternate_details' => '',
        'closer_id' => $closer_id,
        'agent_id' => 0,
        'status' => 'New',
        'auth_key' => 'MakeMoney$$'
    ]);
    $results[] = ['row_number' => $row_number, 'status' => 'saved', 'lead_id' => $lead_id];
}

$insert_lead->close();
$check_phone->close();
$check_slot->close();
$save_slot->close();

if (count($sync_payloads) > 0 && function_exists('curl_multi_init')) {
    $multi_handle = curl_multi_init();
    $curl_handles = [];
    foreach ($sync_payloads as $index => $sync_payload) {
        $curl_handle = curl_init('https://consumerhealthcarecompliance.com/api/sync_new_lead.php');
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_handle, CURLOPT_POST, true);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $sync_payload);
        curl_setopt($curl_handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl_handle, CURLOPT_TIMEOUT, 3);
        curl_multi_add_handle($multi_handle, $curl_handle);
        $curl_handles[$index] = $curl_handle;
    }

    $running = null;
    do {
        curl_multi_exec($multi_handle, $running);
        if ($running > 0) curl_multi_select($multi_handle);
    } while ($running > 0);

    foreach ($curl_handles as $curl_handle) {
        curl_multi_remove_handle($multi_handle, $curl_handle);
        curl_close($curl_handle);
    }
    curl_multi_close($multi_handle);
}

if ($inserted_any && function_exists('workspace_pusher_server')) {
    try {
        $pusher = workspace_pusher_server();
        if ($pusher) {
            $pusher->trigger('global-updates', 'lead-updated', ['message' => 'Reference sheet leads added']);
        }
    } catch (Exception $e) {
        error_log('[REFERENCE SHEET] Pusher notification failed: ' . $e->getMessage());
    }
}

reference_sheet_response(['results' => $results]);
