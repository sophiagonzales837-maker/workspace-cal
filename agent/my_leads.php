<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Agent');

// ==========================================
// 1. NATIVE AJAX ENDPOINTS (CHAT & STATUS)
// ==========================================

// A. Fetch Chat Messages
if (isset($_GET['action']) && $_GET['action'] === 'fetch_chat') {
    header('Content-Type: application/json');
    $lead_id = (int)($_GET['lead_id'] ?? 0);

    $msg_query = mysqli_query($conn, "SELECT m.content, m.created_at, u.username, u.role 
          FROM messages m 
          JOIN users u ON m.sender_id = u.id 
          WHERE m.lead_id = '$lead_id' 
          ORDER BY m.created_at ASC");

    $messages = [];
    if ($msg_query) {
        while ($msg = mysqli_fetch_assoc($msg_query)) {
            $messages[] = [
                'content' => $msg['content'],
                'created_at' => date('M d, h:i A', strtotime($msg['created_at'])),
                'username' => $msg['username'],
                'role' => $msg['role']
            ];
        }
    }
    echo json_encode($messages);
    exit;
}

// B. Send Chat Message
if (isset($_POST['action']) && $_POST['action'] === 'send_chat') {
    header('Content-Type: application/json');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $sender_id = $_SESSION['user_id'];

    if (!empty($message) && $lead_id > 0) {
        $stmt = $conn->prepare("INSERT INTO messages (lead_id, sender_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $lead_id, $sender_id, $message);
        $stmt->execute();
        $stmt->close();

        // Notify assigned closer if one exists
        $lead_q = mysqli_query($conn, "SELECT closer_id, customer_name FROM leads WHERE id = '$lead_id' LIMIT 1");
        if ($lead_q && $lead_data = mysqli_fetch_assoc($lead_q)) {
            $closer_id = (int)($lead_data['closer_id'] ?? 0);
            $c_name = $lead_data['customer_name'] ?? 'Customer';
            if ($closer_id > 0) {
                $notif_msg = "New Agent message on lead: " . mysqli_real_escape_string($conn, $c_name);
                $notif_link = "manage_lead.php?id=" . $lead_id;
                mysqli_query($conn, "INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES ('$closer_id', '$notif_msg', '$notif_link', 0, NOW())");
            }
        }

        // Pusher Realtime Notification
        if (file_exists('../config/pusher.php')) {
            require_once '../config/pusher.php';
            if (function_exists('workspace_pusher_server')) {
                try {
                    $pusher = workspace_pusher_server();
                    if ($pusher) {
                        $pusher->trigger('lead-' . $lead_id . '-updates', 'new-message', []);
                    }
                } catch (Exception $e) {
                }
            }
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Message is empty']);
    }
    exit;
}

// C. Update Status Inline
if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $retransfers = isset($_POST['retransfer_count']) ? (int)$_POST['retransfer_count'] : null;

    if ($lead_id > 0 && !empty($status)) {
        if ($retransfers !== null) {
            $stmt = $conn->prepare("UPDATE leads SET status = ?, retransfer_count = ? WHERE id = ?");
            $stmt->bind_param("sii", $status, $retransfers, $lead_id);
        } else {
            $stmt = $conn->prepare("UPDATE leads SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $lead_id);
        }
        $updated = $stmt->execute();
        $stmt->close();
        if (!$updated) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update lead status.']);
            exit;
        }

        if ($status === 'Retransfer') {
            $lead_stmt = $conn->prepare("SELECT closer_id, customer_name FROM leads WHERE id = ? LIMIT 1");
            if (!$lead_stmt) {
                error_log('[Agent status] Failed to prepare retransfer notification lookup: ' . $conn->error);
            } else {
                $lead_stmt->bind_param("i", $lead_id);
                if (!$lead_stmt->execute()) {
                    error_log('[Agent status] Failed to look up retransfer notification recipient: ' . $lead_stmt->error);
                } else {
                    $lead_data = $lead_stmt->get_result()->fetch_assoc();
                    $closer_id = (int)($lead_data['closer_id'] ?? 0);
                    if ($closer_id > 0) {
                        $customer_name = decryptData($lead_data['customer_name'] ?? '') ?: 'Customer';
                        $notification_message = 'Lead retransferred by Agent: ' . $customer_name;
                        $notification_link = 'manage_lead.php?id=' . $lead_id;
                        $notification_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                        if (!$notification_stmt) {
                            error_log('[Agent status] Failed to prepare retransfer notification: ' . $conn->error);
                        } else {
                            $notification_stmt->bind_param("iss", $closer_id, $notification_message, $notification_link);
                            if ($notification_stmt->execute()) {
                                try {
                                    require_once '../config/pusher.php';
                                    $pusher = workspace_pusher_server();
                                    $pusher->trigger('user-' . $closer_id . '-notifications', 'new-notification', [
                                        'user_id' => $closer_id,
                                        'message' => $notification_message,
                                        'link' => $notification_link,
                                    ]);
                                } catch (Throwable $error) {
                                    error_log('[Agent status] Failed to publish retransfer notification: ' . $error->getMessage());
                                }
                            } else {
                                error_log('[Agent status] Failed to insert retransfer notification: ' . $notification_stmt->error);
                            }
                            $notification_stmt->close();
                        }
                    }
                }
                $lead_stmt->close();
            }
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// D. Save Callback
if (isset($_POST['action']) && $_POST['action'] === 'save_callback') {
    header('Content-Type: application/json');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $dt = $_POST['callback_time'] ?? '';
    $tz = $_POST['timezone'] ?? date_default_timezone_get();

    if (!empty($dt) && $lead_id > 0) {
        $date = new DateTime($dt, new DateTimeZone($tz));
        $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $server_time = $date->format('Y-m-d H:i:s');

        $conn->query("ALTER TABLE leads ADD COLUMN IF NOT EXISTS cb_notified TINYINT(1) DEFAULT 0");
        $stmt = $conn->prepare("UPDATE leads SET status = 'CB', reminder_time = ?, callback_time = ?, cb_notified = 0 WHERE id = ?");
        $stmt->bind_param("ssi", $server_time, $server_time, $lead_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

include 'includes/header.php';

$agent_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT leads.*, 
    (SELECT username FROM users WHERE id = leads.closer_id) as closer_name,
    (SELECT COUNT(id) FROM leads l2 WHERE l2.agent_id = leads.agent_id AND l2.id <= leads.id) as absolute_lead_number 
    FROM leads WHERE agent_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$leads_query = $stmt->get_result();
$stmt->close();
?>

<style>
    :root {
        --primary-navy: #F8FAFC;
        --electric-blue: #2563EB;
        --brand-blue-hover: #1D4ED8;
        --bg-light: #0B1220;
        --text-dark: #F8FAFC;
        --text-muted: #94A3B8;
        --white: #0F172A;
        --border-color: #334155;
        --row-hover: #1E293B;
    }

    .modern-card {
        background-color: #0F172A;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-color);
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        border-bottom: 2px solid var(--bg-light);
        padding-bottom: 15px;
    }

    .page-title {
        color: var(--primary-navy);
        font-size: 24px;
        font-weight: 800;
        margin: 0;
    }

    .search-container {
        display: flex;
        align-items: center;
        background: #0F172A;
        border-radius: 24px;
        border: 1px solid #334155;
        padding: 10px 16px;
        width: 300px;
        transition: box-shadow 0.3s ease, border-color 0.3s ease;
    }

    .search-container:focus-within {
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        border-color: #3B82F6;
    }

    .search-container input {
        width: 100%;
        border: none;
        outline: none;
        font-size: 14px;
        background: transparent;
        color: #FFFFFF;
    }

    .search-container input::placeholder {
        color: #94A3B8;
    }

    .search-container svg {
        width: 18px;
        height: 18px;
        color: #94A3B8;
        margin-right: 10px;
    }

    .table-responsive {
        overflow-x: auto;
        border-radius: 0;
        border: 1px solid #334155;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        background: #0F172A;
        color: #F8FAFC;
        border-radius: 0 !important;
        border: 1px solid #334155;
    }

    th {
        background-color: #1E293B;
        color: #F8FAFC;
        padding: 15px 12px;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        position: relative;
        border: 1px solid #334155;
        border-radius: 0 !important;
    }

    td {
        padding: 15px 12px;
        border: 1px solid #334155;
        font-size: 14px;
        color: #F8FAFC !important;
        background: #0F172A !important;
        vertical-align: middle;
        border-radius: 0 !important;
    }

    tr,
    thead,
    tbody {
        border-radius: 0 !important;
    }

    tr:hover,
    tr:hover td,
    .sheet-cell:hover {
        background: #1E293B !important;
        color: #FFFFFF !important;
    }

    tr:hover td * {
        color: #FFFFFF !important;
    }

    .app-box {
        max-height: 120px;
        width: 250px;
        overflow-y: auto;
        background: var(--bg-light);
        padding: 10px;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        font-size: 12px;
        line-height: 1.6;
        color: var(--text-muted);
        white-space: normal;
        word-wrap: break-word;
        text-align: left;
    }

    .app-box strong {
        color: var(--text-dark);
    }

    .status-select {
        padding: 8px 10px;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        font-size: 13px;
        font-weight: 600;
        background: var(--white);
        cursor: pointer;
        width: 140px;
        outline: none;
        transition: border-color 0.2s;
    }

    .status-select:focus {
        border-color: var(--electric-blue);
    }

    .update-box {
        background: #FFFBEB;
        border: 1px solid #FDE68A;
        color: #92400E;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        max-width: 150px;
        display: inline-block;
        word-wrap: break-word;
    }

    .retransfer-badge {
        display: none;
        background: #EF4444;
        color: white;
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 12px;
        margin-top: 8px;
        font-weight: bold;
        text-align: center;
    }

    .btn-chat {
        background-color: var(--electric-blue);
        color: var(--white);
        border: none;
        padding: 8px 15px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }

    .btn-chat:hover {
        background-color: #0056b3;
    }

    .filter-icon {
        margin-left: 8px;
        cursor: pointer;
        color: #94A3B8;
        font-size: 11px;
        transition: 0.2s;
    }

    .filter-icon:hover,
    .filter-icon.active {
        color: var(--white);
    }

    .filter-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 8px;
        min-width: 200px;
        max-height: 240px;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        z-index: 50;
        padding: 10px;
        color: var(--text-dark);
        text-transform: none;
        font-weight: normal;
        font-size: 13px;
    }

    .filter-menu.show {
        display: block;
    }

    .filter-search {
        width: 100%;
        padding: 8px;
        border: 1px solid var(--border-color);
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
        color: var(--electric-blue);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        padding: 4px 8px;
    }

    .filter-btn:hover {
        text-decoration: underline;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .modal-content {
        background: #fff;
        padding: 25px;
        border-radius: 12px;
        width: 450px;
        max-width: 90%;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    }

    .modal-content h3 {
        margin-top: 0;
        color: var(--primary-navy);
        margin-bottom: 10px;
    }

    .modal-content input[type="datetime-local"] {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        margin: 15px 0;
        font-size: 15px;
        box-sizing: border-box;
        outline: none;
    }

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .btn-cancel {
        background: #64748B;
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
    }

    .btn-save {
        background: var(--electric-blue);
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
    }

    .chat-box-container {
        height: 320px;
        overflow-y: auto;
        background: #fafafa;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .chat-input-wrapper {
        display: flex;
        gap: 10px;
    }

    .chat-input-wrapper input {
        flex: 1;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        outline: none;
        font-size: 14px;
    }

    .chat-input-wrapper input:focus {
        border-color: var(--electric-blue);
    }
</style>

<div class="modern-card">
    <div class="page-header">
        <h2 class="page-title">My Leads</h2>

        <div class="search-container">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            <input type="text" id="search-input" placeholder="Search leads...">
        </div>
        <span id="filtered-count" style="color: #94A3B8; font-size: 13px; white-space: nowrap;"></span>
    </div>

    <div class="table-responsive table-container">
        <table id="leadsTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th data-filter="true">Name</th>
                    <th data-filter="true">Phone</th>
                    <th data-filter="true">Closer</th>
                    <th>Update (From Closer)</th>
                    <th>Chat</th>
                    <th>Live Status</th>
                    <th>Application</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($lead = $leads_query->fetch_assoc()): ?>
                    <?php
                    $lead = decrypt_fields($lead);

                    $closer_name = htmlspecialchars($lead['closer_name'] ?? 'Unassigned');
                    $customer_name = htmlspecialchars($lead['customer_name'] ?? trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')));
                    if (empty($customer_name)) $customer_name = 'Customer';

                    $current_status = htmlspecialchars($lead['status'] ?? 'New');
                    $closer_status_val = htmlspecialchars($lead['closer_status'] ?? '');
                    $retransfer_count = intval($lead['retransfer_count'] ?? 0);

                    $verifier_id = $lead['verifier_id'] ?? null;
                    $has_verifier = !empty($verifier_id);

                    $display_update = $has_verifier ? $current_status : $closer_status_val;
                    $unread_stmt = $conn->prepare("SELECT COUNT(*) AS unread FROM inhouse_chats WHERE lead_id = ? AND recipient_id = ? AND recipient_type = 'Agent' AND sender_type <> 'Agent' AND is_read = 0");
                    if (!$unread_stmt) {
                        throw new RuntimeException('Failed to prepare unread chat count: ' . $conn->error);
                    }
                    $agent_user_id = (int)$_SESSION['user_id'];
                    $lead_id_for_unread = (int)$lead['id'];
                    $unread_stmt->bind_param("ii", $lead_id_for_unread, $agent_user_id);
                    if (!$unread_stmt->execute()) {
                        throw new RuntimeException('Failed to count unread chat messages: ' . $unread_stmt->error);
                    }
                    $unread_count = (int)$unread_stmt->get_result()->fetch_assoc()['unread'];
                    $unread_stmt->close();
                    ?>
                    <tr>
                        <td style="font-weight: bold; background: #f8fafc; text-align: center;">
                            <?php echo $lead['absolute_lead_number']; ?>
                        </td>
                        <td><strong><?php echo $customer_name; ?></strong></td>
                        <td><?php echo htmlspecialchars($lead['phone'] ?? ''); ?></td>
                        <td><?php echo $closer_name; ?></td>

                        <td>
                            <?php if (!empty($display_update)): ?>
                                <div class="update-box" <?php echo $has_verifier ? 'style="background: #e0f2fe; border-color: #bae6fd; color: #0284c7;"' : ''; ?>>
                                    <?php echo $display_update; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px; font-weight:600;">No Update</span>
                            <?php endif; ?>
                        </td>

                        

                        <td>
                            <button type="button" class="btn" style="position:relative;background:#0A2540;color:#fff;padding:6px 12px;font-size:11px;" onclick="openAgentChat(<?= (int)$lead['id'] ?>, <?= (int)($lead['closer_id'] ?? 0) ?>)">
                                <i class="fas fa-comments"></i> Chat
                                <span id="agent-chat-badge-<?php echo (int)$lead['id']; ?>" style="position:absolute;top:-5px;right:-5px;background:#dc3545;color:#fff;font-size:10px;font-weight:bold;padding:2px 6px;border-radius:50%;border:2px solid #fff;line-height:1;display:<?php echo $unread_count > 0 ? 'block' : 'none'; ?>;"><?php echo $unread_count; ?></span>
                            </button>
                        </td>

                        <td>
                            <?php if ($has_verifier || $current_status === 'Pending Verification'): ?>
                                <span style="background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; border: 1px solid #cbd5e1; display: inline-block;">Locked</span>
                            <?php else: ?>
                                <select class="status-select" onchange="handleStatusChange(this, <?php echo $lead['id']; ?>)" data-retransfers="<?php echo $retransfer_count; ?>">
                                    <option value="New" <?php echo ($current_status == 'New' || $current_status == '') ? 'selected' : ''; ?>>New</option>
                                    <option value="VM" <?php echo ($current_status == 'VM') ? 'selected' : ''; ?>>Voicemail (VM)</option>
                                    <option value="NI" <?php echo ($current_status == 'NI') ? 'selected' : ''; ?>>Not Interested (NI)</option>
                                    <option value="CB" <?php echo ($current_status == 'CB') ? 'selected' : ''; ?>>Callback (CB)</option>
                                    <option value="Retransfer" <?php echo ($current_status == 'Retransfer') ? 'selected' : ''; ?>>Retransfer</option>
                                </select>
                            <?php endif; ?>

                            <div class="retransfer-badge" style="display: <?php echo ($retransfer_count > 0) ? 'block' : 'none'; ?>">
                                Transfers: <span class="count"><?php echo $retransfer_count; ?></span>
                            </div>
                        </td>

                        <td>
                            <div class="app-box">
                                <strong>Name:</strong> <?php echo $customer_name; ?><br>
                                <strong>Phone:</strong> <?php echo htmlspecialchars($lead['phone'] ?? ''); ?><br>
                                <strong>Email:</strong> <?php echo htmlspecialchars($lead['email'] ?? ''); ?><br>
                                <strong>Address:</strong> <?php echo htmlspecialchars($lead['address'] ?? ''); ?><br>
                                <strong>City:</strong> <?php echo htmlspecialchars($lead['city'] ?? ''); ?><br>
                                <strong>State:</strong> <?php echo htmlspecialchars($lead['state'] ?? ''); ?><br>
                                <strong>Zipcode:</strong> <?php echo htmlspecialchars($lead['zip_code'] ?? ''); ?><br>
                                <strong>DOB:</strong> <?php echo htmlspecialchars($lead['dob'] ?? ''); ?><br>
                                <strong>Alt Details:</strong> <?php echo htmlspecialchars($lead['additional_details'] ?? ''); ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($leads_query->num_rows == 0): ?>
                    <tr class="no-leads-row">
                        <td colspan="9" style="text-align: center; color: #888; padding: 30px;">No leads submitted yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Callback Modal -->
<div id="cbModal" class="modal-overlay">
    <div class="modal-content">
        <h3>Schedule Callback</h3>
        <p style="color: #64748B; font-size: 14px;">Select date, time, and timezone.</p>
        <input type="hidden" id="cbLeadId">
        <input type="datetime-local" id="cbDateTime">
        <select id="cbTimezone" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 15px; font-size: 15px; outline: none; background: white;">
            <option value="America/New_York">Eastern Time (EST)</option>
            <option value="America/Chicago">Central Time (CST)</option>
            <option value="America/Denver">Mountain Time (MST)</option>
            <option value="America/Los_Angeles">Pacific Time (PST)</option>
            <option value="Asia/Kolkata">India Standard Time (IST)</option>
        </select>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeCbModal()">Cancel</button>
            <button type="button" class="btn-save" onclick="saveCallback()">Set Reminder</button>
        </div>
    </div>
</div>

<!-- Lead Discussion Chat Modal -->
<div id="chatModal" class="modal-overlay">
    <div class="modal-content" style="width: 520px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
            <div>
                <h3 style="margin: 0; color: var(--primary-navy);">Lead Discussion</h3>
                <p style="color: #64748B; font-size: 13px; margin: 4px 0 0 0;">
                    Customer: <strong id="chatCustomerName" style="color: var(--primary-navy);"></strong> |
                    Closer: <strong id="chatCloserName" style="color: var(--electric-blue);"></strong>
                </p>
            </div>
            <button type="button" onclick="closeChatModal()" style="background:none; border:none; font-size:24px; color:#999; cursor:pointer; line-height: 1;">&times;</button>
        </div>

        <div id="chatMessages" class="chat-box-container"></div>

        <div class="chat-input-wrapper">
            <input type="hidden" id="chatLeadId" value="">
            <input type="text" id="chatInput" placeholder="Write a note or message..." onkeypress="if(event.key === 'Enter') sendChatMessage()">
            <button type="button" class="btn-save" onclick="sendChatMessage()"><i class="fas fa-paper-plane"></i> Send</button>
        </div>
    </div>
</div>

<div id="agentChatModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px; max-width: 90%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #0A2540; color: white; padding: 12px; border-radius: 6px;">
            <h4 style="margin: 0;">Inhouse Lead Chat</h4>
            <button type="button" onclick="closeAgentChat()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div id="agentChatMessages" style="height: 280px; overflow-y: auto; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 15px; display: flex; flex-direction: column;"></div>
        <div style="display: flex; gap: 10px;">
            <input type="hidden" id="agentChatLeadId" value="">
            <input type="hidden" id="agentChatCloserId" value="">
            <input type="text" id="agentChatInput" placeholder="Type your message..." style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 6px; outline: none;" onkeypress="if(event.key === 'Enter') sendAgentMessage()">
            <button type="button" onclick="sendAgentMessage()" class="btn" style="background-color: #28a745; color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Send</button>
        </div>
    </div>
</div>

<script>
    // ==========================================
    // CHAT MODAL LOGIC (CENTRALIZED VIA MESSAGES TABLE)
    // ==========================================
    let chatInterval = null;

    function openChatModal(leadId, customerName, closerName) {
        document.getElementById('chatLeadId').value = leadId;
        document.getElementById('chatCustomerName').innerText = customerName;
        document.getElementById('chatCloserName').innerText = closerName || 'Unassigned';
        document.getElementById('chatInput').value = '';

        document.getElementById('chatModal').style.display = 'flex';

        loadChatMessages();
        clearInterval(chatInterval);
        chatInterval = setInterval(loadChatMessages, 3000);
    }

    function closeChatModal() {
        document.getElementById('chatModal').style.display = 'none';
        clearInterval(chatInterval);
    }

    function loadChatMessages() {
        const leadId = document.getElementById('chatLeadId').value;
        if (!leadId) return;

        fetch('my_leads.php?action=fetch_chat&lead_id=' + leadId)
            .then(r => r.json())
            .then(messages => {
                const container = document.getElementById('chatMessages');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                const previousScrollTop = container.scrollTop;
                container.innerHTML = '';

                if (messages.length === 0) {
                    container.innerHTML = "<p style='color: #888; font-style: italic; text-align:center; margin: auto;'>No messages yet on this lead.</p>";
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
                        div.style.padding = '10px 15px';
                        div.style.borderRadius = '8px';
                        div.style.marginBottom = '10px';
                        div.style.color = '#f8fafc';

                        const header = document.createElement('div');
                        header.style.cssText = 'display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;';
                        const sender = document.createElement('strong');
                        sender.style.color = senderColor;
                        sender.textContent = `${msg.username || senderType} (${senderType})`;
                        const timestamp = document.createElement('span');
                        timestamp.style.cssText = 'font-size:11px; color:#94a3b8;';
                        const date = new Date(msg.created_at);
                        timestamp.textContent = Number.isNaN(date.getTime()) ? msg.created_at : date.toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        header.append(sender, timestamp);

                        const body = document.createElement('div');
                        body.style.cssText = 'color:#e2e8f0; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere;';
                        body.textContent = msg.content || msg.message || '';
                        div.append(header, body);
                        container.appendChild(div);
                    });
                }
                if (isScrolledToBottom) {
                    container.scrollTop = container.scrollHeight;
                } else {
                    container.scrollTop = previousScrollTop;
                }
            })
            .catch(err => console.error("Chat Error:", err));
    }

    function sendChatMessage() {
        const leadId = document.getElementById('chatLeadId').value;
        const input = document.getElementById('chatInput');
        const message = input.value.trim();
        if (!message || !leadId) return;

        const formData = new URLSearchParams();
        formData.append('action', 'send_chat');
        formData.append('lead_id', leadId);
        formData.append('message', message);

        fetch('my_leads.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    input.value = '';
                    loadChatMessages();
                } else {
                    alert('Error sending message.');
                }
            })
            .catch(err => console.error("Send Error:", err));
    }

    // ==========================================
    // STATUS & CALLBACK ENGINE
    // ==========================================
    let currentSelectElement = null;

    function handleStatusChange(selectEl, leadId) {
        const val = selectEl.value;
        const badge = selectEl.nextElementSibling;

        if (val === 'CB') {
            currentSelectElement = selectEl;
            document.getElementById('cbLeadId').value = leadId;
            document.getElementById('cbModal').style.display = 'flex';
        } else if (val === 'Retransfer') {
            let count = parseInt(selectEl.getAttribute('data-retransfers')) || 0;
            count++;
            selectEl.setAttribute('data-retransfers', count);

            badge.style.display = 'block';
            badge.querySelector('.count').innerText = count;

            updateStatusInDB(leadId, val, count);
            alert(`Lead retransferred to closer. Total retransfers: ${count}`);
        } else {
            updateStatusInDB(leadId, val, null);
        }
    }

    function updateStatusInDB(leadId, status, retransfers) {
        const formData = new URLSearchParams();
        formData.append('action', 'update_status');
        formData.append('lead_id', leadId);
        formData.append('status', status);
        if (retransfers !== null) formData.append('retransfer_count', retransfers);

        fetch('my_leads.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formData.toString()
        });
    }

    function closeCbModal() {
        document.getElementById('cbModal').style.display = 'none';
        if (currentSelectElement) {
            currentSelectElement.value = 'New';
            currentSelectElement = null;
        }
    }

    function saveCallback() {
        const leadId = document.getElementById('cbLeadId').value;
        const dt = document.getElementById('cbDateTime').value;
        const tz = document.getElementById('cbTimezone').value;

        if (!dt) {
            alert("Please select a date and time.");
            return;
        }

        const formData = new URLSearchParams();
        formData.append('action', 'save_callback');
        formData.append('lead_id', leadId);
        formData.append('callback_time', dt);
        formData.append('timezone', tz);

        fetch('my_leads.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    alert('Reminder Set Successfully!');
                    document.getElementById('cbModal').style.display = 'none';
                    if (currentSelectElement) {
                        currentSelectElement.value = 'CB';
                    }
                    currentSelectElement = null;
                } else {
                    alert('Error setting callback.');
                }
            });
    }

    // ==========================================
    // EXCEL-STYLE TABLE FILTERS & SEARCH
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('leadsTable');
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-leads-row)'));
        const headers = table.querySelectorAll('th[data-filter="true"]');
        let activeFilters = {};

        headers.forEach(th => {
            const colIndex = Array.from(th.parentElement.children).indexOf(th);
            let uniqueValues = new Set();

            rows.forEach(row => {
                const cell = row.cells[colIndex];
                if (!cell) return;
                const text = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                if (text !== "") uniqueValues.add(text);
            });

            if (uniqueValues.size === 0) return;

            th.innerHTML += `
            <i class="fas fa-filter filter-icon" id="icon-col-${colIndex}"></i>
            <div class="filter-menu" id="menu-col-${colIndex}">
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

            Array.from(uniqueValues).sort().forEach(val => {
                optionsContainer.innerHTML += `
                <label class="filter-option">
                    <input type="checkbox" value="${val.replace(/"/g, '&quot;')}">
                    ${val}
                </label>
            `;
            });

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
                    activeFilters[colIndex] = checkedBoxes;
                    icon.classList.add('active');
                } else {
                    delete activeFilters[colIndex];
                    icon.classList.remove('active');
                }
                applyAllFilters();
                menu.classList.remove('show');
            });

            clearBtn.addEventListener('click', function() {
                optionsContainer.querySelectorAll('input:checked').forEach(cb => cb.checked = false);
                delete activeFilters[colIndex];
                icon.classList.remove('active');
                applyAllFilters();
                menu.classList.remove('show');
            });
        });

        function applyAllFilters() {
            const searchTerm = searchInput.value.trim().toLowerCase();
            rows.forEach(row => {
                let rowMatchesAll = row.textContent.toLowerCase().includes(searchTerm);
                for (const colIndex in activeFilters) {
                    if (!rowMatchesAll) break;
                    const allowedValues = activeFilters[colIndex];
                    const cell = row.cells[colIndex];
                    if (cell) {
                        let cellText = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                        if (!allowedValues.includes(cellText)) {
                            rowMatchesAll = false;
                            break;
                        }
                    }
                }
                row.style.display = rowMatchesAll ? '' : 'none';
            });
            updateFilteredCount();
        }

        const searchInput = document.getElementById('search-input');
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyAllFilters, 300);
        });

        const filteredCount = document.getElementById('filtered-count');
        function updateFilteredCount() {
            const visibleCount = rows.filter(row => row.style.display !== 'none').length;
            if (filteredCount) filteredCount.textContent = `(Filtered Count: ${visibleCount})`;
        }

        applyAllFilters();

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.filter-menu') && !e.target.closest('.filter-icon')) {
                document.querySelectorAll('.filter-menu.show').forEach(m => m.classList.remove('show'));
            }
        });
    });

    function searchFilterOptions(inputEl) {
        let filter = inputEl.value.toLowerCase();
        let labels = inputEl.nextElementSibling.querySelectorAll('.filter-option');
        labels.forEach(label => {
            let text = label.innerText.toLowerCase();
            label.style.display = text.includes(filter) ? "flex" : "none";
        });
    }

    let agentChatInterval = null;

    function openAgentChat(leadId, closerId) {
        document.getElementById('agentChatLeadId').value = leadId;
        document.getElementById('agentChatCloserId').value = closerId || 0;
        document.getElementById('agentChatModal').classList.add('show');
        document.getElementById('agentChatModal').style.display = 'flex';
        document.getElementById('agentChatInput').value = '';
        loadAgentMessages();
        clearInterval(agentChatInterval);
        agentChatInterval = setInterval(loadAgentMessages, 3000);
    }

    function closeAgentChat() {
        document.getElementById('agentChatModal').classList.remove('show');
        document.getElementById('agentChatModal').style.display = 'none';
        clearInterval(agentChatInterval);
    }

    function loadAgentMessages() {
        const leadId = document.getElementById('agentChatLeadId').value;
        const closerId = document.getElementById('agentChatCloserId').value || 0;
        if (!leadId) return;

        fetch(`../api/v1/inhouse_chat_api.php?action=get&lead_id=${encodeURIComponent(leadId)}&closer_id=${encodeURIComponent(closerId)}`)
            .then(response => response.json())
            .then(messages => {
                if (!Array.isArray(messages)) throw new Error(messages.error || 'Could not load messages.');
                const badge = document.getElementById('agent-chat-badge-' + leadId);
                if (badge) {
                    badge.textContent = '0';
                    badge.style.display = 'none';
                }
                const container = document.getElementById('agentChatMessages');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                const previousScrollTop = container.scrollTop;
                container.replaceChildren();

                if (messages.length === 0) {
                    const emptyMessage = document.createElement('p');
                    emptyMessage.style.cssText = 'color:#888; font-style:italic; text-align:center; margin-top:20px;';
                    emptyMessage.textContent = 'No messages yet.';
                    container.appendChild(emptyMessage);
                    container.scrollTop = container.scrollHeight;
                    return;
                }

                messages.forEach(message => {
                    const item = document.createElement('div');
                    const senderType = message.sender_type || message.role || 'User';
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
                    item.style.cssText = `padding:10px 15px; border-radius:8px; margin-bottom:10px; border-left:4px solid ${borderColor}; color:#f8fafc; background:${bg};`;

                    const header = document.createElement('div');
                    const sender = document.createElement('strong');
                    sender.style.color = senderColor;
                    sender.textContent = senderType;
                    const timestamp = document.createElement('span');
                    timestamp.style.cssText = 'font-size:11px; color:#666; float:right;';
                    const date = new Date(message.created_at);
                    timestamp.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    header.append(sender, timestamp);

                    const text = document.createElement('p');
                    text.style.cssText = 'margin:8px 0 0; font-size:14px; color:#e2e8f0; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere;';
                    text.textContent = message.message || '';
                    item.append(header, text);
                    container.appendChild(item);
                });

                container.scrollTop = isScrolledToBottom ? container.scrollHeight : previousScrollTop;
            })
            .catch(error => {
                document.getElementById('agentChatMessages').textContent = error.message || 'Could not load messages.';
            });
    }

    function sendAgentMessage() {
        const leadId = document.getElementById('agentChatLeadId').value;
        const closerId = document.getElementById('agentChatCloserId').value || 0;
        const input = document.getElementById('agentChatInput');
        const message = input.value.trim();
        if (!message || !leadId) return;
        if (!closerId) {
            alert('This lead has no closer assigned.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('lead_id', leadId);
        formData.append('closer_id', closerId);
        formData.append('recipient_id', closerId);
        formData.append('recipient_type', 'Closer');
        formData.append('message', message);

        fetch('../api/v1/inhouse_chat_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.error || 'Failed to send message.');
                input.value = '';
                loadAgentMessages();
            })
            .catch(error => alert(error.message || 'Failed to send message.'));
    }

    document.getElementById('agentChatModal').addEventListener('click', function(event) {
        if (event.target === this) closeAgentChat();
    });

    document.addEventListener('DOMContentLoaded', function() {
        if (!window.workspacePusher) return;
        const inhouseChannel = window.workspacePusher.subscribe('inhouse-chat-channel');
        inhouseChannel.bind('new-message', function(data) {
            const agentId = <?php echo (int)$_SESSION['user_id']; ?>;
            if (data.recipient_type !== 'Agent' || Number(data.recipient_id) !== agentId) return;
            const leadId = Number(data.lead_id);
            const openLeadId = Number(document.getElementById('agentChatLeadId').value);
            if (document.getElementById('agentChatModal').classList.contains('show') && openLeadId === leadId) {
                loadAgentMessages();
                return;
            }

            const badge = document.getElementById('agent-chat-badge-' + leadId);
            if (badge) {
                badge.textContent = String((parseInt(badge.textContent, 10) || 0) + 1);
                badge.style.display = 'block';
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>