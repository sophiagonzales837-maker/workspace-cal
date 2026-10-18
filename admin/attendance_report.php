<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'CAL_Admin']);

function attendanceReportDateIsValid(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
        return false;
    }
    return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

function attendanceReportFormatTime(?string $value, string $format = 'g:i A'): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date($format, $timestamp);
}

$has_custom_date = isset($_GET['filter_date']) && trim((string)$_GET['filter_date']) !== '';
$selected_date = $has_custom_date ? trim((string)$_GET['filter_date']) : date('Y-m-d');
$date_error = '';
if (!attendanceReportDateIsValid($selected_date)) {
    $selected_date = date('Y-m-d');
    $date_error = 'Invalid date supplied. Showing records for today.';
}

$report_error = '';
$attendance_rows = [];
$breaks = [];
$api_url = 'https://consumerhealthcarecompliance.com/api/get_attendance.php?' . http_build_query([
    'date' => $selected_date,
    'auth_key' => 'MakeMoney$$',
]);

if (!function_exists('curl_init')) {
    $report_error = 'The cURL extension is not available on this server.';
} else {
    $curl = curl_init($api_url);
    if ($curl === false) {
        $report_error = 'Unable to initialize the attendance API request.';
    } else {
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $api_response = curl_exec($curl);
        $curl_error = curl_error($curl);
        $http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($api_response === false) {
            error_log('[Attendance report] API request failed: ' . $curl_error);
            $report_error = 'Unable to connect to the verifier attendance service.';
        } else {
            $api_result = json_decode($api_response, true);
            if (!is_array($api_result)) {
                error_log('[Attendance report] Attendance API returned invalid JSON (HTTP ' . $http_status . ').');
                $report_error = 'The verifier attendance service returned an invalid response.';
            } elseif ($http_status < 200 || $http_status >= 300 || ($api_result['status'] ?? '') !== 'success') {
                $api_message = trim((string)($api_result['message'] ?? ''));
                error_log('[Attendance report] Attendance API returned HTTP ' . $http_status . ': ' . $api_message);
                $report_error = $api_message !== ''
                    ? 'Attendance service error: ' . $api_message
                    : 'The verifier attendance service could not load records.';
            } elseif (
                !isset($api_result['attendance'], $api_result['breaks'])
                || !is_array($api_result['attendance']) || !is_array($api_result['breaks'])
            ) {
                error_log('[Attendance report] Attendance API response is missing attendance or break data.');
                $report_error = 'The verifier attendance service returned incomplete data.';
            } else {
                $attendance_rows = $api_result['attendance'];
                $breaks = $api_result['breaks'];
            }
        }
    }
}

$break_totals = [];
foreach ($breaks as $break) {
    if (!is_array($break) || !isset($break['user_id'])) {
        continue;
    }
    $user_id = (int)$break['user_id'];
    $break_totals[$user_id] = ($break_totals[$user_id] ?? 0) + max(0, (int)($break['duration_seconds'] ?? 0));
}

$breaks_json = json_encode(
    $breaks,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($breaks_json === false) {
    error_log('[Attendance report] Unable to encode break records for the page.');
    $report_error = 'Unable to display the break details returned by the verifier service.';
    $breaks_json = '[]';
}

include 'includes/header.php';
?>

<style>
    .attendance-report-page {
        max-width: 1600px;
        margin: 24px auto 40px;
        padding: 0 20px;
    }

    .attendance-report-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .attendance-report-heading h1 {
        margin: 0 0 6px;
        color: var(--primary-navy);
        font-size: 27px;
    }

    .attendance-report-heading p {
        margin: 0;
        color: var(--text-muted);
    }

    .attendance-filter {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .attendance-filter label {
        display: grid;
        gap: 5px;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 700;
    }

    .attendance-filter .input-style {
        margin: 0;
    }

    .attendance-report-card {
        padding: 20px;
        overflow: hidden;
    }

    .attendance-table-wrap {
        overflow: auto;
    }

    .attendance-table {
        width: 100%;
        min-width: 950px;
        border-collapse: collapse;
    }

    .attendance-table th,
    .attendance-table td {
        padding: 12px 10px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
        vertical-align: middle;
    }

    .attendance-table th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: var(--primary-navy);
        color: #fff;
        font-size: 12px;
        white-space: nowrap;
    }

    .attendance-table tbody tr:hover {
        background: rgba(148, 163, 184, .1);
    }

    .attendance-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .attendance-active {
        background: #dcfce7;
        color: #166534;
    }

    .attendance-muted {
        color: var(--text-muted);
    }

    .attendance-message {
        margin-bottom: 16px;
        padding: 12px 15px;
        border-radius: 8px;
    }

    .attendance-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .attendance-error {
        background: #fee2e2;
        color: #991b1b;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000000;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(2, 6, 23, .78);
    }

    .modal-content {
        width: min(760px, 100%);
        max-height: 85vh;
        overflow: auto;
        padding: 24px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--bg-light);
        color: var(--text-dark);
        box-shadow: 0 20px 50px rgba(0, 0, 0, .35);
    }

    .break-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .break-modal-header h2 {
        margin: 0;
        color: var(--primary-navy);
    }

    .break-modal-close {
        border: 0;
        border-radius: 6px;
        padding: 4px 11px;
        background: #334155;
        color: #fff;
        font-size: 22px;
        cursor: pointer;
    }

    .break-detail-table {
        width: 100%;
        border-collapse: collapse;
    }

    .break-detail-table th,
    .break-detail-table td {
        padding: 10px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
        vertical-align: top;
    }

    .break-detail-table th {
        color: var(--primary-navy);
    }

    @media (max-width: 640px) {
        .attendance-report-page {
            padding: 0 12px;
        }

        .attendance-report-card {
            padding: 14px;
        }
    }
</style>

<main class="attendance-report-page">
    <div class="attendance-report-heading">
        <div>
            <h1>Verifier Attendance Report</h1>
            <p>Attendance, break totals, and overtime from the verifier system.</p>
        </div>
        <form method="get" class="attendance-filter">
            <label for="attendance-filter-date">Work Date
                <input class="input-style" id="attendance-filter-date" type="date" name="filter_date" value="<?= htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <button class="btn-primary" type="submit">Filter</button>
            <a class="btn-primary" href="attendance_report.php" style="text-decoration:none;">Today</a>
        </form>
    </div>

    <?php if ($date_error !== ''): ?>
        <div class="attendance-message attendance-warning" role="status"><?= htmlspecialchars($date_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($report_error !== ''): ?>
        <div class="attendance-message attendance-error" role="alert"><?= htmlspecialchars($report_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="modern-card attendance-report-card">
        <div class="attendance-table-wrap">
            <table class="excel-table attendance-table">
                <thead>
                    <tr>
                        <th>Verifier Name</th>
                        <th>Date</th>
                        <th>Login Time</th>
                        <th>Required Logout Time</th>
                        <th>Actual Logout Time</th>
                        <th>Break Minutes</th>
                        <th>Overtime Minutes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$report_error && !$attendance_rows): ?>
                        <tr>
                            <td colspan="8" class="attendance-muted" style="text-align:center;padding:24px;">No attendance records found for this date.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($attendance_rows as $attendance): ?>
                        <?php
                        if (!is_array($attendance)) {
                            continue;
                        }
                        $user_id = (int)($attendance['user_id'] ?? 0);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($attendance['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime((string)($attendance['work_date'] ?? $selected_date))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(attendanceReportFormatTime(isset($attendance['login_time']) ? (string)$attendance['login_time'] : null), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($attendance['required_logout_time'])
                                    ? htmlspecialchars(attendanceReportFormatTime((string)$attendance['required_logout_time']), ENT_QUOTES, 'UTF-8')
                                    : '<span class="attendance-muted">Not configured</span>' ?></td>
                            <td>
                                <?php if (($attendance['actual_logout_time'] ?? null) === null): ?>
                                    <span class="attendance-badge attendance-active">Active</span>
                                <?php else: ?>
                                    <?= htmlspecialchars(attendanceReportFormatTime((string)$attendance['actual_logout_time']), ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)floor(($break_totals[$user_id] ?? 0) / 60) ?></td>
                            <td><?= (int)($attendance['overtime_minutes'] ?? 0) ?></td>
                            <td>
                                <button
                                    type="button"
                                    class="btn-primary"
                                    data-user-id="<?= $user_id ?>"
                                    data-work-date="<?= htmlspecialchars((string)($attendance['work_date'] ?? $selected_date), ENT_QUOTES, 'UTF-8') ?>"
                                    data-staff-name="<?= htmlspecialchars((string)($attendance['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="loadBreaks(this)">View Breaks</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div id="breakDetailsModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="break-modal-title">
    <div class="modal-content">
        <div class="break-modal-header">
            <h2 id="break-modal-title">Break Details</h2>
            <button type="button" class="break-modal-close" onclick="closeBreakModal()" aria-label="Close">&times;</button>
        </div>
        <div id="breakDetailsContent" aria-live="polite"></div>
    </div>
</div>

<script>
    const breakModal = document.getElementById('breakDetailsModal');
    const breakContent = document.getElementById('breakDetailsContent');
    const breakTitle = document.getElementById('break-modal-title');
    const attendanceBreaks = <?= $breaks_json ?>;

    function closeBreakModal() {
        breakModal.style.display = 'none';
    }

    function formatBreakDuration(seconds) {
        const totalMinutes = Math.floor(Number(seconds) / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
    }

    function loadBreaks(button) {
        const userId = Number(button.dataset.userId);
        const userBreaks = attendanceBreaks.filter((item) => Number(item.user_id) === userId);
        breakModal.style.display = 'flex';
        breakTitle.textContent = `Break Details — ${button.dataset.staffName} (${button.dataset.workDate})`;
        renderBreaks(userBreaks);
    }

    function renderBreaks(breaks) {
        if (!Array.isArray(breaks) || breaks.length === 0) {
            breakContent.textContent = 'No breaks recorded for this date.';
            return;
        }

        const table = document.createElement('table');
        table.className = 'break-detail-table';
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Start Time', 'End Time', 'Duration', 'Reason'].forEach((label) => {
            const th = document.createElement('th');
            th.textContent = label;
            headerRow.appendChild(th);
        });
        head.appendChild(headerRow);
        table.appendChild(head);

        const body = document.createElement('tbody');
        breaks.forEach((item) => {
            const row = document.createElement('tr');
            [
                item.start_time || '',
                item.end_time || 'In progress',
                formatBreakDuration(item.duration_seconds),
                item.reason || '—'
            ].forEach((value) => {
                const cell = document.createElement('td');
                cell.textContent = String(value);
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
        table.appendChild(body);
        breakContent.replaceChildren(table);
    }

    breakModal.addEventListener('click', (event) => {
        if (event.target === breakModal) closeBreakModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeBreakModal();
    });
</script>

<?php include 'includes/footer.php'; ?>