<?php
require_once '../config/db.php';
require_once '../config/security.php'; 
protect('Closer');

function workspace_trigger_lead_event($event, array $payload) {
    $pusher = workspace_pusher_server();
    if ($pusher) {
        $pusher->trigger('lead-updates-channel', $event, $payload);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $lead_id = (int)$_POST['lead_id'];
    $raw_quick_update = trim($_POST['quick_update'] ?? '');
    $quick_update = mysqli_real_escape_string($conn, $raw_quick_update);
    $closer_id = $_SESSION['user_id'];

    // Verify that the Closer actually owns this lead
    $check = mysqli_query($conn, "SELECT id FROM leads WHERE id = '$lead_id' AND closer_id = '$closer_id'");
    
    if(mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "UPDATE leads SET quick_update = '$quick_update' WHERE id = '$lead_id'");
        workspace_trigger_lead_event('lead-note-added', [
            'lead_id' => $lead_id,
            'note' => $raw_quick_update,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

$redirect_url = "index.php";
if (!empty($_POST['query_string'])) {
    $redirect_url .= "?" . $_POST['query_string'];
}

session_write_close();
header("Location: $redirect_url");
exit();
?>
