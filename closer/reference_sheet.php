<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Closer');

$closer_id = (int)($_SESSION['user_id'] ?? 0);
$_SESSION['reference_sheet_csrf'] = $_SESSION['reference_sheet_csrf'] ?? bin2hex(random_bytes(32));
$csrf_token = $_SESSION['reference_sheet_csrf'];

$conn->query("CREATE TABLE IF NOT EXISTS closer_reference_sheet_rows (
    closer_id INT NOT NULL,
    row_number SMALLINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    notes MEDIUMTEXT NULL,
    saved_at DATETIME NOT NULL,
    PRIMARY KEY (closer_id, row_number),
    UNIQUE KEY unique_reference_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$saved_rows = [];
$stmt = $conn->prepare("SELECT s.row_number, s.notes, l.first_name, l.last_name, l.phone, l.email, l.vertical
    FROM closer_reference_sheet_rows s
    LEFT JOIN leads l ON l.id = s.lead_id
    WHERE s.closer_id = ?
    ORDER BY s.row_number ASC");
if ($stmt) {
    $stmt->bind_param('i', $closer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row_number = (int)$row['row_number'];
        $saved_rows[$row_number] = [
            'first_name' => decryptData($row['first_name'] ?? ''),
            'last_name' => decryptData($row['last_name'] ?? ''),
            'phone' => decryptData($row['phone'] ?? ''),
            'email' => decryptData($row['email'] ?? ''),
            'vertical' => $row['vertical'] ?? '',
            'notes' => decryptData($row['notes'] ?? '')
        ];
    }
    $stmt->close();
}

include 'includes/header.php';
?>
<style>
    .reference-sheet { max-width: 1500px; margin: 8px auto 30px; color: #152b35; }
    .sheet-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0 0 16px; }
    .sheet-toolbar h1 { margin: 0; font-size: 24px; color: #f1f5f6; }
    .sheet-toolbar a { background: #edf4f2; border: 1px solid #bdd1cb; color: #173c38; padding: 9px 13px; border-radius: 5px; text-decoration: none; font-weight: 700; }
    .sheet-status { min-height: 21px; margin: 0 0 10px; color: #d8e7e3; font-size: 13px; }
    .sheet-scroll { max-height: calc(100vh - 245px); overflow: auto; border: 1px solid #aabbb7; background: #fff; }
    .reference-grid { width: 100%; min-width: 920px; table-layout: fixed; border-collapse: collapse; background: #fff; }
    .reference-grid th { position: sticky; top: 0; z-index: 2; padding: 10px 9px; text-align: left; color: #173c38; background: #dce9e5; border: 1px solid #aabbb7; font-size: 13px; }
    .reference-grid td { height: 36px; padding: 0; border: 1px solid #cbd5d1; }
    .reference-grid td input { width: 100%; height: 36px; box-sizing: border-box; padding: 7px 9px; border: 0; outline: 0; background: #fff; color: #1b2e35; font: inherit; }
    .reference-grid td input:focus { box-shadow: inset 0 0 0 2px #25877b; position: relative; z-index: 1; }
    .reference-grid tr.sheet-saved td { background: #e6f4e9; }
    .reference-grid tr.sheet-saved input { background: #e6f4e9; color: #40594c; }
    .reference-grid tr.sheet-saved td:first-child { box-shadow: inset 4px 0 #3a9b69; }
    .reference-grid tr.sheet-duplicate td { background: #fff2dc; }
    .reference-grid tr.sheet-duplicate input { background: #fff2dc; }
    @media (max-width: 700px) {
        .sheet-toolbar { align-items: flex-start; flex-direction: column; }
        .sheet-scroll { max-height: calc(100vh - 285px); }
    }
</style>

<section class="reference-sheet">
    <div class="sheet-toolbar">
        <h1>Reference Sheet</h1>
        <a href="add_lead.php">Return to Lead Entry</a>
    </div>
    <p id="sheetStatus" class="sheet-status" role="status" aria-live="polite"></p>
    <div class="sheet-scroll sheet-scroll-wrapper table-container" id="sheetGrid">
        <table class="reference-grid" aria-label="Paste lead reference data">
            <thead>
                <tr>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Vertical</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($row_number = 1; $row_number <= 50; $row_number++):
                    $row_data = $saved_rows[$row_number] ?? [];
                    $is_saved = isset($saved_rows[$row_number]);
                ?>
                <tr data-row="<?= $row_number ?>" class="<?= $is_saved ? 'sheet-saved' : '' ?>">
                    <?php foreach (['first_name', 'last_name', 'phone', 'email', 'vertical', 'notes'] as $field): ?>
                    <td><input type="text" data-field="<?= $field ?>" data-row="<?= $row_number ?>" value="<?= htmlspecialchars($row_data[$field] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $is_saved ? 'readonly' : '' ?> aria-label="Row <?= $row_number ?> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8') ?>"></td>
                    <?php endforeach; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
const referenceGrid = document.getElementById('sheetGrid');
const sheetStatus = document.getElementById('sheetStatus');
const referenceCsrfToken = <?= json_encode($csrf_token) ?>;
const referenceFields = ['first_name', 'last_name', 'phone', 'email', 'vertical', 'notes'];

function getReferenceRow(rowNumber) {
    return referenceGrid.querySelector(`tr[data-row="${rowNumber}"]`);
}

function readReferenceRow(rowNumber) {
    const row = getReferenceRow(rowNumber);
    const values = {};
    row.querySelectorAll('input[data-field]').forEach(input => {
        values[input.dataset.field] = input.value.trim();
    });
    return values;
}

function saveReferenceRows(rows) {
    if (rows.length === 0) return;
    fetch('api_reference_batch.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': referenceCsrfToken
        },
        body: JSON.stringify({ rows })
    })
    .then(response => response.json().then(data => ({ ok: response.ok, data })))
    .then(({ ok, data }) => {
        if (!ok) throw new Error(data.error || 'Could not save pasted rows.');
        let savedCount = 0;
        let duplicateCount = 0;
        data.results.forEach(result => {
            const row = getReferenceRow(result.row_number);
            if (!row) return;
            if (result.status === 'saved' || result.status === 'locked') {
                row.classList.add('sheet-saved');
                row.querySelectorAll('input').forEach(input => { input.readOnly = true; });
                savedCount += result.status === 'saved' ? 1 : 0;
            } else if (result.status === 'duplicate') {
                row.classList.add('sheet-duplicate');
                duplicateCount++;
            }
        });
        const messages = [];
        if (savedCount) messages.push(`${savedCount} row${savedCount === 1 ? '' : 's'} saved`);
        if (duplicateCount) messages.push(`${duplicateCount} duplicate${duplicateCount === 1 ? '' : 's'} skipped`);
        if (messages.length) sheetStatus.textContent = messages.join(' · ');
    })
    .catch(error => {
        sheetStatus.textContent = error.message;
    });
}

referenceGrid.addEventListener('paste', event => {
    const pastedText = event.clipboardData?.getData('text/plain') ?? '';
    if (!pastedText.includes('\t') && !pastedText.includes('\n')) return;
    event.preventDefault();

    const targetInput = event.target.closest('input[data-field]');
    const startRow = targetInput ? Number(targetInput.dataset.row) : 1;
    const startColumn = targetInput ? referenceFields.indexOf(targetInput.dataset.field) : 0;
    const pastedRows = pastedText.replace(/\r/g, '').split('\n');
    while (pastedRows.length && pastedRows[pastedRows.length - 1] === '') pastedRows.pop();

    const rowsToSave = [];
    let destinationRow = startRow;
    pastedRows.forEach(line => {
        if (!line.trim()) return;
        while (destinationRow <= 50 && getReferenceRow(destinationRow).classList.contains('sheet-saved')) destinationRow++;
        if (destinationRow > 50) return;

        const cells = line.split('\t');
        const row = getReferenceRow(destinationRow);
        cells.forEach((value, offset) => {
            const field = referenceFields[startColumn + offset];
            if (!field) return;
            const input = row.querySelector(`input[data-field="${field}"]`);
            if (input && !input.readOnly) input.value = value.trim();
        });

        const values = readReferenceRow(destinationRow);
        if (values.first_name && values.phone) {
            rowsToSave.push({ row_number: destinationRow, ...values });
        }
        destinationRow++;
    });

    saveReferenceRows(rowsToSave);
});
</script>
<?php include 'includes/footer.php'; ?>
