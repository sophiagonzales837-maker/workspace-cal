<?php
// FILE: workspace-CAL/admin/verifier_timesheets.php
require_once '../config/db.php';
require_once '../config/security.php';

protect(['Admin']);

$api_url = 'https://consumerhealthcarecompliance.com/workspace/api/v1/get_verifier_times.php';
$api_response = false;
$api_error = '';
$curl = curl_init($api_url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['auth_key' => 'MakeMoney$$']));
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($curl, CURLOPT_TIMEOUT, 8);
$api_response = curl_exec($curl);
$http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
if ($api_response === false || $http_status < 200 || $http_status >= 300) {
    $api_error = 'Unable to load verifier times from the live server.';
    if ($api_response === false) error_log('[Verifier timesheet] Live API request failed: ' . curl_error($curl));
}
curl_close($curl);

$decoded_response = is_string($api_response) ? json_decode($api_response, true) : null;
$verifiers = is_array($decoded_response['data'] ?? null) && ($decoded_response['status'] ?? '') === 'success'
    ? $decoded_response['data']
    : [];
if ($api_error === '' && !is_array($decoded_response)) {
    $api_error = 'The live server returned an invalid response.';
} elseif ($api_error === '' && ($decoded_response['status'] ?? '') !== 'success') {
    $api_error = (string)($decoded_response['message'] ?? 'Unable to load verifier times from the live server.');
}

include 'includes/header.php';
?>

<div style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="color: var(--primary-navy); margin: 0;">Verifier Time & Attendance</h2>
        <button onclick="location.reload();" class="btn" style="background: var(--electric-blue); color: white; border-radius: 6px;"><i class="fas fa-sync-alt"></i> Refresh Data</button>
    </div>

    <div class="card" style="background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); overflow: hidden;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 15px;">Verifier Name</th>
                    <th style="padding: 15px;">Current Status</th>
                    <th style="padding: 15px;">First Login Today</th>
                    <th style="padding: 15px;">Last Active Ping</th>
                    <th style="padding: 15px; text-align: center;">Total Active Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($api_error !== ''): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #b91c1c; padding: 20px;"><?= htmlspecialchars($api_error, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php elseif (!$verifiers): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #888; padding: 20px;">No Verifiers found in the system.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($verifiers as $v):
                    $status_state = (string)($v['status_state'] ?? '');
                    $is_online = ($status_state === 'Active');
                    $is_break = ($status_state === 'On Break');
                    $status_label = $is_online ? 'Active' : ($is_break ? 'On Break' : 'Offline');

                    $status_color = '#64748b'; // Offline
                    if ($is_online) $status_color = '#10b981'; // Green
                    if ($is_break) $status_color = '#f59e0b'; // Yellow

                    // Format times
                    $login_time = !empty($v['daily_login_time']) ? date('M d, h:i A', strtotime($v['daily_login_time'])) : 'Not Logged In';
                    $last_seen = !empty($v['last_seen']) ? date('M d, h:i A', strtotime($v['last_seen'])) : 'Never';

                    // Calculate Hours and Minutes
                    $total_mins = (int)($v['total_shift_minutes'] ?? 0);
                    if ($total_mins < 0 || empty($v['daily_login_time'])) $total_mins = 0;

                    $hours = floor($total_mins / 60);
                    $minutes = $total_mins % 60;
                    $time_display = ($hours > 0 ? "{$hours}h " : "") . "{$minutes}m";
                ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 15px;"><strong><?= htmlspecialchars((string)($v['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td style="padding: 15px;">
                            <span style="display: inline-flex; align-items: center; gap: 6px; font-weight: bold; color: <?= $status_color ?>;">
                                <i class="fas fa-circle" style="font-size: 10px;"></i> <?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td style="padding: 15px; color: #475569;"><?= $login_time ?></td>
                        <td style="padding: 15px; color: #475569;"><?= $last_seen ?></td>
                        <td style="padding: 15px; text-align: center;">
                            <span style="background: #f1f5f9; padding: 6px 12px; border-radius: 6px; font-weight: bold; color: #0f172a; border: 1px solid #cbd5e1;">
                                <?= $time_display ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>