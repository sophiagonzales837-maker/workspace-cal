<?php
// Force PHP to show errors if something fails
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
protect('Dev');

$cols = ['id', 'customer_id', 'password', 'vertical', 'status', 'customer_name', 'first_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'referred_by', 'application_text', 'questions', 'c2c_id', 'id_upload_token', 'agent_name', 'closer_name', 'verifier_name', 'jornaya_leadid', 'trustedform_cert_url', 'ip_address'];
$search = trim((string)($_GET['search'] ?? ''));
$search_where = '';
if ($search !== '') {
    $search_value = mysqli_real_escape_string($conn, $search);
    $searchable_fields = ['customer_id', 'vertical', 'status', 'customer_name', 'first_name', 'last_name', 'phone', 'email', 'address', 'city', 'state', 'zip_code', 'dob', 'referred_by', 'application_text', 'questions', 'c2c_id', 'agent_name', 'closer_name', 'verifier_name', 'jornaya_leadid', 'trustedform_cert_url', 'ip_address'];
    $search_conditions = ["CAST(`id` AS CHAR) LIKE '%$search_value%'"];
    foreach ($searchable_fields as $field) {
        $search_conditions[] = "`$field` LIKE '%$search_value%'";
    }
    $search_where = ' WHERE (' . implode(' OR ', $search_conditions) . ')';
}

// ==========================================
// AJAX: INFINITE SCROLL HANDLER
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'load_more') {
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $query = "SELECT * FROM leads$search_where ORDER BY id DESC LIMIT 50 OFFSET $offset";
    $result = mysqli_query($conn, $query);
    
    $html = '';
    if ($result) {
        while($row = mysqli_fetch_assoc($result)) {
            if (function_exists('decrypt_fields')) {
                $row = decrypt_fields($row); 
            }
            $html .= "<tr id='row-" . htmlspecialchars($row['id'] ?? '') . "'>";
            $html .= "<td class='action-cell'><button class='del-btn' onclick='deleteLead(" . htmlspecialchars($row['id'] ?? 0) . ")'>Delete</button></td>";
            foreach($cols as $col) {
                if ($col === 'id') {
                    $html .= "<td class='id-cell'>" . htmlspecialchars(trim($row[$col] ?? '')) . "</td>";
                } else {
                    $html .= "<td><div contenteditable='true' class='sheet-cell autosave-val' data-id='" . htmlspecialchars($row['id'] ?? '') . "' data-col='" . $col . "'>" . htmlspecialchars(trim($row[$col] ?? '')) . "</div></td>";
                }
            }
            $html .= "</tr>";
        }
    }
    echo $html;
    exit();
}

// ==========================================
// INITIAL PAGE LOAD (First 50 Leads)
// ==========================================
$query = "SELECT * FROM leads$search_where ORDER BY id DESC LIMIT 50";
$result = mysqli_query($conn, $query);

include 'includes/header.php'; 
?>
<style>
    /* Strict Excel Table Layout */
    .excel-table {
        width: max-content;
        border-collapse: collapse;
        background: white;
        font-size: 13px;
    }
    .excel-table th, .excel-table td {
        border: 1px solid #cbd5e1;
        padding: 0; /* Removing padding to allow div to control size */
        vertical-align: top;
        color: #334155;
    }
    .excel-table th {
        background: #f1f5f9;
        position: sticky;
        top: 0;
        padding: 6px 10px;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700;
        color: #475569;
        z-index: 10;
        border-bottom: 2px solid #94a3b8;
        white-space: nowrap;
    }

    /* Standardized 190px Cell with Scrolling */
    .sheet-cell {
        outline: none;
        width: 190px;
        min-width: 190px;
        max-width: 190px;
        max-height: 60px; /* Limits height before scrolling */
        min-height: 24px;
        padding: 6px;
        overflow-y: auto;
        overflow-x: hidden;
        white-space: pre-wrap; /* Keeps formatting but wraps text */
        word-break: break-word;
        display: block;
        box-sizing: border-box;
        background: transparent;
    }
    
    /* Clean Scrollbars inside cells */
    .sheet-cell::-webkit-scrollbar { width: 4px; }
    .sheet-cell::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 4px; }
    
    .sheet-cell:hover { background: #f8fafc; cursor: pointer; }
    .sheet-cell:focus, .cell-selected { 
        background: #fff; 
        box-shadow: inset 0 0 0 2px #2563EB; 
        cursor: text; 
        z-index: 5;
        position: relative;
    }
    
    /* Special tighter columns */
    .action-cell, .id-cell { padding: 6px !important; text-align: center; vertical-align: middle !important; white-space: nowrap; }
    .del-btn { background: #ef4444; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 11px; }
    .del-btn:hover { background: #dc2626; }
    
    #table-container { 
        border-radius: 4px; 
        box-shadow: 0 0 0 1px #cbd5e1; 
        overflow: auto; 
        max-height: 75vh; 
    }

    .save-indicator { font-size: 11px; color: #10b981; font-weight: bold; position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 10px 20px; border-radius: 5px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); opacity: 0; transition: opacity 0.3s ease; z-index: 9999; }
    .save-indicator.show { opacity: 1; }
</style>

<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h1 style="color: var(--primary-navy); margin: 0;">Database Master Sheet</h1>
        <input type="text" id="searchInput" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search all leads..." style="padding: 8px 12px; width: 300px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px;">
    </div>

    <?php if (!$result): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px;">
            <strong>Database Error:</strong> <?= htmlspecialchars(mysqli_error($conn)) ?>
        </div>
    <?php else: ?>
        <div id="table-container">
            <table id="leadsTable" class="excel-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <?php foreach($cols as $col): ?>
                            <th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $col))) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="leadsBody">
                    <?php while($row = mysqli_fetch_assoc($result)): 
                        if (function_exists('decrypt_fields')) {
                            $row = decrypt_fields($row); 
                        }
                    ?>
                    <tr id="row-<?= htmlspecialchars($row['id'] ?? '') ?>">
                        <td class="action-cell">
                            <button class="del-btn" onclick="deleteLead(<?= htmlspecialchars($row['id'] ?? 0) ?>)">Delete</button>
                        </td>
                        <?php foreach($cols as $col): ?>
                            <?php if ($col === 'id'): ?>
                                <td class="id-cell"><?= htmlspecialchars(trim($row[$col] ?? '')) ?></td>
                            <?php else: ?>
                                <td><div contenteditable="true" class="sheet-cell autosave-val" data-id="<?= htmlspecialchars($row['id'] ?? '') ?>" data-col="<?= $col ?>"><?= htmlspecialchars(trim($row[$col] ?? '')) ?></div></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="global-save-indicator" class="save-indicator">Saving...</div>

<script>
    // --- 1. EXCEL GRID EDITING ENGINE (STABLE V3 - AUTOSAVE ALL) ---
    const indicator = document.getElementById('global-save-indicator');
    const tableContainer = document.getElementById('table-container');

    if (tableContainer) {
        tableContainer.addEventListener('focusin', function(e) {
            let target = e.target;
            
            if (target.getAttribute('contenteditable') === 'true' || target.classList.contains('sheet-cell')) {
                let cell = target.closest('td');
                if (!cell) return;
                
                document.querySelectorAll('.cell-selected').forEach(c => c.classList.remove('cell-selected'));
                target.classList.add('cell-selected');

                if (!target.hasAttribute('data-original-value')) {
                    let val = target.innerText.trim();
                    target.setAttribute('data-original-value', val);
                }
            }
        });

        tableContainer.addEventListener('focusout', function(e) {
            let target = e.target;
            
            if (target.getAttribute('contenteditable') === 'true' || target.classList.contains('sheet-cell')) {
                let id = target.getAttribute('data-id');
                let col = target.getAttribute('data-col');
                if (!id || !col) return;

                let newValue = target.innerText.trim();
                let originalValue = target.getAttribute('data-original-value');

                if (newValue !== originalValue) {
                    saveCellAjax(id, col, newValue, target);
                    target.setAttribute('data-original-value', newValue);
                }
            }
        });

        tableContainer.addEventListener('keydown', function(e) {
            let target = e.target;
            if (e.key === 'Enter' && !e.shiftKey) {
                if (target.getAttribute('contenteditable') === 'true' || target.classList.contains('sheet-cell')) {
                    e.preventDefault();
                    target.blur();
                }
            }
        });
    }

    function saveCellAjax(id, field, value, cell) {
        if (!indicator) return;
        indicator.innerText = "Saving...";
        indicator.style.color = "#8b5cf6";
        indicator.classList.add('show');
        
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('col', field);
        formData.append('val', value);
        
        fetch('autosave_sheet.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(response => response.text())
        .then(data => {
            if (data.trim() === 'Saved') {
                indicator.innerText = "Saved";
                indicator.style.color = "#10b981";
                setTimeout(() => { indicator.classList.remove('show'); }, 2000);
                
            } else {
                indicator.innerText = "Error Saving";
                indicator.style.color = "#ef4444";
            }
        }).catch(err => {
            indicator.innerText = "Network Error";
            indicator.style.color = "#ef4444";
        });
    }

    // --- 2. Delete Function ---
    function deleteLead(id) {
        if(confirm("DANGER: Are you sure you want to permanently delete Lead ID " + id + "?")) {
            fetch('delete_lead.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${id}`
            }).then(r => r.text()).then(res => {
                if(res.trim() === 'Deleted') {
                    document.getElementById('row-' + id).remove();
                } else {
                    alert('Error deleting: ' + res);
                }
            });
        }
    }

    // --- 3. Live Search & Infinite Scrolling ---
    let offset = 50;
    let isFetching = false;
    let hasMore = true;
    let searchTimeout;
    let searchGeneration = 0;
    const tbody = document.getElementById('leadsBody');
    const searchInput = document.getElementById('searchInput');

    function fetchLeadRows(reset = false) {
        if (!tbody || (isFetching && !reset) || (!hasMore && !reset)) return;
        if (reset) {
            searchGeneration++;
            offset = 0;
            hasMore = true;
        }
        const generation = searchGeneration;
        const requestOffset = offset;
        isFetching = true;
        if (reset) tbody.replaceChildren();

        fetch(`all_leads.php?action=load_more&offset=${requestOffset}&search=${encodeURIComponent(searchInput.value.trim())}`)
            .then(response => response.text())
            .then(html => {
                if (generation !== searchGeneration) return;
                if (html.trim() === '') {
                    hasMore = false;
                } else {
                    if (reset) tbody.innerHTML = html;
                    else tbody.insertAdjacentHTML('beforeend', html);
                    offset = requestOffset + 50;
                }
                isFetching = false;
            })
            .catch(err => {
                if (generation === searchGeneration) {
                    console.error('Fetch error:', err);
                    isFetching = false;
                }
            });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => fetchLeadRows(true), 300);
        });
    }

    if (tableContainer) {
        tableContainer.addEventListener('scroll', function() {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 100) {
                fetchLeadRows();
            }
        });
    }
</script>
<?php include 'includes/footer.php'; ?>