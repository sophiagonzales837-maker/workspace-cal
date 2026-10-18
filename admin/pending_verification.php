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

// Fetch all Verifiers to populate the assignment dropdown
$verifiers = [];
$v_query = $conn->query("SELECT username FROM users WHERE role = 'Verifier' ORDER BY username ASC");
if ($v_query) {
    while ($row = $v_query->fetch_assoc()) {
        $verifiers[] = $row['username'];
    }
}

// Fetch Leads currently pending verification (Using LEFT JOIN to guarantee accurate Usernames)
// Fetch Leads currently pending verification (Using LEFT JOIN to guarantee accurate Usernames)
$query = "SELECT l.id, l.customer_name, l.first_name, l.last_name, l.vertical, l.phone, 
                 l.closer_name, l.verifier_name, l.status, l.updated_at,
                 l.jornaya_leadid, l.trustedform_cert_url, l.ip_address,
                 uc.username as fetched_closer_name,
                 uv.username as fetched_verifier_name
          FROM leads l 
          LEFT JOIN users uc ON l.closer_id = uc.id
          LEFT JOIN users uv ON l.verifier_id = uv.id
          WHERE l.status LIKE 'Pending Verification%' 
          ORDER BY l.updated_at DESC";
$result = $conn->query($query);

include 'includes/header.php';
?>

<style>
    /* Filter & Search Styles */
    th[data-filter="true"] { position: relative; padding-right: 25px !important; }
    .filter-icon { margin-left: 8px; cursor: pointer; color: #94A3B8; font-size: 11px; transition: 0.2s; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); }
    .filter-icon:hover, .filter-icon.active { color: #007BFF; }
    .filter-menu {
        display: none; position: absolute; top: 100%; left: 0; min-width: 200px;
        max-height: 240px; background: white; border: 1px solid #d1d5db; border-radius: 8px;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12); z-index: 50; padding: 10px; color: #1e293b; text-transform: none; font-weight: normal; font-size: 13px; text-align: left;
    }
    .filter-menu.show { display: block; }
    .filter-search {
        width: 100%; padding: 8px; border: 1px solid #e2e8f0;
        border-radius: 4px; margin-bottom: 10px; font-size: 13px; box-sizing: border-box; outline: none;
    }
    .filter-options-container { max-height: 140px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
    .filter-option { display: flex; align-items: center; gap: 8px; padding: 6px; cursor: pointer; border-radius: 4px; margin: 0; }
    .filter-option:hover { background: #f8fafc; }
    .filter-actions { display: flex; justify-content: space-between; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    .filter-btn { background: none; border: none; color: #007BFF; font-size: 12px; font-weight: 700; cursor: pointer; padding: 4px 8px; }
    .filter-btn:hover { text-decoration: underline; }
</style>

<div class="card" style="padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;">
        <div>
            <h2 style="margin: 0; font-size: 24px; color: var(--primary-navy);">Verification Queue Management</h2>
            <p style="color: #64748B; margin: 8px 0 0 0; font-size: 14px;">Monitor leads currently waiting for verification. Use the dropdown to instantly reassign a lead to a different Verifier.</p>
        </div>
        <div style="background: #e8f0fe; color: #2563eb; padding: 8px 16px; border-radius: 8px; font-weight: bold; font-size: 14px;">
            Total Pending: <?php echo $result ? $result->num_rows : 0; ?>
        </div>
    </div>
    
    <!-- Global Search Bar -->
    <div style="margin-bottom: 20px; display: flex; align-items: center; background: white; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 16px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.02);">
        <i class="fas fa-search" style="color: #94A3B8; margin-right: 12px;"></i>
        <input type="text" id="global-search-input" placeholder="Search verification queue..." style="width: 100%; border: none; outline: none; font-size: 14px; background: transparent; color: #1E293B;">
    </div>
    
    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding-bottom: 150px;">
        <table class="table" id="verifier-queue-table" style="min-width: 1000px; margin: 0;">
            <thead>
                <tr style="background: #f8fafc;">
                    <th style="padding: 15px;" data-filter="true">Customer Name</th>
                    <th style="padding: 15px;" data-filter="true">Phone</th>
                    <th style="padding: 15px;" data-filter="true">Vertical</th>
                    <th style="padding: 15px;" data-filter="true">Assigned Closer</th>
                    <th style="padding: 15px;" data-filter="true">Jornaya</th>
                    <th style="padding: 15px;" data-filter="true">Trusted Form</th>
                    <th style="padding: 15px;" data-filter="true">IP Address</th>
                    <th style="padding: 15px;" data-filter="true">Status</th>
                    <th style="padding: 15px; width: 220px;" data-filter="true">Assigned Verifier</th>
                    <th style="padding: 15px;" data-filter="true">Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while($lead_raw = $result->fetch_assoc()): 
                        // Decrypt sensitive fields
                        $lead = decrypt_fields($lead_raw);
                        
                        // Handle missing customer names gracefully
                        $customer_name = htmlspecialchars($lead['customer_name'] ?? trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')));
                        if (empty($customer_name)) $customer_name = 'Unknown Customer';
                        
                        // Smart Fallback for Names (Use JOIN'd user table names first, fallback to leads text column)
                        $display_closer = !empty($lead['fetched_closer_name']) ? $lead['fetched_closer_name'] : (!empty($lead['closer_name']) ? $lead['closer_name'] : 'Unassigned');
                        $display_verifier = !empty($lead['fetched_verifier_name']) ? $lead['fetched_verifier_name'] : (!empty($lead['verifier_name']) ? $lead['verifier_name'] : '');
                    ?>
                        <tr style="border-bottom: 1px solid #e2e8f0; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 12px 15px;"><strong><?= $customer_name ?></strong></td>
                            <td style="padding: 12px 15px;"><?= htmlspecialchars($lead['phone'] ?? 'N/A') ?></td>
                            <td style="padding: 12px 15px;"><?= htmlspecialchars($lead['vertical'] ?? 'General') ?></td>
                            <td style="padding: 12px 15px; color: #475569;"><?= htmlspecialchars($display_closer) ?></td>
                            <td style="padding: 12px 15px;"><?= htmlspecialchars($lead['jornaya_leadid'] ?? 'N/A') ?></td>
                            <td style="padding: 12px 1px;"><?= htmlspecialchars($lead['trustedform_cert_url'] ?? 'N/A') ?></td>
                            <td style="padding: 12px 15px;"><?= htmlspecialchars($lead['ip_address'] ?? 'N/A') ?></td>
                            
                            
                            <td style="padding: 12px 15px;">
                                <span style="background: #fef3c7; color: #b45309; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; border: 1px solid #fde68a;">
                                    <?= htmlspecialchars($lead['status']) ?>
                                </span>
                            </td>
                            <td style="padding: 12px 15px;">
                                <select class="verifier-assign-select" data-lead-id="<?= $lead['id'] ?>" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; font-size: 13px; font-family: inherit; background: #fff; cursor: pointer; color: var(--primary-navy); font-weight: 500;">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach($verifiers as $v_name): ?>
                                        <option value="<?= htmlspecialchars($v_name) ?>" <?= ($display_verifier == $v_name) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($v_name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="padding: 12px 15px; color: #64748b; font-size: 13px;">
                                <?= !empty($lead['updated_at']) ? date('M d, h:i A', strtotime($lead['updated_at'])) : 'N/A' ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="no-leads-row">
                        <td colspan="7" style="text-align: center; padding: 40px 20px; color: #64748B;">
                            <i class="fas fa-check-circle" style="font-size: 30px; color: #10b981; margin-bottom: 10px; display: block;"></i>
                            The verification queue is currently empty.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Global Save Indicator -->
<div id="global-save-indicator" style="font-size: 12px; color: #10b981; font-weight: bold; position: fixed; bottom: 20px; right: 20px; background: #fff; padding: 12px 24px; border-radius: 6px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); opacity: 0; transform: translateY(10px); transition: all 0.3s ease; z-index: 9999;">Saving...</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // --- VERIFIER AUTO-ASSIGNMENT ---
    const indicator = document.getElementById('global-save-indicator');

    document.querySelectorAll('.verifier-assign-select').forEach(select => {
        select.addEventListener('change', function() {
            const leadId = this.getAttribute('data-lead-id');
            const newVerifier = this.value;
            
            // Show loading state
            indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reassigning...';
            indicator.style.color = "#8b5cf6";
            indicator.style.opacity = '1';
            indicator.style.transform = 'translateY(0)';
            
            // Push to autosave_sheet.php
            const formData = new URLSearchParams();
            formData.append('id', leadId);
            formData.append('col', 'verifier_name');
            formData.append('val', newVerifier);
            
            fetch('autosave_sheet.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.text())
            .then(data => {
                if(data.trim() === 'Saved') {
                    indicator.innerHTML = '<i class="fas fa-check-circle"></i> Successfully Reassigned';
                    indicator.style.color = "#10b981";
                } else {
                    indicator.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error Saving';
                    indicator.style.color = "#ef4444";
                }
                
                // Hide toast after 2.5 seconds
                setTimeout(() => { 
                    indicator.style.opacity = '0'; 
                    indicator.style.transform = 'translateY(10px)'; 
                }, 2500);
            })
            .catch(err => {
                indicator.innerHTML = '<i class="fas fa-wifi"></i> Network Error';
                indicator.style.color = "#ef4444";
                setTimeout(() => { 
                    indicator.style.opacity = '0'; 
                    indicator.style.transform = 'translateY(10px)'; 
                }, 2500);
            });
        });
    });

    // --- FILTER & SEARCH ENGINE ---
    let activeFilters = {}; 

    function initializeFilters() {
        const table = document.getElementById('verifier-queue-table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-leads-row)'));
        const headers = table.querySelectorAll('th[data-filter="true"]');
        
        headers.forEach((th) => {
            const actualColIndex = Array.from(th.parentNode.children).indexOf(th);
            let uniqueValues = new Set();
            
            rows.forEach(row => {
                const cell = row.cells[actualColIndex];
                if (!cell) return;
                
                let text = '';
                if (cell.querySelector('select')) {
                    text = cell.querySelector('select').options[cell.querySelector('select').selectedIndex].text;
                } else {
                    text = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                }
                if (text !== "") uniqueValues.add(text);
            });
            
            if (uniqueValues.size === 0) return;
            
            th.insertAdjacentHTML('beforeend', `
                <i class="fas fa-filter filter-icon" id="icon-col-${actualColIndex}"></i>
                <div class="filter-menu" id="menu-col-${actualColIndex}">
                    <input type="text" class="filter-search" placeholder="Search filter..." onkeyup="searchFilterOptions(this)">
                    <div class="filter-options-container"></div>
                    <div class="filter-actions">
                        <button class="filter-btn clear-btn">Clear</button>
                        <button class="filter-btn apply-btn">Apply</button>
                    </div>
                </div>
            `);
            
            const icon = th.querySelector('.filter-icon');
            const menu = th.querySelector('.filter-menu');
            const optionsContainer = th.querySelector('.filter-options-container');
            const applyBtn = th.querySelector('.apply-btn');
            const clearBtn = th.querySelector('.clear-btn');
            
            Array.from(uniqueValues).sort().forEach(val => {
                let isChecked = (activeFilters[actualColIndex] && activeFilters[actualColIndex].includes(val)) ? 'checked' : '';
                optionsContainer.insertAdjacentHTML('beforeend', `
                    <label class="filter-option">
                        <input type="checkbox" value="${val.replace(/"/g, '&quot;')}" ${isChecked}>
                        ${val}
                    </label>
                `);
            });
            
            if (activeFilters[actualColIndex] && activeFilters[actualColIndex].length > 0) {
                icon.classList.add('active');
            }
            
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                document.querySelectorAll('.filter-menu.show').forEach(m => {
                    if(m !== menu) m.classList.remove('show');
                });
                menu.classList.toggle('show');
            });
            
            menu.addEventListener('click', function(e) { e.stopPropagation(); });
            
            applyBtn.addEventListener('click', function(e) {
                e.stopPropagation(); 
                const checkedBoxes = Array.from(optionsContainer.querySelectorAll('input:checked')).map(cb => cb.value);
                if (checkedBoxes.length > 0) {
                    activeFilters[actualColIndex] = checkedBoxes;
                    icon.classList.add('active');
                } else {
                    delete activeFilters[actualColIndex];
                    icon.classList.remove('active');
                }
                applyAllFilters();
                menu.classList.remove('show');
            });
            
            clearBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                optionsContainer.querySelectorAll('input:checked').forEach(cb => cb.checked = false);
                delete activeFilters[actualColIndex];
                icon.classList.remove('active');
                applyAllFilters();
                menu.classList.remove('show');
            });
        });
    }

    function applyAllFilters() {
        const table = document.getElementById('verifier-queue-table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-leads-row)'));
        const searchTerm = (document.getElementById('global-search-input').value || '').toLowerCase();
        
        rows.forEach(row => {
            let rowMatchesAll = true;
            
            // 1. Column Filters
            for (const colIndex in activeFilters) {
                const allowedValues = activeFilters[colIndex];
                const cell = row.cells[colIndex];
                if (cell) {
                    let cellText = cell.querySelector('select') ? cell.querySelector('select').options[cell.querySelector('select').selectedIndex].text : cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                    if (!allowedValues.includes(cellText)) {
                        rowMatchesAll = false;
                        break;
                    }
                }
            }
            
            // 2. Global Search
            if (rowMatchesAll && searchTerm !== '') {
                let textContent = row.textContent.toLowerCase();
                row.querySelectorAll('select').forEach(sel => {
                    textContent += ' ' + sel.options[sel.selectedIndex].text.toLowerCase();
                });
                if (!textContent.includes(searchTerm)) {
                    rowMatchesAll = false;
                }
            }
            
            row.style.display = rowMatchesAll ? '' : 'none';
        });
    }

    // Bind Global Search Input
    document.getElementById('global-search-input').addEventListener('input', applyAllFilters);

    // Global Filter Menu search
    window.searchFilterOptions = function(inputEl) {
        let filter = inputEl.value.toLowerCase();
        let labels = inputEl.nextElementSibling.querySelectorAll('.filter-option');
        labels.forEach(label => {
            let text = label.innerText.toLowerCase();
            label.style.display = text.includes(filter) ? "flex" : "none";
        });
    };

    // Close menus when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.filter-menu') && !e.target.closest('.filter-icon')) {
            document.querySelectorAll('.filter-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // Initialize
    initializeFilters();
});
</script>

<?php include 'includes/footer.php'; ?>