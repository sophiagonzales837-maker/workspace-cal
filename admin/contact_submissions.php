<?php
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin');
include 'includes/header.php'; 
$result = mysqli_query($conn, "SELECT * FROM contact_requests ORDER BY id DESC");
?>
<div class="card" style="width: 100%; min-width: 0; box-sizing: border-box;">
    <h3 style="margin-top: 0; color: var(--primary-navy);">Contact Submissions</h3>
    <div style="overflow-x: auto;">
        <table class="table" style="font-size: 12px; white-space: nowrap;">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>DOB</th><th>Message</th><th>Jornaya</th><th>Date</th></tr></thead>
            <tbody>
                <?php if($result && mysqli_num_rows($result) > 0): ?>
                    <?php while($row = mysqli_fetch_assoc($result)): $row = decrypt_fields($row); ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['phone']) ?></td>
                        <td><?= htmlspecialchars($row['dob']) ?></td>
                        <td><div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($row['message']) ?></div></td>
                        <td><?= htmlspecialchars($row['jornaya_leadid']) ?></td>
                        <td><?= date('m/d/y', strtotime($row['created_at'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px; color:#888;">No contact submissions found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
