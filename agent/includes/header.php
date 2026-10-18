<?php
$user_id_sidebar = (int)($_SESSION['user_id'] ?? 0);
$sidebar_phone = 'Not Set';
$sidebar_dob = 'Not Set';
$sidebar_address = 'Not Set';
if ($user_id_sidebar > 0 && isset($conn)) {
    $profile_stmt = $conn->prepare('SELECT phone, dob, address FROM users WHERE id = ? LIMIT 1');
    if ($profile_stmt) {
        $profile_stmt->bind_param('i', $user_id_sidebar);
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
    <title>Agent Portal - Consumer Healthcare Compliance</title>

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
            padding-top: 130px !important;
            background-color: #F4F7FA !important;
            color: #1E293B !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            overflow-x: hidden !important;
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
        }

        .col-left {
            justify-self: start;
        }

        .col-center {
            justify-self: center;
        }

        .col-right {
            justify-self: end;
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
    </style>
</head>

<body class="app-dark-theme">

    <header class="top-nav">
        <div class="nav-row">
            <div class="col-left brand-group">
                <img src="../assets/logo.png" alt="CHC Logo" onerror="this.src=''; this.alt='CHC LOGO'; this.style.background='transparent'; this.style.color='#fff'; this.style.fontWeight='bold';">
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <div class="agent-name">Agent: <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></span></div>
                    <div style="margin-top: 5px; font-size: 11px; color: #94a3b8; text-align: left; line-height: 1.6; display: flex; flex-direction: column; gap: 4px;">
                        <div><i class="fas fa-phone-alt" style="width: 14px; color: #64748b;"></i> <?= $sidebar_phone ?></div>
                        <div><i class="fas fa-birthday-cake" style="width: 14px; color: #64748b;"></i> <?= $sidebar_dob ?></div>
                        <div style="border-top: 1px solid #334155; padding-top: 4px; margin-top: 2px;"><i class="fas fa-home" style="width: 14px; color: #64748b;"></i> <?= $sidebar_address ?></div>
                    </div>
                </div>
            </div>

            <div class="col-center">
                <?php
                if (file_exists(__DIR__ . '/../../shared/us_clock.php')) {
                    include __DIR__ . '/../../shared/us_clock.php';
                }
                ?>
            </div>

            <div class="col-right">
            </div>
        </div>

        <div class="nav-row">
            <div class="col-left">
                <a href="../auth/logout.php" class="nav-btn nav-logout"><i class="fas fa-power-off" style="margin-right: 5px;"></i> Logout</a>
            </div>

            <div class="col-center nav-links-center">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <a href="index.php" class="nav-btn <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">Add Leads</a>
                <a href="my_leads.php" class="nav-btn <?php echo ($current_page == 'my_leads.php') ? 'active' : ''; ?>">
                    My Leads <?php if ($unread_chat_count > 0): ?><span class="chat-nav-badge"><?php echo $unread_chat_count; ?></span><?php endif; ?>
                </a>

                <a href="time_data.php" class="nav-btn <?php echo ($current_page == 'time_data.php') ? 'active' : ''; ?>">Data</a>

                <button onclick="window.open('https://employees.kingstonsolutions.co.in/workspace/floating_script.php?type=agent', '_blank')" class="nav-btn" style="background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border-color: rgba(139, 92, 246, 0.3);">
                    <i class="fas fa-file-alt" style="margin-right: 5px;"></i> View Script
                </button>
                <button type="button" id="landline-btn" class="top-nav-btn nav-btn" onclick="openZoomPhone()">
                    <i class="fas fa-phone-alt" style="margin-right: 6px;"></i> Landline
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
                        <option value="http://us-ny.prod.surfshark.com:80">New York</option>
                        <option value="http://us-mia.prod.surfshark.com:80">Miami</option>
                        <option value="http://us-dal.prod.surfshark.com:80">Dallas</option>
                        <option value="http://us-sf.prod.surfshark.com:80">San Francisco</option>
                        <option value="http://us-chi.prod.surfshark.com:80">Chicago</option>
                    </select>
                </div>

            </div>

            <div class="col-right">
                <a href="../shared/app_portal.php" class="nav-btn nav-updates" style="position: relative; display: inline-flex; align-items: center;">
                    <i class="fas fa-sync" style="margin-right: 5px;"></i> Updates
                </a>
            </div>
        </div>
    </header>

    <script>
        let zoomOpen = false;

        function openZoomPhone() {
            if (window.electronAPI && typeof window.electronAPI.toggleZoomDialer === 'function') {
                window.electronAPI.toggleZoomDialer(!zoomOpen);
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
                window.electronAPI.openInternalBrowser({ useVpn: isVpnOn, proxyUrl: selectedProxy });
            } else {
                console.error("Electron API not found. Please ensure preload.js is loaded.");
            }
        }

        document.addEventListener("DOMContentLoaded", () => {
            // Sync button state from Electron
            if (window.electronAPI && typeof window.electronAPI.onZoomDialerState === 'function') {
                window.electronAPI.onZoomDialerState((isVisible) => {
                    zoomOpen = isVisible;
                    const btn = document.getElementById('landline-btn');
                    if (btn) btn.classList.toggle('active', zoomOpen);
                });
            }

            // Existing chat widget loader
            if (window.top === window.self && window.electronAPI && typeof window.electronAPI.openFloatingWidget === 'function') {
                window.electronAPI.openFloatingWidget('chatWidget', 'floating_chat.php', 80, 80);
            }
        });
    </script>

    <!-- Main Content Area Starts Here -->
    <div class="main-content">