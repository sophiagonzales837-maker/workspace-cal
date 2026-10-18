<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/pusher.php';
protect('Agent');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Gather Raw Data
    $first_name_raw = trim($_POST['first_name'] ?? '');
    $last_name_raw = trim($_POST['last_name'] ?? '');
    $customer_name_raw = trim($first_name_raw . ' ' . $last_name_raw);

    $raw_phone = trim($_POST['phone'] ?? '');
    if (!empty($raw_phone) && strpos($raw_phone, '+1') !== 0) {
        $raw_phone = '+1' . ltrim($raw_phone, ' 1+');
    }
    if (!preg_match('/^\+1\d{10}$/', $raw_phone)) {
        http_response_code(422);
        exit('Enter a valid 10-digit phone number.');
    }

    $email_raw = trim($_POST['email'] ?? '');
    $address_raw = trim($_POST['address'] ?? '');
    $city_raw = trim($_POST['city'] ?? '');
    $state_raw = trim($_POST['state'] ?? '');
    $zip_code_raw = trim($_POST['zip_code'] ?? '');
    $dob_raw = trim($_POST['dob'] ?? '');

    // Agent name is captured for notifications, but NOT saved to the leads table
    $agent_name = $_POST['agent_name'] ?? '';
    $agent_id = $_SESSION['user_id'];

    // Capture the selected closer from the form
    $selected_closer_id = (int)($_POST['closer_id'] ?? 0);

    if ($selected_closer_id > 0) {

        // Fetch the closer's username based on the submitted closer_id
        $stmt_closer = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'Closer'");
        $stmt_closer->bind_param("i", $selected_closer_id);
        $stmt_closer->execute();
        $res_closer = $stmt_closer->get_result();

        if ($res_closer->num_rows === 0) {
            die("<h3>⚠️ Assignment Error</h3><p>Invalid Closer Selected. Please return to the form and try again.</p>");
        }
        $stmt_closer->close();
    }

    // --- SECURITY FIX 2: Encrypt Sensitive Customer Data ---
    $enc_customer_name = encryptData($customer_name_raw);
    $enc_first_name = encryptData($first_name_raw);
    $enc_last_name = encryptData($last_name_raw);
    $enc_phone = encryptData($raw_phone);
    $enc_email = encryptData($email_raw);
    $enc_address = encryptData($address_raw);
    $enc_city = encryptData($city_raw);
    $enc_state = encryptData($state_raw);
    $enc_zip_code = encryptData($zip_code_raw);
    $enc_dob = encryptData($dob_raw);

    // Generate phone hash for matching
    $phone_hash = createBlindHash($raw_phone);
    $action_type = (string)($_POST['action_type'] ?? 'insert');
    $additional_details_raw = trim((string)($_POST['final_additional_details'] ?? ''));
    $enc_additional_details = encryptData($additional_details_raw);
    if (!in_array($action_type, ['insert', 'update'], true)) {
        http_response_code(400);
        exit('Invalid lead action.');
    }

    if ($action_type === 'update') {
        $duplicate_lead_id = (int)($_POST['duplicate_lead_id'] ?? 0);
        if ($duplicate_lead_id <= 0) {
            http_response_code(400);
            exit('Invalid duplicate lead selection.');
        }

        $stmt_existing = $conn->prepare("SELECT first_name, last_name, email, phone, phone_hash, address, city, state, zip_code, dob, alternate_details, closer_id FROM leads WHERE id = ? AND phone_hash = ? LIMIT 1");
        if (!$stmt_existing) {
            http_response_code(500);
            exit('Could not prepare duplicate update.');
        }
        $stmt_existing->bind_param('is', $duplicate_lead_id, $phone_hash);
        if (!$stmt_existing->execute()) {
            http_response_code(500);
            $stmt_existing->close();
            exit('Could not verify duplicate lead.');
        }
        $existing = $stmt_existing->get_result()->fetch_assoc();
        $stmt_existing->close();
        if (!$existing) {
            http_response_code(409);
            exit('The selected lead no longer matches this phone number. Please check for duplicates again.');
        }

        $final_first_name = $first_name_raw !== '' ? $first_name_raw : decryptData($existing['first_name'] ?? '');
        $final_last_name = $last_name_raw !== '' ? $last_name_raw : decryptData($existing['last_name'] ?? '');
        $final_customer_name = trim($final_first_name . ' ' . $final_last_name);
        $final_email = $email_raw !== '' ? $email_raw : decryptData($existing['email'] ?? '');
        $final_phone = $raw_phone !== '' ? $raw_phone : decryptData($existing['phone'] ?? '');
        $final_address = $address_raw !== '' ? $address_raw : decryptData($existing['address'] ?? '');
        $final_city = $city_raw !== '' ? $city_raw : decryptData($existing['city'] ?? '');
        $final_state = $state_raw !== '' ? $state_raw : decryptData($existing['state'] ?? '');
        $final_zip_code = $zip_code_raw !== '' ? $zip_code_raw : decryptData($existing['zip_code'] ?? '');
        $final_dob = $dob_raw !== '' ? $dob_raw : decryptData($existing['dob'] ?? '');
        $final_additional_details = $additional_details_raw !== '' ? $additional_details_raw : decryptData($existing['alternate_details'] ?? '');

        $enc_merged_customer_name = encryptData($final_customer_name);
        $enc_merged_first_name = encryptData($final_first_name);
        $enc_merged_last_name = encryptData($final_last_name);
        $enc_merged_phone = encryptData($final_phone);
        $enc_merged_email = encryptData($final_email);
        $enc_merged_address = encryptData($final_address);
        $enc_merged_city = encryptData($final_city);
        $enc_merged_state = encryptData($final_state);
        $enc_merged_zip_code = encryptData($final_zip_code);
        $enc_merged_dob = encryptData($final_dob);
        $enc_merged_additional_details = encryptData($final_additional_details);

        $stmt_update = $conn->prepare(
            "UPDATE leads SET customer_name = ?, first_name = ?, last_name = ?, phone = ?, email = ?, address = ?, city = ?, state = ?, zip_code = ?, dob = ?, alternate_details = ?, updated_at = NOW() WHERE id = ? AND phone_hash = ?"
        );
        if (!$stmt_update) {
            http_response_code(500);
            exit('Could not prepare duplicate lead update.');
        }
        $stmt_update->bind_param(
            'sssssssssssis',
            $enc_merged_customer_name,
            $enc_merged_first_name,
            $enc_merged_last_name,
            $enc_merged_phone,
            $enc_merged_email,
            $enc_merged_address,
            $enc_merged_city,
            $enc_merged_state,
            $enc_merged_zip_code,
            $enc_merged_dob,
            $enc_merged_additional_details,
            $duplicate_lead_id,
            $phone_hash
        );
        if (!$stmt_update->execute()) {
            http_response_code(500);
            $stmt_update->close();
            exit('Could not update duplicate lead.');
        }
        $stmt_update->close();

        if (!empty($existing['closer_id'])) {
            notify((int)$existing['closer_id'], "An Agent merged new information into an existing lead: " . $final_customer_name, "manage_lead.php?id=" . $duplicate_lead_id, $conn);
        }
        $pusher = workspace_pusher_server();
        if ($pusher) {
            $pusher->trigger('global-updates', 'lead-updated', [
                'message' => 'An existing lead was updated.'
            ]);
        }
        header('Location: index.php?success=1&updated=1');
        exit;
    }

    $vertical_value = '';
    $case_hash = createBlindHash('');

    // Ensure it triggers the correct pipeline view for the Closer
    $status = $selected_closer_id > 0 ? 'Assigned to Closer' : 'New';

    // --- SCHEMA FIX: Removed agent_name and closer_name to match new DB Schema ---
    $stmt_insert = $conn->prepare("INSERT INTO leads (customer_name, first_name, last_name, phone, phone_hash, email, address, city, state, zip_code, dob, vertical, case_hash, agent_id, closer_id, status, alternate_details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, NOW())");

    // Failsafe: Print the exact MySQL error if the query fails to prepare
    if (!$stmt_insert) {
        die("Database Error in submit_lead.php: " . $conn->error);
    }

    // Updated bind_param string (16 parameters)
    $stmt_insert->bind_param(
        "sssssssssssssiiss",
        $enc_customer_name,
        $enc_first_name,
        $enc_last_name,
        $enc_phone,
        $phone_hash,
        $enc_email,
        $enc_address,
        $enc_city,
        $enc_state,
        $enc_zip_code,
        $enc_dob,
        $vertical_value,
        $case_hash,
        $agent_id,
        $selected_closer_id,
        $status,
        $enc_additional_details
    );

    if ($stmt_insert->execute()) {

        // 1. Grab the new Lead ID first
        $new_lead_id = $stmt_insert->insert_id;

        // --- WEBHOOK: SYNC AGENT INSERT TO LIVE SERVER ---
        // --- WEBHOOK: SYNC AGENT INSERT TO LIVE SERVER ---
        $sync_payload = json_encode([
            'id'                => $new_lead_id, // FORCE IDENTICAL ID
            'customer_name'     => $customer_name_raw,
            'first_name'        => $first_name_raw,
            'last_name'         => $last_name_raw,
            'phone'             => $raw_phone,
            'phone_hash'        => $phone_hash, // CRITICAL: Added phone_hash for Live Server tracking
            'email'             => $email_raw,
            'address'           => $address_raw,
            'city'              => $city_raw,
            'state'             => $state_raw,
            'zip_code'          => $zip_code_raw,
            'dob'               => $dob_raw,
            'vertical'          => '',
            'referred_by'       => '',
            'alternate_details' => $additional_details_raw,
            'closer_id'         => $selected_closer_id,
            'agent_id'          => $agent_id,
            'status'            => $status,
            'auth_key'          => 'MakeMoney$$'
        ]);

        $ch = curl_init('https://consumerhealthcarecompliance.com/api/sync_new_lead.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sync_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL: Fixes XAMPP SSL Block
        curl_exec($ch);
        curl_close($ch);
        // -------------------------------------------------

        // 2. Insert standard database notification for the closer
        if ($selected_closer_id > 0) {
            notify($selected_closer_id, "New lead submitted by Agent: " . $customer_name_raw, "manage_lead.php?id=" . $new_lead_id, $conn);
        }

        $stmt_insert->close();
        session_write_close();
        header("Location: index.php?success=1");

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 3. Trigger Real-Time Pusher Broadcast in the background
        $pusher = workspace_pusher_server();
        if ($pusher) {
            $pusher->trigger('global-updates', 'lead-updated', [
                'message' => 'A lead was just updated or added!'
            ]);
        }

        exit();
    } else {
        die("Database Execution Error: " . $stmt_insert->error);
    }
}
