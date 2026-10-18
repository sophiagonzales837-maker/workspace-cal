<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

$native_include = defined('INHOUSE_CRITERIA_NATIVE_INCLUDE');
$is_embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS inhouse_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    field_name VARCHAR(255) NOT NULL,
    field_value LONGTEXT,
    field_type VARCHAR(100),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_field_name (field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ========================================================
// 1. AJAX CELL AUTOSAVE (Active Status & Rule Fields)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cell_field') {
    header('Content-Type: application/json');
    $fieldName = trim($_POST['field_name'] ?? '');
    $fieldValue = trim($_POST['field_value'] ?? '');

    if ($fieldName !== '') {
        $check = $conn->prepare("SELECT id FROM inhouse_criteria WHERE field_name = ? ORDER BY id DESC LIMIT 1");
        $check->bind_param("s", $fieldName);
        $check->execute();
        $res = $check->get_result();

        if ($res && $res->num_rows > 0) {
            $rowId = (int)$res->fetch_assoc()['id'];
            $u_stmt = $conn->prepare("UPDATE inhouse_criteria SET field_value = ?, updated_at = NOW() WHERE id = ?");
            $u_stmt->bind_param("si", $fieldValue, $rowId);
            $u_stmt->execute();
            $u_stmt->close();
        } else {
            $i_stmt = $conn->prepare("INSERT INTO inhouse_criteria (user_id, field_name, field_value, field_type) VALUES (1, ?, ?, 'criteria_cell')");
            $i_stmt->bind_param("ss", $fieldName, $fieldValue);
            $i_stmt->execute();
            $i_stmt->close();
        }
        $check->close();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Empty field name']);
    }
    exit;
}

// ========================================================
// 2. LOAD LATEST CONFIGURATION (BY ID DESC)
// ========================================================
$config_defaults = ['client_order' => [], 'client_hidden' => [], 'vertical_order' => [], 'vertical_hidden' => []];
$configs = $config_defaults;

$config_q = $conn->query("SELECT id, field_value FROM inhouse_criteria WHERE field_name = 'matrix_config' ORDER BY id DESC LIMIT 1");
if ($config_q && $config_q->num_rows > 0) {
    $row = $config_q->fetch_assoc();
    $decoded = json_decode((string)$row['field_value'], true);
    if (is_array($decoded)) $configs = array_merge($configs, $decoded);
}

// Clean arrays
foreach ($config_defaults as $key => $_default) {
    if (!isset($configs[$key]) || !is_array($configs[$key])) $configs[$key] = [];
    $configs[$key] = array_values(array_unique(array_filter(array_map(
        static fn($v) => trim((string)$v),
        $configs[$key]
    ), static fn($v) => $v !== '')));
}

// ========================================================
// 3. HANDLE FORM SUBMISSIONS (Add Client, Add Vertical, Save Settings)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modified = false;

    // Add Client (button or Enter key)
    if (!empty($_POST['new_client'])) {
        $new_client = trim((string)$_POST['new_client']);
        if ($new_client !== '' && !in_array($new_client, $configs['client_order'], true) && !in_array($new_client, $configs['client_hidden'], true)) {
            $configs['client_order'][] = $new_client;
            $modified = true;
        }
    }

    // Add Vertical (button or Enter key)
    if (!empty($_POST['new_vertical'])) {
        $new_vertical = trim((string)$_POST['new_vertical']);
        if ($new_vertical !== '' && !in_array($new_vertical, $configs['vertical_order'], true) && !in_array($new_vertical, $configs['vertical_hidden'], true)) {
            $configs['vertical_order'][] = $new_vertical;
            $modified = true;
        }
    }

    // Save Matrix Config from Settings Modal
    if (isset($_POST['save_matrix_config']) && !empty($_POST['config_payload'])) {
        $payload = json_decode((string)$_POST['config_payload'], true);
        if (is_array($payload)) {
            foreach ($config_defaults as $key => $_default) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    $configs[$key] = array_values(array_unique(array_filter(array_map(
                        static fn($v) => trim((string)$v),
                        $payload[$key]
                    ), static fn($v) => $v !== '')));
                }
            }
            $modified = true;
        }
    }

    if ($modified) {
        $json_value = json_encode($configs, JSON_INVALID_UTF8_SUBSTITUTE);

        // Robust update: find latest row, update it, and clean duplicates
        $check_existing = $conn->query("SELECT id FROM inhouse_criteria WHERE field_name = 'matrix_config' ORDER BY id DESC LIMIT 1");
        if ($check_existing && $check_existing->num_rows > 0) {
            $target_id = (int)$check_existing->fetch_assoc()['id'];
            $u_stmt = $conn->prepare("UPDATE inhouse_criteria SET field_value = ?, updated_at = NOW() WHERE id = ?");
            $u_stmt->bind_param("si", $json_value, $target_id);
            $u_stmt->execute();
            $u_stmt->close();
            $conn->query("DELETE FROM inhouse_criteria WHERE field_name = 'matrix_config' AND id != $target_id");
        } else {
            $i_stmt = $conn->prepare("INSERT INTO inhouse_criteria (user_id, field_name, field_value, field_type) VALUES (1, 'matrix_config', ?, 'matrix_config')");
            $i_stmt->bind_param("s", $json_value);
            $i_stmt->execute();
            $i_stmt->close();
        }
    }

    $redirect_url = $native_include ? 'criteria.php#criteria-panel' : ($is_embedded ? 'inhouse_criteria.php?embedded=1' : 'inhouse_criteria.php');
    header("Location: $redirect_url");
    exit();
}

// Fetch active statuses and fields for rendering
$internal_status = [];
$res2 = $conn->query("SELECT field_name, field_value FROM inhouse_criteria WHERE field_name LIKE 'internal_active:%'");
while ($row = mysqli_fetch_assoc($res2)) $internal_status[$row['field_name']] = $row['field_value'];

$internal_fields = [];
$res3 = $conn->query("SELECT field_name, field_value FROM inhouse_criteria WHERE field_name LIKE 'internal_field:%'");
while ($row = mysqli_fetch_assoc($res3)) $internal_fields[$row['field_name']] = $row['field_value'];

$clients = $configs['client_order'] ?? [];
$verticals = $configs['vertical_order'] ?? [];

if (!$native_include && !$is_embedded) {
    include 'includes/header.php';
} elseif ($is_embedded && !$native_include) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><link rel="stylesheet" href="../assets/style.css"><link rel="stylesheet" href="[https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css](https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css)"><style>body{background:#0f172a;padding:20px;font-family:sans-serif;}</style></head><body class="app-dark-theme">';
}
?>

<div class="card" style="padding: 30px; max-width: 100%; margin: 0 auto; background: #0f172a; border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.4);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="color: #f8fafc; margin: 0;">Criteria Matrix Builder</h2>
        <div style="display: flex; align-items: center; gap: 15px;">
            <span id="matrix-save-status" style="font-size: 12px; color: #10b981; font-weight: bold; opacity: 0; transition: opacity 0.3s;">Saved</span>
            <button type="button" onclick="openMatrixSettings()" class="btn" style="background: rgba(255,255,255,0.1); border: 1px solid #475569; color: #f8fafc; padding: 8px 15px; border-radius: 6px; cursor: pointer; transition: 0.2s;"><i class="fas fa-cog"></i> Settings</button>
        </div>
    </div>

    <!-- Quick Add Forms -->
    <div style="display: flex; gap: 20px; margin-bottom: 30px; background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155;">
        <form method="POST" style="flex: 1; display: flex; gap: 10px; margin: 0;">
            <input type="hidden" name="add_client" value="1">
            <input type="text" name="new_client" class="input-style" placeholder="Add Client Code (e.g. C1)..." required style="padding: 10px; border: 1px solid #475569; background: #0b1220; color: #f8fafc; border-radius: 6px; flex: 1; outline: none;">
            <button type="submit" class="btn" style="background: #38bdf8; color: #0b1220; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: none; cursor: pointer;">Add Client</button>
        </form>
        <form method="POST" style="flex: 1; display: flex; gap: 10px; margin: 0;">
            <input type="hidden" name="add_vertical" value="1">
            <input type="text" name="new_vertical" class="input-style" placeholder="Add Vertical Name..." required style="padding: 10px; border: 1px solid #475569; background: #0b1220; color: #f8fafc; border-radius: 6px; flex: 1; outline: none;">
            <button type="submit" class="btn" style="background: #10b981; color: white; padding: 10px 20px; border-radius: 6px; font-weight: bold; border: none; cursor: pointer;">Add Vertical</button>
        </form>
    </div>

    <!-- Interactive Grid -->
    <div style="overflow-x: auto; border: 1px solid #334155; border-radius: 8px; max-height: 65vh;">
        <table class="table" style="width: 100%; white-space: nowrap; margin: 0; border-collapse: collapse;">
            <thead style="background: #0f172a; position: sticky; top: 0; z-index: 10;">
                <tr>
                    <th style="position: sticky; left: 0; background: #0f172a; z-index: 20; border-right: 2px solid #334155; text-align: center; padding: 12px; color: #f8fafc; width: 180px; min-width: 180px;">Verticals</th>
                    <?php if (!empty($clients)): ?>
                        <?php foreach ($clients as $client): ?>
                            <th style="text-align: center; border-right: 1px solid #334155; padding: 12px; color: #38bdf8; width: 280px; min-width: 280px;"><?= htmlspecialchars($client, ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <th style="text-align: center; padding: 12px; color: #94a3b8;">No Clients Added</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($verticals)): ?>
                    <?php foreach ($verticals as $vertical): ?>
                        <tr>
                            <td style="position: sticky; left: 0; background: #1e293b; z-index: 5; border-right: 2px solid #334155; font-weight: bold; text-align: center; padding: 12px; color: #f8fafc;">
                                <?= htmlspecialchars($vertical, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <?php foreach ($clients as $client): $activeKey = "internal_active:" . trim($client) . ":" . trim($vertical);
                                $isActive = !empty($internal_status[$activeKey]);
                                $badKey = "internal_field:" . trim($client) . ":" . trim($vertical) . ":bad_states";
                                $ageKey = "internal_field:" . trim($client) . ":" . trim($vertical) . ":age_criteria";
                                $qKey = "internal_field:" . trim($client) . ":" . trim($vertical) . ":qualifying_questions";
                                $otherKey = "internal_field:" . trim($client) . ":" . trim($vertical) . ":other_criteria";
                            ?>
                                <td style="padding: 10px; border-right: 1px solid #334155; background: <?= $isActive ? '#0f291e' : '#0b1220' ?>; vertical-align: top;">
                                    <div style="display: flex; flex-direction: column; gap: 6px; width: 260px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <label style="font-size: 11px; font-weight: bold; color: <?= $isActive ? '#34d399' : '#94a3b8' ?>; cursor: pointer;">
                                                <input type="checkbox" onchange="saveMatrixCellField('<?= addslashes($activeKey) ?>', this.checked ? '1' : '0', this)" <?= $isActive ? 'checked' : '' ?> style="accent-color: #10b981;"> Active
                                            </label>
                                        </div>
                                        <textarea onblur="saveMatrixCellField('<?= addslashes($badKey) ?>', this.value)" class="input-style" style="width: 100%; height: 45px; resize: vertical; border: 1px solid #334155; background: #0f172a; color: #f8fafc; border-radius: 4px; padding: 6px; font-size: 11px; box-sizing: border-box;" placeholder="Bad States..."><?= htmlspecialchars($internal_fields[$badKey] ?? '') ?></textarea>
                                        <textarea onblur="saveMatrixCellField('<?= addslashes($ageKey) ?>', this.value)" class="input-style" style="width: 100%; height: 45px; resize: vertical; border: 1px solid #334155; background: #0f172a; color: #f8fafc; border-radius: 4px; padding: 6px; font-size: 11px; box-sizing: border-box;" placeholder="Age / Conditions..."><?= htmlspecialchars($internal_fields[$ageKey] ?? '') ?></textarea>
                                        <textarea onblur="saveMatrixCellField('<?= addslashes($qKey) ?>', this.value)" class="input-style" style="width: 100%; height: 45px; resize: vertical; border: 1px solid #334155; background: #0f172a; color: #f8fafc; border-radius: 4px; padding: 6px; font-size: 11px; box-sizing: border-box;" placeholder="Questions..."><?= htmlspecialchars($internal_fields[$qKey] ?? '') ?></textarea>
                                        <textarea onblur="saveMatrixCellField('<?= addslashes($otherKey) ?>', this.value)" class="input-style" style="width: 100%; height: 45px; resize: vertical; border: 1px solid #334155; background: #0f172a; color: #f8fafc; border-radius: 4px; padding: 6px; font-size: 11px; box-sizing: border-box;" placeholder="Other constraints..."><?= htmlspecialchars($internal_fields[$otherKey] ?? '') ?></textarea>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="100%" style="text-align:center; padding: 40px; color:#94a3b8; font-style: italic;">No verticals added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Settings Modal (Rename, Delete, Reorder, Hide/Show) -->
<div id="matrixSettingsModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: #0f172a; border: 1px solid #38bdf8; border-radius: 12px; width: 750px; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="background: #0A2540; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: white;"><i class="fas fa-sliders-h"></i> Matrix Sequence &amp; Visibility</h3>
            <button type="button" onclick="document.getElementById('matrixSettingsModal').style.display='none'" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <div style="padding: 20px; display: flex; gap: 20px; overflow-y: auto; flex: 1;">
            <div style="flex: 1; background: #1e293b; padding: 15px; border-radius: 8px;">
                <h4 style="color: #38bdf8; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 8px;">Clients</h4>
                <div id="settings-client-list" style="display: flex; flex-direction: column; gap: 8px;"></div>
            </div>
            <div style="flex: 1; background: #1e293b; padding: 15px; border-radius: 8px;">
                <h4 style="color: #10b981; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 8px;">Verticals</h4>
                <div id="settings-vertical-list" style="display: flex; flex-direction: column; gap: 8px;"></div>
            </div>
        </div>
        <div style="padding: 15px 20px; background: #0A2540; text-align: right;">
            <form method="POST" id="configSaveForm" style="margin: 0;">
                <input type="hidden" name="config_payload" id="config_payload">
                <input type="hidden" name="save_matrix_config" value="1">
                <button type="button" onclick="saveMatrixSettings()" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer;">Save Settings</button>
            </form>
        </div>
    </div>
</div>

<script>
    let sysConfig = <?= json_encode($configs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    function renderSettingsLists() {
        const buildItem = (name, type, isHidden, index) => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; justify-content:space-between; align-items:center; background:#0b1220; padding:8px 12px; border-radius:4px; border:1px solid #334155;';
            const label = document.createElement('span');
            label.style.cssText = `color:${isHidden ? '#64748b' : '#f8fafc'}; text-decoration:${isHidden ? 'line-through' : 'none'}; font-size:13px; font-weight:600;`;
            label.textContent = name;
            row.appendChild(label);

            const controls = document.createElement('div');
            controls.style.cssText = 'display:flex; gap:4px;';
            const addBtn = (title, icon, action, bg) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.title = title;
                btn.style.cssText = `background:${bg}; border:none; color:white; border-radius:3px; cursor:pointer; padding:4px 7px; font-size:11px;`;
                btn.innerHTML = `<i class="fas ${icon}"></i>`;
                btn.addEventListener('click', action);
                controls.appendChild(btn);
            };

            addBtn('Rename', 'fa-pen', () => renameItem(type, isHidden, index), '#f59e0b');
            addBtn('Delete', 'fa-trash', () => deleteItem(type, isHidden, index), '#ef4444');
            addBtn('Move Up', 'fa-arrow-up', () => moveItem(type, isHidden, index, -1), '#334155');
            addBtn('Move Down', 'fa-arrow-down', () => moveItem(type, isHidden, index, 1), '#334155');
            addBtn(isHidden ? 'Show' : 'Hide', isHidden ? 'fa-eye-slash' : 'fa-eye', () => toggleVisibility(type, isHidden, index), isHidden ? '#334155' : '#0ea5e9');

            row.appendChild(controls);
            return row;
        };

        const clientList = document.getElementById('settings-client-list');
        const verticalList = document.getElementById('settings-vertical-list');
        clientList.replaceChildren();
        verticalList.replaceChildren();

        sysConfig.client_order.forEach((client, idx) => clientList.appendChild(buildItem(client, 'client', false, idx)));
        sysConfig.client_hidden.forEach((client, idx) => clientList.appendChild(buildItem(client, 'client', true, idx)));
        sysConfig.vertical_order.forEach((vert, idx) => verticalList.appendChild(buildItem(vert, 'vertical', false, idx)));
        sysConfig.vertical_hidden.forEach((vert, idx) => verticalList.appendChild(buildItem(vert, 'vertical', true, idx)));
    }

    function renameItem(type, isHidden, index) {
        const key = type + (isHidden ? '_hidden' : '_order');
        const oldName = sysConfig[key][index];
        const newName = prompt(`Rename ${type}:`, oldName);
        if (newName && newName.trim() !== '' && newName.trim() !== oldName) {
            sysConfig[key][index] = newName.trim();
            renderSettingsLists();
        }
    }

    function deleteItem(type, isHidden, index) {
        const key = type + (isHidden ? '_hidden' : '_order');
        const name = sysConfig[key][index];
        if (!confirm(`Are you sure you want to permanently delete '${name}'?`)) return;
        sysConfig[key].splice(index, 1);
        renderSettingsLists();
    }

    function moveItem(type, isHidden, index, direction) {
        const key = type + (isHidden ? '_hidden' : '_order');
        const items = sysConfig[key];
        const target = index + direction;
        if (target < 0 || target >= items.length) return;
        [items[index], items[target]] = [items[target], items[index]];
        renderSettingsLists();
    }

    function toggleVisibility(type, isHidden, index) {
        const sourceKey = type + (isHidden ? '_hidden' : '_order');
        const targetKey = type + (isHidden ? '_order' : '_hidden');
        const [item] = sysConfig[sourceKey].splice(index, 1);
        sysConfig[targetKey].push(item);
        renderSettingsLists();
    }

    function openMatrixSettings() {
        renderSettingsLists();
        document.getElementById('matrixSettingsModal').style.display = 'flex';
    }

    function saveMatrixSettings() {
        document.getElementById('config_payload').value = JSON.stringify(sysConfig);
        document.getElementById('configSaveForm').submit();
    }

    function saveMatrixCellField(fieldName, fieldValue, inputEl) {
        const statusEl = document.getElementById('matrix-save-status');
        if (statusEl) {
            statusEl.innerText = 'Saving...';
            statusEl.style.color = '#38bdf8';
            statusEl.style.opacity = '1';
        }

        const fd = new FormData();
        fd.append('action', 'save_cell_field');
        fd.append('field_name', fieldName);
        fd.append('field_value', fieldValue);

        fetch(window.location.href, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (statusEl) {
                    statusEl.innerText = 'Saved';
                    statusEl.style.color = '#10b981';
                    setTimeout(() => {
                        statusEl.style.opacity = '0';
                    }, 1500);
                }
                if (inputEl && inputEl.type === 'checkbox') {
                    const td = inputEl.closest('td');
                    if (td) td.style.background = inputEl.checked ? '#0f291e' : '#0b1220';
                }
            })
            .catch(() => {
                if (statusEl) {
                    statusEl.innerText = 'Error saving';
                    statusEl.style.color = '#ef4444';
                }
            });
    }
</script>

<?php
if (!$native_include && !$is_embedded) {
    include 'includes/footer.php';
} elseif ($is_embedded && !$native_include) {
    echo '</body></html>';
}
?>