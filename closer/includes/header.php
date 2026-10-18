<?php
$user_id_sidebar = (int)($_SESSION['user_id'] ?? 0);
$sidebar_phone = 'Not Set';
$sidebar_dob = 'Not Set';
$sidebar_address = 'Not Set';
if ($user_id_sidebar > 0 && isset($conn)) {
    $profile_stmt = $conn->prepare('SELECT phone, dob, address FROM users WHERE id = ? LIMIT 1');
    if ($profile_stmt) {
        $profile_stmt->bind_param("i", $user_id_sidebar);
        $profile_stmt->execute();
        $profile_data = $profile_stmt->get_result()->fetch_assoc();
        if ($profile_data) {
            $sidebar_phone = !empty($profile_data['phone']) ? htmlspecialchars($profile_data['phone'], ENT_QUOTES, 'UTF-8') : 'Not Set';
            $sidebar_dob = !empty($profile_data['dob']) ? htmlspecialchars($profile_data['dob'], ENT_QUOTES, 'UTF-8') : 'Not Set';
            $sidebar_address = !empty($profile_data['address']) ? htmlspecialchars($profile_data['address'], ENT_QUOTES, 'UTF-8') : 'Not Set';
        }
        $profile_stmt->close();
    }
}

// --- READ RECEIPT ENGINE: GET UNREAD CHAT COUNT ---
$unread_chat_count = 0;
if (isset($_SESSION['user_id']) && isset($conn)) {
    $uid = (int)$_SESSION['user_id'];
    $role = $_SESSION['role'] ?? '';

    if ($role === 'Admin' || $role === 'Jr. Admin' || $role === 'Jr_Admin') {
        $q = $conn->query("SELECT COUNT(id) as unread FROM inhouse_chats WHERE admin_id = $uid AND sender_type = 'Closer' AND is_read = 0");
    } elseif ($role === 'Closer') {
        $q = $conn->query("SELECT COUNT(id) as unread FROM inhouse_chats WHERE (closer_id = $uid AND sender_type = 'Admin' AND is_read = 0) OR (recipient_id = $uid AND recipient_type = 'Closer' AND is_read = 0)");
    } elseif ($role === 'Agent') {
        $q = $conn->query("SELECT COUNT(id) as unread FROM inhouse_chats WHERE recipient_id = $uid AND recipient_type = 'Agent' AND is_read = 0");
    } elseif ($role === 'Verifier') {
        $q = $conn->query("SELECT COUNT(id) as unread FROM inhouse_chats WHERE recipient_id = $uid AND recipient_type = 'Verifier' AND is_read = 0");
    }
    if (isset($q) && $q) {
        $unread_chat_count = $q->fetch_assoc()['unread'] ?? 0;
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="app-dark-theme">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closer Portal - Consumer Healthcare Compliance</title>

    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            display: block !important;
            padding-top: <?= !empty($is_popup) ? '0' : '85px' ?> !important;
            background-color: #F4F7FA !important;
            color: #1E293B !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .top-nav {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            background-color: #FFFFFF !important;
            padding: 10px 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #E2E8F0;
            z-index: 9999;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            min-width: 1200px;
        }

        .nav-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            width: 100%;
            gap: 20px;
        }

        .col-left {
            justify-self: start;
        }

        .col-center {
            justify-self: center;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .shift-clocks-left {
            margin-left: -120px;
        }

        .col-right {
            justify-self: end;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .brand-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 12px;
        }

        .brand-group img {
            height: 45px;
            width: auto;
            max-width: 180px;
            background: #F8FAFC;
            padding: 4px 10px;
            border-radius: 6px;
            object-fit: contain;
        }

        .agent-name {
            color: #64748B;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .agent-name span {
            color: #F8FAFC;
        }

        .nav-links-center {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .nav-btn {
            color: #1E293B;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            background: #F4F7FA;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
        }

        .nav-btn:hover {
            background: #3563A9;
            color: #ffffff;
            border-color: #3563A9;
        }

        .nav-btn.active {
            background: #3563A9 !important;
            color: #ffffff !important;
            border-color: #3563A9 !important;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }

        .nav-btn.nav-logout {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.3);
            padding: 6px 12px;
            font-size: 12px;
        }

        .nav-btn.nav-logout:hover {
            background: #ef4444;
            color: #ffffff;
        }

        .nav-btn.nav-updates {
            color: #3563A9;
            background: rgba(53, 99, 169, 0.12);
            border-color: rgba(53, 99, 169, 0.3);
        }

        .nav-btn.nav-updates:hover {
            background: #264A82;
            color: #ffffff;
        }

        .top-nav .notepad-btn {
            padding: 6px 14px;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-top: 4px solid #fbbf24;
            border-radius: 6px !important;
            border-radius: 6px;
            box-shadow: 2px 4px 6px rgba(0, 0, 0, 0.1);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .top-nav .notepad-btn:hover {
            color: #92400e;
            background: #fef3c7;
            border-color: #fcd34d;
            border-top-color: #fbbf24;
            box-shadow: 3px 6px 10px rgba(0, 0, 0, 0.18);
            transform: translateY(-2px);
        }

        .top-nav .notepad-btn i {
            margin-right: 5px;
            color: #92400e;
        }

        /* Notification Badge Styling */
        .chat-nav-badge {
            background: #ef4444;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 11px;
            margin-left: 6px;
            font-weight: bold;
            line-height: 1;
            display: inline-block;
        }

        .main,
        .main-content {
            margin-left: 0 !important;
            padding: 20px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            display: block !important;
        }

        /* Criteria Matrix Modal CSS */
        .matrix-wrapper {
            width: 100%;
            height: 75vh;
            overflow: auto;
            background: #eceff1;
            position: relative;
        }

        .excel-table {
            border-collapse: collapse;
            min-width: max-content;
            font-family: Arial, sans-serif;
        }

        .excel-table th,
        .excel-table td {
            border: 1px solid #9e9e9e;
            vertical-align: top;
            min-width: 250px;
        }

        .excel-header-row th {
            background-color: #fff176 !important;
            color: #000;
            font-weight: 800;
            font-size: 16px;
            text-align: center;
            padding: 15px;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #000;
        }

        .excel-row-header {
            background-color: #fff176 !important;
            color: #000;
            font-weight: 800;
            font-size: 14px;
            text-transform: uppercase;
            padding: 15px;
            position: sticky;
            left: 0;
            z-index: 5;
            border-right: 2px solid #000;
            text-align: center;
            vertical-align: middle !important;
            width: 150px;
            min-width: 150px !important;
        }

        .excel-corner {
            z-index: 15 !important;
            left: 0;
        }

        .cell-active {
            background-color: #aed581 !important;
        }

        .cell-inactive {
            background-color: #ffffff !important;
        }

        .matrix-cell {
            position: relative;
            padding: 8px;
            height: 100%;
            min-height: 150px;
            display: flex;
            flex-direction: column;
        }

        .cell-textarea {
            width: 100%;
            border: 1px dashed transparent;
            background: transparent;
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            resize: none;
            overflow: hidden;
            padding: 2px;
            margin-bottom: 2px;
            cursor: default;
        }

        .cell-textarea::placeholder {
            color: rgba(0, 0, 0, 0.3);
            font-style: italic;
        }
    </style>
</head>

<body class="app-dark-theme">

    <?php if (empty($is_popup)): ?>
        <header class="top-nav">
            <div class="nav-row">
                <div class="col-left brand-group">
                    <img src="../assets/logo.png" alt="CHC Logo" onerror="this.src=''; this.alt='CHC LOGO'; this.style.background='transparent'; this.style.color='#fff'; this.style.fontWeight='bold';">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <div class="agent-name">Closer: <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></span></div>
                        <div style="margin-top: 5px; font-size: 11px; color: #94a3b8; text-align: left; line-height: 1.6; display: flex; flex-direction: column; gap: 4px;">
                            <div><i class="fas fa-phone-alt" style="width: 14px; color: #64748b;"></i> <?= $sidebar_phone ?></div>
                            <div><i class="fas fa-birthday-cake" style="width: 14px; color: #64748b;"></i> <?= $sidebar_dob ?></div>
                            <div style="border-top: 1px solid #334155; padding-top: 4px; margin-top: 2px;"><i class="fas fa-home" style="width: 14px; color: #64748b;"></i> <?= $sidebar_address ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-center shift-clocks-left">
                    <?php
                    if (file_exists(__DIR__ . '/../../shared/us_clock.php')) {
                        include __DIR__ . '/../../shared/us_clock.php';
                    }
                    ?>
                </div>

                <div class="col-right">
                    <button type="button" onclick="toggleCriteriaModal()" class="nav-btn" style="background: #17a2b8; border-color: #17a2b8; color: white;">View Criteria</button>
                </div>
            </div>

            <div class="nav-row">
                <div class="col-left">
                    <a href="../auth/logout.php" class="nav-btn nav-logout"><i class="fas fa-power-off" style="margin-right: 5px;"></i> Logout</a>
                </div>

                <div class="col-center nav-links-center">
                    <a href="index.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">Dashboard</a>
                    <a href="transferred_lead.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'transferred_lead.php') ? 'active' : ''; ?>">Agent Transfered</a>
                    <a href="reference_sheet.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'reference_sheet.php') ? 'active' : ''; ?>">Sheet</a>
                    <a href="posted_leads.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'posted_leads.php') ? 'active' : ''; ?>">
                        Posted Leads <?php if ($unread_chat_count > 0): ?><span class="chat-nav-badge"><?php echo $unread_chat_count; ?></span><?php endif; ?>
                    </a>
                    <a href="add_lead.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'add_lead.php') ? 'active' : ''; ?>">Add Leads</a>
                    <a href="time_data.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'time_data.php') ? 'active' : ''; ?>">Data</a>
                    <button onclick="window.open('https://employees.kingstonsolutions.co.in/workspace/floating_script.php?type=closer', '_blank')" class="nav-btn" style="background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border-color: rgba(139, 92, 246, 0.3);">
                        <i class="fas fa-file-alt" style="margin-right: 5px;"></i> View Script
                    </button>
                    <button type="button" id="landline-btn" class="top-nav-btn nav-btn" onclick="openZoomPhone()">
                        <i class="fas fa-phone-alt" style="margin-right: 6px;"></i> Landline
                    </button>
                    <button type="button" id="whatsapp-btn" class="top-nav-btn nav-btn" onclick="openWhatsApp()" style="color: #15803d; border-color: #bbf7d0; background: #f0fdf4;">
                        <i class="fab fa-whatsapp" style="margin-right: 6px; font-size: 15px;"></i> WhatsApp
                    </button>
                    <button onclick="if(window.electronAPI) window.electronAPI.openFloatingWidget('notepad', 'floating_notepad.php', 400, 550)" class="notepad-btn">
                        <i class="fas fa-sticky-note"></i> Notepad
                    </button>
                    <div style="display: flex; align-items: center; gap: 8px; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; border: 1px solid #7dd3fc; margin-left: 10px;">
                        <button type="button" onclick="launchInternalBrowser()" style="background: #0ea5e9; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer;">
                            <i class="fas fa-globe"></i> Browser
                        </button>
                        <label style="font-size: 12px; color: #0369a1; font-weight: bold; display: flex; align-items: center; gap: 4px; cursor: pointer; user-select: none;">
                            <input type="checkbox" id="cal-vpn-toggle" onchange="toggleCalVpnDropdown()"> VPN
                        </label>
                        <select id="cal-vpn-location" style="display: none; background: white; border: 1px solid #bae6fd; color: #0369a1; font-weight: 600; font-size: 12px; padding: 2px 4px; border-radius: 4px; outline: none; cursor: pointer;">
                            <option value="us-ny.prod.surfshark.com:80">New York</option>
                            <option value="us-mia.prod.surfshark.com:80">Miami</option>
                            <option value="us-dal.prod.surfshark.com:80">Dallas</option>
                            <option value="us-sf.prod.surfshark.com:80">San Francisco</option>
                            <option value="us-chi.prod.surfshark.com:80">Chicago</option>
                        </select>
                    </div>
                    <a href="script.php" class="nav-btn <?php echo (basename($_SERVER['PHP_SELF']) == 'script.php') ? 'active' : ''; ?>">Links</a>
                </div>

                <div class="col-right">
                    <a href="../shared/app_portal.php" class="nav-btn nav-updates" style="position: relative; display: inline-flex; align-items: center;">
                        <i class="fas fa-sync" style="margin-right: 5px;"></i> Updates
                    </a>
                </div>
            </div>
        </header>
    <?php endif; ?>

    <script>
        let zoomOpen = false;
        let whatsappOpen = false;

        function openZoomPhone() {
            if (window.electronAPI && typeof window.electronAPI.toggleZoomDialer === 'function') {
                window.electronAPI.toggleZoomDialer(!zoomOpen);
            }
        }

        function openWhatsApp() {
            if (window.electronAPI && typeof window.electronAPI.toggleWhatsApp === 'function') {
                window.electronAPI.toggleWhatsApp(!whatsappOpen);
            }
        }

        function toggleCalVpnDropdown() {
            const isVpnOn = document.getElementById('cal-vpn-toggle').checked;
            document.getElementById('cal-vpn-location').style.display = isVpnOn ? 'block' : 'none';
        }

        function launchInternalBrowser() {
            const isVpnOn = document.getElementById('cal-vpn-toggle').checked;
            const selectedProxy = document.getElementById('cal-vpn-location').value;

            if (window.electronAPI && typeof window.electronAPI.openInternalBrowser === 'function') {
                window.electronAPI.openInternalBrowser({
                    useVpn: isVpnOn,
                    proxyUrl: selectedProxy
                });
            } else {
                console.error("Electron API not found. Please ensure preload.js is loaded.");
            }
        }

        document.addEventListener("DOMContentLoaded", () => {
            // Sync Zoom state from Electron
            if (window.electronAPI && typeof window.electronAPI.onZoomDialerState === 'function') {
                window.electronAPI.onZoomDialerState((isVisible) => {
                    zoomOpen = isVisible;
                    const btn = document.getElementById('landline-btn');
                    if (btn) btn.classList.toggle('active', zoomOpen);
                });
            }

            // Sync WhatsApp state from Electron
            if (window.electronAPI && typeof window.electronAPI.onWhatsAppState === 'function') {
                window.electronAPI.onWhatsAppState((isVisible) => {
                    whatsappOpen = isVisible;
                    const btn = document.getElementById('whatsapp-btn');
                    if (btn) {
                        if (isVisible) {
                            btn.style.background = '#16a34a';
                            btn.style.color = '#ffffff';
                        } else {
                            btn.style.background = '#f0fdf4';
                            btn.style.color = '#15803d';
                        }
                    }
                });
            }

            // Existing chat widget loader
            if (window.top === window.self && window.electronAPI && typeof window.electronAPI.openFloatingWidget === 'function') {
                window.electronAPI.openFloatingWidget('chatWidget', 'floating_chat.php', 80, 80);
            }
        });
    </script>

    <!-- CRITERIA MATRIX MODAL -->
    <div id="criteriaModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
        <div style="width: 100%; max-width: 95vw; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 80px rgba(0,0,0,0.3);">
            <div style="background: #142030; color: white; padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; gap: 15px;">
                <div>
                    <h2 style="margin: 0; font-size: 18px;">Inhouse Criteria Matrix</h2>
                    <p style="margin: 6px 0 0; font-size: 13px; color: #d1d5db;">Active criteria rules established by your Admin team (Read-Only).</p>
                </div>
                <button type="button" onclick="closeCriteriaModal()" style="background: none; border: none; color: white; font-size: 32px; line-height: 1; cursor: pointer;">&times;</button>
            </div>
            <div id="criteriaModalBody" style="padding: 0; background: #eceff1;">
                <div style="padding: 40px; text-align: center; color: #667085; font-size: 16px; font-weight: bold;">Loading criteria matrix...</div>
            </div>
        </div>
    </div>

    <script>
        function toggleCriteriaModal() {
            const modal = document.getElementById('criteriaModal');
            if (modal.style.display === 'flex') {
                modal.style.display = 'none';
            } else {
                modal.style.display = 'flex';
                loadCriteriaData();
            }
        }

        function closeCriteriaModal() {
            document.getElementById('criteriaModal').style.display = 'none';
        }

        function loadCriteriaData() {
            const url = '/workspace/closer/criteria_popup.php?t=' + new Date().getTime();
            fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'include'
                })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
                    return response.text();
                })
                .then(html => {
                    const body = document.getElementById('criteriaModalBody');
                    body.innerHTML = html;
                    document.querySelectorAll('#criteriaModalBody .cell-textarea').forEach(textarea => {
                        if (textarea.value.trim() !== '') {
                            textarea.style.height = 'auto';
                            textarea.style.height = textarea.scrollHeight + 'px';
                        }
                    });
                })
                .catch((error) => {
                    console.error("Criteria Fetch Error:", error);
                    document.getElementById('criteriaModalBody').innerHTML = `<div style="padding: 40px; text-align: center; color: #cc3534; font-weight: bold;">Unable to load criteria.<br><br><span style="font-size: 12px; font-weight: normal; color: #666;">Details: ${error.message}</span></div>`;
                });
        }
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('criteriaModal');
            if (event.target === modal) closeCriteriaModal();
        });
    </script>

    <!-- Main Content Area Starts Here -->
    <div class="main-content">