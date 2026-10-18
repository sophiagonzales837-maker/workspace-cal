<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once 'includes/sync_user_to_live.php';
protect(['CAL_Admin', 'Admin']);

$teams = [];
$team_query = mysqli_query($conn, "SELECT name FROM teams ORDER BY name ASC");
if ($team_query && mysqli_num_rows($team_query) > 0) {
    while ($t = mysqli_fetch_assoc($team_query)) {
        $teams[] = $t['name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check if this is a bulk add submission via the array inputs
    if (isset($_POST['bulk_submit'])) {
        $usernames = $_POST['username'];
        $passwords = $_POST['password'];
        $roles = $_POST['role'];
        $teams_post = $_POST['team'];
        $phones = $_POST['phone'] ?? [];
        $addresses = $_POST['address'] ?? [];
        $dobs = $_POST['dob'] ?? [];
        $success_count = 0;

        // Loop through the submitted arrays
        for ($i = 0; $i < count($usernames); $i++) {
            $un = trim($usernames[$i]);

            // Only process rows where a username was actually typed in
            if (!empty($un)) {
                $username = mysqli_real_escape_string($conn, $un);
                $password = password_hash(trim($passwords[$i]), PASSWORD_DEFAULT);
                $role = mysqli_real_escape_string($conn, trim($roles[$i]));
                $team = mysqli_real_escape_string($conn, trim($teams_post[$i]));
                $phone = mysqli_real_escape_string($conn, trim($phones[$i] ?? ''));
                $address = mysqli_real_escape_string($conn, trim($addresses[$i] ?? ''));
                $dob_value = trim($dobs[$i] ?? '');
                $dob = $dob_value === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $dob_value) . "'";

                $query = "INSERT INTO users (username, password, role, team, phone, address, dob) VALUES ('$username', '$password', '$role', '$team', '$phone', '$address', $dob)";
                if (mysqli_query($conn, $query)) {
                    $success_count++;
                    syncUserToLive($conn, (int)mysqli_insert_id($conn));
                }
            }
        }

        session_write_close();
        header("Location: users.php?success=bulk_added&count=" . $success_count);
        exit();
    } elseif (isset($_POST['single_submit'])) {
        // Single user add logic
        $username = mysqli_real_escape_string($conn, trim($_POST['username']));
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = mysqli_real_escape_string($conn, $_POST['role']);
        $team = mysqli_real_escape_string($conn, $_POST['team']);
        $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
        $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
        $dob_value = trim($_POST['dob'] ?? '');
        $dob = $dob_value === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $dob_value) . "'";

        $query = "INSERT INTO users (username, password, role, team, phone, address, dob) VALUES ('$username', '$password', '$role', '$team', '$phone', '$address', $dob)";
        if (mysqli_query($conn, $query)) {
            syncUserToLive($conn, (int)mysqli_insert_id($conn));
            session_write_close();
            header("Location: users.php?success=added");
            exit();
        }
    }
}
include 'includes/header.php';
?>
<div style="margin-bottom: 20px; max-width: 900px; margin: 0 auto 20px auto;">
    <a href="users.php" style="text-decoration: none; color: var(--electric-blue); font-weight: bold;">&larr; Back to Users</a>
</div>

<div class="card" style="max-width: 500px; margin: auto; padding: 40px; margin-bottom: 30px;">
    <h3 style="margin-top: 0; font-size: 24px; color: var(--primary-navy); border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 25px;">Add Single User</h3>
    <form method="POST">
        <input type="hidden" name="single_submit" value="1">
        <div style="margin-bottom: 15px;"><label class="form-label">Username <span style="color:red;">*</span></label><input type="text" name="username" class="input-style" required></div>
        <div style="margin-bottom: 15px;"><label class="form-label">Phone</label><input type="text" name="phone" class="input-style" placeholder="+1xxxxxxxxxx"></div>
        <div style="margin-bottom: 15px;"><label class="form-label">Address</label><input type="text" name="address" class="input-style" placeholder="123 Main St, City, ST"></div>
        <div style="margin-bottom: 15px;"><label class="form-label">DOB</label><input type="date" name="dob" class="input-style"></div>
        <div style="margin-bottom: 15px;"><label class="form-label">Password <span style="color:red;">*</span></label><input type="text" name="password" class="input-style" required></div>
        <div style="margin-bottom: 15px;"><label class="form-label">Role <span style="color:red;">*</span></label>
            <select name="role" id="role-select" class="input-style" required>
                <option value="Agent">Agent</option>
                <option value="Closer">Closer</option>
                <option value="Verifier">Verifier</option>
                <option value="Quality Check">Quality Check</option>
                <option value="Admin">Admin</option>
                <option value="Jr_Admin">Jr_Admin</option>
                <option value="Auditor">Auditor</option>
            </select>
        </div>
        <div style="margin-bottom: 15px;"><label class="form-label">Team <span style="color:red;">*</span></label>
            <select name="team" id="team-select" class="input-style" required>
                <?php foreach ($teams as $team_name): ?>
                    <option value="<?php echo htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
                <option value="None">None</option>
            </select>
        </div>
        <button type="submit" class="btn" style="width:100%; margin-top:20px; font-size: 16px; padding: 12px; font-weight: bold;">Create User</button>
    </form>
</div>

<div class="card" style="max-width: 900px; margin: auto; padding: 40px; overflow-x: auto;">
    <h3 style="margin-top: 0; font-size: 24px; color: var(--primary-navy); border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 15px;">Bulk Add Users</h3>
    <form method="POST">
        <input type="hidden" name="bulk_submit" value="1">

        <table style="width: 100%; min-width: 700px; border-collapse: collapse; margin-bottom: 20px;" id="bulkTable">
            <thead>
                <tr>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Username</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Password</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Role</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Team</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Phone</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">Address</th>
                    <th style="text-align: left; padding-bottom: 10px; color: #666;">DOB</th>
                    <th style="text-align: center; padding-bottom: 10px; color: #666;">Remove</th>
                </tr>
            </thead>
            <tbody id="bulkBody">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <tr>
                        <td style="padding: 5px;"><input type="text" name="username[]" class="input-style" style="margin-bottom:0;" placeholder="User <?php echo $i + 1; ?>"></td>
                        <td style="padding: 5px;"><input type="text" name="password[]" class="input-style" style="margin-bottom:0;" placeholder="Password"></td>
                        <td style="padding: 5px;">
                            <select name="role[]" class="input-style bulk-role-select" style="margin-bottom:0;" onchange="handleBulkRoleChange(this)">
                                <option value="Agent">Agent</option>
                                <option value="Closer">Closer</option>
                                <option value="Verifier">Verifier</option>
                                <option value="Quality Check">Quality Check</option>
                                <option value="Admin">Admin</option>
                                <option value="Jr_Admin">Jr_Admin</option>
                                <option value="Auditor">Auditor</option>
                            </select>
                        </td>
                        <td style="padding: 5px;">
                            <select name="team[]" class="input-style bulk-team-select" style="margin-bottom:0;">
                                <?php foreach ($teams as $team_name): ?>
                                    <option value="<?php echo htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($team_name, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                                <option value="None">None</option>
                            </select>
                        </td>
                        <td style="padding: 5px;"><input type="text" name="phone[]" class="input-style" style="margin-bottom:0;" placeholder="Phone"></td>
                        <td style="padding: 5px;"><input type="text" name="address[]" class="input-style" style="margin-bottom:0;" placeholder="Address"></td>
                        <td style="padding: 5px;"><input type="date" name="dob[]" class="input-style" style="margin-bottom:0;"></td>
                        <td style="padding: 5px; text-align: center;">
                            <button type="button" onclick="this.closest('tr').remove()" style="background: #dc3545; color: white; border: none; border-radius: 4px; padding: 8px 12px; cursor: pointer; font-weight: bold;">X</button>
                        </td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <button type="button" onclick="addBulkRow()" style="background: #e9ecef; color: #333; border: 1px solid #ccc; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">+ Add Another Row</button>
            <button type="submit" class="btn" style="font-size: 16px; padding: 12px 30px; font-weight: bold;">Save All Users</button>
        </div>
    </form>
</div>

<script>
    function selectFirstConfiguredTeam(teamSelect) {
        var firstTeamOption = Array.from(teamSelect.options).find(function(option) {
            return option.value !== 'None' && !option.disabled;
        });
        if (firstTeamOption) teamSelect.value = firstTeamOption.value;
    }

    // Logic for Single Add Form
    document.getElementById('role-select').addEventListener('change', function() {
        var teamSelect = document.getElementById('team-select');
        if (this.value === 'Admin' || this.value === 'Auditor') {
            teamSelect.value = 'None';
        } else {
            if (teamSelect.value === 'None') {
                selectFirstConfiguredTeam(teamSelect);
            }
        }
    });

    // Logic for Bulk Add Grid: Handles auto-switching the Team to 'None' for Admins/Auditors
    function handleBulkRoleChange(selectElement) {
        var tr = selectElement.closest('tr');
        var teamSelect = tr.querySelector('.bulk-team-select');
        if (selectElement.value === 'Admin' || selectElement.value === 'Auditor') {
            teamSelect.value = 'None';
        } else {
            if (teamSelect.value === 'None') {
                selectFirstConfiguredTeam(teamSelect);
            }
        }
    }

    // Logic to add a new row dynamically
    function addBulkRow() {
        var tbody = document.getElementById('bulkBody');
        var rowCount = tbody.getElementsByTagName('tr').length + 1;

        // Create new row elements by cloning the first row to preserve select options
        var firstRow = tbody.getElementsByTagName('tr')[0];
        if (firstRow) {
            var newRow = firstRow.cloneNode(true);
            // Clear out the input values
            var inputs = newRow.querySelectorAll('input');
            inputs[0].value = '';
            inputs[0].placeholder = 'User ' + rowCount;
            inputs[1].value = '';
            inputs[2].value = '';
            inputs[3].value = '';
            inputs[4].value = '';

            // Reset selects
            var selects = newRow.querySelectorAll('select');
            selects[0].selectedIndex = 0;
            selects[1].selectedIndex = 0;

            tbody.appendChild(newRow);
        } else {
            // Fallback if they delete all rows and try to add one (requires a hard refresh otherwise)
            alert('Please refresh the page to add rows if you deleted all of them.');
        }
    }
</script>
<?php include 'includes/footer.php'; ?>