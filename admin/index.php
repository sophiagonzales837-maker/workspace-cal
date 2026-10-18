<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../shared/smart_sync.php';
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

if (empty($_SESSION['verification_queue_csrf'])) {
    $_SESSION['verification_queue_csrf'] = bin2hex(random_bytes(32));
}
$verification_queue_csrf = (string)$_SESSION['verification_queue_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_verifier') {
    header('Content-Type: application/json; charset=utf-8');
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $verifier_id_input = trim((string)($_POST['verifier_id'] ?? ''));
    $csrf_token = (string)($_POST['csrf_token'] ?? '');

    if ($lead_id <= 0 || ($verifier_id_input !== '' && !ctype_digit($verifier_id_input)) || !hash_equals($verification_queue_csrf, $csrf_token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid verifier assignment request.']);
        exit;
    }

    $verifier_id = $verifier_id_input === '' ? null : (int)$verifier_id_input;
    $verifier_name = '';
    if ($verifier_id !== null) {
        $verifier_stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'Verifier' LIMIT 1");
        if (!$verifier_stmt) {
            error_log('[Verification queue] Verifier lookup prepare failed: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update the verifier assignment.']);
            exit;
        }
        $verifier_stmt->bind_param('i', $verifier_id);
        if (!$verifier_stmt->execute()) {
            error_log('[Verification queue] Verifier lookup failed: ' . $verifier_stmt->error);
            $verifier_stmt->close();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Unable to update the verifier assignment.']);
            exit;
        }
        $verifier = $verifier_stmt->get_result()->fetch_assoc();
        $verifier_stmt->close();
        if (!$verifier) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Choose a valid Verifier.']);
            exit;
        }
        $verifier_name = (string)$verifier['username'];
    }

    $assign_stmt = $conn->prepare("UPDATE leads SET verifier_id = ?, verifier_name = ?, updated_at = NOW() WHERE id = ? AND status = 'Pending Verification'");
    if (!$assign_stmt) {
        error_log('[Verification queue] Assignment prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update the verifier assignment.']);
        exit;
    }
    $assign_stmt->bind_param('isi', $verifier_id, $verifier_name, $lead_id);
    if (!$assign_stmt->execute()) {
        error_log('[Verification queue] Assignment update failed: ' . $assign_stmt->error);
        $assign_stmt->close();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update the verifier assignment.']);
        exit;
    }
    $updated = $assign_stmt->affected_rows === 1;
    $assign_stmt->close();
    if (!$updated) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This lead is no longer pending verification. Refresh the page and try again.']);
        exit;
    }

    $sync_success = workspace_send_lead_sync_update($lead_id, [
        'verifier_id' => $verifier_id,
        'verifier_name' => $verifier_name
    ]);
    echo json_encode([
        'success' => true,
        'sync_success' => $sync_success,
        'verifier_name' => $verifier_name !== '' ? $verifier_name : 'Unassigned',
        'message' => $sync_success ? 'Verifier assignment updated.' : 'Verifier assignment saved locally, but live sync failed.'
    ]);
    exit;
}

$verifier_result = $conn->query("SELECT id, username FROM users WHERE role = 'Verifier' ORDER BY username ASC");
if (!$verifier_result) {
    error_log('[Verification queue] Verifier list query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load Verifiers.');
}
$verifiers = [];
while ($verifier = $verifier_result->fetch_assoc()) {
    $verifiers[] = $verifier;
}

$lead_result = $conn->query(
    "SELECT l.*, uc.username AS assigned_closer_username
     FROM leads l
     LEFT JOIN users uc ON uc.id = l.closer_id
     WHERE l.status = 'Pending Verification'
     ORDER BY l.updated_at DESC"
);
if (!$lead_result) {
    error_log('[Verification queue] Pending leads query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load pending verifications.');
}

include 'includes/header.php';
?>
<style>
    .verification-page { max-width: 1600px; margin: 0 auto; }
    .verification-heading { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin: 0 0 18px; }
    .verification-heading h1 { margin: 0; color: #f8fafc; font-size: 25px; }
    .verification-heading p { margin: 5px 0 0; color: #94a3b8; font-size: 13px; }
    .verification-count { color: #cbd5e1; font-weight: 700; white-space: nowrap; }
    .verification-search { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; max-width: 460px; padding: 9px 12px; background: #0f172a; border: 1px solid #334155; border-radius: 7px; color: #94a3b8; }
    .verification-search input { width: 100%; border: 0; outline: 0; background: transparent; color: #f8fafc; }
    .excel-container { max-height: 70vh; overflow: auto; border: 1px solid #334155; border-radius: 8px; background: #0f172a; }
    .excel-table { width: 100%; min-width: 1400px; border-collapse: separate; border-spacing: 0; color: #f8fafc; font-size: 13px; }
    .excel-table th { position: sticky; top: 0; z-index: 2; padding: 11px 12px; background: #1e293b; color: #f8fafc; text-align: left; white-space: nowrap; }
    .excel-table th[data-filter="true"] { position: sticky; padding-right: 30px; }
    .excel-table td { padding: 9px 12px; border-top: 1px solid #334155; vertical-align: top; }
    .excel-table a { color: #7dd3fc; }
    .excel-table tbody tr:hover { background: rgba(51, 65, 85, .35); }
    .verifier-select { min-width: 180px; padding: 7px 8px; border: 1px solid #475569; border-radius: 5px; background: #0b1220; color: #f8fafc; }
    .assignment-indicator { position: fixed; right: 20px; bottom: 20px; z-index: 1000; padding: 11px 16px; border: 1px solid #334155; border-radius: 7px; background: #0f172a; color: #f8fafc; opacity: 0; transform: translateY(8px); transition: .2s; pointer-events: none; }
    .assignment-indicator.show { opacity: 1; transform: translateY(0); }
    .filter-icon { position: absolute; right: 9px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: #94a3b8; cursor: pointer; }
    .filter-icon.active { color: #38bdf8; }
    .filter-menu { display: none; position: absolute; top: calc(100% - 1px); left: 0; z-index: 20; width: 220px; max-height: 280px; padding: 10px; overflow: hidden; border: 1px solid #475569; border-radius: 7px; background: #0b1220; box-shadow: 0 12px 24px rgba(0,0,0,.4); color: #e2e8f0; font-weight: 400; text-transform: none; white-space: normal; }
    .filter-menu.show { display: block; }
    .filter-search { box-sizing: border-box; width: 100%; margin-bottom: 8px; padding: 6px; border: 1px solid #475569; border-radius: 4px; background: #0f172a; color: #f8fafc; }
    .filter-options-container { display: flex; max-height: 165px; flex-direction: column; gap: 3px; overflow-y: auto; }
    .filter-option { display: flex; align-items: center; gap: 7px; padding: 4px; cursor: pointer; }
    .filter-actions { display: flex; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px solid #334155; }
    .filter-btn { padding: 4px 8px; border: 0; background: transparent; color: #38bdf8; font-weight: 700; cursor: pointer; }
</style>

<main class="verification-page">
    <div class="verification-heading">
        <div>
            <h1>Pending Verification</h1>
            <p>Review leads and assign or reassign a Verifier.</p>
        </div>
        <span class="verification-count">Leads: <span id="visible-lead-count"><?= $lead_result->num_rows ?></span> / <?= $lead_result->num_rows ?></span>
    </div>
    <label class="verification-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="global-search-input" placeholder="Search pending verifications..." aria-label="Search pending verifications">
    </label>
    <div class="excel-container" id="table-container">
        <table class="excel-table" id="pending-verifications-table">
            <thead>
                <tr>
                    <th data-filter="true">Customer Name</th>
                    <th data-filter="true">Phone</th>
                    <th data-filter="true">Vertical</th>
                    <th data-filter="true">Assigned Closer</th>
                    <th data-filter="true">Jornaya</th>
                    <th data-filter="true">Trusted Form</th>
                    <th data-filter="true">IP Address</th>
                    <th data-filter="true">Status</th>
                    <th data-filter="true">Assigned Verifier</th>
                    <th data-filter="true">Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($lead_result->num_rows === 0): ?>
                    <tr class="empty-row"><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">No leads are pending verification.</td></tr>
                <?php else: ?>
                    <?php while ($raw_lead = $lead_result->fetch_assoc()):
                        $lead = decrypt_fields($raw_lead);
                        $customer_name = trim((string)($lead['customer_name'] ?? ''));
                        if ($customer_name === '') {
                            $customer_name = trim((string)($lead['first_name'] ?? '') . ' ' . (string)($lead['last_name'] ?? ''));
                        }
                        $closer_name = trim((string)($lead['assigned_closer_username'] ?? ''));
                        if ($closer_name === '') {
                            $closer_name = trim((string)($lead['closer_name'] ?? ''));
                        }
                        $updated_value = (string)($lead['updated_at'] ?? '');
                        if ($updated_value === '') {
                            $updated_value = (string)($lead['created_at'] ?? '');
                        }
                        $updated_timestamp = strtotime($updated_value);
                        $trusted_form_url = trim((string)($lead['trustedform_cert_url'] ?? ''));
                        $trusted_form_scheme = strtolower((string)parse_url($trusted_form_url, PHP_URL_SCHEME));
                        $current_verifier_id = (int)($raw_lead['verifier_id'] ?? 0);
                    ?>
                        <tr data-lead-id="<?= (int)$lead['id'] ?>">
                            <td><?= htmlspecialchars($customer_name !== '' ? $customer_name : 'Unknown Customer', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($lead['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($lead['vertical'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($closer_name !== '' ? $closer_name : 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($lead['jornaya_leadid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($trusted_form_url !== '' && in_array($trusted_form_scheme, ['http', 'https'], true)): ?>
                                    <a href="<?= htmlspecialchars($trusted_form_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($trusted_form_url, ENT_QUOTES, 'UTF-8') ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($trusted_form_url, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($lead['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($lead['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <select class="verifier-select" data-lead-id="<?= (int)$lead['id'] ?>" data-csrf-token="<?= htmlspecialchars($verification_queue_csrf, ENT_QUOTES, 'UTF-8') ?>" aria-label="Assigned Verifier">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($verifiers as $verifier): ?>
                                        <option value="<?= (int)$verifier['id'] ?>" <?= $current_verifier_id === (int)$verifier['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$verifier['username'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><?= $updated_timestamp ? date('m/d/y h:ia', $updated_timestamp) : 'N/A' ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<div id="assignment-indicator" class="assignment-indicator" role="status" aria-live="polite"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('pending-verifications-table');
    const searchInput = document.getElementById('global-search-input');
    const visibleCount = document.getElementById('visible-lead-count');
    const indicator = document.getElementById('assignment-indicator');
    const activeFilters = new Map();
    let searchTimer;
    let indicatorTimer;

    function showAssignmentMessage(message, isError = false) {
        indicator.textContent = message;
        indicator.style.borderColor = isError ? '#ef4444' : '#10b981';
        indicator.classList.add('show');
        window.clearTimeout(indicatorTimer);
        indicatorTimer = window.setTimeout(() => indicator.classList.remove('show'), 3500);
    }

    function cellValue(cell) {
        const select = cell.querySelector('select');
        return select ? select.options[select.selectedIndex].text.trim() : cell.textContent.trim().replace(/\s+/g, ' ');
    }

    function applyFilters() {
        const rows = Array.from(table.tBodies[0].rows).filter(row => !row.classList.contains('empty-row'));
        const searchTerm = searchInput.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(row => {
            let matches = Array.from(activeFilters.entries()).every(([column, values]) => values.includes(cellValue(row.cells[column])));
            if (matches && searchTerm) {
                matches = Array.from(row.cells).some(cell => cellValue(cell).toLocaleLowerCase().includes(searchTerm));
            }
            row.hidden = !matches;
            if (matches) visible++;
        });
        visibleCount.textContent = String(visible);
    }

    table.querySelectorAll('thead th[data-filter="true"]').forEach((header, column) => {
        const values = new Set(Array.from(table.tBodies[0].rows)
            .filter(row => !row.classList.contains('empty-row'))
            .map(row => cellValue(row.cells[column]))
            .filter(value => value !== ''));
        const icon = document.createElement('button');
        icon.type = 'button';
        icon.className = 'filter-icon';
        icon.setAttribute('aria-label', `Filter ${header.textContent.trim()}`);
        icon.innerHTML = '<i class="fas fa-filter" aria-hidden="true"></i>';
        const menu = document.createElement('div');
        menu.className = 'filter-menu';
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'filter-search';
        search.placeholder = 'Search options...';
        search.setAttribute('aria-label', 'Search filter options');
        const options = document.createElement('div');
        options.className = 'filter-options-container';
        const actions = document.createElement('div');
        actions.className = 'filter-actions';
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'filter-btn clear-btn';
        clear.textContent = 'Clear';
        const apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'filter-btn apply-btn';
        apply.textContent = 'Apply';

        Array.from(values).sort((a, b) => a.localeCompare(b)).forEach(value => {
            const label = document.createElement('label');
            label.className = 'filter-option';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = value;
            checkbox.checked = activeFilters.has(column) && activeFilters.get(column).includes(value);
            label.append(checkbox, document.createTextNode(value));
            options.appendChild(label);
        });
        actions.append(clear, apply);
        menu.append(search, options, actions);
        header.append(icon, menu);

        icon.addEventListener('click', event => {
            event.stopPropagation();
            document.querySelectorAll('.filter-menu.show').forEach(openMenu => {
                if (openMenu !== menu) {
                    openMenu.classList.remove('show');
                    openMenu.closest('th').style.zIndex = '2';
                }
            });
            const isOpen = menu.classList.toggle('show');
            header.style.zIndex = isOpen ? '30' : '2';
        });
        menu.addEventListener('click', event => event.stopPropagation());
        search.addEventListener('input', () => {
            const term = search.value.toLocaleLowerCase();
            options.querySelectorAll('.filter-option').forEach(label => {
                label.hidden = !label.textContent.toLocaleLowerCase().includes(term);
            });
        });
        apply.addEventListener('click', () => {
            const checked = Array.from(options.querySelectorAll('input:checked'), checkbox => checkbox.value);
            if (checked.length) {
                activeFilters.set(column, checked);
                icon.classList.add('active');
            } else {
                activeFilters.delete(column);
                icon.classList.remove('active');
            }
            applyFilters();
            menu.classList.remove('show');
            header.style.zIndex = '2';
        });
        clear.addEventListener('click', () => {
            options.querySelectorAll('input:checked').forEach(checkbox => { checkbox.checked = false; });
            activeFilters.delete(column);
            icon.classList.remove('active');
            applyFilters();
        });
    });

    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(applyFilters, 250);
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.filter-menu') && !event.target.closest('.filter-icon')) {
            document.querySelectorAll('.filter-menu.show').forEach(menu => {
                menu.classList.remove('show');
                menu.closest('th').style.zIndex = '2';
            });
        }
    });

    table.querySelectorAll('.verifier-select').forEach(select => {
        select.addEventListener('change', async () => {
            const previousValue = select.dataset.currentValue ?? '';
            const body = new FormData();
            body.append('action', 'assign_verifier');
            body.append('lead_id', select.dataset.leadId);
            body.append('verifier_id', select.value);
            body.append('csrf_token', select.dataset.csrfToken);
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update the verifier assignment.');
                select.dataset.currentValue = select.value;
                showAssignmentMessage(result.message, !result.sync_success);
            } catch (error) {
                select.value = previousValue;
                showAssignmentMessage(error.message || 'Unable to update the verifier assignment.', true);
            }
            applyFilters();
        });
    });

    table.querySelectorAll('.verifier-select').forEach(select => {
        select.dataset.currentValue = select.value;
    });
});
</script>

<?php include 'includes/footer.php'; ?>
