<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/pusher.php';
protect('Closer');

if (isset($_POST['ajax_reference_search'])) {
    header('Content-Type: application/json');
    $search = trim($_POST['ajax_reference_search']);
    $q = $conn->query("SELECT customer_name FROM leads LIMIT 1500");
    $results = [];
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $decrypted = decryptData($row['customer_name']);
            if (stripos($decrypted, $search) !== false) {
                $results[] = $decrypted;
            }
        }
    }
    echo json_encode(array_values(array_unique(array_filter($results))));
    exit;
}

$closer_id = $_SESSION['user_id'];

// Fetch the logged-in Closer's name for the read-only input
$closer_q = $conn->prepare("SELECT username FROM users WHERE id = ?");
$closer_q->bind_param("i", $closer_id);
$closer_q->execute();
$closer_res = $closer_q->get_result()->fetch_assoc();
$closer_name_display = $closer_res['username'] ?? 'Active Closer';
$closer_q->close();

// ==========================================
// 1. AJAX LISTENER FOR PHONE DUPLICATE CHECK
// ==========================================
if (isset($_POST['ajax_check_phone'])) {
    header('Content-Type: application/json');
    $raw_phone = trim($_POST['ajax_check_phone']);

    $clean_phone = preg_replace('/[^\d+]/', '', $raw_phone);
    if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) {
        $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
    }
    $phone_hash = createBlindHash($clean_phone);

    $stmt = $conn->prepare("SELECT id, first_name, last_name, vertical FROM leads WHERE phone_hash = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $phone_hash);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            'exists' => true,
            'id' => $row['id'],
            'first_name' => decryptData($row['first_name']),
            'last_name' => decryptData($row['last_name']),
            'vertical' => $row['vertical'] ?? 'Referral'
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// ==========================================
// 2. FORM SUBMISSION (SMART INSERT OR UPDATE)
// ==========================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_check_phone'])) {
    $action_type = $_POST['action_type'] ?? 'insert';
    $existing_lead_id = (int)($_POST['existing_lead_id'] ?? 0);

    // Grab form inputs
    $raw_first       = trim($_POST['first_name'] ?? '');
    $raw_last        = trim($_POST['last_name'] ?? '');
    $raw_phone       = trim($_POST['phone'] ?? '');
    $raw_email       = trim($_POST['email'] ?? '');
    $vertical        = trim($_POST['vertical'] ?? '');
    $raw_referred_by = trim($_POST['referred_by'] ?? '');
    $raw_alt_details = trim($_POST['alternate_details'] ?? '');

    $raw_dob = trim($_POST['dob'] ?? '');
    $formatted_dob = '';
    if (!empty($raw_dob)) {
        $time = strtotime($raw_dob);
        if ($time !== false) {
            $formatted_dob = date('m/d/Y', $time);
        } else {
            $formatted_dob = $raw_dob; // Fallback
        }
    }

    // Format phone for database logic
    $clean_phone = preg_replace('/[^\d+]/', '', $raw_phone);
    if (!empty($clean_phone) && strpos($clean_phone, '+1') !== 0) {
        $clean_phone = '+1' . ltrim($clean_phone, ' 1+');
    }
    $phone_hash = createBlindHash($clean_phone);

    if ($action_type === 'update' && $existing_lead_id > 0) {
        // FETCH EXISTING LEAD FOR BACKGROUND DATA MERGING
        $stmt_exist = $conn->prepare("SELECT first_name, last_name, phone, email, address, city, state, zip_code, dob, vertical, referred_by, alternate_details FROM leads WHERE id = ?");
        $stmt_exist->bind_param("i", $existing_lead_id);
        $stmt_exist->execute();
        $existing = $stmt_exist->get_result()->fetch_assoc();
        $stmt_exist->close();

        // SMART MERGE - Keep old data if new input is blank
        $final_first       = !empty($raw_first)       ? $raw_first       : decryptData($existing['first_name']);
        $final_last        = !empty($raw_last)        ? $raw_last        : decryptData($existing['last_name']);
        $final_email       = !empty($raw_email)       ? $raw_email       : decryptData($existing['email']);
        $final_phone       = !empty($clean_phone)     ? $clean_phone     : decryptData($existing['phone']);

        $final_address     = !empty($_POST['address'])  ? trim($_POST['address'])  : decryptData($existing['address']);
        $final_city        = !empty($_POST['city'])     ? trim($_POST['city'])     : decryptData($existing['city']);
        $final_state       = !empty($_POST['state'])    ? trim($_POST['state'])    : decryptData($existing['state']);
        $final_zip         = !empty($_POST['zip_code']) ? trim($_POST['zip_code']) : decryptData($existing['zip_code']);

        $final_alt         = !empty($raw_alt_details)   ? $raw_alt_details         : decryptData($existing['alternate_details']);
        $final_dob         = !empty($formatted_dob)     ? $formatted_dob           : decryptData($existing['dob']);

        $final_vertical    = !empty($vertical)        ? $vertical        : $existing['vertical'];
        $final_referred_by = !empty($raw_referred_by) ? $raw_referred_by : $existing['referred_by'];

        $final_customer_name = trim($final_first . ' ' . $final_last);
        $final_phone_hash  = !empty($clean_phone) ? $phone_hash : $existing['phone_hash'];

        // Re-encrypt values
        $enc_customer = encryptData($final_customer_name);
        $enc_first    = encryptData($final_first);
        $enc_last     = encryptData($final_last);
        $enc_email    = encryptData($final_email);
        $enc_phone    = encryptData($final_phone);
        $enc_address  = encryptData($final_address);
        $enc_city     = encryptData($final_city);
        $enc_state    = encryptData($final_state);
        $enc_zip      = encryptData($final_zip);
        $enc_dob      = encryptData($final_dob);
        $enc_alt      = encryptData($final_alt);

        $update_stmt = $conn->prepare("UPDATE leads SET 
                customer_name = ?, first_name = ?, last_name = ?, phone = ?, phone_hash = ?, email = ?, address = ?, city = ?, state = ?, zip_code = ?, dob = ?, vertical = ?, referred_by = ?, alternate_details = ?, closer_id = ? 
                WHERE id = ?");

        if (!$update_stmt) {
            die("Database Error: " . $conn->error);
        }

        $update_stmt->bind_param(
            "ssssssssssssssii",
            $enc_customer,
            $enc_first,
            $enc_last,
            $enc_phone,
            $final_phone_hash,
            $enc_email,
            $enc_address,
            $enc_city,
            $enc_state,
            $enc_zip,
            $enc_dob,
            $final_vertical,
            $final_referred_by,
            $enc_alt,
            $closer_id,
            $existing_lead_id
        );

        if ($update_stmt->execute()) {
            // SYNC TO LIVE SERVER
            $sync_payload = json_encode([
                'id'                => $existing_lead_id,
                'customer_name'     => $final_customer_name,
                'first_name'        => $final_first,
                'last_name'         => $final_last,
                'phone'             => $final_phone,
                'phone_hash'        => $final_phone_hash, // CRITICAL FIX: Added Phone Hash
                'email'             => $final_email,
                'address'           => $final_address,
                'city'              => $final_city,
                'state'             => $final_state,
                'zip_code'          => $final_zip,
                'dob'               => $final_dob,
                'vertical'          => $final_vertical,
                'referred_by'       => $final_referred_by,
                'alternate_details' => $final_alt,
                'closer_id'         => $closer_id,
                'agent_id'          => 0,
                'status'            => 'Pending Verification',
                'auth_key'          => 'MakeMoney$$'
            ]);

            $ch = curl_init('https://consumerhealthcarecompliance.com/api/sync_new_lead.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sync_payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL FIX: Bypass Local SSL Block
            curl_exec($ch);
            curl_close($ch);

            $message = "<div style='color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 6px; margin-bottom: 20px;'>Existing lead successfully patched and synced to Live Server!</div>";

            $pusher = workspace_pusher_server();
            if ($pusher) {
                $pusher->trigger('global-updates', 'lead-updated', ['message' => 'Lead updated']);
            }
        } else {
            $message = "<div style='color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 6px; margin-bottom: 20px;'>Error processing updates.</div>";
        }
    } else {
        // STANDARD FRESH INSERT
        $raw_customer_name = trim($raw_first . ' ' . $raw_last);

        $enc_customer = encryptData($raw_customer_name);
        $enc_first    = encryptData($raw_first);
        $enc_last     = encryptData($raw_last);
        $enc_email    = encryptData($raw_email);
        $enc_phone    = encryptData($clean_phone);
        $enc_address  = encryptData(trim($_POST['address'] ?? ''));
        $enc_city     = encryptData(trim($_POST['city'] ?? ''));
        $enc_state    = encryptData(trim($_POST['state'] ?? ''));
        $enc_zip      = encryptData(trim($_POST['zip_code'] ?? ''));
        $enc_alt      = encryptData($raw_alt_details);
        $enc_dob      = encryptData($formatted_dob);

        // Corrected SQL: Exactly 15 parameters (?) matching the 15 bound variables
        $insert_stmt = $conn->prepare("INSERT INTO leads (
                customer_name, first_name, last_name, phone, phone_hash, email, address, city, state, zip_code, dob, vertical, referred_by, alternate_details, closer_id, status, id_upload_token, c2c_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', NULL, NULL, NOW())");

        if (!$insert_stmt) {
            die("Database Error: " . $conn->error);
        }

        $insert_stmt->bind_param(
            "ssssssssssssssi",
            $enc_customer,
            $enc_first,
            $enc_last,
            $enc_phone,
            $phone_hash,
            $enc_email,
            $enc_address,
            $enc_city,
            $enc_state,
            $enc_zip,
            $enc_dob,
            $vertical,
            $raw_referred_by,
            $enc_alt,
            $closer_id
        );

        if ($insert_stmt->execute()) {
            $new_lead_id = $insert_stmt->insert_id;

            $sync_payload = json_encode([
                'id'                => $new_lead_id,
                'customer_name'     => $raw_customer_name,
                'first_name'        => $raw_first,
                'last_name'         => $raw_last,
                'phone'             => $clean_phone,
                'phone_hash'        => $phone_hash, // CRITICAL FIX: Added Phone Hash
                'email'             => $raw_email,
                'address'           => trim($_POST['address'] ?? ''),
                'city'              => trim($_POST['city'] ?? ''),
                'state'             => trim($_POST['state'] ?? ''),
                'zip_code'          => trim($_POST['zip_code'] ?? ''),
                'dob'               => $formatted_dob,
                'vertical'          => $vertical,
                'referred_by'       => $raw_referred_by,
                'alternate_details' => $raw_alt_details,
                'closer_id'         => $closer_id,
                'agent_id'          => 0,
                'status'            => 'New',
                'auth_key'          => 'MakeMoney$$'
            ]);

            $ch = curl_init('https://consumerhealthcarecompliance.com/api/sync_new_lead.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sync_payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // CRITICAL FIX: Bypass Local SSL Block
            curl_exec($ch);
            curl_close($ch);

            $message = "<div style='color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 6px; margin-bottom: 20px;'>New lead successfully recorded and synced to Live Server.</div>";

            $pusher = workspace_pusher_server();
            if ($pusher) {
                $pusher->trigger('global-updates', 'lead-updated', ['message' => 'New lead added']);
            }
        } else {
            $message = "<div style='color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 12px; border-radius: 6px; margin-bottom: 20px;'>Error adding lead.</div>";
        }
    }
}
include 'includes/header.php';
?>

<style>
    /* Modern Layout Variables */
    :root {
        --primary-navy: #142030;
        --electric-blue: #3563A9;
        --brand-blue-hover: #264A82;
        --bg-light: #F4F7FA;
        --text-dark: #1E293B;
        --text-muted: #64748B;
        --white: #FFFFFF;
        --border-color: #E2E8F0;
        --input-bg: #F8FAFC;
    }

    .modern-card {
        background-color: var(--white);
        padding: 35px 40px;
        border-radius: 16px;
        margin-bottom: 25px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-color);
    }

    .form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 15px;
        margin-bottom: 30px;
    }

    .form-header h3 {
        font-size: 26px;
        color: var(--primary-navy);
        margin: 0;
        font-weight: 800;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 24px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .modern-label {
        font-weight: 600;
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 8px;
        display: block;
        text-transform: uppercase;
    }

    .modern-input {
        width: 100%;
        padding: 14px 16px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 15px;
        background-color: var(--input-bg);
        box-sizing: border-box;
        transition: border-color 0.2s ease;
        font-family: inherit;
    }

    .modern-input:focus {
        outline: none;
        border-color: var(--electric-blue);
    }

    .modern-input[readonly] {
        background-color: #e2e8f0;
        color: #64748b;
        cursor: not-allowed;
    }

    .btn-submit {
        background-color: var(--primary-navy);
        color: var(--white);
        border: none;
        padding: 16px;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 700;
        width: 100%;
        margin-top: 10px;
        transition: 0.2s ease;
        font-size: 15px;
    }

    .btn-submit:hover:not(:disabled) {
        box-shadow: 0 4px 12px rgba(10, 37, 64, 0.3);
    }

    /* Autocomplete Styles */
    .autocomplete-items {
        position: absolute;
        border: 1px solid #d4d4d4;
        border-bottom: none;
        border-top: none;
        z-index: 99;
        top: 100%;
        left: 0;
        right: 0;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        max-height: 200px;
        overflow-y: auto;
    }

    .autocomplete-items div {
        padding: 10px;
        cursor: pointer;
        background-color: #1E293B;
        color: #F8FAFC;
        border-bottom: 1px solid #334155;
        font-size: 14px;
        text-align: left;
    }

    .autocomplete-items div:hover {
        background-color: #3563A9;
        color: #FFFFFF;
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
        background: #1E293B;
        border: 1px solid #334155;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 400px;
        text-align: center;
    }
</style>

<div style="max-width: 900px; margin: 20px auto;">
    <div class="modern-card">
        <div class="form-header">
            <h3>Register Lead / Referral</h3>
            <a href="reference_sheet.php" class="btn-submit" style="width: auto; margin: 0; text-decoration: none;">Open Reference Sheet</a>
        </div>

        <?= $message; ?>

        <form id="addLeadForm" method="POST" action="">
            <input type="hidden" name="action_type" id="action_type" value="insert">
            <input type="hidden" name="existing_lead_id" id="existing_lead_id" value="">

            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">Assigned Closer</label>
                    <input type="text" value="<?= htmlspecialchars($closer_name_display); ?>" class="modern-input" readonly tabindex="-1">
                </div>
                <div class="form-group">
                    <label class="modern-label">Referred By (Name)</label>
                    <input type="text" name="referred_by" id="referred_by" class="modern-input" placeholder="e.g. John Smith" autocomplete="off">
                    <div id="ref-autocomplete-list" class="autocomplete-items"></div>
                </div>
            </div>

            <!-- Basics -->
            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">First Name</label>
                    <input type="text" name="first_name" id="first_name" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">Last Name</label>
                    <input type="text" name="last_name" id="last_name" class="modern-input">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">Phone Number <span>*</span></label>
                    <!-- STRICT HTML VALIDATION -->
                    <input type="text" id="phoneMaskInput" name="phone" class="modern-input" placeholder="+1xxxxxxxxxx" value="+1" maxlength="12" minlength="12" pattern="^\+1\d{10}$" required>
                </div>
                <div class="form-group">
                    <label class="modern-label">Email Address</label>
                    <input type="email" id="email" name="email" class="modern-input" placeholder="customer@example.com">
                    <span id="email-validation-msg" style="font-size: 11px; font-weight: bold; margin-top: 5px; display: none;"></span>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">Date Of Birth</label>
                    <input type="text" id="dobMaskInput" name="dob" class="modern-input" placeholder="MM/DD/YYYY" maxlength="10">
                </div>
                <div class="form-group">
                    <label class="modern-label">Vertical</label>
                    <select name="vertical" class="modern-input">
                        <option value="">-- Select Vertical --</option>
                        <option value="3M Combat Earplug">3M Combat Earplug</option>
                        <option value="AFFF">Aqueous Film Forming Foam</option>
                        <option value="ASD">Autism Spectrum Disorder</option>
                        <option value="NEC">Baby Formulae</option>
                        <option value="BARD Powerport">BARD Powerport</option>
                        <option value="Camp Lejeune">Camp Lejeune</option>
                        <option value="Cartiva">Cartiva</option>
                        <option value="Catheter">Catheter</option>
                        <option value="Chlorpyrifos">Chlorpyrifos</option>
                        <option value="Depo Provera">Depo Provera</option>
                        <option value="Dupixent">Dupixent</option>
                        <option value="Elmiron">Elmiron</option>
                        <option value="Hair Relaxer">Hair Relaxer</option>
                        <option value="Hernia Mesh">Hernia Mesh</option>
                        <option value="Hurricane">Hurricane</option>
                        <option value="CA Sexual Abuse">California Sexual Abuse</option>
                        <option value="CA Womens Prison">California Women's Prison</option>
                        <option value="IL Sexual Abuse">Illinois Sexual Abuse</option>
                        <option value="LDS Sexual Abuse">LDS Sexual Abuse</option>
                        <option value="Lung Cancer">Lung Cancer</option>
                        <option value="MVA">Motor Vehicle Accident</option>
                        <option value="Olympus">Olympus</option>
                        <option value="Ozempic">Ozempic</option>
                        <option value="Paraquat">Paraquat</option>
                        <option value="PFAS">PFAS</option>
                        <option value="Ride Share">Ride Share</option>
                        <option value="Roblox">Roblox</option>
                        <option value="RI-JDC">Rhode Island - Juvenile Detention Center</option>
                        <option value="Round Up">Round Up</option>
                        <option value="Snapchat">Snapchat Abuse</option>
                        <option value="SMA">Social Media Addiction</option>
                        <option value="SSDI">Social Security Disability Insurance</option>
                        <option value="Talcum">Talcum</option>
                        <option value="Toxic Baby Food">Toxic Baby Food</option>
                        <option value="VGA">Video Game Addiction</option>
                        <option value="Zantac">Zantac</option>
                    </select>
                </div>
            </div>

            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

            <!-- ADDRESS SECTION -->
            <h4 style="color: var(--primary-navy); margin-bottom: 20px;">Address Information</h4>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="modern-label">Address As Per ID</label>
                <input type="text" id="id_address_search" name="address" class="modern-input" placeholder="Start typing address..." autocomplete="off">
                <div id="autocomplete-list" class="autocomplete-items"></div>
            </div>

            <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr;">
                <div class="form-group">
                    <label class="modern-label">City</label>
                    <input type="text" id="id_city" name="city" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">State</label>
                    <input type="text" id="id_state" name="state" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">Zip Code</label>
                    <input type="text" id="id_zip" name="zip_code" class="modern-input">
                </div>
            </div>

            <!-- Current Address (Additional Details) -->
            <div style="background: #f8fafc; padding: 20px; border-radius: 10px; margin-top: 20px; border: 1px solid #e2e8f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <label class="modern-label" style="margin:0;">Current Address (If Different)</label>
                    <label style="font-size: 13px; font-weight: bold; cursor: pointer; color: var(--primary-navy);">
                        <input type="checkbox" id="same_address_check" style="margin-right: 5px;"> Same as ID Address
                    </label>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <input type="text" id="current_address" class="modern-input" placeholder="Start typing current address..." autocomplete="off">
                    <div id="autocomplete-list-current" class="autocomplete-items"></div>
                </div>
                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-bottom: 0;">
                    <div class="form-group">
                        <input type="text" id="current_city" class="modern-input" placeholder="City">
                    </div>
                    <div class="form-group">
                        <input type="text" id="current_state" class="modern-input" placeholder="State">
                    </div>
                    <div class="form-group">
                        <input type="text" id="current_zip" class="modern-input" placeholder="Zip">
                    </div>
                </div>
                <!-- Hidden field to compile full current address for DB -->
                <input type="hidden" id="final_alternate_details" name="alternate_details">
            </div>

            <!-- Hidden field needed for the email API payload -->
            <input type="hidden" id="agent_name_input" name="agent_name" value="<?php echo htmlspecialchars($closer_name_display); ?>">

            <!-- Button Group -->
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 25px;">
                <button type="submit" class="btn-submit" onclick="compileCurrentAddress()">Verify & Save Profile</button>
                <button type="button" id="send_email_btn" class="btn-submit" style="background-color: #94a3b8; cursor: not-allowed;" disabled>
                    <i class="fas fa-envelope"></i> Send Company Info
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DUPLICATE MODAL -->
<div id="duplicateModal" class="modal-overlay">
    <div class="modal-content">
        <div style="width: 60px; height: 60px; background: #fef3c7; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
            <i class="fas fa-exclamation-triangle fa-2x"></i>
        </div>
        <h2 style="margin-top:0; font-size: 20px; color: #F8FAFC;">Duplicate Records Detected</h2>
        <p style="color: #94A3B8; font-size: 14px; margin-bottom: 20px;">
            A customer profile using this contact number is already active inside your database.<br>
            <strong id="dupName" style="color: #38BDF8; display: block; margin-top: 8px;"></strong>
            <span id="dupVertical" style="font-size: 12px; color: #64748b;"></span>
        </p>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <button type="button" id="btnUpdateExisting" class="btn-submit" style="background: #10b981; margin-top: 0;">
                Merge / Update Filled Fields
            </button>
            <button type="button" id="btnAddAnyway" class="btn-submit" style="background: #334155; color: #F8FAFC; margin-top: 0;">
                Create Independent Duplicate
            </button>
            <button type="button" onclick="closeDupModal()" style="background: none; border: none; color: #94A3B8; font-size: 13px; cursor: pointer; margin-top: 5px;">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- EMAIL TYPE SELECTION MODAL -->
<div id="emailTypeModal" class="modal-overlay">
    <div class="modal-content" style="background: #1E293B; border: 1px solid #334155; max-width: 420px;">
        <div style="width: 56px; height: 56px; background: rgba(56, 189, 248, 0.15); color: #38BDF8; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
            <i class="fas fa-envelope-open-text fa-lg"></i>
        </div>
        <h2 style="margin-top: 0; font-size: 20px; color: #F8FAFC;">Select Email Template</h2>
        <p style="color: #94A3B8; font-size: 14px; margin-bottom: 22px;">
            Which data pipeline are you dialing for this customer?
        </p>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <button type="button" onclick="executeSendCompanyEmail('raw')" class="btn-submit" style="background: #2563EB; color: #FFFFFF; margin-top: 0; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-bolt"></i> Raw Data (Fresh Lead Email)
            </button>
            <button type="button" onclick="executeSendCompanyEmail('timedata')" class="btn-submit" style="background: #10B981; color: #FFFFFF; margin-top: 0; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-history"></i> Time Data (Existing Customer Email)
            </button>
            <button type="button" onclick="closeEmailTypeModal()" style="background: none; border: none; color: #94A3B8; font-size: 13px; cursor: pointer; margin-top: 6px;">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
    // ==========================================
    // STANDARD FORM LOGIC
    // ==========================================

    // Auto-Capitalize Names
    ['first_name', 'last_name'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function() {
                if (this.value.length > 0) {
                    this.value = this.value.charAt(0).toUpperCase() + this.value.slice(1);
                }
            });
        }
    });

    // Reference Autocomplete
    const refInput = document.getElementById('referred_by');
    const refList = document.getElementById('ref-autocomplete-list');
    let refTimer;
    if (refInput && refList) {
        refInput.addEventListener('input', function() {
            clearTimeout(refTimer);
            refList.innerHTML = '';
            if (this.value.length < 2) return;
            refTimer = setTimeout(() => {
                fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'ajax_reference_search=' + encodeURIComponent(this.value)
                    })
                    .then(response => response.json())
                    .then(data => {
                        refList.innerHTML = '';
                        data.slice(0, 8).forEach(name => {
                            const item = document.createElement('div');
                            item.textContent = name;
                            item.addEventListener('click', function() {
                                refInput.value = name;
                                refList.innerHTML = '';
                            });
                            refList.appendChild(item);
                        });
                    });
            }, 400);
        });
        document.addEventListener('click', e => {
            if (e.target !== refInput) refList.innerHTML = '';
        });
    }

    // --- STRICT PHONE NUMBER MASKING ---
    const phoneInput = document.getElementById('phoneMaskInput');
    if (phoneInput) {
        // Ensure it starts with +1 on focus if empty
        phoneInput.addEventListener('focus', function() {
            if (this.value.trim() === '') this.value = '+1';
        });

        phoneInput.addEventListener('input', function(e) {
            // Remove ALL non-numeric characters
            let digits = this.value.replace(/\D/g, '');

            // If the user tries to type something that strips the 1, ensure the 1 is there
            if (digits.startsWith('1')) {
                digits = digits.substring(1);
            }

            // Restrict to exactly 10 digits after the 1
            digits = digits.substring(0, 10);

            // Force the value to always be +1 followed by the numbers
            this.value = '+1' + digits;

            validateEmailPrerequisites(); // Check if email btn should unlock
        });

        phoneInput.addEventListener('keydown', function(e) {
            // Prevent deleting the '+1' via Backspace or Delete keys
            if ((e.key === 'Backspace' || e.key === 'Delete') && this.value.length <= 2) {
                e.preventDefault();
            }
        });
    }

    // --- STRICT DOB MASKING (MM/DD/YYYY) ---
    const dobInput = document.getElementById('dobMaskInput');
    if (dobInput) {
        dobInput.addEventListener('input', function(e) {
            let val = e.target.value.replace(/\D/g, '');
            if (val.length > 8) val = val.substring(0, 8);
            let formatted = '';

            if (val.length > 0) {
                let month = val.substring(0, 2);
                if (month.length === 2) {
                    if (parseInt(month) > 12) month = '12';
                    if (parseInt(month) === 0) month = '01';
                }
                formatted = month;
            }

            if (val.length >= 2) {
                let day = val.substring(2, 4);
                if (day.length === 2) {
                    if (parseInt(day) > 31) day = '31';
                    if (parseInt(day) === 0) day = '01';
                }
                formatted += '/' + day;
            }

            if (val.length >= 4) {
                let year = val.substring(4, 8);
                formatted += '/' + year;
            }

            e.target.value = formatted;
        });
    }

    // --- FORM VALIDATION: UNLOCK EMAIL BUTTON ---
    const fNameInput = document.getElementById('first_name');
    const lNameInput = document.getElementById('last_name');
    const emailInput = document.getElementById('email');
    const sendEmailBtn = document.getElementById('send_email_btn');
    const emailValidationMsg = document.getElementById('email-validation-msg');
    let verifiedEmail = '';
    let emailValidationRequest = 0;

    function validateEmailPrerequisites() {
        // Phone must be exactly 12 chars (+1 followed by 10 digits)
        const phoneValid = phoneInput.value.length === 12;

        // First name is now optional; requires phone and valid email
        const isReady = emailInput.value.trim() !== '' &&
            phoneValid &&
            emailInput.checkValidity() &&
            verifiedEmail === emailInput.value.trim();

        if (isReady) {
            sendEmailBtn.disabled = false;
            sendEmailBtn.style.backgroundColor = '#10b981';
            sendEmailBtn.style.cursor = 'pointer';
        } else {
            sendEmailBtn.disabled = true;
            sendEmailBtn.style.backgroundColor = '#94a3b8';
            sendEmailBtn.style.cursor = 'not-allowed';
        }
    }

    // Attach listeners to trigger validation check on input
    fNameInput.addEventListener('input', validateEmailPrerequisites);
    lNameInput.addEventListener('input', validateEmailPrerequisites);
    emailInput.addEventListener('input', function() {
        emailValidationRequest++;
        verifiedEmail = '';
        emailValidationMsg.style.display = 'none';
        validateEmailPrerequisites();
    });

    emailInput.addEventListener('blur', function() {
        const requestId = ++emailValidationRequest;
        const email = this.value.trim();
        verifiedEmail = '';

        if (!email || !this.checkValidity()) {
            emailValidationMsg.style.display = 'none';
            validateEmailPrerequisites();
            return;
        }

        emailValidationMsg.style.display = 'block';
        emailValidationMsg.style.color = '#f59e0b';
        emailValidationMsg.textContent = 'Verifying inbox...';
        validateEmailPrerequisites();

        const formData = new URLSearchParams();
        formData.append('email', email);

        fetch('../shared/email_validator.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (requestId !== emailValidationRequest || email !== emailInput.value.trim()) return;
                verifiedEmail = data.valid ? email : '';
                emailValidationMsg.style.color = data.valid ? '#10b981' : '#ef4444';
                const icon = document.createElement('i');
                icon.className = data.valid ? 'fas fa-check-circle' : 'fas fa-times-circle';
                emailValidationMsg.replaceChildren(icon, document.createTextNode(' ' + data.message));
                validateEmailPrerequisites();
            })
            .catch(() => {
                if (requestId !== emailValidationRequest) return;
                verifiedEmail = '';
                emailValidationMsg.style.display = 'none';
                validateEmailPrerequisites();
            });
    });

    const emailTypeModal = document.getElementById('emailTypeModal');

    function closeEmailTypeModal() {
        emailTypeModal.classList.remove('show');
    }

    function executeSendCompanyEmail(emailType) {
        if (emailType !== 'raw' && emailType !== 'timedata') return;
        closeEmailTypeModal();

        const originalText = sendEmailBtn.innerHTML;
        sendEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        sendEmailBtn.disabled = true;
        sendEmailBtn.style.backgroundColor = '#94a3b8';

        const agentNameInput = document.getElementById('agent_name_input').value;
        const endpoint = emailType === 'raw' ?
            '../agent/api_send_raw_data_info.php' :
            '../agent/api_send_company_info.php';
        const emailTypeLabel = emailType === 'raw' ? 'Raw Data' : 'Time Data';

        fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    first_name: fNameInput.value.trim(),
                    last_name: lNameInput.value.trim(),
                    email: emailInput.value.trim(),
                    agent_name: agentNameInput
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(emailTypeLabel + ' email sent successfully to ' + emailInput.value);
                    sendEmailBtn.innerHTML = '<i class="fas fa-check"></i> Sent Successfully';
                    sendEmailBtn.style.backgroundColor = '#10b981';

                    setTimeout(() => {
                        sendEmailBtn.innerHTML = originalText;
                        validateEmailPrerequisites();
                    }, 3000);
                } else {
                    alert('Error: ' + data.message);
                    sendEmailBtn.innerHTML = originalText;
                    validateEmailPrerequisites();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('A network error occurred while sending the email.');
                sendEmailBtn.innerHTML = originalText;
                validateEmailPrerequisites();
            });
    }

    sendEmailBtn.addEventListener('click', function() {
        if (!this.disabled) emailTypeModal.classList.add('show');
    });

    // --- REUSABLE OPENSTREETMAP AUTOCOMPLETE API ---
    function setupAddressAutocomplete(inputId, listId, cityId, stateId, zipId) {
        const inputField = document.getElementById(inputId);
        const autocompleteList = document.getElementById(listId);
        let typingTimer;

        inputField.addEventListener('input', function() {
            clearTimeout(typingTimer);
            autocompleteList.innerHTML = '';
            const val = this.value;

            if (!val || val.length < 3) return;

            typingTimer = setTimeout(() => {
                fetch(`https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&countrycodes=us&q=${encodeURIComponent(val)}`)
                    .then(response => response.json())
                    .then(data => {
                        autocompleteList.innerHTML = '';
                        data.forEach(place => {
                            const item = document.createElement('div');
                            item.innerHTML = place.display_name;
                            item.addEventListener('click', function() {
                                const addr = place.address;
                                const street = (addr.house_number ? addr.house_number + ' ' : '') + (addr.road || '');

                                inputField.value = street || place.name || '';
                                document.getElementById(cityId).value = addr.city || addr.town || addr.village || '';
                                document.getElementById(stateId).value = addr.state || '';
                                document.getElementById(zipId).value = addr.postcode || '';

                                autocompleteList.innerHTML = '';
                            });
                            autocompleteList.appendChild(item);
                        });
                    });
            }, 500);
        });

        document.addEventListener('click', function(e) {
            if (e.target !== inputField) autocompleteList.innerHTML = '';
        });
    }

    // Initialize Autocomplete for BOTH Address Blocks
    setupAddressAutocomplete('id_address_search', 'autocomplete-list', 'id_city', 'id_state', 'id_zip');
    setupAddressAutocomplete('current_address', 'autocomplete-list-current', 'current_city', 'current_state', 'current_zip');

    // --- ADDRESS SYNC CHECKBOX ---
    document.getElementById('same_address_check').addEventListener('change', function() {
        const isChecked = this.checked;
        const fields = ['current_address', 'current_city', 'current_state', 'current_zip'];
        fields.forEach(id => {
            const el = document.getElementById(id);
            el.readOnly = isChecked;
            if (isChecked) {
                el.value = '';
                el.style.backgroundColor = '#e2e8f0';
            } else {
                el.style.backgroundColor = '#F1F5F9';
            }
        });
    });

    // --- COMPILE ALTERNATE DETAILS BEFORE SUBMIT ---
    function compileCurrentAddress() {
        const isSame = document.getElementById('same_address_check').checked;
        if (isSame) {
            document.getElementById('final_alternate_details').value = '';
        } else {
            const addr = document.getElementById('current_address').value;
            const city = document.getElementById('current_city').value;
            const state = document.getElementById('current_state').value;
            const zip = document.getElementById('current_zip').value;

            if (addr || city || state || zip) {
                document.getElementById('final_alternate_details').value = `${addr}, ${city}, ${state} ${zip}`;
            }
        }
    }

    // --- FORM & MODAL LOGIC (Duplicate Catching) ---
    const form = document.getElementById('addLeadForm');
    const modal = document.getElementById('duplicateModal');
    let isChecking = false;
    let forceSubmit = false;

    function closeDupModal() {
        modal.classList.remove('show');
        isChecking = false;
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = false;
        submitButton.innerHTML = 'Verify & Save Profile';
        submitButton.style.cursor = 'pointer';
    }

    form.addEventListener('submit', function(e) {
        compileCurrentAddress(); // Ensure alternate details are mapped

        if (forceSubmit) return true;

        e.preventDefault();
        if (isChecking) return;

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = "Submitting... <i class='fas fa-spinner fa-spin'></i>";
        submitButton.style.cursor = 'not-allowed';

        isChecking = true;
        const phoneInputVal = document.querySelector('input[name="phone"]').value;

        fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'ajax_check_phone=' + encodeURIComponent(phoneInputVal)
            })
            .then(res => res.json())
            .then(data => {
                if (data.exists) {
                    document.getElementById('dupName').innerText = data.first_name + ' ' + data.last_name;
                    document.getElementById('dupVertical').innerText = 'Pipeline: ' + data.vertical;
                    document.getElementById('existing_lead_id').value = data.id;
                    modal.classList.add('show');
                } else {
                    document.getElementById('action_type').value = 'insert';
                    forceSubmit = true;
                    compileCurrentAddress(); // Ensure re-mapped immediately before final execution
                    form.submit();
                }
            })
            .catch(err => {
                console.error('Check failed:', err);
                forceSubmit = true;
                compileCurrentAddress();
                form.submit();
            });
    });

    document.getElementById('btnUpdateExisting').addEventListener('click', function() {
        document.getElementById('action_type').value = 'update';
        forceSubmit = true;
        compileCurrentAddress();
        form.submit();
    });

    document.getElementById('btnAddAnyway').addEventListener('click', function() {
        document.getElementById('action_type').value = 'insert';
        document.getElementById('existing_lead_id').value = '';
        forceSubmit = true;
        compileCurrentAddress();
        form.submit();
    });
</script>

<?php include 'includes/footer.php'; ?>