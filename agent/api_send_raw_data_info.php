<?php
// FILE: api_send_raw_data_info.php

// 1. Turn off visible PHP errors so they NEVER corrupt our JSON output
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$first_name = trim($data['first_name'] ?? '');
$last_name  = trim($data['last_name'] ?? '');
$email      = trim($data['email'] ?? '');

// Grab the agent name and automatically replace underscores with spaces
$raw_agent_name = trim($data['agent_name'] ?? 'Case Representative');
$agent_name = str_replace('_', ' ', $raw_agent_name);

if (
    empty($first_name) ||
    empty($last_name) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    ob_clean();

    echo json_encode([
        'status' => 'error',
        'message' => 'Valid First Name, Last Name, and Email are required.'
    ]);

    exit;
}

// ======================================================================
// 1. HOSTINGER SMTP CREDENTIALS
// ======================================================================

$smtp_host = "mail.consumerhealthcarecompliance.com";
$smtp_user = "support@consumerhealthcarecompliance.com";
$smtp_pass = "TheBigBull@123#";
$smtp_port = 465;

// ======================================================================
// MAIL CONTENT
// ======================================================================

$to = $email;

$subject = "Important Information Regarding Your Potential Claim - Consumer Healthcare Compliances";

// =========================================================================
// DRAFT YOUR RAW DATA (FRESH LEAD) EMAIL TEMPLATE BELOW
// Available variables: $first_name, $last_name, $email, $agent_name
// =========================================================================
$message = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Important Information Regarding Your Potential Claim</title>
</head>
<body style='margin:0; padding:24px 12px; background-color:#f4f6f8; font-family:Arial, Helvetica, sans-serif; color:#333333;'>
    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellpadding='0' cellspacing='0' border='0' style='width:100%; max-width:600px; background-color:#ffffff; border:1px solid #e2e6ea;'>
                    <tr>
                        <td style='padding:32px 36px; border-top:4px solid #b9975b;'>
                            <h1 style='margin:0 0 24px; color:#17324d; font-size:22px; line-height:1.4;'>Important Information Regarding Your Potential Claim</h1>
                            <p style='margin:0 0 16px; font-size:15px; line-height:1.7;'>Hello $first_name $last_name,</p>
                            <p style='margin:0 0 16px; font-size:15px; line-height:1.7;'>Thank you for getting in touch. We are following up to share information about your inquiry and the next steps available to you.</p>
                            <p style='margin:0 0 16px; font-size:15px; line-height:1.7;'>If you have questions or would like to discuss your situation, simply reply to this email. Our team will be glad to assist.</p>
                            <p style='margin:24px 0 4px; font-size:15px; line-height:1.7;'>Kind regards,</p>
                            <p style='margin:0; color:#17324d; font-size:15px; font-weight:bold;'>$agent_name</p>
                            <p style='margin:4px 0 0; color:#66717b; font-size:13px;'>Consumer Healthcare Compliances</p>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:16px 36px; border-top:1px solid #e2e6ea; color:#77818a; font-size:11px; line-height:1.6;'>This message is intended for the recipient identified above. Eligibility and any potential outcome depend on the specific circumstances of your situation. This email is for informational purposes and does not guarantee eligibility or any result.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
";

// ======================================================================
// 3. RAW SMTP ENGINE
// ======================================================================

function send_smtp_mail(
    $to,
    $subject,
    $message,
    $from_name,
    $smtp_host,
    $smtp_user,
    $smtp_pass,
    $smtp_port
) {

    // Ignore SSL certificate strictness locally so XAMPP doesn't complain
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    // Connect directly to Hostinger
    $socket = stream_socket_client(
        "ssl://$smtp_host:$smtp_port",
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return false;
    }

    // Helper to read server responses
    function server_parse($socket, $expected)
    {

        $res = '';

        while ($str = fgets($socket, 515)) {
            $res .= $str;

            if (substr($str, 3, 1) == ' ') {
                break;
            }
        }

        return (substr($res, 0, 3) == $expected);
    }

    // Server greeting
    server_parse($socket, '220');

    // EHLO
    fwrite($socket, "EHLO $smtp_host\r\n");

    if (!server_parse($socket, '250')) {
        fclose($socket);
        return false;
    }

    // Authenticate
    fwrite($socket, "AUTH LOGIN\r\n");

    if (!server_parse($socket, '334')) {
        fclose($socket);
        return false;
    }

    // Username
    fwrite($socket, base64_encode($smtp_user) . "\r\n");

    if (!server_parse($socket, '334')) {
        fclose($socket);
        return false;
    }

    // Password
    fwrite($socket, base64_encode($smtp_pass) . "\r\n");

    if (!server_parse($socket, '235')) {
        fclose($socket);
        return false;
    }

    // Sender
    fwrite($socket, "MAIL FROM: <$smtp_user>\r\n");

    if (!server_parse($socket, '250')) {
        fclose($socket);
        return false;
    }

    // Recipient
    fwrite($socket, "RCPT TO: <$to>\r\n");

    if (!server_parse($socket, '250')) {
        fclose($socket);
        return false;
    }

    // DATA
    fwrite($socket, "DATA\r\n");

    if (!server_parse($socket, '354')) {
        fclose($socket);
        return false;
    }

    // ==================================================================
    // EMAIL HEADERS
    // ==================================================================

    $headers  = "Date: " . date("r") . "\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "From: \"$from_name\" <$smtp_user>\r\n";
    $headers .= "Reply-To: $smtp_user\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: Consumer Healthcare Compliances\r\n";

    // ==================================================================
    // SEND EMAIL
    // ==================================================================

    fwrite(
        $socket,
        "$headers\r\n$message\r\n.\r\n"
    );

    $success = server_parse($socket, '250');

    // Close connection
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return $success;
}

// ======================================================================
// 4. EXECUTE SMTP ENGINE
// ======================================================================

$mail_sent = send_smtp_mail(
    $to,
    $subject,
    $message,
    "Consumer Healthcare Compliances",
    $smtp_host,
    $smtp_user,
    $smtp_pass,
    $smtp_port
);

// ======================================================================
// 5. CLEAN OUTPUT AND RETURN JSON
// ======================================================================

ob_clean();

if ($mail_sent) {

    echo json_encode([
        'status' => 'success',
        'message' => 'Email sent securely.'
    ]);
} else {

    echo json_encode([
        'status' => 'error',
        'message' => 'SMTP Authentication failed. Please verify your SMTP credentials.'
    ]);
}

exit;
