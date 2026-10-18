<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once 'includes/sync_user_to_live.php';
protect('Admin');
$id = (int)$_GET['id'];

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'logout') {
        mysqli_query($conn, "UPDATE users SET force_logout = 1 WHERE id = '$id'");
    }
    if ($_POST['action'] == 'remove') {
        mysqli_query($conn, "DELETE FROM users WHERE id = '$id'");
        session_write_close();
        header("Location: users.php");
        exit();
    }
    if ($_POST['action'] == 'update_pw') {
        $pw = password_hash($_POST['new_pw'], PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password = '$pw' WHERE id = '$id'");
    }
    if ($_POST['action'] == 'update_ip') {
        $ip = mysqli_real_escape_string($conn, trim($_POST['allowed_ip']));
        mysqli_query($conn, "UPDATE users SET allowed_ip = '$ip' WHERE id = '$id'");
    }
    if ($_POST['action'] == 'update_details') {
        $new_username = mysqli_real_escape_string($conn, trim($_POST['username']));
        $new_role = mysqli_real_escape_string($conn, trim($_POST['role']));
        $new_team = mysqli_real_escape_string($conn, trim($_POST['team']));

        mysqli_query($conn, "UPDATE users SET username = '$new_username', role = '$new_role', team = '$new_team' WHERE id = '$id'");
    }
    if (in_array($_POST['action'], ['update_pw', 'update_details'], true)) {
        $sync_ok = syncUserToLive($conn, $id);
        $success = $sync_ok ? 'Action performed and synced to Live.' : 'Action saved locally, but Live synchronization failed.';
    } else {
        $success = 'Action performed successfully.';
    }
}

$u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = '$id'"));
$team_options = [];
$team_query = mysqli_query($conn, 'SELECT name FROM teams ORDER BY name ASC');
if ($team_query) {
    while ($team_row = mysqli_fetch_assoc($team_query)) {
        $team_options[] = (string)$team_row['name'];
    }
}
$assigned_team = trim((string)($u['team'] ?? 'None'));
if ($assigned_team !== '' && $assigned_team !== 'None' && !in_array($assigned_team, $team_options, true)) {
    array_unshift($team_options, $assigned_team);
}
include 'includes/header.php';
?>
<div style="margin-bottom: 20px;"><a href="users.php" style="text-decoration: none; color: var(--electric-blue); font-weight: bold;">&larr; Back to Users</a></div>
<div class="card" style="max-width: 600px; margin: auto;">
    <h3>Manage User: <?php echo htmlspecialchars($u['username']); ?></h3>
    <?php if (isset($success)) echo "<p style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; font-weight: bold;'>✅ $success</p>"; ?>

    <form method="POST" style="margin-top: 20px; border-bottom:1px solid #eee; padding-bottom:15px;">
        <input type="hidden" name="action" value="update_details">
        <h4 style="margin-top: 0; margin-bottom: 15px; color: var(--primary-navy);">Update User Details</h4>

        <div style="margin-bottom: 12px;">
            <label style="display:block; margin-bottom:5px;">Username</label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($u['username']); ?>" required style="width: 100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 12px;">
            <label style="display:block; margin-bottom:5px;">Role</label>
            <select name="role" required style="width: 100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;">
                <?php
                $roles = ['Agent', 'Closer', 'Verifier', 'Admin', 'Auditor', 'QC'];
                $current_role = in_array($u['role'], ['Jr. Admin', 'Jr Admin', 'Jr_Admin'], true) ? 'Admin' : $u['role'];
                foreach ($roles as $r) {
                    $sel = ($current_role == $r) ? 'selected' : '';
                    echo "<option value='$r' $sel>$r</option>";
                }
                ?>
            </select>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:5px;">Team</label>
            <select name="team" required style="width: 100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;">
                <option value="None" <?= $assigned_team === 'None' || $assigned_team === '' ? 'selected' : '' ?>>None</option>
                <?php foreach ($team_options as $team_name): ?>
                    <option value="<?= htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8') ?>" <?= $assigned_team === $team_name ? 'selected' : '' ?>><?= htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn" style="background: var(--primary-navy);">Save Details</button>
    </form>

    <?php
    if ($u['role'] == 'Agent') {
        if (isset($_POST['update_assignment'])) {
            $c_id = (int)$_POST['parent_id'];
            mysqli_query($conn, "UPDATE users SET parent_id = '$c_id' WHERE id = '$id'");
            echo "<p style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; font-weight: bold; margin-top:15px;'>✅ Assignment updated.</p>";
            $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = '$id'"));
        }
    ?>
        <form method="POST" style="margin-top: 20px; border-bottom:1px solid #eee; padding-bottom:15px;">
            <label style="display:block; margin-bottom:5px;">Assign to Closer</label>
            <div style="display:flex; gap:10px;">
                <select name="parent_id" required style="flex:1; padding:10px; border-radius:4px; border:1px solid #ccc;">
                    <option value="">-- Select Closer --</option>
                    <?php
                    $closers = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'Closer'");
                    while ($c = mysqli_fetch_assoc($closers)) {
                        $sel = ($c['id'] == $u['parent_id']) ? 'selected' : '';
                        echo "<option value='{$c['id']}' $sel>{$c['username']}</option>";
                    }
                    ?>
                </select>
                <button type="submit" name="update_assignment" class="btn">Save</button>
            </div>
        </form>
    <?php } ?>

    <div style="display:grid; gap:20px; margin-top:20px;">
        <form method="POST" style="border-bottom:1px solid #eee; padding-bottom:15px;">
            <input type="hidden" name="action" value="update_ip">
            <label style="display:block; margin-bottom:0px;">Network IP Restriction</label>
            <p style="font-size:12px; color:#666; margin-top:5px; margin-bottom:10px;">Leave blank to allow login from anywhere.</p>
            <div style="display:flex; gap:10px;">
                <input type="text" name="allowed_ip" value="<?php echo htmlspecialchars($u['allowed_ip'] ?? ''); ?>" style="flex:1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="submit" class="btn" style="background: var(--primary-navy);">Save IP</button>
            </div>
        </form>
        <form method="POST" style="border-bottom:1px solid #eee; padding-bottom:15px;">
            <input type="hidden" name="action" value="update_pw">
            <label style="display:block; margin-bottom:5px;">Update Password</label>
            <div style="display:flex; gap:10px;">
                <input type="text" name="new_pw" placeholder="Enter new password" required style="flex:1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="submit" class="btn">Update</button>
            </div>
        </form>
        <form method="POST" style="border-bottom:1px solid #eee; padding-bottom:15px;">
            <input type="hidden" name="action" value="logout">
            <label style="display:block; margin-bottom:5px;">Session Control</label>
            <button type="submit" class="btn" style="background:#ffc107; color:black;">Logout User</button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this user permanently?');">
            <input type="hidden" name="action" value="remove">
            <label style="display:block; margin-bottom:5px;">Danger Zone</label>
            <button type="submit" class="btn" style="background:#dc3545; width:100%;">Remove User Forever</button>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>