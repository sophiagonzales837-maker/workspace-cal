<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

if (empty($_SESSION['quiz_admin_csrf'])) {
    $_SESSION['quiz_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['quiz_admin_csrf'];

function syncQuizMappingToLive(string $vertical, array $questions): bool
{
    $payload = json_encode([
        'action' => 'replace_vertical',
        'vertical' => $vertical,
        'questions' => $questions,
        'auth_key' => 'MakeMoney$$'
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false || !function_exists('curl_init')) {
        error_log('[Quiz sync] Unable to encode mapping or cURL is unavailable.');
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
        error_log('[Quiz sync] Live mapping replacement failed: ' . curl_error($curl) . ' (HTTP ' . $http_status . ')');
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    $result = json_decode((string)$response, true);
    return is_array($result) && ($result['status'] ?? '') === 'success';
}

$verticals = [
    '3M Combat Earplug',
    'AFFF',
    'ASD',
    'NEC',
    'BARD Powerport',
    'Camp Lejeune',
    'Cartiva',
    'Catheter',
    'Chlorpyrifos',
    'Depo Provera',
    'Dupixent',
    'Elmiron',
    'Hair Relaxer',
    'Hernia Mesh',
    'Hurricane',
    'CA Sexual Abuse',
    'CA Womens Prison',
    'IL Sexual Abuse',
    'LDS Sexual Abuse',
    'Lung Cancer',
    'MVA',
    'Olympus',
    'Ozempic',
    'Paraquat',
    'PFAS',
    'Ride Share',
    'Roblox',
    'RI-JDC',
    'Round Up',
    'Snapchat',
    'SMA',
    'SSDI',
    'Talcum',
    'Toxic Baby Food',
    'VGA',
    'Zantac'
];

function quiz_admin_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function parseVerifierQuestions(string $raw): array
{
    $questions = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') $questions[] = $line;
    }
    return $questions;
}

function parseCloserApplicationLabels(string $raw): array
{
    $labels = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/:-|:|-(?=\s|$)|(?<=\s)-(?=\S)/u', $line, $delimiter, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $label = trim(substr($line, 0, $delimiter[0][1]));
        if ($label !== '') $labels[] = $label;
    }
    return $labels;
}

$selected_vertical = trim((string)($_GET['vertical'] ?? ''));
if ($selected_vertical === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_vertical = trim((string)($_POST['vertical'] ?? ''));
}
if (!in_array($selected_vertical, $verticals, true)) $selected_vertical = $verticals[0];

$verifier_questions_raw = (string)($_POST['verifier_questions_raw'] ?? '');
$closer_application_raw = (string)($_POST['closer_application_raw'] ?? '');
$message = '';
$message_type = 'success';

if (isset($_SESSION['quiz_mapping_result']) && is_array($_SESSION['quiz_mapping_result'])) {
    $message = (string)($_SESSION['quiz_mapping_result']['message'] ?? '');
    $message_type = (string)($_SESSION['quiz_mapping_result']['type'] ?? 'success');
    unset($_SESSION['quiz_mapping_result']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $message = 'Invalid request token. Refresh and try again.';
        $message_type = 'error';
    } elseif (isset($_POST['parse_mapping'])) {
        unset($_SESSION['quiz_mapping_draft']);
        if (!in_array((string)($_POST['vertical'] ?? ''), $verticals, true)) {
            $message = 'Choose a valid vertical.';
            $message_type = 'error';
        } else {
            $parsed_questions = parseVerifierQuestions($verifier_questions_raw);
            $parsed_labels = parseCloserApplicationLabels($closer_application_raw);
            if (!$parsed_questions || !$parsed_labels) {
                $message = !$parsed_questions
                    ? 'No verifier questions were found.'
                    : 'No application labels were found. Each field line must contain :-, - or : between its label and value.';
                $message_type = 'error';
            } else {
                $_SESSION['quiz_mapping_draft'] = [
                    'token' => bin2hex(random_bytes(24)),
                    'vertical' => $selected_vertical,
                    'questions' => $parsed_questions,
                    'labels' => $parsed_labels,
                    'verifier_questions_raw' => $verifier_questions_raw,
                    'closer_application_raw' => $closer_application_raw
                ];
            }
        }
    } elseif (isset($_POST['save_mapping'])) {
        $draft = $_SESSION['quiz_mapping_draft'] ?? null;
        $submitted_token = (string)($_POST['draft_token'] ?? '');
        if (
            !is_array($draft)
            || !hash_equals((string)($draft['token'] ?? ''), $submitted_token)
            || !in_array((string)($draft['vertical'] ?? ''), $verticals, true)
            || !is_array($draft['questions'] ?? null)
            || !is_array($draft['labels'] ?? null)
        ) {
            $message = 'This parsed mapping has expired. Paste the source text again to continue.';
            $message_type = 'error';
        } else {
            $submitted_mappings = $_POST['mapped_label'] ?? null;
            $mapped_questions = [];
            $valid_mappings = is_array($submitted_mappings)
                && count($submitted_mappings) === count($draft['questions']);

            if ($valid_mappings) {
                foreach ($draft['questions'] as $index => $question) {
                    if (!array_key_exists($index, $submitted_mappings) || !is_string($question)) {
                        $valid_mappings = false;
                        break;
                    }
                    $choice = $submitted_mappings[$index];
                    if (!is_string($choice)) {
                        $valid_mappings = false;
                        break;
                    }
                    if ($choice === '') {
                        $hidden_keyword = null;
                    } elseif (ctype_digit($choice) && array_key_exists((int)$choice, $draft['labels'])) {
                        $hidden_keyword = (string)$draft['labels'][(int)$choice];
                    } else {
                        $valid_mappings = false;
                        break;
                    }

                    $question = trim($question);
                    if ($question === '' || strlen($question) > 65535 || ($hidden_keyword !== null && strlen($hidden_keyword) > 1000)) {
                        $valid_mappings = false;
                        break;
                    }
                    $mapped_questions[] = [
                        'display_question' => $question,
                        'hidden_keyword' => $hidden_keyword
                    ];
                }
            }

            if (!$valid_mappings || count($mapped_questions) !== count($draft['questions']) || !$mapped_questions) {
                $message = 'Every verifier question must have exactly one valid mapping selection. Use -- No Mapping Needed -- when appropriate.';
                $message_type = 'error';
            } else {
                $vertical = (string)$draft['vertical'];
                $transaction_started = $conn->begin_transaction();
                $saved = false;
                if ($transaction_started) {
                    $delete_stmt = $conn->prepare('DELETE FROM vertical_quiz_questions WHERE vertical = ?');
                    if ($delete_stmt) {
                        $delete_stmt->bind_param('s', $vertical);
                        if ($delete_stmt->execute()) {
                            $delete_stmt->close();
                            $insert_stmt = $conn->prepare(
                                'INSERT INTO vertical_quiz_questions (vertical, display_question, hidden_keyword, question_text, question_type, keywords) VALUES (?, ?, ?, ?, ?, ?)'
                            );
                            if ($insert_stmt) {
                                $question_type = 'text';
                                $inserted_count = 0;
                                foreach ($mapped_questions as $mapped_question) {
                                    $display_question = $mapped_question['display_question'];
                                    $hidden_keyword = $mapped_question['hidden_keyword'];
                                    $legacy_keywords = $hidden_keyword ?? '';
                                    $insert_stmt->bind_param(
                                        'ssssss',
                                        $vertical,
                                        $display_question,
                                        $hidden_keyword,
                                        $display_question,
                                        $question_type,
                                        $legacy_keywords
                                    );
                                    if (!$insert_stmt->execute()) {
                                        error_log('[Quiz manager] Mapping insert failed: ' . $insert_stmt->error);
                                        break;
                                    }
                                    $inserted_count++;
                                }
                                $all_inserted = $inserted_count === count($mapped_questions);
                                $insert_stmt->close();
                                if ($all_inserted && $conn->commit()) {
                                    $saved = true;
                                } else {
                                    $conn->rollback();
                                    $saved = false;
                                }
                            } else {
                                error_log('[Quiz manager] Unable to prepare mapping insert: ' . $conn->error);
                                $conn->rollback();
                            }
                        } else {
                            error_log('[Quiz manager] Mapping replacement delete failed: ' . $delete_stmt->error);
                            $delete_stmt->close();
                            $conn->rollback();
                        }
                    } else {
                        error_log('[Quiz manager] Unable to prepare mapping replacement delete: ' . $conn->error);
                        $conn->rollback();
                    }
                } else {
                    error_log('[Quiz manager] Unable to start mapping replacement transaction: ' . $conn->error);
                }

                if (!$saved) {
                    $message = 'Unable to replace the quiz mapping. No local changes were committed; check the server logs and confirm the quiz table schema.';
                    $message_type = 'error';
                } else {
                    unset($_SESSION['quiz_mapping_draft']);
                    $live_synced = syncQuizMappingToLive($vertical, $mapped_questions);
                    $_SESSION['quiz_mapping_result'] = [
                        'message' => count($mapped_questions) . ' question(s) saved locally.'
                            . ($live_synced ? ' Live mapping synchronized.' : ' Live synchronization failed; the local mapping is saved. Check the server logs.'),
                        'type' => $live_synced ? 'success' : 'error'
                    ];
                    header('Location: verifier_quiz.php?vertical=' . rawurlencode($vertical));
                    exit;
                }
            }
        }
    }
}

$draft = $_SESSION['quiz_mapping_draft'] ?? null;
if (!is_array($draft) || !isset($draft['questions'], $draft['labels'], $draft['vertical'])) {
    $draft = null;
} else {
    $selected_vertical = (string)$draft['vertical'];
    $verifier_questions_raw = (string)($draft['verifier_questions_raw'] ?? $verifier_questions_raw);
    $closer_application_raw = (string)($draft['closer_application_raw'] ?? $closer_application_raw);
}

include 'includes/header.php';
?>
<style>
    .quiz-manager {
        max-width: 1100px;
        margin: 24px auto;
        color: #F8FAFC;
    }

    .quiz-manager .panel {
        background: #0B1220;
        border: 1px solid #334155;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
    }

    .quiz-manager h1,
    .quiz-manager h2 {
        color: #F8FAFC;
        margin-top: 0;
    }

    .quiz-manager p {
        color: #F8FAFC !important;
    }

    .quiz-manager label {
        display: grid;
        gap: 6px;
        color: #E2E8F0;
        font-size: 13px;
        font-weight: 700;
    }

    .quiz-manager select,
    .quiz-manager textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #334155;
        border-radius: 6px;
        box-sizing: border-box;
        font: inherit;
        color: #F8FAFC;
        background: #030712;
    }

    .quiz-manager textarea {
        min-height: 220px;
        resize: vertical;
    }

    .quiz-manager .grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .quiz-manager .actions {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-top: 16px;
    }

    .quiz-manager .action-btn {
        border: 0;
        border-radius: 8px;
        padding: 9px 13px;
        color: #FFFFFF;
        background: #2563EB;
        font-weight: 700;
        cursor: pointer;
    }

    .quiz-manager .notice {
        padding: 12px 14px;
        border-radius: 6px;
        margin-bottom: 16px;
        background: #052e2b;
        color: #A7F3D0;
    }

    .quiz-manager .notice.error {
        background: #450a0a;
        color: #FECACA;
    }

    .quiz-manager table {
        width: 100%;
        border-collapse: collapse;
    }

    .quiz-manager th,
    .quiz-manager td {
        padding: 12px;
        border: 1px solid #334155;
        text-align: left;
        vertical-align: top;
        border-radius: 0 !important;
        color: #E2E8F0;
        background: #0F172A;
    }

    .quiz-manager th {
        background: #1E293B;
        color: #F8FAFC;
        font-size: 12px;
        text-transform: uppercase;
    }

    .quiz-manager table,
    .quiz-manager thead,
    .quiz-manager tbody,
    .quiz-manager tr {
        border-radius: 0 !important;
    }

    .quiz-manager tbody tr:hover td {
        background: #1E293B !important;
        color: #FFFFFF !important;
    }

    .quiz-manager .table-wrap {
        overflow-x: auto;
    }

    .quiz-manager .mapping-table {
        min-width: 720px;
    }

    @media (max-width: 640px) {
        .quiz-manager .grid {
            grid-template-columns: 1fr;
        }

        .quiz-manager .panel {
            padding: 16px;
        }
    }
</style>
<main class="quiz-manager">
    <header style="margin-bottom:20px;border-bottom:1px solid #334155;padding-bottom:14px;">
        <h1 style="margin-bottom:4px;">Verifier Quiz Smart Mapper</h1>
        <div style="color:#94a3b8;">Replace a vertical's questions with one explicit application-field mapping per question.</div>
    </header>
    <?php if ($message !== ''): ?><div class="notice <?= $message_type === 'error' ? 'error' : '' ?>"><?= quiz_admin_h($message) ?></div><?php endif; ?>

    <section class="panel">
        <h2>Step 1: Paste both source lists</h2>
        <p style="color:#64748b;margin-top:-8px;">Use one verifier question per line. Application lines can separate a label and value with <code>:-</code>, <code>-</code> or <code>:</code>.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= quiz_admin_h($csrf_token) ?>">
            <label style="max-width:420px;">Vertical
                <select name="vertical" required onchange="loadVerticalQuestions(this.value)">
                    <?php foreach ($verticals as $vertical): ?><option value="<?= quiz_admin_h($vertical) ?>" <?= $selected_vertical === $vertical ? 'selected' : '' ?>><?= quiz_admin_h($vertical) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="grid" style="margin-top:16px;">
                <label>Verifier questions
                    <textarea id="verifier_questions_raw" name="verifier_questions_raw" rows="15" placeholder="What condition were you diagnosed with?&#10;When did the symptoms begin?" required><?= quiz_admin_h($verifier_questions_raw) ?></textarea>
                </label>
                <label>Closer application
                    <textarea id="closer_application_raw" name="closer_application_raw" rows="15" placeholder="Diagnosis :- Example diagnosis&#10;Date symptoms began: 2020&#10;Product used - Example product" required><?= quiz_admin_h($closer_application_raw) ?></textarea>
                </label>
            </div>
            <div class="actions">
                <button class="action-btn" type="submit" name="parse_mapping" value="1">Parse and Map</button>
            </div>
        </form>

        <?php if ($draft): ?>
            <div style="margin-top:24px;padding-top:20px;border-top:1px solid #e2e8f0;">
                <h2>Step 2: Map each verifier question</h2>
                <p style="color:#64748b;margin-top:-8px;">Each question is saved once. Leave the default selected when no application-field mapping is needed.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= quiz_admin_h($csrf_token) ?>">
                    <input type="hidden" name="draft_token" value="<?= quiz_admin_h($draft['token']) ?>">
                    <div class="table-wrap">
                        <table class="mapping-table">
                            <thead>
                                <tr>
                                    <th>Verifier Question</th>
                                    <th>Closer Application Label</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($draft['questions'] as $index => $question): ?>
                                    <tr>
                                        <td><?= nl2br(quiz_admin_h($question)) ?></td>
                                        <td>
                                            <select name="mapped_label[<?= (int)$index ?>]">
                                                <option value="">-- No Mapping Needed --</option>
                                                <?php foreach ($draft['labels'] as $label_index => $label): ?>
                                                    <option value="<?= (int)$label_index ?>"><?= quiz_admin_h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="actions">
                        <button class="action-btn" type="submit" name="save_mapping" value="1">Replace Vertical Mapping</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <?php include '../shared/quiz_questions_editor.php'; ?>
</main>
<?php include 'includes/footer.php'; ?>