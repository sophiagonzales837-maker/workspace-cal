<?php 
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin'); 

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    session_write_close();
    header("Location: users.php");
    exit();
}

$id = (int)$_GET['id'];

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'remove') { 
        mysqli_query($conn, "DELETE FROM clients WHERE id = '$id'"); 
        session_write_close();
        header("Location: users.php?success=client_removed"); 
        exit(); 
    }
    if ($_POST['action'] == 'update_pw') {
        $pw = password_hash($_POST['new_pw'], PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE clients SET password = '$pw' WHERE id = '$id'");
        $success = "Client password updated successfully.";
    }
    if ($_POST['action'] == 'update_details') {
        $new_code = mysqli_real_escape_string($conn, trim($_POST['client_code']));
        $new_display = mysqli_real_escape_string($conn, trim($_POST['display_name']));
        
        mysqli_query($conn, "UPDATE clients SET client_code = '$new_code', display_name = '$new_display' WHERE id = '$id'");
        $success = "Client details updated successfully.";
    }
}

$c_res = mysqli_query($conn, "SELECT * FROM clients WHERE id = '$id'");
if (!$c_res || mysqli_num_rows($c_res) == 0) {
    die("<div style='padding: 20px; font-family: sans-serif;'><h3>Client not found.</h3><a href='users.php'>Return to Directory</a></div>");
}
$c = mysqli_fetch_assoc($c_res);

include 'includes/header.php'; 
?>

<div style="margin-bottom: 20px;">
    <a href="users.php" style="text-decoration: none; color: var(--electric-blue); font-weight: bold;">&larr; Back to Directory</a>
</div>

<div class="card" style="max-width: 600px; margin: auto;">
    <h3>Manage External Client: <?php echo htmlspecialchars($c['client_code']); ?></h3>
    
    <div style="background: #f0fdf4; border-left: 4px solid #22c55e; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px;">
        <p style="font-size: 13px; color: #166534; margin: 0; line-height: 1.5;">
            <strong>Note:</strong> This profile is managed in the CRM client directory.
        </p>
    </div>

    <?php if(isset($success)) echo "<p style='background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; font-weight: bold;'>✅ $success</p>"; ?>

    <form method="POST" style="margin-top: 20px; border-bottom:1px solid #eee; padding-bottom:15px;">
        <input type="hidden" name="action" value="update_details">
        <h4 style="margin-top: 0; margin-bottom: 15px; color: var(--primary-navy);">Update Client Details</h4>
        
        <div style="margin-bottom: 12px;">
            <label style="display:block; margin-bottom:5px;">Internal Client Code</label>
            <input type="text" name="client_code" value="<?php echo htmlspecialchars($c['client_code']); ?>" required style="width: 100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;">
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display:block; margin-bottom:5px;">Display Name</label>
            <input type="text" name="display_name" value="<?php echo htmlspecialchars($c['display_name'] ?? ''); ?>" required style="width: 100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box;">
        </div>
        
        <button type="submit" class="btn" style="background: var(--primary-navy);">Save Details</button>
    </form>
    
    <div style="display:grid; gap:20px; margin-top:20px;">
        <form method="POST" style="border-bottom:1px solid #eee; padding-bottom:15px;">
            <input type="hidden" name="action" value="update_pw">
            <label style="display:block; margin-bottom:5px;">Update Password</label>
            <div style="display:flex; gap:10px;">
                <input type="text" name="new_pw" placeholder="Enter new password" required style="flex:1; padding:10px; border:1px solid #ccc; border-radius:4px;">
                <button type="submit" class="btn">Update</button>
            </div>
        </form>
        
        <form method="POST" onsubmit="return confirm('🚨 WARNING: Are you sure you want to completely delete this external client? This will orphan any leads currently posted to them. This action CANNOT be undone.');">
            <input type="hidden" name="action" value="remove">
            <label style="display:block; margin-bottom:5px;">Danger Zone</label>
            <button type="submit" class="btn" style="background:#dc3545; width:100%;">Remove Client Forever</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>