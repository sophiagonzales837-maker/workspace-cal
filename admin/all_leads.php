<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');

if (!function_exists('getWebPath')) {
    function getWebPath($db_path)
    {
        if (empty(trim((string)$db_path))) return '';
        $paths = explode(',', $db_path);
        $urls = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if (empty($path)) continue;
            if (strpos($path, 'http') !== 0) $urls[] = 'https://consumerhealthcarecompliance.com/' . ltrim($path, '/');
            else $urls[] = $path;
        }
        return implode(',', $urls);
    }
}

$new_cols = [
    'closer_status' => "VARCHAR(50) DEFAULT NULL",
    'jornaya_leadid' => "VARCHAR(255) DEFAULT NULL",
    'trustedform_cert_url' => "VARCHAR(255) DEFAULT NULL",
    'ip_address' => "VARCHAR(100) DEFAULT NULL"
];
foreach ($new_cols as $col => $def) {
    $check = $conn->query("SHOW COLUMNS FROM leads LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE leads ADD COLUMN $col $def");
    }
}

// FILTER: '1=1' means we pull ALL leads for this page
$where = "1=1";

function getStatusRowClass($status)
{
    $parts = array_values(array_filter(array_map('trim', preg_split('/\/+/', (string) $status)), 'strlen'));
    $normalized = strtoupper(end($parts) ?: '');
    $normalized = str_replace('DOCUMENTS UPLOADED', 'DOC', $normalized);
    $normalized = str_replace('DOC SIGN', 'DOC', $normalized);
    if ($normalized === 'DOC') return 'status-row-doc';
    if ($normalized === 'DNQ') return 'status-row-dnq';
    if ($normalized === 'APPROVED') return 'status-row-approved';
    if ($normalized === 'PAID') return 'status-row-paid';
    if ($normalized === 'DSP') return 'status-row-dsp';
    return '';
}

if (isset($_GET['action']) && $_GET['action'] == 'fetch_chat') {
    $lead_id = (int)$_GET['lead_id'];
    $msg_query = mysqli_query($conn, "SELECT m.content, m.created_at, u.username, u.role 
          FROM messages m JOIN users u ON m.sender_id = u.id 
          WHERE m.lead_id = '$lead_id' ORDER BY m.created_at ASC");
    $messages = [];
    while ($msg = mysqli_fetch_assoc($msg_query)) {
        $messages[] = [
            'content' => nl2br(htmlspecialchars($msg['content'])),
            'created_at' => date('M d, H:i', strtotime($msg['created_at'])),
            'username' => htmlspecialchars($msg['username']),
            'role' => htmlspecialchars($msg['role'])
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

// ========================================================================
// CRITICAL FIX: SERVER-SIDE FILTER & BLAZING FAST SEARCH ENGINE
// ========================================================================
if (isset($_GET['action']) && in_array($_GET['action'], ['load_more', 'fetch_fresh'])) {
    $offset = ($_GET['action'] == 'load_more') ? (int)$_GET['offset'] : 0;
    $search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
    $col_filters = isset($_GET['col_filters']) ? json_decode($_GET['col_filters'], true) : [];

    $is_sql_match = false;
    $dynamic_where = $where;

    $encrypted_headers = ['first_name', 'last_name', 'customer_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'client_name', 'alternate_details', 'application_text', 'questions'];
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
                    case 'status':
                        $sql_col = 'l.status';
                        break;
                    case 'closer_status':
                        $sql_col = 'l.closer_status';
                        break;
                    case 'agent_name':
                        $sql_col = '(SELECT username FROM users WHERE id = l.agent_id)';
                        break;
                    case 'closer_name':
                        $sql_col = '(SELECT username FROM users WHERE id = l.closer_id)';
                        break;
                    case 'verifier_name':
                        $sql_col = '(SELECT username FROM users WHERE id = l.verifier_id)';
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
                    case 'payouts':
                        $sql_col = 'l.payouts';
                        break;
                    case 'incentive':
                        $sql_col = 'l.incentive';
                        break;
                }
                if ($sql_col !== '') {
                    $or_cond = [];
                    foreach ($values as $v) {
                        $v_esc = mysqli_real_escape_string($conn, $v);
                        $or_cond[] = "$sql_col LIKE '%$v_esc%'";
                    }
                    $dynamic_where .= " AND (" . implode(' OR ', $or_cond) . ")";
                }
            }
        }
    }

    if ($search !== '') {
        $search_esc = mysqli_real_escape_string($conn, $search);
        $fast_check = "SELECT l.id FROM leads l WHERE $dynamic_where AND (
            l.vertical LIKE '%$search_esc%' OR l.status LIKE '%$search_esc%' OR l.closer_status LIKE '%$search_esc%' OR 
            l.jornaya_leadid LIKE '%$search_esc%' OR (SELECT username FROM users WHERE id = l.closer_id) LIKE '%$search_esc%' OR 
            (SELECT username FROM users WHERE id = l.agent_id) LIKE '%$search_esc%' OR (SELECT username FROM users WHERE id = l.verifier_id) LIKE '%$search_esc%'
        ) LIMIT 1";

        $check_res = $conn->query($fast_check);
        if ($check_res && $check_res->num_rows > 0) {
            $is_sql_match = true;
            $dynamic_where .= " AND (
                l.vertical LIKE '%$search_esc%' OR l.status LIKE '%$search_esc%' OR l.closer_status LIKE '%$search_esc%' OR 
                l.jornaya_leadid LIKE '%$search_esc%' OR (SELECT username FROM users WHERE id = l.closer_id) LIKE '%$search_esc%' OR 
                (SELECT username FROM users WHERE id = l.agent_id) LIKE '%$search_esc%' OR (SELECT username FROM users WHERE id = l.verifier_id) LIKE '%$search_esc%'
            )";
        }
    }

    $query = "SELECT l.*, 
             (SELECT username FROM users WHERE id = l.agent_id) as agent_username,
             (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
             (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username
             FROM leads l WHERE $dynamic_where ORDER BY l.updated_at DESC";

    if (($search === '' || $is_sql_match) && !$requires_php_filter) {
        $query .= " LIMIT 20 OFFSET ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $offset);
        $stmt->execute();
        $leads = $stmt->get_result();
    } else {
        $query .= " LIMIT 1500";
        $leads = mysqli_query($conn, $query);
    }

    $html = '';
    $match_count = 0;
    $skipped = 0;
    while ($row = mysqli_fetch_assoc($leads)) {
        $row = decrypt_fields($row);

        if ($requires_php_filter) {
            $passes_filter = true;
            foreach ($php_filters as $hdr => $allowed_values) {
                $cell_val = strtolower(trim(preg_replace('/[\n\r]+|[\s]{2,}/', ' ', $row[$hdr] ?? '')));
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
            $searchable_text = strtolower(implode(' ', array_values($row)));
            if (strpos($searchable_text, $search) === false) continue;
        }

        if (($search !== '' || $requires_php_filter) && $skipped < $offset) {
            $skipped++;
            continue;
        }

        $row_class = getStatusRowClass($row['status'] ?? '');
        $un_in_q = $conn->query("SELECT COUNT(*) as unread FROM inhouse_chats WHERE lead_id='{$row['id']}' AND sender_type='Closer' AND is_read=0");
        $unread_inhouse = $un_in_q ? $un_in_q->fetch_assoc()['unread'] : 0;

        // APPLIED getWebPath() DIRECTLY HERE
        $front_id = getWebPath($row['id_front_path'] ?? '');
        $back_id = getWebPath($row['id_back_path'] ?? '');
        $add_records = getWebPath($row['additional_records'] ?? '');

        ob_start();
?>
        <tr class="<?php echo htmlspecialchars($row_class); ?>" id="row-<?php echo $row['id']; ?>">
            <td style="text-align: center; vertical-align: middle;">
                <button type="button" class="btn inhouse-chat-btn" data-lead-id="<?php echo $row['id']; ?>" style="position: relative; background-color: #17a2b8; color: white; border: none; padding: 6px 12px; font-size: 11px; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="openInhouseChat(<?php echo $row['id']; ?>, <?php echo $row['closer_id'] ?? 0; ?>, '<?php echo htmlspecialchars(addslashes($row['closer_username'] ?? 'Closer')); ?>')">
                    <i class="fas fa-comments"></i> Admin
                    <span id="inhouse-badge-<?php echo $row['id']; ?>" style="position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; border: 2px solid white; line-height: 1; display: <?php echo ($unread_inhouse > 0) ? 'block' : 'none'; ?>;">
                        <?php echo $unread_inhouse; ?>
                    </span>
                </button>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="vertical">
                <div class="scroll-box"><?php echo htmlspecialchars($row['vertical'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="first_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['first_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="last_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['last_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="phone">
                <div class="scroll-box"><?php echo htmlspecialchars($row['phone'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="email">
                <div class="scroll-box"><?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-wide" data-id="<?php echo $row['id']; ?>" data-col="address">
                <div class="scroll-box"><?php echo htmlspecialchars($row['address'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="city">
                <div class="scroll-box"><?php echo htmlspecialchars($row['city'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-mini" data-id="<?php echo $row['id']; ?>" data-col="state">
                <div class="scroll-box"><?php echo htmlspecialchars($row['state'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="zip_code">
                <div class="scroll-box"><?php echo htmlspecialchars($row['zip_code'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="dob">
                <div class="scroll-box"><?php echo htmlspecialchars($row['dob'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="client_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['client_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="closer_status">
                <div class="scroll-box"><?php echo htmlspecialchars($row['closer_status'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="status">
                <div class="scroll-box"><?php echo htmlspecialchars($row['status'] ?? ''); ?></div>
            </td>

            <td class="app-col"><textarea class="app-textarea autosave-val scroll-box" data-id="<?php echo $row['id']; ?>" data-col="application_text"><?php echo htmlspecialchars($row['application_text'] ?? ''); ?></textarea></td>

            <td contenteditable="true" class="cell-editable col-wide" data-id="<?php echo $row['id']; ?>" data-col="alternate_details">
                <div class="scroll-box"><?php echo htmlspecialchars($row['alternate_details'] ?? ''); ?></div>
            </td>

            <td style="text-align: center; vertical-align: middle;">
                <button type="button" class="btn" style="background: #f59e0b; padding: 6px 12px; font-size: 11px; font-weight: bold; color: white; border: none; border-radius: 4px; cursor: pointer;" onclick="openProofsModal('<?php echo htmlspecialchars(addslashes($front_id)); ?>', '<?php echo htmlspecialchars(addslashes($back_id)); ?>', '<?php echo htmlspecialchars(addslashes($add_records)); ?>')">
                    <i class="fas fa-id-card"></i> View Proofs
                </button>
            </td>

            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="agent_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['agent_username'] ?? $row['agent_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="referred_by">
                <div class="scroll-box"><?php echo htmlspecialchars($row['referred_by'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="closer_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['closer_username'] ?? $row['closer_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="verifier_name">
                <div class="scroll-box"><?php echo htmlspecialchars($row['verifier_username'] ?? $row['verifier_name'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="jornaya_leadid">
                <div class="scroll-box"><?php echo htmlspecialchars($row['jornaya_leadid'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="trustedform_cert_url">
                <div class="scroll-box"><?php echo htmlspecialchars($row['trustedform_cert_url'] ?? ''); ?></div>
            </td>
            <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="ip_address">
                <div class="scroll-box"><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></div>
            </td>

            <td contenteditable="false" class="cell-editable finance-cell col-narrow" data-id="<?php echo $row['id']; ?>" data-col="payouts" data-real-value="<?php echo htmlspecialchars($row['payouts'] ?? ''); ?>">
                <div class="scroll-box">****</div>
            </td>
            <td contenteditable="false" class="cell-editable finance-cell col-narrow" data-id="<?php echo $row['id']; ?>" data-col="incentive" data-real-value="<?php echo htmlspecialchars($row['incentive'] ?? ''); ?>">
                <div class="scroll-box">****</div>
            </td>

            <td style="color: #888; font-size: 11px; vertical-align: middle;"><?php echo date('m/d/y h:ia', strtotime($row['updated_at'] ?? $row['created_at'])); ?></td>
        </tr>
<?php
        $html .= ob_get_clean();

        $match_count++;
        if (($search !== '' || $is_sql_match || $requires_php_filter) && $match_count >= 20) break;
    }
    echo json_encode(['html' => $html]);
    exit;
}

include 'includes/header.php';

// Fetch Initial 20 Leads
$query = "SELECT l.*, 
          (SELECT username FROM users WHERE id = l.agent_id) as agent_username,
          (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
          (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username
          FROM leads l WHERE $where ORDER BY l.updated_at DESC LIMIT 20";
$stmt = $conn->prepare($query);
$stmt->execute();
$leads = $stmt->get_result();
$stmt->close();
?>

<style>
    .excel-container {
        width: 100%;
        max-height: calc(100vh - 260px);
        overflow: auto;
        border: 1px solid rgba(56, 189, 248, 0.2);
        background: rgba(15, 23, 42, 0.82);
        border-radius: 8px;
    }

    .leads-toolbar {
        background: rgba(15, 23, 42, 0.82);
        border: 1px solid rgba(56, 189, 248, 0.2);
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.28);
    }

    .leads-search-box {
        background: #0b1220;
    }

    .excel-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        font-size: 13px;
        white-space: nowrap;
    }

    .excel-table th,
    .excel-table td {
        border: 1px solid rgba(56, 189, 248, 0.18);
        padding: 6px 8px;
        text-align: left;
        vertical-align: top;
        max-width: 300px;
    }

    .excel-table th {
        background: #0f172a;
        position: sticky;
        top: 0;
        z-index: 2;
        box-shadow: 0 1px 0 rgba(56, 189, 248, 0.24);
        color: #f8fafc;
        user-select: none;
        vertical-align: middle;
        padding: 10px;
    }

    .excel-table tr:hover {
        background-color: rgba(56, 189, 248, 0.1);
        color: #f8fafc;
    }

    .excel-table tbody tr:nth-child(even) td {
        background: rgba(30, 41, 59, 0.4) !important;
        color: #f8fafc !important;
    }

    .excel-table tbody tr:hover td {
        background: rgba(56, 189, 248, 0.1) !important;
        color: #f8fafc !important;
    }

    .excel-table td.cell-editable:hover {
        background: rgba(56, 189, 248, 0.1) !important;
        color: #f8fafc !important;
    }

    .col-wide {
        max-width: 300px !important;
        min-width: 200px;
    }

    .col-medium {
        max-width: 180px !important;
        min-width: 120px;
    }

    .col-narrow {
        max-width: 100px !important;
    }

    .scroll-box {
        max-height: 65px;
        overflow-y: auto;
        overflow-x: hidden;
        white-space: pre-wrap;
        word-wrap: break-word;
        width: 100%;
        outline: none;
        font-size: 13px;
    }

    .scroll-box::-webkit-scrollbar {
        width: 5px;
    }

    .scroll-box::-webkit-scrollbar-track {
        background: transparent;
    }

    .scroll-box::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
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
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(56, 189, 248, 0.24);
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.46);
        z-index: 50;
        padding: 10px;
        color: #f8fafc;
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
        border: 1px solid rgba(56, 189, 248, 0.24);
        border-radius: 4px;
        margin-bottom: 10px;
        font-size: 13px;
        box-sizing: border-box;
        outline: none;
        background: #0b1220;
        color: #f8fafc;
    }

    .filter-search::placeholder,
    #search-input::placeholder {
        color: #94a3b8;
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
        background: rgba(56, 189, 248, 0.1);
        color: #f8fafc;
    }

    .filter-actions {
        display: flex;
        justify-content: space-between;
        border-top: 1px solid rgba(56, 189, 248, 0.2);
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

    .cell-editable {
        background: rgba(15, 23, 42, 0.6);
        color: #f8fafc;
        cursor: cell;
        transition: box-shadow 0.1s;
    }

    .cell-selected {
        box-shadow: inset 0 0 0 2px #38bdf8 !important;
        background-color: rgba(56, 189, 248, 0.12) !important;
        color: #f8fafc !important;
        z-index: 1;
        position: relative;
    }

    .cell-editing {
        background: #0b1220 !important;
        color: #f8fafc !important;
        box-shadow: inset 0 0 0 2px #10b981 !important;
        cursor: text;
    }

    .finance-cell {
        background: rgba(120, 53, 15, 0.24) !important;
        font-weight: bold;
        color: #fde68a !important;
    }

    .btn-chat {
        background-color: #007BFF;
        color: #FFFFFF;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-chat:hover {
        background-color: #0056b3;
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
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(56, 189, 248, 0.24);
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        width: 100%;
        max-width: 450px;
        text-align: left;
    }

    .app-col {
        min-width: 320px;
        max-width: 420px;
    }

    .app-textarea {
        width: 100%;
        min-height: 72px;
        resize: vertical;
        border: 1px solid rgba(56, 189, 248, 0.24);
        border-radius: 6px;
        background: #0b1220;
        color: #f8fafc;
        padding: 8px;
        font-family: inherit;
        font-size: 12px;
        line-height: 1.4;
    }

    .app-textarea:focus {
        border-color: var(--electric-blue);
        background: #0f172a;
        color: #f8fafc;
        outline: none;
    }

    .save-indicator {
        font-size: 11px;
        color: #10b981;
        font-weight: bold;
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(56, 189, 248, 0.2);
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

    .excel-table tbody tr.status-row-doc td {
        background-color: rgba(30, 58, 138, 0.28) !important;
        color: #dbeafe !important;
    }

    .excel-table tbody tr.status-row-dnq td {
        background-color: rgba(127, 29, 29, 0.28) !important;
        color: #fecaca !important;
    }

    .excel-table tbody tr.status-row-approved td {
        background-color: rgba(6, 78, 59, 0.3) !important;
        color: #bbf7d0 !important;
    }

    .excel-table tbody tr.status-row-paid td {
        background-color: rgba(30, 41, 59, 0.52) !important;
        color: #e2e8f0 !important;
    }

    .excel-table tbody tr.status-row-dsp td {
        background-color: rgba(120, 53, 15, 0.28) !important;
        color: #fde68a !important;
    }
</style>

<div class="leads-toolbar" style="display: flex; gap: 15px; margin-bottom: 20px; padding: 20px; border-radius: 10px; align-items: center; margin-top: 20px;">
    <div style="flex: 2;">
        <label style="margin-top: 0; font-size: 12px; font-weight: bold; color: #cbd5e1;">Search All Leads</label>
        <div class="leads-search-box" style="display: flex; align-items: center; border-radius: 24px; border: 1px solid rgba(56, 189, 248, 0.2); padding: 8px 12px;">
            <svg style="width: 16px; height: 16px; color: #9aa0a6; margin-right: 8px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            <input type="text" id="search-input" placeholder="Search entire database..." style="border: none; outline: none; font-size: 14px; font-family: inherit; background: transparent; color: #f8fafc; width: 100%;">
        </div>
    </div>
    <button onclick="toggleFinancials()" class="btn" style="background:#ffc107; color:black; font-weight:bold;">Check Payouts</button>
</div>

<div class="card" style="width: 100%; box-sizing: border-box; padding: 20px;">
    <h3 style="margin-top: 0; margin-bottom: 10px; color: var(--primary-navy);"> All Leads Database</h3>
    <div class="excel-container" id="leadsTableContainer">
        <table class="excel-table" id="masterSheet">
            <thead>
                <tr>
                    <th>Admin Chat</th>
                    <th data-filter="true" data-name="vertical">Vertical</th>
                    <th data-filter="true" data-name="first_name">First Name</th>
                    <th data-filter="true" data-name="last_name">Last Name</th>
                    <th data-filter="true" data-name="phone">Phone</th>
                    <th data-filter="true" data-name="email">Email</th>
                    <th data-filter="true" data-name="address">Address</th>
                    <th data-filter="true" data-name="city">City</th>
                    <th data-filter="true" data-name="state">State</th>
                    <th data-filter="true" data-name="zip_code">Zipcode</th>
                    <th data-filter="true" data-name="dob">DOB</th>
                    <th data-filter="true" data-name="client_name">Client</th>
                    <th data-filter="true" data-name="closer_status">Update</th>
                    <th data-filter="true" data-name="status">Status</th>
                    <th>Closers Application</th>
                    <th data-filter="true" data-name="alternate_details">Alternate Info</th>
                    <th>Proof</th>
                    <th data-filter="true" data-name="agent_name">Agent Name</th>
                    <th data-filter="true" data-name="referred_by">Reference Name</th>
                    <th data-filter="true" data-name="closer_name">Closer Name</th>
                    <th data-filter="true" data-name="verifier_name">Verifier Name</th>
                    <th data-filter="true" data-name="jornaya_leadid">Jornaya</th>
                    <th data-filter="true" data-name="trustedform_cert_url">Trusted Form</th>
                    <th data-filter="true" data-name="ip_address">IP</th>
                    <th data-filter="true" data-name="payouts">Payouts</th>
                    <th data-filter="true" data-name="incentive">Incentive</th>
                    <th data-filter="true">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($leads)):
                    $row = decrypt_fields($row);
                    $un_in_q = $conn->query("SELECT COUNT(*) as unread FROM inhouse_chats WHERE lead_id='{$row['id']}' AND sender_type='Closer' AND is_read=0");
                    $unread_inhouse = $un_in_q ? $un_in_q->fetch_assoc()['unread'] : 0;

                    // APPLIED getWebPath() DIRECTLY HERE
                    $front_id = getWebPath($row['id_front_path'] ?? '');
                    $back_id = getWebPath($row['id_back_path'] ?? '');
                    $add_records = getWebPath($row['additional_records'] ?? '');

                    $row_class = getStatusRowClass($row['status'] ?? '');
                ?>
                    <tr class="<?php echo htmlspecialchars($row_class); ?>" id="row-<?php echo $row['id']; ?>">
                        <td style="text-align: center; vertical-align: middle;">
                            <button type="button" class="btn inhouse-chat-btn" data-lead-id="<?php echo $row['id']; ?>" style="position: relative; background-color: #17a2b8; color: white; border: none; padding: 6px 12px; font-size: 11px; border-radius: 4px; cursor: pointer; font-weight: bold;" onclick="openInhouseChat(<?php echo $row['id']; ?>, <?php echo $row['closer_id'] ?? 0; ?>, '<?php echo htmlspecialchars(addslashes($row['closer_username'] ?? 'Closer')); ?>')">
                                <i class="fas fa-comments"></i> Admin
                                <span id="inhouse-badge-<?php echo $row['id']; ?>" style="position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; border: 2px solid white; line-height: 1; display: <?php echo ($unread_inhouse > 0) ? 'block' : 'none'; ?>;">
                                    <?php echo $unread_inhouse; ?>
                                </span>
                            </button>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="vertical">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['vertical'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="first_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['first_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="last_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['last_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="phone">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['phone'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="email">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-wide" data-id="<?php echo $row['id']; ?>" data-col="address">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['address'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="city">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['city'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-mini" data-id="<?php echo $row['id']; ?>" data-col="state">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['state'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="zip_code">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['zip_code'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-narrow" data-id="<?php echo $row['id']; ?>" data-col="dob">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['dob'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="client_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['client_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="closer_status">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['closer_status'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="status">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['status'] ?? ''); ?></div>
                        </td>

                        <td class="app-col"><textarea class="app-textarea autosave-val scroll-box" data-id="<?php echo $row['id']; ?>" data-col="application_text"><?php echo htmlspecialchars($row['application_text'] ?? ''); ?></textarea></td>

                        <td contenteditable="true" class="cell-editable col-wide" data-id="<?php echo $row['id']; ?>" data-col="alternate_details">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['alternate_details'] ?? ''); ?></div>
                        </td>

                        <td style="text-align: center; vertical-align: middle;">
                            <button type="button" class="btn" style="background: #f59e0b; padding: 6px 12px; font-size: 11px; font-weight: bold; color: white; border: none; border-radius: 4px; cursor: pointer;" onclick="openProofsModal('<?php echo htmlspecialchars(addslashes($front_id)); ?>', '<?php echo htmlspecialchars(addslashes($back_id)); ?>', '<?php echo htmlspecialchars(addslashes($add_records)); ?>')">
                                <i class="fas fa-id-card"></i> View Proofs
                            </button>
                        </td>

                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="agent_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['agent_username'] ?? $row['agent_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="referred_by">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['referred_by'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="closer_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['closer_username'] ?? $row['closer_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="verifier_name">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['verifier_username'] ?? $row['verifier_name'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="jornaya_leadid">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['jornaya_leadid'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="trustedform_cert_url">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['trustedform_cert_url'] ?? ''); ?></div>
                        </td>
                        <td contenteditable="true" class="cell-editable col-medium" data-id="<?php echo $row['id']; ?>" data-col="ip_address">
                            <div class="scroll-box"><?php echo htmlspecialchars($row['ip_address'] ?? ''); ?></div>
                        </td>

                        <td contenteditable="false" class="cell-editable finance-cell col-narrow" data-id="<?php echo $row['id']; ?>" data-col="payouts" data-real-value="<?php echo htmlspecialchars($row['payouts'] ?? ''); ?>">
                            <div class="scroll-box">****</div>
                        </td>
                        <td contenteditable="false" class="cell-editable finance-cell col-narrow" data-id="<?php echo $row['id']; ?>" data-col="incentive" data-real-value="<?php echo htmlspecialchars($row['incentive'] ?? ''); ?>">
                            <div class="scroll-box">****</div>
                        </td>

                        <td style="color: #888; font-size: 11px; vertical-align: middle;"><?php echo date('m/d/y h:ia', strtotime($row['updated_at'] ?? $row['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
                <?php if (mysqli_num_rows($leads) == 0): ?>
                    <tr>
                        <td colspan="28" style="text-align: center; color: #888; padding: 20px;">No leads found in this view.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="global-save-indicator" class="save-indicator">Saving...</div>

<div id="proofsModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 800px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; font-size: 18px; color: #0A2540;">Customer Proofs</h2>
            <button type="button" onclick="document.getElementById('proofsModal').style.display='none'" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        <div id="proofsContainer" style="max-height: 60vh; overflow-y: auto; text-align: center; background: #0b1220; color: #e2e8f0; padding: 15px; border-radius: 8px; border: 1px solid rgba(56, 189, 248, 0.2);">
        </div>
    </div>
</div>

<div id="verifierChatModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px; max-width: 90%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0; color: #0A2540;">Updates & Feedback</h3>
            <button type="button" onclick="closeVerifierChat()" style="background:none; border:none; font-size: 24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        <p style="color: #64748B; font-size: 13px; margin-top: 0;">Lead: <span id="verifierChatLeadName" style="font-weight: bold; color: var(--primary-navy);"></span></p>

        <div id="verifierChatMessages" style="height: 300px; overflow-y: auto; background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 15px; margin-bottom: 15px; display: flex; flex-direction: column; gap: 10px;"></div>

        <div style="display: flex; gap: 10px;">
            <input type="hidden" id="verifierChatLeadId" value="">
            <input type="text" id="verifierChatInput" placeholder="Write an update..." style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 6px; outline: none;" onkeypress="if(event.key === 'Enter') sendVerifierMessage()">
            <button onclick="sendVerifierMessage()" style="background: #28a745; color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;"><i class="fas fa-paper-plane"></i> Send</button>
        </div>
    </div>
</div>

<div id="adminChatModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px; max-width: 90%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #0A2540; color: white; padding: 12px; border-radius: 6px;">
            <h4 style="margin: 0;">Messages from Closer</h4>
            <button onclick="closeInhouseChat()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div id="adminChatMessages" style="height: 280px; overflow-y: auto; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 15px;"></div>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="adminChatInput" placeholder="Type your reply..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; outline: none;" onkeypress="if(event.key === 'Enter') sendInhouseMessage()">
            <button onclick="sendInhouseMessage()" class="btn" style="background-color: #28a745; color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Send</button>
        </div>
    </div>
</div>

<script>
    let showFinance = false;

    function toggleFinancials() {
        showFinance = !showFinance;
        let cells = document.querySelectorAll('.finance-cell');
        cells.forEach(cell => {
            let targetNode = cell.querySelector('.scroll-box') || cell;
            if (showFinance) {
                let realVal = cell.getAttribute('data-real-value') || '';
                targetNode.innerText = realVal;
                targetNode.setAttribute('contenteditable', 'true');
            } else {
                targetNode.innerText = '****';
                targetNode.setAttribute('contenteditable', 'false');
            }
        });
    }

    function openProofsModal(front, back, addRecords) {
        let html = '';

        // Removed the hardcoded '../../' so it uses the exact URL passed from PHP
        if (front) html += `<div style="margin-bottom:15px;"><strong>Front ID:</strong><br><a href="${front}" target="_blank"><img src="${front}" style="max-width:100%; max-height: 250px; border-radius:6px; margin-top:5px; border:1px solid #ccc;"></a></div>`;

        if (back) html += `<div style="margin-bottom:15px;"><strong>Back ID:</strong><br><a href="${back}" target="_blank"><img src="${back}" style="max-width:100%; max-height: 250px; border-radius:6px; margin-top:5px; border:1px solid #ccc;"></a></div>`;

        if (addRecords) {
            html += `<div style="margin-top:15px; border-top:1px solid #ccc; padding-top:15px;"><strong>Additional Records:</strong><br><div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center; margin-top:5px;">`;
            const recs = addRecords.split(',');
            recs.forEach((rec, idx) => {
                if (rec.trim() !== '') {
                    // Removed the hardcoded '../../' here as well
                    html += `<a href="${rec.trim()}" target="_blank" style="background:#17a2b8; color:white; padding:8px 15px; border-radius:4px; font-size:13px; text-decoration:none;"><i class="fas fa-file"></i> Record ${idx+1}</a>`;
                }
            });
            html += `</div></div>`;
        }

        document.getElementById('proofsContainer').innerHTML = html || '<p style="color:#888;">No proofs or records available.</p>';
        document.getElementById('proofsModal').style.display = 'flex';
    }

    // ==========================================
    // EXCEL GRID EDITING ENGINE (STABLE V3 - AUTOSAVE ALL)
    // ==========================================
    const indicator = document.getElementById('global-save-indicator');
    const tableContainer = document.getElementById('leadsTableContainer') || document.getElementById('toPostContainer') || document.querySelector('.excel-container');

    if (tableContainer) {
        // 1. Capture the original value when clicking/focusing into ANY cell or textarea
        tableContainer.addEventListener('focusin', function(e) {
            let target = e.target;

            // Check if we are inside an editable cell, a textarea, or a scroll-box
            if (target.getAttribute('contenteditable') === 'true' || target.tagName === 'TEXTAREA' || target.classList.contains('scroll-box')) {
                let cell = target.closest('td.cell-editable') || target.closest('td.app-col') || target.closest('td');
                if (!cell) return;

                // Highlight the active cell
                document.querySelectorAll('.cell-selected').forEach(c => c.classList.remove('cell-selected'));
                cell.classList.add('cell-selected');

                // Store the original value to compare later
                if (!cell.hasAttribute('data-original-value')) {
                    let val = target.tagName === 'TEXTAREA' ? target.value : target.innerText.trim();
                    cell.setAttribute('data-original-value', val);
                }
            }
        });

        // 2. Automatically save when clicking away, tabbing out, or pressing Enter
        tableContainer.addEventListener('focusout', function(e) {
            let target = e.target;

            if (target.getAttribute('contenteditable') === 'true' || target.tagName === 'TEXTAREA' || target.classList.contains('scroll-box')) {
                let cell = target.closest('td.cell-editable') || target.closest('td.app-col') || target.closest('td');
                if (!cell) return;

                let id = cell.getAttribute('data-id');
                let col = cell.getAttribute('data-col') || cell.getAttribute('data-field');
                if (!id || !col) return;

                // Get the new text they just typed
                let newValue = target.tagName === 'TEXTAREA' ? target.value : target.innerText.trim();
                let originalValue = cell.getAttribute('data-original-value');

                // If the value actually changed, trigger the database save
                if (newValue !== originalValue) {
                    saveCellAjax(id, col, newValue, cell);
                    cell.setAttribute('data-original-value', newValue); // Update to prevent duplicate saves
                }
            }
        });

        // 3. Handle Keyboard events (Enter = Save & unfocus)
        tableContainer.addEventListener('keydown', function(e) {
            let target = e.target;

            if (e.key === 'Enter' && !e.shiftKey) {
                if (target.getAttribute('contenteditable') === 'true' || target.tagName === 'TEXTAREA' || target.classList.contains('scroll-box')) {
                    e.preventDefault();
                    target.blur(); // This natively triggers the 'focusout' event above to save the data securely
                }
            }
        });
    }

    // 4. The actual AJAX push to the server
    function saveCellAjax(id, field, value, cell) {
        if (!indicator) return;
        indicator.innerText = "Saving...";
        indicator.style.color = "#8b5cf6";
        indicator.classList.add('show');

        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('col', field);
        formData.append('val', value);
        formData.append('skip_timestamp', 'true');

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

                    // Handle payouts view formatting if active
                    if (typeof showFinance !== 'undefined' && cell && cell.classList.contains('finance-cell') && showFinance) {
                        cell.setAttribute('data-real-value', value);
                    }

                    setTimeout(() => {
                        indicator.classList.remove('show');
                    }, 2000);
                } else {
                    indicator.innerText = "Error Saving";
                    indicator.style.color = "#ef4444";
                    console.error("Server responded with:", data);
                }
            }).catch(err => {
                indicator.innerText = "Network Error";
                indicator.style.color = "#ef4444";
                console.error("AJAX Error:", err);
            });
    }
    // ==========================================
    // EXCEL-STYLE FILTERING & SEARCH
    // ==========================================
    let activeFilters = {};

    function initializeFilters() {
        const table = document.getElementById('masterSheet');
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
                let text = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                if (text !== "") uniqueValues.add(text);
            });

            if (uniqueValues.size === 0) return;

            th.style.position = 'relative';
            th.innerHTML += `
            <i class="fas fa-filter filter-icon" id="icon-col-${actualColIndex}"></i>
            <div class="filter-menu" id="menu-col-${actualColIndex}">
                <input type="text" class="filter-search" placeholder="Search filter..." onkeyup="searchFilterOptions(this)">
                <div class="filter-options-container"></div>
                <div class="filter-actions" style="display: flex; justify-content: space-between; border-top: 1px solid rgba(56, 189, 248, 0.2); padding-top: 8px; margin-top: 8px;">
                    <button type="button" class="filter-btn clear-btn" style="background:none; border:none; color:#ef4444; font-weight:bold; cursor:pointer;">Clear</button>
                    <button type="button" class="filter-btn apply-btn" style="background:#007BFF; color:white; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">Apply</button>
                </div>
            </div>
        `;

            const icon = th.querySelector('.filter-icon');
            const menu = th.querySelector('.filter-menu');
            const optionsContainer = th.querySelector('.filter-options-container');
            const applyBtn = th.querySelector('.apply-btn');
            const clearBtn = th.querySelector('.clear-btn');
            const colNameKey = th.getAttribute('data-name');

            Array.from(uniqueValues).sort().forEach(val => {
                let isChecked = (activeFilters[colNameKey] && activeFilters[colNameKey].includes(val)) ? 'checked' : '';
                optionsContainer.innerHTML += `
                <label class="filter-option">
                    <input type="checkbox" value="${val.replace(/"/g, '&quot;')}" ${isChecked}>
                    ${val}
                </label>
            `;
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

        const tbody = document.querySelector('#masterSheet tbody');
        const colCount = document.querySelector('thead tr').children.length;
        tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center; padding: 20px; color: #666; font-size: 14px;">Searching Database...</td></tr>`;

        const searchBox = document.getElementById('search-input');
        const searchTerm = searchBox ? searchBox.value.trim() : '';
        const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

        fetch(`all_leads.php?action=fetch_fresh&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
            .then(r => r.json())
            .then(data => {
                if (data.html && data.html.trim() !== '') {
                    tbody.innerHTML = data.html;
                } else {
                    tbody.innerHTML = `<tr><td colspan="${colCount}" style="text-align:center; padding: 20px; color: #888; font-size: 14px;">No matching leads found.</td></tr>`;
                }
                currentOffset = 20;
                initializeFilters();

                document.querySelectorAll('.autosave-val').forEach(textarea => {
                    textarea.addEventListener('blur', function() {
                        saveCellAjax(this.getAttribute('data-id'), this.getAttribute('data-col'), this.value);
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

    let currentOffset = 20;
    let isLoadingMore = false;
    let hasMore = true;

    function loadMoreLeads() {
        if (!hasMore || isLoadingMore) return;
        isLoadingMore = true;

        const searchBox = document.getElementById('search-input');
        const searchTerm = searchBox ? searchBox.value.trim() : '';
        const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

        fetch(`all_leads.php?action=load_more&offset=${currentOffset}&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
            .then(r => r.json())
            .then(data => {
                if (data.html.trim() === '') {
                    hasMore = false;
                } else {
                    document.querySelector('#masterSheet tbody').insertAdjacentHTML('beforeend', data.html);
                    currentOffset += 20;
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
        saveCellAjax(this.getAttribute('data-id'), this.getAttribute('data-col'), this.value);
    }

    document.getElementById('leadsTableContainer').addEventListener('scroll', function() {
        if (this.scrollTop + this.clientHeight >= this.scrollHeight - 50) {
            loadMoreLeads();
        }
    });

    // ==========================================
    // CHAT POPUPS (VERIFIER UPDATES & INHOUSE ADMIN)
    // ==========================================
    let chatRefreshInterval;

    function openVerifierChat(leadId, customerName) {
        document.getElementById('verifierChatLeadId').value = leadId;
        document.getElementById('verifierChatLeadName').innerText = customerName;
        document.getElementById('verifierChatModal').classList.add('show');
        document.getElementById('verifierChatInput').value = '';
        loadVerifierChatMessages();
        chatRefreshInterval = setInterval(loadVerifierChatMessages, 3000);
    }

    function closeVerifierChat() {
        document.getElementById('verifierChatModal').classList.remove('show');
        clearInterval(chatRefreshInterval);
    }

    function loadVerifierChatMessages() {
        const leadId = document.getElementById('verifierChatLeadId').value;
        if (!leadId) return;

        fetch(`all_leads.php?action=fetch_chat&lead_id=${leadId}`)
            .then(r => r.json())
            .then(messages => {
                const container = document.getElementById('verifierChatMessages');
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
                        div.style.borderLeft = `4px solid ${borderColor}`;
                        div.style.color = '#f8fafc';
                        div.style.padding = '10px 15px';
                        div.style.borderRadius = '8px';
                        div.style.marginBottom = '10px';
                        div.innerHTML = `<strong style='color:${senderColor};'>${msg.username} (${senderType})</strong>
                                     <span style='font-size:11px;color:#94a3b8;float:right;'>${msg.created_at}</span>
                                     <p style='margin:8px 0 0;font-size:14px;color:#e2e8f0;line-height:1.5;'>${msg.content}</p>`;
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

    function sendVerifierMessage() {
        const leadId = document.getElementById('verifierChatLeadId').value;
        const message = document.getElementById('verifierChatInput').value.trim();
        if (!message || !leadId) return;

        const formData = new URLSearchParams();
        formData.append('action', 'send_chat');
        formData.append('lead_id', leadId);
        formData.append('message', message);

        fetch('all_leads.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById('verifierChatInput').value = '';
                    loadVerifierChatMessages();
                }
            });
    }

    // INHOUSE ADMIN CHAT LOGIC
    let currentChatLeadId = null;
    let currentCloserId = null;

    function openInhouseChat(leadId, closerId, closerName) {
        if (!closerId) {
            alert('This lead has no closer assigned.');
            return;
        }
        currentChatLeadId = leadId;
        currentCloserId = closerId;
        document.getElementById('adminChatModal').classList.add('show');
        document.getElementById('adminChatInput').value = '';
        let badge = document.getElementById('inhouse-badge-' + leadId);
        if (badge) badge.style.display = 'none';
        loadInhouseMessages();
    }

    function closeInhouseChat() {
        document.getElementById('adminChatModal').classList.remove('show');
        currentChatLeadId = null;
    }

    function loadInhouseMessages() {
        if (!currentChatLeadId) return;
        fetch('../api/v1/inhouse_chat_api.php?action=get&lead_id=' + currentChatLeadId + '&closer_id=' + currentCloserId)
            .then(r => r.json())
            .then(messages => {
                const container = document.getElementById('adminChatMessages');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                const previousScrollTop = container.scrollTop;
                container.innerHTML = '';
                if (messages.length === 0) {
                    container.innerHTML = '<p style="color: #888; font-style: italic; text-align:center; margin-top:20px;">No messages yet.</p>';
                }
                messages.forEach(msg => {
                    const div = document.createElement('div');
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
                    div.style.background = bg;
                    div.style.padding = '10px 15px';
                    div.style.borderRadius = '8px';
                    div.style.marginBottom = '10px';
                    div.style.borderLeft = `4px solid ${borderColor}`;
                    div.style.color = '#f8fafc';
                    const d = new Date(msg.created_at);
                    const timeStr = Number.isNaN(d.getTime()) ? '' : d.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const sender = document.createElement('strong');
                    sender.style.color = senderColor;
                    sender.textContent = senderType;
                    const timestamp = document.createElement('span');
                    timestamp.style.cssText = 'font-size:11px; color:#94a3b8; float:right;';
                    timestamp.textContent = timeStr;
                    const body = document.createElement('p');
                    body.style.cssText = 'margin:8px 0 0; font-size:14px; color:#e2e8f0; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere;';
                    body.textContent = msg.message || msg.content || '';
                    div.append(sender, timestamp, body);
                    container.appendChild(div);
                });
                if (isScrolledToBottom) {
                    container.scrollTop = container.scrollHeight;
                } else {
                    container.scrollTop = previousScrollTop;
                }
            });
    }

    function sendInhouseMessage() {
        const message = document.getElementById('adminChatInput').value.trim();
        if (!message || !currentChatLeadId) return;
        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('lead_id', currentChatLeadId);
        formData.append('closer_id', currentCloserId);
        formData.append('message', message);
        fetch('../api/v1/inhouse_chat_api.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(res => {
            if (res.success) {
                document.getElementById('adminChatInput').value = '';
                loadInhouseMessages();
            }
        });
    }

    setInterval(() => {
        if (document.getElementById('adminChatModal').classList.contains('show')) loadInhouseMessages();
    }, 3000);

    setInterval(() => {
        if (typeof canRefresh === 'function' && canRefresh() && !isEditing) {
            const searchBox = document.getElementById('search-input');
            const searchTerm = searchBox ? searchBox.value.trim() : '';
            const filterPayload = encodeURIComponent(JSON.stringify(activeFilters));

            fetch(`all_leads.php?action=fetch_fresh&search=${encodeURIComponent(searchTerm)}&col_filters=${filterPayload}`)
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success' || data.html) {
                        const tbody = document.querySelector('#masterSheet tbody');
                        if (tbody && tbody.innerHTML.trim() !== data.html.trim()) {
                            tbody.innerHTML = data.html;
                            currentOffset = 20;
                            initializeFilters();
                        }
                    }
                })
                .catch(err => console.error('Error auto-refreshing all leads:', err));
        }
    }, 300000);
</script>

<?php include 'includes/footer.php'; ?>