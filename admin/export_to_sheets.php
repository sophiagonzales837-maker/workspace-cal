<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');

// PASTE YOUR NEW GOOGLE APPS SCRIPT WEB APP URL HERE
$webhook_url = "https://script.google.com/macros/s/AKfycbwu30JQdMGfJCZtHMm8Ca8WnqC4fBC7l4hP5-lYD65Jxi5hJfIWR3ccFkWjgWmRr8tF/exec"; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    
    $rows = [];
    $sheetName = '';

    if ($type === 'posted') {
        $sheetName = 'Posted_Leads'; 
        
        $where = "(l.admin_status = 'Approved' OR l.status IN ('U', 'DOC', 'DOCUMENTS UPLOADED', 'Approved', 'Paid') OR (l.status LIKE '%DNQ%' AND l.client_name IS NOT NULL AND l.client_name != ''))";
        
        // Changed to DESC for newest first, and pulling c.client_code
        $query = "SELECT l.*, 
                  (SELECT username FROM users WHERE id = l.agent_id) as agent_username,
                  (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
                  (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username,
                  FROM leads l 
                  WHERE $where 
                  ORDER BY l.created_at DESC"; 
                  
    } elseif ($type === 'all') {
        $sheetName = 'All_Leads'; 
        
        // Changed to DESC for newest first
        $query = "SELECT l.*, 
                  (SELECT username FROM users WHERE id = l.agent_id) as agent_username,
                  (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
                  (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username
                  FROM leads l 
                  ORDER BY l.created_at DESC";
    } else {
        die(json_encode(["status" => "error", "message" => "Invalid backup type."]));
    }

    $res = mysqli_query($conn, $query);

    while ($row = mysqli_fetch_assoc($res)) {
        $row = decrypt_fields($row);
        
        if ($type === 'posted') {
            $rows[] = [
                $row['vertical'] ?? '', 
                $row['first_name'] ?? '', 

                $row['last_name'] ?? '', 
                $row['phone'] ?? '', 
                $row['email'] ?? '', 
                $row['address'] ?? '', 
                $row['city'] ?? '', 
                $row['state'] ?? '', 
                $row['zip_code'] ?? '', 
                $row['dob'] ?? '', 
                $row['client_name'] ?? '',
                $row['status'] ?? '', 
                $row['quick_update'] ?? '', 
                $row['application_text'] ?? '', 
                $row['agent_username'] ?? $row['agent_name'] ?? '', 
                $row['closer_username'] ?? $row['closer_name'] ?? '', 
                $row['verifier_username'] ?? $row['verifier_name'] ?? '', 
                $row['referred_by'] ?? '', 
                $row['payouts'] ?? '', 
                $row['incentive'] ?? '', 
                date('M d, Y h:i A', strtotime($row['created_at'] ?? 'now'))
            ];
        } else {
            // This is the updated array for All Leads (26 Columns)
            $rows[] = [
                $row['vertical'] ?? '', 
                $row['first_name'] ?? '', 
                $row['last_name'] ?? '', 
                $row['phone'] ?? '', 
                $row['email'] ?? '', 
                $row['address'] ?? '', 
                $row['city'] ?? '', 
                $row['state'] ?? '', 
                $row['zip_code'] ?? '', 
                $row['dob'] ?? '', 
                $row['client_name'] ?? '', 
                $row['closer_status'] ?? '', 
                $row['status'] ?? '', 
                $row['application_text'] ?? '', 
                $row['verifier_application_text'] ?? '', 
                $row['alternate_details'] ?? '', 
                (!empty($row['id_front_path']) || !empty($row['id_back_path']) || !empty($row['additional_records']) ? 'Proofs Uploaded' : 'None'), // Proof column
                $row['agent_username'] ?? $row['agent_name'] ?? '', 
                $row['referred_by'] ?? '',
                $row['closer_username'] ?? $row['closer_name'] ?? '', 
                $row['verifier_username'] ?? $row['verifier_name'] ?? '', 
                $row['jornaya_leadid'] ?? '', 
                $row['trustedform_cert_url'] ?? '', 
                $row['ip_address'] ?? '', 
                $row['customer_id'] ?? '', 
                date('M d, Y h:i A', strtotime($row['created_at'] ?? 'now'))
            ];
        }
    }

    if (count($rows) === 0) {
        echo json_encode(["status" => "error", "message" => "No records found to backup. Ensure there are leads on the screen."]);
        exit;
    }

    $payload = json_encode(["sheetName" => $sheetName, "rows" => $rows]);

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        echo json_encode(["status" => "error", "message" => "Server connection error: " . $curl_error]);
        exit;
    }

    $decoded_response = json_decode($response, true);

    if ($http_code == 200 && isset($decoded_response['status'])) {
        if ($decoded_response['status'] == 'success') {
            echo json_encode(["status" => "success", "message" => "Successfully pushed " . count($rows) . " leads to Google Sheets!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Google Sheet Error: " . $decoded_response['message']]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Unexpected Response. Make sure the Google App Script is deployed."]);
    }
}
?>