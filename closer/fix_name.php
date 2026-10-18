<?php
// Fix Script to populate missing customer_names
require_once '../config/db.php'; 
require_once '../config/security.php'; 

// Fetch all leads
$query = "SELECT id, first_name, last_name, customer_name FROM leads";
$result = $conn->query($query);

$updated_count = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // Decrypt the existing first and last names
        $first = decryptData($row['first_name']);
        $last = decryptData($row['last_name']);
        
        // If we successfully decrypted a name, but customer_name is empty/missing
        if (!empty($first)) {
            $full_name = trim($first . ' ' . $last);
            
            // Re-encrypt the combined full name
            $enc_full_name = encryptData($full_name);
            
            // Update the database
            $update_stmt = $conn->prepare("UPDATE leads SET customer_name = ? WHERE id = ?");
            $update_stmt->bind_param("si", $enc_full_name, $row['id']);
            
            if ($update_stmt->execute()) {
                $updated_count++;
            }
        }
    }
    echo "<div style='font-family: sans-serif; padding: 20px; text-align: center; margin-top: 50px;'>";
    echo "<h2 style='color: #28a745;'>Success!</h2>";
    echo "<p>Successfully generated and encrypted <strong>$updated_count</strong> missing customer names.</p>";
    echo "<a href='index.php' style='display: inline-block; padding: 10px 20px; background: #0A2540; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px;'>Return to Dashboard</a>";
    echo "</div>";
} else {
    echo "No leads found in the database.";
}
?>