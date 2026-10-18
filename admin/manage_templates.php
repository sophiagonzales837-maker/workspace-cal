<?php
// Securely connect to the database
require_once '../config/db.php';
require_once '../config/security.php';
protect('Admin');
require_once '../shared/criteria_storage.php';

try {
    workspace_ensure_criteria_schema($conn, true);
} catch (Throwable $error) {
    error_log('[Templates] Criteria schema initialization failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Unable to load application templates.');
}

// ---------------------------------------------------------
// API HANDLER (For fetching template data via JS)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_template') {
    header('Content-Type: application/json');
    $case_name = $_GET['case_name'] ?? '';

    $stmt = $conn->prepare("SELECT document_body, qa_section FROM generator_templates WHERE case_name = ?");
    $stmt->bind_param("s", $case_name);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    echo json_encode($result ?: ['document_body' => '', 'qa_section' => '']);
    exit;
}

// ---------------------------------------------------------
// FORM SUBMISSION HANDLER (Saving the template)
// ---------------------------------------------------------
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $case_name = $_POST['case_name'];
    $document_body = $_POST['document_body'];
    $qa_section = $_POST['qa_section'];

    // Insert or update if it already exists
    $stmt = $conn->prepare("INSERT INTO generator_templates (case_name, document_body, qa_section) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE document_body = VALUES(document_body), qa_section = VALUES(qa_section)");
    $stmt->bind_param("sss", $case_name, $document_body, $qa_section);

    if ($stmt->execute()) {
        $success_msg = "Template updated successfully for <strong>" . htmlspecialchars($case_name) . "</strong>!";
    } else {
        $error_msg = "Failed to update template: " . $conn->error;
    }
}

// ---------------------------------------------------------
// FETCH VISIBLE VERTICALS & AUTO-INJECT POA VARIANTS
// ---------------------------------------------------------
$cases = [];
$result = $conn->query("SELECT vertical_name AS case_name FROM verticals WHERE is_hidden = 0 ORDER BY sort_order ASC, id ASC");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $base_case = $row['case_name'];
        if (!in_array($base_case, $cases)) {
            $cases[] = $base_case;
        }

        // AUTO-INHERIT LOGIC: Mirror the Closer API so Jr. Admin sees the POA options
        $lowerCase = strtolower($base_case);
        if ((strpos($lowerCase, 'abuse') !== false || strpos($lowerCase, 'roblox') !== false) && strpos($lowerCase, 'poa') === false) {
            $poa_case = trim($base_case) . ' (POA)';
            if (!in_array($poa_case, $cases)) {
                $cases[] = $poa_case;
            }
        }
    }
}

// Keep templates selectable even when their case is no longer in the active matrix.
$template_cases = $conn->query("SELECT DISTINCT case_name FROM generator_templates");
if ($template_cases) {
    while ($row = $template_cases->fetch_assoc()) {
        $template_case = trim((string)$row['case_name']);
        if ($template_case !== '' && !in_array($template_case, $cases, true)) {
            $cases[] = $template_case;
        }
    }
}

// Sort alphabetically so the POA versions group nicely next to their parent cases
sort($cases);

if (!defined('MANAGE_TEMPLATES_NATIVE_INCLUDE')) {
    include 'includes/header.php';
}
?>

<script src="https://cdn.tailwindcss.com"></script>

<div class="p-6 text-gray-800">
    <div class="max-w-5xl mx-auto bg-white rounded-lg shadow-md p-8 border-t-4 border-blue-900">
        <h1 class="text-2xl font-bold mb-2 text-blue-900">Manage Application Templates</h1>
        <p class="text-gray-500 mb-6">Create or edit the text templates used by closers in the Application Generator.</p>

        <?php if ($success_msg): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                <?= $success_msg ?>
            </div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="template-form">
            <div class="mb-6">
                <label class="block text-sm font-bold text-gray-700 mb-2">Select Case to Edit</label>
                <select name="case_name" id="case-select" class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50 focus:outline-none focus:border-blue-900 focus:ring-1 focus:ring-blue-900" required>
                    <option value="">-- Choose a Case --</option>
                    <?php foreach ($cases as $case): ?>
                        <option value="<?= htmlspecialchars($case) ?>" <?= (isset($_POST['case_name']) && $_POST['case_name'] === $case) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($case) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="editor-section" class="<?= isset($_POST['case_name']) ? '' : 'hidden' ?> space-y-6">

                <div class="bg-blue-50 p-4 rounded-lg border border-blue-100 text-sm">
                    <h4 class="font-bold text-blue-900 mb-2">Available Placeholders</h4>
                    <p class="text-gray-700">Use these exact tags in your templates. They will be automatically replaced with the client's information when generated.</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2 font-mono text-xs">
                        <span>{{name}}</span>
                        <span>{{phone}}</span>
                        <span>{{dob}}</span>
                        <span>{{email}}</span>
                        <span>{{address}}</span>
                        <span>{{city}}</span>
                        <span>{{state}}</span>
                        <span>{{zip}}</span>
                        <span>{{incident_date}}</span>
                        <span>{{driver_name}}</span>
                        <span>{{abuser_name}}</span>
                        <span>{{facility}}</span>
                        <span class="text-blue-700 font-bold">{{child_name}}</span>
                        <span class="text-blue-700 font-bold">{{child_dob}}</span>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Application Body</label>
                    <textarea name="document_body" id="document_body" rows="12" class="w-full p-4 border border-gray-300 rounded-lg font-mono text-sm bg-gray-50 focus:outline-none focus:border-blue-900 focus:ring-1 focus:ring-blue-900" required><?= isset($_POST['document_body']) ? htmlspecialchars($_POST['document_body']) : '' ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">Q&A Section</label>
                    <textarea name="qa_section" id="qa_section" rows="10" class="w-full p-4 border border-gray-300 rounded-lg font-mono text-sm bg-gray-50 focus:outline-none focus:border-blue-900 focus:ring-1 focus:ring-blue-900" required><?= isset($_POST['qa_section']) ? htmlspecialchars($_POST['qa_section']) : '' ?></textarea>
                </div>

                <button type="submit" name="save_template" class="w-full bg-blue-900 text-white font-bold py-3 rounded-lg hover:bg-blue-800 transition shadow-md">
                    Save Template
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('case-select').addEventListener('change', function(e) {
        const caseName = e.target.value;
        const editorSection = document.getElementById('editor-section');

        if (!caseName) {
            editorSection.classList.add('hidden');
            return;
        }

        // Fetch the existing template for this case
        fetch(`manage_templates.php?action=get_template&case_name=${encodeURIComponent(caseName)}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('document_body').value = data.document_body || '';
                document.getElementById('qa_section').value = data.qa_section || '';
                editorSection.classList.remove('hidden');
            })
            .catch(err => console.error('Error fetching template:', err));
    });
</script>

<?php if (!defined('MANAGE_TEMPLATES_NATIVE_INCLUDE')) include 'includes/footer.php'; ?>