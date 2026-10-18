<?php
require_once '../config/db.php';
require_once '../config/security.php';
protect('Closer');

$is_popup = isset($_GET['popup']) && $_GET['popup'] === '1';

$lead = [];
if (isset($_GET['id'])) {
    $lead_id = (int)$_GET['id'];
    $q = mysqli_query($conn, "SELECT * FROM leads WHERE id = '$lead_id'");
    if ($row = mysqli_fetch_assoc($q)) {
        $lead = decrypt_fields($row);
    }
}

include 'includes/header.php';
if (!$is_popup && file_exists(__DIR__ . '/includes/sidebar.php')) {
    include 'includes/sidebar.php';
}
?>

<style>
    :root {
        --primary-navy: #142030;
        --electric-blue: #3563A9;
        --brand-blue-hover: #264A82;
        --bg-light: #F4F7FA;
        --white: #FFFFFF;
        --text-dark: #1E293B;
        --text-muted: #64748B;
        --border-color: #E2E8F0;
        --input-bg: #F8FAFC;
    }

    .generator-container {
        max-width: 1000px;
        margin: 30px auto;
        background: #fff;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border-top: 4px solid var(--primary-navy);
    }

    .gen-title {
        font-size: 24px;
        font-weight: bold;
        color: var(--primary-navy);
        margin-bottom: 25px;
        margin-top: 0;
    }

    .step-section {
        margin-top: 35px;
        border-top: 1px solid var(--border-color);
        padding-top: 30px;
    }

    .step-title {
        font-size: 18px;
        font-weight: bold;
        color: var(--primary-navy);
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .grid-2 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .col-span-2 {
        grid-column: 1 / -1;
    }

    .form-label {
        display: block;
        font-size: 12px;
        font-weight: bold;
        color: #64748B;
        margin-bottom: 6px;
        text-transform: uppercase;
    }

    .input-style {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-light);
        font-size: 14px;
        box-sizing: border-box;
        transition: 0.2s;
    }

    .input-style:focus {
        background: #fff;
        border-color: var(--primary-navy);
        outline: none;
    }

    .btn {
        padding: 14px 25px;
        border: none;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.2s;
        font-size: 15px;
        text-align: center;
    }

    .btn-primary {
        background: var(--primary-navy);
        color: #fff;
    }

    .btn-primary:hover:not(:disabled) {
        background: #1a365d;
    }

    .btn-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-secondary {
        background: var(--border-color);
        color: #1e293b;
    }

    .btn-secondary:hover {
        background: #cbd5e1;
    }

    .btn-success {
        background: #15803d;
        color: #fff;
    }

    .btn-success:hover {
        background: #166534;
    }

    .btn-danger {
        background: #b91c1c;
        color: #fff;
    }

    .btn-danger:hover {
        background: #991b1b;
    }

    .hidden {
        display: none !important;
    }

    .alert-danger {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 15px;
        border-radius: 8px;
        font-weight: bold;
        margin-bottom: 20px;
    }

    .info-box {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .info-box h4 {
        margin: 0 0 5px 0;
        color: var(--primary-navy);
        font-size: 16px;
    }

    .info-box p {
        margin: 0;
        font-size: 13px;
        color: #475569;
    }

    .dynamic-fields {
        background: var(--bg-light);
        padding: 20px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        margin-top: 20px;
    }

    .flex-gap {
        display: flex;
        gap: 15px;
        margin-top: 25px;
    }

    textarea.input-style {
        font-family: monospace;
        min-height: 200px;
        resize: vertical;
        background: #f8fafc;
    }
</style>

<div class="generator-container">
    <h1 class="gen-title">Application Generator</h1>

    <!-- STEP 1: Basic Info -->
    <div id="step-1">
        <h3 class="step-title">Step 1: Customer Information</h3>
        <div class="grid-2">
            <div><label class="form-label">First Name</label><input type="text" id="fname" value="<?= htmlspecialchars($lead['first_name'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">Last Name</label><input type="text" id="lname" value="<?= htmlspecialchars($lead['last_name'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">Phone</label><input type="text" id="phone" value="<?= htmlspecialchars($lead['phone'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">Email</label><input type="email" id="email" value="<?= htmlspecialchars($lead['email'] ?? '') ?>" class="input-style"></div>
            <div class="col-span-2"><label class="form-label">Address</label><input type="text" id="address" value="<?= htmlspecialchars($lead['address'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">City</label><input type="text" id="city" value="<?= htmlspecialchars($lead['city'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">State Code (e.g. TX)</label><input type="text" id="state" maxlength="2" value="<?= htmlspecialchars($lead['state'] ?? '') ?>" class="input-style" style="text-transform: uppercase;"></div>
            <div><label class="form-label">Zip Code</label><input type="text" id="zip" value="<?= htmlspecialchars($lead['zip_code'] ?? '') ?>" class="input-style"></div>
            <div><label class="form-label">Date of Birth</label><input type="date" id="dob" value="<?= htmlspecialchars($lead['dob'] ?? '') ?>" class="input-style"></div>
        </div>
        <div style="margin-top: 25px;">
            <button onclick="checkEligibility()" id="eligibility-btn" class="btn btn-primary">Check Eligibility &rarr;</button>
        </div>
    </div>

    <!-- STEP 2: Campaign & Details -->
    <div id="step-2" class="step-section hidden">
        <h3 class="step-title">Step 2: Eligible Claims & Details</h3>
        <div id="no-eligibility-msg" class="alert-danger hidden">This customer does not meet the criteria.</div>
        <div id="eligibility-forms" class="hidden grid-2">
            <div><label class="form-label">Eligible Case</label><select id="case-select" class="input-style"></select></div>
            <div><label class="form-label">Client Code</label><select id="client-select" class="input-style" disabled>
                    <option>Select Case First</option>
                </select></div>
        </div>
        <div id="dynamic-fields" class="grid-2 dynamic-fields hidden"></div>
        <div class="flex-gap">
            <button onclick="backToStep(1)" class="btn btn-secondary">&larr; Back</button>
            <button onclick="findProviders()" id="find-provider-btn" disabled class="btn btn-primary">Search Medical Providers</button>
        </div>
    </div>

    <!-- STEP 3: Provider Selection -->
    <div id="step-3" class="step-section hidden">
        <h3 class="step-title">Step 3: Confirm Providers</h3>
        <div class="info-box">
            <h4 id="hospital-display-name"></h4>
            <p id="hospital-display-contact"></p>
        </div>
        <div class="info-box" style="background:#f8fafc; border-color:#e2e8f0;">
            <h4 style="color:#475569;">Assigned Specialists</h4>
            <p id="docs-display-info"></p>
        </div>
        <div class="flex-gap">
            <button onclick="backToStep(2)" class="btn btn-secondary">&larr; Back</button>
            <button onclick="generateFinalApplication()" class="btn btn-success" id="generate-final-btn">Generate Application</button>
        </div>
    </div>

    <!-- STEP 4: Output -->
    <div id="step-4" class="step-section hidden">
        <h3 class="step-title" style="color: #15803d;">Generation Complete</h3>
        <div style="margin-bottom: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <label class="form-label" style="margin: 0; color: var(--primary-navy);">Application Details</label>
                <button onclick="copyText('app-output')" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">Copy App</button>
            </div>
            <textarea id="app-output" class="input-style"></textarea>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <label class="form-label" style="margin: 0; color: var(--primary-navy);">Q&A Section</label>
                <button onclick="copyText('qa-output')" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">Copy Q&A</button>
            </div>
            <textarea id="qa-output" class="input-style"></textarea>
        </div>
        <div class="flex-gap">
            <button onclick="copyAllToDashboard()" class="btn btn-primary">Copy All & Go to Dashboard</button>
            <button onclick="resetGenerator()" class="btn btn-danger">Start New / Clear</button>
        </div>
    </div>
</div>

<script>
    let eligibleData = {};
    let currentTemplate = null;
    let globalProviderData = null;
    let globalCourtData = "Superior Court";
    let calculatedDetentionData = {};
    let globalDetentionCenterMeta = [];

    async function safeFetch(url) {
        const response = await fetch(url);
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error("Server returned invalid output.");
        }
    }

    function formatHumanDate(dateStr) {
        if (!dateStr || dateStr === 'N/A' || dateStr.includes('Edit')) return dateStr;
        if (!dateStr.includes('-')) return dateStr;
        const [y, m, d] = dateStr.split('-');
        return new Date(y, m - 1, d).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function formatHumanMonthYear(dateStr) {
        if (!dateStr || dateStr === 'N/A' || dateStr.includes('Edit')) return dateStr;
        if (!dateStr.includes('-')) return dateStr;
        const [y, m, d] = dateStr.split('-');
        return new Date(y, m - 1, d).toLocaleDateString('en-US', {
            month: 'short',
            year: 'numeric'
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (new URLSearchParams(window.location.search).has('id')) {
            localStorage.removeItem('chc_draft_app');
            localStorage.removeItem('chc_draft_qa');
        } else {
            const draftApp = localStorage.getItem('chc_draft_app');
            if (draftApp) {
                document.getElementById('app-output').value = draftApp;
                document.getElementById('qa-output').value = localStorage.getItem('chc_draft_qa');
                backToStep(4);
            }
        }
    });

    function resetGenerator() {
        localStorage.clear();
        location.reload();
    }

    function backToStep(step) {
        document.querySelectorAll('div[id^="step-"]').forEach(el => el.classList.add('hidden'));
        document.getElementById('step-' + step).classList.remove('hidden');
    }

    async function checkEligibility() {
        const dob = document.getElementById('dob').value;
        const state = document.getElementById('state').value;
        if (!dob || !state) return alert("Date of Birth and State Code are required.");
        const btn = document.getElementById('eligibility-btn');
        btn.innerText = "Checking...";
        btn.disabled = true;

        try {
            const data = await safeFetch(`generator_api.php?action=get_eligible&dob=${dob}&state=${state}`);
            eligibleData = data.data;
            backToStep(2);
            const caseSelect = document.getElementById('case-select');
            caseSelect.innerHTML = '<option value="">-- Select Eligible Case --</option>';
            document.getElementById('eligibility-forms').classList.remove('hidden');
            for (const caseName in eligibleData) caseSelect.innerHTML += `<option value="${caseName}">${caseName}</option>`;
        } catch (e) {
            alert(e.message);
        } finally {
            btn.innerText = "Check Eligibility &rarr;";
            btn.disabled = false;
        }
    }

    document.getElementById('case-select').addEventListener('change', async function(e) {
        const caseName = e.target.value;
        const clientSelect = document.getElementById('client-select');
        const dynamicBox = document.getElementById('dynamic-fields');
        clientSelect.innerHTML = '<option value="">-- Select Client Code --</option>';
        dynamicBox.classList.add('hidden');
        document.getElementById('find-provider-btn').disabled = true;

        if (!caseName) return clientSelect.disabled = true;
        clientSelect.disabled = false;

        eligibleData[caseName].forEach(item => {
            let code = typeof item === 'object' ? item.code : item;
            let usage = typeof item === 'object' ? item.usage : '';
            clientSelect.innerHTML += `<option value="${code}" data-usage="${usage}">Client Code: ${code}</option>`;
        });

        const lower = caseName.toLowerCase();
        const isAbuseOrDetention = lower.includes('abuse') || lower.includes('detention');
        const isPOA = lower.includes('poa');
        let dynHTML = '';

        if (isPOA) {
            let childDobStr = new Date(new Date().getFullYear() - 15, 1, 1).toISOString().split('T')[0];
            dynHTML += `<div class="col-span-2 form-label" style="border-bottom:1px solid #ccc; padding-bottom:5px;">👶 Child Information</div>
                <div><label class="form-label">Child's Name *</label><input type="text" id="dyn-child-name" class="input-style"></div>
                <div><label class="form-label">Child's DOB</label><input type="date" id="dyn-child-dob" class="input-style" value="${childDobStr}" readonly></div>`;
        }

        if (isAbuseOrDetention) {
            dynHTML += `<div class="col-span-2 form-label" style="border-bottom:1px solid #ccc; padding-bottom:5px; margin-top:10px;">Facility Details</div>
                <div><label class="form-label" style="color:#991b1b;">State Track</label><select id="dyn-target-state" class="input-style"><option value="CA">California</option><option value="IL">Illinois</option></select></div>
                <div><label class="form-label" style="color:#991b1b;">Detention Center</label><select id="dyn-detention-center" class="input-style"><option value="">-- Choose State First --</option></select></div>`;
        }

        if (dynHTML) {
            dynamicBox.innerHTML = dynHTML;
            dynamicBox.classList.remove('hidden');
        }

        if (isAbuseOrDetention) {
            if (lower.includes('il')) {
                document.getElementById('dyn-target-state').value = 'IL';
            } else if (lower.includes('ca')) {
                document.getElementById('dyn-target-state').value = 'CA';
            }
            document.getElementById('dyn-target-state').addEventListener('change', () => {
                triggerDetentionCenterMapping();
            });
        }

        try {
            const data = await safeFetch(`generator_api.php?action=get_template&case_name=${encodeURIComponent(caseName)}`);
            currentTemplate = data.template || null;
        } catch (e) {
            console.error(e);
        }
    });

    document.getElementById('client-select').addEventListener('change', () => {
        triggerDetentionCenterMapping();
    });

    async function triggerDetentionCenterMapping() {
        const btn = document.getElementById('find-provider-btn');
        const caseSelect = document.getElementById('case-select').value.toLowerCase();
        const clientCode = document.getElementById('client-select').value;
        const zip = document.getElementById('zip').value;

        if (!clientCode) {
            btn.disabled = true;
            return;
        }
        btn.disabled = false;

        if (caseSelect.includes('abuse') || caseSelect.includes('detention')) {
            const trackState = document.getElementById('dyn-target-state').value;
            const detentionSelect = document.getElementById('dyn-detention-center');
            detentionSelect.innerHTML = '<option>Loading matched centers...</option>';
            const data = await safeFetch(`generator_api.php?action=get_detention_centers&client_code=${clientCode}&zip=${zip}&target_state=${trackState}`);
            detentionSelect.innerHTML = '';
            if (data.data && data.data.length > 0) {
                globalDetentionCenterMeta = data.data;
                detentionSelect.innerHTML = '<option value="">-- Select Facility --</option>';
                globalDetentionCenterMeta.forEach((center, idx) => {
                    detentionSelect.innerHTML += `<option value="${idx}">${center.name} (${center.zipcode})</option>`;
                });
            } else {
                detentionSelect.innerHTML = '<option value="">No centers mapped</option>';
            }
        }
    }

    async function findProviders() {
        const btn = document.getElementById('find-provider-btn');
        btn.innerText = "Resolving Information...";
        btn.disabled = true;

        let originalCity = document.getElementById('city').value;
        let originalState = document.getElementById('state').value.toUpperCase();
        let originalZip = document.getElementById('zip').value;
        const caseName = document.getElementById('case-select').value;
        let addressFoundFormatted = "";

        try {
            if (caseName.toLowerCase().includes('abuse') || caseName.toLowerCase().includes('detention')) {
                const centerIdx = document.getElementById('dyn-detention-center').value;
                if (centerIdx === "") throw new Error("Please select a Detention Center.");
                const center = globalDetentionCenterMeta[centerIdx];
                const trackState = document.getElementById('dyn-target-state').value;

                // Abuse 3-Mile Location Math
                try {
                    const addrData = await safeFetch(`generator_api.php?action=get_real_address&zip=${center.zipcode}&state=${trackState}`);
                    if (addrData.status === 'success' && addrData.address) {
                        addressFoundFormatted = `${addrData.address}, ${addrData.city}, ${trackState} ${center.zipcode}`;
                    } else {
                        addressFoundFormatted = `101 Main St, Chicago, IL ${center.zipcode}`;
                    }
                } catch (e) {
                    addressFoundFormatted = `101 Main St, Los Angeles, CA ${center.zipcode}`;
                }

                calculatedDetentionData = {
                    facility: center.name,
                    address_found: addressFoundFormatted
                };
            }

            // CRITICAL: Always look up hospitals, pharmacies, and doctors from the customer's ORIGINAL HOME ADDRESS.
            let data;
            if (window.electronAPI) {
                const rawData = await window.electronAPI.runPythonEngine('search', {
                    client: {
                        city: originalCity,
                        state: originalState,
                        zip: originalZip
                    }
                });

                if (rawData.status === 'success' && rawData.data && rawData.data.length > 0) {
                    const hosp = rawData.data[0];
                    const doctors = Array.isArray(hosp.doctors) ? hosp.doctors : [];
                    data = {
                        status: 'success',
                        hospital: {
                            name: hosp.hospital_name,
                            address: hosp.hospital_address
                        },
                        specialist1: doctors.length > 0 ? [doctors[0]] : [],
                        specialist2: doctors.length > 1 ? [doctors[1]] : [],
                        location_name: hosp.hospital_name + ' Pharmacy',
                        location_address: hosp.hospital_address
                    };
                } else {
                    throw new Error('No providers found via Python engine.');
                }
            } else {
                data = await safeFetch(`generator_api.php?action=get_providers&city=${encodeURIComponent(originalCity)}&state=${encodeURIComponent(originalState)}&case=${encodeURIComponent(caseName)}&zip=${encodeURIComponent(originalZip)}`);
            }
            if (data.status === 'error') throw new Error(data.message);

            globalProviderData = data;
            document.getElementById('hospital-display-name').innerText = globalProviderData.hospital.name;
            document.getElementById('hospital-display-contact').innerText = globalProviderData.hospital.address;

            let docsInfo = "";
            if (data.specialist1 && data.specialist1.length > 0) docsInfo += `<strong>Doc 1:</strong> ${data.specialist1[0].name} (${data.specialist1[0].specialty})<br>`;
            if (data.specialist2 && data.specialist2.length > 0) docsInfo += `<strong>Doc 2:</strong> ${data.specialist2[0].name} (${data.specialist2[0].specialty})<br>`;

            // If Firecrawl fails to find any valid doctors across all checked hospitals, we fall back to a manual edit.
            if (!docsInfo) docsInfo = "Auto-assigning specialists (No named specialists found online)...";

            document.getElementById('docs-display-info').innerHTML = docsInfo;

            backToStep(3);
        } catch (e) {
            alert(e.message);
            btn.innerText = "Search Medical Providers";
            btn.disabled = false;
        }
    }

    async function generateFinalApplication() {
        if (!currentTemplate) return alert("Template payload error.");
        const btn = document.getElementById('generate-final-btn');
        btn.innerText = "Processing Data...";
        btn.disabled = true;

        let originalCity = document.getElementById('city').value;
        let originalState = document.getElementById('state').value.toUpperCase();

        let doc1 = {
            name: "[Edit: Attending Doctor]",
            specialty: "Specialist",
            phone: "[Edit: Phone]"
        };
        let doc2 = {
            name: "[Edit: Secondary Doctor]",
            specialty: "Specialist",
            phone: "[Edit: Phone]"
        };

        if (globalProviderData.specialist1 && globalProviderData.specialist1.length > 0) {
            doc1 = globalProviderData.specialist1[0];
            try {
                const pd = await safeFetch(`generator_api.php?action=get_doctor_phone&doctor=${encodeURIComponent(doc1.name)}&specialty=${encodeURIComponent(doc1.specialty)}&hospital=${encodeURIComponent(globalProviderData.hospital.name)}`);
                doc1.phone = (pd.status === 'success' && pd.phone !== 'N/A') ? pd.phone : "[Edit: Phone]";
            } catch (e) {
                doc1.phone = "[Edit: Phone]";
            }
        }

        if (globalProviderData.specialist2 && globalProviderData.specialist2.length > 0) {
            doc2 = globalProviderData.specialist2[0];
            try {
                const pd2 = await safeFetch(`generator_api.php?action=get_doctor_phone&doctor=${encodeURIComponent(doc2.name)}&specialty=${encodeURIComponent(doc2.specialty)}&hospital=${encodeURIComponent(globalProviderData.hospital.name)}`);
                doc2.phone = (pd2.status === 'success' && pd2.phone !== 'N/A') ? pd2.phone : "[Edit: Phone]";
            } catch (e) {
                doc2.phone = "[Edit: Phone]";
            }
        }

        // ==========================================
        // USAGE YEARS CRITERIA ENGINE
        // ==========================================
        const selectedOption = document.getElementById('client-select').options[document.getElementById('client-select').selectedIndex];
        let rawUsage = selectedOption.getAttribute('data-usage');
        let usageYears = parseInt(rawUsage);

        if (!usageYears || isNaN(usageYears)) {
            const cCode = selectedOption.value.toUpperCase();
            if (cCode.includes('C10')) usageYears = 5;
            else if (cCode.includes('C12')) usageYears = 10;
            else usageYears = Math.floor(Math.random() * 6) + 4; // Default random 4-9 years if not specified
        }

        // ==========================================
        // DATE GENERATION ENGINE (Max 6 Months Back)
        // ==========================================
        const dateData = await safeFetch('generator_api.php?action=get_random_date');
        let d0 = new Date(dateData.date + 'T00:00:00'); // Base Incident / Complication Date

        let d1 = new Date(d0);
        d1.setDate(d1.getDate() + Math.floor(Math.random() * 5) + 4); // Test Date
        let d2 = new Date(d1);
        d2.setDate(d2.getDate() + Math.floor(Math.random() * 5) + 4); // Diag Date
        let d3 = new Date(d2);
        d3.setDate(d3.getDate() + Math.floor(Math.random() * 5) + 4); // Treatment Date

        // Accurate Start / Stop year based strictly on Criteria Usage Years
        let startYear = new Date(d0);
        startYear.setFullYear(startYear.getFullYear() - usageYears);
        let stopYear = new Date(d0);
        stopYear.setMonth(stopYear.getMonth() - Math.floor(Math.random() * 3 + 1));

        let cancerFree = new Date(d3);
        cancerFree.setFullYear(cancerFree.getFullYear() + 2);

        const formAddress = document.getElementById('address').value;
        const formCity = document.getElementById('city').value;
        const formState = document.getElementById('state').value.toUpperCase();
        const formZip = document.getElementById('zip').value;
        const addressFoundFormatted = calculatedDetentionData.address_found || `${formAddress}, ${formCity}, ${formState} ${formZip}`;

        const vars = {
            name: document.getElementById('fname').value + ' ' + document.getElementById('lname').value,
            phone: document.getElementById('phone').value,
            email: document.getElementById('email').value || '[Edit: Email]',
            address: `${formAddress}, ${formCity}, ${formState} ${formZip}`,
            address_found: addressFoundFormatted,
            dob: formatHumanDate(document.getElementById('dob').value),
            ssn: "[Edit: SSN]",

            child_name: document.getElementById('dyn-child-name')?.value || '[Edit: Child Name]',
            child_dob: formatHumanDate(document.getElementById('dyn-child-dob')?.value),

            // Standard Dates and Usage
            usage_years: usageYears,
            incident_date: formatHumanDate(d0.toISOString().split('T')[0]),
            test_date: formatHumanDate(d1.toISOString().split('T')[0]),
            diag_date: formatHumanDate(d2.toISOString().split('T')[0]),
            treatment_date: formatHumanDate(d3.toISOString().split('T')[0]),
            start_date: formatHumanMonthYear(startYear.toISOString().split('T')[0]),
            stop_date: formatHumanMonthYear(stopYear.toISOString().split('T')[0]),
            cancer_free_date: formatHumanDate(cancerFree.toISOString().split('T')[0]),

            // Facilities
            hospital_name: globalProviderData?.hospital?.name || '[Edit: Hospital]',
            hospital_contact: globalProviderData?.hospital?.address || '[Edit: Hospital Address]',
            location_name: globalProviderData?.location_name || '[Edit: Location Name]',
            location_address: globalProviderData?.location_address || '[Edit: Location Address]',
            facility: calculatedDetentionData.facility || '[Edit: Facility]',

            // Doctors
            doc1_name: doc1.name,
            doc1_desig: doc1.specialty,
            doc1_phone: doc1.phone,
            doc2_name: doc2.name,
            doc2_desig: doc2.specialty,
            doc2_phone: doc2.phone
        };

        let appText = currentTemplate.document_body;
        let qaText = currentTemplate.qa_section;
        for (const [key, value] of Object.entries(vars)) {
            const regex = new RegExp(`{{${key}}}`, 'gi');
            appText = appText.replace(regex, value);
            qaText = qaText.replace(regex, value);
        }

        if (window.electronAPI) {
            window.electronAPI.openGeneratorEditor({
                appText,
                qaText
            });
        } else {
            document.getElementById('app-output').value = appText;
            document.getElementById('qa-output').value = qaText;
        }
        localStorage.setItem('chc_draft_app', appText);
        localStorage.setItem('chc_draft_qa', qaText);

        btn.innerText = "Generate Application";
        btn.disabled = false;
        backToStep(4);

        setTimeout(() => {
            alert("Application generated successfully!\n\n🚨 IMPORTANT: Please review the text and manually fill in any highlighted [Edit: ...] sections.");
        }, 300);
    }

    function copyText(id) {
        document.getElementById(id).select();
        document.execCommand("copy");
        alert("Copied!");
    }

    function copyAllToDashboard() {
        const combinedText = `${document.getElementById('app-output').value}\n\n\n\n${document.getElementById('qa-output').value}`;
        navigator.clipboard.writeText(combinedText).then(() => {
            window.location.href = 'index.php';
        });
    }
</script>

<?php include 'includes/footer.php'; ?>