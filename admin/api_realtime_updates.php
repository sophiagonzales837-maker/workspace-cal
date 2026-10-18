<?php
// Real-time updates API for admin dashboard and leads
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');

$action = $_GET['action'] ?? '';

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

function getRealtimeLeadClientId($conn, string $client_name): ?int
{
    $client_code = trim(explode('/', $client_name)[0]);
    if ($client_code === '') return null;

    $stmt = $conn->prepare('SELECT id FROM clients WHERE client_code = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('s', $client_code);
    $stmt->execute();
    $client_id = $stmt->get_result()->fetch_assoc()['id'] ?? null;
    $stmt->close();
    return $client_id === null ? null : (int)$client_id;
}

switch ($action) {
    case 'dashboard_stats':
        // Return updated dashboard statistics

        // 1. Agents Online
        $agents_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role='Agent' AND last_activity IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_activity, NOW()) < 5");
        $agents_online = mysqli_fetch_assoc($agents_q)['count'] ?? 0;

        // 2. Leads Pending to Post
        $pending_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM leads WHERE status = 'Qualified' AND (admin_status IS NULL OR admin_status != 'Approved')");
        $pending_post = mysqli_fetch_assoc($pending_q)['count'] ?? 0;

        // 3. CHC Form Leads
        $leads_received = 0;
        $ext_leads_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM leads WHERE jornaya_leadid IS NOT NULL OR message IS NOT NULL");
        if ($ext_leads_q) {
            $leads_received = mysqli_fetch_assoc($ext_leads_q)['count'] ?? 0;
        }

        // 4. Pending Reviews
        $reviews_pending = 0;
        $rev_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='pending'");
        if ($rev_q) {
            $reviews_pending = mysqli_fetch_assoc($rev_q)['count'] ?? 0;
        }

        // 5. Contact Requests
        $contact_req_count = 0;
        $contact_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM contact_requests");
        if ($contact_q) {
            $contact_req_count = mysqli_fetch_assoc($contact_q)['count'] ?? 0;
        }

        echo json_encode([
            'status' => 'success',
            'agents_online' => intval($agents_online),
            'pending_post' => intval($pending_post),
            'leads_received' => intval($leads_received),
            'reviews_pending' => intval($reviews_pending),
            'contact_requests' => intval($contact_req_count),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'all_leads':
        // Return updated leads table rows
        $query = "SELECT l.*, u.username as assigned_closer 
                  FROM leads l 
                  LEFT JOIN users u ON l.closer_id = u.id 
                  ORDER BY l.created_at DESC LIMIT 300";
        $leads = mysqli_query($conn, $query);

        $leads_html = '';
        while ($row = mysqli_fetch_assoc($leads)) {
            $row = function_exists('decrypt_fields') ? decrypt_fields($row) : $row;
            $row_class = getStatusRowClass($row['status'] ?? '');
            $leads_html .= '<tr class="' . htmlspecialchars($row_class) . '">';

            // --- NEW DELETE BUTTON COLUMN ADDED HERE ---
            $leads_html .= '<td style="text-align: center;">';
            $leads_html .= '<button onclick="deleteLead(' . intval($row['id']) . ')" class="btn" style="background-color: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; font-weight: bold;">';
            $leads_html .= '<i class="fas fa-trash-alt"></i> Delete';
            $leads_html .= '</button>';
            $leads_html .= '</td>';
            // ------------------------------------------

            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="customer_id">' . htmlspecialchars($row['customer_id'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="vertical">' . htmlspecialchars($row['vertical'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="first_name">' . htmlspecialchars($row['first_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="last_name">' . htmlspecialchars($row['last_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="phone">' . htmlspecialchars($row['phone'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="email">' . htmlspecialchars($row['email'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="address">' . htmlspecialchars($row['address'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="city">' . htmlspecialchars($row['city'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="state">' . htmlspecialchars($row['state'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="zip_code">' . htmlspecialchars($row['zip_code'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="dob">' . htmlspecialchars($row['dob'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="client_code">' . htmlspecialchars($row['client_code'] ?? $row['client_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="status">' . htmlspecialchars($row['status'] ?? '') . '</td>';
            $leads_html .= '<td class="app-col"><textarea class="app-textarea autosave-val" data-id="' . intval($row['id']) . '" data-col="application_text">' . htmlspecialchars($row['application_text'] ?? '') . '</textarea></td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="agent_name">' . htmlspecialchars($row['agent_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="closer_name">' . htmlspecialchars($row['closer_name'] ?? $row['assigned_closer'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="verifier_name">' . htmlspecialchars($row['verifier_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="false" class="sheet-cell finance-cell" data-id="' . intval($row['id']) . '" data-col="payouts" data-real-value="' . htmlspecialchars($row['payouts'] ?? '') . '">****</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="created_at" style="color: #888; font-size: 11px;">' . date('m/d/y h:ia', strtotime($row['created_at'])) . '</td>';
            $leads_html .= '</tr>';
        }

        echo json_encode([
            'status' => 'success',
            'html' => $leads_html,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'verifier_leads':
        // Return updated leads for verifier
        $verifier_id = $_SESSION['user_id'] ?? 0;
        $query = "SELECT * FROM leads WHERE verifier_id = ? ORDER BY created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $verifier_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $leads_html = '';
        while ($row = $result->fetch_assoc()) {
            $row = function_exists('decrypt_fields') ? decrypt_fields($row) : $row;
            $status_label = '';
            if ($row['admin_status'] == 'Approved') $status_label = '<span style="color:#10B981; font-weight:bold;">✅ Approved</span>';
            elseif ($row['admin_status'] == 'Rejected') $status_label = '<span style="color:#EF4444; font-weight:bold;">❌ Rejected</span>';
            else $status_label = '<span style="color:#F59E0B; font-weight:bold;">⏳ Pending Review</span>';

            $leads_html .= '<tr>';
            $leads_html .= '<td><a href="review_lead.php?id=' . intval($row['id']) . '" style="color:#3B82F6; text-decoration:none;">' . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . '</a></td>';
            $leads_html .= '<td>' . htmlspecialchars($row['phone'] ?? '') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($row['vertical'] ?? '') . '</td>';
            $leads_html .= '<td>' . $status_label . '</td>';
            $leads_html .= '<td>' . date('m/d/y h:i A', strtotime($row['created_at'])) . '</td>';
            $leads_html .= '</tr>';
        }
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'html' => $leads_html,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'posted_leads':
        // THIS IS THE SECTION FOR ADMIN -> leads.php
        $query = "SELECT l.*, 
            (SELECT username FROM users WHERE id = l.agent_id) as agent_username,
            (SELECT username FROM users WHERE id = l.closer_id) as closer_username,
            (SELECT username FROM users WHERE id = l.verifier_id) as verifier_username
            FROM leads l WHERE (l.admin_status = 'Approved' OR l.status IN ('U', 'DOC', 'DOCUMENTS UPLOADED', 'Approved', 'Paid') OR (l.status LIKE '%DNQ%' AND l.client_name IS NOT NULL AND l.client_name <> '')) ORDER BY l.updated_at DESC LIMIT 100";
        $leads = mysqli_query($conn, $query);

        $leads_html = '';
        while ($row = mysqli_fetch_assoc($leads)) {
            $row = function_exists('decrypt_fields') ? decrypt_fields($row) : $row;
            $row_class = getStatusRowClass($row['status'] ?? '');
            $client_id = getRealtimeLeadClientId($conn, $row['client_name'] ?? '');
            $un_count = 0;
            if (!empty($client_id)) {
                $un_q = $conn->query("SELECT COUNT(*) as unread FROM client_chats WHERE lead_id='{$row['id']}' AND sender='Client' AND is_read=0");
                if ($un_q) $un_count = (int)($un_q->fetch_assoc()['unread'] ?? 0);
            }

            $leads_html .= '<tr class="' . htmlspecialchars($row_class) . '">';
            $leads_html .= '<td style="text-align: center; vertical-align: middle;"><div style="position: relative; display: inline-block; cursor: pointer; padding: 5px;" onclick="openLeadChat(' . intval($row['id']) . ', ' . ($client_id ?: 'null') . ', \'' . htmlspecialchars(addslashes($row['first_name'] . ' ' . $row['last_name'])) . '\')"><i id="chat-icon-' . intval($row['id']) . '" class="fas fa-comment-dots" style="font-size: 18px; color: ' . ($un_count > 0 ? '#28a745' : '#a0aec0') . ';">Chat</i>' . ($un_count > 0 ? '<span id="chat-badge-' . intval($row['id']) . '" style="position: absolute; top: -5px; right: -8px; background: #dc3545; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; border: 2px solid white; line-height: 1;">' . $un_count . '</span>' : '') . '</div></td>';
            $leads_html .= '<td style="text-align: center; vertical-align: middle;">' . (!empty($row['closer_id']) ? '<button type="button" class="btn" style="background-color: #17a2b8; padding: 4px 8px; font-size: 11px; border: none; border-radius: 4px; cursor: pointer;" onclick="openInhouseChat(' . intval($row['id']) . ', ' . intval($row['closer_id']) . ', \'' . htmlspecialchars(addslashes($row['closer_username'] ?? $row['closer_id'])) . '\')">💬 Closer</button>' : '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="vertical">' . htmlspecialchars($row['vertical'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="first_name">' . htmlspecialchars($row['first_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="last_name">' . htmlspecialchars($row['last_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="phone">' . htmlspecialchars($row['phone'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="email">' . htmlspecialchars($row['email'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="address">' . htmlspecialchars($row['address'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="city">' . htmlspecialchars($row['city'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="state">' . htmlspecialchars($row['state'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="zip_code">' . htmlspecialchars($row['zip_code'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="dob">' . htmlspecialchars($row['dob'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="client_code">' . htmlspecialchars($row['client_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="status">' . htmlspecialchars($row['status'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="quick_update">' . htmlspecialchars($row['quick_update'] ?? '') . '</td>';
            $leads_html .= '<td class="app-col"><textarea class="app-textarea autosave-val" data-id="' . intval($row['id']) . '" data-col="application_text">' . htmlspecialchars($row['application_text'] ?? '') . '</textarea></td>';

            // --- NEW COMBINED DETAILS COLUMN ---
            $leads_html .= '<td style="text-align: center; vertical-align: middle;">';
            $leads_html .= '<button type="button" class="btn" style="background: #0A2540; padding: 6px 12px; font-size: 11px; width: 100%; border-radius: 4px;" onclick="openDetailsModal(' . intval($row['id']) . ')">';
            $leads_html .= '<i class="fas fa-folder-open"></i> View Details';
            $leads_html .= '</button>';
            $leads_html .= '</td>';
            // -----------------------------------

            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="agent_name">' . htmlspecialchars($row['agent_username'] ?? $row['agent_name'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="closer_name">' . htmlspecialchars($row['closer_name'] ?? $row['closer_username'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="verifier_name">' . htmlspecialchars($row['verifier_name'] ?? $row['verifier_username'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="referred_by">' . htmlspecialchars($row['referred_by'] ?? '') . '</td>';
            $leads_html .= '<td contenteditable="false" class="sheet-cell finance-cell" data-id="' . intval($row['id']) . '" data-col="payouts" data-real-value="' . htmlspecialchars($row['payouts'] ?? '') . '">****</td>';
            $leads_html .= '<td contenteditable="false" class="sheet-cell finance-cell" data-id="' . intval($row['id']) . '" data-col="incentive" data-real-value="' . htmlspecialchars($row['incentive'] ?? '') . '">****</td>';
            $leads_html .= '<td contenteditable="true" class="sheet-cell" data-id="' . intval($row['id']) . '" data-col="updated_at" style="color: #888; font-size: 11px;">' . date('m/d/y h:ia', strtotime($row['updated_at'] ?? $row['created_at'])) . '</td>';
            $leads_html .= '</tr>';
        }

        echo json_encode(['status' => 'success', 'html' => $leads_html, 'timestamp' => date('Y-m-d H:i:s')]);
        break;

    case 'to_post_report':
        header('Content-Type: application/json');
        $lead_id = (int)($_GET['lead_id'] ?? 0);
        $report_stmt = $conn->prepare('SELECT verifier_application_text FROM leads WHERE id = ? LIMIT 1');
        if (!$report_stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to load verification report.']);
            break;
        }
        $report_stmt->bind_param('i', $lead_id);
        $report_stmt->execute();
        $report_row = $report_stmt->get_result()->fetch_assoc();
        $report_stmt->close();
        if (!$report_row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Lead not found.']);
            break;
        }
        $report = function_exists('decryptData') ? decryptData((string)($report_row['verifier_application_text'] ?? '')) : (string)($report_row['verifier_application_text'] ?? '');
        $report = html_entity_decode((string)$report, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        echo json_encode(['success' => true, 'report' => $report], JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'to_post':
        // Safe where clause implementation
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

        $leads_html = '';
        while ($lead = mysqli_fetch_assoc($leads)) {
            $lead = function_exists('decrypt_fields') ? decrypt_fields($lead) : $lead;
            $lead_id = (int)$lead['id'];
            $unread_query = $conn->query("SELECT COUNT(*) AS unread FROM inhouse_chats WHERE lead_id = $lead_id AND sender_type = 'Closer' AND is_read = 0");
            $unread = $unread_query ? (int)$unread_query->fetch_assoc()['unread'] : 0;
            $front_id = ltrim((string)($lead['id_front_path'] ?? ''), '/');
            $back_id = ltrim((string)($lead['id_back_path'] ?? ''), '/');
            $additional_records = ltrim((string)($lead['additional_records'] ?? ''), '/');
            $customer_name_json = json_encode((string)($lead['customer_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            $leads_html .= '<tr>';
            $leads_html .= '<td><button type="button" class="btn" style="background:#0A2540;color:#fff;position:relative;" onclick="openCombinedChatModal(' . $lead_id . ', ' . (int)($lead['closer_id'] ?? 0) . ', ' . htmlspecialchars($customer_name_json, ENT_QUOTES, 'UTF-8') . ')"><i class="fas fa-comments"></i> Admin' . ($unread > 0 ? '<span id="admin-chat-badge-' . $lead_id . '" style="position:absolute;top:-5px;right:-5px;background:#dc3545;color:white;font-size:10px;font-weight:bold;padding:2px 6px;border-radius:50%;">' . $unread . '</span>' : '') . '</button></td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['vertical'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['first_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['last_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['phone'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['email'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['address'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['city'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['state'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['zip_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['dob'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td><form action="process_post.php" method="POST" style="margin:0;display:flex;flex-direction:column;">';
            $leads_html .= '<input type="hidden" name="lead_id" value="' . $lead_id . '">';
            $leads_html .= '<input type="text" name="client_name" placeholder="Client Code (e.g. C1)" oninput="togglePostBtn(this, ' . $lead_id . ')" required>';
            $leads_html .= '<div style="display:flex;gap:6px;">';
            $leads_html .= '<button type="submit" name="action" value="post" id="post-btn-' . $lead_id . '" class="btn" disabled>Post</button>';
            $leads_html .= '<button type="button" class="btn" onclick="openReverifyModal(' . $lead_id . ')">Reverify</button>';
            $leads_html .= '</div></form></td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['closer_status'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['status'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td><textarea readonly>' . htmlspecialchars($lead['application_text'] ?? '', ENT_QUOTES, 'UTF-8') . '</textarea></td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['alternate_details'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>';
            if ($front_id !== '') $leads_html .= '<a href="../../' . htmlspecialchars($front_id, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">[Front ID]</a><br>';
            if ($back_id !== '') $leads_html .= '<a href="../../' . htmlspecialchars($back_id, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">[Back ID]</a><br>';
            if ($additional_records !== '') $leads_html .= '<a href="../../' . htmlspecialchars($additional_records, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">[Additional]</a>';
            $leads_html .= '</td>';
            $upload_url = !empty($lead['id_upload_token']) ? 'https://consumerhealthcarecompliance.com/uploads?token=' . rawurlencode((string)$lead['id_upload_token']) : '';
            $leads_html .= '<td>' . ($upload_url !== '' ? '<a href="' . htmlspecialchars($upload_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open Link</a>' : '') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['agent_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['referred_by'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['closer_username'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['verifier_username'] ?? $lead['verifier_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['jornaya_leadid'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['trustedform_cert_url'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $leads_html .= '<td>' . htmlspecialchars($lead['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $timestamp = $lead['updated_at'] ?? $lead['created_at'] ?? 'now';
            $leads_html .= '<td>' . date('m/d/y h:ia', strtotime($timestamp)) . '</td>';
            $leads_html .= '</tr>';
        }

        if (mysqli_num_rows($leads) == 0) {
            $leads_html .= '<tr><td colspan="26" style="text-align: center; color: #888; padding: 20px;">No leads currently waiting to be posted.</td></tr>';
        }

        echo json_encode(['status' => 'success', 'html' => $leads_html, 'timestamp' => date('Y-m-d H:i:s')]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
