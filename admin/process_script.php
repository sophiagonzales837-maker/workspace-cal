<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// ==========================================
// ROLE SECURITY CHECK
// ==========================================
$allowed_roles = ['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    session_write_close();
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $script_type = $_POST['script_type'] ?? '';
    $script_content = $_POST['script_content'] ?? '';

    // Define allowed script types and their corresponding file paths
    $allowed_scripts = [
        'agent'        => '../scripts/agent_script.txt',
        'closer'       => '../scripts/closer_script.txt',
        'verifier'     => '../scripts/verifier_script.txt',
        'closer_links' => '../scripts/closer_links.txt',
        'genuine'      => '../scripts/genuine.txt' // <-- NEW: Added Genuine Script Mapping
    ];

    // Check if the submitted script type is valid
    if (array_key_exists($script_type, $allowed_scripts)) {
        
        $file_path = $allowed_scripts[$script_type];
        $dir_path = dirname($file_path);

        // Ensure the directory exists
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0777, true);
        }

        // Attempt to save the file
        if (file_put_contents($file_path, $script_content) !== false) {
            $formatted_name = ucwords(str_replace('_', ' ', $script_type));
            $_SESSION['script_message'] = "$formatted_name saved successfully!";
        } else {
            $_SESSION['script_error'] = "Failed to save the script. Please check folder permissions.";
        }
    } else {
        // This is the error you were seeing!
        $_SESSION['script_error'] = "Invalid script type."; 
    }

    // Redirect back to the script manager page
    session_write_close();
    
    // Make sure this matches the exact name of your frontend file (manage_script.php or manage_scripts.php)
    header("Location: manage_scripts.php"); 
    exit();
} else {
    // If accessed directly without posting, send them back
    header("Location: manage_scripts.php");
    exit();
}
?>