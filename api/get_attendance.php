<?php
header('Content-Type: application/json; charset=utf-8');

function attendanceApiRespond(array $payload, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$auth_key = (string)($_GET['auth_key'] ?? '');
if (!hash_equals('MakeMoney$$', $auth_key)) {
    attendanceApiRespond(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

require_once __DIR__ . '/../config/db.php';

if (isset($_GET['date']) && trim((string)$_GET['date']) !== '') {
    $date = trim((string)$_GET['date']);
} else {
    $today_result = $conn->query('SELECT CURDATE() AS current_date');
    if (!$today_result) {
        error_log('[Attendance API] Unable to determine current database date: ' . $conn->error);
        attendanceApiRespond(['status' => 'error', 'message' => 'Unable to determine report date.'], 500);
    }
    $today = $today_result->fetch_assoc();
    $date = (string)($today['current_date'] ?? '');
    $today_result->free();
}

if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts)
    || !checkdate((int)$date_parts[2], (int)$date_parts[3], (int)$date_parts[1])) {
    attendanceApiRespond(['status' => 'error', 'message' => 'A valid date in YYYY-MM-DD format is required.'], 400);
}

$attendance_stmt = $conn->prepare(
    'SELECT da.id AS attendance_id, da.user_id, da.work_date, da.login_time,
            da.actual_logout_time, da.overtime_minutes, u.username, u.role, u.team,
            t.logout_time AS required_logout_time
     FROM daily_attendance da
     INNER JOIN users u ON u.id = da.user_id
     LEFT JOIN teams t ON t.name = u.team
     WHERE u.role = ? AND da.work_date = ?
     ORDER BY da.login_time DESC'
);
if (!$attendance_stmt) {
    error_log('[Attendance API] Attendance query prepare failed: ' . $conn->error);
    attendanceApiRespond(['status' => 'error', 'message' => 'Unable to prepare attendance query.'], 500);
}

$verifier_role = 'Verifier';
$attendance_stmt->bind_param('ss', $verifier_role, $date);
if (!$attendance_stmt->execute()) {
    error_log('[Attendance API] Attendance query failed: ' . $attendance_stmt->error);
    $attendance_stmt->close();
    attendanceApiRespond(['status' => 'error', 'message' => 'Unable to load attendance records.'], 500);
}
$attendance_result = $attendance_stmt->get_result();
if (!$attendance_result) {
    error_log('[Attendance API] Unable to read attendance query results: ' . $attendance_stmt->error);
    $attendance_stmt->close();
    attendanceApiRespond(['status' => 'error', 'message' => 'Unable to read attendance records.'], 500);
}

$attendance = [];
$user_ids = [];
while ($row = $attendance_result->fetch_assoc()) {
    $attendance[] = $row;
    $user_ids[(int)$row['user_id']] = true;
}
$attendance_stmt->close();

$breaks = [];
if ($user_ids) {
    $break_stmt = $conn->prepare(
        'SELECT bl.user_id, bl.start_time, bl.end_time,
                GREATEST(0, TIMESTAMPDIFF(
                    SECOND, bl.start_time,
                    LEAST(COALESCE(bl.end_time, NOW()), COALESCE(da.actual_logout_time, NOW()))
                )) AS duration_seconds,
                bl.reason
         FROM break_logs bl
         INNER JOIN users u ON u.id = bl.user_id AND u.role = ?
         LEFT JOIN daily_attendance da ON da.user_id = bl.user_id AND da.work_date = ?
         WHERE bl.start_time >= ? AND bl.start_time < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY bl.start_time ASC'
    );
    if (!$break_stmt) {
        error_log('[Attendance API] Break query prepare failed: ' . $conn->error);
        attendanceApiRespond(['status' => 'error', 'message' => 'Unable to prepare break query.'], 500);
    }

    $break_stmt->bind_param('ssss', $verifier_role, $date, $date, $date);
    if (!$break_stmt->execute()) {
        error_log('[Attendance API] Break query failed: ' . $break_stmt->error);
        $break_stmt->close();
        attendanceApiRespond(['status' => 'error', 'message' => 'Unable to load break records.'], 500);
    }
    $break_result = $break_stmt->get_result();
    if (!$break_result) {
        error_log('[Attendance API] Unable to read break query results: ' . $break_stmt->error);
        $break_stmt->close();
        attendanceApiRespond(['status' => 'error', 'message' => 'Unable to read break records.'], 500);
    }
    while ($break = $break_result->fetch_assoc()) {
        if (isset($user_ids[(int)$break['user_id']])) {
            $breaks[] = $break;
        }
    }
    $break_stmt->close();
}

attendanceApiRespond([
    'status' => 'success',
    'attendance' => $attendance,
    'breaks' => $breaks,
]);
