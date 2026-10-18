<?php
require_once '../config/db.php';
require_once '../config/security.php'; 
protect('Admin');
include 'includes/header.php'; 

$result = mysqli_query($conn, "SELECT * FROM leads WHERE jornaya_leadid IS NOT NULL OR message IS NOT NULL ORDER BY id DESC");
?>
<div class="card" style="width: 100%; min-width: 0; box-sizing: border-box;">
    <h3 style="margin-top: 0; color: var(--primary-navy);">Website Form Leads Submitted</h3>
    <div style="overflow-x: auto;">
        <table class="table" style="font-size: 12px; white-space: nowrap;">
            <thead>
                <tr>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>City</th>
                    <th>State</th>
                    <th>Zip Code</th>
                    <th>DOB</th>
                    <th>Vertical</th>
                    <th>Message</th>
                    <th>Jornaya</th>
                    <th>Trusted Form</th>
                    <th>IP Address</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && mysqli_num_rows($result) > 0): ?>
                    <?php while($row = mysqli_fetch_assoc($result)): $row = decrypt_fields($row); ?>
                    <tr>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="first_name"><?= htmlspecialchars($row['first_name']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="last_name"><?= htmlspecialchars($row['last_name']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="email"><?= htmlspecialchars($row['email']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="phone"><?= htmlspecialchars($row['phone']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="address"><?= htmlspecialchars($row['address']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="city"><?= htmlspecialchars($row['city']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="state"><?= htmlspecialchars($row['state']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="zip_code"><?= htmlspecialchars($row['zip_code']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="dob"><?php $dob = new DateTime($row['dob']); echo htmlspecialchars($dob->format('m-d-Y')); ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="vertical"><?= htmlspecialchars($row['vertical']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="message"><?= htmlspecialchars($row['message']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="jornaya_leadid"><?= htmlspecialchars($row['jornaya_leadid']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="trustedform_cert_url"><?= htmlspecialchars($row['trustedform_cert_url']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="ip_address"><?= htmlspecialchars($row['ip_address']) ?></td>
                        <td contenteditable="true" class="editable" data-id="<?= $row['id'] ?>" data-col="created_at"><?= $row['created_at'] ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="15" style="text-align:center; padding:20px; color:#888;">No website leads found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const editableCells = document.querySelectorAll('.editable');

    editableCells.forEach(cell => {
        // Store the original value when the user clicks into the cell
        cell.addEventListener('focus', function() {
            if (!this.hasAttribute('data-original')) {
                this.setAttribute('data-original', this.innerText.trim());
            }
        });

        // Trigger save when the user clicks out of the cell
        cell.addEventListener('blur', function() {
            let id = this.getAttribute('data-id');
            let col = this.getAttribute('data-col');
            let val = this.innerText.trim();
            let originalVal = this.getAttribute('data-original') || '';

            // Only send AJAX if the value actually changed
            if (val !== originalVal) {
                // Visual feedback: Yellow indicates saving
                this.style.backgroundColor = '#fff3cd';

                let formData = new FormData();
                formData.append('id', id);
                formData.append('col', col);
                formData.append('val', val);

                // IMPORTANT: Ensure this points to the correct path of your autosave script
                fetch('autosave.php', { 
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    if(data.trim() === 'Saved') {
                        // Visual feedback: Green means success
                        this.style.backgroundColor = '#d4edda'; 
                        this.setAttribute('data-original', val);
                        setTimeout(() => this.style.backgroundColor = 'transparent', 1500);
                    } else {
                        // Visual feedback: Red means error
                        this.style.backgroundColor = '#f8d7da'; 
                        alert('Error saving data: ' + data);
                    }
                })
                .catch(error => {
                    this.style.backgroundColor = '#f8d7da';
                    console.error('Error:', error);
                });
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>