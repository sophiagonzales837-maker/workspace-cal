<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../shared/criteria_storage.php';
protect('Closer');

try {
    workspace_ensure_criteria_schema($conn);
} catch (Throwable $error) {
    error_log('[Criteria popup] Schema initialization failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load criteria.');
}

$clients = [];
$client_result = $conn->query('SELECT id, client_code FROM clients WHERE is_hidden = 0 ORDER BY sort_order ASC, id ASC');
if (!$client_result) {
    error_log('[Criteria popup] Client query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria.');
}
while ($row = $client_result->fetch_assoc()) $clients[] = $row;

$verticals = [];
$vertical_result = $conn->query('SELECT id, vertical_name FROM verticals WHERE is_hidden = 0 ORDER BY sort_order ASC, id ASC');
if (!$vertical_result) {
    error_log('[Criteria popup] Vertical query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria.');
}
while ($row = $vertical_result->fetch_assoc()) $verticals[] = $row;

$criteria_map = [];
$criteria_result = $conn->query('SELECT client_id, vertical_id, is_active, criteria_text FROM criteria');
if (!$criteria_result) {
    error_log('[Criteria popup] Matrix query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria.');
}
while ($row = $criteria_result->fetch_assoc()) {
    $criteria_map[(int)$row['client_id']][(int)$row['vertical_id']] = $row;
}
?>

<div class="matrix-wrapper">
    <table class="excel-table">
        <thead class="excel-header-row">
            <tr>
                <th class="excel-row-header excel-corner">Verticals</th>
                <?php foreach ($clients as $client): ?>
                    <th><?= htmlspecialchars((string)$client['client_code'], ENT_QUOTES, 'UTF-8') ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!$verticals): ?>
                <tr><td colspan="<?= max(1, count($clients) + 1) ?>">No visible criteria available.</td></tr>
            <?php else: foreach ($verticals as $vertical): ?>
                <tr>
                    <td class="excel-row-header"><?= htmlspecialchars((string)$vertical['vertical_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <?php foreach ($clients as $client):
                        $cell = $criteria_map[(int)$client['id']][(int)$vertical['id']] ?? [];
                        $is_active = !empty($cell['is_active']);
                    ?>
                        <td class="<?= $is_active ? 'cell-active' : 'cell-inactive' ?>">
                            <div class="matrix-cell">
                                <?php if ($is_active): ?>
                                    <div class="criteria-active-badge">ACTIVE</div>
                                <?php endif; ?>
                                <textarea readonly class="cell-textarea" aria-label="Criteria for <?= htmlspecialchars((string)$vertical['vertical_name'] . ' / ' . (string)$client['client_code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="No criteria entered..."><?= htmlspecialchars((string)($cell['criteria_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<style>
    .cell-active { background: #103b25 !important; }
    .cell-inactive { background: #0b1220 !important; }
    .criteria-active-badge { position: absolute; top: 5px; right: 5px; z-index: 2; padding: 2px 6px; border-radius: 4px; background: #2e7d32; color: #fff; font-size: 10px; font-weight: bold; }
</style>
