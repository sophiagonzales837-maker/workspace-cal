<?php
// FILE: LOCAL XAMPP - workspace/closer/api_generate_links.php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../shared/smart_sync.php';

// Authorize the Closer
protect('Closer');

header('Content-Type: application/json');

$lead_id = (int)($_POST['lead_id'] ?? 0);

if ($lead_id > 0) {
    // 1. Generate BOTH tokens
    $id_upload_token = bin2hex(random_bytes(16)); // For MAT
    do {
        $token_check_stmt = $conn->prepare("SELECT id FROM leads WHERE id != ? AND id_upload_token = ? LIMIT 1");
        $token_check_stmt->bind_param("is", $lead_id, $id_upload_token);
        $token_check_stmt->execute();
        $token_is_unique = $token_check_stmt->get_result()->num_rows === 0;
        $token_check_stmt->close();
        if (!$token_is_unique) {
            $id_upload_token = bin2hex(random_bytes(16));
        }
    } while (!$token_is_unique);

    $c2c_id = bin2hex(random_bytes(16));          // For ClassActionCase
    do {
        $c2c_check_stmt = $conn->prepare("SELECT id FROM leads WHERE id != ? AND c2c_id = ? LIMIT 1");
        $c2c_check_stmt->bind_param("is", $lead_id, $c2c_id);
        $c2c_check_stmt->execute();
        $c2c_id_is_unique = $c2c_check_stmt->get_result()->num_rows === 0;
        $c2c_check_stmt->close();
        if (!$c2c_id_is_unique) {
            $c2c_id = bin2hex(random_bytes(16));
        }
    } while (!$c2c_id_is_unique);

    // 2. Save BOTH to the local CRM database
    $stmt = $conn->prepare("UPDATE leads SET id_upload_token = ?, c2c_id = ? WHERE id = ?");
    $stmt->bind_param("ssi", $id_upload_token, $c2c_id, $lead_id);

    if ($stmt->execute()) {
        workspace_sync_c2c_id_to_live($lead_id, $c2c_id);

        // 3. Fetch lead details
        $q = $conn->query("SELECT first_name, last_name, email, phone, vertical, questions, c2c_docs_path FROM leads WHERE id = $lead_id");

        if ($lead = $q->fetch_assoc()) {

            $lead = function_exists('decrypt_fields') ? decrypt_fields($lead) : $lead;
            $vertical = $lead['vertical'] ?? '';

            // ====================================================================
            // WEBHOOK 1: SHOOT TOKEN TO MATTHEWS & ASSOCIATES (time_data2)
            // ====================================================================
            $mat_payload = json_encode([
                'token'      => $id_upload_token,
                'first_name' => $lead['first_name'],
                'last_name'  => $lead['last_name'],
                'email'      => $lead['email'],
                'phone'      => $lead['phone'],
                'vertical'   => $vertical,
                'send_email' => false
            ]);

            $ch1 = curl_init('https://consumerhealthcarecompliance.com/api/sync_token.php');
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch1, CURLOPT_POST, true);
            curl_setopt($ch1, CURLOPT_POSTFIELDS, $mat_payload);
            curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch1, CURLOPT_TIMEOUT, 2);
            curl_exec($ch1);
            curl_close($ch1);

            // ====================================================================
            // WEBHOOK 2: SHOOT DATA TO consumerhealthcarecompliance.com
            // ====================================================================
            $c2c_payload = json_encode([
                'c2c_id'        => $c2c_id,
                'first_name'    => $lead['first_name'],
                'last_name'     => $lead['last_name'],
                'email'         => $lead['email'],
                'phone'         => $lead['phone'],
                'vertical'      => $vertical,
                'questions'     => $lead['questions'] ?? '',
                'c2c_docs_path' => $lead['c2c_docs_path'] ?? ''
            ]);

            $ch2 = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $c2c_payload);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 2);
            curl_exec($ch2);
            curl_close($ch2);

            // ====================================================================
            // 4. SEND THE QUESTIONNAIRE EMAIL TO THE CUSTOMER
            // ====================================================================
            $to = $lead['email'];
            $first_name = $lead['first_name'];

            $mat_link = "https://pulaskikherkherlaw.info/dashboard?token=" . $id_upload_token;
            $c2c_link = "https://pulaskikherkherlaw.info/customer_application?c2c_id=" . $c2c_id;

            if (!empty($to)) {

                // --- EMAIL: The Application/Questionnaire ---
                $c2c_subject = "Action Required: Claim Questionnaire Review - Class Action Support";
                $c2c_body = "Hello $first_name,\n\n" .
                    "As part of handling your $vertical claim, Hereby are the required details.\n\n" .
                    "Please review the helping questions using the secure link below. Completing this application ensures our legal team has the accurate information needed to fight for your compensation:\n\n" .
                    "Questionnaire Link: $c2c_link\n\n" .
                    "Please read this as soon as possible to avoid any delays in your case review.\n\n" .
                    "Thank you,\nClass Action Support Team";

                $c2c_headers = "From: support@pulaskikherkherlaw.info\r\n";
                $c2c_headers .= "Reply-To: support@pulaskikherkherlaw.info\r\n";
                $c2c_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                @mail($to, $c2c_subject, $c2c_body, $c2c_headers);

                // --- Log the action for the Closer ---
                $closer_id = $_SESSION['user_id'];
                $msg = "System: Webhooks fired to MAT and ClassActionCase. The Questionnaire email was sent to the customer.";
                $conn->query("INSERT INTO messages (lead_id, sender_id, content) VALUES ('$lead_id', '$closer_id', '$msg')");
            }
        }

        // Return BOTH URLs back to the Closer's screen
        echo json_encode([
            "status" => "success",
            "dashboard_url" => $mat_link ?? '',
            "c2c_url" => $c2c_link ?? ''
        ]);
        exit;
    }
}

echo json_encode(["error" => "Failed to generate links."]);
