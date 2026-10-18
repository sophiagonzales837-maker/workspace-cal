<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect('Dev'); // Ensure your security.php protect() function accepts 'Dev'

// Fetch all staff users
$query = "SELECT id, username, role, team, status_state, last_activity, 
          TIMESTAMPDIFF(MINUTE, last_activity, NOW()) as inactive_mins 
          FROM users WHERE role IN ('Agent', 'Closer', 'Verifier') 
          ORDER BY role, username";
$users = mysqli_query($conn, $query);

include 'includes/header.php'; // Assuming you copy/create a header for dev
?>
<div class="main-content" style="padding: 20px;">
    <h1 style="color: var(--primary-navy); margin-bottom: 20px;">Dev Dashboard: Staff Overview</h1>
    
    <div class="card" style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 12px;">User</th>
                    <th style="padding: 12px;">Role</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px;">Last Ping</th>
                </tr>
            </thead>
            <tbody>
                <?php while($u = mysqli_fetch_assoc($users)): 
                    $is_online = ($u['last_activity'] && $u['inactive_mins'] !== null && $u['inactive_mins'] < 5);
                ?>
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 12px;"><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td style="padding: 12px;"><?= htmlspecialchars($u['role']) ?></td>
                    <td style="padding: 12px;">
                        <?php 
                        if (!$is_online) echo '<span style="color:#6c757d; font-weight:bold;">Offline</span>';
                        else echo '<span style="color:#28a745; font-weight:bold;">Active</span>';
                        ?>
                    </td>
                    <td style="padding: 12px; color: #64748b; font-size: 13px;">
                        <?= $u['last_activity'] ? date('M d, h:i A', strtotime($u['last_activity'])) : 'Never' ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'includes/footer.php'; ?>