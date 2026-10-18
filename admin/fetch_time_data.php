<?php 
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin');

$query = "SELECT id, username, role, team, status_state, last_activity,
                 TIMESTAMPDIFF(MINUTE, last_activity, NOW()) AS inactive_mins
          FROM users
          WHERE role NOT IN ('Admin', 'Jr_Admin', 'Jr. Admin', 'Jr Admin', 'Dev')
          ORDER BY role, username";
$users = mysqli_query($conn, $query);

while ($u = mysqli_fetch_assoc($users)):
    $is_online = $u['status_state'] === 'Active'
        && $u['last_activity']
        && $u['inactive_mins'] !== null
        && (int)$u['inactive_mins'] < 5;
    ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
        <td><?php echo htmlspecialchars($u['role']); ?></td>
        <td><?php echo htmlspecialchars($u['team']); ?></td>
        <td><?php echo $is_online ? '<span style="color:#28a745; font-weight:bold;">Active</span>' : '<span style="color:#6c757d; font-weight:bold;">Offline</span>'; ?></td>
        <td><?php echo $u['last_activity'] ? date('M d, h:i A', strtotime($u['last_activity'])) : 'Never'; ?></td>
    </tr>
<?php endwhile; ?>