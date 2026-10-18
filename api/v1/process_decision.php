<?php
// FILE: workspace/api/v1/process_decision.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start an output buffer to swallow any stray PHP warnings so they don't corrupt the JSON
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Verifier') {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['log_attempt'])) {
    session_write_close();
    if (function_exists('workspace_close_db_connections')) workspace_close_db_connections();
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Logged attempt.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// --------------------------------------------------------------------------
// 1. EXTRACT SESSION VARS & UNLOCK SESSION
// --------------------------------------------------------------------------
$final_v_id = $_SESSION['user_id'];
$final_v_name = $_SESSION['username'];
$actual_verifier_name = trim($_POST['actual_verifier_name'] ?? '');

session_write_close();

// --------------------------------------------------------------------------
// 2. PROCESS DATABASE UPDATES
// --------------------------------------------------------------------------
$lead_id = (int)($_POST['lead_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
$cb_time = $_POST['cb_time'] ?? null;

if (!empty($actual_verifier_name)) {
    $u_stmt = $conn->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
    if ($u_stmt) {
        $u_stmt->bind_param("s", $actual_verifier_name);
        $u_stmt->execute();
        $u_res = $u_stmt->get_result();
        if ($u_res && $u_res->num_rows > 0) {
            $u_data = $u_res->fetch_assoc();
            $final_v_id = $u_data['id'];
            $final_v_name = $u_data['username'];
        }
        $u_stmt->close();
    }
}

$check_col = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'verifier_application_text'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE leads ADD COLUMN verifier_application_text TEXT");
}

$check_hist = mysqli_query($conn, "SHOW COLUMNS FROM leads LIKE 'verification_history'");
if (mysqli_num_rows($check_hist) == 0) {
    mysqli_query($conn, "ALTER TABLE leads ADD COLUMN verification_history TEXT");
}

$verifier_app = function_exists('encryptData') ? encryptData("Notes moved to live chat.") : "Notes moved to live chat.";
$verifier_app_text_raw = trim($_POST['verifier_application_text'] ?? '');
$verifier_app_text = function_exists('encryptData') ? encryptData($verifier_app_text_raw) : $verifier_app_text_raw;

$lead_data_query = mysqli_query($conn, "SELECT customer_name, first_name, email, closer_id, verification_count, verification_history FROM leads WHERE id = '$lead_id'");
$lead_data_raw = mysqli_fetch_assoc($lead_data_query);
$lead_data = function_exists('decrypt_fields') ? decrypt_fields($lead_data_raw) : $lead_data_raw;

$c_id = $lead_data['closer_id'] ?? null;
$name = $lead_data['customer_name'] ?? null;
$raw_first_name = $lead_data['first_name'] ?? null;
$raw_email = $lead_data['email'] ?? null;

// ==========================================
// HANDLE CALLBACK
// ==========================================
if ($decision === 'cb') {
    $msg = "System: " . $final_v_name . " scheduled a Callback for " . date('M d, h:i A', strtotime($cb_time));
    mysqli_query($conn, "UPDATE leads SET 
         status = 'Pending Verification (CB)', 
         reminder_time = '$cb_time',
         verifier_id = '$final_v_id',
         verifier_name = '$final_v_name',
         updated_at = NOW()
         WHERE id = '$lead_id'");
    mysqli_query($conn, "INSERT INTO messages (lead_id, sender_id, content) VALUES ('$lead_id', '$final_v_id', '$msg')");

    if (file_exists(__DIR__ . '/../../config/pusher.php')) {
        require_once __DIR__ . '/../../config/pusher.php';
        trigger_pusher_event($lead_id, 'cb');
    }

    if (function_exists('workspace_close_db_connections')) workspace_close_db_connections();

    // Clean buffer and send pure JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Callback scheduled.']);
    exit;
}

// ==========================================
// HANDLE ALL OTHER DECISIONS
// ==========================================
$status_map = [
    'dnq' => 'DNQ',
    'nu' => 'NU',
    'reverify' => 'Reverify',
    'vm' => 'VM'
];

if (!isset($status_map[$decision])) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid decision selected.']);
    exit;
}

$new_status = $status_map[$decision];
$msg = "System: " . $final_v_name . " marked lead as " . strtoupper($decision);

$history = [];
if (!empty($lead_data_raw['verification_history'])) {
    $dec_hist = function_exists('decryptData') ? decryptData($lead_data_raw['verification_history']) : $lead_data_raw['verification_history'];
    if ($dec_hist) {
        $history = json_decode($dec_hist, true);
    }
}
if (!is_array($history)) $history = [];

$history[] = [
    'attempt' => ((int)$lead_data_raw['verification_count']) + 1,
    'date' => date('M d, Y h:i A'),
    'verifier' => $final_v_name,
    'decision' => strtoupper($decision),
    'score' => $_POST['score'] ?? '0/0',
    'graded_text' => $verifier_app_text_raw
];
$new_hist_enc = function_exists('encryptData') ? encryptData(json_encode($history)) : json_encode($history);
$new_closer_status = ($decision === 'vm') ? 'VM' : 'Contacted';

if ($decision === 'reverify') {
    mysqli_query($conn, "UPDATE leads SET 
         status = '$new_status', 
         closer_status = '$new_closer_status',
         verifier_id = '$final_v_id',
         verifier_name = '$final_v_name',
         verifier_application = '$verifier_app', 
         verifier_application_text = NULL, 
         quiz_progress = NULL, 
         verification_history = '$new_hist_enc',
         verification_count = COALESCE(verification_count, 0) + 1,
         updated_at = NOW()
         WHERE id = '$lead_id'");
} else {
    $admin_status_update = ($decision === 'nu') ? "admin_status = 'approved'," : '';
    mysqli_query($conn, "UPDATE leads SET 
         status = '$new_status', 
         closer_status = '$new_closer_status',
         $admin_status_update
         verifier_id = '$final_v_id',
         verifier_name = '$final_v_name',
         verifier_application = '$verifier_app', 
         verifier_application_text = '$verifier_app_text',
         verification_history = '$new_hist_enc',
         updated_at = NOW()
         WHERE id = '$lead_id'");
}

mysqli_query($conn, "INSERT INTO messages (lead_id, sender_id, content) VALUES ('$lead_id', '$final_v_id', '$msg')");


// --------------------------------------------------------------------------
// 3. SYNCHRONOUS EMAILS & NOTIFICATIONS
// --------------------------------------------------------------------------
if (file_exists(__DIR__ . '/../../config/pusher.php')) {
    require_once __DIR__ . '/../../config/pusher.php';
}

if ($decision == 'nu') {
    $admins = mysqli_query($conn, "SELECT id FROM users WHERE role = 'Admin'");
    while ($adm = mysqli_fetch_assoc($admins)) {
        if (function_exists('notify')) notify($adm['id'], "New Qualified Lead: $name is ready to post.", "to_post.php", $conn);
    }
    if ($c_id && function_exists('notify')) {
        notify($c_id, "Congratulations! Lead $name was Verified by $final_v_name and sent to Admin for posting.", "posted_leads.php", $conn);
    }
    if (!empty($raw_email)) {
        $chc_final_subject = "Important Update: Your Application is Progressing";
        $chc_final_body = "Hello $raw_first_name,\n\nYour application is now handled by Consumer Healthcare Compliance, and will be sent ahead to the intakers, followed by the law firms who will fight your case. So we request you to stay with us for further updates. Also if you receive any documents in future, feel free to share them securely with us on records@consumerhealthcarecompliance.com or just reply to this email.\n\nThank you,\nConsumer Healthcare Compliance Team";
        $chc_final_headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: Consumer Healthcare Compliance <support@consumerhealthcarecompliance.com>\r\nReply-To: records@consumerhealthcarecompliance.com\r\n";
        @mail($raw_email, $chc_final_subject, $chc_final_body, $chc_final_headers);
    }
} elseif ($decision == 'reverify') {
    if ($c_id && function_exists('notify')) {
        notify($c_id, "Action Needed: $name sent back for Reverification by $final_v_name.", "manage_lead.php?id=$lead_id", $conn);
    }
} elseif ($decision == 'vm') {
    if ($c_id && function_exists('notify')) {
        notify($c_id, "Your Lead $name is on VM sent back from $final_v_name.", "manage_lead.php?id=$lead_id", $conn);
    }
} elseif ($decision == 'dnq') {
    if ($c_id && function_exists('notify')) {
        notify($c_id, "Lead $name was Disqualified by $final_v_name.", "manage_lead.php?id=$lead_id", $conn);
    }
}

trigger_pusher_event($lead_id, $decision);

if (function_exists('workspace_close_db_connections')) {
    workspace_close_db_connections();
}

// --------------------------------------------------------------------------
// 4. SAFE JSON RESPONSE OUTPUT
// --------------------------------------------------------------------------
// Clear the buffer (destroying any hidden PHP warnings/errors) so the JSON is pristine
ob_end_clean();

header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'Decision recorded successfully.']);
exit;

// ==========================================================================
// HELPER FUNCTIONS
// ==========================================================================

function trigger_pusher_event($lead_id, $decision)
{
    if (function_exists('workspace_pusher_server')) {
        try {
            $pusher = workspace_pusher_server();
            $pusher->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id, 'decision' => $decision]);
        } catch (Exception $e) {
        }
    }
}
