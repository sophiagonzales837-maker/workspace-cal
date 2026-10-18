<?php 
session_start();
require_once '../config/db.php'; 
require_once '../config/security.php'; 

// Only allow Admins and Jr. Admins
$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    session_write_close();
    header("Location: ../auth/login.php");
    exit();
}

// ==========================================
// AJAX HANDLERS (IMPORT, UPDATE, DELETE)
// ==========================================
if (isset($_POST['ajax_action'])) {
    
    // 1. BULK IMPORT CENTERS
    if ($_POST['ajax_action'] == 'bulk_import') {
        $centers = json_decode($_POST['centers'], true);
        
        if (empty($centers)) {
            echo 'Error: Invalid Data';
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO detention_centers (name, state, zipcode, client_codes) VALUES (?, ?, ?, ?)");
        
        $inserted_count = 0;
        foreach ($centers as $c) {
            $name = trim($c['name'] ?? '');
            if (empty($name)) continue; // Name is required
            
            $state = trim($c['state'] ?? '');
            $zip = trim($c['zipcode'] ?? '');
            $codes = trim($c['client_codes'] ?? '');
            
            $stmt->bind_param("ssss", $name, $state, $zip, $codes);
            if ($stmt->execute()) {
                $inserted_count++;
            }
        }
        $stmt->close();
        echo "Success: $inserted_count";
        exit;
    }

    // 2. INLINE EDIT (AUTO-SAVE)
    if ($_POST['ajax_action'] == 'update_center') {
        $id = (int)$_POST['id'];
        $field = $_POST['field'];
        $value = trim($_POST['value']);
        
        $allowed_fields = ['name', 'state', 'zipcode', 'client_codes'];
        if (in_array($field, $allowed_fields)) {
            $stmt = $conn->prepare("UPDATE detention_centers SET `$field` = ? WHERE id = ?");
            $stmt->bind_param("si", $value, $id);
            if ($stmt->execute()) echo "Success";
            else echo "Error";
            $stmt->close();
        } else {
            echo "Invalid Field";
        }
        exit;
    }

    // 3. BULK DELETE CENTERS (Triggered by 'Delete' Key)
    if ($_POST['ajax_action'] == 'delete_centers') {
        $ids = json_decode($_POST['ids'], true);
        if (!empty($ids)) {
            $clean_ids = array_map('intval', $ids);
            $id_string = implode(',', $clean_ids);
            
            if ($conn->query("DELETE FROM detention_centers WHERE id IN ($id_string)")) {
                echo "Success";
            } else {
                echo "Error";
            }
        }
        exit;
    }
}

// Fetch all existing centers
$centers = [];
$result = $conn->query("SELECT * FROM detention_centers ORDER BY state ASC, name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $centers[] = $row;
    }
}

include 'includes/header.php'; 
?>

<style>
    .dashboard-card { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
    
    .paste-area { 
        width: 100%; height: 100px; border: 2px dashed #94a3b8; border-radius: 8px; padding: 15px; 
        font-size: 16px; text-align: center; color: #64748b; background: #f8fafc; resize: none; transition: all 0.2s ease;
    }
    .paste-area:focus { border-color: #3b82f6; background: #fff; outline: none; box-shadow: 0 0 0 3px #eff6ff; }
    
    .mapping-table-wrapper { overflow-x: auto; overflow-y: auto; max-height: 600px; margin-top: 15px; border: 1px solid #cbd5e1; border-radius: 6px; user-select: none; }
    .mapping-table { width: 100%; border-collapse: collapse; font-size: 13px; white-space: nowrap; }
    .mapping-table th, .mapping-table td { padding: 8px 12px; border: 1px solid #e2e8f0; text-align: left; }
    .mapping-table th { background: #f1f5f9; position: sticky; top: 0; z-index: 2; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4); color: #334155; }
    
    /* Advanced Excel-like Grid Styling */
    .grid-cell { cursor: cell; }
    .grid-cell.selected-cell { background-color: #dbeafe !important; outline: 1px solid #3b82f6; }
    .grid-cell.editing-cell { background-color: #fff !important; outline: 2px solid #2563eb !important; outline-offset: -2px; cursor: text; user-select: text; }
    
    .mapping-select { width: 100%; padding: 6px; font-size: 13px; border-radius: 4px; border: 1px solid #94a3b8; background: #fff; }
    
    .btn-import { background: #2563eb; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: bold; margin-top: 15px; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-import:hover { background: #1d4ed8; }
    .btn-cancel { background: #f1f5f9; color: #334155; padding: 10px 20px; border: 1px solid #cbd5e1; border-radius: 5px; cursor: pointer; font-size: 14px; margin-top: 15px; margin-left: 10px; display: inline-block; }
    
    .save-indicator { 
        font-size: 11px; color: #10b981; font-weight: bold; position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 10px 20px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); opacity: 0; transition: opacity 0.3s ease; z-index: 9999; 
    }
    .save-indicator.show { opacity: 1; }
</style>

<!-- EXCEL PASTE & MAP SECTION -->
<div class="dashboard-card" id="paste-section">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2 style="margin-top: 0; color: #0A2540; font-size: 24px;">Manage Detention Centers</h2>
    </div>
    <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">Use the box below to paste bulk centers directly from Excel, or edit the existing facilities in the grid below.</p>
    
    <textarea id="excel-paste-box" class="paste-area" placeholder="Click here and press Ctrl+V to paste new centers from Excel..."></textarea>
</div>

<!-- MAPPING PREVIEW SECTION (Hidden by default) -->
<div class="dashboard-card" id="mapping-section" style="display: none;">
    <h3 style="margin-top: 0; color: #0f172a;">Map Your Columns</h3>
    <div class="mapping-table-wrapper">
        <table class="mapping-table" id="preview-table">
            <thead id="preview-thead"></thead>
            <tbody id="preview-tbody"></tbody>
        </table>
    </div>
    <button id="btn-confirm-import" class="btn-import">Confirm & Import Facilities</button>
    <button id="btn-cancel-import" class="btn-cancel">Cancel</button>
    <div id="loading-indicator" style="display: none; margin-top: 15px; color: #3b82f6; font-weight: bold;"><i class="fas fa-spinner fa-spin"></i> Processing...</div>
</div>

<!-- EXISTING CENTERS SECTION (EXCEL-LIKE NAVIGATION & EDIT) -->
<div class="dashboard-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0; color: #0f172a;">Active Facilities (<?php echo count($centers); ?>)</h3>
        <button id="btn-delete-selected" class="btn-import" style="background:#dc3545; margin-top: 0;">
            <i class="fas fa-trash"></i> Delete Selected
        </button>
    </div>
    
    <div style="background: #f1f5f9; padding: 10px; border-radius: 6px; margin-bottom: 10px; font-size: 13px; color: #334155; border: 1px solid #cbd5e1;">
        <strong>Quick Actions:</strong><br>
        &bull; <strong>Click & Drag:</strong> Select multiple cells.<br>
        &bull; <strong>Shift + Arrows:</strong> Expand selection.<br>
        &bull; <strong>Delete Key (or Delete Button):</strong> Completely deletes the selected facility from the database.<br>
        &bull; <strong>Backspace:</strong> Empties the text in the selected cell(s).<br>
        &bull; <strong>Double-Click:</strong> Edit cell text.
    </div>
    
    <div class="mapping-table-wrapper" id="grid-container">
        <table class="mapping-table" id="existing-centers-table">
            <thead>
                <tr>
                    <th style="width: 30%;">Facility Name</th>
                    <th style="width: 15%;">State Code</th>
                    <th style="width: 15%;">Zip Code</th>
                    <th style="width: 40%;">Mapped Client Codes (Comma Separated)</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($centers)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px;">No detention centers found in the database.</td></tr>
                <?php else: ?>
                    <?php foreach($centers as $c): ?>
                        <tr id="row-<?php echo $c['id']; ?>">
                            <td class="grid-cell" data-field="name"><?php echo htmlspecialchars($c['name'] ?? ''); ?></td>
                            <td class="grid-cell" data-field="state"><?php echo htmlspecialchars($c['state'] ?? ''); ?></td>
                            <td class="grid-cell" data-field="zipcode"><?php echo htmlspecialchars($c['zipcode'] ?? ''); ?></td>
                            <td class="grid-cell" data-field="client_codes"><?php echo htmlspecialchars($c['client_codes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Global Save Indicator -->
<div id="global-save-indicator" class="save-indicator">Saving...</div>

<script>
// ==========================================
// EXCEL-LIKE RECTANGULAR SELECTION ENGINE
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    const table = document.getElementById('existing-centers-table');
    if (!table) return;

    let selectedCells = new Set();
    let selectionStartCell = null;
    let selectionEndCell = null;
    let activeCell = null;
    let isDragging = false;

    function getCoords(cell) {
        return { r: cell.parentElement.rowIndex, c: cell.cellIndex };
    }

    function clearSelection() {
        selectedCells.forEach(cell => cell.classList.remove('selected-cell'));
        selectedCells.clear();
    }

    function renderSelection() {
        clearSelection();
        if (!selectionStartCell || !selectionEndCell) return;

        const start = getCoords(selectionStartCell);
        const end = getCoords(selectionEndCell);
        
        const minRow = Math.min(start.r, end.r);
        const maxRow = Math.max(start.r, end.r);
        const minCol = Math.min(start.c, end.c);
        const maxCol = Math.max(start.c, end.c);

        const rows = table.rows;
        for (let r = minRow; r <= maxRow; r++) {
            if (!rows[r]) continue;
            for (let c = minCol; c <= maxCol; c++) {
                const cell = rows[r].cells[c];
                if (cell && cell.classList.contains('grid-cell')) {
                    selectedCells.add(cell);
                    cell.classList.add('selected-cell');
                }
            }
        }
    }

    table.addEventListener('mousedown', (e) => {
        const cell = e.target.closest('.grid-cell');
        if (!cell || cell.getAttribute('contenteditable') === 'true') return;
        
        isDragging = true;
        selectionStartCell = cell;
        selectionEndCell = cell;
        activeCell = cell;
        renderSelection();
    });

    table.addEventListener('mouseover', (e) => {
        if (!isDragging) return;
        const cell = e.target.closest('.grid-cell');
        if (cell) {
            selectionEndCell = cell;
            activeCell = cell;
            renderSelection();
        }
    });

    document.addEventListener('mouseup', () => {
        isDragging = false;
    });

    // --- DOUBLE CLICK TO EDIT ---
    table.addEventListener('dblclick', (e) => {
        const cell = e.target.closest('.grid-cell');
        if (!cell) return;

        clearSelection();
        cell.classList.add('editing-cell');
        cell.setAttribute('contenteditable', 'true');
        cell.focus();
        
        const range = document.createRange();
        range.selectNodeContents(cell);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        const saveAndClose = () => {
            cell.removeAttribute('contenteditable');
            cell.classList.remove('editing-cell');
            const rowId = cell.parentElement.id.replace('row-', '');
            const fieldName = cell.dataset.field;
            updateDatabase(rowId, fieldName, cell.innerText.trim());
            cell.removeEventListener('blur', saveAndClose);
            cell.removeEventListener('keydown', handleEditKey);
        };

        const handleEditKey = (e) => {
            if (e.key === 'Enter') { e.preventDefault(); cell.blur(); }
            if (e.key === 'Escape') { cell.blur(); }
        };

        cell.addEventListener('blur', saveAndClose);
        cell.addEventListener('keydown', handleEditKey);
    });

    // --- BULK DELETION LOGIC ---
    function handleDeletion() {
        if (selectedCells.size > 0) {
            const rowsToDelete = new Set();
            selectedCells.forEach(cell => { rowsToDelete.add(cell.parentElement); });
            
            if(!confirm(`Are you sure you want to COMPLETELY DELETE ${rowsToDelete.size} facility/facilities?`)) {
                return;
            }

            const ids = Array.from(rowsToDelete).map(row => row.id.replace('row-', ''));
            deleteBulkCenters(ids, Array.from(rowsToDelete));
        } else {
            alert("Please select rows to delete first. (Click and drag to select)");
        }
    }

    document.getElementById('btn-delete-selected')?.addEventListener('click', handleDeletion);

    // --- KEYBOARD NAVIGATION & ACTIONS ---
    document.addEventListener('keydown', (e) => {
        if (document.activeElement.getAttribute('contenteditable') === 'true') return;

        if (e.key === 'Delete') {
            if (selectedCells.size > 0) {
                e.preventDefault();
                handleDeletion();
            }
            return;
        }

        if (e.key === 'Backspace') {
            if (selectedCells.size > 0) {
                e.preventDefault();
                selectedCells.forEach(cell => {
                    cell.innerText = '';
                    const rowId = cell.parentElement.id.replace('row-', '');
                    updateDatabase(rowId, cell.dataset.field, '');
                });
            }
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            const rows = table.querySelectorAll('tbody tr');
            if(rows.length > 0 && rows[0].cells[0].classList.contains('grid-cell')) {
                selectionStartCell = rows[0].cells[0];
                const lastRow = rows[rows.length - 1];
                selectionEndCell = lastRow.cells[lastRow.cells.length - 1];
                renderSelection();
            }
            return;
        }

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
            if (selectedCells.size > 0) {
                e.preventDefault();
                const text = Array.from(selectedCells).map(c => c.innerText).join('\t');
                navigator.clipboard.writeText(text);
            }
            return;
        }

        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key) && activeCell) {
            e.preventDefault();
            const coords = getCoords(activeCell);
            let targetRow = coords.r;
            let targetCol = coords.c;

            if (e.key === 'ArrowUp') targetRow = Math.max(1, coords.r - 1); 
            if (e.key === 'ArrowDown') targetRow = Math.min(table.rows.length - 1, coords.r + 1);
            if (e.key === 'ArrowLeft') targetCol = Math.max(0, coords.c - 1);
            if (e.key === 'ArrowRight') targetCol = Math.min(table.rows[targetRow].cells.length - 1, coords.c + 1);

            const nextCell = table.rows[targetRow].cells[targetCol];
            if (nextCell && nextCell.classList.contains('grid-cell')) {
                activeCell = nextCell;
                
                if (e.shiftKey) {
                    selectionEndCell = nextCell;
                } else {
                    selectionStartCell = nextCell;
                    selectionEndCell = nextCell;
                }
                
                nextCell.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
                renderSelection();
            }
        }
    });

// ==========================================
// DATABASE AJAX FUNCTIONS
// ==========================================
const indicator = document.getElementById('global-save-indicator');

function updateDatabase(id, fieldName, newValue) {
    indicator.innerText = "Saving...";
    indicator.style.color = "#8b5cf6";
    indicator.classList.add('show');
    
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'update_center');
    formData.append('id', id);
    formData.append('field', fieldName);
    formData.append('value', newValue);

    fetch('manage_detention.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString() })
    .then(r => r.text())
    .then(data => {
        if(data.includes('Success')) {
            indicator.innerText = "Saved";
            indicator.style.color = "#10b981";
            setTimeout(() => { indicator.classList.remove('show'); }, 2000);
        } else {
            indicator.innerText = "Error Saving";
            indicator.style.color = "#ef4444";
        }
    })
    .catch(err => console.error('Error updating:', err));
}

function deleteBulkCenters(idsArray, rowElementsArray) {
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'delete_centers');
    formData.append('ids', JSON.stringify(idsArray));

    fetch('manage_detention.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.text())
    .then(data => {
        if (data.includes('Success')) {
            rowElementsArray.forEach(row => row.remove());
        } else {
            alert('Failed to delete facilities.');
        }
    }).catch(err => { alert('Network error while deleting.'); });
}

// ==========================================
// IMPORT JAVASCRIPT
// ==========================================
const dbFields = [
    { value: '', label: '-- Ignore --' },
    { value: 'name', label: 'Facility Name (Required)' },
    { value: 'state', label: 'State Code (e.g. CA)' },
    { value: 'zipcode', label: 'Zip Code' },
    { value: 'client_codes', label: 'Client Codes' }
];

const autoGuessDefaults = ['name', 'state', 'zipcode', 'client_codes'];

let fullPastedData = []; 
document.getElementById('excel-paste-box').addEventListener('paste', function(e) {
    e.preventDefault();
    const clipboardData = e.clipboardData || window.clipboardData;
    const pastedText = clipboardData.getData('Text');
    if (!pastedText.trim()) return;

    const rows = pastedText.split('\n');
    fullPastedData = [];
    let maxCols = 0;

    for (let i = 0; i < rows.length; i++) {
        let rowData = rows[i].trim();
        if (rowData !== '') {
            let columns = rowData.split('\t');
            fullPastedData.push(columns);
            if (columns.length > maxCols) maxCols = columns.length;
        }
    }

    if (fullPastedData.length === 0) return;

    document.getElementById('paste-section').style.display = 'none';
    document.getElementById('mapping-section').style.display = 'block';
    generatePreviewTable(maxCols);
});

function generatePreviewTable(maxCols) {
    const thead = document.getElementById('preview-thead');
    const tbody = document.getElementById('preview-tbody');
    thead.innerHTML = ''; tbody.innerHTML = '';

    let headerRow = document.createElement('tr');
    for (let i = 0; i < maxCols; i++) {
        let th = document.createElement('th');
        let select = document.createElement('select');
        select.className = 'mapping-select';
        select.dataset.colIndex = i;
        
        dbFields.forEach(field => {
            let option = document.createElement('option');
            option.value = field.value;
            option.innerText = field.label;
            if (autoGuessDefaults[i] && field.value === autoGuessDefaults[i]) option.selected = true;
            select.appendChild(option);
        });
        
        th.appendChild(select);
        headerRow.appendChild(th);
    }
    thead.appendChild(headerRow);

    for (let r = 0; r < fullPastedData.length; r++) {
        let tr = document.createElement('tr');
        for (let c = 0; c < maxCols; c++) {
            let td = document.createElement('td');
            td.innerText = fullPastedData[r][c] !== undefined ? fullPastedData[r][c] : '';
            tr.appendChild(td);
        }
        tbody.appendChild(tr);
    }
}

document.getElementById('btn-cancel-import').addEventListener('click', function() {
    fullPastedData = [];
    document.getElementById('excel-paste-box').value = '';
    document.getElementById('mapping-section').style.display = 'none';
    document.getElementById('paste-section').style.display = 'block';
});

document.getElementById('btn-confirm-import').addEventListener('click', function() {
    const selects = document.querySelectorAll('.mapping-select');
    let columnMap = {}; 
    let hasName = false;

    selects.forEach(select => {
        let colIndex = select.dataset.colIndex;
        let selectedField = select.value;
        if (selectedField !== '') {
            columnMap[colIndex] = selectedField;
            if (selectedField === 'name') hasName = true;
        }
    });

    if (!hasName) {
        alert("You MUST map at least one column to 'Facility Name' to import facilities.");
        return;
    }

    this.style.display = 'none';
    document.getElementById('btn-cancel-import').style.display = 'none';
    document.getElementById('loading-indicator').style.display = 'block';

    let mappedData = [];
    for (let r = 0; r < fullPastedData.length; r++) {
        let rowObj = {};
        for (let colIndex in columnMap) {
            let fieldName = columnMap[colIndex];
            rowObj[fieldName] = fullPastedData[r][colIndex] !== undefined ? fullPastedData[r][colIndex] : '';
        }
        mappedData.push(rowObj);
    }

    const formData = new URLSearchParams();
    formData.append('ajax_action', 'bulk_import');
    formData.append('centers', JSON.stringify(mappedData));

    fetch('manage_detention.php', { 
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(response => response.text())
    .then(data => {
        if (data.includes('Success')) {
            alert(data);
            window.location.reload(); 
        } else {
            alert('Error processing data: ' + data);
            resetUI();
        }
    }).catch(err => { alert('Network error occurred.'); resetUI(); });

    function resetUI() {
        document.getElementById('btn-confirm-import').style.display = 'inline-block';
        document.getElementById('btn-cancel-import').style.display = 'inline-block';
        document.getElementById('loading-indicator').style.display = 'none';
    }
});

});
</script>

<?php include 'includes/footer.php'; ?>