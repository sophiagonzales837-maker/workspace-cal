<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// ==========================================
// ROLE SECURITY CHECK
// ==========================================
protect(['Admin', 'Jr. Admin', 'Jr Admin', 'Jr_Admin']);

$success_message = '';
$error_message = '';

if (isset($_SESSION['script_message'])) {
    $success_message = $_SESSION['script_message'];
    unset($_SESSION['script_message']);
}
if (isset($_SESSION['script_error'])) {
    $error_message = $_SESSION['script_error'];
    unset($_SESSION['script_error']);
}
?>
<?php include 'includes/header.php'; ?>
<style>
    .script-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 24px;
        padding-bottom: 50px;
        background: #0F172A;
        border: 1px solid #334155;
        border-radius: 12px;
    }

    .tab-nav {
        display: flex;
        border-bottom: 2px solid #334155;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 5px;
    }

    .tab-button {
        padding: 15px 20px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-weight: 700;
        font-size: 15px;
        color: #94A3B8;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: 0.2s;
    }

    .tab-button:hover {
        color: var(--primary-navy);
    }

    .tab-button.active {
        color: #F8FAFC;
        border-bottom-color: var(--electric-blue);
    }

    .script-container label {
        color: #E2E8F0 !important;
    }

    .tab-content {
        display: none;
        background: #0B1220;
        color: #F8FAFC;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.25);
        padding: 30px;
        border: 1px solid #475569;
    }

    .tab-content.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .script-textarea {
        width: 100%;
        height: 60vh;
        min-height: 400px;
        padding: 20px;
        font-family: 'Courier New', Courier, monospace;
        font-size: 14px;
        line-height: 1.6;
        color: #F8FAFC;
        background: #030712;
        border: 1px solid #334155;
        border-radius: 8px;
        resize: vertical;
        box-sizing: border-box;
        outline: none;
        transition: border-color 0.2s;
    }

    .script-textarea:focus {
        border-color: #2563EB;
        background: #030712;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
    }

    .save-btn {
        background: #2563EB;
        color: #FFFFFF;
        border: none;
        padding: 12px 25px;
        border-radius: 8px;
        font-weight: bold;
        font-size: 16px;
        cursor: pointer;
        margin-top: 20px;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .save-btn:hover {
        background: #1D4ED8;
        transform: translateY(-1px);
    }
</style>
<div class="script-container">
    <h1 style="font-size: 28px; color: var(--primary-navy); margin-bottom: 5px;">Manage Call Scripts</h1>
    <p style="color: #64748b; margin-bottom: 30px;">Update the reference scripts and links available to the team.</p>

    <?php if ($success_message): ?>
        <div style="background: #dcfce3; border-left: 4px solid #10b981; color: #166534; padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; font-weight: 500;">
            <i class="fas fa-check-circle" style="margin-right: 8px;"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div style="background: #fee2e2; border-left: 4px solid #ef4444; color: #991b1b; padding: 15px 20px; border-radius: 6px; margin-bottom: 25px; font-weight: 500;">
            <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="tab-nav">
        <button class="tab-button active" onclick="switchTab('agent', this)"><i class="fas fa-headset"></i> Agent Script</button>
        <button class="tab-button" onclick="switchTab('closer', this)"><i class="fas fa-user-tie"></i> Closer Script</button>
        <button class="tab-button" onclick="switchTab('verifier', this)"><i class="fas fa-check-double"></i> Verifier Script</button>
        <button class="tab-button" onclick="switchTab('genuine', this)"><i class="fas fa-shield-check"></i> Genuine Script</button>
        <button class="tab-button" onclick="switchTab('closer_links', this)"><i class="fas fa-link"></i> Closer Links</button>
    </div>

    <!-- 1. Agent Script Tab -->
    <div id="agent" class="tab-content active">
        <form method="POST" action="process_script.php">
            <input type="hidden" name="script_type" value="agent">
            <label style="display: block; font-weight: bold; color: #334155; margin-bottom: 10px; font-size: 14px;">Agent Call Script Editor</label>
            <textarea name="script_content" class="script-textarea" spellcheck="false"><?php
                                                                                        $agent_script_file = '../scripts/agent_script.txt';
                                                                                        if (file_exists($agent_script_file)) echo htmlspecialchars(file_get_contents($agent_script_file));
                                                                                        ?></textarea>
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Agent Script</button>
        </form>
    </div>

    <!-- 2. Closer Script Tab -->
    <div id="closer" class="tab-content">
        <form method="POST" action="process_script.php">
            <input type="hidden" name="script_type" value="closer">
            <label style="display: block; font-weight: bold; color: #334155; margin-bottom: 10px; font-size: 14px;">Closer Call Script Editor</label>
            <textarea name="script_content" class="script-textarea" spellcheck="false"><?php
                                                                                        $closer_script_file = '../scripts/closer_script.txt';
                                                                                        if (file_exists($closer_script_file)) echo htmlspecialchars(file_get_contents($closer_script_file));
                                                                                        ?></textarea>
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Closer Script</button>
        </form>
    </div>

    <!-- 3. Verifier Script Tab -->
    <div id="verifier" class="tab-content">
        <form method="POST" action="process_script.php">
            <input type="hidden" name="script_type" value="verifier">
            <label style="display: block; font-weight: bold; color: #334155; margin-bottom: 10px; font-size: 14px;">Verifier Call Script Editor</label>
            <textarea name="script_content" class="script-textarea" spellcheck="false"><?php
                                                                                        $verifier_script_file = '../scripts/verifier_script.txt';
                                                                                        if (file_exists($verifier_script_file)) echo htmlspecialchars(file_get_contents($verifier_script_file));
                                                                                        ?></textarea>
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Verifier Script</button>
        </form>
    </div>

    <!-- 4. Genuine Script Tab -->
    <div id="genuine" class="tab-content">
        <form method="POST" action="process_script.php">
            <!-- Note: Ensure your process_script.php maps value="genuine" to genuine.txt -->
            <input type="hidden" name="script_type" value="genuine">
            <label style="display: block; font-weight: bold; color: #334155; margin-bottom: 10px; font-size: 14px;">Genuine Call Script Editor</label>
            <textarea name="script_content" class="script-textarea" spellcheck="false"><?php
                                                                                        $genuine_script_file = '../scripts/genuine.txt';
                                                                                        if (file_exists($genuine_script_file)) echo htmlspecialchars(file_get_contents($genuine_script_file));
                                                                                        ?></textarea>
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Genuine Script</button>
        </form>
    </div>

    <!-- 5. Closer Links Tab -->
    <div id="closer_links" class="tab-content">
        <form method="POST" action="process_script.php">
            <input type="hidden" name="script_type" value="closer_links">
            <label style="display: block; font-weight: bold; color: #334155; margin-bottom: 10px; font-size: 14px;">Closer Links Editor</label>
            <textarea name="script_content" class="script-textarea" spellcheck="false"><?php
                                                                                        $closer_links_file = '../scripts/closer_links.txt';
                                                                                        if (file_exists($closer_links_file)) echo htmlspecialchars(file_get_contents($closer_links_file));
                                                                                        ?></textarea>
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Closer Links</button>
        </form>
    </div>

</div>
<script>
    function switchTab(tabName, element) {
        // Hide all contents
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        // Remove active state from all buttons
        document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));

        // Show selected content and add active state to clicked button
        document.getElementById(tabName).classList.add('active');
        if (element) element.classList.add('active');
    }
</script>

<?php include 'includes/footer.php'; ?>