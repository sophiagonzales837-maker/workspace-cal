<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Closer');

// --- GENERATE CUSTOMER ID & SEND LOGIN LINK ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_customer_id'])) {
    $lead_id = (int)$_POST['lead_id'];

    // Check if customer_id already exists to prevent overwriting
    $q_check = mysqli_query($conn, "SELECT customer_id FROM leads WHERE id = '$lead_id'");
    $lead_data = mysqli_fetch_assoc($q_check);

    if (empty($lead_data['customer_id'])) {
        // Generate a formal customer_id (CAC-XXXX)
        $cust_id_gen = 'CAC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $stmt = $conn->prepare("UPDATE leads SET customer_id = ? WHERE id = ?");
        $stmt->bind_param("si", $cust_id_gen, $lead_id);
        $stmt->execute();
        $customer_id_to_send = $cust_id_gen;
    } else {
        $customer_id_to_send = $lead_data['customer_id'];
    }

    // Re-fetch decrypted info for email
    // Re-fetch decrypted info for email
    $q = mysqli_query($conn, "SELECT customer_name, email, phone, vertical FROM leads WHERE id = '$lead_id'");
    $lead_email_data = mysqli_fetch_assoc($q);
    $lead_email_data = decrypt_fields($lead_email_data);
    $to = $lead_email_data['email'] ?? '';

    // --- WEBHOOK: SYNC CUSTOMER ID TO LIVE HOSTING & TRIGGER EMAIL ---
    $vertical = $lead_email_data['vertical'] ?? '';
    $payload = json_encode([
        'lead_id'     => $lead_id,
        'customer_id' => $customer_id_to_send,
        'name'        => $lead_email_data['customer_name'],
        'email'       => $to,
        'phone'       => $lead_email_data['phone'] ?? '',
        'vertical'    => $vertical,
        'status'      => 'Waiting on Customer',
        'is_approval' => false,
        'auth_key'    => 'MakeMoney$$'
    ]);

    $ch = curl_init('https://consumerhealthcarecompliance.com/api/sync_dashboard_id.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    curl_close($ch);
    // -------------------------------------------------

    $success_message = "Customer ID Generated and Login Link Sent Successfully via Live Server.";
}

$lead_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0);
$closer_id = $_SESSION['user_id'];

$lead_query = mysqli_query($conn, "SELECT * FROM leads WHERE id = '$lead_id' AND closer_id = '$closer_id'");
if (mysqli_num_rows($lead_query) == 0) die("Lead not found.");
$lead = mysqli_fetch_assoc($lead_query);
$lead = decrypt_fields($lead);

include 'includes/header.php';
?>

<div style="margin-bottom: 20px;">
    <a href="index.php" style="text-decoration: none; color: var(--electric-blue); font-weight: bold;">&larr; Back to Leads</a>
</div>

<?php if (isset($success_message)): ?>
    <div style="background: #D1FAE5; color: #065F46; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;">
        ✅ <?php echo $success_message; ?>
    </div>
<?php endif; ?>

<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <!-- LEFT COLUMN: Lead Information & Additional Records -->
    <div class="card" style="flex: 1; min-width: 300px;">
        <h3 style="margin-top:0;">Lead Information</h3>
        <p style="margin: 5px 0; font-size: 14px;"><strong>Name:</strong> <?php echo htmlspecialchars($lead['customer_name'] ?? ''); ?></p>
        <p style="margin: 5px 0; font-size: 14px;"><strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone'] ?? ''); ?></p>
        <p style="margin: 5px 0; font-size: 14px;"><strong>Email:</strong> <?php echo htmlspecialchars($lead['email'] ?? ''); ?></p>

        <p style="margin: 5px 0; font-size: 14px;">
            <strong>Address:</strong>
            <?php
            $full_address = trim(htmlspecialchars($lead['address'] ?? '') . ', ' . htmlspecialchars($lead['city'] ?? '') . ', ' . htmlspecialchars($lead['state'] ?? '') . ' ' . htmlspecialchars($lead['zip_code'] ?? ''), ', ');
            echo !empty(str_replace(',', '', $full_address)) ? $full_address : 'N/A';
            ?>
        </p>

        <p style="margin: 5px 0; font-size: 14px;"><strong>DOB:</strong> <?php echo !empty($lead['dob']) ? htmlspecialchars($lead['dob']) : 'N/A'; ?></p>

        <p style="margin: 5px 0; font-size: 14px;"><strong>Status:</strong>
            <span class="status-badge" style="background: <?php echo (strpos($lead['status'], 'Locked') !== false) ? '#f8d7da; color: #721c24;' : '#e8f0fe; color: var(--electric-blue);'; ?>; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase;">
                <?php echo htmlspecialchars($lead['status'] ?? 'NEW'); ?>
            </span>
        </p>
        <p style="margin: 5px 0; font-size: 14px;"><strong>Verification Count:</strong> <?php echo (int)($lead['verification_count'] ?? 0); ?></p>

        <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

        <p style="margin: 5px 0; font-size: 14px;"><strong>Agent Name:</strong> <?php echo !empty($lead['agent_name']) ? htmlspecialchars($lead['agent_name']) : 'Self'; ?></p>
        <p style="margin: 5px 0; font-size: 14px;">
            <strong>Reference Name:</strong>
            <?php echo !empty($lead['referred_by']) ? htmlspecialchars($lead['referred_by']) : '<span style="color:#94a3b8;">None</span>'; ?>
        </p>

        <?php
        // --- ADDITIONAL RECORDS LOGIC (Proofs / Receipts) ---
        $additional_records = $lead['additional_records'] ?? '';
        if (!empty($additional_records)) {
            echo '<div style="margin-top: 25px; padding: 20px; background: #e0f2fe; border: 2px dashed #38bdf8; border-radius: 12px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">';
            echo '<h4 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.05em;"><i class="fas fa-folder-open" style="margin-right: 8px;"></i> Additional Proofs / Records</h4>';
            echo '<div style="display: flex; gap: 15px; flex-wrap: wrap;">';
            $records = explode(',', $additional_records);
            foreach ($records as $rec) {
                if (!empty($rec) && file_exists('../../' . ltrim($rec, '/'))) {
                    $ext = strtolower(pathinfo($rec, PATHINFO_EXTENSION));
                    $web_path = '../../' . ltrim($rec, '/');
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        echo '<a href="' . htmlspecialchars($web_path) . '" target="_blank"><img src="' . htmlspecialchars($web_path) . '" style="height: 120px; width: auto; border: 2px solid #bae6fd; border-radius: 6px; object-fit: cover; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.1); cursor: pointer;" title="Click to view full size"></a>';
                    } else {
                        echo '<a href="' . htmlspecialchars($web_path) . '" target="_blank" style="background:#0284c7; padding:12px 18px; border-radius:6px; font-size:13px; font-weight:bold; color:white; text-decoration:none; display:inline-flex; align-items:center; gap:8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: background 0.2s;"><i class="fas fa-file-alt"></i> View ' . strtoupper($ext) . ' File</a>';
                    }
                }
            }
            echo '</div></div>';
        }
        ?>
    </div>

    <!-- RIGHT COLUMN: Portal Access & Updates -->
    <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column; gap: 20px;">

        <div class="card" style="margin: 0;">
            <h3 style="margin-top:0;">Portal Access & ID Upload</h3>

            <!-- VERIFIER'S UPLOAD LINK -->
            <?php if (!empty($lead['id_upload_token'])): ?>
                <div style="background: #e8f0fe; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="margin:0; font-size: 13px; font-weight:bold; color: #0A2540;">Secure ID Upload Link (Generated by Verifier):</p>
                    <input type="text" readonly value="https://consumerhealthcarecompliance.com/uploads?token=<?php echo $lead['id_upload_token']; ?>" style="width:100%; padding:10px; margin-top:8px; border-radius:6px; border:1px solid #ccc; font-size:12px; font-family:monospace;" onclick="this.select();">
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; color: #0f172a !important; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffeeba;">
                    <p style="margin:0; font-size: 13px; font-weight:bold; color: #0f172a !important;">Secure ID Upload Link:</p>
                    <p style="margin: 5px 0 0 0; font-size: 13px; color: #0f172a !important;">Not generated yet. The verifier will generate this link after the call.</p>
                </div>
            <?php endif; ?>

            <hr style="border:0; border-top:1px dashed #ccc; margin: 20px 0;">

            <!-- GENERATE ID AND PORTAL LOGIN LINK -->
            <form method="POST">
                <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
                <button type="submit" name="generate_customer_id" class="btn" style="background: #0A2540; width: 100%;">
                    <i class="fas fa-id-card"></i> Generate Customer ID & Send Login Link
                </button>
            </form>

            <?php if (!empty($lead['customer_id'])): ?>
                <p style="text-align: center; font-size: 13px; margin-top: 15px; color: #28a745; font-weight: bold;">Current Customer ID: <?php echo htmlspecialchars($lead['customer_id']); ?></p>
            <?php endif; ?>
        </div>

        <!-- UPDATES & FEEDBACK (Unchanged) -->
        <div class="card" style="margin: 0;">
            <h3 style="margin-top:0;">Updates & Feedback</h3>
            <div style="height: 200px; overflow-y: auto; padding-right: 10px; margin-bottom: 15px; background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 15px;">
                <?php
                $msg_query = mysqli_query($conn, "SELECT m.content, m.created_at, u.username, u.role 
                    FROM messages m JOIN users u ON m.sender_id = u.id 
                    WHERE m.lead_id = '$lead_id' ORDER BY m.created_at ASC");

                if (mysqli_num_rows($msg_query) > 0) {
                    while ($msg = mysqli_fetch_assoc($msg_query)) {
                        $bg = '#e9ecef';
                        if ($msg['role'] == 'Closer') $bg = '#d4edda';
                        if ($msg['role'] == 'Verifier') $bg = '#fff3cd';
                        echo "<div style='background: {$bg}; padding: 10px 15px; border-radius: 8px; margin-bottom: 10px; border-left: 4px solid rgba(0,0,0,0.1);'>
                                <strong style='color: var(--primary-navy);'>{$msg['username']} ({$msg['role']})</strong>
                                <span style='font-size: 11px; color: #666; float: right;'>" . date('M d, H:i', strtotime($msg['created_at'])) . "</span>
                                <p style='margin: 8px 0 0 0; font-size: 14px;'>" . nl2br(htmlspecialchars($msg['content'])) . "</p>
                              </div>";
                    }
                } else {
                    echo "<p style='color: #888; font-style: italic; text-align:center;'>No updates yet.</p>";
                }
                ?>
            </div>
            <form action="../shared/send_msg.php" method="POST">
                <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
                <textarea name="message" placeholder="Write an update for the Agent or Verifier..." required style="margin-bottom: 10px; width: 100%; height: 60px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
                <button type="submit" class="btn" style="width: 100%;">Post Update</button>
            </form>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>