<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../config/db.php';
require_once '../config/security.php';

// Allow ONLY Admins and Jr. Admins
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Jr_Admin', 'Jr Admin', 'Jr. Admin'])) {
    session_write_close();
    header("Location: ../login.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_id = trim($_POST['customer_id'] ?? '');

    if (!empty($search_id)) {
        $stmt = $conn->prepare("SELECT id, customer_id, customer_name FROM leads WHERE customer_id = ?");
        $stmt->bind_param("s", $search_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $lead = $result->fetch_assoc();
            $stmt->close();

            // Create a timed, signed payload (valid for 60 seconds)
            $auth_key = 'MakeMoney$$';
            $timestamp = time();
            $customer_id = $lead['customer_id'];
            
            // Signature prevents URL tampering
            $signature = hash_hmac('sha256', $customer_id . '|' . $timestamp, $auth_key);
            
            // Redirect to the live hosting auth receiver
            $portal_url = "https://pulaskikherkherlaw.info/customer_login.php?" . http_build_query([
                'action'       => 'admin_impersonate',
                'customer_id'  => $customer_id,
                'ts'           => $timestamp,
                'sig'          => $signature
            ]);

            header("Location: " . $portal_url);
            exit();
        } else {
            $error = "Customer ID not found. Please verify the ID and try again.";
            $stmt->close();
        }
    } else {
        $error = "Please enter a Customer ID.";
    }
}

include 'includes/header.php';
?>

<div class="card" style="max-width: 500px; margin: 60px auto; padding: 40px; text-align: center; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); background: #fff;">
    <div style="background: #e8f0fe; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;">
        <i class="fas fa-user-secret" style="font-size: 28px; color: #17a2b8;"></i>
    </div>

    <h2 style="color: #0A2540; margin-top: 0; margin-bottom: 10px;">Login As Customer</h2>
    <p style="color: #64748B; font-size: 14px; margin-bottom: 30px; line-height: 1.5;">
        Enter the Customer Login ID below to securely bypass the password check and view their portal exactly as they see it.
    </p>
    
    <?php if($error): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; font-weight: bold; border-left: 4px solid #dc3545;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="impersonate.php" style="margin: 0;">
        <input type="text" name="customer_id" placeholder="e.g. CAC-XXXXXXXX" required 
               style="width: 100%; padding: 15px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; margin-bottom: 20px; text-align: center; font-family: monospace; outline: none; box-sizing: border-box;">
                
        <button type="submit" class="btn" style="background: #17a2b8; color: white; width: 100%; padding: 15px; font-size: 16px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer;">
            Access Customer Portal
        </button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>