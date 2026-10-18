<?php
// FILE: admin/manage_criteria_sol.php
require_once '../config/db.php';
require_once '../config/security.php';

protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

$success = "";
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$buyer_id = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_criteria'])) {
    $v = trim($_POST['vertical']);
    $cid = (int)$_POST['target_client_id'];
    $bid = !empty($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : null;
    $sol = isset($_POST['sol']) ? 1 : 0;
    $bad = trim($_POST['bad_states']);
    $age = trim($_POST['age']);
    $ques = trim($_POST['questions']);
    $other = trim($_POST['other']);
    $active = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO client_criteria_matrix 
        (client_id, buyer_id, vertical_name, sol_applicable, bad_states, age_criteria, qualifying_questions, other_criteria, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        sol_applicable=?, bad_states=?, age_criteria=?, qualifying_questions=?, other_criteria=?, is_active=?");
    
    $stmt->bind_param("iisissssiissssi", 
        $cid, $bid, $v, $sol, $bad, $age, $ques, $other, $active, 
        $sol, $bad, $age, $ques, $other, $active
    );
    $stmt->execute();
    exit("Saved");
}

include 'includes/header.php';
?>
<div class="card" style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <h2>Live Client Criteria Matrix</h2>
    <form method="GET" style="margin-bottom: 20px;">
        <select name="client_id" style="padding: 10px; border-radius: 6px; width: 250px;" onchange="this.form.submit()">
            <option value="">-- Select Client --</option>
            <?php
            $cl_q = $conn->query("SELECT id, client_code FROM clients ORDER BY client_code ASC");
            while ($cl = $cl_q->fetch_assoc()): ?>
                <option value="<?php echo $cl['id']; ?>" <?php echo $client_id == $cl['id'] ? 'selected' : ''; ?>><?php echo $cl['client_code']; ?></option>
            <?php endwhile; ?>
        </select>
    </form>

    <?php if ($client_id): ?>
    <div style="overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Vertical</th>
                    <th>Buyer</th>
                    <th>Bad States</th>
                    <th>Age / DX</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $res = $conn->query("SELECT ccm.*, COALESCE(b.buyer_name, 'Direct') as buyer_name FROM client_criteria_matrix ccm LEFT JOIN buyers b ON ccm.buyer_id = b.id WHERE ccm.client_id = $client_id ORDER BY b.buyer_name ASC, ccm.vertical_name ASC");
                while ($d = $res->fetch_assoc()): ?>
                    <tr style="background: <?php echo $d['is_active'] ? '#e8f5e9' : '#f5f5f5'; ?>">
                        <td><strong><?php echo htmlspecialchars($d['vertical_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['buyer_name']); ?></td>
                        <td><textarea style="width: 100%;" class="save-trigger bad_states"><?php echo htmlspecialchars($d['bad_states']); ?></textarea></td>
                        <td><textarea style="width: 100%;" class="save-trigger age"><?php echo htmlspecialchars($d['age_criteria']); ?></textarea></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
