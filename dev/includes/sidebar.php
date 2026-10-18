<div class="sidebar">
    <div style="text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px; margin-top: 0;">
        <img src="../assets/logo.png" alt="CHC Logo" style="height: 45px; width: auto; max-width: 180px; background: transparent; object-fit: contain;">
    </div>
    
    <div style="margin-bottom: 25px; margin-top: 20px; text-align: center;">
        <p style="color: #BDC3C7; margin: 5px 0; font-size: 13px;">Logged in as:</p>
        <p style="color: white; font-weight: bold; margin: 0;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Dev'); ?></p>
        <p style="color: #60a5fa; font-size: 11px; margin-top: 5px; text-transform: uppercase; letter-spacing: 1px;">Developer</p>
    </div>

    <ul style="list-style-type: none; padding: 0; margin: 0;">
        <li style="margin-bottom: 10px;">
            <a href="index.php" style="color: white; text-decoration: none; font-size: 14px; display: block; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 6px; text-align: center; transition: 0.2s;">
                <i class="fas fa-users" style="margin-right: 8px;"></i> Staff Dashboard
            </a>
        </li>
        <li style="margin-bottom: 10px;">
            <a href="all_leads.php" style="color: white; text-decoration: none; font-size: 14px; display: block; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 6px; text-align: center; transition: 0.2s;">
                <i class="fas fa-database" style="margin-right: 8px;"></i> Database Master
            </a>
        </li>
    </ul>

    <div style="position: absolute; bottom: 30px; width: calc(100% - 50px);">
        <a href="../auth/logout.php" style="color: #ff6b6b; text-decoration: none; display: block; padding: 12px 0; border-top: 1px solid rgba(255,255,255,0.1); font-weight: 600; text-align: center; transition: 0.2s;">
            <i class="fas fa-sign-out-alt" style="margin-right: 8px;"></i> Logout
        </a>
    </div>
</div>