<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');

if (empty($_SESSION['team_admin_csrf'])) {
    $_SESSION['team_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['team_admin_csrf'];
$message = '';
$message_type = 'success';

function syncTeamToLive(array $team): bool
{
    $payload = json_encode($team, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false || !function_exists('curl_init')) return false;

    $curl = curl_init('https://consumerhealthcarecompliance.com/workspace/api/v1/sync_teams.php');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($curl);
    $http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $http_status < 200 || $http_status >= 300) {
        error_log('[Team sync] Live request failed: ' . curl_error($curl) . ' (HTTP ' . $http_status . ')');
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    $result = json_decode((string)$response, true);
    return is_array($result) && ($result['status'] ?? '') === 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $message = 'Invalid request token. Refresh and try again.';
        $message_type = 'error';
    } elseif (isset($_POST['delete_team'])) {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM teams WHERE id = ?');
        $stmt->bind_param('i', $team_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = 'Team deleted. Existing users assigned to it must be reassigned.';
        } else {
            $message = 'Unable to delete that team.';
            $message_type = 'error';
        }
        $stmt->close();
    } elseif (isset($_POST['save_team'])) {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $team_name = trim((string)($_POST['name'] ?? ''));
        $login_time = trim((string)($_POST['login_time'] ?? ''));
        $logout_time = trim((string)($_POST['logout_time'] ?? ''));
        $allowed_break_minutes = filter_var($_POST['allowed_break_minutes'] ?? null, FILTER_VALIDATE_INT);

        if (
            $team_name === '' || strlen($team_name) > 100
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $login_time)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $logout_time)
            || $allowed_break_minutes === false || $allowed_break_minutes < 0
        ) {
            $message = 'Enter a team name, valid shift times, and a non-negative break allowance.';
            $message_type = 'error';
        } else {
            $duplicate_stmt = $conn->prepare('SELECT id FROM teams WHERE name = ? AND id <> ? LIMIT 1');
            $duplicate_stmt->bind_param('si', $team_name, $team_id);
            $duplicate_stmt->execute();
            $duplicate = $duplicate_stmt->get_result()->fetch_assoc();
            $duplicate_stmt->close();

            if ($duplicate) {
                $message = 'A team with that name already exists.';
                $message_type = 'error';
            } else {
                $previous_name = '';
                if ($team_id > 0) {
                    $previous_stmt = $conn->prepare('SELECT name FROM teams WHERE id = ? LIMIT 1');
                    $previous_stmt->bind_param('i', $team_id);
                    $previous_stmt->execute();
                    $previous_name = (string)($previous_stmt->get_result()->fetch_assoc()['name'] ?? '');
                    $previous_stmt->close();
                    $stmt = $conn->prepare('UPDATE teams SET name = ?, login_time = ?, logout_time = ?, allowed_break_minutes = ? WHERE id = ?');
                    $stmt->bind_param('sssii', $team_name, $login_time, $logout_time, $allowed_break_minutes, $team_id);
                } else {
                    $stmt = $conn->prepare('INSERT INTO teams (name, login_time, logout_time, allowed_break_minutes) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('sssi', $team_name, $login_time, $logout_time, $allowed_break_minutes);
                }

                if ($stmt->execute()) {
                    if ($team_id > 0 && $previous_name !== '' && $previous_name !== $team_name) {
                        $assignment_stmt = $conn->prepare('UPDATE users SET team = ? WHERE team = ?');
                        $assignment_stmt->bind_param('ss', $team_name, $previous_name);
                        $assignment_stmt->execute();
                        $assignment_stmt->close();
                    }
                    $sync_ok = syncTeamToLive([
                        'name' => $team_name,
                        'login_time' => $login_time,
                        'logout_time' => $logout_time,
                        'allowed_break_minutes' => $allowed_break_minutes,
                        'previous_name' => $previous_name,
                        'auth_key' => 'MakeMoney$$'
                    ]);
                    $message = $sync_ok
                        ? 'Team saved and synced to Live.'
                        : 'Team saved locally, but Live synchronization failed. Check the server logs.';
                    $message_type = $sync_ok ? 'success' : 'error';
                } else {
                    $message = 'Unable to save the team: ' . $stmt->error;
                    $message_type = 'error';
                }
                $stmt->close();
            }
        }
    }
}

$edit_team = null;
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $edit_stmt = $conn->prepare('SELECT id, name, login_time, logout_time, allowed_break_minutes FROM teams WHERE id = ? LIMIT 1');
    $edit_stmt->bind_param('i', $edit_id);
    $edit_stmt->execute();
    $edit_team = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();
}

$teams = [];
$result = $conn->query('SELECT id, name, login_time, logout_time, allowed_break_minutes FROM teams ORDER BY name ASC');
if ($result) {
    while ($row = $result->fetch_assoc()) $teams[] = $row;
}

include 'includes/header.php';
?>
<div class="card" style="max-width: 1100px; margin: 24px auto; padding: 24px;">
    <h2 style="margin-top: 0; color: var(--primary-navy);">Manage Teams</h2>
    <?php if ($message !== ''): ?>
        <div role="status" style="margin-bottom: 16px; padding: 12px; border-radius: 6px; color: <?= $message_type === 'success' ? '#166534' : '#991b1b' ?>; background: <?= $message_type === 'success' ? '#dcfce7' : '#fee2e2' ?>;">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; margin-bottom: 28px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="team_id" value="<?= (int)($edit_team['id'] ?? 0) ?>">
        <label>Team Name<input class="input-style" name="name" maxlength="100" required value="<?= htmlspecialchars((string)($edit_team['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Login Time<input class="input-style" type="time" name="login_time" required value="<?= htmlspecialchars(substr((string)($edit_team['login_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Logout Time<input class="input-style" type="time" name="logout_time" required value="<?= htmlspecialchars(substr((string)($edit_team['logout_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Allowed Break (Minutes)<input class="input-style" type="number" name="allowed_break_minutes" min="0" step="1" required value="<?= (int)($edit_team['allowed_break_minutes'] ?? 0) ?>"></label>
        <button class="btn" type="submit" name="save_team" value="1"><?= $edit_team ? 'Update Team' : 'Create Team' ?></button>
        <?php if ($edit_team): ?><a class="btn" href="manage_teams.php" style="text-align: center;">Cancel Edit</a><?php endif; ?>
    </form>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Team</th>
                    <th>Login</th>
                    <th>Logout</th>
                    <th>Break Minutes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$teams): ?><tr>
                        <td colspan="5" style="padding: 18px; text-align: center;">No teams configured.</td>
                    </tr><?php endif; ?>
                <?php foreach ($teams as $team): ?>
                    <tr>
                        <td><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr((string)$team['login_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(substr((string)$team['logout_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$team['allowed_break_minutes'] ?></td>
                        <td style="display: flex; gap: 8px;">
                            <a class="btn" href="manage_teams.php?edit=<?= (int)$team['id'] ?>">Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this team? Assigned users will need another team.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                <button class="btn" type="submit" name="delete_team" value="1" style="background: #b91c1c; color: white;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'includes/footer.php'; ?>