<?php
require_once '../config/db.php';
require_once '../config/security.php'; 
protect('Admin');
include 'includes/header.php'; 

$result = mysqli_query($conn, "SELECT * FROM reviews WHERE status='pending' ORDER BY created_at DESC");
?>
<div class="card" style="width: 100%; min-width: 0; box-sizing: border-box;">
    <h3 style="margin-top: 0; color: var(--primary-navy);">Review Moderation Panel</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
        <?php if($result && mysqli_num_rows($result) > 0): ?>
            <?php while($row = mysqli_fetch_assoc($result)): ?>
                <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eee;">
                    <h4 style="margin: 0 0 10px 0; font-size: 16px;"><?= htmlspecialchars($row['name']) ?></h4>
                    <div style="color: #ffc107; font-size: 18px; margin-bottom: 10px;"><?= str_repeat("⭐", (int)$row['rating']) ?></div>
                    <p style="font-size: 13px; color: #555; margin-bottom: 20px; line-height: 1.5;">"<?= htmlspecialchars($row['review']) ?>"</p>
                    <div style="display: flex; gap: 10px;">
                        <a onclick="return confirm('Approve this review?')" href="approve_review.php?id=<?= $row['id'] ?>" class="btn" style="flex: 1; text-align: center; background: #28a745; text-decoration: none;">Approve</a>
                        <a onclick="return confirm('Reject this review?')" href="reject_review.php?id=<?= $row['id'] ?>" class="btn" style="flex: 1; text-align: center; background: #dc3545; text-decoration: none;">Reject</a>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color: #888; grid-column: 1 / -1;">No pending reviews found.</p>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
