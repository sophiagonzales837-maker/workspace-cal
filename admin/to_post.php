<?php
// FILE: workspace-CAL/admin/to_post.php
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin']);

if (($_GET['action'] ?? '') === 'fetch_report') {
    header('Content-Type: application/json');
    $lead_id = (int)($_GET['lead_id'] ?? 0);
    $stmt = $conn->prepare('SELECT verifier_application_text FROM leads WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $lead_id);
    $stmt->execute();
    $report_data = $stmt->get_result()->fetch_assoc();
    echo json_encode(['success' => true, 'report' => html_entity_decode((string)decryptData($report_data['verifier_application_text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')]);
    exit;
}

$msg = '';
if (($_GET['success'] ?? '') === 'reverify') {
    $msg = 'Lead assigned to a Verifier for reverification.';
} elseif (($_GET['error'] ?? '') === 'verifier') {
    $msg = 'Error: Select a valid active Verifier.';
} elseif (($_GET['error'] ?? '') === 'unavailable') {
    $msg = 'Error: This lead is no longer in the NU posting queue.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reverify_lead'])) {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $verifier_id = (int)($_POST['verifier_id'] ?? 0);

    $verifier_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'Verifier' LIMIT 1");
    $verifier_stmt->bind_param('i', $verifier_id);
    $verifier_stmt->execute();
    $valid_verifier = $verifier_stmt->get_result()->fetch_assoc();
    $verifier_stmt->close();

    if ($lead_id <= 0 || !$valid_verifier) {
        $msg = 'Error: Select a valid Verifier.';
    } else {
        $reverify_stmt = $conn->prepare("UPDATE leads SET status = 'Pending Verification', verifier_id = ?, closer_status = NULL, updated_at = NOW() WHERE id = ? AND status = 'NU'");
        $reverify_stmt->bind_param('ii', $verifier_id, $lead_id);
        $reverified = $reverify_stmt->execute() && $reverify_stmt->affected_rows === 1;
        $reverify_stmt->close();

        if ($reverified) {
            $pusher = workspace_pusher_server();
            if ($pusher) {
                try {
                    $pusher->trigger('global-updates', 'lead-updated', ['lead_id' => $lead_id]);
                } catch (Exception $e) {
                    error_log('[Reverify assignment] Pusher refresh failed: ' . $e->getMessage());
                }
            }
            $msg = 'Lead assigned to the selected Verifier for reverification.';
        } else {
            $msg = 'Error: This lead is no longer in the NU queue or could not be assigned.';
        }
    }
}

// Handle Post to Client Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_lead'])) {
    $lead_id = (int)$_POST['lead_id'];

    $stmt = $conn->prepare("UPDATE leads SET status = 'Posted', admin_status = 'Approved', updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $lead_id);

    if ($stmt->execute()) {
        // Sync the Posted status back to the Live Website portal
        $q = $conn->query("SELECT phone_hash FROM leads WHERE id = $lead_id");
        if ($row = $q->fetch_assoc()) {
            $payload = json_encode([
                'auth_key' => 'MakeMoney$$',
                'phone_hash' => $row['phone_hash'],
                'updates' => [
                    'status' => 'Posted',
                    'admin_status' => 'Approved'
                ]
            ]);

            $ch = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        }
        $msg = "Lead Posted Successfully and synced to Customer Portal.";
    }
    $stmt->close();
}

$where_clause = "l.status IN ('NU', 'NU/ID Uploaded', 'Pending Post') AND (l.admin_status IS NULL OR l.admin_status != 'Approved')";
$query = "SELECT l.*, 
    (SELECT username FROM users WHERE id = l.agent_id) as agent_name,
    (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
    (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username 
    FROM leads l 
    WHERE $where_clause 
    ORDER BY l.created_at ASC";
$leads = mysqli_query($conn, $query);
if (!$leads) {
    die("SQL Error: " . mysqli_error($conn));
}
$verifiers = [];
$verifier_result = $conn->query("SELECT id, username FROM users WHERE role = 'Verifier' ORDER BY username ASC");
if ($verifier_result) {
    while ($verifier = $verifier_result->fetch_assoc()) {
        $verifiers[] = $verifier;
    }
    $verifier_result->free();
}

include 'includes/header.php';
?>

<div style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <h2 style="color: var(--primary-navy); margin-bottom: 20px;">Leads Ready to Post</h2>

    <?php if ($msg) echo "<div style='background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>$msg</div>"; ?>

    <div class="card">
        <div style="overflow-x: auto;">
            <table class="table" id="masterSheet" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Admin Chat</th>
                        <th>Vertical</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>City</th>
                        <th>State</th>
                        <th>Zipcode</th>
                        <th>DOB</th>
                        <th>Client</th>
                        <th>Update</th>
                        <th>Status</th>
                        <th>Closers Application</th>
                        <th>Alternate Info</th>
                        <th>Proof</th>
                        <th>Upload Link</th>
                        <th>Agent Name</th>
                        <th>Reference Name</th>
                        <th>Closer Name</th>
                        <th>Verifier Name</th>
                        <th>Jornaya</th>
                        <th>Trusted Form</th>
                        <th>IP</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($leads)):
                        $lead = function_exists('decrypt_fields') ? decrypt_fields($row) : $row;
                        $lead_id = (int)$lead['id'];
                        $un_in_q = $conn->query("SELECT COUNT(*) AS unread FROM inhouse_chats WHERE lead_id = $lead_id AND sender_type = 'Closer' AND is_read = 0");
                        $unread = $un_in_q ? (int)$un_in_q->fetch_assoc()['unread'] : 0;
                        $front_id = ltrim((string)($lead['id_front_path'] ?? ''), '/');
                        $back_id = ltrim((string)($lead['id_back_path'] ?? ''), '/');
                        $additional_records = ltrim((string)($lead['additional_records'] ?? ''), '/');
                    ?>
                        <tr style="border-top: 1px solid #e2e8f0;">
                            <td style="text-align: center;">
                                <button type="button" class="btn" style="background: #0A2540; color: white; position: relative;" onclick="openCombinedChatModal(<?= $lead_id ?>, <?= (int)($lead['closer_id'] ?? 0) ?>, <?= htmlspecialchars(json_encode((string)($lead['customer_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">
                                    <i class="fas fa-comments"></i> Admin
                                    <?php if ($unread > 0): ?><span id="admin-chat-badge-<?= $lead_id ?>" style="position:absolute; top:-5px; right:-5px; background:#dc3545; color:white; font-size:10px; font-weight:bold; padding:2px 6px; border-radius:50%;"><?= $unread ?></span><?php endif; ?>
                                </button>
                            </td>
                            <td><?= htmlspecialchars($lead['vertical'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['city'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['state'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['zip_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['dob'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <form action="process_post.php" method="POST" style="min-width: 190px; display: flex; flex-direction: column; gap: 5px;">
                                    <input type="hidden" name="lead_id" value="<?= $lead_id ?>">
                                    <input type="text" name="client_name" placeholder="Client Code (e.g. C1)" oninput="togglePostBtn(this, <?= $lead_id ?>)" required style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                                    <div style="display: flex; gap: 6px;">
                                        <button type="submit" name="action" value="post" id="post-btn-<?= $lead_id ?>" class="btn" style="flex: 1; background: #94a3b8; color: white;" disabled>Post</button>
                                        <button type="button" class="btn" style="flex: 1; background: #facc15; color: #713f12;" onclick="openReverifyModal(<?= $lead_id ?>)">Reverify</button>
                                    </div>
                                </form>
                            </td>
                            <td><?= htmlspecialchars($lead['closer_status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><textarea readonly><?= htmlspecialchars($lead['application_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></td>
                            <td><?= htmlspecialchars($lead['alternate_details'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($front_id !== ''): ?><a href="../../<?= htmlspecialchars($front_id, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">[Front ID]</a><br><?php endif; ?>
                                <?php if ($back_id !== ''): ?><a href="../../<?= htmlspecialchars($back_id, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">[Back ID]</a><br><?php endif; ?>
                                <?php if ($additional_records !== ''): ?><a href="../../<?= htmlspecialchars($additional_records, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">[Additional]</a><?php endif; ?>
                            </td>
                            <td><?php if (!empty($lead['id_upload_token'])): ?><a href="https://consumerhealthcarecompliance.com/uploads?token=<?= rawurlencode((string)$lead['id_upload_token']) ?>" target="_blank" rel="noopener">Open Link</a><?php endif; ?></td>
                            <td><?= htmlspecialchars($lead['agent_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['referred_by'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['closer_username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['verifier_username'] ?? $lead['verifier_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['jornaya_leadid'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['trustedform_cert_url'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($lead['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= date('m/d/y h:ia', strtotime($lead['updated_at'] ?? $lead['created_at'] ?? 'now')) ?></td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if (mysqli_num_rows($leads) === 0): ?>
                        <tr>
                            <td colspan="26" style="text-align: center; color: #888; padding: 20px;">No leads ready to post.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .6);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(2px);
    }

    .modal-overlay.show {
        display: flex;
    }
</style>

<!-- REVERIFY MODAL -->
<div id="reverifyModal" class="modal-overlay">
    <div class="modal-content" style="width: 400px; max-width: 95%;">
        <h3 style="margin-top: 0; color: #0A2540;">Assign Verifier</h3>
        <form action="process_post.php" method="POST">
            <input type="hidden" name="action" value="reverify">
            <input type="hidden" name="lead_id" id="reverifyLeadId" value="">
            <select name="verifier_id" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ccc; margin-bottom: 15px;" required>
                <option value="">-- Select Active Verifier --</option>
                <?php foreach ($verifiers as $verifier): ?>
                    <option value="<?= (int)$verifier['id'] ?>"><?= htmlspecialchars($verifier['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeReverifyModal()" class="btn" style="background: #e2e8f0; color: #1e293b; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn" style="background: #facc15; color: #713f12; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Reverify</button>
            </div>
        </form>
    </div>
</div>

<!-- COMBINED CHAT MODAL -->
<div id="combinedChatModal" class="modal-overlay">
    <div class="modal-content" style="width: 600px; max-width: 95%; max-height: 90vh; display: flex; flex-direction: column; background: #f8fafc; padding: 0; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: center; background: #0A2540; color: white; padding: 15px 20px; flex-shrink: 0;">
            <h3 style="margin: 0; font-size: 16px;"><i class="fas fa-comments"></i> Chat &amp; Report: <span id="combinedChatLeadName"></span></h3>
            <button type="button" onclick="closeCombinedChatModal()" style="background:none; border:none; font-size: 20px; color:white; cursor:pointer;">&times;</button>
        </div>
        <div style="padding: 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column;">
            <div style="margin-bottom: 15px; flex-shrink: 0;">
                <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #475569; text-transform: uppercase;">Verification Report</h4>
                <pre id="combinedReportContent" style="max-height: 120px; overflow-y: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; font-size: 12px; margin: 0; color: #1e293b; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"></pre>
            </div>
            <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #475569; text-transform: uppercase; flex-shrink: 0;">Closer Chat</h4>
            <div id="combinedChatMessages" style="min-height: 150px; flex: 1; overflow-y: auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 15px; margin-bottom: 15px; display: flex; flex-direction: column; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);"></div>
            <div style="display: flex; gap: 10px; flex-shrink: 0;">
                <input type="hidden" id="combinedChatLeadId" value="">
                <input type="hidden" id="combinedChatCloserId" value="">
                <input type="text" id="combinedChatInput" placeholder="Type your message to Closer..." style="flex: 1; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; font-size: 13px;" onkeypress="if(event.key === 'Enter') sendCombinedChatMessage()">
                <button type="button" onclick="sendCombinedChatMessage()" class="btn" style="background-color: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer;">Send</button>
            </div>
        </div>
    </div>
</div>

<script>
    let combinedChatTimer = null;

    function togglePostBtn(input, leadId) {
        const button = document.getElementById('post-btn-' + leadId);
        const ready = input.value.trim() !== '';
        button.disabled = !ready;
        button.style.backgroundColor = ready ? '#28a745' : '#94a3b8';
    }

    function openReverifyModal(leadId) {
        document.getElementById('reverifyLeadId').value = leadId;
        document.getElementById('reverifyModal').classList.add('show');
    }

    function closeReverifyModal() {
        document.getElementById('reverifyModal').classList.remove('show');
    }

    function escapeChatText(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    function sanitizeReportHtml(value) {
        const parsed = new DOMParser().parseFromString(String(value ?? ''), 'text/html');
        const allowedTags = new Set(['br', 'strong', 'b', 'em', 'i', 'span', 'p', 'div', 'ul', 'ol', 'li']);
        const output = document.createElement('div');
        const copySafeNode = (parent, node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                parent.appendChild(document.createTextNode(node.nodeValue || ''));
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            const tagName = node.tagName.toLowerCase();
            const allowed = allowedTags.has(tagName);
            const target = allowed ? document.createElement(tagName) : parent;
            if (allowed && tagName === 'span') {
                const color = node.style.color;
                const fontWeight = node.style.fontWeight;
                if (/^(#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\))$/i.test(color)) target.style.color = color;
                if (fontWeight === 'bold' || fontWeight === '600' || fontWeight === '700') target.style.fontWeight = fontWeight;
            }
            if (allowed) parent.appendChild(target);
            Array.from(node.childNodes).forEach(child => copySafeNode(target, child));
        };
        Array.from(parsed.body.childNodes).forEach(node => copySafeNode(output, node));
        return output.innerHTML;
    }

    async function openCombinedChatModal(leadId, closerId, customerName) {
        document.getElementById('combinedChatLeadId').value = leadId;
        document.getElementById('combinedChatCloserId').value = closerId;
        document.getElementById('combinedChatLeadName').textContent = customerName;
        document.getElementById('combinedChatModal').classList.add('show');
        document.getElementById('combinedReportContent').textContent = 'Loading report...';
        document.getElementById('combinedChatMessages').innerHTML = '';
        document.getElementById('combinedChatInput').value = '';
        const badge = document.getElementById('admin-chat-badge-' + leadId);
        if (badge) badge.remove();
        try {
            const response = await fetch(`to_post.php?action=fetch_report&lead_id=${encodeURIComponent(leadId)}`);
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load report.');
            document.getElementById('combinedReportContent').innerHTML = sanitizeReportHtml(result.report || 'No verification report available.');
        } catch (error) {
            document.getElementById('combinedReportContent').textContent = error.message || 'Unable to load report.';
        }
        clearInterval(combinedChatTimer);
        loadCombinedChatMessages();
        combinedChatTimer = setInterval(loadCombinedChatMessages, 3000);
    }

    function closeCombinedChatModal() {
        document.getElementById('combinedChatModal').classList.remove('show');
        clearInterval(combinedChatTimer);
    }

    function loadCombinedChatMessages() {
        const leadId = document.getElementById('combinedChatLeadId').value;
        const closerId = document.getElementById('combinedChatCloserId').value;
        if (!leadId || !closerId) return;
        fetch(`../api/v1/inhouse_chat_api.php?action=get&lead_id=${encodeURIComponent(leadId)}&closer_id=${encodeURIComponent(closerId)}`)
            .then(response => response.json())
            .then(messages => {
                if (!Array.isArray(messages)) throw new Error('Unable to load chat.');
                const container = document.getElementById('combinedChatMessages');
                const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 10;
                container.innerHTML = '';
                if (messages.length === 0) {
                    container.innerHTML = '<p style="color: #94a3b8; font-style: italic; text-align:center; margin-top:20px; font-size: 13px;">No messages yet.</p>';
                }
                messages.forEach(message => {
                    const item = document.createElement('div');
                    const senderType = message.sender_type || 'Closer';
                    let background = '#1e293b';
                    let borderColor = '#3b82f6';
                    let senderColor = '#38bdf8';
                    let senderLabel = message.sender_type || 'Closer';
                    if (message.sender_type === 'Admin') {
                        background = '#0f172a';
                        borderColor = '#8b5cf6';
                        senderColor = '#a78bfa';
                        senderLabel = 'You (Admin)';
                    } else if (message.sender_type === 'Verifier') {
                        background = '#1e293b';
                        borderColor = '#3b82f6';
                        senderColor = '#38bdf8';
                        senderLabel = 'Verifier';
                    } else if (message.sender_type === 'Agent') {
                        background = '#111827';
                        borderColor = '#10b981';
                        senderColor = '#34d399';
                        senderLabel = 'Agent';
                    } else if (message.sender_type === 'Closer') {
                        background = '#1e293b';
                        borderColor = '#f59e0b';
                        senderColor = '#fbbf24';
                        senderLabel = 'Closer';
                    }

                    item.style.background = background;
                    item.style.borderLeft = `4px solid ${borderColor}`;
                    item.style.color = '#f8fafc';
                    item.style.padding = '10px 15px';
                    item.style.borderRadius = '8px';
                    item.style.marginBottom = '10px';
                    item.style.alignSelf = message.sender_type === 'Admin' ? 'flex-end' : 'flex-start';
                    item.style.maxWidth = '85%';

                    const date = new Date(message.created_at);
                    const timeText = date.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const messageHtml = message.sender_type === 'Verifier' ?
                        sanitizeReportHtml(message.message).replace(/\n/g, '<br>') :
                        escapeChatText(message.message).replace(/\n/g, '<br>');
                    item.innerHTML = `<strong style="color: ${senderColor}; font-size: 12px;">${escapeChatText(senderLabel || senderType)}</strong><span style="font-size: 10px; color: #94a3b8; float: right; margin-left: 10px;">${escapeChatText(timeText)}</span><div style="margin: 8px 0 0; font-size: 14px; color: #e2e8f0; line-height: 1.5;">${messageHtml}</div>`;
                    container.appendChild(item);
                });
                if (isScrolledToBottom) container.scrollTop = container.scrollHeight;
            })
            .catch(error => console.error('Unable to load inhouse chat:', error));
    }

    function sendCombinedChatMessage() {
        const leadId = document.getElementById('combinedChatLeadId').value;
        const closerId = document.getElementById('combinedChatCloserId').value;
        const input = document.getElementById('combinedChatInput');
        const message = input.value.trim();
        if (!message || !leadId || !closerId) return;
        const data = new FormData();
        data.append('action', 'send');
        data.append('lead_id', leadId);
        data.append('closer_id', closerId);
        data.append('message', message);
        fetch('../api/v1/inhouse_chat_api.php', {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                if (!result.success) throw new Error(result.error || 'Unable to send message.');
                input.value = '';
                loadCombinedChatMessages();
            })
            .catch(error => window.alert(error.message || 'Unable to send message.'));
    }
</script>

<?php include 'includes/footer.php'; ?>