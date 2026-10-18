<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// Protect page: Only Admins & Jr. Admins allowed
$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'],$allowed_roles)) {
    session_write_close();
    header("Location: ../auth/login.php");
    exit();
}

// ==========================================
// AJAX DELETION HANDLER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'delete_leads') {
    header('Content-Type: application/json');
    $lead_ids = isset($_POST['lead_ids']) ? json_decode($_POST['lead_ids'], true) : [];
    
    if (is_array($lead_ids) && count($lead_ids) > 0) {
        // Sanitize all IDs to strict integers to prevent SQL injection
        $clean_ids = array_filter(array_map('intval',$lead_ids));
        
        if (count($clean_ids) > 0) {
            $ids_str = implode(',',$clean_ids);
            
            // Optional cleanup if related messages do not cascade on delete.
            mysqli_query($conn, "DELETE FROM messages WHERE lead_id IN ($ids_str)");
            
            // Delete from main leads table
            $delete_query = "DELETE FROM leads WHERE id IN ($ids_str)";
            
            if (mysqli_query($conn,$delete_query)) {
                echo json_encode(['status' => 'success', 'message' => count($clean_ids) . ' leads deleted successfully.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error during deletion.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid IDs provided.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No leads selected.']);
    }
    exit;
}

// ==========================================
// FETCH LEADS (Limiting to 1000 for performance)
// ==========================================
$query = "SELECT id, customer_name, first_name, last_name, phone, email, vertical, status, closer_name, created_at 
          FROM leads 
          ORDER BY created_at DESC 
          LIMIT 1000";
$result = mysqli_query($conn,$query);

include 'includes/header.php';
?>

<style>
    .modern-card { background-color: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; margin-bottom: 25px; }
    .table-container { max-height: 600px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
    th { background: #f8fafc; padding: 12px 15px; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid #cbd5e1; color: #1e293b; }
    td { padding: 10px 15px; border-bottom: 1px solid #e2e8f0; color: #475569; }
    tr:hover { background: #f1f5f9; }
    
    .btn-danger { background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: 0.2s; }
    .btn-danger:hover:not(:disabled) { background: #dc2626; }
    .btn-danger:disabled { opacity: 0.5; cursor: not-allowed; }
    
    .search-input { width: 300px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
    .search-input:focus { border-color: #3b82f6; }
    
    /* Custom Checkbox */
    .lead-checkbox, #selectAll { cursor: pointer; width: 16px; height: 16px; accent-color: #ef4444; }
    .row-selected td { background-color: #fef2f2 !important; }
</style>

<div style="max-width: 1200px; margin: 20px auto;">
    <div class="modern-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0; color: #0A2540; font-size: 24px;">Lead Deletion Management</h2>
                <p style="color: #64748b; font-size: 14px; margin-top: 5px;">Select leads below to permanently delete them. <strong>This action cannot be undone.</strong></p>
            </div>
            
            <div style="display: flex; gap: 15px; align-items: center;">
                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, phone, or vertical...">
                <button id="bulkDeleteBtn" class="btn-danger" disabled>
                    <i class="fas fa-trash-alt"></i> Delete Selected (<span id="selectedCount">0</span>)
                </button>
            </div>
        </div>

        <div class="table-container">
            <table id="leadsTable">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="selectAll" title="Select All Visible">
                        </th>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Phone</th>
                        <th>Vertical</th>
                        <th>Closer</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): 
                            // Decrypt data safely
                            $row = function_exists('decrypt_fields') ? decrypt_fields($row) :$row;
                            $customer_name = htmlspecialchars($row['customer_name'] ?? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                        ?>
                            <tr class="lead-row" data-id="<?php echo $row['id']; ?>">
                                <td style="text-align: center;">
                                    <input type="checkbox" class="lead-checkbox" value="<?php echo $row['id']; ?>">
                                </td>
                                <td><strong>#<?php echo $row['id']; ?></strong></td>
                                <td class="searchable"><?php echo $customer_name; ?></td>
                                <td class="searchable"><?php echo htmlspecialchars($row['phone'] ?? ''); ?></td>
                                <td class="searchable"><?php echo htmlspecialchars($row['vertical'] ?? ''); ?></td>
                                <td class="searchable"><?php echo htmlspecialchars($row['closer_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <span style="background: #e2e8f0; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; color: #475569;">
                                        <?php echo htmlspecialchars($row['status'] ?? 'New'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                <td style="text-align: center;">
                                    <button class="btn-danger single-delete-btn" data-id="<?php echo $row['id']; ?>" style="padding: 5px 10px; font-size: 11px;">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" style="text-align: center; padding: 30px; color: #94a3b8;">No leads found in the database.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const leadCheckboxes = document.querySelectorAll('.lead-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    const searchInput = document.getElementById('searchInput');

    // --- SEARCH LOGIC ---
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('.lead-row');
        
        rows.forEach(row => {
            const searchableText = Array.from(row.querySelectorAll('.searchable')).map(td => td.textContent.toLowerCase()).join(' ');
            if (searchableText.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
                // Deselect hidden rows
                const cb = row.querySelector('.lead-checkbox');
                if (cb.checked) {
                    cb.checked = false;
                    row.classList.remove('row-selected');
                }
            }
        });
        updateSelectionState();
    });

    // --- CHECKBOX LOGIC ---
    function updateSelectionState() {
        const visibleCheckboxes = Array.from(document.querySelectorAll('.lead-checkbox')).filter(cb => cb.closest('tr').style.display !== 'none');
        const checkedBoxes = visibleCheckboxes.filter(cb => cb.checked);
        
        selectedCountSpan.innerText = checkedBoxes.length;
        bulkDeleteBtn.disabled = checkedBoxes.length === 0;
        
        // Update "Select All" state
        if (visibleCheckboxes.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedBoxes.length === visibleCheckboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedBoxes.length > 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }

    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.lead-row').forEach(row => {
            if (row.style.display !== 'none') {
                const cb = row.querySelector('.lead-checkbox');
                cb.checked = isChecked;
                if (isChecked) {
                    row.classList.add('row-selected');
                } else {
                    row.classList.remove('row-selected');
                }
            }
        });
        updateSelectionState();
    });

    leadCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const row = this.closest('tr');
            if (this.checked) {
                row.classList.add('row-selected');
            } else {
                row.classList.remove('row-selected');
            }
            updateSelectionState();
        });
    });

    // --- DELETION LOGIC (AJAX) ---
    function executeDelete(idsArray) {
        if (!confirm(`Are you absolutely sure you want to permanently delete ${idsArray.length} lead(s)?\n\nThis action cannot be undone.`)) {
            return;
        }

        // Visual loading state
        const originalText = bulkDeleteBtn.innerHTML;
        bulkDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        bulkDeleteBtn.disabled = true;

        const formData = new URLSearchParams();
        formData.append('action', 'delete_leads');
        formData.append('lead_ids', JSON.stringify(idsArray));

        fetch('delete_leads.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message);
                // Remove deleted rows from the DOM without reloading the page
                idsArray.forEach(id => {
                    const row = document.querySelector(`.lead-row[data-id="${id}"]`);
                    if (row) row.remove();
                });
                updateSelectionState();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            alert('A network error occurred.');
        })
        .finally(() => {
            bulkDeleteBtn.innerHTML = originalText;
            updateSelectionState();
        });
    }

    // Bulk Delete
    bulkDeleteBtn.addEventListener('click', function() {
        const checkedBoxes = Array.from(document.querySelectorAll('.lead-checkbox:checked'));
        const idsToDelete = checkedBoxes.map(cb => cb.value);
        if (idsToDelete.length > 0) {
            executeDelete(idsToDelete);
        }
    });

    // Single Delete
    document.querySelectorAll('.single-delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            executeDelete([this.getAttribute('data-id')]);
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>