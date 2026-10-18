<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

$conn->query("ALTER TABLE time_data ADD COLUMN IF NOT EXISTS import_batch_id VARCHAR(100) DEFAULT NULL, ADD COLUMN IF NOT EXISTS sheet_name VARCHAR(150) DEFAULT 'Default'");
$conn->query("ALTER TABLE time_data2 ADD COLUMN IF NOT EXISTS import_batch_id VARCHAR(100) DEFAULT NULL, ADD COLUMN IF NOT EXISTS sheet_name VARCHAR(150) DEFAULT 'Default'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(50) DEFAULT NULL, ADD COLUMN IF NOT EXISTS address TEXT DEFAULT NULL, ADD COLUMN IF NOT EXISTS dob DATE DEFAULT NULL");
$conn->query("ALTER TABLE inhouse_chats ADD COLUMN IF NOT EXISTS agent_id INT DEFAULT 0");

$selected_user = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'gen' ? 'gen' : 'pipeline';
$table_name = $active_tab === 'gen' ? 'time_data2' : 'time_data';

// ==========================================
// EXPORT DATA TO CSV (EXCEL)
// ==========================================
if (isset($_GET['export_data']) && $_GET['export_data'] == 1 && $selected_user > 0) {
    $u_q = $conn->query("SELECT username FROM users WHERE id = $selected_user");
    $export_username = ($u_q && $u_q->num_rows > 0) ? $u_q->fetch_assoc()['username'] : 'User';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table_name . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $export_username) . '_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    fputcsv($output, ['Agent Name', 'Phone', 'Status1', 'Status2', 'First Name', 'Last Name', 'Email', 'Address', 'City', 'State', 'Zip Code', 'DOB', 'Vertical', 'Updates', 'Closers', 'Application', 'Referred By', 'Timestamp']);

    $q = $conn->query("SELECT * FROM $table_name WHERE assigned_user_id = $selected_user ORDER BY id ASC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $r['agent_name'] ?? '',
                " " . ($r['phone_number'] ?? ''),
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
                $r['updated_at'] ?? $r['timestamp1'] ?? ''
            ]);
        }
    }
    fclose($output);
    exit();
}

// ==========================================
// AJAX HANDLERS
// ==========================================
if (isset($_POST['ajax_action'])) {

    // 1. BULK IMPORT LEADS
    if ($_POST['ajax_action'] == 'bulk_import') {
        $user_id = (int)$_POST['user_id'];
        $sheet_name = trim($_POST['sheet_name'] ?? 'Default');
        $import_batch_id = date('M-d-Y h:i:s A') . '_' . uniqid();
        $leads = json_decode($_POST['leads'], true);
        if (!$user_id || empty($leads)) {
            exit('Error: Invalid Data or No User Selected');
        }

        // 1. UPDATED QUERY: 'phone' and 'created_at' now match your database table perfectly
        $sql = "INSERT INTO $table_name (
            assigned_user_id, agent_name, phone_number, status, status2, 
            first_name, last_name, email, address, city, 
            state, zip_code, dob, vertical, old_updates, 
            previous_closers, application_text, referred_by, created_at, sheet_name, import_batch_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        // 2. ERROR CATCHER: This instantly stops the white screen and tells you exactly what is wrong if it fails
        if (!$stmt) {
            die("Database Error in Prepare: " . $conn->error);
        }

        $inserted_count = 0;
        $user_lookup_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$user_lookup_stmt) {
            $stmt->close();
            die("Database Error in User Lookup: " . $conn->error);
        }

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
                if ($user_lookup_stmt->execute()) {
                    $user_result = $user_lookup_stmt->get_result();
                    if ($user_row = $user_result->fetch_assoc()) {
                        $assigned_user_id = (int)$user_row['id'];
                    }
                }
            }

            // Fallback: If no valid user was matched from the row text, assign to the dropdown's selected user ID
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

            // Map the CSV's timestamp into the database's created_at variable
            $created_at = trim($lead['timestamp1'] ?? '');
            // Optional fallback: If the CSV is missing a timestamp, generate one right now
            if (empty($created_at)) $created_at = date('Y-m-d H:i:s');

            $stmt->bind_param(
                "issssssssssssssssssss",
                $assigned_user_id,
                $agent_name,
                $phone,
                $status1,
                $status2,
                $fname,
                $lname,
                $email,
                $address,
                $city,
                $state,
                $zip,
                $dob,
                $vertical,
                $old_updates,
                $previous_closers,
                $application_text,
                $referred_by,
                $created_at,
                $sheet_name,
                $import_batch_id
            );

            if ($stmt->execute()) {
                $inserted_count++;
            } else {
                // Optional: If a specific row fails (like a duplicate phone number), you can see it here
                error_log("Failed to insert lead (Phone: $phone). Error: " . $stmt->error);
            }
        }

        $user_lookup_stmt->close();
        $stmt->close();
        exit("Success: $inserted_count");
    }

    // 2. INLINE EDIT (AUTO-SAVE)
    if ($_POST['ajax_action'] == 'update_lead') {
        $lead_id = (int)$_POST['lead_id'];
        $field = $_POST['field'];
        $value = trim($_POST['value']);
        $allowed_fields = ['agent_name', 'phone_number', 'status', 'status2', 'first_name', 'last_name', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'vertical', 'notes', 'old_updates', 'previous_closers', 'application_text', 'referred_by'];

        if (in_array($field, $allowed_fields)) {
            $stmt = $conn->prepare("UPDATE $table_name SET `$field` = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("si", $value, $lead_id);
            if ($stmt->execute()) echo "Success";
            else echo "Error";
            $stmt->close();
        } else {
            echo "Invalid Field";
        }
        exit;
    }

    // 3. BATCH DELETE
    if ($_POST['ajax_action'] == 'delete_batch') {
        $u_id = (int)$_POST['user_id'];
        $batch_id = $_POST['batch_id'];

        $stmt = $conn->prepare("DELETE FROM $table_name WHERE assigned_user_id = ? AND import_batch_id = ?");
        $stmt->bind_param("is", $u_id, $batch_id);
        if ($stmt->execute()) echo "Success";
        else echo "Error";
        $stmt->close();
        exit;
    }

    // 4. MASS PURGE
    if ($_POST['ajax_action'] == 'mass_purge') {
        $u_id = (int)$_POST['user_id'];
        $status = $_POST['purge_status'];

        if ($status === 'ALL') {
            $stmt = $conn->prepare("DELETE FROM $table_name WHERE assigned_user_id = ?");
            $stmt->bind_param("i", $u_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM $table_name WHERE assigned_user_id = ? AND (status = ? OR status2 = ?)");
            $stmt->bind_param("iss", $u_id, $status, $status);
        }
        if ($stmt->execute()) echo "Success";
        else echo "Error";
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
if (!$users_result) {
    die('Database Error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}

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
$bad_data_leads = 0;
$status_breakdown = [];
$existing_leads = [];
$total_pages = 1;

if ($selected_user > 0) {
    $report_query = "SELECT status, COUNT(*) AS lead_count
                     FROM `$table_name`
                     WHERE assigned_user_id = $selected_user
                     GROUP BY status";
    $report_result = $conn->query($report_query);

    if ($report_result) {
        while ($row = $report_result->fetch_assoc()) {
            $lead_count = (int)$row['lead_count'];
            $total_leads += $lead_count;
            $status = trim((string)($row['status'] ?? ''));

            if ($status === '' || strcasecmp($status, 'New') === 0) {
                $remaining_leads += $lead_count;
            } else {
                $dialed_leads += $lead_count;
                $status_breakdown[$status] = $lead_count;
                if (preg_match('/\b(?:dnc|do not call|bad\s+data|bad\s+(?:number|phone)|invalid\s+(?:number|phone|data))\b/i', $status)) {
                    $bad_data_leads += $lead_count;
                }
            }
        }
        $report_result->free();
    } else {
        die('Database Error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
    }

    // Apply Filters
    $search = isset($_GET['search']) ? trim(mysqli_real_escape_string($conn, $_GET['search'])) : '';
    $status_filter = isset($_GET['status_filter']) ? trim(mysqli_real_escape_string($conn, $_GET['status_filter'])) : 'all';
    $where_clause = "assigned_user_id = $selected_user";

    if ($search !== '') $where_clause .= " AND (phone_number LIKE '%$search%' OR first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR email LIKE '%$search%')";
    if ($status_filter !== '' && $status_filter !== 'all') $where_clause .= " AND (status = '$status_filter' OR status2 = '$status_filter')";

    $filtered_count_result = $conn->query("SELECT COUNT(*) AS total FROM `$table_name` WHERE $where_clause");
    if (!$filtered_count_result) {
        die('Database Error: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
    }
    $filtered_total_leads = (int)$filtered_count_result->fetch_assoc()['total'];
    $total_pages = max(1, (int)ceil($filtered_total_leads / $limit));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $limit;

    $leads_query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $leads_result = $conn->query($leads_query);
    if ($leads_result) {
        while ($row = $leads_result->fetch_assoc()) {
            $existing_leads[] = $row;
        }
    }
}

include 'includes/header.php';
?>
<style>
    .dashboard-card {
        background: var(--card-bg, rgba(15, 23, 42, 0.6));
        padding: 20px;
        border-radius: 8px;
        border: 1px solid var(--border-color, rgba(56, 189, 248, 0.2));
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
        display: block;
        width: 100%;
        box-sizing: border-box;
    }

    .stat-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        min-width: 0;
        box-sizing: border-box;
        padding: 20px;
        border-radius: 12px;
        background: #0f172a;
        border: 1px solid #1e293b;
    }

    .stat-box h4 {
        margin: 0 0 8px;
        font-size: 12px;
        letter-spacing: 1px;
        font-weight: bold;
        color: #94a3b8;
        text-transform: uppercase;
    }

    .stat-box h2 {
        margin: 0;
        font-size: 28px;
        line-height: 1.15;
        color: var(--stat-color);
    }

    .report-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .stat-box-content {
        min-width: 0;
    }

    .stat-box-icon {
        color: var(--stat-color);
        font-size: 24px;
        opacity: .9;
    }

    .stat-blue {
        --stat-color: #38bdf8;
    }

    .stat-green {
        --stat-color: #10b981;
    }

    .stat-amber {
        --stat-color: #f59e0b;
    }

    .stat-rose {
        --stat-color: #ef4444;
    }

    .report-breakdown {
        margin-top: 20px;
        padding: 15px;
        background: #0f172a;
        border-radius: 10px;
        border: 1px solid #1e293b;
        color: #e2e8f0;
    }

    .report-breakdown-items {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
    }

    .report-breakdown-item {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid #334155;
        background: #1e293b;
        color: #cbd5e1;
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
        resize: vertical;
        box-sizing: border-box;
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

    .mapping-table th {
        background: var(--header-bg, #0f172a);
        color: #f8fafc;
        position: sticky;
        top: 0;
        z-index: 2;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4);
    }

    .mapping-table td {
        padding: 8px 12px;
        border: 1px solid #334155;
        text-align: left;
        vertical-align: top;
        background: transparent;
        color: inherit;
    }

    .search-container {
        display: flex;
        align-items: center;
        gap: 12px;
        width: min(450px, 100%);
        box-sizing: border-box;
        padding: 10px 20px;
        border: 1px solid #334155;
        border-radius: 24px;
        background: #0F172A;
        color: #fff;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .search-container:focus-within {
        border-color: var(--electric-blue, #2563eb);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
    }

    .search-container i {
        color: #94A3B8;
    }

    .search-container input {
        width: 100%;
        min-width: 0;
        border: 0;
        outline: 0;
        background: transparent;
        color: #fff;
        font-size: 14px;
    }

    .excel-container {
        width: 100%;
        max-height: 65vh;
        overflow: auto;
        border: 1px solid #334155;
        border-radius: 8px;
        background: #0F172A;
    }

    .excel-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        font-size: 13px;
        color: #e2e8f0;
        background: #0F172A;
        white-space: nowrap;
    }

    .excel-table th,
    .excel-table td {
        position: relative;
        height: 38px;
        box-sizing: border-box;
        padding: 4px 25px 4px 8px;
        border: 1px solid #334155;
        text-align: center;
        vertical-align: middle;
    }

    .excel-table th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: #1E293B;
        color: #F8FAFC;
        user-select: none;
    }

    .excel-table tbody tr:hover {
        background: #17243a;
    }

    .excel-table td.cell-editable,
    .excel-table td.locked-cell {
        padding: 4px;
    }

    .scroll-box {
        width: 100%;
        height: 28px;
        max-height: 28px;
        overflow-y: auto;
        overflow-x: hidden;
        white-space: pre-wrap;
        word-wrap: break-word;
        outline: none;
        font-size: 13px;
        line-height: 1.2;
        padding: 6px 4px 0;
        box-sizing: border-box;
        cursor: text;
    }

    .scroll-box::-webkit-scrollbar {
        width: 4px;
    }

    .scroll-box::-webkit-scrollbar-thumb {
        background: #64748b;
        border-radius: 4px;
    }

    .cell-editable {
        cursor: cell;
    }

    .cell-selected {
        position: relative;
        z-index: 1;
        box-shadow: inset 0 0 0 2px #2563eb !important;
    }

    .cell-editing {
        box-shadow: inset 0 0 0 2px #10b981 !important;
        background: #13251f !important;
        cursor: text;
    }

    .locked-cell {
        color: #94a3b8;
        cursor: not-allowed;
    }

    .filter-toggle {
        position: static;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
    }

    .filter-icon {
        position: absolute;
        top: 50%;
        right: 8px;
        transform: translateY(-50%);
        color: #94A3B8;
        transition: color 0.15s ease;
    }

    .filter-toggle:hover .filter-icon,
    .filter-toggle[aria-expanded="true"] .filter-icon {
        color: #38bdf8;
    }

    .filter-menu {
        position: absolute;
        top: calc(100% + 4px);
        right: 0;
        z-index: 10;
        width: 220px;
        max-height: 280px;
        overflow: auto;
        padding: 10px;
        border: 1px solid #475569;
        border-radius: 6px;
        background: #0F172A;
        color: #e2e8f0;
        text-align: left;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35);
        font-weight: normal;
    }

    .filter-menu[hidden] {
        display: none;
    }

    .filter-menu label {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 4px 0;
        white-space: normal;
    }

    .filter-options {
        max-height: 190px;
        overflow: auto;
        margin-top: 5px;
        border-top: 1px solid #334155;
    }

    .pagination-header {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        margin-bottom: 15px;
        gap: 12px;
        color: #e2e8f0;
    }

    .pagination-controls {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 6px;
    }

    .pagination-button {
        display: inline-block;
        padding: 8px 12px;
        border: 0;
        border-radius: 8px;
        background: var(--electric-blue, #2563eb);
        color: #fff;
        font-weight: 700;
        text-decoration: none;
    }

    .pagination-button.disabled {
        background: #334155;
        color: #94a3b8;
        pointer-events: none;
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

    .tab-nav {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 25px;
        width: 100%;
    }

    .tab-button {
        padding: 12px 25px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-weight: 700;
        font-size: 15px;
        color: #64748b;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: 0.2s;
        text-decoration: none;
    }

    .tab-button:hover {
        color: var(--primary-navy);
    }

    .tab-button.active {
        color: var(--primary-navy);
        border-bottom-color: var(--electric-blue);
    }
</style>

<div class="dashboard-card">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
        <h2 style="margin: 0; color: var(--primary-navy); font-size: 24px;">Admin Data Management</h2>
        <form method="GET" action="" style="display: flex; align-items: center; gap: 10px;">
            <input type="hidden" name="tab" id="current_tab_input" value="<?php echo $active_tab; ?>">
            <label style="font-weight: bold; font-size: 14px;">Select Target User:</label>
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
                        <?php echo htmlspecialchars($u['username']); ?>
                    </option>
                <?php endwhile;
                if ($current_role != '') echo '</optgroup>';
                ?>
            </select>
        </form>
    </div>
</div>

<?php if ($selected_user > 0): ?>

    <!-- TABS NAVIGATION -->
    <div class="tab-nav">
        <a href="?user_id=<?= $selected_user ?>&tab=pipeline" class="tab-button <?= $active_tab === 'pipeline' ? 'active' : '' ?>">Pipeline Data (Table 1)</a>
        <a href="?user_id=<?= $selected_user ?>&tab=gen" class="tab-button <?= $active_tab === 'gen' ? 'active' : '' ?>">Gen Data (Table 2)</a>
    </div>

    <!-- REPORT SECTION -->
    <div class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid #1e293b; padding-bottom: 10px; color: #e2e8f0;">User Report - <?= strtoupper($active_tab) ?></h3>
        <div class="report-stats-grid">
            <div class="stat-box stat-blue">
                <div class="stat-box-content">
                    <h4>Total Leads</h4>
                    <h2><?php echo number_format($total_leads); ?></h2>
                </div>
                <i class="fas fa-users stat-box-icon" aria-hidden="true"></i>
            </div>
            <div class="stat-box stat-green">
                <div class="stat-box-content">
                    <h4>Dialed / Completed</h4>
                    <h2><?php echo number_format($dialed_leads); ?></h2>
                </div>
                <i class="fas fa-check-circle stat-box-icon" aria-hidden="true"></i>
            </div>
            <div class="stat-box stat-amber">
                <div class="stat-box-content">
                    <h4>Remaining / Left</h4>
                    <h2><?php echo number_format($remaining_leads); ?></h2>
                </div>
                <i class="fas fa-hourglass-half stat-box-icon" aria-hidden="true"></i>
            </div>
            <div class="stat-box stat-rose">
                <div class="stat-box-content">
                    <h4>DNC / Bad Data</h4>
                    <h2><?php echo number_format($bad_data_leads); ?></h2>
                </div>
                <i class="fas fa-ban stat-box-icon" aria-hidden="true"></i>
            </div>
        </div>
        <?php if (!empty($status_breakdown)): ?>
            <div class="report-breakdown">
                <strong>Breakdown of Dialed Leads:</strong><br>
                <div class="report-breakdown-items">
                    <?php foreach ($status_breakdown as $status => $count): ?>
                        <span class="report-breakdown-item">
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
        <input type="text" id="import_sheet_name" class="mapping-select" placeholder="Name this sheet (e.g., Gen2, Aug Leads)..." style="margin-bottom: 15px; padding: 12px;">
        <textarea id="excel-paste-box" class="paste-area" placeholder="Click here and press Ctrl+V to paste leads from Excel..."></textarea>
    </div>

    <!-- MAPPING PREVIEW SECTION -->
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

    <!-- EXISTING LEADS SECTION -->
    <div class="dashboard-card">
        <h3 style="margin-top: 0; color: #0f172a;">Existing Leads</h3>

        <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
            <a href="?export_data=1&user_id=<?php echo $selected_user; ?>&tab=<?php echo $active_tab; ?>" class="btn-import" id="exportDataBtn" target="_blank" style="background:#10b981; margin-top: 0; text-decoration:none;">
                <i class="fas fa-file-download"></i> Download Data
            </a>
            <button type="button" id="btn-open-batch-delete" class="btn-import" style="background:#dc3545; margin-top: 0;">
                <i class="fas fa-trash-alt"></i> Batch Delete
            </button>
            <button type="button" id="btn-open-purge" class="btn-import" style="background:#991b1b; margin-top: 0;">
                <i class="fas fa-bomb"></i> Mass Purge
            </button>
        </div>

        <div style="background: #0f172a; padding: 10px; border-radius: 6px; margin-bottom: 10px; font-size: 13px; color: #cbd5e1; border: 1px solid #334155;">
            <strong>Quick Actions:</strong>
            &bull; Click a cell to select; use arrow keys to navigate.
            &bull; Press Enter or double-click to edit; start typing to replace the cell value.
        </div>

        <div class="pagination-header" style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; margin-bottom: 15px;">
            <div>Showing Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?> (<?php echo count($existing_leads); ?> rows)</div>
            <label class="search-container" for="searchInput">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="text" id="searchInput" value="<?php echo htmlspecialchars($search ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search visible rows..." autocomplete="off">
            </label>
            <nav class="pagination-controls" aria-label="Table pagination">
                <?php if ($page > 1): ?>
                    <a class="pagination-button" href="?user_id=<?php echo $selected_user; ?>&amp;tab=<?php echo htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8'); ?>&amp;page=<?php echo $page - 1; ?><?php echo isset($_GET['search']) ? '&amp;search=' . rawurlencode((string)$_GET['search']) : ''; ?><?php echo isset($_GET['status_filter']) ? '&amp;status_filter=' . rawurlencode((string)$_GET['status_filter']) : ''; ?>">Previous</a>
                <?php else: ?>
                    <span class="pagination-button disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a class="pagination-button" href="?user_id=<?php echo $selected_user; ?>&amp;tab=<?php echo htmlspecialchars($active_tab, ENT_QUOTES, 'UTF-8'); ?>&amp;page=<?php echo $page + 1; ?><?php echo isset($_GET['search']) ? '&amp;search=' . rawurlencode((string)$_GET['search']) : ''; ?><?php echo isset($_GET['status_filter']) ? '&amp;status_filter=' . rawurlencode((string)$_GET['status_filter']) : ''; ?>">Next</a>
                <?php else: ?>
                    <span class="pagination-button disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>

        <div class="excel-container" id="grid-container">
            <table class="excel-table" id="existing-leads-table">
                <thead>
                    <tr>
                        <?php
                        $table_headers = [
                            'Agent Name',
                            'Phone',
                            'Status1',
                            'Status2',
                        ];
                        if ($active_tab === 'gen') $table_headers[] = 'Notes';
                        $table_headers = array_merge($table_headers, [
                            'First Name',
                            'Last Name',
                            'Email',
                            'Address',
                            'City',
                            'State',
                            'Zip Code',
                            'DOB',
                            'Vertical',
                            'Updates',
                            'Closers',
                            'Application',
                            'Referred By',
                            'Timestamp',
                        ]);
                        foreach ($table_headers as $column_index => $header):
                        ?>
                            <th>
                                <?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?>
                                <button type="button" class="filter-toggle" aria-label="Filter <?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="false">
                                    <i class="fas fa-caret-down filter-icon" aria-hidden="true"></i>
                                </button>
                                <div class="filter-menu" hidden>
                                    <label><input type="checkbox" class="filter-select-all" checked> Select all</label>
                                    <div class="filter-options"></div>
                                    <div class="filter-actions" style="display: flex; justify-content: space-between; border-top: 1px solid rgba(56, 189, 248, 0.2); padding-top: 8px; margin-top: 8px;">
                                        <button type="button" class="filter-btn clear-btn" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer;">Clear</button>
                                        <button type="button" class="filter-btn apply-btn" style="background:#007BFF; color:white; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">Apply</button>
                                    </div>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($existing_leads)): ?>
                        <tr class="no-leads-row">
                            <td colspan="<?php echo count($table_headers); ?>" style="text-align: center;">
                                <div class="scroll-box">No leads assigned to this user yet.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($existing_leads as $lead): ?>
                            <tr id="row-<?php echo $lead['id']; ?>">
                                <td class="cell-editable" tabindex="0" data-field="agent_name">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['agent_name'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="phone_number">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['phone_number'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="status" style="font-weight: bold;">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['status'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="status2">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['status2'] ?? ''); ?></div>
                                </td>

                                <?php if ($active_tab === 'gen'): ?>
                                    <td class="cell-editable" tabindex="0" data-field="notes">
                                        <div class="scroll-box"><?php echo htmlspecialchars($lead['notes'] ?? ''); ?></div>
                                    </td>
                                <?php endif; ?>

                                <td class="cell-editable" tabindex="0" data-field="first_name">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['first_name'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="last_name">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['last_name'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="email">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['email'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="address">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['address'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="city">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['city'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="state">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['state'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="zip_code">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="dob">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['dob'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="vertical">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['vertical'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="old_updates">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['old_updates'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="previous_closers">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['previous_closers'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="application_text">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['application_text'] ?? ''); ?></div>
                                </td>
                                <td class="cell-editable" tabindex="0" data-field="referred_by">
                                    <div class="scroll-box"><?php echo htmlspecialchars($lead['referred_by'] ?? ''); ?></div>
                                </td>
                                <td class="locked-cell" tabindex="0" style="color:#94a3b8; font-size:11px;">
                                    <div class="scroll-box"><?php echo date('m-d-Y H:i', strtotime($lead['updated_at'] ?? $lead['created_at'] ?? 'now')); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BATCH DELETE MODAL -->
    <div id="batchDeleteModal" class="modal-overlay">
        <div class="modal-content" style="width: 450px;">
            <h3 style="color: #991b1b;"><i class="fas fa-trash-alt"></i> Batch Delete Leads</h3>
            <p style="font-size: 13px; color: #475569; margin-bottom: 20px;">Select a batch of leads to permanently delete for this user.</p>
            <div style="text-align: left; margin-bottom: 20px;">
                <select id="batchSelect" class="mapping-select" style="margin-top: 8px; padding: 10px;">
                    <?php
                    $batch_q = $conn->query("SELECT import_batch_id, sheet_name, COUNT(*) as count FROM $table_name WHERE assigned_user_id = $selected_user AND import_batch_id IS NOT NULL GROUP BY import_batch_id, sheet_name ORDER BY MAX(id) DESC");
                    if ($batch_q && $batch_q->num_rows > 0) {
                        while ($b = $batch_q->fetch_assoc()) {
                            echo "<option value=\"" . htmlspecialchars($b['import_batch_id']) . "\">" . htmlspecialchars($b['sheet_name']) . " (" . $b['count'] . " leads) - Imported: " . htmlspecialchars($b['import_batch_id']) . "</option>";
                        }
                    } else {
                        echo "<option value=''>No specific imports found</option>";
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
                <select id="purgeStatusSelect" class="mapping-select" style="margin-top: 5px;">
                    <option value="ALL"> ALL LEADS (Entire User Queue)</option>
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
        // ==========================================
        // BATCH DELETE & PURGE LOGIC
        // ==========================================
        let isDataDownloaded = false;
        document.getElementById('exportDataBtn')?.addEventListener('click', function() {
            isDataDownloaded = true;
        });

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
            if (!isDataDownloaded && !confirm("WARNING: You have not downloaded the Time Data yet! Delete anyway?")) return;
            if (!confirm("Are you sure you want to permanently delete this batch of leads?")) return;

            this.innerText = "Processing...";
            this.disabled = true;
            const formData = new URLSearchParams();
            formData.append('ajax_action', 'delete_batch');
            formData.append('user_id', userId);
            formData.append('batch_id', offset);

            fetch(window.location.href, {
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
                });
        });

        document.getElementById('btn-open-purge')?.addEventListener('click', function() {
            document.getElementById('purgeModal').style.display = 'flex';
        });

        document.getElementById('btn-confirm-purge')?.addEventListener('click', function() {
            const purgeStatus = document.getElementById('purgeStatusSelect').value;
            const userId = "<?php echo $selected_user; ?>";
            if (!isDataDownloaded && !confirm("WARNING: You have not downloaded the Time Data yet! Delete anyway?")) return;
            if (!confirm(`Are you sure you want to delete ${purgeStatus} leads for this user?`)) return;

            this.innerText = "Processing...";
            this.disabled = true;
            const formData = new URLSearchParams();
            formData.append('ajax_action', 'mass_purge');
            formData.append('user_id', userId);
            formData.append('purge_status', purgeStatus);

            fetch(window.location.href, {
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
                });
        });

        // ==========================================
        // EXCEL-LIKE EDITING ENGINE
        // ==========================================
        let isEditing = false;
        let currentEditCell = null;
        const table = document.getElementById('existing-leads-table');
        const gridContainer = document.getElementById('grid-container');

        if (table) {
            table.addEventListener('mousedown', (e) => {
                const cell = e.target.closest('.cell-editable, .locked-cell');
                if (!cell) return;
                if (isEditing && currentEditCell !== cell) finishEditing(currentEditCell);
                if (!isEditing) selectCell(cell);
            });

            table.addEventListener('dblclick', (e) => {
                const cell = e.target.closest('.cell-editable');
                if (cell) enterEditMode(cell);
            });
        }

        function selectCell(cell) {
            table.querySelectorAll('.cell-selected').forEach(selected => selected.classList.remove('cell-selected'));
            cell.classList.add('cell-selected');
            cell.focus({
                preventScroll: true
            });
        }

        function moveSelection(cell, direction) {
            const rows = Array.from(table.tBodies[0].rows).filter(row => !row.hidden && !row.classList.contains('no-leads-row'));
            const rowIndex = rows.indexOf(cell.parentElement);
            let nextCell = null;

            if (direction === 'ArrowLeft' || direction === 'ArrowRight') {
                let sibling = direction === 'ArrowLeft' ? cell.previousElementSibling : cell.nextElementSibling;
                while (sibling && !sibling.matches('.cell-editable, .locked-cell')) {
                    sibling = direction === 'ArrowLeft' ? sibling.previousElementSibling : sibling.nextElementSibling;
                }
                nextCell = sibling;
            } else {
                const nextRowIndex = rowIndex + (direction === 'ArrowDown' ? 1 : -1);
                const nextRow = rows[nextRowIndex];
                const candidate = nextRow && nextRow.cells[cell.cellIndex];
                if (candidate && candidate.matches('.cell-editable, .locked-cell')) nextCell = candidate;
            }

            if (!nextCell) return;
            selectCell(nextCell);

            const cellRect = nextCell.getBoundingClientRect();
            const containerRect = gridContainer.getBoundingClientRect();
            const headerBottom = table.tHead.getBoundingClientRect().bottom;
            if (cellRect.bottom > containerRect.bottom) {
                gridContainer.scrollTop += cellRect.bottom - containerRect.bottom + 20;
            } else if (cellRect.top < headerBottom) {
                gridContainer.scrollTop -= headerBottom - cellRect.top + 12;
            }
            if (cellRect.right > containerRect.right) {
                gridContainer.scrollLeft += cellRect.right - containerRect.right + 20;
            } else if (cellRect.left < containerRect.left) {
                gridContainer.scrollLeft -= containerRect.left - cellRect.left + 20;
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.target.closest('#searchInput, .filter-menu')) return;

            if (isEditing) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    finishEditing(currentEditCell);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    finishEditing(currentEditCell, true);
                }
                return;
            }

            if (e.target.matches('input, select, textarea, button')) return;

            const selectedCell = table && table.querySelector('.cell-selected');
            if (!selectedCell) return;

            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                moveSelection(selectedCell, e.key);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedCell.classList.contains('cell-editable')) enterEditMode(selectedCell);
            } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && selectedCell.classList.contains('cell-editable')) {
                e.preventDefault();
                enterEditMode(selectedCell, e.key);
            }
        });

        function placeCaretAtEnd(node) {
            const range = document.createRange();
            range.selectNodeContents(node);
            range.collapse(false);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }

        function enterEditMode(cell, initialText = null) {
            if (!cell || !cell.classList.contains('cell-editable')) return;
            if (isEditing && currentEditCell !== cell) finishEditing(currentEditCell);

            const targetNode = cell.querySelector('.scroll-box');
            if (!targetNode) return;

            isEditing = true;
            currentEditCell = cell;
            cell.classList.add('cell-selected', 'cell-editing');
            cell.dataset.originalValue = targetNode.textContent.trim();
            targetNode.setAttribute('contenteditable', 'true');
            targetNode.focus();

            if (initialText !== null) targetNode.textContent = initialText;
            placeCaretAtEnd(targetNode);
            targetNode.addEventListener('blur', () => {
                if (isEditing && currentEditCell === cell) finishEditing(cell);
            }, {
                once: true
            });
        }

        function finishEditing(cell, cancel = false) {
            if (!isEditing || !cell || cell !== currentEditCell) return;

            const targetNode = cell.querySelector('.scroll-box');
            const originalValue = cell.dataset.originalValue ?? '';
            const newValue = cancel ? originalValue : targetNode.textContent.trim();
            targetNode.textContent = newValue;
            targetNode.removeAttribute('contenteditable');
            cell.classList.remove('cell-editing');
            isEditing = false;
            currentEditCell = null;

            if (!cancel && newValue !== originalValue) {
                updateLeadDatabase(cell.parentElement.id.replace('row-', ''), cell.dataset.field, newValue);
            }
        }

        function updateLeadDatabase(leadId, fieldName, newValue) {
            const formData = new URLSearchParams();
            formData.append('ajax_action', 'update_lead');
            formData.append('lead_id', leadId);
            formData.append('field', fieldName);
            formData.append('value', newValue);
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            }).then(response => response.text().then(text => {
                if (!response.ok || text.trim() !== 'Success') {
                    throw new Error(text.trim() || `HTTP ${response.status}`);
                }
            })).catch(error => {
                alert('Failed to save the cell: ' + error.message);
            });
        }

        if (table && gridContainer) {
            const searchInput = document.getElementById('searchInput');
            const filterStates = new Map();
            const dataRows = Array.from(table.tBodies[0].rows).filter(row => !row.classList.contains('no-leads-row'));

            function getCellValue(row, columnIndex) {
                const cell = row.cells[columnIndex];
                const scrollBox = cell && cell.querySelector('.scroll-box');
                return (scrollBox ? scrollBox.textContent : cell ? cell.textContent : '').trim();
            }

            table.querySelectorAll('thead th').forEach((header, columnIndex) => {
                const menu = header.querySelector('.filter-menu');
                const toggle = header.querySelector('.filter-toggle');
                const options = menu.querySelector('.filter-options');
                const values = Array.from(new Set(dataRows.map(row => getCellValue(row, columnIndex)))).sort((a, b) => a.localeCompare(b));
                const selectedValues = new Set(values);
                filterStates.set(columnIndex, null);

                values.forEach(value => {
                    const label = document.createElement('label');
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.checked = true;
                    checkbox.value = value;
                    checkbox.addEventListener('change', () => {
                        if (checkbox.checked) selectedValues.add(value);
                        else selectedValues.delete(value);
                        const selectAll = menu.querySelector('.filter-select-all');
                        selectAll.checked = selectedValues.size === values.length;
                        selectAll.indeterminate = selectedValues.size > 0 && selectedValues.size < values.length;
                    });
                    label.append(checkbox, document.createTextNode(value || '(Blank)'));
                    options.appendChild(label);
                });

                const selectAll = menu.querySelector('.filter-select-all');
                selectAll.addEventListener('change', () => {
                    selectedValues.clear();
                    if (selectAll.checked) values.forEach(value => selectedValues.add(value));
                    selectAll.indeterminate = false;
                    options.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        checkbox.checked = selectAll.checked;
                    });
                });

                menu.querySelector('.apply-btn').addEventListener('click', () => {
                    filterStates.set(columnIndex, selectedValues.size === 0 || selectedValues.size === values.length ? null : new Set(selectedValues));
                    applyTableFilters();
                    menu.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                });

                menu.querySelector('.clear-btn').addEventListener('click', () => {
                    selectedValues.clear();
                    options.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    const selectAll = menu.querySelector('.filter-select-all');
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    filterStates.set(columnIndex, null);
                    applyTableFilters();
                });

                toggle.addEventListener('click', () => {
                    const isOpening = menu.hidden;
                    table.querySelectorAll('.filter-menu').forEach(openMenu => {
                        openMenu.hidden = true;
                        openMenu.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                    menu.hidden = !isOpening;
                    toggle.setAttribute('aria-expanded', String(isOpening));
                });
            });

            function applyTableFilters() {
                const query = searchInput.value.trim().toLocaleLowerCase();
                dataRows.forEach(row => {
                    const matchesSearch = row.textContent.toLocaleLowerCase().includes(query);
                    const matchesColumns = Array.from(filterStates.entries()).every(([columnIndex, selectedValues]) => {
                        return selectedValues === null || selectedValues.has(getCellValue(row, columnIndex));
                    });
                    row.hidden = !matchesSearch || !matchesColumns;
                });

                const selectedCell = table.querySelector('.cell-selected');
                if (selectedCell && selectedCell.parentElement.hidden) selectedCell.classList.remove('cell-selected');
            }

            let searchTimeout;
            searchInput.addEventListener('input', () => {
                applyTableFilters();
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const url = new URL(window.location.href);
                    const query = searchInput.value.trim();
                    if (query) url.searchParams.set('search', query);
                    else url.searchParams.delete('search');
                    url.searchParams.set('page', '1');
                    window.location.assign(url.toString());
                }, 300);
            });

            document.addEventListener('click', e => {
                if (e.target.closest('.filter-toggle, .filter-menu')) return;
                table.querySelectorAll('.filter-menu').forEach(menu => {
                    menu.hidden = true;
                    menu.previousElementSibling.setAttribute('aria-expanded', 'false');
                });
            });
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
                    currentRow.push(currentCell);
                    currentCell = '';
                } else if (char === '\n' && !inQuotes) {
                    currentRow.push(currentCell);
                    if (currentRow.some(cell => cell.trim() !== '')) rows.push(currentRow);
                    currentRow = [];
                    currentCell = '';
                } else {
                    currentCell += char;
                }
            }
            if (currentCell || currentRow.length > 0) {
                currentRow.push(currentCell);
                if (currentRow.some(cell => cell.trim() !== '')) rows.push(currentRow);
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
            for (let r = 0; r < Math.min(5, fullPastedData.length); r++) {
                let tr = document.createElement('tr');
                for (let c = 0; c < maxCols; c++) {
                    let td = document.createElement('td');
                    td.innerText = fullPastedData[r][c] !== undefined ? fullPastedData[r][c].substring(0, 30) : '';
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
        });

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
            const sheetName = document.getElementById('import_sheet_name').value.trim() || 'Default';
            formData.append('sheet_name', sheetName);
            formData.append('leads', JSON.stringify(mappedData));

            fetch(window.location.href, {
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
                        document.getElementById('btn-confirm-import').style.display = 'inline-block';
                        document.getElementById('btn-cancel-import').style.display = 'inline-block';
                        document.getElementById('loading-indicator').style.display = 'none';
                    }
                });
        });
    </script>

<?php endif; ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const reportPre = document.getElementById('combinedReportContent');
        if (reportPre) {
            reportPre.addEventListener('dblclick', function() {
                this.classList.toggle('fullscreen-report-active');
                if (this.classList.contains('fullscreen-report-active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            });
        }
    });
</script>
<?php include 'includes/footer.php'; ?>