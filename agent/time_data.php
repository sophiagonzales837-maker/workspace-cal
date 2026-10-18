<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// Allowed for Agents normally, and Admins via iframe
$allowed_roles = ['Agent', 'Closer', 'Verifier', 'Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    exit("Unauthorized");
}

$can_select_assigned_user = in_array($_SESSION['role'], ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'], true);
$hide_header = isset($_GET['hide_header']) ? (int)$_GET['hide_header'] : 0;
$user_id = $can_select_assigned_user && isset($_GET['user_id']) && $_GET['user_id'] > 0
    ? (int)$_GET['user_id']
    : (int)$_SESSION['user_id'];

$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'gen' ? 'gen' : 'pipeline';
$table_name = $active_tab === 'gen' ? 'time_data2' : 'time_data';

// ==========================================
// AJAX: BACKGROUND AUTO-SAVE
// ==========================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'save_cell') {
    $id = (int)($_POST['id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $target_table_name = (string)($_POST['table_name'] ?? '');
    $allowed_fields = $target_table_name === 'time_data2'
        ? ['status', 'status2', 'notes', 'first_name', 'last_name', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'vertical', 'old_updates', 'previous_closers', 'application_text', 'referred_by']
        : ['status', 'status2', 'notes', 'first_name', 'last_name', 'email', 'address', 'city', 'state', 'zip_code', 'dob'];
    if ($id <= 0 || !in_array($target_table_name, ['time_data', 'time_data2'], true) || !in_array($field, $allowed_fields, true)) {
        http_response_code(400);
        exit('Error: Invalid save request.');
    }
    $target_table = $target_table_name;
    $target_user = $can_select_assigned_user
        ? max(0, (int)($_POST['user_id'] ?? $user_id))
        : (int)$_SESSION['user_id'];
    $value = trim((string)($_POST['value'] ?? ''));

    $stmt = $conn->prepare("UPDATE $target_table SET `$field` = ? WHERE id = ? AND assigned_user_id = ?");
    if (!$stmt) {
        http_response_code(500);
        exit("SQL Error: " . $conn->error);
    }
    $stmt->bind_param("sii", $value, $id, $target_user);
    if ($stmt->execute()) {
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated === 0) {
            $permission_check = $conn->prepare("SELECT id FROM $target_table WHERE id = ? AND assigned_user_id = ?");
            if (!$permission_check) {
                http_response_code(500);
                exit('Error: Could not verify save permission.');
            }
            $permission_check->bind_param('ii', $id, $target_user);
            $permission_check->execute();
            $has_access = $permission_check->get_result()->num_rows === 1;
            $permission_check->close();
            if (!$has_access) {
                http_response_code(403);
                exit('Error: You do not have permission to update this lead.');
            }
        }
        echo 'Saved';
    } else {
        http_response_code(500);
        echo "SQL Error: " . $stmt->error;
        $stmt->close();
    }
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'save_callback') {
    $id = (int)($_POST['id'] ?? 0);
    $cb_time = (string)($_POST['cb_time'] ?? '');
    $field = (string)($_POST['field'] ?? 'status');
    $target_table_name = (string)($_POST['table_name'] ?? '');
    if ($id <= 0 || !in_array($target_table_name, ['time_data', 'time_data2'], true) || !in_array($field, ['status', 'status2'], true) || strtotime($cb_time) === false) {
        http_response_code(400);
        exit('Error: Invalid callback request.');
    }
    $target_table = $target_table_name;
    $target_user = $can_select_assigned_user
        ? max(0, (int)($_POST['user_id'] ?? $user_id))
        : (int)$_SESSION['user_id'];

    if (
        !$conn->query("ALTER TABLE $target_table ADD COLUMN IF NOT EXISTS callback_time DATETIME DEFAULT NULL") ||
        !$conn->query("ALTER TABLE $target_table ADD COLUMN IF NOT EXISTS cb_notified TINYINT(1) DEFAULT 0")
    ) {
        http_response_code(500);
        exit("SQL Error: " . $conn->error);
    }

    $formatted_time = date('Y-m-d H:i:s', strtotime($cb_time));
    $stmt = $conn->prepare("UPDATE $target_table SET callback_time = ?, `$field` = 'CB', cb_notified = 0 WHERE id = ? AND assigned_user_id = ?");
    if (!$stmt) {
        http_response_code(500);
        exit("SQL Error: " . $conn->error);
    }
    $stmt->bind_param("sii", $formatted_time, $id, $target_user);
    if ($stmt->execute()) {
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated === 0) {
            $permission_check = $conn->prepare("SELECT id FROM $target_table WHERE id = ? AND assigned_user_id = ?");
            if (!$permission_check) {
                http_response_code(500);
                exit('Error: Could not verify callback permission.');
            }
            $permission_check->bind_param('ii', $id, $target_user);
            $permission_check->execute();
            $has_access = $permission_check->get_result()->num_rows === 1;
            $permission_check->close();
            if (!$has_access) {
                http_response_code(403);
                exit('Error: You do not have permission to update this lead.');
            }
        }
        echo 'Saved';
    } else {
        http_response_code(500);
        echo "SQL Error: " . $stmt->error;
        $stmt->close();
    }
    exit;
}

// Generate Token (Only for Data 2)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'generate_token' && $active_tab === 'gen') {
    $id = (int)$_POST['id'];
    $target_user = $can_select_assigned_user && isset($_POST['user_id']) && $_POST['user_id'] > 0 ? (int)$_POST['user_id'] : $user_id;

    $stmt = $conn->prepare("SELECT email, first_name, last_name FROM time_data2 WHERE id = ? AND assigned_user_id = ?");
    $stmt->bind_param("ii", $id, $target_user);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $lead = $result->fetch_assoc();
        $email = trim($lead['email']);
        if (empty($email)) {
            exit('Error: No email address found for this lead.');
        }
        $token = bin2hex(random_bytes(16));
        $upd_stmt = $conn->prepare("UPDATE time_data2 SET upload_token = ? WHERE id = ? AND assigned_user_id = ?");
        $upd_stmt->bind_param("sii", $token, $id, $target_user);

        if ($upd_stmt->execute()) {
            $to = $email;
            $subject = "Action Required: Upload Government Issued ID & Medical Records";
            $message = "Dear " . (!empty($lead['first_name']) ? $lead['first_name'] . " " . $lead['last_name'] : "Client") . ",\n\nPlease use your secure dashboard link below to upload these documents:\nhttps://pulaskikherkherlaw.info/dashboard?token=" . $token;
            $headers = "From: cases@pulaskikherkherlaw.info\r\nReply-To: cases@pulaskikherkherlaw.info\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            if (mail($to, $subject, $message, $headers)) echo 'Success';
            else echo 'Error: Database updated, but failed to send email.';
        } else {
            echo 'Error: Database update failed.';
        }
        $upd_stmt->close();
    } else {
        echo 'Error: Lead not found.';
    }
    $stmt->close();
    exit;
}

// ==========================================
// RENDER HTML GRID
// ==========================================
if (!$hide_header) {
    include 'includes/header.php';
} else {
    echo '<link rel="stylesheet" href="../assets/style.css?v=' . time() . '">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">';
    echo '<body class="app-dark-theme" style="background: #030712; padding: 0;">';
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

$where_clause = "assigned_user_id = $user_id";
if ($search !== '') {
    $escaped_search = mysqli_real_escape_string($conn, $search);
    $where_clause .= " AND (phone_number LIKE '%$escaped_search%' OR first_name LIKE '%$escaped_search%' OR last_name LIKE '%$escaped_search%' OR email LIKE '%$escaped_search%' OR city LIKE '%$escaped_search%')";
}
if ($status_filter !== '' && $status_filter !== 'all') {
    if (strcasecmp($status_filter, 'New') === 0) {
        $where_clause .= " AND ((status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) = 'new') OR (status2 IS NULL OR TRIM(status2) = '' OR LOWER(TRIM(status2)) = 'new'))";
    } else {
        $escaped_status_filter = mysqli_real_escape_string($conn, $status_filter);
        $where_clause .= " AND (status = '$escaped_status_filter' OR status2 = '$escaped_status_filter')";
    }
}

$limit = 100;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$count_result = $conn->query("SELECT COUNT(*) AS total FROM $table_name WHERE $where_clause");
if (!$count_result) {
    throw new RuntimeException('Failed to count leads: ' . $conn->error);
}
$total_leads = (int)$count_result->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_leads / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset";
$leads_result = $conn->query($query);
if (!$leads_result) {
    throw new RuntimeException('Failed to fetch leads: ' . $conn->error);
}

$pagination_params = [
    'search' => $search,
    'status_filter' => $status_filter,
    'tab' => $active_tab,
];
if ($hide_header) {
    $pagination_params['hide_header'] = $hide_header;
    $pagination_params['user_id'] = $user_id;
}
$page_url = static function (int $target_page) use ($pagination_params): string {
    return '?' . htmlspecialchars(http_build_query($pagination_params + ['page' => $target_page]), ENT_QUOTES, 'UTF-8');
};
?>

<style>
    .search-container {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 450px;
        box-sizing: border-box;
        padding: 10px 20px;
        border: 1px solid #334155;
        border-radius: 24px;
        background: #0F172A;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .search-container:focus-within {
        border-color: var(--electric-blue);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.15);
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
        color: #F8FAFC;
        font-size: 14px;
    }

    .pagination-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin: 0 0 12px;
        color: #e2e8f0;
        font-weight: 600;
    }

    .pagination-controls {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .pagination-button {
        display: inline-block;
        padding: 8px 12px;
        border: 0;
        border-radius: 8px;
        background: var(--electric-blue);
        color: #fff;
        font-weight: 700;
        text-decoration: none;
    }

    .pagination-button:hover {
        filter: brightness(1.1);
    }

    .pagination-button.current {
        background: #1e293b;
        color: #fff;
    }

    .pagination-button.disabled {
        background: #334155;
        color: #94a3b8;
        pointer-events: none;
    }

    .excel-container {
        width: 100%;
        height: calc(100vh - <?php echo $hide_header ? '100px' : '200px'; ?>);
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

    .scroll-box::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .cell-editable {
        background: #fff;
        transition: box-shadow 0.1s;
        cursor: cell;
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

    .status-select-input {
        width: 100%;
        height: 100%;
        border: none;
        outline: none;
        background: transparent;
        font-family: inherit;
        font-size: inherit;
        cursor: pointer;
        text-align: center;
    }

    .btn-generate {
        background: #2563eb;
        color: #fff;
        border: none;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        height: 100%;
    }

    .btn-generate:hover {
        background: #1d4ed8;
    }

    .btn-docs {
        background: #f59e0b;
        color: #fff;
        border: none;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        height: 100%;
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

    .tab-nav {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 15px;
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

    .cb-modal-overlay {
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
    }

    .cb-modal-content {
        background: #ffffff;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        width: 320px;
        text-align: center;
    }
</style>

<div style="padding: <?php echo $hide_header ? '10px' : '0'; ?>;">

    <?php if (!$hide_header): ?>
        <div class="tab-nav">
            <a href="?tab=pipeline" class="tab-button <?php echo $active_tab === 'pipeline' ? 'active' : ''; ?>">Pipeline Data</a>
            <a href="?tab=gen" class="tab-button <?php echo $active_tab === 'gen' ? 'active' : ''; ?>">Gen Data</a>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0; color: #0f172a;"><?php echo $active_tab === 'gen' ? 'Gen Data (Table 2)' : 'Pipeline Data (Table 1)'; ?></h3>

        <form method="GET" style="display: flex; gap: 12px; align-items: center;">
            <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
            <?php if ($hide_header) echo '<input type="hidden" name="hide_header" value="1"><input type="hidden" name="user_id" value="' . $user_id . '">'; ?>

            <select name="status_filter" onchange="this.form.submit()" style="border: 1px solid #dadce0; border-radius: 24px; padding: 10px 18px; background: #f8f9fa; font-size: 13px; outline: none;">
                <option value="all" <?= ($status_filter == 'all') ? 'selected' : '' ?>>All Statuses</option>
                <option value="New" <?= (strcasecmp($status_filter, 'New') === 0) ? 'selected' : '' ?>>New</option>
                <option value="VM" <?= ($status_filter == 'VM') ? 'selected' : '' ?>>VM</option>
                <option value="Transfered" <?= ($status_filter == 'Transfered') ? 'selected' : '' ?>>Transfered</option>
                <option value="NI" <?= ($status_filter == 'NI') ? 'selected' : '' ?>>NI</option>
                <option value="CB" <?= ($status_filter == 'CB') ? 'selected' : '' ?>>CB</option>
            </select>
            <span id="filtered-count" role="status" aria-live="polite" title="Visible rows on this page / total leads matching the selected server filters" style="white-space: nowrap; padding: 6px 10px; border-radius: 9999px; background: #f1f5f9; color: #475569; font-size: 13px; font-weight: 600;"></span>

            <div class="search-container">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="text" id="search-input" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </form>
    </div>

    <!-- Global Save Indicator -->
    <div id="global-save-indicator" class="save-indicator">Saving...</div>

    <div class="pagination-header">
        <span>Showing Page <?= $page ?> of <?= $total_pages ?> (Total Leads: <?= $total_leads ?>)</span>
        <nav class="pagination-controls" aria-label="Lead pages">
            <?php if ($page > 1): ?>
                <a class="pagination-button" href="<?= $page_url($page - 1) ?>">Previous</a>
            <?php else: ?>
                <span class="pagination-button disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <?php
            $first_page = max(1, $page - 2);
            $last_page = min($total_pages, $page + 2);
            for ($page_number = $first_page; $page_number <= $last_page; $page_number++):
            ?>
                <a class="pagination-button <?= $page_number === $page ? 'current' : '' ?>"
                    href="<?= $page_url($page_number) ?>"
                    <?= $page_number === $page ? 'aria-current="page"' : '' ?>><?= $page_number ?></a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a class="pagination-button" href="<?= $page_url($page + 1) ?>">Next</a>
            <?php else: ?>
                <span class="pagination-button disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </nav>
    </div>

    <div class="excel-container table-container">
        <table class="excel-table" id="leadsTable">
            <thead>
                <tr>
                    <th class="col-mini">#</th>
                    <th class="col-medium">Phone Number</th>
                    <th class="col-medium">Status 1</th>
                    <th class="col-medium">Status 2</th>
                    <?php if ($active_tab === 'gen'): ?>
                        <th class="col-medium">Notes</th>
                        <th class="col-narrow">Action</th>
                    <?php endif; ?>
                    <th class="col-medium">First Name</th>
                    <th class="col-medium">Last Name</th>
                    <th class="col-medium">Email</th>
                    <th class="col-wide">Address</th>
                    <th class="col-narrow">City</th>
                    <th class="col-mini">State</th>
                    <th class="col-narrow">Zip Code</th>
                    <th class="col-narrow">DOB</th>
                    <?php if ($active_tab === 'gen'): ?>
                        <th class="col-medium">Vertical</th>
                        <th class="col-wide">Updates</th>
                        <th class="col-medium">Closers</th>
                        <th class="col-wide">Application</th>
                        <th class="col-medium">Referred By</th>
                    <?php else: ?>
                        <th class="col-wide">Notes</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $row_num = 1;
                if ($leads_result && $leads_result->num_rows > 0):
                    while ($lead = $leads_result->fetch_assoc()):
                ?>
                        <tr id="row-<?= $lead['id'] ?>">
                            <td style="color: #94a3b8;"><?= $row_num++ ?></td>
                            <td class="locked-cell col-medium" tabindex="0">
                                <div class="scroll-box"><?= htmlspecialchars($lead['phone_number'] ?? '') ?></div>
                            </td>

                            <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="status">
                                <div class="scroll-box"><?= htmlspecialchars($lead['status'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="status2">
                                <div class="scroll-box"><?= htmlspecialchars($lead['status2'] ?? '') ?></div>
                            </td>

                            <?php if ($active_tab === 'gen'): ?>
                                <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="notes">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['notes'] ?? '') ?></div>
                                </td>
                                <td class="col-narrow" style="padding: 0;">
                                    <?php if (!empty($lead['upload_token'])): ?>
                                        <button onclick="window.open('https://pulaskikherkherlaw.info/dashboard?token=<?= $lead['upload_token'] ?>')" class="btn-docs">Docs</button>
                                    <?php else: ?>
                                        <button onclick="generateToken(<?= $lead['id'] ?>)" class="btn-generate">Generate</button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                            <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="first_name">
                                <div class="scroll-box"><?= htmlspecialchars($lead['first_name'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="last_name">
                                <div class="scroll-box"><?= htmlspecialchars($lead['last_name'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="email">
                                <div class="scroll-box"><?= htmlspecialchars($lead['email'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-wide" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="address">
                                <div class="scroll-box"><?= htmlspecialchars($lead['address'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-narrow" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="city">
                                <div class="scroll-box"><?= htmlspecialchars($lead['city'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-mini" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="state">
                                <div class="scroll-box"><?= htmlspecialchars($lead['state'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-narrow" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="zip_code">
                                <div class="scroll-box"><?= htmlspecialchars($lead['zip_code'] ?? '') ?></div>
                            </td>
                            <td class="cell-editable col-narrow" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="dob">
                                <div class="scroll-box"><?= htmlspecialchars($lead['dob'] ?? '') ?></div>
                            </td>

                            <?php if ($active_tab === 'gen'): ?>
                                <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="vertical">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['vertical'] ?? '') ?></div>
                                </td>
                                <td class="cell-editable col-wide" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="old_updates">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['old_updates'] ?? '') ?></div>
                                </td>
                                <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="previous_closers">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['previous_closers'] ?? '') ?></div>
                                </td>
                                <td class="cell-editable col-wide" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="application_text">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['application_text'] ?? '') ?></div>
                                </td>
                                <td class="cell-editable col-medium" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="referred_by">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['referred_by'] ?? '') ?></div>
                                </td>
                            <?php else: ?>
                                <td class="cell-editable col-wide" tabindex="0" data-id="<?= $lead['id'] ?>" data-field="notes">
                                    <div class="scroll-box"><?= htmlspecialchars($lead['old_updates'] ?? '') ?></div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile;
                else: ?>
                    <tr class="no-leads-row">
                        <td colspan="20" style="text-align:center; padding: 30px; color: #94a3b8;">No records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- Callback Modal -->
<div id="cbModal" class="cb-modal-overlay">
    <div class="cb-modal-content">
        <h4 style="margin-top:0; color:#0f172a;"><i class="fas fa-clock"></i> Set Callback Reminder</h4>
        <input type="datetime-local" id="cbDatetimeInput" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:20px;">
        <button onclick="saveCallbackReminder()" style="background:#2563eb; color:#fff; border:none; padding:10px 15px; border-radius:6px; cursor:pointer; font-weight:bold; width:100%;">Set Reminder</button>
        <button onclick="document.getElementById('cbModal').style.display='none'" style="background:#e2e8f0; color:#475569; border:none; padding:10px 15px; border-radius:6px; cursor:pointer; font-weight:bold; width:100%; margin-top:10px;">Cancel</button>
    </div>
</div>

<script>
    let isEditing = false;
    let currentEditCell = null;
    let cbPendingData = {
        id: null,
        cell: null,
        field: null
    };
    const indicator = document.getElementById('global-save-indicator');
    const statusOptions = {
        "": "Select...",
        "VM": "VM",
        "NIS (Not In Service)": "NIS (Not In Service)",
        "Transfered": "Transfered",
        "NI": "NI",
        "Hangup": "Hangup",
        "LB (Language Barier)": "LB (Language Barier)",
        "CB": "CB",
        "Other": "Other",
        "New": "New"
    };

    // 1. Mouse Controls (1-click select, 2-click edit)
    document.querySelectorAll('.cell-editable, .locked-cell').forEach(cell => {
        cell.addEventListener('mousedown', function(e) {
            if (isEditing && currentEditCell === this) return;
            if (isEditing && currentEditCell !== this) finishEditing(currentEditCell);
            selectCell(this);
        });
        cell.addEventListener('dblclick', function(e) {
            // Only allow editing if it's an editable cell
            if (this.classList.contains('cell-editable')) {
                enterEditMode(this);
            }
        });
    });

    // 2. Keyboard Controls (Arrows, Enter, Typing)
    document.addEventListener('keydown', function(e) {
        // Don't interfere if the user is typing in the main search bar
        if (document.activeElement.tagName === 'INPUT' && !document.activeElement.classList.contains('status-select-input')) return;

        const selectedCell = document.querySelector('.cell-selected');

        // IF ACTIVELY EDITING A CELL:
        if (isEditing) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                finishEditing(currentEditCell);
            } else if (e.key === 'Escape') {
                finishEditing(currentEditCell);
            }
            return;
        }

        if (!selectedCell) return;

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && selectedCell.classList.contains('locked-cell')) {
            const textNode = selectedCell.querySelector('.scroll-box') || selectedCell;
            const range = document.createRange();
            range.selectNodeContents(textNode);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }

        // ARROW KEY GRID NAVIGATION:
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
            e.preventDefault();

            let row = selectedCell.parentElement;
            let cellIndex = Array.prototype.indexOf.call(row.children, selectedCell);
            let nextCell = null;

            if (e.key === 'ArrowRight') {
                nextCell = selectedCell.nextElementSibling;
                while (nextCell && !nextCell.matches('.cell-editable, .locked-cell')) {
                    nextCell = nextCell.nextElementSibling;
                }
            } else if (e.key === 'ArrowLeft') {
                nextCell = selectedCell.previousElementSibling;
                while (nextCell && !nextCell.matches('.cell-editable, .locked-cell')) {
                    nextCell = nextCell.previousElementSibling;
                }
            } else if (e.key === 'ArrowDown') {
                let nextRow = row.nextElementSibling;
                while (nextRow && !nextRow.matches('tr:not(.no-leads-row)')) {
                    nextRow = nextRow.nextElementSibling;
                }
                if (nextRow) nextCell = nextRow.children[cellIndex];
            } else if (e.key === 'ArrowUp') {
                let prevRow = row.previousElementSibling;
                while (prevRow && !prevRow.matches('tr:not(.no-leads-row)')) {
                    prevRow = prevRow.previousElementSibling;
                }
                if (prevRow) nextCell = prevRow.children[cellIndex];
            }

            // Move selection and smart-scroll the container
            if (nextCell && nextCell.matches('.cell-editable, .locked-cell')) {
                selectCell(nextCell);

                const container = document.querySelector('.excel-container');
                const cellRect = nextCell.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                const headerHeight = 50; // Account for the sticky table header

                // Scroll Vertically
                if (cellRect.bottom > containerRect.bottom) {
                    container.scrollTop += (cellRect.bottom - containerRect.bottom) + 20;
                } else if (cellRect.top < containerRect.top + headerHeight) {
                    container.scrollTop -= (containerRect.top + headerHeight - cellRect.top) + 20;
                }

                // Scroll Horizontally
                if (cellRect.right > containerRect.right) {
                    container.scrollLeft += (cellRect.right - containerRect.right) + 50;
                } else if (cellRect.left < containerRect.left) {
                    container.scrollLeft -= (containerRect.left - cellRect.left) + 50;
                }
            }
        }
        // PRESS ENTER TO EDIT (Like Google Sheets):
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedCell.classList.contains('cell-editable')) {
                enterEditMode(selectedCell);
            }
        }
        // START TYPING TO OVERWRITE (Like Google Sheets):
        else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            if (selectedCell.classList.contains('cell-editable')) {
                // The 'true' flag clears the cell content instantly as they start typing
                enterEditMode(selectedCell, true);
            }
        }
    });

    function selectCell(cell) {
        document.querySelectorAll('.cell-selected').forEach(c => c.classList.remove('cell-selected'));
        cell.classList.add('cell-selected');
        cell.focus();
    }

    function enterEditMode(cell, clearContent = false) {
        isEditing = true;
        currentEditCell = cell;
        cell.classList.add('cell-editing');
        const field = cell.getAttribute('data-field');
        let targetNode = cell.querySelector('.scroll-box') || cell;
        const currentValue = targetNode.innerText.trim();
        cell.setAttribute('data-original-value', currentValue);

        if (field === 'status' || field === 'status2') {
            let selectHTML = `<select class="status-select-input" onblur="finishEditing(this.parentElement)">`;
            for (const [val, label] of Object.entries(statusOptions)) {
                selectHTML += `<option value="${val}" ${val.toLowerCase() === currentValue.toLowerCase() ? 'selected' : ''}>${label}</option>`;
            }
            cell.innerHTML = selectHTML + `</select>`;
            cell.querySelector('select').focus();
        } else {
            targetNode.setAttribute('contenteditable', 'true');
            if (clearContent) targetNode.innerText = '';
            targetNode.focus();
        }
    }

    function finishEditing(cell) {
        if (!isEditing || cell !== currentEditCell) return;
        isEditing = false;
        currentEditCell = null;
        cell.classList.remove('cell-editing');

        const field = cell.getAttribute('data-field');
        const id = cell.getAttribute('data-id');
        let newValue = '';

        if (field === 'status' || field === 'status2') {
            const select = cell.querySelector('select');
            newValue = select ? select.value : cell.innerText.trim();
            if (newValue === 'CB') {
                cell.innerHTML = `<div class="scroll-box">CB</div>`;
                cbPendingData = {
                    id: id,
                    cell: cell,
                    field: field
                };
                document.getElementById('cbModal').style.display = 'flex';
                return;
            }
            cell.innerHTML = `<div class="scroll-box">${statusOptions[newValue] || newValue}</div>`;
        } else {
            let targetNode = cell.querySelector('.scroll-box') || cell;
            targetNode.removeAttribute('contenteditable');
            newValue = targetNode.innerText.trim();
        }

        if (newValue !== cell.getAttribute('data-original-value')) {
            saveCellAjax(id, field, newValue);
        }
    }

    function saveCallbackReminder() {
        const dt = document.getElementById('cbDatetimeInput').value;
        if (!dt) return alert("Select time.");
        saveCellAjax(cbPendingData.id, cbPendingData.field, 'CB', true, dt);
        document.getElementById('cbModal').style.display = 'none';
    }

    function saveCellAjax(id, field, value, isCallback = false, cbTime = '') {
        indicator.classList.add('show');
        indicator.innerText = "Saving...";
        const fd = new URLSearchParams();
        fd.append('ajax_action', isCallback ? 'save_callback' : 'save_cell');
        fd.append('table_name', '<?= $table_name ?>');
        fd.append('user_id', '<?= $user_id ?>');
        fd.append('id', id);
        fd.append('field', field);
        fd.append('value', value);
        if (isCallback) fd.append('cb_time', cbTime);

        fetch('time_data.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.text())
            .then(res => {
                if (res.trim() === 'Saved') {
                    indicator.innerText = "Saved";
                    indicator.style.color = "#10b981";
                } else {
                    indicator.innerText = "Error";
                    indicator.style.color = "#ef4444";
                }
                setTimeout(() => indicator.classList.remove('show'), 2000);
            });
    }

    function generateToken(leadId) {
        if (!confirm("Generate secure dashboard link?")) return;
        const fd = new URLSearchParams();
        fd.append('ajax_action', 'generate_token');
        fd.append('table_name', 'time_data2');
        fd.append('user_id', '<?= $user_id ?>');
        fd.append('id', leadId);

        fetch('time_data.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.text())
            .then(res => {
                if (res.trim() === 'Success') window.location.reload();
                else alert(res);
            });
    }

    const searchInput = document.getElementById('search-input');
    const filteredCount = document.getElementById('filtered-count');
    const rows = Array.from(document.querySelectorAll('#leadsTable tbody tr:not(.no-leads-row)'));
    const updateFilteredCount = function() {
        const visibleCount = rows.filter(row => row.style.display !== 'none').length;
        if (filteredCount) filteredCount.textContent = `${visibleCount} / <?= $total_leads ?> leads`;
    };
    const getRowFilterText = function(row) {
        return Array.from(row.cells).map(cell => {
            if (!cell.matches('[data-field="status"], [data-field="status2"]')) return cell.textContent;
            const statusSelect = cell.querySelector('select');
            const value = (statusSelect ? statusSelect.value : cell.textContent).trim();
            if (!value || value === '-- Select --') return 'New';
            return `${value} ${statusSelect ? statusSelect.selectedOptions[0].textContent : ''}`;
        }).join(' ').toLowerCase();
    };
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            rows.forEach(row => {
                const rowText = getRowFilterText(row);
                row.style.display = rowText.includes(searchTerm) ? '' : 'none';
            });
            updateFilteredCount();

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
    }
    updateFilteredCount();
</script>

<?php
if (!$hide_header) {
    include 'includes/footer.php';
} else {
    echo '</body>';
}
?>