<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Agent');

if (isset($_GET['ajax_check_phone'])) {
    header('Content-Type: application/json');
    $phone = trim((string)($_POST['phone'] ?? ''));
    if ($phone !== '' && strpos($phone, '+1') !== 0) {
        $phone = '+1' . ltrim($phone, ' 1+');
    }
    if (!preg_match('/^\+1\d{10}$/', $phone)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Enter a valid 10-digit phone number.']);
        exit;
    }
    $phone_hash = createBlindHash($phone);
    $stmt = $conn->prepare(
        "SELECT l.id, l.vertical, COALESCE(NULLIF(uc.username, ''), NULLIF(ua.username, ''), 'Unassigned') AS assigned_name
         FROM leads l
         LEFT JOIN users uc ON l.closer_id = uc.id
         LEFT JOIN users ua ON l.agent_id = ua.id
         WHERE l.phone_hash = ?
         ORDER BY l.id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not check for duplicate phone numbers.']);
        exit;
    }
    $stmt->bind_param('s', $phone_hash);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not check for duplicate phone numbers.']);
        $stmt->close();
        exit;
    }
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode($duplicate
        ? ['status' => 'duplicate', 'lead_id' => (int)$duplicate['id'], 'vertical' => $duplicate['vertical'] ?? '', 'assigned_name' => $duplicate['assigned_name']]
        : ['status' => 'clear']);
    exit;
}

// FIXED: Removed the invalid "status = 'active'" column check.
$closer_query = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'Closer' ORDER BY username ASC");
$active_closers = [];
if ($closer_query) {
    while ($row = mysqli_fetch_assoc($closer_query)) {
        $active_closers[] = $row;
    }
}

include 'includes/header.php';
?>

<style>
    :root {
        --primary-navy: #142030;
        --electric-blue: #3563A9;
        --brand-blue-hover: #264A82;
        --bg-light: #F4F7FA;
        --text-dark: #1E293B;
        --text-muted: #64748B;
        --white: #FFFFFF;
        --border-color: #E2E8F0;
        --input-bg: #F8FAFC;
    }

    .modern-card {
        background-color: var(--white);
        padding: 35px 40px;
        border-radius: 16px;
        margin-bottom: 25px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--border-color);
    }

    .form-header {
        font-size: 26px;
        color: var(--primary-navy);
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 15px;
        margin-top: 0;
        margin-bottom: 30px;
        font-weight: 800;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 24px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .modern-label {
        font-weight: 600;
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 8px;
        display: block;
        text-transform: uppercase;
    }

    .modern-input {
        width: 100%;
        padding: 14px 16px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 15px;
        background-color: var(--input-bg);
        box-sizing: border-box;
        transition: border-color 0.2s ease;
    }

    .modern-input:focus {
        outline: none;
        border-color: var(--electric-blue);
    }

    .btn-submit {
        background-color: var(--electric-blue);
        color: var(--white);
        border: none;
        padding: 16px;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 700;
        width: 100%;
        margin-top: 10px;
        transition: 0.2s ease;
    }

    .btn-submit:hover:not(:disabled) {
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
    }

    .autocomplete-items {
        position: absolute;
        border: 1px solid #d4d4d4;
        border-bottom: none;
        border-top: none;
        z-index: 99;
        top: 100%;
        left: 0;
        right: 0;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        max-height: 200px;
        overflow-y: auto;
    }

    .autocomplete-items div {
        padding: 10px;
        cursor: pointer;
        background-color: #1E293B;
        color: #F8FAFC;
        border-bottom: 1px solid #334155;
        font-size: 14px;
    }

    .autocomplete-items div:hover {
        background-color: #3563A9;
        color: #FFFFFF;
    }

    .duplicate-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 10000;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 23, 42, 0.7);
    }

    .duplicate-modal-content {
        width: min(100%, 520px);
        padding: 28px;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
    }

    .duplicate-modal-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        margin-top: 22px;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 10001;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(4px);
    }

    .modal-overlay.show {
        display: flex;
    }

    .modal-content {
        width: 100%;
        padding: 25px;
        border-radius: 12px;
        text-align: center;
    }
</style>

<div style="max-width: 900px; margin: 0 auto;">
    <div class="modern-card">
        <h3 class="form-header">Submit New Lead</h3>
        <form action="submit_lead.php" method="POST" id="leadForm">
            <input type="hidden" name="action_type" id="leadActionType" value="insert">
            <input type="hidden" name="duplicate_lead_id" id="duplicateLeadId" value="">
            <!-- Basics -->
            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">First Name</label>
                    <input type="text" name="first_name" id="first_name" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">Last Name</label>
                    <input type="text" name="last_name" id="last_name" class="modern-input">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">Phone Number <span>*</span></label>
                    <input type="text" id="phoneMaskInput" name="phone" class="modern-input" value="+1" maxlength="12" pattern="^\+1\d{10}$" required>
                </div>
                <div class="form-group">
                    <label class="modern-label">Date Of Birth</label>
                    <input type="text" id="dobMaskInput" name="dob" class="modern-input" placeholder="MM/DD/YYYY" maxlength="10">
                </div>
            </div>

            <!-- Email Addition -->
            <div class="form-grid" style="grid-template-columns: 1fr;">
                <div class="form-group">
                    <label class="modern-label">Email Address</label>
                    <input type="email" id="email" name="email" class="modern-input" placeholder="customer@example.com">
                </div>
            </div>

            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

            <!-- ADDRESS SECTION -->
            <h4 style="color: var(--primary-navy); margin-bottom: 20px;">Address Information</h4>

            <div class="form-group" style="margin-bottom: 15px;">
                <label class="modern-label">Address As Per ID</label>
                <input type="text" id="id_address_search" name="address" class="modern-input" placeholder="Start typing address..." autocomplete="off">
                <div id="autocomplete-list" class="autocomplete-items"></div>
            </div>

            <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr;">
                <div class="form-group">
                    <label class="modern-label">City</label>
                    <input type="text" id="id_city" name="city" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">State</label>
                    <input type="text" id="id_state" name="state" class="modern-input">
                </div>
                <div class="form-group">
                    <label class="modern-label">Zip Code</label>
                    <input type="text" id="id_zip" name="zip_code" class="modern-input">
                </div>
            </div>

            <!-- Current Address (Additional Details) -->
            <div style="padding: 20px; border-radius: 10px; margin-top: 20px; border: 1px solid #e2e8f0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <label class="modern-label" style="margin:0;">Current Address (If Different)</label>
                    <label style="font-size: 13px; font-weight: bold; cursor: pointer;">
                        <input type="checkbox" id="same_address_check" style="margin-right: 5px;"> Same as ID Address
                    </label>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <input type="text" id="current_address" name="additional_details" class="modern-input" placeholder="Start typing current address..." autocomplete="off">
                    <div id="autocomplete-list-current" class="autocomplete-items"></div>
                </div>
                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-bottom: 0;">
                    <div class="form-group">
                        <input type="text" id="current_city" class="modern-input" placeholder="City">
                    </div>
                    <div class="form-group">
                        <input type="text" id="current_state" class="modern-input" placeholder="State">
                    </div>
                    <div class="form-group">
                        <input type="text" id="current_zip" class="modern-input" placeholder="Zip">
                    </div>
                </div>
                <!-- Hidden field to compile full current address for DB -->
                <input type="hidden" id="final_additional_details" name="final_additional_details">
            </div>

            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

            <!-- Assignment -->
            <div class="form-grid">
                <div class="form-group">
                    <label class="modern-label">Agent Name</label>
                    <input type="text" name="agent_name" class="modern-input" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label class="modern-label">Select Closer</label>
                    <select name="closer_id" class="modern-input">
                        <option value="">-- Choose a Closer --</option>
                        <?php foreach ($active_closers as $closer): ?>
                            <option value="<?php echo $closer['id']; ?>"><?php echo htmlspecialchars($closer['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Button Group -->
            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                <button type="submit" class="btn-submit" onclick="compileCurrentAddress()">Route Lead</button>
                <button type="button" id="send_email_btn" class="btn-submit" style="background-color: #94a3b8; cursor: not-allowed;" disabled>
                    <i class="fas fa-envelope"></i> Send Email
                </button>
            </div>
        </form>
    </div>
</div>

<div class="duplicate-modal" id="duplicateModal" role="dialog" aria-modal="true" aria-labelledby="duplicateModalTitle">
    <div class="duplicate-modal-content">
        <h3 id="duplicateModalTitle" style="margin-top: 0; color: var(--primary-navy);">Possible Duplicate Lead</h3>
        <p id="duplicateModalMessage" style="color: #475569; line-height: 1.6;"></p>
        <div class="duplicate-modal-actions">
            <button type="button" class="btn-submit" id="mergeDuplicateButton">Merge / Update</button>
            <button type="button" class="btn-submit" id="createDuplicateButton" style="background: #475569;">Create Independent Duplicate</button>
            <button type="button" class="btn-submit" id="cancelDuplicateButton" style="background: #e2e8f0; color: #1e293b;">Cancel</button>
        </div>
    </div>
</div>

<!-- EMAIL TYPE SELECTION MODAL -->
<div id="emailTypeModal" class="modal-overlay">
    <div class="modal-content" style="background: #1E293B; border: 1px solid #334155; max-width: 420px;">
        <div style="width: 56px; height: 56px; background: rgba(56, 189, 248, 0.15); color: #38BDF8; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
            <i class="fas fa-envelope-open-text fa-lg"></i>
        </div>
        <h2 style="margin-top: 0; font-size: 20px; color: #F8FAFC;">Select Email Template</h2>
        <p style="color: #94A3B8; font-size: 14px; margin-bottom: 22px;">
            Which data pipeline are you dialing for this customer?
        </p>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <button type="button" onclick="executeSendCompanyEmail('raw')" class="btn-submit" style="background: #2563EB; color: #FFFFFF; margin-top: 0; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-bolt"></i> Raw Data (Fresh Lead Email)
            </button>
            <button type="button" onclick="executeSendCompanyEmail('timedata')" class="btn-submit" style="background: #10B981; color: #FFFFFF; margin-top: 0; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-history"></i> Time Data (Existing Customer Email)
            </button>
            <button type="button" onclick="closeEmailTypeModal()" style="background: none; border: none; color: #94A3B8; font-size: 13px; cursor: pointer; margin-top: 6px;">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
    // --- FORM VALIDATION: UNLOCK EMAIL BUTTON ---
    const fNameInput = document.getElementById('first_name');
    const lNameInput = document.getElementById('last_name');
    const emailInput = document.getElementById('email');
    const sendEmailBtn = document.getElementById('send_email_btn');
    const phoneInput = document.getElementById('phoneMaskInput');
    const dobInput = document.getElementById('dobMaskInput');

    phoneInput.addEventListener('input', function() {
        let digits = this.value.replace(/\D/g, '');
        if (digits.startsWith('1')) digits = digits.substring(1);
        this.value = '+1' + digits.substring(0, 10);
    });

    phoneInput.addEventListener('keydown', function(event) {
        if ((event.key === 'Backspace' || event.key === 'Delete') && this.selectionStart <= 2) {
            event.preventDefault();
        }
    });

    dobInput.addEventListener('input', function() {
        const digits = this.value.replace(/\D/g, '').substring(0, 8);
        let formatted = digits.substring(0, 2);
        if (digits.length >= 2) formatted += '/' + digits.substring(2, 4);
        if (digits.length >= 4) formatted += '/' + digits.substring(4, 8);

        const month = parseInt(digits.substring(0, 2), 10);
        const day = parseInt(digits.substring(2, 4), 10);
        if (digits.length >= 2 && month > 12) formatted = '12' + formatted.substring(2);
        if (digits.length >= 4 && day > 31) formatted = formatted.substring(0, 3) + '31' + formatted.substring(5);
        this.value = formatted;
    });

    function validateEmailPrerequisites() {
        const isReady = fNameInput.value.trim() !== '' &&
            lNameInput.value.trim() !== '' &&
            emailInput.value.trim() !== '' &&
            emailInput.checkValidity(); // Validates standard email format

        if (isReady) {
            sendEmailBtn.disabled = false;
            sendEmailBtn.style.backgroundColor = '#10b981'; // Green active color
            sendEmailBtn.style.cursor = 'pointer';
        } else {
            sendEmailBtn.disabled = true;
            sendEmailBtn.style.backgroundColor = '#94a3b8'; // Disabled grey
            sendEmailBtn.style.cursor = 'not-allowed';
        }
    }

    // Attach listeners to trigger validation check on input
    fNameInput.addEventListener('input', validateEmailPrerequisites);
    lNameInput.addEventListener('input', validateEmailPrerequisites);
    emailInput.addEventListener('input', validateEmailPrerequisites);

    const leadForm = document.getElementById('leadForm');
    const duplicateModal = document.getElementById('duplicateModal');
    const duplicateModalMessage = document.getElementById('duplicateModalMessage');
    const leadActionType = document.getElementById('leadActionType');
    const duplicateLeadId = document.getElementById('duplicateLeadId');
    const leadSubmitButton = leadForm.querySelector('button[type="submit"]');

    function submitLeadForm(actionType, leadId = '') {
        leadActionType.value = actionType;
        duplicateLeadId.value = leadId;
        duplicateModal.style.display = 'none';
        leadSubmitButton.disabled = true;
        leadSubmitButton.innerHTML = "Submitting... <i class='fas fa-spinner fa-spin'></i>";
        leadSubmitButton.style.cursor = 'not-allowed';
        leadForm.submit();
    }

    leadForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        compileCurrentAddress();
        leadSubmitButton.disabled = true;
        leadSubmitButton.innerHTML = "Checking phone... <i class='fas fa-spinner fa-spin'></i>";
        leadSubmitButton.style.cursor = 'not-allowed';

        try {
            const response = await fetch('index.php?ajax_check_phone=1', {
                method: 'POST',
                body: new URLSearchParams({ phone: phoneInput.value })
            });
            if (!response.ok) throw new Error('Phone check failed with status ' + response.status);
            const result = await response.json();

            if (result.status === 'duplicate') {
                duplicateLeadId.value = result.lead_id;
                duplicateModalMessage.textContent =
                    'This information already exists. Please confirm with the previous Closer/Agent: ' +
                    result.assigned_name + (result.vertical ? ' (Vertical: ' + result.vertical + ')' : '');
                duplicateModal.style.display = 'flex';
                leadSubmitButton.disabled = false;
                leadSubmitButton.innerHTML = 'Route Lead';
                leadSubmitButton.style.cursor = 'pointer';
                return;
            }
            if (result.status !== 'clear') throw new Error(result.message || 'The phone check returned an unexpected response.');
            submitLeadForm('insert');
        } catch (error) {
            alert('Unable to check for duplicate phone numbers. Please try again. ' + error.message);
            leadSubmitButton.disabled = false;
            leadSubmitButton.innerHTML = 'Route Lead';
            leadSubmitButton.style.cursor = 'pointer';
        }
    });

    document.getElementById('mergeDuplicateButton').addEventListener('click', function() {
        submitLeadForm('update', duplicateLeadId.value);
    });
    document.getElementById('createDuplicateButton').addEventListener('click', function() {
        submitLeadForm('insert');
    });
    document.getElementById('cancelDuplicateButton').addEventListener('click', function() {
        duplicateModal.style.display = 'none';
        leadSubmitButton.disabled = false;
        leadSubmitButton.innerHTML = 'Route Lead';
        leadSubmitButton.style.cursor = 'pointer';
    });

    const emailTypeModal = document.getElementById('emailTypeModal');

    function closeEmailTypeModal() {
        emailTypeModal.classList.remove('show');
    }

    function executeSendCompanyEmail(emailType) {
        if (emailType !== 'raw' && emailType !== 'timedata') return;
        closeEmailTypeModal();

        const originalText = sendEmailBtn.innerHTML;
        sendEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        sendEmailBtn.disabled = true;
        sendEmailBtn.style.backgroundColor = '#94a3b8';

        const agentNameInput = document.querySelector('input[name="agent_name"]').value;
        const endpoint = emailType === 'raw'
            ? 'api_send_raw_data_info.php'
            : 'api_send_company_info.php';
        const emailTypeLabel = emailType === 'raw' ? 'Raw Data' : 'Time Data';

        fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    first_name: fNameInput.value.trim(),
                    last_name: lNameInput.value.trim(),
                    email: emailInput.value.trim(),
                    agent_name: agentNameInput
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(emailTypeLabel + ' email sent successfully to ' + emailInput.value);
                    sendEmailBtn.innerHTML = '<i class="fas fa-check"></i> Sent Successfully';
                    sendEmailBtn.style.backgroundColor = '#10b981';

                    setTimeout(() => {
                        sendEmailBtn.innerHTML = originalText;
                        validateEmailPrerequisites();
                    }, 3000);
                } else {
                    alert('Error: ' + data.message);
                    sendEmailBtn.innerHTML = originalText;
                    validateEmailPrerequisites();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('A network error occurred while sending the email.');
                sendEmailBtn.innerHTML = originalText;
                validateEmailPrerequisites();
            });
    }

    sendEmailBtn.addEventListener('click', function() {
        if (!this.disabled) emailTypeModal.classList.add('show');
    });

    // --- REUSABLE OPENSTREETMAP AUTOCOMPLETE API ---
    function setupAddressAutocomplete(inputId, listId, cityId, stateId, zipId) {
        const inputField = document.getElementById(inputId);
        const autocompleteList = document.getElementById(listId);
        let typingTimer;

        inputField.addEventListener('input', function() {
            clearTimeout(typingTimer);
            autocompleteList.innerHTML = '';
            const val = this.value;
            if (!val || val.length < 3) return;

            typingTimer = setTimeout(() => {
                fetch(`https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&countrycodes=us&q=${encodeURIComponent(val)}`)
                    .then(response => response.json())
                    .then(data => {
                        autocompleteList.innerHTML = '';
                        data.forEach(place => {
                            const item = document.createElement('div');
                            item.innerHTML = place.display_name;
                            item.addEventListener('click', function() {
                                const addr = place.address;
                                const street = (addr.house_number ? addr.house_number + ' ' : '') + (addr.road || '');

                                inputField.value = street || place.name || '';
                                document.getElementById(cityId).value = addr.city || addr.town || addr.village || '';
                                document.getElementById(stateId).value = addr.state || '';
                                document.getElementById(zipId).value = addr.postcode || '';

                                autocompleteList.innerHTML = '';
                            });
                            autocompleteList.appendChild(item);
                        });
                    });
            }, 500);
        });

        // Close autocomplete when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target !== inputField) autocompleteList.innerHTML = '';
        });
    }

    // Initialize Autocomplete for BOTH Address Blocks
    setupAddressAutocomplete('id_address_search', 'autocomplete-list', 'id_city', 'id_state', 'id_zip');
    setupAddressAutocomplete('current_address', 'autocomplete-list-current', 'current_city', 'current_state', 'current_zip');

    // --- ADDRESS SYNC CHECKBOX ---
    document.getElementById('same_address_check').addEventListener('change', function() {
        const isChecked = this.checked;
        const fields = ['current_address', 'current_city', 'current_state', 'current_zip'];

        fields.forEach(id => {
            const el = document.getElementById(id);
            el.readOnly = isChecked;
            if (isChecked) {
                el.value = '';
                el.style.backgroundColor = '#e2e8f0';
            } else {
                el.style.backgroundColor = '#f1f5f9';
            }
        });
    });

    // --- COMPILE ADDITIONAL DETAILS BEFORE SUBMIT ---
    function compileCurrentAddress() {
        const isSame = document.getElementById('same_address_check').checked;
        if (isSame) {
            document.getElementById('final_additional_details').value = '';
        } else {
            const addr = document.getElementById('current_address').value;
            const city = document.getElementById('current_city').value;
            const state = document.getElementById('current_state').value;
            const zip = document.getElementById('current_zip').value;

            if (addr || city || state || zip) {
                document.getElementById('final_additional_details').value = `${addr}, ${city}, ${state} ${zip}`;
            }
        }
    }
</script>

<?php include 'includes/footer.php'; ?>