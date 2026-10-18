<?php
// FILE: api_send_company_info.php

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

$subject = "Claim what you deserve. Get a free consultation to check your eligibility";

// ======================================================================
// 2. AGENT EMAIL HTML TEMPLATE
// ======================================================================

$message = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Consumer Healthcare Compliances</title>
</head>

<body style='margin:0; padding:0; background-color:#ffffff; font-family:Arial, Helvetica, sans-serif; color:#333333;'>

    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#ffffff;'>
        <tr>
            <td align='center'>

                <!-- Email Container -->
                <table width='600' cellpadding='0' cellspacing='0' border='0'
                       style='width:100%; max-width:600px; background:#ffffff;'>

                    <!-- ==================================================
                         HEADER / LOGO
                         ================================================== -->
                    <tr>
                        <td style='padding:25px 30px 22px 30px;
                                   border-bottom:3px solid #b9975b;
                                   text-align:center;'>

                            <!-- CHC Logo -->
                            <img
                                src='https://consumerhealthcarecompliance.com/workspace/assets/logo.png'
                                alt='Consumer Healthcare Compliances'
                                width='230'
                                style='display:block;
                                       width:230px;
                                       max-width:100%;
                                       height:auto;
                                       border:0;
                                       outline:none;
                                       text-decoration:none;
                                       margin:0 auto;'
                            >

                            <!-- Company Name -->
                            <div style='font-size:18px;
                                        font-weight:bold;
                                        color:#17324d;
                                        margin-top:10px;
                                        letter-spacing:0.2px;'>
                                Consumer Healthcare Compliances
                            </div>

                        </td>
                    </tr>


                    <!-- ==================================================
                         INTRO
                         ================================================== -->
                    <tr>
                        <td style='padding:30px 30px 10px 30px;'>

                            <p style='margin:0 0 20px 0;
                                      font-size:15px;
                                      line-height:1.7;
                                      color:#333333;'>
                                Dear $first_name $last_name,
                            </p>

                            <p style='margin:0 0 18px 0;
                                      font-size:14px;
                                      line-height:1.75;
                                      color:#3f464c;'>
                                We are reaching out to inform you about a survey concerning
                                a potential class action lawsuit from
                                <strong>Consumer Healthcare Compliances</strong>.
                                You may wish to determine whether you could qualify for
                                an ongoing personal injury claim.
                            </p>

                            <p style='margin:0 0 22px 0;
                                      font-size:14px;
                                      line-height:1.75;
                                      color:#3f464c;'>
                                Consumer Healthcare Compliances helps individuals and families
                                explore potential legal options involving defective drugs,
                                unsafe products, and corporate negligence through class action
                                and personal injury litigation.
                            </p>

                        </td>
                    </tr>


                    <!-- ==================================================
                         INFORMATION HIGHLIGHT
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 25px 30px;'>

                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'
                                   style='border-left:4px solid #b9975b;
                                          background:#fafafa;'>

                                <tr>
                                    <td style='padding:16px 18px;'>

                                        <div style='font-size:12px;
                                                    font-weight:bold;
                                                    color:#17324d;
                                                    margin-bottom:6px;'>
                                            POTENTIAL CLAIM INFORMATION
                                        </div>

                                        <div style='font-size:13px;
                                                    color:#59636b;
                                                    line-height:1.65;'>
                                            If you believe you may have been affected,
                                            completing the survey may help determine whether
                                            you could pursue potential compensation for
                                            qualifying medical expenses and personal losses.
                                        </div>

                                    </td>
                                </tr>

                            </table>

                        </td>
                    </tr>


                    <!-- ==================================================
                         SECTION HEADING
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 15px 30px;'>

                            <div style='font-size:15px;
                                        font-weight:bold;
                                        color:#17324d;
                                        padding-bottom:9px;
                                        border-bottom:1px solid #e5e7e9;'>
                                Key Information About the Process
                            </div>

                        </td>
                    </tr>


                    <!-- ==================================================
                         BENEFITS
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 10px 30px;'>


                            <!-- ITEM 1 -->
                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'>

                                <tr>

                                    <td width='28'
                                        valign='top'
                                        style='padding-top:2px;'>

                                        <div style='font-size:12px;
                                                    font-weight:bold;
                                                    color:#b9975b;'>
                                            01
                                        </div>

                                    </td>

                                    <td valign='top'
                                        style='padding-bottom:17px;'>

                                        <div style='font-size:13px;
                                                    font-weight:bold;
                                                    color:#333333;'>
                                            Hassle-Free Process
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#626c74;
                                                    line-height:1.6;
                                                    margin-top:3px;'>
                                            The process can be completed online, so there is
                                            no need to visit a law firm in person. Individuals
                                            across the United States may complete the initial survey.
                                        </div>

                                    </td>

                                </tr>

                            </table>


                            <!-- ITEM 2 -->
                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'>

                                <tr>

                                    <td width='28'
                                        valign='top'
                                        style='padding-top:2px;'>

                                        <div style='font-size:12px;
                                                    font-weight:bold;
                                                    color:#b9975b;'>
                                            02
                                        </div>

                                    </td>

                                    <td valign='top'
                                        style='padding-bottom:17px;'>

                                        <div style='font-size:13px;
                                                    font-weight:bold;
                                                    color:#333333;'>
                                            Confidential Intake
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#626c74;
                                                    line-height:1.6;
                                                    margin-top:3px;'>
                                            We do not request financial or banking information
                                            as part of the initial survey process.
                                        </div>

                                    </td>

                                </tr>

                            </table>


                            <!-- ITEM 3 -->
                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'>

                                <tr>

                                    <td width='28'
                                        valign='top'
                                        style='padding-top:2px;'>

                                        <div style='font-size:12px;
                                                    font-weight:bold;
                                                    color:#b9975b;'>
                                            03
                                        </div>

                                    </td>

                                    <td valign='top'
                                        style='padding-bottom:17px;'>

                                        <div style='font-size:13px;
                                                    font-weight:bold;
                                                    color:#333333;'>
                                            No Upfront Charges
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#626c74;
                                                    line-height:1.6;
                                                    margin-top:3px;'>
                                            There are no upfront charges for the initial
                                            consultation or case review.
                                        </div>

                                    </td>

                                </tr>

                            </table>


                            <!-- ITEM 4 -->
                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'>

                                <tr>

                                    <td width='28'
                                        valign='top'
                                        style='padding-top:2px;'>

                                        <div style='font-size:12px;
                                                    font-weight:bold;
                                                    color:#b9975b;'>
                                            04
                                        </div>

                                    </td>

                                    <td valign='top'
                                        style='padding-bottom:5px;'>

                                        <div style='font-size:13px;
                                                    font-weight:bold;
                                                    color:#333333;'>
                                            Payment Disbursement
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#626c74;
                                                    line-height:1.6;
                                                    margin-top:3px;'>
                                            If compensation is ultimately awarded, payment
                                            arrangements will be determined according to
                                            the applicable case and legal process.
                                        </div>

                                    </td>

                                </tr>

                            </table>

                        </td>
                    </tr>


                    <!-- ==================================================
                         DIVIDER
                         ================================================== -->
                    <tr>
                        <td style='padding:10px 30px 20px 30px;'>

                            <div style='height:1px;
                                        background:#e5e7e9;'>
                            </div>

                        </td>
                    </tr>


                    <!-- ==================================================
                         MAIN TEXT
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 10px 30px;'>

                            <p style='margin:0 0 18px 0;
                                      font-size:14px;
                                      line-height:1.75;
                                      color:#3f464c;'>
                                If you or your family member have experienced circumstances that
                                may be relevant to one of these matters, you may have the
                                opportunity to request a <strong>free case evaluation</strong>.
                            </p>

                            <p style='margin:0 0 22px 0;
                                      font-size:14px;
                                      line-height:1.75;
                                      color:#3f464c;'>
                                Our purpose is to provide general information and help you
                                determine whether you may qualify for consideration in a
                                class action or personal injury matter through a transparent
                                consultation process.
                            </p>

                        </td>
                    </tr>


                    <!-- ==================================================
                         NEXT STEP
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 25px 30px;'>

                            <table width='100%'
                                   cellpadding='0'
                                   cellspacing='0'
                                   border='0'
                                   style='border-top:1px solid #dfe3e6;
                                          border-bottom:1px solid #dfe3e6;'>

                                <tr>

                                    <td style='padding:16px 0;'>

                                        <div style='font-size:11px;
                                                    font-weight:bold;
                                                    color:#b9975b;
                                                    text-transform:uppercase;
                                                    letter-spacing:1px;
                                                    margin-bottom:5px;'>
                                            Next Step
                                        </div>

                                        <div style='font-size:13px;
                                                    color:#4f5961;
                                                    line-height:1.65;'>
                                            A case representative may contact you shortly
                                            to discuss the survey and answer any questions
                                            you may have.
                                        </div>

                                    </td>

                                </tr>

                            </table>

                        </td>
                    </tr>


                    <!-- ==================================================
                         CLOSING / SIGNATURE
                         ================================================== -->
                    <tr>
                        <td style='padding:0 30px 30px 30px;'>

                            <p style='margin:0 0 8px 0;
                                      font-size:14px;
                                      line-height:1.6;
                                      color:#3f464c;'>
                                We are here to provide information regarding your potential
                                legal options.
                            </p>

                            <p style='margin:0 0 20px 0;
                                      font-size:14px;
                                      line-height:1.6;
                                      color:#3f464c;'>
                                Thank you,
                            </p>


                            <!-- Signature -->
                            <table cellpadding='0'
                                   cellspacing='0'
                                   border='0'>

                                <tr>

                                    <td style='border-left:3px solid #b9975b;
                                               padding-left:14px;'>

                                        <div style='font-size:15px;
                                                    font-weight:bold;
                                                    color:#17324d;'>
                                            $agent_name
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#68747e;
                                                    margin-top:3px;'>
                                            Case Representative
                                        </div>

                                        <div style='font-size:12px;
                                                    color:#68747e;
                                                    margin-top:2px;'>
                                            Consumer Healthcare Compliances
                                        </div>

                                        <div style='font-size:12px;
                                                    margin-top:5px;'>

                                            <a href='https://consumerhealthcarecompliance.com'
                                               style='color:#315f86;
                                                      text-decoration:none;'>
                                                consumerhealthcarecompliance.com
                                            </a>

                                        </div>

                                    </td>

                                </tr>

                            </table>

                        </td>
                    </tr>


                    <!-- ==================================================
                         FOOTER
                         ================================================== -->
                    <tr>
                        <td style='border-top:1px solid #e2e5e7;
                                   padding:20px 30px 25px 30px;'>

                            <div style='font-size:10px;
                                        font-weight:bold;
                                        color:#59636b;
                                        text-transform:uppercase;
                                        letter-spacing:0.6px;
                                        margin-bottom:7px;'>
                                Important Notice
                            </div>

                            <div style='font-size:10px;
                                        color:#7a838a;
                                        line-height:1.65;
                                        text-align:justify;'>

                                Eligibility and any potential compensation depend on the
                                specific circumstances of your case and applicable legal
                                requirements. Receiving this email does not mean that you
                                are eligible for compensation. Consumer Healthcare Compliances
                                does not request personal banking information or Social Security
                                information as part of this communication. This communication
                                is intended solely for the recipient identified above.
                                If you believe you have received this communication in error
                                or have questions regarding its contents, please contact
                                Consumer Healthcare Compliances.

                            </div>

                            <div style='margin-top:15px;
                                        text-align:center;
                                        font-size:9px;
                                        color:#9aa1a6;'>
                                Consumer Healthcare Compliances
                            </div>

                        </td>
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
