<?php 
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Agent'); 

$lead_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$agent_id = $_SESSION['user_id'];

// --- SECURITY FIX 1: Prepared Statement for Ownership Check ---
$stmt_lead = $conn->prepare("SELECT customer_name, status FROM leads WHERE id = ? AND agent_id = ?");
$stmt_lead->bind_param("ii", $lead_id, $agent_id);
$stmt_lead->execute();
$lead_result = $stmt_lead->get_result();
$stmt_lead->close();

if($lead_result->num_rows == 0) {
    die("Lead not found or access denied.");
}
$lead = $lead_result->fetch_assoc();

// --- SECURITY FIX 2: Decrypt Sensitive Data ---
$decrypted_customer_name = decryptData($lead['customer_name']);

include 'includes/header.php'; 
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="margin-bottom: 20px;">
        <a href="my_leads.php" style="text-decoration: none; color: var(--electric-blue); font-weight: bold;">&larr; Back to My Leads</a>
    </div>

    <div class="card">
        <h3>Updates for: <?php echo htmlspecialchars($decrypted_customer_name); ?></h3>
        <p>Current Status: <strong><?php echo htmlspecialchars($lead['status']); ?></strong></p>
        <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

        <div style="max-height: 400px; overflow-y: auto; padding-right: 10px; margin-bottom: 20px;">
            <?php
            // --- SECURITY FIX 3: Prepared Statement for Messages ---
            $stmt_msg = $conn->prepare("SELECT m.content, m.created_at, u.username, u.role 
                FROM messages m JOIN users u ON m.sender_id = u.id 
                WHERE m.lead_id = ? ORDER BY m.created_at ASC");
            $stmt_msg->bind_param("i", $lead_id);
            $stmt_msg->execute();
            $msg_query = $stmt_msg->get_result();
            $stmt_msg->close();
                
            if($msg_query->num_rows > 0) {
                while($msg = $msg_query->fetch_assoc()) {
                    $bg = ($msg['role'] == 'Agent') ? '#e9ecef' : '#e0f7fa';
                    $safe_username = htmlspecialchars($msg['username']);
                    $safe_role = htmlspecialchars($msg['role']);
                    $safe_content = nl2br(htmlspecialchars($msg['content']));
                    $formatted_date = date('M d, H:i', strtotime($msg['created_at']));

                    echo "<div style='background: {$bg}; padding: 10px 15px; border-radius: 8px; margin-bottom: 10px;'>
                            <strong style='color: var(--primary-navy);'>{$safe_username} ({$safe_role})</strong>
                            <span style='font-size: 11px; color: #666; float: right;'>{$formatted_date}</span>
                            <p style='margin: 8px 0 0 0; font-size: 14px;'>{$safe_content}</p>
                          </div>";
                }
            } else {
                echo "<p style='color: #888; font-style: italic;'>No updates or comments yet.</p>";
            }
            ?>
        </div>

        <form action="../shared/send_msg.php" method="POST">
            <input type="hidden" name="lead_id" value="<?php echo $lead_id; ?>">
            <textarea name="message" placeholder="Add a note or update..." style="margin-bottom: 10px; width: 100%; height: 60px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            <button type="submit" class="btn">Add Update</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
