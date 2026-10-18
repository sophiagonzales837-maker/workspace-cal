<?php 
session_start(); 
require_once '../config/db.php'; 
require_once '../config/security.php'; 

$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']; 
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {     
    session_write_close();     
    header("Location: ../auth/login.php");     
    exit(); 
} 

$result = mysqli_query($conn, "SELECT * FROM leads WHERE jornaya_leadid IS NOT NULL OR message IS NOT NULL ORDER BY id DESC"); 
?>
<!DOCTYPE html> 
<html lang="en"> 
<head>     
    <meta charset="UTF-8">     
    <title>Admin | Website Form Leads</title>
    <link rel="stylesheet" href="../assets/style.css"> 
    <style>
        /* Filter & Search Styles */
        th[data-filter="true"] { position: relative; padding-right: 25px !important; }
        .filter-icon { margin-left: 8px; cursor: pointer; color: #94A3B8; font-size: 11px; transition: 0.2s; position: absolute; right: 4px; top: 50%; transform: translateY(-50%); }
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
</head> 
<body>     
    <?php include 'includes/header.php'; ?>     
    <div class="main">         
        <h1 style="font-size: 28px; color: var(--primary-navy); margin-bottom: 20px;">Website Form Leads Submitted</h1>         
        
        <!-- Global Search Bar -->
        <div style="margin-bottom: 20px; display: flex; align-items: center; background: white; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px 16px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.02);">
            <i class="fas fa-search" style="color: #94A3B8; margin-right: 12px;"></i>
            <input type="text" id="global-search-input" placeholder="Search forms, names, emails..." style="width: 100%; border: none; outline: none; font-size: 14px; background: transparent; color: #1E293B;">
        </div>

        <div class="card" style="overflow-x:auto; padding-bottom: 150px;">             
            <table class="table" id="forms-table" style="font-size: 13px; min-width: 1120px; white-space: nowrap;">                 
                <thead>                     
                    <tr> 
                        <th data-filter="true">First Name</th> 
                        <th data-filter="true">Last Name</th> 
                        <th data-filter="true">Email</th> 
                        <th data-filter="true">Phone</th> 
                        <th data-filter="true">Address</th> 
                        <th data-filter="true">City</th> 
                        <th data-filter="true">State</th> 
                        <th data-filter="true">Zip Code</th> 
                        <th data-filter="true">DOB</th> 
                        <th data-filter="true">Vertical</th>
                        <th>Message</th> 
                        <th data-filter="true">Jornaya</th> 
                        <th data-filter="true">Trusted Form</th> 
                        <th data-filter="true">IP Address</th> 
                        <th data-filter="true">Date</th> 
                    </tr>                 
                </thead>                 
                <tbody>                     
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>                         
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>                             
                            <?php $row = decrypt_fields($row); ?>                             
                            <tr> 
                                <td><?= htmlspecialchars($row['first_name']) ?></td> 
                                <td><?= htmlspecialchars($row['last_name']) ?></td> 
                                <td><?= htmlspecialchars($row['email']) ?></td> 
                                <td><?= htmlspecialchars($row['phone']) ?></td> 
                                <td><?= htmlspecialchars($row['address']) ?></td> 
                                <td><?= htmlspecialchars($row['city']) ?></td> 
                                <td><?= htmlspecialchars($row['state']) ?></td> 
                                <td><?= htmlspecialchars($row['zip_code']) ?></td> 
                                <td><?php $dob = new DateTime($row['dob']); echo htmlspecialchars($dob->format('m-d-Y')); ?></td> 
                                <td><?= htmlspecialchars($row['vertical']) ?></td>
                                <td style="white-space: normal; min-width: 200px;"><?= htmlspecialchars($row['message']) ?></td> 
                                <td><?= htmlspecialchars($row['jornaya_leadid']) ?></td> 
                                <td><?= htmlspecialchars($row['trustedform_cert_url']) ?></td> 
                                <td><?= htmlspecialchars($row['ip_address']) ?></td> 
                                <td><?= $row['created_at'] ?></td> 
                            </tr>                         
                        <?php endwhile; ?>                     
                    <?php else: ?>                         
                        <tr class="no-leads-row"><td colspan="15" style="text-align:center; padding:20px; color:#888;">No website leads found.</td></tr>                     
                    <?php endif; ?>                 
                </tbody>             
            </table>         
        </div>     
    </div> 

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // --- FILTER & SEARCH ENGINE ---
    let activeFilters = {}; 

    function initializeFilters() {
        const table = document.getElementById('forms-table');
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
                
                let text = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
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
        const table = document.getElementById('forms-table');
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
                    let cellText = cell.textContent.trim().replace(/[\n\r]+|[\s]{2,}/g, ' ');
                    if (!allowedValues.includes(cellText)) {
                        rowMatchesAll = false;
                        break;
                    }
                }
            }
            
            // 2. Global Search
            if (rowMatchesAll && searchTerm !== '') {
                let textContent = row.textContent.toLowerCase();
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

</body> 
</html>