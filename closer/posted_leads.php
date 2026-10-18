<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../shared/smart_sync.php';
protect('Closer');

function workspace_trigger_lead_event($event, array $payload)
{
    $pusher = workspace_pusher_server();
    if ($pusher) {
        $pusher->trigger('lead-updates-channel', $event, $payload);
    }
}

// ==========================================
// SAFELY ENSURE NEW COLUMNS EXIST
// ==========================================
$new_cols = [
    'closer_status' => "VARCHAR(150) DEFAULT NULL",
    'jornaya_leadid' => "VARCHAR(255) DEFAULT NULL",
    'trustedform_cert_url' => "VARCHAR(255) DEFAULT NULL",
    'id_upload_token' => "VARCHAR(255) DEFAULT NULL",
    'customer_id' => "VARCHAR(255) DEFAULT NULL",
    'ip_address' => "VARCHAR(100) DEFAULT NULL"
];
foreach ($new_cols as $col => $def) {
    $check = $conn->query("SHOW COLUMNS FROM leads LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE leads ADD COLUMN $col $def");
    }
}

mysqli_query($conn, "ALTER TABLE leads MODIFY COLUMN closer_status VARCHAR(150) DEFAULT NULL");
mysqli_query($conn, "UPDATE leads SET closer_status = NULL WHERE closer_status LIKE '%Data Truncated%' OR closer_status LIKE 'ENC:%'");

if (($_GET['action'] ?? '') === 'fetch_report') {
    header('Content-Type: application/json');
    $lead_id = (int)($_GET['lead_id'] ?? 0);
    $stmt = $conn->prepare('SELECT verifier_application_text, closer_status FROM leads WHERE id = ? AND closer_id = ? LIMIT 1');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load report']);
        exit;
    }
    $stmt->bind_param('ii', $lead_id, $_SESSION['user_id']);
    $stmt->execute();
    $report_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$report_data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'report' => html_entity_decode((string)decryptData((string)($report_data['verifier_application_text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'can_mark_done' => ($report_data['closer_status'] ?? '') === 'Processing the Information'
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_POST['action'] ?? '') === 'mark_report_done') {
    header('Content-Type: application/json');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $check_stmt = $conn->prepare('SELECT closer_status FROM leads WHERE id = ? AND closer_id = ? LIMIT 1');
    if (!$check_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update lead']);
        exit;
    }
    $check_stmt->bind_param('ii', $lead_id, $_SESSION['user_id']);
    $check_stmt->execute();
    $lead_status = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    if (!$lead_status) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }
    if (($lead_status['closer_status'] ?? '') !== 'Processing the Information') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This report is not ready to mark as done']);
        exit;
    }
    $update_stmt = $conn->prepare("UPDATE leads SET status = 'Pending Post', closer_status = 'Pending Post' WHERE id = ? AND closer_id = ?");
    if (!$update_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update lead']);
        exit;
    }
    $update_stmt->bind_param('ii', $lead_id, $_SESSION['user_id']);
    $updated = $update_stmt->execute();
    $update_stmt->close();
    if (!$updated) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update lead']);
        exit;
    }
    $sync_ok = workspace_post_smart_sync_payload([
        'id' => $lead_id,
        'status' => 'Pending Post',
        'closer_status' => 'Pending Post',
        'auth_key' => 'MakeMoney$$'
    ]);
    if (!$sync_ok) error_log('[Closer report] Live status sync failed for lead ' . $lead_id);
    echo json_encode(['success' => true, 'synced' => $sync_ok]);
    exit;
}

if (($_GET['action'] ?? '') === 'fetch_inhouse_chat') {
    header('Content-Type: application/json');
    $lead_id = (int)($_GET['lead_id'] ?? 0);
    $closer_id = (int)$_SESSION['user_id'];
    $ownership_stmt = $conn->prepare('SELECT id FROM leads WHERE id = ? AND closer_id = ? LIMIT 1');
    $ownership_stmt->bind_param('ii', $lead_id, $closer_id);
    $ownership_stmt->execute();
    $lead_exists = $ownership_stmt->get_result()->fetch_assoc();
    $ownership_stmt->close();
    if (!$lead_exists) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }
    $read_stmt = $conn->prepare("UPDATE inhouse_chats SET is_read = 1 WHERE lead_id = ? AND closer_id = ? AND sender_type = 'Admin'");
    $read_stmt->bind_param('ii', $lead_id, $closer_id);
    $read_stmt->execute();
    $read_stmt->close();
    $chat_stmt = $conn->prepare('SELECT * FROM inhouse_chats WHERE lead_id = ? AND closer_id = ? ORDER BY created_at ASC');
    $chat_stmt->bind_param('ii', $lead_id, $closer_id);
    $chat_stmt->execute();
    $result = $chat_stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) $messages[] = $row;
    $chat_stmt->close();
    echo json_encode($messages, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_POST['action'] ?? '') === 'send_inhouse_chat') {
    header('Content-Type: application/json');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $closer_id = (int)$_SESSION['user_id'];
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '' || $lead_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message and lead are required']);
        exit;
    }
    $ownership_stmt = $conn->prepare('SELECT id FROM leads WHERE id = ? AND closer_id = ? LIMIT 1');
    $ownership_stmt->bind_param('ii', $lead_id, $closer_id);
    $ownership_stmt->execute();
    $lead_exists = $ownership_stmt->get_result()->fetch_assoc();
    $ownership_stmt->close();
    if (!$lead_exists) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }
    $chat_stmt = $conn->prepare("INSERT INTO inhouse_chats (lead_id, closer_id, admin_id, message, sender_type, is_read) VALUES (?, ?, 0, ?, 'Closer', 0)");
    if (!$chat_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to send message']);
        exit;
    }
    $chat_stmt->bind_param('iis', $lead_id, $closer_id, $message);
    if (!$chat_stmt->execute()) {
        $chat_stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to send message']);
        exit;
    }
    $chat_stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ==========================================
// AJAX: CHAT MODAL
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'fetch_chat') {
    $lead_id = (int)$_GET['lead_id'];
    $msg_query = mysqli_query($conn, "SELECT m.content, m.created_at, u.username, u.role 
          FROM messages m JOIN users u ON m.sender_id = u.id 
          WHERE m.lead_id = '$lead_id' ORDER BY m.created_at ASC");
    $messages = [];
    while ($msg = mysqli_fetch_assoc($msg_query)) {
        $messages[] = [
            'content' => $msg['content'],
            'created_at' => date('M d, H:i', strtotime($msg['created_at'])),
            'username' => $msg['username'],
            'role' => $msg['role']
        ];
    }
    echo json_encode($messages);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'send_chat') {
    $lead_id = (int)$_POST['lead_id'];
    $message = trim($_POST['message']);
    $sender_id = $_SESSION['user_id'];
    if (!empty($message)) {
        $stmt = $conn->prepare("INSERT INTO messages (lead_id, sender_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $lead_id, $sender_id, $message);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'save_callback') {
    $lead_id = (int)$_POST['lead_id'];
    $dt = $_POST['callback_time'];
    $tz = $_POST['timezone'];

    $date = new DateTime($dt, new DateTimeZone($tz));
    $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $server_time = $date->format('Y-m-d H:i:s');

    $conn->query("ALTER TABLE leads ADD COLUMN IF NOT EXISTS cb_notified TINYINT(1) DEFAULT 0");
    $stmt = $conn->prepare("UPDATE leads SET closer_status = 'CB', reminder_time = ?, callback_time = ?, cb_notified = 0 WHERE id = ?");
    $stmt->bind_param("ssi", $server_time, $server_time, $lead_id);
    if ($stmt->execute()) echo json_encode(['success' => true]);
    else echo json_encode(['success' => false]);
    $stmt->close();
    exit;
}

// ==========================================
// CAL POPUP LOGIC (WITH DIRECT SMTP & HTML DESIGN)
// ==========================================
if (!function_exists('server_parse')) {
    function server_parse($socket, $expected)
    {
        $response = '';
        while (($line = @fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return substr($response, 0, 3) === $expected;
    }
}

if (!function_exists('send_smtp_mail')) {
    function send_smtp_mail($to, $subject, $message, $from_name, $smtp_host, $smtp_user, $smtp_pass, $smtp_port, $is_html = true)
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $subject . $from_name)) {
            error_log('[Closer SMTP] Refusing invalid recipient or mail header.');
            return false;
        }
        if ($smtp_pass === '') {
            error_log('[Closer SMTP] SMTP password is not configured.');
            return false;
        }

        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = @stream_socket_client("ssl://$smtp_host:$smtp_port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            error_log('[Closer SMTP] Connection failed (' . (int)$errno . '): ' . preg_replace('/[\r\n]+/', ' ', $errstr));
            return false;
        }
        stream_set_timeout($socket, 10);

        $fail = static function ($stage) use (&$socket) {
            error_log('[Closer SMTP] SMTP exchange failed during ' . $stage . '.');
            if (is_resource($socket)) fclose($socket);
            return false;
        };
        $command = static function ($data, $expected) use ($socket) {
            return @fwrite($socket, $data . "\r\n") !== false && server_parse($socket, $expected);
        };

        if (!server_parse($socket, '220')) return $fail('server greeting');
        if (!$command("EHLO $smtp_host", '250')) return $fail('EHLO');
        if (!$command('AUTH LOGIN', '334')) return $fail('AUTH LOGIN');
        if (!$command(base64_encode($smtp_user), '334')) return $fail('SMTP username authentication');
        if (!$command(base64_encode($smtp_pass), '235')) return $fail('SMTP password authentication');
        if (!$command("MAIL FROM: <$smtp_user>", '250')) return $fail('sender address');
        if (!$command("RCPT TO: <$to>", '250')) return $fail('recipient address');
        if (!$command('DATA', '354')) return $fail('message data request');

        $content_type = $is_html ? 'text/html' : 'text/plain';
        $headers  = 'Date: ' . date('r') . "\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "From: \"$from_name\" <$smtp_user>\r\n";
        $headers .= "Reply-To: $smtp_user\r\n";
        $headers .= 'Subject: ' . $subject . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: $content_type; charset=UTF-8\r\n";
        $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        $body = preg_replace('/^\./m', '..', $body);
        if (@fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n") === false || !server_parse($socket, '250')) {
            return $fail('message delivery');
        }

        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }
}

function send_c2c_application_email($to, $first_name, $vertical, $c2c_id)
{
    $smtp_host = 'mail.pulaskikherkherlaw.info';
    $smtp_user = 'support@pulaskikherkherlaw.info';
    $smtp_pass = 'TheBigBull@123#';
    $smtp_port = 465;
    $c2c_link = "https://pulaskikherkherlaw.info/customer_application?token=" . urlencode($c2c_id);
    $subject = "Action Required: Claim Questionnaire & Document Review - Pulaski Kherkher Law Group";
    $message = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:24px 12px; background-color:#f4f6f8; font-family:Arial, sans-serif; color:#333333;'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0'>
            <tr>
                <td align='center'>
                    <table width='600' cellpadding='0' cellspacing='0' border='0' style='width:100%; max-width:600px; background:#ffffff; border:1px solid #e2e6ea;'>
                        <tr>
                            <td style='padding:32px 36px; border-top:4px solid #0A2540;'>
                                <h2 style='margin:0 0 20px; color:#0A2540; font-size:20px;'>Action Required: Claim Questionnaire &amp; Document Review</h2>
                                <p style='margin:0 0 16px; font-size:15px; line-height:1.6;'>Hello <strong>" . htmlspecialchars((string)$first_name, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
                                <p style='margin:0 0 16px; font-size:15px; line-height:1.6;'>As part of handling your <strong>" . htmlspecialchars((string)$vertical, ENT_QUOTES, 'UTF-8') . "</strong> claim, we have prepared your application details and supporting documents for review.</p>
                                <p style='margin:0 0 20px; font-size:15px; line-height:1.6;'>Please review your application and helping questions using the secure link below. Completing this step ensures our legal team has the accurate information needed for your case evaluation:</p>
                                <p style='margin:0 0 24px;'>
                                    <a href='{$c2c_link}' style='display:inline-block; padding:12px 24px; background-color:#0A2540; color:#ffffff; text-decoration:none; font-weight:bold; border-radius:6px; font-size:14px;'>Review Application &amp; Documents</a>
                                </p>
                                <p style='margin:0 0 16px; font-size:13px; color:#64748b;'>Or copy and paste this link into your browser:<br><a href='{$c2c_link}' style='color:#2563eb;'>{$c2c_link}</a></p>
                                <p style='margin:0; font-size:14px; line-height:1.6;'>Please review this as soon as possible to avoid any delays in your case review.<br><br>Thank you,<br><strong>Pulaski Kherkher Law Group Support Team</strong></p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";

    return send_smtp_mail($to, $subject, $message, "Pulaski Kherkher Law Group", $smtp_host, $smtp_user, $smtp_pass, $smtp_port, true);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['send_dashboard_link']) || isset($_POST['send_approval_link']))) {
    $lead_id = (int)$_POST['lead_id'];
    $is_approval = isset($_POST['send_approval_link']);

    $q_check = mysqli_query($conn, "SELECT customer_id FROM leads WHERE id = '$lead_id'");
    $lead_data = mysqli_fetch_assoc($q_check);

    if (empty($lead_data['customer_id'])) {
        $cust_id_gen = 'CAC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $conn->prepare("UPDATE leads SET customer_id = ? WHERE id = ?");
        $stmt->bind_param("si", $cust_id_gen, $lead_id);
        $stmt->execute();
        $customer_id_to_send = $cust_id_gen;
    } else {
        $customer_id_to_send = $lead_data['customer_id'];
    }

    $q = mysqli_query($conn, "SELECT customer_name, email, phone, vertical, id_upload_token FROM leads WHERE id = '$lead_id'");
    $lead_email_data = mysqli_fetch_assoc($q);
    $lead_email_data = function_exists('decrypt_fields') ? decrypt_fields($lead_email_data) : $lead_email_data;

    $to = $lead_email_data['email'] ?? '';
    $vertical = $lead_email_data['vertical'] ?? '';

    $payload = json_encode([
        'lead_id'      => $lead_id,
        'customer_id'  => $customer_id_to_send,
        'name'         => $lead_email_data['customer_name'],
        'email'        => $to,
        'phone'        => $lead_email_data['phone'] ?? '',
        'vertical'     => $vertical,
        'status'       => 'Waiting on Customer',
        'auth_key'     => 'MakeMoney$$'
    ]);

    $ch = curl_init('https://consumerhealthcarecompliance.com/api/sync_dashboard_id.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);

    if (!empty($to)) {
        $smtp_host = 'mail.pulaskikherkherlaw.info';
        $smtp_user = 'support@pulaskikherkherlaw.info';
        $smtp_pass = 'TheBigBull@123#';
        $smtp_port = 465;

        $login_link = "https://pulaskikherkherlaw.info/customer_login?id=" . urlencode($customer_id_to_send);
        $customer_name = $lead_email_data['customer_name'] ?? 'Customer';

        if ($is_approval) {
            $closer_name = trim($_POST['closer_name'] ?? '');
            $closer_phone = trim($_POST['closer_phone'] ?? '');
            $law_firm = trim($_POST['law_firm'] ?? '');

            $subject = "Congratulations for {$vertical} compensation acceptence.";

            $message = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            </head>
            <body style='margin:0; padding:0; background-color:#ffffff; font-family:Arial, Helvetica, sans-serif; color:#333333;'>
                <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#ffffff;'>
                    <tr>
                        <td align='left' style='padding: 20px;'>
                            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background:#ffffff;'>
                                <tr>
                                    <td style='padding-bottom:20px; border-bottom:2px solid #0A2540;'>
                                        <div style='font-size:22px; font-weight:bold; color:#0A2540;'>Matthews & Associates</div>
                                        <div style='font-size:11px; color:#68747e; letter-spacing:1px; margin-top:5px; text-transform:uppercase;'>Case Acceptance & Next Steps</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:25px 0 15px 0;'>
                                        <p style='margin:0 0 15px 0; font-size:15px;'>Dear <strong>{$customer_name}</strong>,</p>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>We are writing this to congratulate you for a progressive acceptance regarding your involvement in the <strong>{$vertical}</strong> Lawsuit.</p>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>As we received your signed documents submitted from <strong>{$law_firm}</strong> confirming your participation in the class action lawsuit against the brand manufacturer for unexpected loss, injury, or harm, your agreement indicates a diagnosis of serious health complications from toxic product & services exposure. Your case is being handled with the utmost seriousness.</p>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>Matthews & Associates supports individuals and families seeking justice against defective & unsafe products, potential personal injury litigation, consumer healthcare matters and corporate negligence through class action.</p>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>Your submitted information and supporting documentation have been added to your case file and are currently undergoing further review.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 20px 0;'>
                                        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f8fafc; border-left:4px solid #2563eb; padding:15px;'>
                                            <tr>
                                                <td>
                                                    <div style='font-size:14px; font-weight:bold; color:#1e293b; margin-bottom:8px;'>Current Status</div>
                                                    <div style='font-size:13px; line-height:1.6; color:#475569;'>Moving forward, <strong>{$law_firm}</strong> will oversee the remaining legal aspects of your matter. Your claim is currently undergoing further review and, where applicable, assessment for potential reimbursement or settlement consideration.<br><br>This preliminary status indicates that the information submitted has satisfied the applicable initial review requirements.</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 15px 0;'>
                                        <div style='font-size:15px; font-weight:bold; color:#0A2540; padding-bottom:8px; border-bottom:1px solid #e2e8f0; margin-bottom:12px;'>Administrative Review</div>
                                        <ul style='margin:0; padding-left:20px; font-size:13px; line-height:1.6; color:#4a5568;'>
                                            <li style='margin-bottom:6px;'>Your submitted information has completed the applicable initial review.</li>
                                            <li style='margin-bottom:6px;'>Your claim has been accepted for continued processing and legal evaluation.</li>
                                            <li style='margin-bottom:6px;'>The supporting documentation provided has been added to your case file.</li>
                                            <li style='margin-bottom:6px;'>Your matter will proceed through the applicable review and administrative procedures.</li>
                                            <li style='margin-bottom:6px;'>Additional information or documentation may be requested as the review progresses.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 20px 0;'>
                                        <div style='font-size:15px; font-weight:bold; color:#0A2540; padding-bottom:8px; border-bottom:1px solid #e2e8f0; margin-bottom:12px;'>Important Information</div>
                                        <ul style='margin:0; padding-left:20px; font-size:13px; line-height:1.6; color:#4a5568;'>
                                            <li style='margin-bottom:6px;'>This preliminary status does not constitute a final determination regarding liability, settlement eligibility, or compensation.</li>
                                            <li style='margin-bottom:6px;'>Any potential settlement, reimbursement, or other recovery will depend on the specific circumstances of your claim and the applicable agreements, court orders, and governing laws.</li>
                                            <li style='margin-bottom:6px;'>Prompt responses may help prevent unnecessary delays.</li>
                                            <li style='margin-bottom:6px;'>Please notify us promptly if your contact information changes so that you can continue receiving important updates regarding your matter.</li>
                                            <li style='margin-bottom:6px;'>No specific settlement amount or reimbursement is guaranteed unless and until formally determined through the applicable legal process.</li>
                                        </ul>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:10px 0 25px 0;'>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>Our processing team will continue to monitor the status of your file and provide updates as they become available. You may access the applicable case-status portal using the secure link below:</p>
                                        <a href='{$login_link}' style='display:inline-block; padding:12px 25px; background-color:#0A2540; color:#ffffff; text-decoration:none; font-weight:bold; border-radius:6px; font-size:14px;'>Access Case Status Portal</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 30px 0;'>
                                        <p style='margin:0 0 15px 0; font-size:13px; line-height:1.6; color:#4a5568;'>If you have questions regarding your legal rights, eligibility, or potential recovery, please consult the appropriate legal representative handling your matter.<br><br>If you know someone who may have experienced similar circumstances, they may wish to seek an independent legal evaluation to determine whether they may have a potential claim. Our purpose is to provide general information and help you determine whether you may qualify for a class action lawsuit with transparent consultation with our professionals.</p>
                                        <p style='margin:0 0 20px 0; font-size:14px; font-weight:bold; color:#0A2540;'>We are here to support you for the justice you deserve.<br>Thank you for your attention and cooperation.</p>
                                        <table cellpadding='0' cellspacing='0' border='0'>
                                            <tr>
                                                <td style='border-left:3px solid #2563eb; padding-left:14px;'>
                                                    <div style='font-size:15px; font-weight:bold; color:#0A2540;'>{$closer_name}</div>
                                                    <div style='font-size:12px; color:#68747e; margin-top:3px;'>Legal Intake Representative</div>
                                                    <div style='font-size:12px; color:#68747e; margin-top:2px;'>Matthews & Associates</div>
                                                    <div style='font-size:12px; color:#68747e; margin-top:2px;'>{$closer_phone}</div>
                                                    <div style='font-size:12px; margin-top:5px;'><a href='https://pulaskikherkherlaw.info' style='color:#2563eb; text-decoration:none;'>pulaskikherkherlaw.info</a></div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";

            $mail_sent = send_smtp_mail($to, $subject, $message, "Matthews & Associates", $smtp_host, $smtp_user, $smtp_pass, $smtp_port, true);
        } else {
            $subject = "Your Case Portal Access";

            $message = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            </head>
            <body style='margin:0; padding:0; background-color:#ffffff; font-family:Arial, Helvetica, sans-serif; color:#333333;'>
                <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#ffffff;'>
                    <tr>
                        <td align='left' style='padding: 20px;'>
                            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background:#ffffff;'>
                                <tr>
                                    <td style='padding-bottom:20px; border-bottom:2px solid #0A2540;'>
                                        <div style='font-size:22px; font-weight:bold; color:#0A2540;'>Matthews & Associates</div>
                                        <div style='font-size:11px; color:#68747e; letter-spacing:1px; margin-top:5px; text-transform:uppercase;'>Secure Portal Access</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:25px 0 20px 0;'>
                                        <p style='margin:0 0 15px 0; font-size:15px;'>Dear <strong>{$customer_name}</strong>,</p>
                                        <p style='margin:0 0 15px 0; font-size:14px; line-height:1.6; color:#4a5568;'>Your secure portal access has been generated successfully. You can use this portal to track the status of your case and upload required documentation.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 20px 0;'>
                                        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f8fafc; border-left:4px solid #2563eb; padding:15px;'>
                                            <tr>
                                                <td>
                                                    <div style='font-size:13px; line-height:1.6; color:#475569;'>
                                                        <strong>Your Customer ID:</strong> {$customer_id_to_send}<br><br>
                                                        <em>For your security, you will be prompted to set up your own password upon your first login.</em>
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:10px 0 25px 0;'>
                                        <a href='{$login_link}' style='display:inline-block; padding:12px 25px; background-color:#0A2540; color:#ffffff; text-decoration:none; font-weight:bold; border-radius:6px; font-size:14px;'>Access Secure Portal</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:0 0 30px 0;'>
                                        <p style='margin:0 0 20px 0; font-size:14px; color:#4a5568;'>Thank you,<br><strong style='color:#0A2540;'>Matthews & Associates Team</strong></p>
                                        <table cellpadding='0' cellspacing='0' border='0'>
                                            <tr>
                                                <td style='border-left:3px solid #2563eb; padding-left:14px;'>
                                                    <div style='font-size:12px; color:#68747e; margin-top:2px;'>Case Support Team</div>
                                                    <div style='font-size:12px; color:#68747e; margin-top:2px;'>Matthews & Associates</div>
                                                    <div style='font-size:12px; margin-top:5px;'><a href='https://pulaskikherkherlaw.info' style='color:#2563eb; text-decoration:none;'>pulaskikherkherlaw.info</a></div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            ";

            $mail_sent = send_smtp_mail($to, $subject, $message, "Matthews & Associates", $smtp_host, $smtp_user, $smtp_pass, $smtp_port, true);
        }

        if (!$mail_sent) {
            $success_message = "Dashboard Link synced, but SMTP Email failed. Check server logs.";
        } else {
            $success_message = "Customer ID Generated and " . ($is_approval ? "Approval Email " : "Dashboard Link Email ") . "Sent Successfully!";
        }
    } else {
        $success_message = "Customer ID Generated and Synced, but no email address was found to send it to.";
    }
}

// ==========================================
// C2C LINKS POST HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['send_dashboard_link']) && !isset($_POST['send_approval_link']) && !isset($_POST['action'])) {

    if (isset($_POST['generate_c2c'])) {
        $lead_id = (int)$_POST['lead_id'];

        $c2c_id = bin2hex(random_bytes(16));
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

        $uploaded_paths = [];
        $upload_dir = '../../workspace/uploads/c2c_docs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        if (isset($_FILES['c2c_files']) && !empty($_FILES['c2c_files']['name'][0])) {
            foreach ($_FILES['c2c_files']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['c2c_files']['size'][$key] > 0) {
                    $filename = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $_FILES['c2c_files']['name'][$key]);
                    $safe_filename = 'c2c_' . $lead_id . '_' . time() . '_' . $filename;
                    $target_path = $upload_dir . $safe_filename;
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $uploaded_paths[] = 'workspace/uploads/c2c_docs/' . $safe_filename;
                    }
                }
            }
        }
        $docs_string = !empty($uploaded_paths) ? implode(',', $uploaded_paths) : NULL;
        $new_id_upload_token = bin2hex(random_bytes(16));
        $token_stmt = $conn->prepare("SELECT id_upload_token FROM leads WHERE id = ?");
        $token_stmt->bind_param("i", $lead_id);
        $token_stmt->execute();
        $current_token_row = $token_stmt->get_result()->fetch_assoc();
        $token_stmt->close();
        $current_id_upload_token = $current_token_row['id_upload_token'] ?? '';

        $shared_token_stmt = $conn->prepare("SELECT id FROM leads WHERE id != ? AND id_upload_token = ? LIMIT 1");
        $shared_token_stmt->bind_param("is", $lead_id, $current_id_upload_token);
        $shared_token_stmt->execute();
        $token_is_shared = !empty($current_id_upload_token) && $shared_token_stmt->get_result()->num_rows > 0;
        $shared_token_stmt->close();

        if (empty($current_id_upload_token) || $token_is_shared) {
            do {
                $token_check_stmt = $conn->prepare("SELECT id FROM leads WHERE id != ? AND id_upload_token = ? LIMIT 1");
                $token_check_stmt->bind_param("is", $lead_id, $new_id_upload_token);
                $token_check_stmt->execute();
                $token_is_unique = $token_check_stmt->get_result()->num_rows === 0;
                $token_check_stmt->close();
                if (!$token_is_unique) {
                    $new_id_upload_token = bin2hex(random_bytes(16));
                }
            } while (!$token_is_unique);
        } else {
            $new_id_upload_token = $current_id_upload_token;
        }

        $stmt = $conn->prepare("UPDATE leads SET c2c_id = ?, c2c_docs = ?, id_upload_token = ? WHERE id = ?");
        $stmt->bind_param("sssi", $c2c_id, $docs_string, $new_id_upload_token, $lead_id);

        if ($stmt->execute()) {
            workspace_sync_c2c_id_to_live($lead_id, $c2c_id);
            $q = mysqli_query($conn, "SELECT phone_hash, id_upload_token, customer_name, first_name, last_name, email, phone, address, city, state, zip_code, dob, vertical, questions FROM leads WHERE id = '$lead_id'");
            $lead = mysqli_fetch_assoc($q);

            if ($lead) {
                if (function_exists('decrypt_fields')) {
                    $lead = decrypt_fields($lead);
                }

                $vertical = $lead['vertical'] ?? '';
                $to = $lead['email'] ?? '';
                $first_name = !empty($lead['first_name']) ? $lead['first_name'] : ($lead['customer_name'] ?? 'Customer');
                $id_upload_token = $lead['id_upload_token'] ?? '';

                $absolute_docs = [];
                if (!empty($uploaded_paths)) {
                    foreach ($uploaded_paths as $path) {
                        $absolute_docs[] = "https://consumerhealthcarecompliance.com/" . $path;
                    }
                }

                // Webhook A: Email Triggers
                $mat_payload = json_encode([
                    'id'    => $lead_id,
                    'token'      => $id_upload_token,
                    'first_name' => $first_name,
                    'last_name'  => $lead['last_name'] ?? '',
                    'email'      => $to,
                    'phone'      => $lead['phone'] ?? '',
                    'vertical'   => $vertical,
                    'send_email' => false,
                    'auth_key'   => 'MakeMoney$$'
                ]);
                $ch1 = curl_init('https://consumerhealthcarecompliance.com/api/sync_token.php');
                curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch1, CURLOPT_POST, true);
                curl_setopt($ch1, CURLOPT_POSTFIELDS, $mat_payload);
                curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch1, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch1, CURLOPT_TIMEOUT, 10);
                curl_exec($ch1);
                curl_close($ch1);

                // Webhook B: Unified Database Sync
                $c2c_payload = json_encode([
                    'id'            => $lead_id,
                    'phone_hash'    => $lead['phone_hash'] ?? '',
                    'c2c_id'        => $c2c_id,
                    'first_name'    => $first_name,
                    'last_name'     => $lead['last_name'] ?? '',
                    'customer_name' => $lead['customer_name'] ?? '',
                    'email'         => $to,
                    'phone'         => $lead['phone'] ?? '',
                    'address'       => $lead['address'] ?? '',
                    'city'          => $lead['city'] ?? '',
                    'state'         => $lead['state'] ?? '',
                    'zip_code'      => $lead['zip_code'] ?? '',
                    'dob'           => $lead['dob'] ?? '',
                    'vertical'      => $vertical,
                    'questions'     => $lead['questions'] ?? '',
                    'c2c_docs_path' => implode(',', $absolute_docs),
                    'send_email'    => false,
                    'auth_key'      => 'MakeMoney$$',
                    'updates'       => [
                        'c2c_id'        => $c2c_id,
                        'first_name'    => $first_name,
                        'last_name'     => $lead['last_name'] ?? '',
                        'customer_name' => $lead['customer_name'] ?? '',
                        'email'         => $to,
                        'phone'         => $lead['phone'] ?? '',
                        'address'       => $lead['address'] ?? '',
                        'city'          => $lead['city'] ?? '',
                        'state'         => $lead['state'] ?? '',
                        'zip_code'      => $lead['zip_code'] ?? '',
                        'dob'           => $lead['dob'] ?? '',
                        'vertical'      => $vertical,
                        'questions'     => $lead['questions'] ?? '',
                        'c2c_docs'      => implode(',', $absolute_docs)
                    ]
                ]);

                $ch2 = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, $c2c_payload);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
                curl_exec($ch2);
                curl_close($ch2);

                $email_sent = !empty($to) && send_c2c_application_email($to, $first_name, $vertical, $c2c_id);
                $success_message = "Application Links Generated and Full Profile Synced via Live Server! " .
                    ($email_sent ? "Customer application email sent." : (!empty($to) ? "Customer application email failed; check server logs." : "No customer email address was found."));
            }
        } else {
            $success_message = "Error generating links.";
        }
        $stmt->close();
    }

    if (isset($_POST['upload_more_c2c'])) {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $closer_id = (int)($_SESSION['user_id'] ?? 0);
        $upload_is_ajax = isset($_POST['ajax_upload']);
        $upload_message = 'Error: Lead not found.';

        $lead_stmt = $conn->prepare("SELECT c2c_id, c2c_docs, first_name, last_name, customer_name, email, phone, address, city, state, zip_code, dob, vertical, questions, phone_hash FROM leads WHERE id = ? AND closer_id = ?");
        $lead_stmt->bind_param("ii", $lead_id, $closer_id);
        $lead_stmt->execute();
        $lead = $lead_stmt->get_result()->fetch_assoc();
        $lead_stmt->close();

        if ($lead) {
            if (function_exists('decrypt_fields')) $lead = decrypt_fields($lead);
            $first_name = !empty($lead['first_name']) ? $lead['first_name'] : ($lead['customer_name'] ?? 'Customer');
            $generated_c2c_id = empty($lead['c2c_id']);
            $c2c_id = $generated_c2c_id ? bin2hex(random_bytes(16)) : $lead['c2c_id'];
            $uploaded_paths = [];
            $upload_dir = '../../workspace/uploads/c2c_docs/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            if (isset($_FILES['c2c_files']) && is_array($_FILES['c2c_files']['name'])) {
                $file_info = new finfo(FILEINFO_MIME_TYPE);
                foreach ($_FILES['c2c_files']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['c2c_files']['error'][$key] !== UPLOAD_ERR_OK || $_FILES['c2c_files']['size'][$key] <= 0) continue;

                    $mime_type = $file_info->file($tmp_name);
                    $allowed_extensions = [
                        'application/pdf' => 'pdf',
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/bmp' => 'bmp',
                        'image/tiff' => 'tif'
                    ];
                    if (!isset($allowed_extensions[$mime_type])) continue;

                    $safe_filename = 'c2c_' . $lead_id . '_' . uniqid() . '.' . $allowed_extensions[$mime_type];
                    if (move_uploaded_file($tmp_name, $upload_dir . $safe_filename)) {
                        $uploaded_paths[] = 'workspace/uploads/c2c_docs/' . $safe_filename;
                    }
                }
            }

            if (!empty($uploaded_paths)) {
                $existing_docs = array_filter(array_map('trim', explode(',', $lead['c2c_docs'] ?? '')));
                $final_docs = implode(',', array_merge($existing_docs, $uploaded_paths));
                $update_stmt = $conn->prepare("UPDATE leads SET c2c_id = ?, c2c_docs = ? WHERE id = ? AND closer_id = ?");
                $update_stmt->bind_param("ssii", $c2c_id, $final_docs, $lead_id, $closer_id);

                if ($update_stmt->execute()) {
                    $upload_message = 'Success: Documents attached to the customer portal.';
                    if ($generated_c2c_id) workspace_sync_c2c_id_to_live($lead_id, $c2c_id);

                    if (function_exists('curl_init')) {
                        $absolute_docs = array_map(
                            static fn($path) => preg_match('~^https?://~i', $path) ? $path : 'https://consumerhealthcarecompliance.com/' . ltrim($path, '/'),
                            array_filter(array_map('trim', explode(',', $final_docs)))
                        );
                        $c2c_updates = [
                            'c2c_id' => $c2c_id,
                            'first_name' => $first_name,
                            'last_name' => $lead['last_name'] ?? '',
                            'customer_name' => $lead['customer_name'] ?? '',
                            'email' => $lead['email'] ?? '',
                            'phone' => $lead['phone'] ?? '',
                            'address' => $lead['address'] ?? '',
                            'city' => $lead['city'] ?? '',
                            'state' => $lead['state'] ?? '',
                            'zip_code' => $lead['zip_code'] ?? '',
                            'dob' => $lead['dob'] ?? '',
                            'vertical' => $lead['vertical'] ?? '',
                            'questions' => $lead['questions'] ?? '',
                            'c2c_docs' => implode(',', $absolute_docs)
                        ];
                        $c2c_payload = json_encode([
                            'id' => $lead_id,
                            'phone_hash' => $lead['phone_hash'] ?? '',
                            'c2c_id' => $c2c_id,
                            'first_name' => $first_name,
                            'last_name' => $lead['last_name'] ?? '',
                            'customer_name' => $lead['customer_name'] ?? '',
                            'email' => $lead['email'] ?? '',
                            'phone' => $lead['phone'] ?? '',
                            'address' => $lead['address'] ?? '',
                            'city' => $lead['city'] ?? '',
                            'state' => $lead['state'] ?? '',
                            'zip_code' => $lead['zip_code'] ?? '',
                            'dob' => $lead['dob'] ?? '',
                            'vertical' => $lead['vertical'] ?? '',
                            'questions' => $lead['questions'] ?? '',
                            'c2c_docs_path' => implode(',', $absolute_docs),
                            'send_email' => false,
                            'auth_key' => 'MakeMoney$$',
                            'updates' => $c2c_updates
                        ], JSON_INVALID_UTF8_SUBSTITUTE);
                        $ch_sync = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
                        curl_setopt($ch_sync, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch_sync, CURLOPT_POST, true);
                        curl_setopt($ch_sync, CURLOPT_POSTFIELDS, $c2c_payload);
                        curl_setopt($ch_sync, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                        curl_setopt($ch_sync, CURLOPT_TIMEOUT, 3);
                        curl_setopt($ch_sync, CURLOPT_SSL_VERIFYPEER, false);
                        $sync_response = curl_exec($ch_sync);
                        if ($sync_response === false) error_log('[Closer C2C] Document sync failed for lead ' . $lead_id . ': ' . curl_error($ch_sync));
                        curl_close($ch_sync);
                    }
                    $email_sent = !empty($lead['email']) && send_c2c_application_email($lead['email'], $first_name, $lead['vertical'] ?? '', $c2c_id);
                    $upload_message .= $email_sent ? ' Customer application email sent.' : (!empty($lead['email']) ? ' Customer application email failed; check server logs.' : ' No customer email address was found.');
                } else {
                    $upload_message = 'Error: Could not save the uploaded documents.';
                }
                $update_stmt->close();
            } else {
                $upload_message = 'Error: No valid PDF or image files were uploaded.';
            }
        }

        if ($upload_is_ajax) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $upload_message;
            exit;
        }
        $success_message = $upload_message;
    }

    if (isset($_POST['revoke_c2c'])) {
        $lead_id = (int)$_POST['lead_id'];
        $q = mysqli_query($conn, "SELECT c2c_docs, phone_hash FROM leads WHERE id = '$lead_id'");
        if ($row = mysqli_fetch_assoc($q)) {
            if (!empty($row['c2c_docs'])) {
                $files_to_delete = explode(',', $row['c2c_docs']);
                foreach ($files_to_delete as $file) {
                    $file_path = '../../' . ltrim($file, '/');
                    if (file_exists($file_path)) unlink($file_path);
                }
            }

            $stmt = $conn->prepare("UPDATE leads SET c2c_id = NULL, c2c_docs = NULL WHERE id = ?");
            $stmt->bind_param("i", $lead_id);

            if ($stmt->execute()) {
                $revoke_payload = json_encode([
                    'id'         => $lead_id,
                    'auth_key'   => 'MakeMoney$$',
                    'phone_hash' => $row['phone_hash'] ?? '',
                    'updates'    => [
                        'c2c_id'   => "",
                        'c2c_docs' => ""
                    ]
                ]);
                $ch_rev = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
                curl_setopt($ch_rev, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_rev, CURLOPT_POST, true);
                curl_setopt($ch_rev, CURLOPT_POSTFIELDS, $revoke_payload);
                curl_setopt($ch_rev, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch_rev, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch_rev, CURLOPT_SSL_VERIFYPEER, false);
                curl_exec($ch_rev);
                curl_close($ch_rev);
            }
            $stmt->close();
            $success_message = "Customer application links revoked and documents permanently deleted.";
        }
    }
}

// ========================================================================
// CRITICAL FIX: SERVER-SIDE FILTER & BLAZING FAST SEARCH ENGINE
// ========================================================================
if (isset($_GET['action']) && in_array($_GET['action'], ['load_more', 'fetch_fresh'])) {
    $closer_id = $_SESSION['user_id'];
    $search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
    $offset = ($_GET['action'] == 'load_more') ? (int)$_GET['offset'] : 0;
    $col_filters = isset($_GET['col_filters']) ? json_decode($_GET['col_filters'], true) : [];

    $where_clause = "l.closer_id = $closer_id
                      AND (l.status IN ('NU', 'Pending Post', 'Paid') OR l.admin_status = 'Approved')";

    $is_sql_match = false;

    // Process Dropdown Filters natively in SQL
    $encrypted_headers = ['first_name', 'last_name', 'customer_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'alternate_details', 'application_text', 'questions'];
    $requires_php_filter = false;
    $php_filters = [];

    if (!empty($col_filters)) {
        foreach ($col_filters as $hdr => $values) {
            if (empty($values)) continue;

            if (in_array($hdr, $encrypted_headers)) {
                $requires_php_filter = true;
                $php_filters[$hdr] = array_map('strtolower', $values);
            } else {
                $sql_col = '';
                switch ($hdr) {
                    case 'vertical':
                        $sql_col = 'l.vertical';
                        break;
                    case 'closer_status':
                        $sql_col = 'l.closer_status';
                        break;
                    case 'status':
                        $sql_col = 'l.status';
                        break;
                    case 'source':
                    case 'agent_name':
                        $sql_col = '(SELECT username FROM users WHERE id = l.agent_id)';
                        break;
                    case 'referred_by':
                        $sql_col = 'l.referred_by';
                        break;
                    case 'jornaya_leadid':
                        $sql_col = 'l.jornaya_leadid';
                        break;
                    case 'trustedform_cert_url':
                        $sql_col = 'l.trustedform_cert_url';
                        break;
                    case 'ip_address':
                        $sql_col = 'l.ip_address';
                        break;
                }
                if ($sql_col !== '') {
                    $or_cond = [];
                    foreach ($values as $v) {
                        $v_esc = mysqli_real_escape_string($conn, $v);
                        $or_cond[] = "$sql_col LIKE '%$v_esc%'";
                    }
                    $where_clause .= " AND (" . implode(' OR ', $or_cond) . ")";
                }
            }
        }
    }

    if ($search !== '') {
        $search_esc = mysqli_real_escape_string($conn, $search);

        $fast_check = "SELECT l.id FROM leads l WHERE $where_clause AND (
            l.vertical LIKE '%$search_esc%' OR l.status LIKE '%$search_esc%' OR l.closer_status LIKE '%$search_esc%' OR 
            l.jornaya_leadid LIKE '%$search_esc%' OR l.customer_id LIKE '%$search_esc%' OR 
            (SELECT username FROM users WHERE id = l.agent_id) LIKE '%$search_esc%' OR 
            (SELECT username FROM users WHERE id = l.closer_id) LIKE '%$search_esc%' OR 
            (SELECT username FROM users WHERE id = l.verifier_id) LIKE '%$search_esc%'
        ) LIMIT 1";

        $check_res = $conn->query($fast_check);
        if ($check_res && $check_res->num_rows > 0) {
            $is_sql_match = true;
            $where_clause .= " AND (
                l.vertical LIKE '%$search_esc%' OR l.status LIKE '%$search_esc%' OR l.closer_status LIKE '%$search_esc%' OR 
                l.jornaya_leadid LIKE '%$search_esc%' OR l.customer_id LIKE '%$search_esc%' OR 
                (SELECT username FROM users WHERE id = l.agent_id) LIKE '%$search_esc%' OR 
                (SELECT username FROM users WHERE id = l.closer_id) LIKE '%$search_esc%' OR 
                (SELECT username FROM users WHERE id = l.verifier_id) LIKE '%$search_esc%'
            )";
        }
    }

    $query = "SELECT l.id, l.c2c_id, l.customer_name, l.vertical, l.phone, l.email, l.address, l.city, l.state, l.zip_code, l.dob, l.application_text, l.questions, l.status, l.admin_status, l.quick_update, l.closer_status, l.jornaya_leadid, l.trustedform_cert_url, l.ip_address, l.id_upload_token, l.customer_id, l.id_front_path, l.id_back_path, l.additional_records, l.created_at, l.updated_at, l.agent_id, l.referred_by, l.alternate_details, l.reminder_time, l.reminder_timezone,
        (SELECT username FROM users WHERE id = l.agent_id) as agent_name,
        (SELECT COUNT(id) FROM leads l2 WHERE l2.closer_id = l.closer_id AND l2.id <= l.id) as absolute_lead_number
        FROM leads l 
        WHERE $where_clause 
        ORDER BY l.updated_at DESC";

    if (($search === '' || $is_sql_match) && !$requires_php_filter) {
        $query .= " LIMIT 50 OFFSET ?";
        $stmt_leads = $conn->prepare($query);
        $stmt_leads->bind_param("i", $offset);
        $stmt_leads->execute();
        $leads_query = $stmt_leads->get_result();
        $stmt_leads->close();
    } else {
        $query .= " LIMIT 1500";
        $leads_query = mysqli_query($conn, $query);
    }

    $html = '';
    $match_count = 0;
    $skipped = 0;

    while ($lead_raw = mysqli_fetch_assoc($leads_query)) {
        $lead = decrypt_fields($lead_raw);

        if ($requires_php_filter) {
            $passes_filter = true;
            foreach ($php_filters as $hdr => $allowed_values) {
                $cell_val = strtolower(trim(preg_replace('/[\n\r]+|[\s]{2,}/', ' ', $lead[$hdr] ?? '')));
                if ($hdr === 'source' || $hdr === 'agent_name') {
                    $cell_val = strtolower(trim($lead['agent_name'] ?? 'reference'));
                }
                $match_found = false;
                foreach ($allowed_values as $av) {
                    if ($cell_val === strtolower(trim($av))) {
                        $match_found = true;
                        break;
                    }
                }
                if (!$match_found) {
                    $passes_filter = false;
                    break;
                }
            }
            if (!$passes_filter) continue;
        }

        if ($search !== '' && !$is_sql_match) {
            $searchable_text = strtolower(implode(' ', array_values($lead)));
            if (strpos($searchable_text, $search) === false) continue;
        }

        if (($search !== '' || $requires_php_filter) && $skipped < $offset) {
            $skipped++;
            continue;
        }

        $source = $lead['agent_id'] ? "Agent: " . htmlspecialchars($lead['agent_name']) : "<span style='color:var(--electric-blue); font-weight:bold;'>Reference</span>";
        if (!empty($lead['referred_by'])) {
            $source .= " | Ref: " . htmlspecialchars($lead['referred_by']);
        }

        $cal_upload_token = htmlspecialchars($lead['id_upload_token'] ?? '');
        $cal_cust_id = htmlspecialchars($lead['customer_id'] ?? '');
        $front_id = getWebPath($lead['id_front_path'] ?? '');
        $back_id = getWebPath($lead['id_back_path'] ?? '');
        $add_records = getWebPath($lead['additional_records'] ?? '');
        $add_records_name = basename(parse_url($add_records, PHP_URL_PATH) ?: $add_records);
        $is_locked = (stripos($lead['status'] ?? '', 'Pending Verification') !== false);
        $edit_class = $is_locked ? 'locked-cell' : 'cell-editable';
        $tab_index = $is_locked ? '' : 'tabindex="0"';
        ob_start();
?>
        <tr id="row-<?php echo $lead['id']; ?>" class="<?php echo $is_locked ? 'locked-row' : ''; ?>">
            <td style="text-align: center; color: #1e293b; font-weight: bold; background: #f8fafc; border-right: 2px solid #cbd5e1;">
                <?php echo $skipped + $match_count + 1; ?>
                <?php echo $lead['absolute_lead_number']; ?>
            </td>
            <td class="col-mini" style="text-align: center;">
                <a href="generator.php?id=<?php echo $lead['id']; ?>&amp;popup=1" target="_blank" style="text-decoration: none;">
                    <i class="fas fa-file-alt" style="color: #007BFF; font-size: 16px;" title="Generate Application"></i>
                </a>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="vertical" data-type="select-vertical" style="vertical-align: middle;"><?php echo htmlspecialchars($lead['vertical'] ?? ''); ?></td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="customer_name">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['customer_name'] ?? ''); ?></div>
            </td>

            <td class="locked-cell col-medium" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="phone">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['phone'] ?? ''); ?></div>
            </td>

            <td class="col-medium">
                <div class="scroll-box" style="cursor: default;"><?php echo $source; ?></div>
            </td>

            <td class="col-mini" style="text-align: center;">
                <button type="button" class="btn-chat" onclick="openCombinedChatModal(<?php echo (int)$lead['id']; ?>, <?php echo htmlspecialchars(json_encode((string)($lead['customer_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" style="position: relative; background: #0A2540;">
                    <i class="fas fa-comments"></i> Chat
                    <?php
                    $un_in_q = $conn->query("SELECT COUNT(*) as unread FROM inhouse_chats WHERE lead_id='" . (int)$lead['id'] . "' AND is_read=0 AND ((sender_type='Admin' AND closer_id='" . (int)$closer_id . "') OR (sender_type='Agent' AND recipient_id='" . (int)$closer_id . "' AND recipient_type='Closer'))");
                    $unread_admin = $un_in_q ? $un_in_q->fetch_assoc()['unread'] : 0;
                    ?>
                    <span id="closer-chat-badge-<?php echo $lead['id']; ?>" style="position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; border: 2px solid white; line-height: 1; display: <?php echo ($unread_admin > 0) ? 'block' : 'none'; ?>;">
                        <?php echo $unread_admin; ?>
                    </span>
                </button>
            </td>

            <td class="<?php echo $edit_class; ?>" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="closer_status" data-type="select-closer" style="vertical-align: middle;"><?php echo htmlspecialchars($lead['closer_status'] ?? ''); ?></td>

            <td class="system-status-cell col-medium" style="vertical-align: middle;">
                <div class="scroll-box" style="cursor: not-allowed; background: transparent; font-weight: bold; color: #475569;"><?php echo htmlspecialchars($lead['status'] ?? 'New'); ?></div>
            </td>

            <td class="cell-editable col-wide" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="application_text" data-type="fullscreen-editor">
                <div class="scroll-box" style="cursor: text;"><?php echo htmlspecialchars($lead['application_text'] ?? ''); ?></div>
            </td>

            <td class="cell-editable col-wide" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="questions" data-type="fullscreen-editor">
                <div class="scroll-box" style="cursor: text;"><?php echo htmlspecialchars($lead['questions'] ?? ''); ?></div>
            </td>

            <td class="col-medium" style="text-align: center; vertical-align: middle; background: #fdfcff; padding: 0 !important;">
                <?php if (empty($lead['c2c_id'])): ?>
                    <form method="POST" enctype="multipart/form-data" class="action-group">
                        <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                        <button type="submit" name="generate_c2c" class="c2c-btn" style="background: #28a745; color: white;">Gen Link</button>
                        <button type="button" onclick="openAddDocsModal(<?php echo $lead['id']; ?>)" class="c2c-btn" style="background: #17a2b8; color: white;" title="Attach Docs">+</button>
                    </form>
                <?php else: ?>
                    <div class="action-group">
                        <?php $generated_link = "https://pulaskikherkherlaw.info/customer_application?token=" . htmlspecialchars($lead['c2c_id']); ?>
                        <input type="text" value="<?php echo $generated_link; ?>" readonly onclick="this.select(); document.execCommand('copy'); alert('Copied!');" title="Click to copy link">
                        <button type="button" onclick="openAddDocsModal(<?php echo $lead['id']; ?>)" class="c2c-btn" style="background:#17a2b8; color:white;">+</button>
                        <form method="POST" style="margin: 0; display: inline-flex;">
                            <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                            <button type="submit" name="revoke_c2c" class="c2c-btn" style="background: #dc3545; color: white;">Revoke</button>
                        </form>
                    </div>
                <?php endif; ?>
            </td>

            <td class="col-medium">
                <?php if ($add_records !== ''): ?>
                    <a href="<?php echo htmlspecialchars($add_records, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($add_records_name, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php else: ?>
                    <span aria-label="No additional records">-</span>
                <?php endif; ?>
            </td>

            <td class="<?php echo $edit_class; ?> col-wide" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="alternate_details">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['alternate_details'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="email">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="address">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['address'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="city">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['city'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-mini" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="state">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['state'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="zip_code">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="dob">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['dob'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="jornaya_leadid">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['jornaya_leadid'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="trustedform_cert_url">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['trustedform_cert_url'] ?? ''); ?></div>
            </td>

            <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="ip_address">
                <div class="scroll-box"><?php echo htmlspecialchars($lead['ip_address'] ?? ''); ?></div>
            </td>

            <td class="col-mini" style="text-align: center;">
                <button type="button" class="cal-btn" style="background: #0A2540; color: white;" onclick="openCalPopup(<?php echo $lead['id']; ?>, '<?php echo addslashes($cal_upload_token); ?>', '<?php echo addslashes($cal_cust_id); ?>', '<?php echo addslashes($front_id); ?>', '<?php echo addslashes($back_id); ?>', '<?php echo addslashes($add_records); ?>')">CAL</button>
            </td>

            <td class="col-medium">
                <div class="scroll-box" style="cursor: default; color: #64748b;"><?php echo date('m-d-Y H:i', strtotime($lead['created_at'])); ?></div>
            </td>
        </tr>
<?php
        $html .= ob_get_clean();

        $match_count++;
        if (($search !== '' || $is_sql_match || $requires_php_filter) && $match_count >= 50) break;
    }

    echo json_encode(['html' => $html]);
    exit;
}

include 'includes/header.php';

$closer_id = $_SESSION['user_id'];
$where_clause = "l.closer_id = $closer_id
               AND (l.status IN ('NU', 'Pending Post', 'Paid') OR l.admin_status = 'Approved')";

$query = "SELECT l.id, l.c2c_id, l.customer_name, l.vertical, l.phone, l.email, l.address, l.city, l.state, l.zip_code, l.dob, l.application_text, l.questions, l.status, l.admin_status, l.quick_update, l.closer_status, l.jornaya_leadid, l.trustedform_cert_url, l.ip_address, l.id_upload_token, l.customer_id, l.id_front_path, l.id_back_path, l.additional_records, l.created_at, l.updated_at, l.agent_id, l.referred_by, l.alternate_details, l.reminder_time, l.reminder_timezone,
    (SELECT username FROM users WHERE id = l.agent_id) as agent_name,
    (SELECT COUNT(id) FROM leads l2 WHERE l2.closer_id = l.closer_id AND l2.id <= l.id) as absolute_lead_number
    FROM leads l 
    WHERE $where_clause 
    ORDER BY l.updated_at DESC LIMIT 50";

$stmt_leads = $conn->prepare($query);
if (!$stmt_leads) {
    die("Query Error: " . $conn->error);
}
$stmt_leads->execute();
$leads_query = $stmt_leads->get_result();
$stmt_leads->close();
?>
<style>
    .excel-container {
        width: 100%;
        max-height: calc(100vh - 200px);
        overflow: auto;
        border: 1px solid #cbd5e1;
        background: #fff;
        border-radius: 8px;
    }

    .excel-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        font-size: 13px !important;
        white-space: nowrap;
    }

    .excel-table tr {
        height: 50px !important;
        max-height: 50px !important;
    }

    .excel-table th,
    .excel-table td {
        border: 1px solid #cbd5e1;
        padding: 0px 4px !important;
        text-align: center !important;
        vertical-align: middle !important;
        height: 30px !important;
        max-height: 30px !important;
        box-sizing: border-box;
        overflow: hidden !important;
        font-size: 13px !important;
        white-space: nowrap !important;
    }

    .excel-table th {
        background: #f1f5f9;
        position: sticky;
        top: 0;
        z-index: 2;
        box-shadow: 0 1px 0 #cbd5e1;
        color: #334155;
        user-select: none;
        overflow: visible !important;
    }

    .excel-table tr:hover {
        background-color: #f8fafc;
    }

    .col-wide {
        width: 300px !important;
        min-width: 300px !important;
        max-width: 400px !important;
    }

    .col-medium {
        width: 150px !important;
        min-width: 150px !important;
        max-width: 180px !important;
    }

    .col-narrow {
        width: 90px !important;
        min-width: 90px !important;
        max-width: 110px !important;
    }

    .col-mini {
        width: 60px !important;
        min-width: 60px !important;
        max-width: 80px !important;
    }

    .scroll-box {
        height: 28px;
        max-height: 28px;
        overflow-y: auto;
        overflow-x: hidden;
        white-space: pre-wrap;
        word-wrap: break-word;
        width: 100%;
        outline: none;
        font-size: 13px !important;
        text-align: center;
        line-height: 1.2;
        padding-top: 6px;
        box-sizing: border-box;
        cursor: text;
    }

    .scroll-box::-webkit-scrollbar {
        width: 4px;
    }

    .scroll-box::-webkit-scrollbar-track {
        background: transparent;
    }

    .scroll-box::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .cell-editable {
        background: #fff;
        transition: box-shadow 0.1s;
    }

    .cell-selected {
        box-shadow: inset 0 0 0 2px #2563eb !important;
        background-color: #eff6ff !important;
        z-index: 1;
        position: relative;
    }

    .cell-editing {
        background: #ffffff !important;
        box-shadow: inset 0 0 0 2px #10b981 !important;
        cursor: text;
    }

    .locked-cell {
        background: #f3f4f6 !important;
        color: #6b7280;
        cursor: not-allowed;
    }

    .locked-row td {
        background-color: #f8fafc;
        color: #64748b;
    }

    .status-select-input,
    .vertical-input {
        width: 100%;
        height: 28px !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none;
        outline: none;
        background: transparent;
        font-family: inherit;
        font-size: 13px !important;
        cursor: pointer;
        text-align: center;
    }

    .btn-chat,
    .cal-btn,
    .c2c-btn {
        height: 24px !important;
        padding: 0 8px !important;
        font-size: 11px !important;
        border-radius: 4px !important;
        font-weight: bold;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        text-decoration: none;
        border: none;
        box-sizing: border-box;
        margin: 0 auto;
    }

    .btn-chat {
        background-color: #007BFF;
        color: #FFFFFF;
    }

    .btn-chat:hover {
        background-color: #0056b3;
    }

    .action-group {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        width: 100%;
        margin: 0;
        padding: 0 4px;
        box-sizing: border-box;
    }

    .action-group input[type="text"] {
        width: 75px;
        height: 24px;
        padding: 0 4px;
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        font-size: 10px;
        background: #fff;
        color: #334155;
        box-sizing: border-box;
        margin: 0;
        text-align: center;
        cursor: pointer;
    }

    .action-group .c2c-btn {
        height: 24px !important;
        padding: 0 8px !important;
        font-size: 11px !important;
        border-radius: 4px !important;
        font-weight: bold;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        margin: 0;
        white-space: nowrap;
        text-decoration: none;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9998;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }

    .modal-overlay.show {
        display: flex;
    }

    .modal-content {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 450px;
        text-align: left;
    }

    .save-indicator {
        font-size: 11px;
        color: #10b981;
        font-weight: bold;
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #fff;
        padding: 10px 20px;
        border-radius: 5px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        opacity: 0;
        transition: opacity 0.3s ease;
        z-index: 9999;
    }

    .save-indicator.show {
        opacity: 1;
    }

    #toast-container {
        position: fixed;
        bottom: 70px;
        right: 20px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .toast-alert {
        background: #0f172a !important;
        border: 1px solid #334155 !important;
        border-left: 4px solid #10b981 !important;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45) !important;
        border-radius: 8px;
        padding: 15px;
        width: 320px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        color: #f8fafc !important;
        transform: translateX(120%);
        transition: transform 0.3s ease-out;
    }

    .toast-alert.show {
        transform: translateX(0);
    }

    .filter-icon {
        margin-left: 8px;
        cursor: pointer;
        color: #94A3B8;
        font-size: 11px;
        transition: 0.2s;
        position: absolute;
        right: 4px;
        top: 50%;
        transform: translateY(-50%);
    }

    .filter-icon:hover,
    .filter-icon.active {
        color: #007BFF;
    }

    .filter-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        min-width: 200px;
        max-height: 240px;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        z-index: 50;
        padding: 10px;
        color: #1e293b;
        text-transform: none;
        font-weight: normal;
        font-size: 13px;
        text-align: left;
    }

    .filter-menu.show {
        display: block;
    }

    .filter-search {
        width: 100%;
        padding: 8px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        margin-bottom: 10px;
        font-size: 13px;
        box-sizing: border-box;
        outline: none;
    }

    .filter-options-container {
        max-height: 140px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-bottom: 10px;
    }

    .filter-option {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px;
        cursor: pointer;
        border-radius: 4px;
        margin: 0;
    }

    .filter-option:hover {
        background: #f8fafc;
    }

    .filter-actions {
        display: flex;
        justify-content: space-between;
        border-top: 1px solid #e2e8f0;
        padding-top: 8px;
    }

    .filter-btn {
        background: none;
        border: none;
        color: #007BFF;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        padding: 4px 8px;
    }

    .filter-btn:hover {
        text-decoration: underline;
    }
</style>

<div class="card" style="margin-top: 20px; background: transparent; box-shadow: none; border: none; padding: 0;">
    <h3 style="margin-top: 0; color: #ffffff; font-size: 22px;">Posted Leads</h3>
    <br>
    <div style="margin-bottom: 20px; display: flex; align-items: center; background: white; border-radius: 12px; border: 1px solid #E2E8F0; padding: 12px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); transition: box-shadow 0.3s ease;">
        <svg style="width: 18px; height: 18px; color: #94A3B8; margin-right: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
        </svg>
        <input type="text" id="search-input" placeholder="Search entire database..." style="width: 100%; border: none; outline: none; font-size: 15px; font-family: inherit; background: transparent; color: #1E293B;">
    </div>

    <div id="leadTableContainer" class="excel-container table-container">
        <table id="closer-table" class="excel-table">
            <thead>
                <tr>
                    <th class="col-mini">#</th>
                    <th class="col-mini">App</th>
                    <th class="col-medium" data-filter="true" data-name="vertical">Vertical</th>
                    <th class="col-medium" data-filter="true" data-name="customer_name">Name</th>
                    <th class="col-medium" data-filter="true" data-name="phone">Phone</th>
                    <th class="col-medium" data-filter="true" data-name="source">Source</th>
                    <th class="col-mini">Chat</th>
                    <th class="col-medium" data-filter="true" data-name="closer_status">Status</th>
                    <th class="col-medium" data-filter="true" data-name="status">Update</th>
                    <th class="col-wide" data-filter="true" data-name="application_text">Application Text</th>
                    <th class="col-wide" data-filter="true" data-name="questions">Questions</th>
                    <th class="col-medium">Application Link</th>
                    <th class="col-medium">Additional Records</th>
                    <th class="col-wide" data-filter="true" data-name="alternate_details">Alternate information</th>
                    <th class="col-medium" data-filter="true" data-name="email">Email</th>
                    <th class="col-medium" data-filter="true" data-name="address">Address</th>
                    <th class="col-narrow" data-filter="true" data-name="city">City</th>
                    <th class="col-mini" data-filter="true" data-name="state">State</th>
                    <th class="col-narrow" data-filter="true" data-name="zip_code">Zip Code</th>
                    <th class="col-narrow" data-filter="true" data-name="dob">DOB</th>
                    <th class="col-medium" data-filter="true" data-name="jornaya_leadid">Jornaya</th>
                    <th class="col-medium" data-filter="true" data-name="trustedform_cert_url">Trusted Form</th>
                    <th class="col-medium" data-filter="true" data-name="ip_address">IP address</th>
                    <th class="col-mini">CAL</th>
                    <th class="col-medium" data-filter="true">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($lead_raw = mysqli_fetch_assoc($leads_query)):
                    $lead = decrypt_fields($lead_raw);

                    $source = $lead['agent_id'] ? "Agent: " . htmlspecialchars($lead['agent_name']) : "<span style='color:var(--electric-blue); font-weight:bold;'>Reference</span>";
                    if (!empty($lead['referred_by'])) {
                        $source .= " | Ref: " . htmlspecialchars($lead['referred_by']);
                    }

                    $cal_upload_token = htmlspecialchars($lead['id_upload_token'] ?? '');
                    $cal_cust_id = htmlspecialchars($lead['customer_id'] ?? '');
                    $front_id = getWebPath($lead['id_front_path'] ?? '');
                    $back_id = getWebPath($lead['id_back_path'] ?? '');
                    $add_records = getWebPath($lead['additional_records'] ?? '');
                    $add_records_name = basename(parse_url($add_records, PHP_URL_PATH) ?: $add_records);
                    $is_locked = (stripos($lead['status'] ?? '', 'Pending Verification') !== false);
                    $edit_class = $is_locked ? 'locked-cell' : 'cell-editable';
                    $tab_index = $is_locked ? '' : 'tabindex="0"';
                ?>
                    <tr id="row-<?php echo $lead['id']; ?>" class="<?php echo $is_locked ? 'locked-row' : ''; ?>">
                        <td style="text-align: center; color: #1e293b; font-weight: bold; background: #f8fafc; border-right: 2px solid #cbd5e1;">
                            <?php echo $lead['absolute_lead_number']; ?>
                        </td>
                        <td class="col-mini" style="text-align: center;">
                            <a href="generator.php?id=<?php echo $lead['id']; ?>&amp;popup=1" target="_blank" style="text-decoration: none;">
                                <i class="fas fa-file-alt" style="color: #007BFF; font-size: 16px;" title="Generate Application"></i>
                            </a>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="vertical" data-type="select-vertical" style="vertical-align: middle;"><?php echo htmlspecialchars($lead['vertical'] ?? ''); ?></td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="customer_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['customer_name'] ?? ''); ?></div>
                        </td>

                        <td class="locked-cell col-medium" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="phone">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['phone'] ?? ''); ?></div>
                        </td>

                        <td class="col-medium">
                            <div class="scroll-box" style="cursor: default;"><?php echo $source; ?></div>
                        </td>

                        <td class="col-mini" style="text-align: center;">
                            <button type="button" class="btn-chat" onclick="openCombinedChatModal(<?php echo (int)$lead['id']; ?>, <?php echo htmlspecialchars(json_encode((string)($lead['customer_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" style="position: relative; background: #0A2540;">
                                <i class="fas fa-comments"></i> Chat
                                <?php
                                $un_in_q = $conn->query("SELECT COUNT(*) as unread FROM inhouse_chats WHERE lead_id='" . (int)$lead['id'] . "' AND is_read=0 AND ((sender_type='Admin' AND closer_id='" . (int)$closer_id . "') OR (sender_type='Agent' AND recipient_id='" . (int)$closer_id . "' AND recipient_type='Closer'))");
                                $unread_admin = $un_in_q ? $un_in_q->fetch_assoc()['unread'] : 0;
                                ?>
                                <span id="closer-chat-badge-<?php echo $lead['id']; ?>" style="position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; border: 2px solid white; line-height: 1; display: <?php echo ($unread_admin > 0) ? 'block' : 'none'; ?>;">
                                    <?php echo $unread_admin; ?>
                                </span>
                            </button>
                        </td>

                        <td class="<?php echo $edit_class; ?>" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="closer_status" data-type="select-closer" style="vertical-align: middle;"><?php echo htmlspecialchars($lead['closer_status'] ?? ''); ?></td>

                        <td class="system-status-cell col-medium" style="vertical-align: middle;">
                            <div class="scroll-box" style="cursor: not-allowed; background: transparent; font-weight: bold; color: #475569;"><?php echo htmlspecialchars($lead['status'] ?? 'New'); ?></div>
                        </td>

                        <td class="cell-editable col-wide" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="application_text" data-type="fullscreen-editor">
                            <div class="scroll-box" style="cursor: text;"><?php echo htmlspecialchars($lead['application_text'] ?? ''); ?></div>
                        </td>

                        <td class="cell-editable col-wide" tabindex="0" data-id="<?php echo $lead['id']; ?>" data-field="questions" data-type="fullscreen-editor">
                            <div class="scroll-box" style="cursor: text;"><?php echo htmlspecialchars($lead['questions'] ?? ''); ?></div>
                        </td>

                        <td class="col-medium" style="text-align: center; vertical-align: middle; background: #fdfcff; padding: 0 !important;">
                            <?php if (empty($lead['c2c_id'])): ?>
                                <form method="POST" enctype="multipart/form-data" class="action-group">
                                    <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                    <button type="submit" name="generate_c2c" class="c2c-btn" style="background: #28a745; color: white;">Gen Link</button>
                                    <button type="button" onclick="openAddDocsModal(<?php echo $lead['id']; ?>)" class="c2c-btn" style="background: #17a2b8; color: white;" title="Attach Docs">+</button>
                                </form>
                            <?php else: ?>
                                <div class="action-group">
                                    <?php $generated_link = "https://pulaskikherkherlaw.info/customer_application?token=" . htmlspecialchars($lead['c2c_id']); ?>
                                    <input type="text" value="<?php echo $generated_link; ?>" readonly onclick="this.select(); document.execCommand('copy'); alert('Copied!');" title="Click to copy link">
                                    <button type="button" onclick="openAddDocsModal(<?php echo $lead['id']; ?>)" class="c2c-btn" style="background:#17a2b8; color:white;">+</button>
                                    <form method="POST" style="margin: 0; display: inline-flex;">
                                        <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                        <button type="submit" name="revoke_c2c" class="c2c-btn" style="background: #dc3545; color: white;">Revoke</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="col-medium">
                            <?php if ($add_records !== ''): ?>
                                <a href="<?php echo htmlspecialchars($add_records, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($add_records_name, ENT_QUOTES, 'UTF-8'); ?></a>
                            <?php else: ?>
                                <span aria-label="No additional records">-</span>
                            <?php endif; ?>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-wide" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="alternate_details">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['alternate_details'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="email">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="address">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['address'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="city">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['city'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-mini" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="state">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['state'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="zip_code">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-narrow" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="dob">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['dob'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="jornaya_leadid">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['jornaya_leadid'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="trustedform_cert_url">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['trustedform_cert_url'] ?? ''); ?></div>
                        </td>

                        <td class="<?php echo $edit_class; ?> col-medium" <?php echo $tab_index; ?> data-id="<?php echo $lead['id']; ?>" data-field="ip_address">
                            <div class="scroll-box"><?php echo htmlspecialchars($lead['ip_address'] ?? ''); ?></div>
                        </td>

                        <td class="col-mini" style="text-align: center;">
                            <button type="button" class="cal-btn" style="background: #0A2540; color: white;" onclick="openCalPopup(<?php echo $lead['id']; ?>, '<?php echo addslashes($cal_upload_token); ?>', '<?php echo addslashes($cal_cust_id); ?>', '<?php echo addslashes($front_id); ?>', '<?php echo addslashes($back_id); ?>', '<?php echo addslashes($add_records); ?>')">CAL</button>
                        </td>

                        <td class="col-medium">
                            <div class="scroll-box" style="cursor: default; color: #64748b;"><?php echo date('m-d-Y H:i', strtotime($lead['created_at'])); ?></div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if (mysqli_num_rows($leads_query) == 0): ?>
                    <tr class="no-leads-row">
                        <td colspan="24" style="text-align: center; color: #888; padding: 30px; font-size: 14px;">No leads found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- GLOBAL SAVE INDICATOR -->
<div id="global-save-indicator" class="save-indicator">Saving...</div>

<!-- CHAT MODAL OVERLAY -->
<div id="chatModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px; max-width: 90%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #0A2540;">Updates & Feedback</h3>
            <button type="button" onclick="closeChatModal()" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        <p style="color: #64748B; font-size: 13px; margin-top: 0;">Lead: <span id="chatLeadName" style="font-weight: bold; color: var(--primary-navy);"></span></p>

        <div id="chatMessages" style="height: 300px; overflow-y: auto; background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 15px; margin-bottom: 15px; display: flex; flex-direction: column; gap: 10px;"></div>

        <div style="display: flex; gap: 10px;">
            <input type="hidden" id="chatLeadId" value="">
            <input type="text" id="chatInput" placeholder="Write an update..." style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 6px; outline: none;" onkeypress="if(event.key === 'Enter') sendChatMessage()">
            <button onclick="sendChatMessage()" style="background: #28a745; color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;"><i class="fas fa-paper-plane"></i> Send</button>
        </div>
    </div>
</div>

<div id="combinedChatModal" class="modal-overlay">
    <div class="modal-content" style="width: 600px; max-width: 95%; max-height: 90vh; display: flex; flex-direction: column; background: #f8fafc; padding: 0; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: center; background: #0A2540; color: white; padding: 15px 20px; flex-shrink: 0;">
            <h3 style="margin: 0; font-size: 16px;"><i class="fas fa-comments"></i> Admin Chat &amp; Report: <span id="combinedChatLeadName"></span></h3>
            <button type="button" onclick="closeCombinedChatModal()" style="background:none; border:none; font-size: 20px; color:white; cursor:pointer;">&times;</button>
        </div>
        <div style="padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column;">
            <div style="margin-bottom: 15px; flex-shrink: 0;">
                <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #475569; text-transform: uppercase;">Verification Report</h4>
                <pre id="combinedReportContent" title="Double-click to expand to full screen" style="cursor: zoom-in; max-height: 120px; overflow-y: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; font-size: 12px; margin: 0; color: #1e293b; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"></pre>
                <p id="reportActionMessage" role="status" style="min-height: 20px; color: #475569; font-size: 12px; margin-bottom: 0; display: none;"></p>
                <button id="markReportDoneButton" type="button" onclick="markReportDone()" hidden disabled style="width: 100%; background: #16803c; color: white; border: none; border-radius: 6px; padding: 8px; font-size: 13px; font-weight: bold; cursor: pointer; margin-top: 8px; transition: 0.2s;">Mark as Done (Send to Pending Post)</button>
            </div>
            <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #475569; text-transform: uppercase; flex-shrink: 0;">Admin Chat</h4>
            <div id="combinedChatMessages" style="min-height: 150px; flex: 1; overflow-y: auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 15px; margin-bottom: 15px; display: flex; flex-direction: column; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"></div>
            <div style="display: flex; gap: 10px; flex-shrink: 0;">
                <input type="hidden" id="combinedChatLeadId" value="">
                <input type="text" id="combinedChatInput" placeholder="Type your message to Admin..." style="flex: 1; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; font-size: 13px;" onkeypress="if(event.key === 'Enter') sendCombinedChatMessage()">
                <button type="button" onclick="sendCombinedChatMessage()" class="btn" style="background-color: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s;">Send</button>
            </div>
        </div>
    </div>
</div>

<div id="trueFullscreenReportModal" class="modal-overlay" style="z-index: 10000008 !important; padding: 0;">
    <div class="modal-content" style="width: 100vw; height: 100vh; max-width: 100%; display: flex; flex-direction: column; background: #0f172a; padding: 0; border: none; border-radius: 0; margin: 0;">
        <div style="background: #0A2540; padding: 15px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155;">
            <h3 style="margin: 0; color: #f8fafc; font-size: 18px;"><i class="fas fa-file-alt"></i> Verification Report (Expanded)</h3>
            <button type="button" onclick="closeTrueFullscreenReport()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div style="flex: 1; padding: 40px; overflow-y: auto;">
            <pre id="trueFullscreenReportText" style="white-space: pre-wrap; word-wrap: break-word; font-family: monospace; font-size: 16px; line-height: 1.8; color: #e2e8f0; margin: 0;"></pre>
        </div>
    </div>
</div>

<!-- CAL MODAL OVERLAY -->
<div id="calModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-size: 18px; color: #0A2540;">Portal Access & ID Upload</h2>
            <button type="button" onclick="closeCalPopup()" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>

        <div id="cal_upload_div_active" style="display:none; background: #1E293B; border: 1px solid #334155; border-radius: 8px; padding: 12px; margin-bottom: 20px;">
            <p style="display:block; margin:0 0 8px; font-size:13px; font-weight:600; color:#94A3B8;">Secure ID Upload Link (Generated by Verifier):</p>
            <input type="text" id="cal_upload_input" readonly style="background:#0F172A; color:#38BDF8; border:1px solid #475569; padding:8px 10px; border-radius:6px; width:100%; box-sizing:border-box; font-family:monospace; font-size:12px;" onclick="this.select(); document.execCommand('copy'); alert('Secure ID Upload link copied to clipboard!');" title="Click to copy link">
        </div>

        <div id="cal_upload_div_inactive" style="display:none; background: #fff3cd; color: #0f172a !important; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffeeba;">
            <p style="margin:0; font-size: 13px; font-weight:bold; color: #0f172a !important;">Secure ID Upload Link:</p>
            <p style="margin: 5px 0 0 0; font-size: 13px; color: #0f172a !important;">Not generated yet. The verifier will generate this link after the call.</p>
        </div>
        <hr style="border:0; border-top:1px dashed #ccc; margin: 20px 0;">

        <form method="POST">
            <input type="hidden" name="lead_id" id="cal_lead_id" value="">
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button type="submit" name="send_dashboard_link" class="btn" style="background: #0A2540; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; width: 100%; cursor: pointer;">
                    <i class="fas fa-handshake"></i> Reference Policy
                </button>
                <button type="button" onclick="openApprovalModal()" class="btn" style="background: #28a745; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; width: 100%; cursor: pointer;">
                    <i class="fas fa-thumbs-up"></i> Send Dashboard & Approval Letter
                </button>
            </div>
        </form>

        <p id="cal_customer_id_display" style="display:none; text-align: center; font-size: 13px; margin-top: 15px; color: #28a745; font-weight: bold;"></p>
        <hr style="border:0; border-top:1px dashed #ccc; margin: 20px 0;">
        <h4 style="margin: 0 0 10px 0; color: #0A2540; font-size: 14px;">Customer Uploaded Proofs</h4>
        <div id="cal_proofs_container" style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; align-items: center;"></div>
    </div>
</div>

<div id="approvalModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 400px; text-align: left;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-size: 18px; color: #0A2540;">Approval Email Details</h2>
            <button type="button" onclick="closeApprovalModal()" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="lead_id" id="approval_lead_id" value="">
            <div style="margin-bottom: 15px;">
                <label style="display:block; font-size:13px; font-weight:bold; margin-bottom:5px;">Your Name</label>
                <input type="text" name="closer_name" value="<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; outline: none;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display:block; font-size:13px; font-weight:bold; margin-bottom:5px;">Your Contact Number</label>
                <input type="text" name="closer_phone" placeholder="e.g. +1 234 567 8900" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; outline: none;">
            </div>
            <div style="margin-bottom: 25px;">
                <label style="display:block; font-size:13px; font-weight:bold; margin-bottom:5px;">Litigating Law Firm</label>
                <input type="text" name="law_firm" placeholder="e.g. Pulaski Law Firm" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; outline: none;">
            </div>
            <button type="submit" name="send_approval_link" class="btn" style="background: #28a745; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; width: 100%; cursor: pointer;">
                <i class="fas fa-paper-plane"></i> Send Approval Email
            </button>
        </form>
    </div>
</div>

<div id="addDocsModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-size: 18px; color: #0A2540;">Add Documents</h2>
            <button type="button" onclick="closeAddDocsModal()" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="lead_id" id="addDocsLeadId" value="">
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 12px; font-weight: bold; color: #555; margin-bottom: 5px;">Select Files to Upload</label>
                <input type="file" name="c2c_files[]" multiple accept=".pdf, image/*" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; font-size: 12px; box-sizing: border-box;" required>
            </div>
            <button type="submit" name="upload_more_c2c" style="width: 100%; background: #17a2b8; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: background 0.3s;">Upload & Attach</button>
        </form>
    </div>
</div>

<!-- Callback Date/Time Modal -->
<div id="cbModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 350px; text-align: left;">
        <h3 style="margin-top: 0; color: #0A2540;">Schedule Callback</h3>
        <p style="color: #64748B; font-size: 13px;">Select local time and timezone.</p>
        <input type="hidden" id="cbLeadId">
        <input type="hidden" id="cbField">
        <input type="datetime-local" id="cbDateTime" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; margin-bottom: 10px; font-size: 14px; outline: none; box-sizing: border-box;">
        <select id="cbTimezone" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; margin-bottom: 20px; font-size: 14px; outline: none; background: white; box-sizing: border-box;">
            <option value="America/New_York">Eastern Time (EST)</option>
            <option value="America/Chicago">Central Time (CST)</option>
            <option value="America/Denver">Mountain Time (MST)</option>
            <option value="America/Los_Angeles">Pacific Time (PST)</option>
        </select>
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button class="btn-cancel" onclick="closeCbModal()" style="background: #64748B; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600;">Cancel</button>
            <button class="btn-save" onclick="saveCallback()" style="background: #2563eb; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600;">Set Reminder</button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<div id="fullScreenEditorModal" class="modal-overlay">
    <div class="modal-content" style="width: 90%; max-width: 1200px; height: 90vh; display: flex; flex-direction: column; padding: 0; background: #0f172a; border: 1px solid #334155;">
        <div style="display: flex; justify-content: space-between; align-items: center; background: #0A2540; color: white; padding: 15px 20px;">
            <h3 style="margin: 0; font-size: 18px;"><i class="fas fa-edit"></i> <span id="fs-editor-title">Editor</span></h3>
            <button type="button" onclick="closeFullScreenEditor()" style="background:none; border:none; font-size: 24px; color:white; cursor:pointer;">&times;</button>
        </div>
        <div style="flex: 1; padding: 20px; display: flex; flex-direction: column; overflow-y: auto;">
            <input type="hidden" id="fs-lead-id">
            <input type="hidden" id="fs-field-name">
            <textarea id="fs-textarea" style="flex: 1; width: 100%; min-height: 300px; resize: none; background: #0b1220; color: #f8fafc; border: 1px solid #334155; border-radius: 8px; padding: 15px; font-family: monospace; font-size: 14px; outline: none;"></textarea>
        </div>
        <div style="padding: 15px 20px; border-top: 1px solid #334155; display: flex; justify-content: flex-end; gap: 10px; background: #1e293b;">
            <button type="button" onclick="closeFullScreenEditor()" class="btn" style="background: #64748b; color: white; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: none; cursor: pointer;">Cancel</button>
            <button type="button" onclick="saveFullScreenEditor()" class="btn" style="background: #10b981; color: white; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: none; cursor: pointer;">Save Changes</button>
        </div>
    </div>
</div>

<script>
    // ==========================================
    // EXCEL GRID EDITING ENGINE
    // ==========================================
    let isEditing = false;
    let currentEditCell = null;
    const indicator = document.getElementById('global-save-indicator');
    const closerStatusOptions = {
        "": "Select...",
        "VM": "VM",
        "CB": "CB",
        "NI": "NI",
        "Available": "Available",
        "Revised": "Revised"
    };
    const verticalOptions = {
        "": "-- Select Vertical --",
        "3M Combat Earplug": "3M Combat Earplug",
        "AFFF": "AFFF",
        "ASD": "ASD",
        "NEC": "NEC",
        "BARD Powerport": "BARD Powerport",
        "Camp Lejeune": "Camp Lejeune",
        "Cartiva": "Cartiva",
        "Catheter": "Catheter",
        "Chlorpyrifos": "Chlorpyrifos",
        "Depo Provera": "Depo Provera",
        "Dupixent": "Dupixent",
        "Elmiron": "Elmiron",
        "Hair Relaxer": "Hair Relaxer",
        "Hernia Mesh": "Hernia Mesh",
        "Hurricane": "Hurricane",
        "CA Sexual Abuse": "CA Sexual Abuse",
        "CA Womens Prison": "CA Womens Prison",
        "IL Sexual Abuse": "IL Sexual Abuse",
        "LDS Sexual Abuse": "LDS Sexual Abuse",
        "Lung Cancer": "Lung Cancer",
        "MVA": "MVA",
        "Olympus": "Olympus",
        "Ozempic": "Ozempic",
        "Paraquat": "Paraquat",
        "PFAS": "PFAS",
        "Ride Share": "Ride Share",
        "Roblox": "Roblox",
        "RI-JDC": "Rhode Island - Juvenile Detention Center",
        "Round Up": "Round Up",
        "Snapchat": "Snapchat",
        "SMA": "SMA",
        "SSDI": "SSDI",
        "Talcum": "Talcum",
        "Toxic Baby Food": "Toxic Baby Food",
        "VGA": "VGA",
        "Zantac": "Zantac"
    };

    const tableContainer = document.getElementById('closer-table');
    tableContainer.addEventListener('mousedown', function(e) {
        let cell = e.target.closest('td.cell-editable, td.locked-cell');
        if (!cell) return;
        if (isEditing && currentEditCell === cell) return;
        if (isEditing && currentEditCell !== cell) finishEditing(currentEditCell);
        selectCell(cell);
    });

    tableContainer.addEventListener('dblclick', function(e) {
        let cell = e.target.closest('td.cell-editable');
        if (cell) enterEditMode(cell);
    });

    document.addEventListener('keydown', function(e) {
        if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'SELECT' || document.activeElement.tagName === 'TEXTAREA') return;
        const selectedCell = document.querySelector('.cell-selected');

        if (isEditing) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                finishEditing(currentEditCell);
                if (selectedCell) moveSelection(selectedCell, 'down');
            } else if (e.key === 'Escape') {
                finishEditing(currentEditCell);
            }
            return;
        }

        if (!selectedCell) return;

        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
            e.preventDefault();
            moveSelection(selectedCell, e.key.replace('Arrow', '').toLowerCase());
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedCell.classList.contains('cell-editable')) enterEditMode(selectedCell);
        } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            if (!selectedCell.classList.contains('cell-editable')) return;
            e.preventDefault();
            enterEditMode(selectedCell, true, e.key);
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && selectedCell.classList.contains('locked-cell')) {
            const textNode = selectedCell.querySelector('.scroll-box') || selectedCell;
            const range = document.createRange();
            range.selectNodeContents(textNode);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }
    });

    function selectCell(cell) {
        document.querySelectorAll('.cell-selected').forEach(c => c.classList.remove('cell-selected'));
        cell.classList.add('cell-selected');
        cell.focus();
    }

    function moveSelection(currentCell, direction) {
        let tr = currentCell.closest('tr');
        let cellIndex = Array.from(tr.children).indexOf(currentCell);
        let nextCell = null;

        if (direction === 'right') {
            nextCell = currentCell.nextElementSibling;
            while (nextCell && !nextCell.matches('.cell-editable, .locked-cell')) {
                nextCell = nextCell.nextElementSibling;
            }
        } else if (direction === 'left') {
            nextCell = currentCell.previousElementSibling;
            while (nextCell && !nextCell.matches('.cell-editable, .locked-cell')) {
                nextCell = nextCell.previousElementSibling;
            }
        } else if (direction === 'down') {
            let nextTr = tr.nextElementSibling;
            while (nextTr) {
                if (nextTr.children[cellIndex] && nextTr.children[cellIndex].matches('.cell-editable, .locked-cell')) {
                    nextCell = nextTr.children[cellIndex];
                    break;
                }
                nextTr = nextTr.nextElementSibling;
            }
        } else if (direction === 'up') {
            let prevTr = tr.previousElementSibling;
            while (prevTr) {
                if (prevTr.children[cellIndex] && prevTr.children[cellIndex].matches('.cell-editable, .locked-cell')) {
                    nextCell = prevTr.children[cellIndex];
                    break;
                }
                prevTr = prevTr.previousElementSibling;
            }
        }
        if (nextCell) selectCell(nextCell);
    }

    function openFullscreenEditor(cell, initialText = null) {
        const fieldName = cell.dataset.field || cell.dataset.col;
        const targetNode = cell.querySelector('.scroll-box') || cell;
        isEditing = true;
        currentEditCell = cell;
        document.getElementById('fs-lead-id').value = cell.parentElement.id.replace('row-', '');
        document.getElementById('fs-field-name').value = fieldName;
        document.getElementById('fs-textarea').value = initialText === null ? targetNode.innerText.trim() : initialText;
        document.getElementById('fs-editor-title').innerText = 'Edit ' + fieldName.replace('_', ' ').toUpperCase();
        window.fsActiveCell = targetNode;
        document.getElementById('fullScreenEditorModal').classList.add('show');
        document.getElementById('fs-textarea').focus();
    }

    function enterEditMode(cell, clearContent = false, initialText = null) {
        if (!cell.classList.contains('cell-editable')) return;
        if (cell.dataset.type === 'fullscreen-editor' || ['questions', 'application_text', 'alternate_details'].includes(cell.dataset.field)) {
            openFullscreenEditor(cell, clearContent ? initialText : null);
            return;
        }
        isEditing = true;
        currentEditCell = cell;
        cell.classList.add('cell-editing');

        const fieldType = cell.getAttribute('data-type');
        let targetNode = cell.querySelector('.scroll-box') || cell;
        const currentValue = targetNode.innerText.trim();

        cell.setAttribute('data-original-value', currentValue);

        if (fieldType === 'select-vertical' || fieldType === 'select-closer') {
            const optionsList = fieldType === 'select-vertical' ? verticalOptions : closerStatusOptions;
            let selectHTML = `<select class="status-select-input" onblur="finishEditing(this.parentElement)">`;
            for (const [val, label] of Object.entries(optionsList)) {
                const selected = (val === currentValue || label === currentValue) ? 'selected' : '';
                selectHTML += `<option value="${val}" ${selected}>${label}</option>`;
            }
            selectHTML += `</select>`;
            cell.innerHTML = selectHTML;

            const selectElement = cell.querySelector('select');
            selectElement.focus();
        } else {
            targetNode.setAttribute('contenteditable', 'true');
            if (clearContent) targetNode.innerText = initialText === null ? '' : initialText;
            targetNode.focus();

            const range = document.createRange();
            const sel = window.getSelection();
            range.selectNodeContents(targetNode);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);

            targetNode.addEventListener('blur', function blurHandler() {
                if (isEditing && currentEditCell === cell) {
                    finishEditing(cell);
                    targetNode.removeEventListener('blur', blurHandler);
                }
            });
        }
    }

    function finishEditing(cell) {
        if (!isEditing || cell !== currentEditCell) return;

        isEditing = false;
        currentEditCell = null;
        cell.classList.remove('cell-editing');

        const field = cell.getAttribute('data-field') || cell.getAttribute('data-col');
        const id = cell.getAttribute('data-id');

        let newValue = '';
        let selectEl = cell.querySelector('select');

        if (selectEl) {
            newValue = selectEl.options[selectEl.selectedIndex].value;
            cell.innerHTML = newValue;
        } else {
            let targetNode = cell.querySelector('.scroll-box') || cell;
            targetNode.removeAttribute('contenteditable');
            newValue = targetNode.innerText.trim();
        }

        const originalValue = cell.getAttribute('data-original-value');
        if (newValue !== originalValue) {
            if (field === 'closer_status' && newValue === 'CB') {
                openCbModal(id, cell, field);
            }
            saveCellAjax(id, field, newValue, cell);
            cell.setAttribute('data-original-value', newValue);
        }
    }

    function closeFullScreenEditor() {
        document.getElementById('fullScreenEditorModal').classList.remove('show');
        isEditing = false;
        currentEditCell = null;
        window.fsActiveCell = null;
    }

    function saveFullScreenEditor() {
        const leadId = document.getElementById('fs-lead-id').value;
        const field = document.getElementById('fs-field-name').value;
        const value = document.getElementById('fs-textarea').value;
        const activeNode = window.fsActiveCell;
        const activeCell = activeNode && activeNode.closest('td');
        if (!activeCell) return;

        activeNode.innerText = value;
        activeCell.setAttribute('data-original-value', value);
        saveCellAjax(leadId, field, value, activeCell);
        closeFullScreenEditor();
    }

    let cbPendingCell = null;

    function openCbModal(id, cell, field) {
        document.getElementById('cbLeadId').value = id;
        document.getElementById('cbField').value = field;
        cbPendingCell = cell;
        document.getElementById('cbModal').classList.add('show');
    }

    function closeCbModal() {
        document.getElementById('cbModal').classList.remove('show');
        cbPendingCell = null;
    }

    function saveCallback() {
        const leadId = document.getElementById('cbLeadId').value;
        const dt = document.getElementById('cbDateTime').value;
        const tz = document.getElementById('cbTimezone').value;

        if (!dt) {
            alert("Please select a date and time.");
            return;
        }

        indicator.innerText = "Saving Callback...";
        indicator.style.color = "#8b5cf6";
        indicator.classList.add('show');

        const formData = new URLSearchParams();
        formData.append('action', 'save_callback');
        formData.append('lead_id', leadId);
        formData.append('callback_time', dt);
        formData.append('timezone', tz);

        fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    indicator.innerText = "Reminder Set!";
                    indicator.style.color = "#10b981";
                    setTimeout(() => {
                        indicator.classList.remove('show');
                    }, 2000);
                    closeCbModal();
                } else {
                    indicator.innerText = "Error Saving";
                    indicator.style.color = "#ef4444";
                }
            }).catch(err => {
                indicator.innerText = "Network Error";
                indicator.style.color = "#ef4444";
            });
    }

    function saveCellAjax(id, field, value, cell) {
        indicator.innerText = "Saving...";
        indicator.style.color = "#8b5cf6";
        indicator.classList.add('show');

        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('col', field);
        formData.append('val', value);

        fetch('autosave_sheet.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(response => response.text())
            .then(data => {
                if (data.trim() === 'Saved') {
                    indicator.innerText = "Saved";
                    indicator.style.color = "#10b981";
                    setTimeout(() => {
                        indicator.classList.remove('show');
                    }, 2000);

                    if (field === 'closer_status' && value === 'Revised') {
                        let row = document.getElementById('row-' + id);
                        if (row) {
                            row.classList.add('locked-row');
                            row.querySelectorAll('td.cell-editable').forEach(td => {
                                const f = td.getAttribute('data-field');
                                if (f !== 'application_text' && f !== 'questions') {
                                    td.classList.remove('cell-editable');
                                    td.classList.add('locked-cell');
                                    td.removeAttribute('tabindex');
                                }
                            });

                            const fd2 = new URLSearchParams();
                            fd2.append('id', id);
                            fd2.append('col', 'status');
                            fd2.append('val', 'Pending Verification');
                            fetch('autosave_sheet.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: fd2.toString()
                            });

                            let statusCell = row.querySelector('.system-status-cell .scroll-box');
                            if (statusCell) statusCell.innerText = 'Pending Verification';
                        }
                    }
                } else {
                    indicator.innerText = "Error Saving";
                    indicator.style.color = "#ef4444";
                }
            }).catch(err => {
                indicator.innerText = "Network Error";
                indicator.style.color = "#ef4444";
            });
    }

    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.hasAttribute('enctype') || form.querySelector('button[name="generate_c2c"]') || form.querySelector('button[name="upload_more_c2c"]') || form.querySelector('button[name="send_dashboard_link"]') || form.querySelector('button[name="send_approval_link"]')) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                if (submitBtn.classList.contains('processing')) {
                    e.preventDefault();
                    return false;
                }
                submitBtn.classList.add('processing');
                submitBtn.style.opacity = '0.7';
                submitBtn.style.cursor = 'not-allowed';
                submitBtn.innerHTML = 'Processing...';
            }
        }
    });

    let chatRefreshInterval;
    let combinedChatInterval;
    let reportLeadId = null;

    function escapeCombinedChatHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    async function openCombinedChatModal(leadId, customerName) {
        reportLeadId = leadId;
        document.getElementById('combinedChatLeadId').value = leadId;
        document.getElementById('combinedChatLeadName').textContent = customerName;
        document.getElementById('combinedChatModal').classList.add('show');
        document.getElementById('reportActionMessage').textContent = '';
        document.getElementById('reportActionMessage').style.display = 'none';
        document.getElementById('combinedChatInput').value = '';
        const badge = document.getElementById('closer-chat-badge-' + leadId);
        if (badge) badge.style.display = 'none';

        const reportContent = document.getElementById('combinedReportContent');
        const doneButton = document.getElementById('markReportDoneButton');
        reportContent.textContent = 'Loading report...';
        doneButton.hidden = true;
        doneButton.disabled = true;
        try {
            const response = await fetch(`posted_leads.php?action=fetch_report&lead_id=${encodeURIComponent(leadId)}`);
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load report.');
            window.renderSafeReport(reportContent, result.report || 'No receptionist report is available.');
            doneButton.hidden = !result.can_mark_done;
            doneButton.disabled = !result.can_mark_done;
        } catch (error) {
            reportContent.textContent = error.message || 'Unable to load report.';
        }
        clearInterval(combinedChatInterval);
        loadCombinedChatMessages();
        combinedChatInterval = setInterval(loadCombinedChatMessages, 3000);
    }

    function closeCombinedChatModal() {
        document.getElementById('combinedChatModal').classList.remove('show');
        clearInterval(combinedChatInterval);
        reportLeadId = null;
    }

    function loadCombinedChatMessages() {
        const leadId = document.getElementById('combinedChatLeadId').value;
        if (!leadId) return;
        fetch(`posted_leads.php?action=fetch_inhouse_chat&lead_id=${encodeURIComponent(leadId)}`)
            .then(response => response.json())
            .then(messages => {
                if (!Array.isArray(messages)) throw new Error(messages.message || 'Unable to load chat.');
                const container = document.getElementById('combinedChatMessages');
                const shouldScroll = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                const previousScrollTop = container.scrollTop;
                container.innerHTML = '';
                if (!messages.length) container.innerHTML = '<p style="color:#94a3b8;font-style:italic;text-align:center;margin-top:20px;font-size:13px;">No messages yet.</p>';
                messages.forEach(message => {
                    const item = document.createElement('div');
                    const senderType = message.sender_type || 'Closer';
                    let bg = '#1e293b';
                    let borderColor = '#3b82f6';
                    let senderColor = '#38bdf8';
                    if (senderType === 'Admin') {
                        bg = '#0f172a';
                        borderColor = '#8b5cf6';
                        senderColor = '#a78bfa';
                    } else if (senderType === 'Agent') {
                        bg = '#111827';
                        borderColor = '#10b981';
                        senderColor = '#34d399';
                    } else if (senderType === 'Closer') {
                        bg = '#1e293b';
                        borderColor = '#f59e0b';
                        senderColor = '#fbbf24';
                    }
                    item.style.cssText = `background:${bg};border-left:4px solid ${borderColor};color:#f8fafc;padding:10px 15px;border-radius:8px;margin-bottom:10px;align-self:${senderType === 'Closer' ? 'flex-end' : 'flex-start'};max-width:85%;`;
                    const time = new Date(message.created_at).toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const sender = message.sender_type === 'Closer' ? 'You' : 'Admin / Receptionist';
                    item.innerHTML = `<strong style="color:${senderColor};font-size:12px;">${escapeCombinedChatHtml(sender)}</strong><span style="font-size:10px;color:#94a3b8;float:right;margin-left:10px;">${escapeCombinedChatHtml(time)}</span><p style="margin:8px 0 0;font-size:14px;color:#e2e8f0;line-height:1.5;white-space:pre-wrap;">${escapeCombinedChatHtml(message.message).replace(/\n/g, '<br>')}</p>`;
                    container.appendChild(item);
                });
                container.scrollTop = shouldScroll ? container.scrollHeight : previousScrollTop;
            })
            .catch(error => console.error('Unable to load inhouse chat:', error));
    }

    function sendCombinedChatMessage() {
        const leadId = document.getElementById('combinedChatLeadId').value;
        const input = document.getElementById('combinedChatInput');
        const message = input.value.trim();
        if (!message || !leadId) return;
        fetch('posted_leads.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'send_inhouse_chat',
                lead_id: leadId,
                message
            }).toString(),
            credentials: 'same-origin'
        }).then(response => response.json()).then(result => {
            if (!result.success) throw new Error(result.message || 'Unable to send message.');
            input.value = '';
            loadCombinedChatMessages();
        }).catch(error => window.alert(error.message || 'Unable to send message.'));
    }

    async function markReportDone() {
        if (!reportLeadId) return;
        const doneButton = document.getElementById('markReportDoneButton');
        const message = document.getElementById('reportActionMessage');
        doneButton.disabled = true;
        try {
            const response = await fetch('posted_leads.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'mark_report_done',
                    lead_id: reportLeadId
                }).toString(),
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to mark report as done.');
            doneButton.hidden = true;
            message.style.display = 'block';
            message.textContent = result.synced ? 'Marked as done and sent to the Receptionist queue.' : 'Marked as done locally, but Live Server sync failed. Please contact an administrator.';
            const row = document.getElementById('row-' + reportLeadId);
            if (row) {
                const statusCell = row.querySelector('.system-status-cell .scroll-box');
                if (statusCell) statusCell.textContent = 'Pending Post';
                const closerStatusCell = row.querySelector('[data-field="closer_status"] .scroll-box');
                if (closerStatusCell) closerStatusCell.textContent = 'Pending Post';
            }
        } catch (error) {
            doneButton.disabled = false;
            message.style.display = 'block';
            message.textContent = error.message || 'Unable to mark report as done.';
        }
    }

    function openChatModal(leadId, customerName) {
        document.getElementById('chatLeadId').value = leadId;
        document.getElementById('chatLeadName').innerText = customerName;
        document.getElementById('chatModal').classList.add('show');
        document.getElementById('chatInput').value = '';
        loadChatMessages();
        chatRefreshInterval = setInterval(loadChatMessages, 3000);
    }

    function closeChatModal() {
        document.getElementById('chatModal').classList.remove('show');
        clearInterval(chatRefreshInterval);
    }

    function loadChatMessages() {
        const leadId = document.getElementById('chatLeadId').value;
        if (!leadId) return;

        fetch(`posted_leads.php?action=fetch_chat&lead_id=${leadId}`)
            .then(r => r.json())
            .then(messages => {
                const container = document.getElementById('chatMessages');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                const previousScrollTop = container.scrollTop;
                container.innerHTML = '';
                if (messages.length === 0) {
                    container.innerHTML = "<p style='color: #888; font-style: italic; text-align:center;'>No updates yet.</p>";
                } else {
                    messages.forEach(msg => {
                        const senderType = msg.sender_type || msg.role || 'User';
                        let bg = '#1e293b';
                        let borderColor = '#3b82f6';
                        let senderColor = '#38bdf8';
                        if (senderType === 'Admin') {
                            bg = '#0f172a';
                            borderColor = '#8b5cf6';
                            senderColor = '#a78bfa';
                        } else if (senderType === 'Agent') {
                            bg = '#111827';
                            borderColor = '#10b981';
                            senderColor = '#34d399';
                        } else if (senderType === 'Closer') {
                            bg = '#1e293b';
                            borderColor = '#f59e0b';
                            senderColor = '#fbbf24';
                        }

                        const div = document.createElement('div');
                        div.style.background = bg;
                        div.style.padding = '10px 15px';
                        div.style.borderRadius = '8px';
                        div.style.borderLeft = `4px solid ${borderColor}`;
                        div.style.color = '#f8fafc';
                        const sender = document.createElement('strong');
                        sender.style.color = senderColor;
                        sender.textContent = `${msg.username || senderType} (${senderType})`;
                        const timestamp = document.createElement('span');
                        timestamp.style.cssText = 'font-size:11px; color:#94a3b8; float:right;';
                        timestamp.textContent = msg.created_at || '';
                        const body = document.createElement('p');
                        body.style.cssText = 'margin:8px 0 0; font-size:14px; color:#e2e8f0; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere;';
                        body.textContent = msg.content || msg.message || '';
                        div.append(sender, timestamp, body);
                        container.appendChild(div);
                    });
                }
                if (isScrolledToBottom) {
                    container.scrollTop = container.scrollHeight;
                } else {
                    container.scrollTop = previousScrollTop;
                }
            });
    }

    function sendChatMessage() {
        const leadId = document.getElementById('chatLeadId').value;
        const message = document.getElementById('chatInput').value.trim();
        if (!message || !leadId) return;

        const formData = new URLSearchParams();
        formData.append('action', 'send_chat');
        formData.append('lead_id', leadId);
        formData.append('message', message);

        fetch('posted_leads.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById('chatInput').value = '';
                    loadChatMessages();
                }
            });
    }

    // ==========================================
    // EXCEL-STYLE FILTERING & SEARCH (HYBRID SQL ENGINE)
    // ==========================================
    let activeFilters = {};

    function initializeFilters() {
        const table = document.getElementById('closer-table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const headers = table.querySelectorAll('th[data-filter="true"]');

        table.querySelectorAll('.filter-icon').forEach(el => el.remove());
        table.querySelectorAll('.filter-menu').forEach(el => el.remove());

        headers.forEach((th) => {
            const actualColIndex = Array.from(th.parentNode.children).indexOf(th);
            let uniqueValues = new Set();

            rows.forEach(row => {
                const cell = row.cells[actualColIndex];
                if (!cell) return;
                let text = cell.querySelector('select') ? cell.querySelector('select').options[cell.querySelector('select').selectedIndex].text : cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                if (text !== "") uniqueValues.add(text);
            });

            if (uniqueValues.size === 0) return;

            th.style.position = 'relative';
            th.insertAdjacentHTML('beforeend', `
            <i class="fas fa-filter filter-icon" id="icon-col-${actualColIndex}"></i>
            <div class="filter-menu" id="menu-col-${actualColIndex}">
                <input type="text" class="filter-search" placeholder="Search filter..." onkeyup="searchFilterOptions(this)">
                <div class="filter-options-container"></div>
                <div class="filter-actions" style="display: flex; justify-content: space-between; border-top: 1px solid rgba(56, 189, 248, 0.2); padding-top: 8px; margin-top: 8px;">
                    <button type="button" class="filter-btn clear-btn" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer;">Clear</button>
                    <button type="button" class="filter-btn apply-btn" style="background:#007BFF; color:white; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">Apply</button>
                </div>
            </div>
        `);

            const icon = th.querySelector('.filter-icon');
            const menu = th.querySelector('.filter-menu');
            const optionsContainer = th.querySelector('.filter-options-container');
            const applyBtn = th.querySelector('.apply-btn');
            const clearBtn = th.querySelector('.clear-btn');
            const colNameKey = th.getAttribute('data-name');

            Array.from(uniqueValues).sort().forEach(val => {
                let isChecked = (activeFilters[colNameKey] && activeFilters[colNameKey].includes(val)) ? 'checked' : '';
                optionsContainer.insertAdjacentHTML('beforeend', `
                <label class="filter-option">
                    <input type="checkbox" value="${val.replace(/"/g, '&quot;')}" ${isChecked}>
                    ${val}
                </label>
            `);
            });

            if (activeFilters[colNameKey] && activeFilters[colNameKey].length > 0) {
                icon.classList.add('active');
            }

            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                document.querySelectorAll('.filter-menu.show').forEach(m => {
                    if (m !== menu) m.classList.remove('show');
                });
                menu.classList.toggle('show');
            });

            menu.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            applyBtn.addEventListener('click', function() {
                const checkedBoxes = Array.from(optionsContainer.querySelectorAll('input:checked')).map(cb => cb.value);
                if (checkedBoxes.length > 0) {
                    activeFilters[colNameKey] = checkedBoxes;
                } else {
                    delete activeFilters[colNameKey];
                }
                menu.classList.remove('show');
                triggerBackendSearch();
            });

            clearBtn.addEventListener('click', function() {
                optionsContainer.querySelectorAll('input:checked').forEach(cb => cb.checked = false);
                delete activeFilters[colNameKey];
                menu.classList.remove('show');
                triggerBackendSearch();
            });
        });
    }

    function searchFilterOptions(inputEl) {
        let filter = inputEl.value.toLowerCase();
        let labels = inputEl.nextElementSibling.querySelectorAll('.filter-option');
        labels.forEach(label => {
            let text = label.innerText.toLowerCase();
            label.style.display = text.includes(filter) ? "flex" : "none";
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.filter-menu') && !e.target.closest('.filter-icon')) {
            document.querySelectorAll('.filter-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // BLAZING FAST SERVER SEARCH HANDLER
    function triggerBackendSearch() {
        currentOffset = 0;
        hasMore = true;

        const tbody = document.querySelector('#closer-table tbody');
        const colCount = document.querySelector('thead tr').children.length;
        tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center; padding: 20px; color: #666; font-size: 14px;">Searching Database...</td></tr>`;

        const searchBox = document.getElementById('search-input');
        const searchTerm = searchBox ? searchBox.value.trim() : '';
        const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

        fetch(`posted_leads.php?action=fetch_fresh&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
            .then(r => r.json())
            .then(data => {
                if (data.html && data.html.trim() !== '') {
                    tbody.innerHTML = data.html;
                } else {
                    tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center; padding: 20px; color: #888; font-size: 14px;">No matching leads found.</td></tr>`;
                }
                currentOffset = 50;
                initializeFilters();
                document.querySelectorAll('.autosave-val').forEach(textarea => {
                    textarea.addEventListener('blur', function() {
                        saveCellAjax(this.getAttribute('data-id'), this.getAttribute('data-field') || this.getAttribute('data-col'), this.value);
                    });
                });
            }).catch(err => console.error('Search error:', err));
    }

    document.addEventListener("DOMContentLoaded", function() {
        initializeFilters();

        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    triggerBackendSearch();
                }, 300);
            });
        }
    });

    let currentOffset = 50;
    let isLoadingMore = false;
    let hasMore = true;

    function loadMoreLeads() {
        if (!hasMore || isLoadingMore) return;
        isLoadingMore = true;

        const searchBox = document.getElementById('search-input');
        const searchTerm = searchBox ? searchBox.value.trim() : '';
        const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

        fetch(`posted_leads.php?action=load_more&offset=${currentOffset}&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
            .then(r => r.json())
            .then(data => {
                if (data.html.trim() === '') {
                    hasMore = false;
                } else {
                    document.querySelector('#closer-table tbody').insertAdjacentHTML('beforeend', data.html);
                    currentOffset += 50;
                    initializeFilters();
                    document.querySelectorAll('.autosave-val').forEach(textarea => {
                        textarea.removeEventListener('blur', handleTextareaBlur);
                        textarea.addEventListener('blur', handleTextareaBlur);
                    });
                }
                isLoadingMore = false;
            }).catch(() => {
                isLoadingMore = false;
            });
    }

    function handleTextareaBlur() {
        saveCellAjax(this.getAttribute('data-id'), this.getAttribute('data-field') || this.getAttribute('data-col'), this.value);
    }

    document.getElementById('leadTableContainer').addEventListener('scroll', function() {
        if (this.scrollTop + this.clientHeight >= this.scrollHeight - 50) {
            loadMoreLeads();
        }
    });

    function openCalPopup(leadId, token, customerId, frontId, backId, addRecords) {
        document.getElementById('cal_lead_id').value = leadId;
        const activeDiv = document.getElementById('cal_upload_div_active');
        const inactiveDiv = document.getElementById('cal_upload_div_inactive');
        const tokenInput = document.getElementById('cal_upload_input');

        if (token && token !== '') {
            tokenInput.value = 'https://consumerhealthcarecompliance.com/uploads?token=' + token;
            activeDiv.style.display = 'block';
            inactiveDiv.style.display = 'none';
        } else {
            activeDiv.style.display = 'none';
            inactiveDiv.style.display = 'block';
            inactiveDiv.style.setProperty('color', '#0f172a', 'important');
            inactiveDiv.querySelectorAll('p').forEach(paragraph => paragraph.style.setProperty('color', '#0f172a', 'important'));
        }

        const customerIdDisplay = document.getElementById('cal_customer_id_display');
        if (customerId && customerId !== '') {
            customerIdDisplay.innerText = 'Current Customer ID: ' + customerId;
            customerIdDisplay.style.display = 'block';
        } else {
            customerIdDisplay.style.display = 'none';
        }

        const proofsContainer = document.getElementById('cal_proofs_container');
        proofsContainer.replaceChildren();
        const appendProof = (label, path, isImage) => {
            const proofUrl = new URL(path, window.location.href);
            if (!['http:', 'https:'].includes(proofUrl.protocol)) return;

            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'flex-shrink:0; text-align:center;';
            const caption = document.createElement('span');
            caption.style.cssText = 'font-size:10px; font-weight:bold; color:#64748b;';
            caption.textContent = label;
            wrapper.appendChild(caption);
            wrapper.appendChild(document.createElement('br'));

            const link = document.createElement('a');
            link.href = proofUrl.href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            if (isImage) {
                const image = document.createElement('img');
                image.src = proofUrl.href;
                image.alt = label;
                image.style.cssText = 'height:100px; width:auto; border-radius:6px; border:1px solid #cbd5e1; margin-top:4px;';
                link.appendChild(image);
            } else {
                link.style.cssText = 'display:inline-block; margin-top:4px; padding:10px; background:#e2e8f0; color:#334155; border-radius:6px; text-decoration:none; font-size:12px; font-weight:bold;';
                link.innerHTML = '<i class="fas fa-file-alt"></i> View';
            }
            wrapper.appendChild(link);
            proofsContainer.appendChild(wrapper);
        };

        if (frontId) appendProof('Front ID', frontId, true);
        if (backId) appendProof('Back ID', backId, true);
        if (addRecords) {
            addRecords.split(',').forEach((record, index) => {
                const path = record.trim();
                if (path) appendProof('Record ' + (index + 1), path, false);
            });
        }
        if (!proofsContainer.children.length) {
            const emptyMessage = document.createElement('p');
            emptyMessage.style.cssText = 'color:#94a3b8; font-size:12px; font-style:italic;';
            emptyMessage.textContent = 'No proofs uploaded yet.';
            proofsContainer.appendChild(emptyMessage);
        }

        document.getElementById('calModal').classList.add('show');
    }

    function closeCalPopup() {
        document.getElementById('calModal').classList.remove('show');
    }

    function openApprovalModal() {
        document.getElementById('approval_lead_id').value = document.getElementById('cal_lead_id').value;
        closeCalPopup();
        document.getElementById('approvalModal').classList.add('show');
    }

    function closeApprovalModal() {
        document.getElementById('approvalModal').classList.remove('show');
    }

    function openAddDocsModal(leadId) {
        document.getElementById('addDocsLeadId').value = leadId;
        document.getElementById('addDocsModal').classList.add('show');
    }

    function closeAddDocsModal() {
        document.getElementById('addDocsModal').classList.remove('show');
    }

    function showSuccessPopup(message) {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = 'toast-alert';
        toast.innerHTML = `
        <div style="padding-top:2px; color:#10b981 !important;"><i class="fas fa-check-circle fa-lg"></i></div>
        <div style="flex:1;">
            <h4 style="margin:0; font-size:14px; font-weight:bold; color:#f8fafc !important;">Notification</h4>
            <p style="margin:4px 0 0; font-size:12px; line-height:1.4; color:#cbd5e1 !important;">${message}</p>
        </div>
        <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; color:#94a3b8 !important; font-size:18px; line-height:1;">&times;</button>
    `;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 8000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($success_message)): ?>
            showSuccessPopup(<?= json_encode(htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        <?php endif; ?>
    });

    setInterval(() => {
        if (typeof canRefresh === 'function' && canRefresh() && !isEditing) {
            const searchInput = document.getElementById('search-input');
            const searchTerm = searchInput ? searchInput.value.trim() : '';
            const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

            fetch(`posted_leads.php?action=fetch_fresh&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
                .then(r => r.json())
                .then(data => {
                    if (data.html) {
                        document.querySelector('#closer-table tbody').innerHTML = data.html;
                        currentOffset = 50;
                        initializeFilters();
                    }
                });
        }
    }, 420000);

    document.addEventListener("DOMContentLoaded", function() {
        const reportPre = document.getElementById('combinedReportContent');
        if (reportPre) {
            reportPre.style.cursor = 'zoom-in';
            reportPre.title = 'Double-click to expand to full screen';
            reportPre.addEventListener('dblclick', function() {
                document.getElementById('trueFullscreenReportText').innerText = this.innerText;
                document.getElementById('trueFullscreenReportModal').classList.add('show');
                document.body.style.overflow = 'hidden';
            });
        }
    });

    function closeTrueFullscreenReport() {
        document.getElementById('trueFullscreenReportModal').classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('trueFullscreenReportModal').addEventListener('click', function(event) {
        if (event.target === this) closeTrueFullscreenReport();
    });
</script>

<?php include 'includes/footer.php'; ?>