<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

header('Content-Type: application/json; charset=utf-8');

function quiz_api_respond(int $status_code, array $payload): void
{
    http_response_code($status_code);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function quiz_api_sync_vertical(string $vertical, array $questions): bool
{
    if (!function_exists('curl_init')) {
        error_log('[Quiz API] cURL is unavailable; live question sync skipped.');
        return false;
    }

    $payload = json_encode([
        'action' => 'replace_vertical',
        'vertical' => $vertical,
        'questions' => $questions,
        'auth_key' => 'MakeMoney$$'
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        error_log('[Quiz API] Unable to encode live question sync payload.');
        return false;
    }

    $curl = curl_init('https://consumerhealthcarecompliance.com/workspace/api/v1/sync_quiz_question.php');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($curl);
    $http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $http_status < 200 || $http_status >= 300) {
        error_log('[Quiz API] Live question sync failed: ' . curl_error($curl) . ' (HTTP ' . $http_status . ')');
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    $result = json_decode((string)$response, true);
    return is_array($result) && ($result['status'] ?? '') === 'success';
}

$vertical = trim((string)($_GET['vertical'] ?? $_POST['vertical'] ?? ''));
if ($vertical === '' || strlen($vertical) > 150) {
    quiz_api_respond(400, ['status' => 'error', 'message' => 'Choose a valid vertical.']);
}

if (($_GET['action'] ?? '') === 'get_questions') {
    $stmt = $conn->prepare(
        "SELECT id, COALESCE(NULLIF(display_question, ''), question_text) AS question,
                COALESCE(hidden_keyword, NULLIF(keywords, '')) AS hidden_keyword
         FROM vertical_quiz_questions WHERE vertical = ? ORDER BY id"
    );
    if (!$stmt) {
        error_log('[Quiz API] Unable to prepare question lookup: ' . $conn->error);
        quiz_api_respond(500, ['status' => 'error', 'message' => 'Unable to load questions.']);
    }
    $stmt->bind_param('s', $vertical);
    if (!$stmt->execute()) {
        error_log('[Quiz API] Question lookup failed: ' . $stmt->error);
        $stmt->close();
        quiz_api_respond(500, ['status' => 'error', 'message' => 'Unable to load questions.']);
    }
    $result = $stmt->get_result();
    $questions = [];
    while ($row = $result->fetch_assoc()) {
        $questions[] = [
            'id' => (int)$row['id'],
            'question' => (string)($row['question'] ?? ''),
            'hidden_keyword' => (string)($row['hidden_keyword'] ?? '')
        ];
    }
    $stmt->close();
    quiz_api_respond(200, ['status' => 'success', 'questions' => $questions]);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body) || ($body['action'] ?? '') !== 'save_questions') {
    quiz_api_respond(400, ['status' => 'error', 'message' => 'Unsupported quiz operation.']);
}
if (
    empty($_SESSION['quiz_admin_csrf'])
    || !hash_equals((string)$_SESSION['quiz_admin_csrf'], (string)($body['csrf_token'] ?? ''))
) {
    quiz_api_respond(403, ['status' => 'error', 'message' => 'Invalid request token. Refresh and try again.']);
}
if (!isset($body['questions']) || !is_array($body['questions']) || count($body['questions']) > 500) {
    quiz_api_respond(400, ['status' => 'error', 'message' => 'Invalid question list.']);
}

$questions = [];
foreach ($body['questions'] as $item) {
    if (!is_array($item)) {
        quiz_api_respond(400, ['status' => 'error', 'message' => 'Invalid question data.']);
    }
    $question = trim((string)($item['question'] ?? ''));
    $hidden_keyword = trim((string)($item['hidden_keyword'] ?? ''));
    if ($question === '' && $hidden_keyword === '') continue;
    if ($question === '' || strlen($question) > 65535 || strlen($hidden_keyword) > 1000) {
        quiz_api_respond(400, ['status' => 'error', 'message' => 'Each saved question needs text, and mappings must be 1,000 characters or fewer.']);
    }
    $questions[] = [
        'display_question' => $question,
        'hidden_keyword' => $hidden_keyword === '' ? null : $hidden_keyword
    ];
}

if (!$conn->begin_transaction()) {
    error_log('[Quiz API] Unable to start question save transaction: ' . $conn->error);
    quiz_api_respond(500, ['status' => 'error', 'message' => 'Unable to save questions.']);
}

$delete_stmt = $conn->prepare('DELETE FROM vertical_quiz_questions WHERE vertical = ?');
$insert_stmt = $conn->prepare(
    'INSERT INTO vertical_quiz_questions (vertical, display_question, hidden_keyword, question_text, question_type, keywords)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$saved = false;
if ($delete_stmt && $insert_stmt) {
    $delete_stmt->bind_param('s', $vertical);
    $saved = $delete_stmt->execute();
    if (!$saved) error_log('[Quiz API] Existing question removal failed: ' . $delete_stmt->error);

    $question_type = 'text';
    foreach ($questions as $item) {
        if (!$saved) break;
        $question = $item['display_question'];
        $hidden_keyword = $item['hidden_keyword'];
        $keywords = $hidden_keyword ?? '';
        $insert_stmt->bind_param('ssssss', $vertical, $question, $hidden_keyword, $question, $question_type, $keywords);
        if (!$insert_stmt->execute()) {
            error_log('[Quiz API] Question insertion failed: ' . $insert_stmt->error);
            $saved = false;
        }
    }
} else {
    error_log('[Quiz API] Unable to prepare question replacement: ' . $conn->error);
}
if ($delete_stmt) $delete_stmt->close();
if ($insert_stmt) $insert_stmt->close();

if (!$saved || !$conn->commit()) {
    $conn->rollback();
    quiz_api_respond(500, ['status' => 'error', 'message' => 'Unable to save questions. No changes were committed.']);
}

$synced = quiz_api_sync_vertical($vertical, $questions);
quiz_api_respond(200, [
    'status' => 'success',
    'message' => count($questions) . ' question(s) saved locally.' . ($synced ? ' Live mapping synchronized.' : ' Live synchronization failed; local changes are saved.'),
    'synced' => $synced
]);
