<?php
$is_embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
$current_page = basename($_SERVER['PHP_SELF']);
$is_admin_dark_theme = true;

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
<html lang="en" class="app-dark-theme <?php echo $is_admin_dark_theme ? 'admin-dark-theme' : ''; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jr. Admin Portal - Consumer Healthcare Compliance</title>

    <link rel="stylesheet" href="../assets/style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* Force Reset & Base Body Styling */
        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            display: block !important;
            padding-top: 140px !important;
            background-color: #F4F7FA !important;
            color: #1E293B !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            overflow-x: hidden !important;
        }

        /* Top Header Container */
        #master-top-nav {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            height: auto !important;
            background-color: #FFFFFF !important;
            padding: 12px 30px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 12px !important;
            border-bottom: 1px solid #E2E8F0 !important;
            z-index: 999999 !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4) !important;
            box-sizing: border-box !important;
        }

        .nav-row-custom {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            width: 100% !important;
            gap: 15px !important;
        }

        .col-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .col-center {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .col-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-group img {
            height: 45px !important;
            width: auto !important;
            max-width: 180px !important;
            background: #F8FAFC !important;
            padding: 4px 10px !important;
            border-radius: 6px !important;
            object-fit: contain !important;
        }

        .agent-name {
            color: #94a3b8 !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            letter-spacing: 0.5px !important;
            white-space: nowrap !important;
        }

        .agent-name span {
            color: #ffffff !important;
        }

        .top-nav-btn {
            color: #e2e8f0 !important;
            text-decoration: none !important;
            padding: 6px 12px !important;
            border-radius: 6px !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            transition: all 0.2s ease-in-out !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            white-space: nowrap !important;
            box-sizing: border-box !important;
        }

        .top-nav-btn:hover {
            background: #007BFF !important;
            color: #ffffff !important;
            border-color: #007BFF !important;
        }

        .top-nav-btn.active {
            background: #007BFF !important;
            color: #ffffff !important;
            border-color: #007BFF !important;
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3) !important;
        }

        .top-nav-btn.nav-logout {
            background: rgba(239, 68, 68, 0.15) !important;
            color: #fca5a5 !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
        }

        .top-nav-btn.nav-logout:hover {
            background: #ef4444 !important;
            color: #ffffff !important;
        }

        #main-wrapper,
        .main {
            margin-left: 0 !important;
            padding: 20px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            display: block !important;
            background-color: #F4F7FA !important;
            color: #1E293B !important;
        }

        .nav-btn.nav-updates:hover {
            background: #38bdf8;
            color: #ffffff;
        }

        #master-top-nav .agent-name {
            color: #64748B !important;
        }

        #master-top-nav .agent-name span {
            color: #142030 !important;
        }

        #master-top-nav .top-nav-btn:not(.active):not(.nav-logout) {
            color: #1E293B !important;
            background: #F4F7FA !important;
            border-color: #E2E8F0 !important;
        }

        #master-top-nav .top-nav-btn:hover,
        #master-top-nav .top-nav-btn.active {
            background: #3563A9 !important;
            color: #FFFFFF !important;
            border-color: #3563A9 !important;
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

        .sidebar,
        #sidebar,
        aside,
        .top-nav {
            display: none !important;
            width: 0 !important;
        }

        body.embedded {
            padding-top: 0 !important;
            background: #F4F7FA !important;
            overflow-x: auto !important;
        }

        body.embedded #master-top-nav {
            display: none !important;
        }

        body.embedded #main-wrapper {
            padding: 0 !important;
            background: #F4F7FA !important;
        }

        body.embedded #admin-chat-widget {
            display: none !important;
        }

        body.admin-dark-theme {
            position: relative;
            color-scheme: dark;
            background-color: #030712 !important;
            background-image:
                radial-gradient(ellipse at 12% 0%, rgba(8, 145, 178, .17), transparent 38%),
                radial-gradient(ellipse at 92% 18%, rgba(37, 99, 235, .12), transparent 32%),
                radial-gradient(rgba(148, 163, 184, .13) .7px, transparent .7px) !important;
            background-size: auto, auto, 22px 22px !important;
            color: #f8fafc !important;
        }

        html.admin-dark-theme {
            background-color: #030712 !important;
        }

        body.admin-dark-theme #master-top-nav {
            background: rgba(3, 7, 18, .92) !important;
            border-bottom-color: rgba(56, 189, 248, .24) !important;
            box-shadow: 0 12px 32px rgba(0, 0, 0, .38) !important;
            backdrop-filter: blur(18px);
        }

        body.admin-dark-theme #master-top-nav .agent-name,
        body.admin-dark-theme #master-top-nav .agent-name span {
            color: #cbd5e1 !important;
        }

        body.admin-dark-theme #master-top-nav .top-nav-btn:not(.nav-logout) {
            color: #cbd5e1 !important;
            background: rgba(15, 23, 42, .78) !important;
            border-color: rgba(56, 189, 248, .18) !important;
        }

        body.admin-dark-theme #master-top-nav .top-nav-btn:hover,
        body.admin-dark-theme #master-top-nav .top-nav-btn.active {
            color: #f8fafc !important;
            background: rgba(8, 145, 178, .34) !important;
            border-color: rgba(56, 189, 248, .62) !important;
        }

        body.admin-dark-theme #main-wrapper,
        body.admin-dark-theme .main {
            position: relative;
            z-index: 1;
            background: transparent !important;
            color: #f8fafc !important;
        }

        body.admin-dark-theme :is(.card, .tracking-panel, .stat-card) {
            color: #f8fafc !important;
            background: rgba(15, 23, 42, .82) !important;
            border: 1px solid rgba(56, 189, 248, .2) !important;
            box-shadow: 0 18px 48px rgba(0, 0, 0, .2);
            backdrop-filter: blur(14px);
        }

        body.admin-dark-theme :is(h1, h2, h3, h4, p, label, .dashboard-heading h1, .dashboard-heading p, .tracking-heading h2) {
            color: #e2e8f0 !important;
        }

        body.admin-dark-theme .tracking-heading {
            background: rgba(15, 23, 42, .9) !important;
            border-bottom-color: rgba(56, 189, 248, .2) !important;
        }

        body.admin-dark-theme table.table {
            color: #f8fafc !important;
            border-collapse: collapse;
        }

        body.admin-dark-theme table.table th {
            color: #f8fafc !important;
            background: #0f172a !important;
            border: 1px solid rgba(56, 189, 248, .2) !important;
        }

        body.admin-dark-theme table.table td {
            color: #e2e8f0 !important;
            background: rgba(15, 23, 42, .38) !important;
            border: 1px solid rgba(56, 189, 248, .14) !important;
        }

        body.admin-dark-theme table.table tbody tr:hover td {
            background: rgba(56, 189, 248, .1) !important;
            color: #f8fafc !important;
        }

        body.admin-dark-theme table.table tbody tr:nth-child(even):not(.empty-unassigned-row):not(:hover) td {
            background: rgba(30, 41, 59, .4) !important;
            color: #f8fafc !important;
        }

        body.admin-dark-theme :is(input, select, textarea, .input-style) {
            color: #f8fafc !important;
            background-color: #0b1220 !important;
            border-color: rgba(148, 163, 184, .35) !important;
        }

        body.admin-dark-theme :is(input, select, textarea, .input-style)::placeholder {
            color: #94a3b8 !important;
        }

        body.admin-dark-theme #addUserModal {
            background: rgba(2, 6, 23, .76) !important;
            backdrop-filter: blur(10px);
        }

        body.admin-dark-theme #addUserModal>div {
            max-width: calc(100vw - 32px);
            color: #f8fafc !important;
            background: rgba(15, 23, 42, .96) !important;
            border: 1px solid rgba(56, 189, 248, .3);
            box-shadow: 0 24px 80px rgba(0, 0, 0, .55) !important;
        }

        body.admin-dark-theme .table-filter-bar {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 10px;
        }

        body.admin-dark-theme .table-filter-search {
            width: min(100%, 320px);
            padding: 9px 12px !important;
            border: 1px solid rgba(56, 189, 248, .25) !important;
            border-radius: 6px;
        }

        body.admin-dark-theme .column-filter {
            position: relative;
            display: inline-block;
            margin-left: 6px;
            vertical-align: middle;
        }

        body.admin-dark-theme .column-filter>button {
            padding: 4px 6px;
            color: #7dd3fc;
            background: transparent;
            border: 0;
            border-radius: 4px;
            cursor: pointer;
        }

        body.admin-dark-theme .column-filter>button:hover,
        body.admin-dark-theme .column-filter>button[aria-expanded="true"] {
            background: rgba(56, 189, 248, .16);
        }

        body.admin-dark-theme .column-filter-menu {
            position: fixed;
            z-index: 20;
            min-width: 210px;
            max-height: 260px;
            overflow: auto;
            padding: 10px;
            color: #e2e8f0;
            background: #0b1220;
            border: 1px solid rgba(56, 189, 248, .3);
            border-radius: 6px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, .48);
            text-align: left;
        }

        body.admin-dark-theme .column-filter-menu label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 2px;
            font-size: 12px;
            font-weight: 400;
            cursor: pointer;
        }

        body.admin-dark-theme .column-filter-menu input {
            accent-color: #38bdf8;
        }

        @media (max-width: 650px) {
            body.admin-dark-theme #master-top-nav {
                padding: 10px 12px !important;
            }

            body.admin-dark-theme #master-top-nav .col-center {
                justify-content: flex-start;
            }
        }
    </style>
</head>

<body class="app-dark-theme <?php echo ($is_embedded ? 'embedded ' : '') . ($is_admin_dark_theme ? 'admin-dark-theme' : ''); ?>">

    <div id="master-top-nav">
        <div class="nav-row-custom">
            <div class="col-left brand-group">
                <img src="../assets/logo.png" alt="CHC Logo" onerror="this.src=''; this.alt='CHC LOGO'; this.style.background='transparent'; this.style.color='#fff'; this.style.fontWeight='bold';">
                <div class="agent-name">Jr. Admin: <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?></span></div>
            </div>

            <div class="col-center">
                <?php
                if (file_exists(__DIR__ . '/../../shared/us_clock.php')) {
                    include __DIR__ . '/../../shared/us_clock.php';
                }
                ?>
            </div>

            <div class="col-right"></div>
        </div>

        <div class="nav-row-custom">
            <div class="col-left">
                <a href="../auth/logout.php" class="top-nav-btn nav-logout"><i class="fas fa-power-off" style="margin-right: 5px;"></i> Logout</a>
            </div>

            <div class="col-center">
                <a href="index.php" class="top-nav-btn <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">Dashboard</a>
                <a href="criteria.php" class="top-nav-btn <?php echo (in_array($current_page, ['criteria.php', 'inhouse_criteria.php', 'manage_templates.php'], true)) ? 'active' : ''; ?>">Criteria</a>
                <a href="scripts.php" class="top-nav-btn <?php echo (in_array($current_page, ['scripts.php', 'manage_scripts.php'], true)) ? 'active' : ''; ?>">Scripts</a>
                <a href="data_management.php" class="top-nav-btn <?php echo (in_array($current_page, ['data_management.php', 'data_pipeline.php', 'data_gen.php'], true)) ? 'active' : ''; ?>">Data</a>
                <a href="verifier_quiz.php" class="top-nav-btn <?php echo (in_array($current_page, ['verifier_quiz.php', 'manage_quiz.php'], true)) ? 'active' : ''; ?>">Verifier Quiz</a>
                <button type="button" onclick="if(window.electronAPI) window.electronAPI.openWidget('notepad', 'floating_notepad.php', 400, 550)" class="top-nav-btn">
                    <i class="fas fa-sticky-note" style="margin-right:5px;"></i> Notepad
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
    </div>

    <script>
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
            if (window.top === window.self && window.electronAPI && typeof window.electronAPI.openWidget === 'function') {
                window.electronAPI.openWidget('chatWidget', 'floating_chat.php', 80, 80);
            }
        });
    </script>

    <!-- Main Content Area Starts Here -->
    <div id="main-wrapper" class="main">