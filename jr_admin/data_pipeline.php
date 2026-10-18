<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);


// ==========================================
// EXPORT DATA TO CSV (EXCEL)
// ==========================================
if (isset($_GET['export_time_data']) && $_GET['export_time_data'] == 1 && isset($_GET['user_id'])) {
    $export_user_id = (int)$_GET['user_id'];

    $u_q = $conn->query("SELECT username FROM users WHERE id = $export_user_id");
    $export_username = ($u_q && $u_q->num_rows > 0) ? $u_q->fetch_assoc()['username'] : 'User';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_data_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $export_username) . '_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');

    // Add BOM for proper Excel UTF-8 support
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Write Required Headers
    fputcsv($output, ['Agent Name', 'Phone', 'Status1', 'Status2', 'First Name', 'Last Name', 'Email', 'Address', 'City', 'State', 'Zip Code', 'DOB', 'Vertical', 'Updates', 'Closers', 'Application', 'Referred By', 'Timestamp']);

    // Fetch all data for this specific user
    $q = $conn->query("SELECT * FROM time_data WHERE assigned_user_id = $export_user_id ORDER BY id ASC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $r['agent_name'] ?? '',
                " " . ($r['phone_number'] ?? ''), // Single space prevents scientific notation without breaking tabs
                $r['status'] ?? '',
                $r['status2'] ?? '',
                $r['first_name'] ?? '',
                $r['last_name'] ?? '',
                $r['email'] ?? '',
                $r['address'] ?? '',
                $r['city'] ?? '',
                $r['state'] ?? '',
                $r['zip_code'] ?? '',
                $r['dob'] ?? '',
                $r['vertical'] ?? '',
                $r['old_updates'] ?? '',
                $r['previous_closers'] ?? '',
                $r['application_text'] ?? '',
                $r['referred_by'] ?? '',
                $r['timestamp1'] ?? '',
                $r['updated_at'] ?? ''
            ]);
        }
    }
    fclose($output);
    exit();
}

// ==========================================
// AJAX HANDLERS (IMPORT, UPDATE, BATCH DELETE)
// ==========================================
if (isset($_POST['ajax_action'])) {

    // 1. BULK IMPORT LEADS
    if ($_POST['ajax_action'] == 'bulk_import') {
        $user_id = (int)$_POST['user_id'];
        $leads = json_decode($_POST['leads'], true);

        if (!$user_id || empty($leads)) {
            echo 'Error: Invalid Data or No User Selected';
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO time_data (assigned_user_id, agent_name, phone_number, status, status2, first_name, last_name, email, address, city, state, zip_code, dob, vertical, old_updates, previous_closers, application_text, referred_by, timestamp1) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $user_lookup_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$user_lookup_stmt) {
            $stmt->close();
            die("Database Error in User Lookup: " . $conn->error);
        }

        $inserted_count = 0;
        foreach ($leads as $lead) {
            $phone = trim($lead['phone_number'] ?? '');
            if (empty($phone)) continue;

            $target_username = '';
            foreach (['closer_name', 'previous_closers', 'agent_name'] as $username_column) {
                $candidate_username = trim($lead[$username_column] ?? '');
                if ($candidate_username !== '') {
                    $target_username = $candidate_username;
                    break;
                }
            }

            $assigned_user_id = 0;
            if ($target_username !== '') {
                $user_lookup_stmt->bind_param("s", $target_username);
                if (!$user_lookup_stmt->execute()) {
                    $lookup_error = $user_lookup_stmt->error;
                    $user_lookup_stmt->close();
                    $stmt->close();
                    error_log("CSV import user lookup failed: " . $lookup_error);
                    die("Database Error in User Lookup: " . $lookup_error);
                } else {
                    $user_result = $user_lookup_stmt->get_result();
                    if ($user_row = $user_result->fetch_assoc()) {
                        $assigned_user_id = (int)$user_row['id'];
                    }
                }
            }

            if ($assigned_user_id <= 0) {
                $assigned_user_id = $user_id;
            }

            $agent_name = trim($lead['agent_name'] ?? '');
            $status1 = trim($lead['status'] ?? 'New');
            if (empty($status1)) $status1 = 'New';
            $status2 = trim($lead['status2'] ?? '');
            $fname = trim($lead['first_name'] ?? '');
            $lname = trim($lead['last_name'] ?? '');
            $email = trim($lead['email'] ?? '');
            $address = trim($lead['address'] ?? '');
            $city = trim($lead['city'] ?? '');
            $state = trim($lead['state'] ?? '');
            $zip = trim($lead['zip_code'] ?? '');
            $dob = trim($lead['dob'] ?? '');
            $vertical = trim($lead['vertical'] ?? '');
            $old_updates = trim($lead['old_updates'] ?? '');
            $previous_closers = trim($lead['previous_closers'] ?? '');
            $application_text = trim($lead['application_text'] ?? '');
            $referred_by = trim($lead['referred_by'] ?? '');
            $timestamp1 = trim($lead['timestamp1'] ?? '');

            $stmt->bind_param("issssssssssssssssss", $assigned_user_id, $agent_name, $phone, $status1, $status2, $fname, $lname, $email, $address, $city, $state, $zip, $dob, $vertical, $old_updates, $previous_closers, $application_text, $referred_by, $timestamp1);
            if ($stmt->execute()) {
                $inserted_count++;
            }
        }
        $user_lookup_stmt->close();
        $stmt->close();
        echo "Success: $inserted_count";
        exit;
    }

    // 2. INLINE EDIT (AUTO-SAVE)
    if ($_POST['ajax_action'] == 'update_lead') {
        $lead_id = (int)$_POST['lead_id'];
        $field = $_POST['field'];
        $value = trim($_POST['value']);

        $allowed_fields = [
            'agent_name',
            'phone_number',
            'status',
            'status2',
            'first_name',
            'last_name',
            'email',
            'address',
            'city',
            'state',
            'zip_code',
            'dob',
            'vertical',
            'old_updates',
            'previous_closers',
            'application_text',
            'referred_by, timestamp1'
        ];

        if (in_array($field, $allowed_fields)) {
            $stmt = $conn->prepare("UPDATE time_data SET $field = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("si", $value, $lead_id);
            if ($stmt->execute()) echo "Success";
            else echo "Error";
            $stmt->close();
        } else {
            echo "Invalid Field";
        }
        exit;
    }

    // 3. BATCH DELETE LEADS
    if ($_POST['ajax_action'] == 'delete_batch') {
        $u_id = (int)$_POST['user_id'];
        $offset = (int)$_POST['offset'];
        $limit = 100;

        $stmt = $conn->prepare("SELECT id FROM time_data WHERE assigned_user_id = ? ORDER BY id ASC LIMIT ? OFFSET ?");
        $stmt->bind_param("iii", $u_id, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();

        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();

        if (!empty($ids)) {
            $id_string = implode(',', $ids);
            if ($conn->query("DELETE FROM time_data WHERE id IN ($id_string)")) {
                echo "Success";
            } else {
                echo "Error: Database failure";
            }
        } else {
            echo "Success";
        }
        exit;
    }

    // 4. MASS PURGE (Delete by Status instantly)
    if ($_POST['ajax_action'] == 'mass_purge') {
        $u_id = (int)$_POST['user_id'];
        $status = $_POST['purge_status'];

        if ($status === 'ALL') {
            $stmt = $conn->prepare("DELETE FROM time_data WHERE assigned_user_id = ?");
            $stmt->bind_param("i", $u_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM time_data WHERE assigned_user_id = ? AND (status = ? OR status2 = ?)");
            $stmt->bind_param("iss", $u_id, $status, $status);
        }

        if ($stmt->execute()) {
            echo "Success";
        } else {
            echo "Error";
        }
        $stmt->close();
        exit;
    }
}



// ==========================================
// FETCH ALL AVAILABLE USERS FOR DROPDOWN
// ==========================================
$users_query = "SELECT id, username, role FROM users 
                WHERE role NOT IN ('Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin') 
                ORDER BY role ASC, username ASC";
$users_result = $conn->query($users_query);

$selected_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 100;
$offset = ($page - 1) * $limit;

// ==========================================
// REPORT GENERATION & EXISTING LEADS LOGIC
// ==========================================
$total_leads = 0;
$dialed_leads = 0;
$remaining_leads = 0;
$status_breakdown = [];
$existing_leads = [];
$total_pages = 1;

if ($selected_user > 0) {
    $report_query = "SELECT status, COUNT(*) as count FROM time_data WHERE assigned_user_id = $selected_user GROUP BY status";
    $report_result = $conn->query($report_query);

    while ($row = $report_result->fetch_assoc()) {
        $total_leads += $row['count'];
        $status = trim($row['status']);

        if (empty($status) || strcasecmp($status, 'New') == 0) {
            $remaining_leads += $row['count'];
        } else {
            $dialed_leads += $row['count'];
            $status_breakdown[$status] = $row['count'];
        }
    }

    $total_pages = ceil($total_leads / $limit);

    $leads_query = "SELECT * FROM time_data WHERE assigned_user_id = $selected_user ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $leads_result = $conn->query($leads_query);

    while ($row = $leads_result->fetch_assoc()) {
        $existing_leads[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html,
        body {
            margin: 0;
            padding: 10px;
            background: #f8fafc;
        }
    </style>
</head>

<body class="app-dark-theme">

    <style>
        .dashboard-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .stat-box {
            display: inline-block;
            padding: 15px 25px;
            border-radius: 6px;
            margin-right: 15px;
            margin-bottom: 15px;
        }

        .stat-box h4 {
            margin: 0 0 5px 0;
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
        }

        .stat-box h2 {
            margin: 0;
            font-size: 24px;
            color: #0f172a;
        }

        .bg-blue {
            background: #e0f2fe;
            border-left: 4px solid #0ea5e9;
        }

        .bg-green {
            background: #dcfce3;
            border-left: 4px solid #10b981;
        }

        .bg-yellow {
            background: #fef9c3;
            border-left: 4px solid #eab308;
        }

        .paste-area {
            width: 100%;
            height: 100px;
            border: 2px dashed #94a3b8;
            border-radius: 8px;
            padding: 15px;
            font-size: 16px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
            resize: none;
            transition: all 0.2s ease;
        }

        .paste-area:focus {
            border-color: #3b82f6;
            background: #fff;
            outline: none;
            box-shadow: 0 0 0 3px #eff6ff;
        }

        .mapping-table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 500px;
            margin-top: 15px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            user-select: none;
        }

        .mapping-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            white-space: nowrap;
        }

        .mapping-table th,
        .mapping-table td {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: top;
        }

        .mapping-table th {
            background: #f1f5f9;
            position: sticky;
            top: 0;
            z-index: 2;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
        }

        .grid-cell {
            cursor: text;
        }

        .grid-cell.editing-cell {
            background-color: #fff !important;
            outline: 2px solid #2563eb !important;
            outline-offset: -2px;
            user-select: text;
        }

        .grid-cell.selected-cell {
            background-color: #dbeafe !important;
            outline: 1px solid #3b82f6;
        }

        /* Internal scroll-box for large text columns */
        .scroll-box {
            max-height: 45px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            outline: none;
            min-width: 200px;
            cursor: text;
        }

        .scroll-box::-webkit-scrollbar {
            width: 4px;
        }

        .scroll-box::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .mapping-select {
            width: 100%;
            padding: 6px;
            font-size: 13px;
            border-radius: 4px;
            border: 1px solid #94a3b8;
            background: #fff;
        }

        .btn-import {
            background: #2563eb;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .btn-import:hover {
            background: #1d4ed8;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #334155;
            padding: 10px 20px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 15px;
            margin-left: 10px;
            display: inline-block;
        }

        .pagination {
            margin-top: 15px;
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-decoration: none;
            color: #334155;
            font-size: 13px;
            background: #fff;
        }

        .page-link:hover {
            background: #f1f5f9;
        }

        .page-link.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background: #ffffff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .modal-content h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }

        .modal-btn {
            padding: 12px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            border: none;
            font-size: 14px;
            transition: 0.2s;
        }
    </style>

    <div class="dashboard-card" style="display: flex; align-items: center; justify-content: space-between;">
        <div>
            <h3 style="margin: 0 0 10px 0;">Manage Cold Call Leads</h3>
            <form method="GET" action="data_management.php" target="_top">
                <input type="hidden" name="tab" value="pipeline">
                <select name="user_id" onchange="this.form.submit()" style="padding: 8px 12px; font-size: 15px; border-radius: 5px; border: 1px solid #cbd5e1; min-width: 250px;">
                    <option value="">-- Select a User --</option>
                    <?php
                    $current_role = '';
                    while ($u = $users_result->fetch_assoc()):
                        if ($current_role != $u['role']) {
                            if ($current_role != '') echo '</optgroup>';
                            $current_role = $u['role'];
                            echo '<optgroup label="' . htmlspecialchars($current_role) . 's">';
                        }
                    ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($selected_user == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['username']); ?> - <?php echo htmlspecialchars($u['role']); ?>
                        </option>
                    <?php endwhile;
                    if ($current_role != '') echo '</optgroup>';
                    ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($selected_user > 0): ?>

        <!-- REPORT SECTION -->
        <div class="dashboard-card">
            <h3 style="margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">User Report</h3>

            <div class="stat-box bg-blue">
                <h4>Total Leads</h4>
                <h2><?php echo number_format($total_leads); ?></h2>
            </div>
            <div class="stat-box bg-green">
                <h4>Dialed Leads</h4>
                <h2><?php echo number_format($dialed_leads); ?></h2>
            </div>
            <div class="stat-box bg-yellow">
                <h4>Remaining Leads</h4>
                <h2><?php echo number_format($remaining_leads); ?></h2>
            </div>

            <?php if (!empty($status_breakdown)): ?>
                <div style="margin-top: 15px; padding: 15px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <strong>Breakdown of Dialed Leads:</strong><br>
                    <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px;">
                        <?php foreach ($status_breakdown as $status => $count): ?>
                            <span style="background: #fff; padding: 5px 10px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 14px;">
                                <strong><?php echo htmlspecialchars($status); ?>:</strong> <?php echo number_format($count); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- EXCEL PASTE & MAP SECTION -->
        <div class="dashboard-card" id="paste-section">
            <h3 style="margin-top: 0; color: #0f172a;">Bulk Add Leads (Smart Import)</h3>
            <textarea id="excel-paste-box" class="paste-area" placeholder="Click here and press Ctrl+V to paste leads from Excel..."></textarea>
        </div>

        <!-- MAPPING PREVIEW SECTION (Hidden by default) -->
        <div class="dashboard-card" id="mapping-section" style="display: none;">
            <h3 style="margin-top: 0; color: #0f172a;">Map Your Columns</h3>
            <div class="mapping-table-wrapper">
                <table class="mapping-table" id="preview-table">
                    <thead id="preview-thead"></thead>
                    <tbody id="preview-tbody"></tbody>
                </table>
            </div>
            <button id="btn-confirm-import" class="btn-import">Confirm & Import Leads</button>
            <button id="btn-cancel-import" class="btn-cancel">Cancel & Paste Again</button>
            <div id="loading-indicator" style="display: none; margin-top: 15px; color: #3b82f6; font-weight: bold;"><i class="fas fa-spinner fa-spin"></i> Processing...</div>
        </div>

        <!-- EXISTING LEADS SECTION (EXCEL-LIKE NAVIGATION & EDIT) -->
        <div class="dashboard-card">
            <h3 style="margin-top: 0; color: #0f172a;">Existing Leads (Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?>)</h3>

            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                <a href="data_pipeline.php?export_time_data=1&user_id=<?php echo $selected_user; ?>" class="btn-import" id="exportDataBtn" target="_blank" style="background:#10b981; margin-top: 0; text-decoration:none;">
                    <i class="fas fa-file-download"></i> Download Time Data
                </a>
                <button type="button" id="btn-open-batch-delete" class="btn-import" style="background:#dc3545; margin-top: 0;">
                    <i class="fas fa-trash-alt"></i> Delete Data
                </button>
                <button type="button" id="btn-open-purge" class="btn-import" style="background:#991b1b; margin-top: 0;">
                    <i class="fas fa-bomb"></i> Mass Purge
                </button>
            </div>

            <div style="background: #f1f5f9; padding: 10px; border-radius: 6px; margin-bottom: 10px; font-size: 13px; color: #334155; border: 1px solid #cbd5e1;">
                <strong>Quick Actions:</strong><br>
                &bull; <strong>Click & Drag:</strong> Select multiple cells.<br>
                &bull; <strong>Shift + Arrows:</strong> Expand selection.<br>
                &bull; <strong>Backspace:</strong> Empties the text in the selected cell(s).<br>
                &bull; <strong>Double-Click:</strong> Edit cell text.
            </div>

            <div class="mapping-table-wrapper" id="grid-container">
                <table class="mapping-table" id="existing-leads-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Agent Name</th>
                            <th>Phone</th>
                            <th>Status1</th>
                            <th>Status2</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>City</th>
                            <th>State</th>
                            <th>Zip Code</th>
                            <th>DOB</th>
                            <th>Vertical</th>
                            <th>Updates</th>
                            <th>Closers</th>
                            <th>Application</th>
                            <th>Referred By</th>
                            <th>Timestamp</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($existing_leads)): ?>
                            <tr>
                                <td colspan="20" style="text-align: center;">No leads assigned to this user yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($existing_leads as $lead): ?>
                                <tr id="row-<?php echo $lead['id']; ?>">
                                    <td class="locked-cell" style="text-align: center; font-weight: bold; background: #f8fafc; border-right: 2px solid #cbd5e1;"><?php echo (int)$lead['id']; ?></td>
                                    <td class="grid-cell" data-field="agent_name"><?php echo htmlspecialchars($lead['agent_name'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="phone_number"><?php echo htmlspecialchars($lead['phone_number'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="status" style="font-weight: bold;"><?php echo htmlspecialchars($lead['status'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="status2"><?php echo htmlspecialchars($lead['status2'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="first_name"><?php echo htmlspecialchars($lead['first_name'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="last_name"><?php echo htmlspecialchars($lead['last_name'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="email"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="address"><?php echo htmlspecialchars($lead['address'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="city"><?php echo htmlspecialchars($lead['city'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="state"><?php echo htmlspecialchars($lead['state'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="zip_code"><?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="dob"><?php echo htmlspecialchars($lead['dob'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="vertical"><?php echo htmlspecialchars($lead['vertical'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="old_updates">
                                        <div class="scroll-box"><?php echo htmlspecialchars($lead['old_updates'] ?? ''); ?></div>
                                    </td>
                                    <td class="grid-cell" data-field="previous_closers"><?php echo htmlspecialchars($lead['previous_closers'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="application_text">
                                        <div class="scroll-box"><?php echo htmlspecialchars($lead['application_text'] ?? ''); ?></div>
                                    </td>
                                    <td class="grid-cell" data-field="referred_by"><?php echo htmlspecialchars($lead['referred_by'] ?? ''); ?></td>
                                    <td class="grid-cell" data-field="timestamp1"><?php echo htmlspecialchars($lead['timestamp1'] ?? ''); ?></td>
                                    <td style="color:#64748b;"><?php echo date('m-d-Y H:i', strtotime($lead['updated_at'] ?? $lead['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?user_id=<?php echo $selected_user; ?>&page=<?php echo $page - 1; ?>" class="page-link">Previous</a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 3);
                    $end = min($total_pages, $page + 3);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="?user_id=<?php echo $selected_user; ?>&page=<?php echo $i; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?user_id=<?php echo $selected_user; ?>&page=<?php echo $page + 1; ?>" class="page-link">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- BATCH DELETE MODAL -->
        <div id="batchDeleteModal" class="modal-overlay">
            <div class="modal-content" style="width: 450px;">
                <h3 style="color: #991b1b;"><i class="fas fa-trash-alt"></i> Batch Delete Leads</h3>
                <p style="font-size: 13px; color: #475569; margin-bottom: 20px;">Select a batch of leads to permanently delete for this user.</p>

                <div style="text-align: left; margin-bottom: 20px;">
                    <label style="font-weight: bold; font-size: 13px; color: #333;">Select Batch to Delete:</label>
                    <select id="batchSelect" class="mapping-select" style="margin-top: 8px; padding: 10px;">
                        <?php
                        if ($total_leads > 0) {
                            $batches = ceil($total_leads / 100);
                            for ($i = 0; $i < $batches; $i++) {
                                $start = ($i * 100) + 1;
                                $end = min(($i + 1) * 100, $total_leads);
                                $offset = $i * 100;
                                echo "<option value=\"{$offset}\">Batch: Leads {$start} to {$end}</option>";
                            }
                        } else {
                            echo "<option value=''>No leads available</option>";
                        }
                        ?>
                    </select>
                </div>

                <button id="btn-confirm-batch-delete" class="modal-btn" style="background: #dc3545; color: white;">Delete Selected Batch</button>
                <button class="modal-btn" style="background: #e2e8f0; color: #475569; margin-top: 10px;" onclick="document.getElementById('batchDeleteModal').style.display='none'">Cancel</button>
            </div>
        </div>

        <!-- MASS PURGE MODAL -->
        <div id="purgeModal" class="modal-overlay">
            <div class="modal-content" style="width: 400px;">
                <h3 style="color: #991b1b;"><i class="fas fa-bomb"></i> Mass Purge Data</h3>
                <p style="font-size: 13px; color: #475569; margin-bottom: 20px;">Permanently delete leads for this user by status.</p>

                <div style="text-align: left; margin-bottom: 15px;">
                    <label style="font-weight: bold; font-size: 12px; color: #333;">Select Status to Purge:</label>
                    <select id="purgeStatusSelect" class="mapping-select" style="margin-top: 5px;">
                        <option value="ALL">⚠️ ALL LEADS (Entire User Queue)</option>
                        <option value="New">New</option>
                        <option value="VM">VM</option>
                        <option value="NIS (Not In Service)">NIS (Not In Service)</option>
                        <option value="Transfered">Transfered</option>
                        <option value="NI">NI</option>
                        <option value="Hangup">Hangup</option>
                        <option value="LB (Language Barier)">LB (Language Barier)</option>
                        <option value="CB">CB</option>
                    </select>
                </div>

                <button id="btn-confirm-purge" class="modal-btn" style="background: #dc3545; color: white;">Yes, Purge Data</button>
                <button class="modal-btn" style="background: #e2e8f0; color: #475569; margin-top: 10px;" onclick="document.getElementById('purgeModal').style.display='none'">Cancel</button>
            </div>
        </div>

        <script>
            // Tracker for Data Download Validation
            let isDataDownloaded = false;
            document.getElementById('exportDataBtn')?.addEventListener('click', function() {
                isDataDownloaded = true;
            });

            // ==========================================
            // BATCH DELETE LOGIC
            // ==========================================
            document.getElementById('btn-open-batch-delete')?.addEventListener('click', function() {
                document.getElementById('batchDeleteModal').style.display = 'flex';
            });

            document.getElementById('btn-confirm-batch-delete')?.addEventListener('click', function() {
                const offset = document.getElementById('batchSelect').value;
                const userId = "<?php echo $selected_user; ?>";

                if (offset === '') {
                    alert("No leads available to delete.");
                    return;
                }

                if (!isDataDownloaded) {
                    if (!confirm("WARNING: You have not downloaded the Time Data yet! Do you really want to permanently delete this batch of leads?")) {
                        return;
                    }
                } else {
                    if (!confirm("Are you sure you want to permanently delete this batch of leads?")) {
                        return;
                    }
                }

                this.innerText = "Processing...";
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('ajax_action', 'delete_batch');
                formData.append('user_id', userId);
                formData.append('offset', offset);

                fetch('data_pipeline.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: formData.toString()
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Success')) {
                            alert('Batch deleted successfully.');
                            window.location.reload();
                        } else {
                            alert('Failed to delete leads: ' + data);
                            document.getElementById('batchDeleteModal').style.display = 'none';
                            this.innerText = "Delete Selected Batch";
                            this.disabled = false;
                        }
                    }).catch(err => {
                        alert('Network error while deleting.');
                        document.getElementById('batchDeleteModal').style.display = 'none';
                        this.innerText = "Delete Selected Batch";
                        this.disabled = false;
                    });
            });

            // ==========================================
            // MASS PURGE LOGIC
            // ==========================================
            document.getElementById('btn-open-purge')?.addEventListener('click', function() {
                document.getElementById('purgeModal').style.display = 'flex';
            });

            document.getElementById('btn-confirm-purge')?.addEventListener('click', function() {
                const purgeStatus = document.getElementById('purgeStatusSelect').value;
                const userId = "<?php echo $selected_user; ?>";

                if (!isDataDownloaded) {
                    if (!confirm("WARNING: You have not downloaded the Time Data yet! Do you really want to permanently delete this data?")) {
                        return;
                    }
                } else {
                    if (!confirm(`Are you absolutely sure you want to delete ${purgeStatus === 'ALL' ? 'ALL' : '"' + purgeStatus + '"'} leads for this user?`)) {
                        return;
                    }
                }

                this.innerText = "Processing...";
                this.disabled = true;

                const formData = new URLSearchParams();
                formData.append('ajax_action', 'mass_purge');
                formData.append('user_id', userId);
                formData.append('purge_status', purgeStatus);

                fetch('data_pipeline.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: formData.toString()
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Success')) {
                            alert('Purge successful.');
                            window.location.reload();
                        } else {
                            alert('Failed to purge leads: ' + data);
                            document.getElementById('purgeModal').style.display = 'none';
                            this.innerText = "Yes, Purge Data";
                            this.disabled = false;
                        }
                    }).catch(err => {
                        alert('Network error while purging.');
                        document.getElementById('purgeModal').style.display = 'none';
                        this.innerText = "Yes, Purge Data";
                        this.disabled = false;
                    });
            });

            // ==========================================
            // EXCEL-LIKE EDITING ENGINE
            // ==========================================
            document.addEventListener("DOMContentLoaded", function() {
                const table = document.getElementById('existing-leads-table');
                if (!table) return;

                let selectedCells = new Set();
                let activeCell = null;
                let isDragging = false;
                let selectionStartCell = null;
                let selectionEndCell = null;

                function getCoords(cell) {
                    return {
                        r: cell.parentElement.rowIndex,
                        c: cell.cellIndex
                    };
                }

                function clearSelection() {
                    selectedCells.forEach(cell => cell.classList.remove('selected-cell'));
                    selectedCells.clear();
                }

                function renderSelection() {
                    clearSelection();
                    if (!selectionStartCell || !selectionEndCell) return;

                    const start = getCoords(selectionStartCell);
                    const end = getCoords(selectionEndCell);

                    const minRow = Math.min(start.r, end.r);
                    const maxRow = Math.max(start.r, end.r);
                    const minCol = Math.min(start.c, end.c);
                    const maxCol = Math.max(start.c, end.c);

                    const rows = table.rows;
                    for (let r = minRow; r <= maxRow; r++) {
                        if (!rows[r]) continue;
                        for (let c = minCol; c <= maxCol; c++) {
                            const cell = rows[r].cells[c];
                            if (cell && cell.classList.contains('grid-cell')) {
                                selectedCells.add(cell);
                                cell.classList.add('selected-cell');
                            }
                        }
                    }
                }

                table.addEventListener('mousedown', (e) => {
                    const cell = e.target.closest('.grid-cell');
                    if (!cell || (document.activeElement && document.activeElement.getAttribute('contenteditable') === 'true')) return;

                    isDragging = true;
                    selectionStartCell = cell;
                    selectionEndCell = cell;
                    activeCell = cell;
                    renderSelection();
                });

                table.addEventListener('mouseover', (e) => {
                    if (!isDragging) return;
                    const cell = e.target.closest('.grid-cell');
                    if (cell) {
                        selectionEndCell = cell;
                        activeCell = cell;
                        renderSelection();
                    }
                });

                document.addEventListener('mouseup', () => {
                    isDragging = false;
                });

                table.addEventListener('dblclick', (e) => {
                    const cell = e.target.closest('.grid-cell');
                    if (!cell) return;

                    clearSelection();

                    // Target the inner scroll box if it exists, otherwise target the cell
                    const targetNode = cell.querySelector('.scroll-box') || cell;

                    cell.classList.add('editing-cell');
                    targetNode.setAttribute('contenteditable', 'true');
                    targetNode.focus();

                    const range = document.createRange();
                    range.selectNodeContents(targetNode);
                    range.collapse(false);
                    const sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);

                    const saveAndClose = () => {
                        targetNode.removeAttribute('contenteditable');
                        cell.classList.remove('editing-cell');
                        const leadId = cell.parentElement.id.replace('row-', '');
                        const fieldName = cell.dataset.field;
                        updateLeadDatabase(leadId, fieldName, targetNode.innerText.trim());

                        targetNode.removeEventListener('blur', saveAndClose);
                        targetNode.removeEventListener('keydown', handleEditKey);
                    };

                    const handleEditKey = (e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            targetNode.blur();
                        }
                        if (e.key === 'Escape') {
                            targetNode.blur();
                        }
                    };

                    targetNode.addEventListener('blur', saveAndClose);
                    targetNode.addEventListener('keydown', handleEditKey);
                });

                document.addEventListener('keydown', (e) => {
                    if (document.activeElement.getAttribute('contenteditable') === 'true') return;

                    if (e.key === 'Backspace') {
                        if (selectedCells.size > 0) {
                            e.preventDefault();
                            selectedCells.forEach(cell => {
                                const targetNode = cell.querySelector('.scroll-box') || cell;
                                targetNode.innerText = '';
                                const leadId = cell.parentElement.id.replace('row-', '');
                                updateLeadDatabase(leadId, cell.dataset.field, '');
                            });
                        }
                        return;
                    }

                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
                        e.preventDefault();
                        const rows = table.querySelectorAll('tbody tr');
                        if (rows.length > 0 && rows[0].cells[0].classList.contains('grid-cell')) {
                            selectionStartCell = rows[0].cells[0];
                            const lastRow = rows[rows.length - 1];
                            selectionEndCell = lastRow.cells[lastRow.cells.length - 2]; // Exclude timestamp
                            renderSelection();
                        }
                        return;
                    }

                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
                        if (selectedCells.size > 0) {
                            e.preventDefault();
                            const text = Array.from(selectedCells).map(c => {
                                const targetNode = c.querySelector('.scroll-box') || c;
                                return targetNode.innerText.replace(/\n/g, ' ');
                            }).join('\t');
                            navigator.clipboard.writeText(text);
                        }
                        return;
                    }

                    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && activeCell) {
                        e.preventDefault();
                        const coords = getCoords(activeCell);
                        let targetRow = coords.r;
                        let targetCol = coords.c;

                        if (e.key === 'ArrowUp') targetRow = Math.max(1, coords.r - 1);
                        if (e.key === 'ArrowDown') targetRow = Math.min(table.rows.length - 1, coords.r + 1);
                        if (e.key === 'ArrowLeft') targetCol = Math.max(0, coords.c - 1);
                        if (e.key === 'ArrowRight') targetCol = Math.min(table.rows[targetRow].cells.length - 2, coords.c + 1);

                        const nextCell = table.rows[targetRow].cells[targetCol];
                        if (nextCell && nextCell.classList.contains('grid-cell')) {
                            activeCell = nextCell;

                            if (e.shiftKey) {
                                selectionEndCell = nextCell;
                            } else {
                                selectionStartCell = nextCell;
                                selectionEndCell = nextCell;
                            }

                            nextCell.scrollIntoView({
                                behavior: "smooth",
                                block: "nearest",
                                inline: "nearest"
                            });
                            renderSelection();
                        }
                    }
                });

                function updateLeadDatabase(leadId, fieldName, newValue) {
                    const formData = new URLSearchParams();
                    formData.append('ajax_action', 'update_lead');
                    formData.append('lead_id', leadId);
                    formData.append('field', fieldName);
                    formData.append('value', newValue);

                    fetch('data_pipeline.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: formData.toString()
                        })
                        .catch(err => console.error('Error updating:', err));
                }

                // ==========================================
                // ROBUST TSV/EXCEL PARSER
                // ==========================================
                const dbFields = [{
                        value: '',
                        label: '-- Ignore --'
                    },
                    {
                        value: 'agent_name',
                        label: 'Agent Name'
                    },
                    {
                        value: 'phone_number',
                        label: 'Phone (Required)'
                    },
                    {
                        value: 'status',
                        label: 'Status1'
                    },
                    {
                        value: 'status2',
                        label: 'Status2'
                    },
                    {
                        value: 'first_name',
                        label: 'First Name'
                    },
                    {
                        value: 'last_name',
                        label: 'Last Name'
                    },
                    {
                        value: 'email',
                        label: 'Email'
                    },
                    {
                        value: 'address',
                        label: 'Address'
                    },
                    {
                        value: 'city',
                        label: 'City'
                    },
                    {
                        value: 'state',
                        label: 'State'
                    },
                    {
                        value: 'zip_code',
                        label: 'Zip Code'
                    },
                    {
                        value: 'dob',
                        label: 'DOB'
                    },
                    {
                        value: 'vertical',
                        label: 'Vertical'
                    },
                    {
                        value: 'old_updates',
                        label: 'Updates'
                    },
                    {
                        value: 'previous_closers',
                        label: 'Closers'
                    },
                    {
                        value: 'application_text',
                        label: 'Application'
                    },
                    {
                        value: 'referred_by',
                        label: 'Referred By'
                    },
                    {
                        value: 'timestamp1',
                        label: 'Timestamp1'
                    }
                ];

                const autoGuessDefaults = ['agent_name', 'phone_number', 'status', 'status2', 'first_name', 'last_name', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'vertical', 'old_updates', 'previous_closers', 'application_text', 'referred_by', 'timestamp1'];

                let fullPastedData = [];

                function parseExcelPaste(pasteData) {
                    let rows = [];
                    let currentRow = [];
                    let currentCell = '';
                    let inQuotes = false;

                    // Normalize line endings
                    pasteData = pasteData.replace(/\r/g, '');

                    for (let i = 0; i < pasteData.length; i++) {
                        let char = pasteData[i];
                        let nextChar = pasteData[i + 1];

                        if (char === '"' && inQuotes && nextChar === '"') {
                            currentCell += '"';
                            i++;
                        } else if (char === '"') {
                            inQuotes = !inQuotes;
                        } else if (char === '\t' && !inQuotes) {
                            currentRow.push(currentCell); // NO TRIM! Preserves empty columns perfectly
                            currentCell = '';
                        } else if (char === '\n' && !inQuotes) {
                            currentRow.push(currentCell);
                            if (currentRow.some(cell => cell.trim() !== '')) {
                                rows.push(currentRow);
                            }
                            currentRow = [];
                            currentCell = '';
                        } else {
                            currentCell += char;
                        }
                    }

                    if (currentCell || currentRow.length > 0) {
                        currentRow.push(currentCell);
                        if (currentRow.some(cell => cell.trim() !== '')) {
                            rows.push(currentRow);
                        }
                    }
                    return rows;
                }

                document.getElementById('excel-paste-box').addEventListener('paste', function(e) {
                    e.preventDefault();
                    const clipboardData = e.clipboardData || window.clipboardData;
                    const pastedText = clipboardData.getData('Text');
                    if (!pastedText.trim()) return;

                    fullPastedData = parseExcelPaste(pastedText);

                    if (fullPastedData.length === 0) return;

                    let maxCols = 0;
                    fullPastedData.forEach(row => {
                        if (row.length > maxCols) maxCols = row.length;
                    });

                    document.getElementById('paste-section').style.display = 'none';
                    document.getElementById('mapping-section').style.display = 'block';
                    generatePreviewTable(maxCols);
                });

                function generatePreviewTable(maxCols) {
                    const thead = document.getElementById('preview-thead');
                    const tbody = document.getElementById('preview-tbody');
                    thead.innerHTML = '';
                    tbody.innerHTML = '';

                    let headerRow = document.createElement('tr');
                    for (let i = 0; i < maxCols; i++) {
                        let th = document.createElement('th');
                        let select = document.createElement('select');
                        select.className = 'mapping-select';
                        select.dataset.colIndex = i;

                        dbFields.forEach(field => {
                            let option = document.createElement('option');
                            option.value = field.value;
                            option.innerText = field.label;
                            if (autoGuessDefaults[i] && field.value === autoGuessDefaults[i]) option.selected = true;
                            select.appendChild(option);
                        });

                        th.appendChild(select);
                        headerRow.appendChild(th);
                    }
                    thead.appendChild(headerRow);

                    for (let r = 0; r < fullPastedData.length; r++) {
                        let tr = document.createElement('tr');
                        for (let c = 0; c < maxCols; c++) {
                            let td = document.createElement('td');
                            td.innerText = fullPastedData[r][c] !== undefined ? fullPastedData[r][c] : '';
                            tr.appendChild(td);
                        }
                        tbody.appendChild(tr);
                    }
                }

                document.getElementById('btn-cancel-import').addEventListener('click', function() {
                    fullPastedData = [];
                    document.getElementById('excel-paste-box').value = '';
                    document.getElementById('mapping-section').style.display = 'none';
                    document.getElementById('paste-section').style.display = 'block';
                });

                document.getElementById('btn-confirm-import').addEventListener('click', function() {
                    const selects = document.querySelectorAll('.mapping-select');
                    let columnMap = {};
                    let hasPhone = false;

                    selects.forEach(select => {
                        let colIndex = select.dataset.colIndex;
                        let selectedField = select.value;
                        if (selectedField !== '') {
                            columnMap[colIndex] = selectedField;
                            if (selectedField === 'phone_number') hasPhone = true;
                        }
                    });

                    if (!hasPhone) {
                        alert("You MUST map at least one column to 'Phone Number' to import leads.");
                        return;
                    }

                    this.style.display = 'none';
                    document.getElementById('btn-cancel-import').style.display = 'none';
                    document.getElementById('loading-indicator').style.display = 'block';

                    let mappedData = [];
                    for (let r = 0; r < fullPastedData.length; r++) {
                        let rowObj = {};
                        for (let colIndex in columnMap) {
                            let fieldName = columnMap[colIndex];
                            rowObj[fieldName] = fullPastedData[r][colIndex] !== undefined ? fullPastedData[r][colIndex].trim() : '';
                        }
                        mappedData.push(rowObj);
                    }

                    const formData = new URLSearchParams();
                    formData.append('ajax_action', 'bulk_import');
                    formData.append('user_id', '<?php echo $selected_user; ?>');
                    formData.append('leads', JSON.stringify(mappedData));

                    fetch('data_pipeline.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: formData.toString()
                        })
                        .then(response => response.text())
                        .then(data => {
                            if (data.includes('Success')) {
                                alert(data);
                                window.location.reload();
                            } else {
                                alert('Error processing leads: ' + data);
                                resetUI();
                            }
                        }).catch(err => {
                            alert('Network error occurred.');
                            resetUI();
                        });

                    function resetUI() {
                        document.getElementById('btn-confirm-import').style.display = 'inline-block';
                        document.getElementById('btn-cancel-import').style.display = 'inline-block';
                        document.getElementById('loading-indicator').style.display = 'none';
                    }
                });

            });
        </script>

    <?php endif; ?>
</body>

</html>