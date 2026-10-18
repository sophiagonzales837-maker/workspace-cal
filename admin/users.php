<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once 'includes/sync_user_to_live.php';
protect(['CAL_Admin', 'Admin']);

if (!$conn->query("CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    login_time TIME NOT NULL,
    logout_time TIME NOT NULL,
    allowed_break_minutes INT NOT NULL DEFAULT 60
)")) {
    error_log('[User management] Unable to initialize teams table: ' . $conn->error);
    http_response_code(500);
    exit('Unable to initialize team management.');
}

$roles = ['Agent', 'Closer', 'Verifier', 'Quality Check', 'Admin', 'Jr_Admin', 'Auditor'];
$message = '';
$message_type = 'success';
$row_errors = [];
if (isset($_SESSION['users_flash']) && is_array($_SESSION['users_flash'])) {
    $message = (string)($_SESSION['users_flash']['message'] ?? '');
    $message_type = (string)($_SESSION['users_flash']['type'] ?? 'success');
    $row_errors = is_array($_SESSION['users_flash']['row_errors'] ?? null) ? $_SESSION['users_flash']['row_errors'] : [];
    unset($_SESSION['users_flash']);
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['users_csrf'];

function usersEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function usersValidTime(string $value): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
}

function usersValidTeam(string $team, array $team_names): bool
{
    return $team === 'None' || in_array($team, $team_names, true);
}

function usersInsert(mysqli_stmt $stmt, string $username, string $password, string $role, string $team, string $phone = '', string $address = ''): bool
{
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    if ($password_hash === false) {
        throw new RuntimeException('Unable to securely hash the password.');
    }
    $status_state = 'Offline';
    $stmt->bind_param('sssssss', $username, $password_hash, $role, $team, $status_state, $phone, $address);
    return $stmt->execute();
}

$team_rows = [];
$team_result = $conn->query('SELECT id, name, login_time, logout_time, allowed_break_minutes FROM teams ORDER BY name ASC');
if (!$team_result) {
    error_log('[User management] Unable to fetch teams: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load teams.');
}
while ($team_row = $team_result->fetch_assoc()) {
    $team_rows[] = $team_row;
}
$team_names = array_map(static fn($team) => (string)$team['name'], $team_rows);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $message = 'Your session token expired. Refresh the page and try again.';
        $message_type = 'error';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_single_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = trim((string)($_POST['role'] ?? ''));
            $team = trim((string)($_POST['team'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($username === '' || strlen($username) > 100 || $password === '' || !in_array($role, $roles, true) || !usersValidTeam($team, $team_names)) {
                $message = 'Enter a username, password, valid role, and configured team.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, password, role, team, status, status_state, phone, address) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)");
                if (!$stmt) {
                    $message = 'Unable to prepare employee creation.';
                    $message_type = 'error';
                    error_log('[User management] Add user prepare failed: ' . $conn->error);
                } else {
                    try {
                        if (usersInsert($stmt, $username, $password, $role, $team, $phone, $address)) {
                            $user_id = (int)$conn->insert_id;
                            $synced = syncUserToLive($conn, $user_id);
                            if (!$synced) error_log('[User management] Employee ' . $user_id . ' was created locally but live synchronization failed.');
                            $message = $synced
                                ? 'Employee created and synchronized.'
                                : 'Employee created locally, but live synchronization failed. Check the server logs.';
                            $message_type = $synced ? 'success' : 'warning';
                        } else {
                            $message = $stmt->errno === 1062 ? 'That username already exists.' : 'Unable to create employee.';
                            $message_type = 'error';
                            if ($stmt->errno !== 1062) error_log('[User management] Add user failed: ' . $stmt->error);
                        }
                    } catch (Throwable $exception) {
                        $message = 'Unable to securely create employee.';
                        $message_type = 'error';
                        error_log('[User management] Password hashing failed: ' . $exception->getMessage());
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'bulk_add_users') {
            $upload = $_FILES['csv_file'] ?? null;
            if (!$upload || !isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
                $message = 'Select a CSV file to upload.';
                $message_type = 'error';
            } elseif (!isset($upload['tmp_name'], $upload['name'], $upload['size']) || !is_uploaded_file($upload['tmp_name']) || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'csv' || (int)$upload['size'] > 5 * 1024 * 1024) {
                $message = 'Upload a valid CSV file no larger than 5 MB.';
                $message_type = 'error';
            } else {
                $handle = fopen($upload['tmp_name'], 'rb');
                if ($handle === false) {
                    $message = 'Unable to read the uploaded CSV file.';
                    $message_type = 'error';
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO users (username, password, role, team, status, status_state) VALUES (?, ?, ?, ?, 'active', ?)");
                    if (!$insert_stmt) {
                        fclose($handle);
                        $message = 'Unable to prepare bulk employee creation.';
                        $message_type = 'error';
                        error_log('[User management] Bulk add prepare failed: ' . $conn->error);
                    } else {
                        $imported = 0;
                        $skipped = 0;
                        $synced_failures = 0;
                        $line_number = 0;
                        $header_checked = false;

                        while (($columns = fgetcsv($handle)) !== false) {
                            $line_number++;
                            $columns = array_map(static fn($value) => trim((string)$value), $columns);
                            if (count(array_filter($columns, static fn($value) => $value !== '')) === 0) continue;

                            if (!$header_checked) {
                                $header_checked = true;
                                $columns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columns[0]);
                                if (strcasecmp($columns[0], 'username') === 0) continue;
                            }
                            if (count($columns) < 4) {
                                $skipped++;
                                if (count($row_errors) < 10) $row_errors[] = "Row {$line_number}: expected Username, Password, Role, Team.";
                                continue;
                            }

                            [$username, $password, $role, $team] = array_slice($columns, 0, 4);
                            if ($username === '' || strlen($username) > 100 || $password === '' || !in_array($role, $roles, true) || !usersValidTeam($team, $team_names)) {
                                $skipped++;
                                if (count($row_errors) < 10) $row_errors[] = "Row {$line_number}: invalid or incomplete employee data.";
                                continue;
                            }

                            try {
                                $inserted = usersInsert($insert_stmt, $username, $password, $role, $team);
                            } catch (Throwable $exception) {
                                $inserted = false;
                                error_log('[User management] Bulk password hashing failed on row ' . $line_number . ': ' . $exception->getMessage());
                            }
                            if (!$inserted) {
                                $skipped++;
                                if (count($row_errors) < 10) {
                                    $row_errors[] = $insert_stmt->errno === 1062
                                        ? "Row {$line_number}: username already exists."
                                        : "Row {$line_number}: employee could not be created.";
                                }
                                if ($insert_stmt->errno !== 1062) error_log('[User management] Bulk employee insert failed: ' . $insert_stmt->error);
                                continue;
                            }

                            $imported++;
                            if (!syncUserToLive($conn, (int)$conn->insert_id)) $synced_failures++;
                        }
                        fclose($handle);
                        $insert_stmt->close();
                        $message = "{$imported} employee(s) imported; {$skipped} row(s) skipped.";
                        if ($synced_failures > 0) {
                            error_log('[User management] Live synchronization failed for ' . $synced_failures . ' bulk-imported employee(s).');
                            $message .= " Live synchronization failed for {$synced_failures} employee(s); check the server logs.";
                            $message_type = 'warning';
                        } elseif ($skipped > 0) {
                            $message_type = 'warning';
                        }
                    }
                }
            }
        } elseif ($action === 'add_team') {
            $team_name = trim((string)($_POST['team_name'] ?? ''));
            $login_time = trim((string)($_POST['login_time'] ?? ''));
            $logout_time = trim((string)($_POST['logout_time'] ?? ''));
            $break_minutes = filter_var($_POST['break_minutes'] ?? null, FILTER_VALIDATE_INT);

            if ($team_name === '' || strlen($team_name) > 100 || !usersValidTime($login_time) || !usersValidTime($logout_time) || $break_minutes === false || $break_minutes < 0) {
                $message = 'Enter a team name, valid shift times, and a non-negative break limit.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare('INSERT INTO teams (name, login_time, logout_time, allowed_break_minutes) VALUES (?, ?, ?, ?)');
                if (!$stmt) {
                    $message = 'Unable to prepare team creation.';
                    $message_type = 'error';
                    error_log('[User management] Add team prepare failed: ' . $conn->error);
                } else {
                    $stmt->bind_param('sssi', $team_name, $login_time, $logout_time, $break_minutes);
                    if ($stmt->execute()) {
                        $message = 'Team created successfully.';
                        $message_type = 'success';
                    } else {
                        $message = $stmt->errno === 1062 ? 'A team with that name already exists.' : 'Unable to create team.';
                        $message_type = 'error';
                        if ($stmt->errno !== 1062) error_log('[User management] Add team failed: ' . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'edit_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $dob = trim((string)($_POST['dob'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            if ($user_id <= 0 || $username === '' || strlen($username) > 100) {
                $message = 'Enter a valid employee and username.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare('UPDATE users SET username = ?, phone = ?, dob = ?, address = ? WHERE id = ?');
                if (!$stmt) {
                    $message = 'Unable to prepare employee update.';
                    $message_type = 'error';
                    error_log('[User management] Edit user prepare failed: ' . $conn->error);
                } else {
                    $dob = $dob !== '' ? $dob : null;
                    $stmt->bind_param('ssssi', $username, $phone, $dob, $address, $user_id);
                    if ($stmt->execute()) {
                        $message = 'Employee profile updated.';
                    } else {
                        $message = $stmt->errno === 1062 ? 'That username already exists.' : 'Unable to update employee.';
                        $message_type = 'error';
                        if ($stmt->errno !== 1062) error_log('[User management] Edit user failed: ' . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'delete_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id <= 0 || $user_id === (int)($_SESSION['user_id'] ?? 0)) {
                $message = 'You cannot delete this employee.';
                $message_type = 'error';
            } else {
                $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
                if (!$stmt) {
                    $message = 'Unable to prepare employee deletion.';
                    $message_type = 'error';
                    error_log('[User management] Delete user prepare failed: ' . $conn->error);
                } else {
                    $stmt->bind_param('i', $user_id);
                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        $message = 'Employee deleted.';
                    } else {
                        $message = 'Unable to delete that employee.';
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'update_ips') {
            $allowed_ips = trim((string)($_POST['allowed_ips'] ?? ''));
            $stmt = $conn->prepare("INSERT INTO inhouse_criteria (user_id, field_name, field_value, field_type) VALUES (1, 'allowed_ips', ?, 'system') ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)");
            if (!$stmt) {
                $message = 'Unable to prepare IP settings update.';
                $message_type = 'error';
                error_log('[User management] IP settings prepare failed: ' . $conn->error);
            } else {
                $stmt->bind_param('s', $allowed_ips);
                if ($stmt->execute()) {
                    $message = 'Security IPs updated.';
                } else {
                    $message = 'Unable to update security IPs.';
                    $message_type = 'error';
                    error_log('[User management] IP settings update failed: ' . $stmt->error);
                }
                $stmt->close();
            }
        } else {
            $message = 'Unsupported request.';
            $message_type = 'error';
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))
    && $message !== ''
) {
    $_SESSION['users_flash'] = [
        'message' => $message,
        'type' => $message_type,
        'row_errors' => $row_errors,
    ];
    session_write_close();
    header('Location: users.php');
    exit();
}

$users = [];
$user_result = $conn->query('SELECT id, username, role, team, status, status_state, phone, address, dob FROM users ORDER BY role ASC, username ASC');
if (!$user_result) {
    error_log('[User management] Unable to fetch users: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load employees.');
}
while ($user_row = $user_result->fetch_assoc()) $users[] = $user_row;

$ip_result = $conn->query("SELECT field_value FROM inhouse_criteria WHERE field_name = 'allowed_ips' ORDER BY id DESC LIMIT 1");
$allowed_ips = '';
if (!$ip_result) {
    error_log('[User management] Unable to fetch IP allow-list: ' . $conn->error);
} elseif ($ip_result->num_rows > 0) {
    $allowed_ips = (string)$ip_result->fetch_assoc()['field_value'];
}

$attendance_rows = [];
$attendance_error = '';
$attendance_date_filter = trim((string)($_GET['attendance_date'] ?? date('Y-m-d')));
if ($attendance_date_filter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date_filter)) {
    $attendance_date_filter = date('Y-m-d');
}
$daily_attendance_table = $conn->query("SHOW TABLES LIKE 'daily_attendance'");
$break_logs_table = $conn->query("SHOW TABLES LIKE 'break_logs'");
if (!$daily_attendance_table || $daily_attendance_table->num_rows === 0 || !$break_logs_table || $break_logs_table->num_rows === 0) {
    $attendance_error = 'Attendance or break log tables are not available in this database.';
} else {
    $attendance_query = "SELECT u.username, u.role, u.team, da.work_date, da.login_time, da.actual_logout_time,
                                FLOOR(COALESCE(SUM(CASE WHEN b.id IS NULL THEN 0 ELSE GREATEST(0, TIMESTAMPDIFF(
                                    SECOND, b.start_time,
                                    LEAST(COALESCE(b.end_time, NOW()), COALESCE(da.actual_logout_time, NOW()))
                                )) END), 0) / 60) AS total_break_minutes,
                                da.overtime_minutes
                         FROM users u
                         INNER JOIN daily_attendance da ON da.user_id = u.id
                         LEFT JOIN break_logs b ON b.user_id = u.id
                             AND b.start_time >= da.work_date
                             AND b.start_time < DATE_ADD(da.work_date, INTERVAL 1 DAY)
                         WHERE da.work_date = ?
                         GROUP BY u.id, u.username, u.role, u.team, da.id, da.work_date, da.login_time, da.actual_logout_time, da.overtime_minutes
                         ORDER BY u.username ASC, da.login_time ASC";
    $attendance_stmt = $conn->prepare($attendance_query);
    if (!$attendance_stmt) {
        $attendance_error = 'Unable to prepare attendance and break log report.';
        error_log('[User management] Attendance prepare failed: ' . $conn->error);
    } else {
        $attendance_stmt->bind_param('s', $attendance_date_filter);
        if (!$attendance_stmt->execute()) {
            $attendance_error = 'Unable to load attendance and break logs.';
            error_log('[User management] Attendance query failed: ' . $attendance_stmt->error);
        } else {
            $attendance_result = $attendance_stmt->get_result();
            if ($attendance_result) {
                while ($attendance_row = $attendance_result->fetch_assoc()) $attendance_rows[] = $attendance_row;
            }
        }
        $attendance_stmt->close();
    }
}

include 'includes/header.php';
?>

<style>
    .users-page { max-width: 1500px; margin: 20px auto 36px; padding: 0 20px; color: var(--text-dark); }
    .users-page h1, .users-page h2, .users-page h3 { color: var(--primary-navy); }
    .users-title { margin: 0 0 6px; font-size: 28px; }
    .users-subtitle { color: var(--text-muted); margin-bottom: 22px; }
    .users-toolbar { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
    .modern-card { padding: 24px; }
    .users-form { display: grid; gap: 14px; }
    .users-form label { display: grid; gap: 6px; color: var(--text-muted); font-size: 13px; font-weight: 700; }
    .btn-primary { border: 0; border-radius: 8px; padding: 11px 16px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-danger { border: 0; border-radius: 6px; padding: 7px 10px; background: #b91c1c; color: #fff; font-weight: 700; cursor: pointer; }
    .users-table-wrap { overflow-x: auto; }
    .users-table { width: 100%; min-width: 700px; border-collapse: collapse; }
    .users-table th, .users-table td { padding: 11px 10px; text-align: left; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
    .users-table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
    .users-actions { display: flex; align-items: center; gap: 8px; }
    .users-notice { padding: 13px 16px; border-radius: 8px; margin-bottom: 18px; }
    .users-notice.success { background: #dcfce7; color: #166534; }
    .users-notice.warning { background: #fef3c7; color: #92400e; }
    .users-notice.error { background: #fee2e2; color: #991b1b; }
    .users-errors { margin: 8px 0 0 20px; }
    .users-hint { color: var(--text-muted); font-size: 13px; line-height: 1.5; }
    .users-modal { display: none; position: fixed; inset: 0; z-index: 1000000; align-items: center; justify-content: center; padding: 20px; background: rgba(2, 6, 23, .78); }
    .users-modal-content { width: min(680px, 100%); max-height: 90vh; overflow-y: auto; padding: 24px; border-radius: 12px; background: var(--bg-light); }
    .users-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
    .users-modal-header h2 { margin: 0; }
    .users-close { border: 0; border-radius: 7px; padding: 4px 11px; background: #334155; color: #fff; font-size: 23px; cursor: pointer; }
    .users-team-table { margin-top: 22px; }
</style>

<main class="users-page">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
        <div>
            <h1 class="users-title">Employee Management</h1>
            <p class="users-subtitle">View employee accounts and manage users and teams.</p>
        </div>
        <div class="users-toolbar">
            <button type="button" class="btn-primary" onclick="openModal('singleUserModal')">+ Add Single User</button>
            <button type="button" class="btn-primary" onclick="openModal('bulkUsersModal')">+ Bulk Add Users</button>
            <button type="button" class="btn-primary" onclick="openModal('manageTeamsModal')">Manage Teams</button>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="users-notice <?= usersEscape($message_type) ?>" role="status">
            <?= usersEscape($message) ?>
            <?php if ($row_errors): ?>
                <ul class="users-errors"><?php foreach ($row_errors as $row_error): ?><li><?= usersEscape($row_error) ?></li><?php endforeach; ?></ul>
                <?php if (count($row_errors) === 10): ?><p class="users-hint">Only the first 10 row details are shown.</p><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="modern-card" style="overflow-x:auto;">
        <h2 style="margin:0 0 18px;">Employee Directory <span class="users-hint">(<?= count($users) ?>)</span></h2>
        <div class="users-table-wrap">
            <table class="users-table">
                <thead><tr><th>Username</th><th>Phone</th><th>Address</th><th>Role</th><th>Team</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No employees found.</td></tr>
                    <?php else: foreach ($users as $user): ?>
                        <tr>
                            <td><strong><?= usersEscape($user['username']) ?></strong></td>
                            <td><?= usersEscape($user['phone'] ?? '') ?></td>
                            <td><?= usersEscape($user['address'] ?? '') ?></td>
                            <td><?= usersEscape($user['role']) ?></td>
                            <td><?= usersEscape($user['team'] ?? 'None') ?></td>
                            <td><?= usersEscape($user['status_state'] ?? 'Offline') ?></td>
                            <td>
                                <?php if ((int)$user['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                    <div class="users-actions">
                                        <button type="button" class="btn-primary" style="padding:7px 10px;" onclick="openUserEdit(<?= (int)$user['id'] ?>, <?= htmlspecialchars(json_encode((string)$user['username'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)($user['phone'] ?? '')), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)($user['dob'] ?? '')), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)($user['address'] ?? '')), ENT_QUOTES, 'UTF-8') ?>)">Edit</button>
                                        <form method="post" onsubmit="return confirm('Delete this employee?');">
                                            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <button class="btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                <?php else: ?><span class="users-hint">Current user</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="modern-card" style="margin-top:22px;overflow-x:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
            <div><h2 style="margin:0;">Attendance &amp; Break Logs</h2><p class="users-hint" style="margin-top:6px;">Attendance totals for <?= usersEscape(date('M j, Y', strtotime($attendance_date_filter))) ?>.</p></div>
            <form method="get" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                <label class="users-hint">Date<input class="input-style" type="date" name="attendance_date" value="<?= usersEscape($attendance_date_filter) ?>"></label>
                <button type="submit" class="btn-primary">Filter</button>
                <a class="btn-primary" href="users.php" style="text-decoration:none;">Clear</a>
                <span class="users-hint"><?= count($attendance_rows) ?> records</span>
            </form>
        </div>
        <?php if ($attendance_error !== ''): ?>
            <p class="users-notice warning" role="status"><?= usersEscape($attendance_error) ?></p>
        <?php else: ?>
            <div class="users-table-wrap">
                <table class="users-table" style="min-width:850px;">
                    <thead><tr><th>User</th><th>Role</th><th>Team</th><th>Login</th><th>Logout</th><th>Break Minutes</th><th>Overtime Minutes</th></tr></thead>
                    <tbody>
                        <?php if (!$attendance_rows): ?>
                            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No attendance records found for this date.</td></tr>
                        <?php else: foreach ($attendance_rows as $attendance): ?>
                            <tr>
                                <td><?= usersEscape($attendance['username']) ?></td>
                                <td><?= usersEscape($attendance['role']) ?></td>
                                <td><?= usersEscape($attendance['team'] ?? '—') ?></td>
                                <td><?= !empty($attendance['login_time']) ? usersEscape(date('g:i A', strtotime($attendance['login_time']))) : '—' ?></td>
                                <td><?= !empty($attendance['actual_logout_time']) ? usersEscape(date('g:i A', strtotime($attendance['actual_logout_time']))) : 'In progress' ?></td>
                                <td><?= number_format((int)$attendance['total_break_minutes']) ?></td>
                                <td><?= number_format((int)$attendance['overtime_minutes']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="modern-card" style="margin-top:22px;max-width:700px;">
        <h2 style="margin:0 0 16px;">Bulk IP Security</h2>
        <form method="post" class="users-form">
            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
            <input type="hidden" name="action" value="update_ips">
            <label>Allowed IPs (comma separated)<input class="input-style" type="text" name="allowed_ips" value="<?= usersEscape($allowed_ips) ?>" placeholder="e.g. 192.168.1.1, 10.0.0.1"></label>
            <button type="submit" class="btn-primary">Save IPs</button>
        </form>
    </section>
</main>

<div id="singleUserModal" class="users-modal" role="dialog" aria-modal="true" aria-labelledby="single-user-title">
    <div class="users-modal-content">
        <div class="users-modal-header">
            <h2 id="single-user-title">Add Single User</h2>
            <button type="button" class="users-close" onclick="closeModal('singleUserModal')" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="users-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
            <input type="hidden" name="action" value="add_single_user">
            <label>Username<input class="input-style" type="text" name="username" maxlength="100" required autocomplete="off"></label>
            <label>Password<input class="input-style" type="password" name="password" required autocomplete="new-password"></label>
            <label>Role
                <select class="input-style" name="role" required>
                    <option value="">Select role</option>
                    <?php foreach ($roles as $role): ?><option value="<?= usersEscape($role) ?>"><?= usersEscape($role) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Team
                <select class="input-style" name="team" required>
                    <option value="">Select team</option>
                    <option value="None">None</option>
                    <?php foreach ($team_names as $team_name): ?><option value="<?= usersEscape($team_name) ?>"><?= usersEscape($team_name) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Phone Number<input class="input-style" type="tel" name="phone"></label>
            <label>Address<input class="input-style" type="text" name="address"></label>
            <button type="submit" class="btn-primary">Create User</button>
        </form>
    </div>
</div>

<div id="bulkUsersModal" class="users-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-users-title">
    <div class="users-modal-content">
        <div class="users-modal-header">
            <div>
                <h2 id="bulk-users-title">Bulk Add Users</h2>
                <p class="users-hint" style="margin-top:6px;">CSV format: Username, Password, Role, Team</p>
            </div>
            <button type="button" class="users-close" onclick="closeModal('bulkUsersModal')" aria-label="Close">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="users-form">
            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
            <input type="hidden" name="action" value="bulk_add_users">
            <label>CSV File<input class="input-style" type="file" name="csv_file" accept=".csv,text/csv" required></label>
            <p class="users-hint">A header row is optional. Empty rows are skipped. Valid roles: <?= usersEscape(implode(', ', $roles)) ?>. Team must be a configured team name or <code>None</code>.</p>
            <button type="submit" class="btn-primary">Upload CSV</button>
        </form>
    </div>
</div>

<div id="manageTeamsModal" class="users-modal" role="dialog" aria-modal="true" aria-labelledby="manage-teams-title">
    <div class="users-modal-content">
        <div class="users-modal-header">
            <h2 id="manage-teams-title">Manage Teams</h2>
            <button type="button" class="users-close" onclick="closeModal('manageTeamsModal')" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="users-form">
            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
            <input type="hidden" name="action" value="add_team">
            <label>Team Name<input class="input-style" type="text" name="team_name" maxlength="100" required></label>
            <label>Shift Start<input class="input-style" type="time" name="login_time" required></label>
            <label>Shift End<input class="input-style" type="time" name="logout_time" required></label>
            <label>Break Limit (Minutes)<input class="input-style" type="number" name="break_minutes" min="0" step="1" value="60" required></label>
            <button type="submit" class="btn-primary">Create Team</button>
        </form>
        <div class="users-table-wrap users-team-table">
            <table class="users-table" style="min-width:520px;">
                <thead><tr><th>Team</th><th>Shift Start</th><th>Shift End</th><th>Break Limit</th></tr></thead>
                <tbody>
                    <?php if (!$team_rows): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--text-muted);">No teams configured.</td></tr>
                    <?php else: foreach ($team_rows as $team): ?>
                        <tr>
                            <td><?= usersEscape($team['name']) ?></td>
                            <td><?= usersEscape(substr((string)$team['login_time'], 0, 5)) ?></td>
                            <td><?= usersEscape(substr((string)$team['logout_time'], 0, 5)) ?></td>
                            <td><?= (int)$team['allowed_break_minutes'] ?> min</td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <p class="users-hint" style="margin-top:14px;"><a href="manage_teams.php">Open full team manager</a></p>
    </div>
</div>

<div id="editUserModal" class="users-modal" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
    <div class="users-modal-content">
        <div class="users-modal-header">
            <h2 id="edit-user-title">Edit Employee</h2>
            <button type="button" class="users-close" onclick="closeUserEdit()" aria-label="Close">&times;</button>
        </div>
        <form method="post" class="users-form">
            <input type="hidden" name="csrf_token" value="<?= usersEscape($csrf_token) ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit-user-id">
            <label>Username<input class="input-style" type="text" name="username" id="edit-username" maxlength="100" required></label>
            <label>Phone<input class="input-style" type="text" name="phone" id="edit-phone"></label>
            <label>Date of Birth<input class="input-style" type="date" name="dob" id="edit-dob"></label>
            <label>Address<input class="input-style" type="text" name="address" id="edit-address"></label>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn-primary" style="background:#475569;" onclick="closeUserEdit()">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    function openUserEdit(id, username, phone, dob, address) {
        document.getElementById('edit-user-id').value = id;
        document.getElementById('edit-username').value = username;
        document.getElementById('edit-phone').value = phone;
        document.getElementById('edit-dob').value = dob;
        document.getElementById('edit-address').value = address;
        document.getElementById('editUserModal').style.display = 'flex';
    }

    function closeUserEdit() {
        closeModal('editUserModal');
    }

    document.querySelectorAll('.users-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal(modal.id);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.users-modal').forEach((modal) => closeModal(modal.id));
        }
    });
</script>

<?php include 'includes/footer.php'; ?>
