<?php
require_once __DIR__ . '/criteria_storage.php';

$criteria_module_dir = $criteria_module_dir ?? dirname(__DIR__) . '/admin';
$criteria_add_error = '';

try {
    workspace_ensure_criteria_schema($conn, true);
} catch (Throwable $error) {
    error_log('[Criteria] Schema initialization failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to initialize the criteria matrix.');
}

if (empty($_SESSION['criteria_csrf_token'])) {
    $_SESSION['criteria_csrf_token'] = bin2hex(random_bytes(32));
}
$criteria_csrf_token = (string)$_SESSION['criteria_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cell') {
    header('Content-Type: application/json; charset=utf-8');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $vertical_id = (int)($_POST['vertical_id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $value = (string)($_POST['value'] ?? '');
    $allowed_fields = ['is_active', 'criteria_text'];

    if (
        $client_id <= 0
        || $vertical_id <= 0
        || !in_array($field, $allowed_fields, true)
        || !hash_equals($criteria_csrf_token, (string)($_POST['csrf_token'] ?? ''))
    ) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid criteria update.']);
        exit;
    }
    $cell_ids_stmt = $conn->prepare(
        'SELECT (SELECT COUNT(*) FROM clients WHERE id = ?) AS client_exists,
                (SELECT COUNT(*) FROM verticals WHERE id = ?) AS vertical_exists'
    );
    if (!$cell_ids_stmt) {
        error_log('[Criteria] Cell validation prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to save this criteria cell.']);
        exit;
    }
    $cell_ids_stmt->bind_param('ii', $client_id, $vertical_id);
    if (!$cell_ids_stmt->execute()) {
        error_log('[Criteria] Cell validation failed: ' . $cell_ids_stmt->error);
        $cell_ids_stmt->close();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to save this criteria cell.']);
        exit;
    }
    $cell_ids = $cell_ids_stmt->get_result()->fetch_assoc();
    $cell_ids_stmt->close();
    if (!$cell_ids || !(int)$cell_ids['client_exists'] || !(int)$cell_ids['vertical_exists']) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'The selected client or vertical no longer exists.']);
        exit;
    }
    if ($field === 'is_active') {
        $value = $value === '1' ? '1' : '0';
    }

    $stmt = $conn->prepare(
        "INSERT INTO criteria (client_id, vertical_id, `$field`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `$field` = VALUES(`$field`)"
    );
    if (!$stmt) {
        error_log('[Criteria] Cell save prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to save this criteria cell.']);
        exit;
    }
    $stmt->bind_param('iis', $client_id, $vertical_id, $value);
    if (!$stmt->execute()) {
        error_log('[Criteria] Cell save failed: ' . $stmt->error);
        $stmt->close();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to save this criteria cell.']);
        exit;
    }
    $stmt->close();
    workspace_invalidate_criteria_cache();
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manage_item') {
    header('Content-Type: application/json; charset=utf-8');
    $type = (string)($_POST['type'] ?? '');
    $item_id = (int)($_POST['id'] ?? 0);
    $operation = (string)($_POST['operation'] ?? '');
    $csrf_token = (string)($_POST['csrf_token'] ?? '');
    $entities = [
        'client' => ['table' => 'clients', 'name_column' => 'client_code'],
        'vertical' => ['table' => 'verticals', 'name_column' => 'vertical_name']
    ];
    if (
        !isset($entities[$type])
        || $item_id <= 0
        || !in_array($operation, ['rename', 'delete', 'move_up', 'move_down', 'toggle_visibility'], true)
        || !hash_equals($criteria_csrf_token, $csrf_token)
    ) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid item update.']);
        exit;
    }

    $table = $entities[$type]['table'];
    $name_column = $entities[$type]['name_column'];
    try {
        $transaction_started = false;
        if ($operation === 'rename') {
            $name = trim((string)($_POST['name'] ?? ''));
            $max_length = $type === 'client' ? 100 : 150;
            if ($name === '' || workspace_criteria_string_length($name) > $max_length) {
                throw new InvalidArgumentException('Enter a name within the allowed length.');
            }
            $stmt = $conn->prepare("UPDATE `$table` SET `$name_column` = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('si', $name, $item_id);
            if (!$stmt->execute()) {
                $error_message = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error_message);
            }
            $stmt->close();
        } elseif ($operation === 'toggle_visibility') {
            $stmt = $conn->prepare("UPDATE `$table` SET is_hidden = IF(is_hidden = 1, 0, 1) WHERE id = ?");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('i', $item_id);
            if (!$stmt->execute()) {
                $error_message = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error_message);
            }
            $stmt->close();
        } elseif ($operation === 'delete') {
            if (!$conn->begin_transaction()) throw new RuntimeException($conn->error);
            $transaction_started = true;
            $delete_cells = $conn->prepare($type === 'client'
                ? 'DELETE FROM criteria WHERE client_id = ?'
                : 'DELETE FROM criteria WHERE vertical_id = ?');
            if (!$delete_cells) throw new RuntimeException($conn->error);
            $delete_cells->bind_param('i', $item_id);
            if (!$delete_cells->execute()) {
                $error_message = $delete_cells->error;
                $delete_cells->close();
                throw new RuntimeException($error_message);
            }
            $delete_cells->close();
            $delete_item = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
            if (!$delete_item) throw new RuntimeException($conn->error);
            $delete_item->bind_param('i', $item_id);
            if (!$delete_item->execute()) {
                $error_message = $delete_item->error;
                $delete_item->close();
                throw new RuntimeException($error_message);
            }
            $deleted = $delete_item->affected_rows === 1;
            $delete_item->close();
            if (!$deleted) throw new RuntimeException('The selected item no longer exists.');
            if (!$conn->commit()) throw new RuntimeException($conn->error);
            $transaction_started = false;
        } else {
            $direction = $operation === 'move_up' ? -1 : 1;
            $result = $conn->query("SELECT id, sort_order FROM `$table` ORDER BY sort_order ASC, id ASC");
            if (!$result) throw new RuntimeException($conn->error);
            $ordered_items = [];
            while ($item = $result->fetch_assoc()) $ordered_items[] = $item;
            $current_index = null;
            foreach ($ordered_items as $index => $item) {
                if ((int)$item['id'] === $item_id) {
                    $current_index = $index;
                    break;
                }
            }
            $target_index = $current_index === null ? -1 : $current_index + $direction;
            if ($current_index === null || !isset($ordered_items[$target_index])) {
                throw new InvalidArgumentException('This item cannot be moved further in that direction.');
            }
            [$ordered_items[$current_index], $ordered_items[$target_index]] = [$ordered_items[$target_index], $ordered_items[$current_index]];
            if (!$conn->begin_transaction()) throw new RuntimeException($conn->error);
            $transaction_started = true;
            $sort_stmt = $conn->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ?");
            if (!$sort_stmt) throw new RuntimeException($conn->error);
            foreach ($ordered_items as $index => $item) {
                $ordered_index = $index;
                $ordered_id = (int)$item['id'];
                $sort_stmt->bind_param('ii', $ordered_index, $ordered_id);
                if (!$sort_stmt->execute()) {
                    $error_message = $sort_stmt->error;
                    $sort_stmt->close();
                    throw new RuntimeException($error_message);
                }
            }
            $sort_stmt->close();
            if (!$conn->commit()) throw new RuntimeException($conn->error);
            $transaction_started = false;
        }
        workspace_invalidate_criteria_cache();
        echo json_encode(['status' => 'success']);
    } catch (InvalidArgumentException $error) {
        if (!empty($transaction_started)) $conn->rollback();
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        if (!empty($transaction_started)) $conn->rollback();
        error_log('[Criteria] Item update failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Unable to update this item. Check that names are unique and try again.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_client']) || isset($_POST['add_vertical']))) {
    if (!hash_equals($criteria_csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Invalid request token.');
    }
    try {
        if (isset($_POST['add_client'])) {
            $new_client = trim((string)($_POST['new_client'] ?? ''));
            if ($new_client === '' || workspace_criteria_string_length($new_client) > 100) {
                throw new InvalidArgumentException('Client code is required and must be at most 100 characters.');
            }
            workspace_add_criteria_client($conn, $new_client);
        } else {
            $new_vertical = trim((string)($_POST['new_vertical'] ?? ''));
            if ($new_vertical === '' || workspace_criteria_string_length($new_vertical) > 150) {
                throw new InvalidArgumentException('Vertical name is required and must be at most 150 characters.');
            }
            $stmt = $conn->prepare(
                'INSERT INTO verticals (vertical_name, sort_order)
                 SELECT ?, COALESCE(MAX(sort_order), 0) + 1 FROM verticals'
            );
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('s', $new_vertical);
            if (!$stmt->execute()) {
                $error_message = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error_message);
            }
            $stmt->close();
        }
        workspace_invalidate_criteria_cache();
    } catch (Throwable $error) {
        error_log('[Criteria] Add item failed: ' . $error->getMessage());
        $criteria_add_error = $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'Unable to add this item. Check for a duplicate name and try again.';
    }
    $_SESSION['criteria_add_error'] = $criteria_add_error;
    header('Location: criteria.php');
    exit;
}

if (!empty($_SESSION['criteria_add_error'])) {
    $criteria_add_error = (string)$_SESSION['criteria_add_error'];
    unset($_SESSION['criteria_add_error']);
}

$clients = [];
$client_result = $conn->query('SELECT id, client_code, sort_order, is_hidden FROM clients WHERE is_hidden = 0 ORDER BY sort_order ASC, id ASC');
if (!$client_result) {
    error_log('[Criteria] Client query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria clients.');
}
while ($row = $client_result->fetch_assoc()) $clients[] = $row;

$verticals = [];
$vertical_result = $conn->query('SELECT id, vertical_name, sort_order, is_hidden FROM verticals WHERE is_hidden = 0 ORDER BY sort_order ASC, id ASC');
if (!$vertical_result) {
    error_log('[Criteria] Vertical query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria verticals.');
}
while ($row = $vertical_result->fetch_assoc()) $verticals[] = $row;

$criteria_map = [];
$criteria_result = $conn->query('SELECT client_id, vertical_id, is_active, criteria_text FROM criteria');
if (!$criteria_result) {
    error_log('[Criteria] Matrix query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load criteria cells.');
}
while ($row = $criteria_result->fetch_assoc()) {
    $criteria_map[(int)$row['client_id']][(int)$row['vertical_id']] = $row;
}

$settings_clients = [];
$all_client_result = $conn->query('SELECT id, client_code AS name, sort_order, is_hidden FROM clients ORDER BY sort_order ASC, id ASC');
if (!$all_client_result) {
    error_log('[Criteria] Client settings query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load client settings.');
}
while ($row = $all_client_result->fetch_assoc()) $settings_clients[] = $row;

$settings_verticals = [];
$all_vertical_result = $conn->query('SELECT id, vertical_name AS name, sort_order, is_hidden FROM verticals ORDER BY sort_order ASC, id ASC');
if (!$all_vertical_result) {
    error_log('[Criteria] Vertical settings query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load vertical settings.');
}
while ($row = $all_vertical_result->fetch_assoc()) $settings_verticals[] = $row;

include $criteria_module_dir . '/includes/header.php';
?>
<style>
    .criteria-admin-page { max-width: 1600px; margin: 0 auto; padding: 20px; }
    .criteria-heading { margin: 0 0 14px; color: #f3f7f8; font-size: 24px; }
    .criteria-tabs { display: flex; gap: 6px; border-bottom: 1px solid #334155; margin-bottom: 20px; }
    .criteria-tab { padding: 12px 20px; background: transparent; color: #94a3b8; border: 0; border-bottom: 3px solid transparent; font-weight: 700; cursor: pointer; font-size: 14px; }
    .criteria-tab[aria-selected="true"] { color: #38bdf8; border-bottom-color: #38bdf8; }
    .criteria-panel { display: none; }
    .criteria-panel.active { display: block; }
    .excel-container { overflow: auto; border: 1px solid #334155; border-radius: 8px; max-height: 65vh; }
    .criteria-matrix { width: 100%; white-space: nowrap; margin: 0; border-collapse: separate; border-spacing: 0; }
    .criteria-matrix thead th { position: sticky; top: 0; z-index: 10; background: #0f172a; }
    .criteria-matrix .vertical-heading { position: sticky; left: 0; z-index: 8; background: #1e293b; }
    .criteria-matrix thead .vertical-heading { z-index: 12; background: #0f172a; }
    .criteria-textarea { display: block; width: 260px; max-width: 100%; height: auto; min-height: 72px; overflow-y: hidden; resize: none; white-space: pre-wrap; border: 1px solid #334155; background: #0f172a; color: #f8fafc; border-radius: 4px; padding: 6px; font-size: 11px; line-height: 1.4; box-sizing: border-box; }
    .criteria-settings-modal { display: none; position: fixed; inset: 0; z-index: 100000; align-items: center; justify-content: center; background: rgba(0,0,0,.7); }
    .criteria-settings-content { background: #0f172a; border: 1px solid #38bdf8; border-radius: 12px; width: 900px; max-width: 94vw; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
    .criteria-settings-lists { display: flex; gap: 20px; padding: 20px; overflow-y: auto; flex: 1; }
    .criteria-settings-column { flex: 1; min-width: 0; background: #1e293b; padding: 15px; border-radius: 8px; }
    .criteria-settings-item { display: flex; justify-content: space-between; align-items: center; gap: 8px; background: #0b1220; padding: 8px 10px; border-radius: 4px; border: 1px solid #334155; margin-bottom: 8px; }
    .criteria-settings-item.is-hidden { color: #64748b; text-decoration: line-through; }
    .criteria-settings-actions { display: flex; gap: 4px; }
    .criteria-settings-actions button { background: #334155; border: 0; color: white; border-radius: 3px; cursor: pointer; padding: 4px 7px; font-size: 11px; }
    .criteria-status { min-height: 18px; font-size: 12px; color: #10b981; }
    @media (max-width: 700px) { .criteria-settings-lists { flex-direction: column; } }
</style>

<main class="criteria-admin-page">
    <h1 class="criteria-heading">Criteria &amp; Templates</h1>
    <div class="criteria-tabs" role="tablist">
        <button class="criteria-tab" type="button" role="tab" id="criteria-tab" aria-controls="criteria-panel" aria-selected="true">Criteria Builder</button>
        <button class="criteria-tab" type="button" role="tab" id="templates-tab" aria-controls="templates-panel" aria-selected="false">Application Templates</button>
    </div>

    <section class="criteria-panel active" id="criteria-panel" role="tabpanel" aria-labelledby="criteria-tab">
        <div class="card" style="padding:24px;background:#0f172a;border:1px solid rgba(56,189,248,.2);border-radius:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;">
                <h2 style="color:#f8fafc;margin:0;">Matrix Editor</h2>
                <div style="display:flex;gap:12px;align-items:center;">
                    <span id="matrix-save-status" class="criteria-status" role="status"></span>
                    <button type="button" onclick="openMatrixSettings()" class="btn" style="background:rgba(255,255,255,.1);border:1px solid #475569;color:#f8fafc;padding:8px 15px;border-radius:6px;cursor:pointer;"><i class="fas fa-cog"></i> Manage Clients &amp; Verticals</button>
                </div>
            </div>
            <?php if ($criteria_add_error !== ''): ?>
                <p role="alert" style="color:#fca5a5;"><?= htmlspecialchars($criteria_add_error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:20px;background:#1e293b;padding:16px;border-radius:8px;border:1px solid #334155;">
                <form method="POST" action="criteria.php" style="flex:1;min-width:270px;display:flex;gap:8px;margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($criteria_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="add_client" value="1">
                    <input type="text" name="new_client" maxlength="100" placeholder="Add Client Code..." required style="min-width:0;flex:1;padding:10px;border:1px solid #475569;background:#0b1220;color:#f8fafc;border-radius:6px;">
                    <button type="submit" class="btn" style="background:#38bdf8;color:#0b1220;padding:10px 18px;border-radius:6px;font-weight:bold;border:0;cursor:pointer;">Add Client</button>
                </form>
                <form method="POST" action="criteria.php" style="flex:1;min-width:270px;display:flex;gap:8px;margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($criteria_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="add_vertical" value="1">
                    <input type="text" name="new_vertical" maxlength="150" placeholder="Add Vertical Name..." required style="min-width:0;flex:1;padding:10px;border:1px solid #475569;background:#0b1220;color:#f8fafc;border-radius:6px;">
                    <button type="submit" class="btn" style="background:#10b981;color:white;padding:10px 18px;border-radius:6px;font-weight:bold;border:0;cursor:pointer;">Add Vertical</button>
                </form>
            </div>
            <div class="excel-container">
                <table class="table criteria-matrix">
                    <thead>
                        <tr>
                            <th class="vertical-heading" style="border-right:2px solid #334155;text-align:center;padding:12px;color:#f8fafc;width:180px;min-width:180px;">Verticals</th>
                            <?php foreach ($clients as $client): ?>
                                <th style="text-align:center;border-right:1px solid #334155;padding:12px;color:#38bdf8;width:280px;min-width:280px;"><?= htmlspecialchars((string)$client['client_code'], ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$verticals): ?>
                            <tr><td colspan="<?= max(1, count($clients) + 1) ?>" style="text-align:center;padding:40px;color:#94a3b8;">No visible verticals. Add or show a vertical to begin.</td></tr>
                        <?php else: foreach ($verticals as $vertical): ?>
                            <tr>
                                <td class="vertical-heading" style="border-right:2px solid #334155;font-weight:bold;text-align:center;padding:12px;color:#f8fafc;"><?= htmlspecialchars((string)$vertical['vertical_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <?php foreach ($clients as $client):
                                    $client_id = (int)$client['id'];
                                    $vertical_id = (int)$vertical['id'];
                                    $cell = $criteria_map[$client_id][$vertical_id] ?? [];
                                    $active = !empty($cell['is_active']);
                                ?>
                                    <td class="criteria-cell" data-client-id="<?= $client_id ?>" data-vertical-id="<?= $vertical_id ?>" style="padding:10px;border-right:1px solid #334155;background:<?= $active ? '#0f291e' : '#0b1220' ?>;vertical-align:top;">
                                        <div style="display:flex;flex-direction:column;gap:7px;width:260px;">
                                            <label style="font-size:11px;font-weight:bold;color:<?= $active ? '#34d399' : '#94a3b8' ?>;cursor:pointer;">
                                                <input type="checkbox" onchange="saveMatrixCell(<?= $client_id ?>,<?= $vertical_id ?>,'is_active',this.checked ? '1' : '0',this)" <?= $active ? 'checked' : '' ?> style="accent-color:#10b981;"> Active
                                            </label>
                                            <textarea class="criteria-textarea" oninput="autoResizeTextarea(this)" onblur="saveMatrixCell(<?= $client_id ?>,<?= $vertical_id ?>,'criteria_text',this.value,this)" placeholder="Enter all criteria for this client and vertical..."><?= htmlspecialchars((string)($cell['criteria_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="criteria-panel" id="templates-panel" role="tabpanel" aria-labelledby="templates-tab">
        <?php
        $_GET['embedded'] = '1';
        if (is_file($criteria_module_dir . '/manage_templates.php')) {
            if (!defined('MANAGE_TEMPLATES_NATIVE_INCLUDE')) define('MANAGE_TEMPLATES_NATIVE_INCLUDE', true);
            include $criteria_module_dir . '/manage_templates.php';
        } else {
            echo "<p style='color:#94a3b8;padding:20px;'>Application Templates manager is not available.</p>";
        }
        ?>
    </section>
</main>

<div id="matrixSettingsModal" class="criteria-settings-modal" role="dialog" aria-modal="true" aria-labelledby="criteria-settings-title">
    <div class="criteria-settings-content">
        <div style="background:#0A2540;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="criteria-settings-title" style="margin:0;color:white;"><i class="fas fa-sliders-h"></i> Manage Clients &amp; Verticals</h3>
            <button type="button" onclick="document.getElementById('matrixSettingsModal').style.display='none'" aria-label="Close settings" style="background:none;border:0;color:white;font-size:24px;cursor:pointer;">&times;</button>
        </div>
        <div class="criteria-settings-lists">
            <div class="criteria-settings-column">
                <h4 style="color:#38bdf8;margin-top:0;">Clients</h4>
                <div id="settings-client-list"></div>
            </div>
            <div class="criteria-settings-column">
                <h4 style="color:#10b981;margin-top:0;">Verticals</h4>
                <div id="settings-vertical-list"></div>
            </div>
        </div>
    </div>
</div>

<script>
const criteriaCsrfToken = <?= json_encode($criteria_csrf_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const criteriaSettingsItems = <?= json_encode(['client' => $settings_clients, 'vertical' => $settings_verticals], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
const criteriaTabs = document.querySelectorAll('.criteria-tab');

function setCriteriaTab(tab) {
    criteriaTabs.forEach(item => {
        const selected = item === tab;
        item.setAttribute('aria-selected', selected ? 'true' : 'false');
        document.getElementById(item.getAttribute('aria-controls')).classList.toggle('active', selected);
    });
    localStorage.setItem('activeCriteriaTab', tab.id);
}
criteriaTabs.forEach(tab => tab.addEventListener('click', () => setCriteriaTab(tab)));
const storedCriteriaTab = document.getElementById(localStorage.getItem('activeCriteriaTab') || 'criteria-tab');
if (storedCriteriaTab) setCriteriaTab(storedCriteriaTab);

function showCriteriaStatus(message, isError = false) {
    const status = document.getElementById('matrix-save-status');
    status.textContent = message;
    status.style.color = isError ? '#ef4444' : '#10b981';
}

async function criteriaPost(formData) {
    const response = await fetch('criteria.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    const data = await response.json();
    if (!response.ok || data.status !== 'success') throw new Error(data.message || 'The update could not be saved.');
    return data;
}

async function saveMatrixCell(clientId, verticalId, fieldName, value, element) {
    showCriteriaStatus('Saving...', false);
    const formData = new FormData();
    formData.append('action', 'save_cell');
    formData.append('client_id', String(clientId));
    formData.append('vertical_id', String(verticalId));
    formData.append('field', fieldName);
    formData.append('value', value);
    formData.append('csrf_token', criteriaCsrfToken);
    try {
        await criteriaPost(formData);
        if (fieldName === 'is_active' && element) {
            const cell = element.closest('.criteria-cell');
            cell.style.background = element.checked ? '#0f291e' : '#0b1220';
            element.closest('label').style.color = element.checked ? '#34d399' : '#94a3b8';
        }
        showCriteriaStatus('Saved', false);
    } catch (error) {
        showCriteriaStatus(error.message || 'Error saving criteria.', true);
    }
}

function autoResizeTextarea(element) {
    element.style.height = 'auto';
    element.style.height = (element.scrollHeight + 4) + 'px';
}

document.addEventListener('input', event => {
    if (event.target.classList.contains('criteria-textarea')) {
        autoResizeTextarea(event.target);
    }
});
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.criteria-textarea').forEach(autoResizeTextarea);
});

function renderCriteriaSettings() {
    for (const type of ['client', 'vertical']) {
        const container = document.getElementById(`settings-${type}-list`);
        container.replaceChildren();
        criteriaSettingsItems[type].forEach((item, index) => {
            const row = document.createElement('div');
            row.className = `criteria-settings-item${Number(item.is_hidden) ? ' is-hidden' : ''}`;
            const label = document.createElement('span');
            label.textContent = item.name;
            const controls = document.createElement('div');
            controls.className = 'criteria-settings-actions';
            const addControl = (title, text, operation) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.title = title;
                button.textContent = text;
                button.addEventListener('click', () => manageCriteriaItem(type, item, operation, index));
                controls.appendChild(button);
            };
            addControl('Rename', 'Rename', 'rename');
            addControl('Move Up', '↑', 'move_up');
            addControl('Move Down', '↓', 'move_down');
            addControl(Number(item.is_hidden) ? 'Show' : 'Hide', Number(item.is_hidden) ? 'Show' : 'Hide', 'toggle_visibility');
            addControl('Delete', 'Delete', 'delete');
            row.append(label, controls);
            container.appendChild(row);
        });
    }
}

async function manageCriteriaItem(type, item, operation, index) {
    const labels = { client: 'client code', vertical: 'vertical name' };
    let name = '';
    if (operation === 'rename') {
        name = window.prompt(`Rename ${labels[type]}:`, item.name);
        if (name === null || name.trim() === '' || name.trim() === item.name) return;
        name = name.trim();
    }
    if (operation === 'delete' && !window.confirm(`Delete "${item.name}" and all of its criteria cells?`)) return;
    if ((operation === 'move_up' && index === 0) || (operation === 'move_down' && index === criteriaSettingsItems[type].length - 1)) return;

    const formData = new FormData();
    formData.append('action', 'manage_item');
    formData.append('type', type);
    formData.append('id', String(item.id));
    formData.append('operation', operation);
    formData.append('name', name);
    formData.append('csrf_token', criteriaCsrfToken);
    try {
        await criteriaPost(formData);
        window.location.reload();
    } catch (error) {
        window.alert(error.message || 'Unable to update this item.');
    }
}

function openMatrixSettings() {
    renderCriteriaSettings();
    document.getElementById('matrixSettingsModal').style.display = 'flex';
}
</script>

<?php include $criteria_module_dir . '/includes/footer.php'; ?>
