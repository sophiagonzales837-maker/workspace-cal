<?php
// Prevent server timeouts during heavy operations
set_time_limit(150);

require_once '../config/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Firecrawl is used ONLY for Healthgrades scraping and the lightweight Phone Number fallback
define('FIRECRAWL_API_KEY', 'fc-2f30a613e1434dbfb69ceefac5eb588d');

// ==========================================
// HELPER: FREE OPENSTREETMAP (OSM) API
// ==========================================
function queryFreeMapAPI($query)
{
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($query) . "&format=json&addressdetails=1&limit=3";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: CHC-Application-CRM/1.0 (Contact: admin@consumerhealthcarecompliance.com)']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

// ==========================================
// HELPER: ELIGIBILITY PARSER
// ==========================================
function isEligible($dob, $state, $badStates, $ageCriteria)
{
    if (!empty($state) && !empty($badStates) && strtolower($badStates) !== 'no bad states') {
        if (stripos($badStates, $state) !== false) return false;
    }
    if (empty($dob) || empty($ageCriteria)) return true;

    $birthYear = (int)date('Y', strtotime($dob));
    $age = (int)date('Y') - $birthYear;

    if (preg_match('/Born\s+(\d{4})\s*-\s*(\d{4})/i', $ageCriteria, $m)) {
        if ($birthYear < $m[1] || $birthYear > $m[2]) return false;
    } elseif (preg_match('/Born before (\d{4})/i', $ageCriteria, $m)) {
        if ($birthYear >= $m[1]) return false;
    } elseif (preg_match('/Born after (\d{4})/i', $ageCriteria, $m)) {
        if ($birthYear <= $m[1]) return false;
    } elseif (preg_match('/Currently (\d+)\+/i', $ageCriteria, $m)) {
        if ($age < $m[1]) return false;
    } elseif (preg_match('/under (\d+\.?\d*)/i', $ageCriteria, $m)) {
        if ($age >= (float)$m[1]) return false;
    } elseif (preg_match('/(\d{2})-(\d{2})\s+age/i', $ageCriteria, $m)) {
        if ($age < $m[1] || $age > $m[2]) return false;
    }
    return true;
}

function extractCriteriaSection(string $criteriaText, string $section): string
{
    $sectionPatterns = [
        'bad_states' => 'Bad States',
        'age_criteria' => 'Age\s*/\s*Conditions|Age Criteria',
        'qualifying_questions' => 'Qualifying Questions',
        'other_criteria' => 'Other Criteria'
    ];
    if (!isset($sectionPatterns[$section])) return '';
    $otherHeadings = implode('|', array_values($sectionPatterns));
    $pattern = '/(?:^|\n)\s*(?:' . $sectionPatterns[$section] . ')\s*:\s*(.*?)(?=\n\s*(?:' . $otherHeadings . ')\s*:\s*|\z)/is';
    if (!preg_match($pattern, $criteriaText, $matches)) return '';
    return trim((string)$matches[1]);
}

$action = $_GET['action'] ?? '';

// ==========================================
// ACTION 0: GET RANDOM HISTORICAL DATE (MAX 6 MONTHS)
// ==========================================
if ($action === 'get_random_date') {
    // Generate base dates between 6 months and 1 month ago.
    // We leave a 1-month buffer so sequential treatment dates (d1, d2, d3) added in JS never predict the future.
    $sixMonthsAgo = strtotime("-6 months");
    $oneMonthAgo = strtotime("-1 month");
    $randomTimestamp = rand($sixMonthsAgo, $oneMonthAgo);

    echo json_encode(['status' => 'success', 'date' => date('Y-m-d', $randomTimestamp)]);
    exit;
}

// ==========================================
// ACTION 1: GET ELIGIBLE CASES
// ==========================================
if ($action === 'get_eligible') {
    $dob = $_GET['dob'] ?? '';
    $state = $_GET['state'] ?? '';

    require_once '../shared/criteria_storage.php';
    try {
        workspace_ensure_criteria_schema($conn, true);
    } catch (Throwable $error) {
        error_log('[Application generator] Criteria schema initialization failed: ' . $error->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Unable to load criteria']);
        exit;
    }

    $query = "SELECT cl.client_code, v.vertical_name, c.criteria_text
              FROM criteria c
              JOIN clients cl ON c.client_id = cl.id
              JOIN verticals v ON c.vertical_id = v.id
              WHERE c.is_active = 1 AND cl.is_hidden = 0 AND v.is_hidden = 0
              ORDER BY v.sort_order ASC, v.id ASC, cl.sort_order ASC, cl.id ASC";
    $result = $conn->query($query);
    if (!$result) {
        echo json_encode(['status' => 'error', 'message' => 'Unable to load criteria']);
        exit;
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $caseName = (string)$row['vertical_name'];
        $clientCode = (string)$row['client_code'];
        if (!isset($data[$caseName])) $data[$caseName] = [];
        $data[$caseName][$clientCode] = [
            'bad_states' => extractCriteriaSection((string)($row['criteria_text'] ?? ''), 'bad_states'),
            'age_criteria' => extractCriteriaSection((string)($row['criteria_text'] ?? ''), 'age_criteria'),
            'active' => true
        ];
    }

    $eligibleCases = [];
    foreach ($data as $caseName => $clients) {
        $eligibleClientCodes = [];
        $poaEligibleCodes = [];

        foreach ($clients as $clientCode => $rules) {
            if ($rules['active']) {
                $usage = $rules['usage_years'] ?? '';
                if (isEligible($dob, $state, $rules['bad_states'], $rules['age_criteria'])) {
                    $eligibleClientCodes[] = ['code' => $clientCode, 'usage' => $usage];
                }
                if (isEligible($dob, $state, $rules['bad_states'], '')) {
                    $poaEligibleCodes[] = ['code' => $clientCode, 'usage' => $usage];
                }
            }
        }

        if (!empty($eligibleClientCodes)) {
            $eligibleCases[$caseName] = $eligibleClientCodes;
        }

        $lowerCase = strtolower($caseName);
        if ((strpos($lowerCase, 'detention') !== false || strpos($lowerCase, 'abuse') !== false || strpos($lowerCase, 'roblox') !== false || strpos($lowerCase, 'snapchat') !== false) && strpos($lowerCase, 'poa') === false) {
            if (!empty($poaEligibleCodes)) {
                $poaName = trim($caseName) . ' (POA)';
                $eligibleCases[$poaName] = $poaEligibleCodes;
            }
        }
    }

    if (empty($eligibleCases)) $eligibleCases = new stdClass();
    echo json_encode(['status' => 'success', 'data' => $eligibleCases]);
    exit;
}

// ==========================================
// ACTION 2: GET TEXT TEMPLATE
// ==========================================
if ($action === 'get_template') {
    $caseName = $_GET['case_name'];
    $stmt = $conn->prepare("SELECT document_body, qa_section FROM generator_templates WHERE case_name = ?");
    $stmt->bind_param("s", $caseName);
    $stmt->execute();
    echo json_encode(['status' => 'success', 'template' => $stmt->get_result()->fetch_assoc()]);
    exit;
}

// ========================================================
// ACTION 3: FETCH MAPPED DETENTION CENTERS + CRITERIA
// ========================================================
if ($action === 'get_detention_centers') {
    $clientCode = $_GET['client_code'] ?? '';
    $userZip = (int)preg_replace('/\D/', '', $_GET['zip'] ?? '0');
    $targetState = $_GET['target_state'] ?? 'CA';

    $query = "SELECT id, name, zipcode, state, opened_year, closed_year, min_stay, max_stay, stay_unit 
              FROM detention_centers
              WHERE client_codes LIKE '%$clientCode%' AND state = '$targetState'";
    $result = $conn->query($query);

    $centers = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $centerZip = (int)$row['zipcode'];
            $distance = abs($centerZip - $userZip);
            $row['distance'] = $distance;
            $centers[] = $row;
        }
    }
    usort($centers, function ($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
    echo json_encode(['status' => 'success', 'data' => $centers]);
    exit;
}

// ========================================================
// FIRECRAWL UTILITIES
// ========================================================
function fcScrapeJson($url, $prompt, $schema)
{
    $ch = curl_init('https://api.firecrawl.dev/v2/scrape');
    $payload = json_encode(['url' => $url, 'formats' => [['type' => 'json', 'prompt' => $prompt, 'schema' => $schema]], 'onlyMainContent' => true, 'location' => ['country' => 'US', 'languages' => ['en']]]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . FIRECRAWL_API_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return $decoded['data']['json'] ?? $decoded['json'] ?? null;
}

function fcSearch($query, $limit = 3)
{
    $ch = curl_init('https://api.firecrawl.dev/v2/search');
    $payload = json_encode(['query' => $query, 'limit' => $limit, 'location' => 'United States']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . FIRECRAWL_API_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return $decoded['data']['web'] ?? $decoded['web'] ?? $decoded['data'] ?? [];
}

// ========================================================
// ACTION 4: MEDICAL API & STRICT LOCATION FINDER 
// ========================================================
if ($action === 'get_providers') {
    $city = strtolower(trim($_GET['city'] ?? ''));
    $stateInput = trim($_GET['state'] ?? '');
    $case = strtolower(trim($_GET['case'] ?? ''));
    $zip = trim($_GET['zip'] ?? '');

    $cacheFile = '../config/provider_cache_' . md5($city . $stateInput . $case) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        echo file_get_contents($cacheFile);
        exit;
    }

    // A. FIND SUPPLEMENTARY LOCATIONS (Pharmacy, Fire Dept, Hardware) WITH GUARANTEED FALLBACKS
    $placeName = "[Edit: Location Name]";
    $placeAddress = "[Edit: Location Address]";

    if (strpos($case, 'talcum') !== false || strpos($case, 'hair relaxer') !== false || strpos($case, 'zantac') !== false || strpos($case, 'depo') !== false) {
        $osm = queryFreeMapAPI("pharmacy $zip");
        if (empty($osm)) $osm = queryFreeMapAPI("pharmacy $city $stateInput");

        if (!empty($osm)) {
            $placeName = $osm[0]['name'] ?? 'Walgreens / CVS Pharmacy';
            $placeAddress = ($osm[0]['address']['house_number'] ?? rand(100, 9999)) . " " . ($osm[0]['address']['road'] ?? 'Main St') . ", " . $city . ", " . $stateInput . " " . $zip;
        } else {
            $placeName = 'Walgreens / CVS Pharmacy';
            $placeAddress = rand(100, 9999) . " Main St, " . $city . ", " . $stateInput . " " . $zip;
        }
    } elseif (strpos($case, 'roundup') !== false) {
        $stores = ['The Home Depot', 'Ace Hardware', 'Lowe\'s Home Improvement', 'Walmart'];
        $placeName = $stores[array_rand($stores)];

        $osm = queryFreeMapAPI("hardware store $zip");
        if (empty($osm)) $osm = queryFreeMapAPI("hardware store $city $stateInput");

        if (!empty($osm)) {
            $placeAddress = ($osm[0]['address']['house_number'] ?? rand(100, 9999)) . " " . ($osm[0]['address']['road'] ?? 'Commercial Blvd') . ", " . $city . ", " . $stateInput . " " . $zip;
        } else {
            $placeAddress = rand(100, 9999) . " Commercial Blvd, " . $city . ", " . $stateInput . " " . $zip;
        }
    } elseif (strpos($case, 'afff') !== false) {
        $osm = queryFreeMapAPI("fire station $zip");
        if (empty($osm)) $osm = queryFreeMapAPI("fire station $city $stateInput");

        if (!empty($osm)) {
            $placeName = $osm[0]['name'] ?? "$city Fire Department Station 1";
            $placeAddress = ($osm[0]['address']['house_number'] ?? rand(100, 9999)) . " " . ($osm[0]['address']['road'] ?? 'Safety Way') . ", " . $city . ", " . $stateInput . " " . $zip;
        } else {
            $placeName = "$city Fire Department Station 1";
            $placeAddress = rand(100, 9999) . " Safety Way, " . $city . ", " . $stateInput . " " . $zip;
        }
    }

    // B. FIND HOSPITALS
    $US_STATES = ['AL' => 'alabama', 'AK' => 'alaska', 'AZ' => 'arizona', 'AR' => 'arkansas', 'CA' => 'california', 'CO' => 'colorado', 'CT' => 'connecticut', 'DE' => 'delaware', 'FL' => 'florida', 'GA' => 'georgia', 'HI' => 'hawaii', 'ID' => 'idaho', 'IL' => 'illinois', 'IN' => 'indiana', 'IA' => 'iowa', 'KS' => 'kansas', 'KY' => 'kentucky', 'LA' => 'louisiana', 'ME' => 'maine', 'MD' => 'maryland', 'MA' => 'massachusetts', 'MI' => 'michigan', 'MN' => 'minnesota', 'MS' => 'mississippi', 'MO' => 'missouri', 'MT' => 'montana', 'NE' => 'nebraska', 'NV' => 'nevada', 'NH' => 'new-hampshire', 'NJ' => 'new-jersey', 'NM' => 'new-mexico', 'NY' => 'new-york', 'NC' => 'north-carolina', 'ND' => 'north-dakota', 'OH' => 'ohio', 'OK' => 'oklahoma', 'OR' => 'oregon', 'PA' => 'pennsylvania', 'RI' => 'rhode-island', 'SC' => 'south-carolina', 'SD' => 'south-dakota', 'TN' => 'tennessee', 'TX' => 'texas', 'UT' => 'utah', 'VT' => 'vermont', 'VA' => 'virginia', 'WA' => 'washington', 'WV' => 'west-virginia', 'WI' => 'wisconsin', 'WY' => 'wyoming'];
    $stateUpper = strtoupper($stateInput);
    $sSlug = '';
    if (isset($US_STATES[$stateUpper])) {
        $sSlug = strtolower($stateUpper) . '-' . $US_STATES[$stateUpper];
    } else {
        $slugifiedState = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $stateInput));
        foreach ($US_STATES as $code => $name) {
            if ($name === $slugifiedState) {
                $sSlug = strtolower($code) . '-' . $name;
                break;
            }
        }
    }

    if (empty($sSlug)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid State Code']);
        exit;
    }
    $cSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $city));

    $dirUrl = "https://www.healthgrades.com/hospital-directory/$sSlug/$cSlug";
    $dirSchema = ["type" => "object", "properties" => ["hospitals" => ["type" => "array", "items" => ["type" => "object", "properties" => ["name" => ["type" => "string"], "address" => ["type" => "string"], "profileUrl" => ["type" => "string"]], "required" => ["name", "profileUrl"]]]], "required" => ["hospitals"]];

    $hospData = fcScrapeJson($dirUrl, "Extract hospitals listed.", $dirSchema);
    if (empty($hospData['hospitals'])) {
        $cSlug = ($stateUpper === 'IL') ? 'chicago' : 'los-angeles';
        $dirUrl = "https://www.healthgrades.com/hospital-directory/$sSlug/$cSlug";
        $hospData = fcScrapeJson($dirUrl, "Extract hospitals listed.", $dirSchema);
    }
    if (empty($hospData['hospitals'])) {
        echo json_encode(['status' => 'error', 'message' => "No hospitals could be resolved for $stateInput."]);
        exit;
    }

    $hospitalsToSearch = array_slice($hospData['hospitals'], 0, 3);
    $foundHospital = null;
    $specialist1 = [];
    $specialist2 = [];

    // C. DYNAMIC PROMPTS BASED ON CASE
    $docSchema = ["type" => "object", "properties" => ["specialist1" => ["type" => "array", "items" => ["type" => "object", "properties" => ["name" => ["type" => "string"], "specialty" => ["type" => "string"]], "required" => ["name", "specialty"]]], "specialist2" => ["type" => "array", "items" => ["type" => "object", "properties" => ["name" => ["type" => "string"], "specialty" => ["type" => "string"]], "required" => ["name", "specialty"]]]]];
    $prompt = "";

    if (strpos($case, 'talcum') !== false || strpos($case, 'hair relaxer') !== false) {
        $prompt = "Extract physicians. Array 'specialist1': Obstetrics & Gynecology. Array 'specialist2': Oncology.";
    } elseif (strpos($case, 'roundup') !== false) {
        $prompt = "Extract physicians. Array 'specialist1': Hematology or Oncology. Array 'specialist2': Family Medicine.";
    } elseif (strpos($case, 'bard') !== false || strpos($case, 'hernia') !== false) {
        $prompt = "Extract physicians specializing in General Surgery into 'specialist1' and 'specialist2'.";
    } elseif (strpos($case, 'ozempic') !== false) {
        $prompt = "Extract physicians specializing in Gastroenterology into 'specialist1'.";
    } elseif (strpos($case, 'zantac') !== false) {
        $prompt = "Extract physicians specializing in Oncology into 'specialist1'.";
    } elseif (strpos($case, 'afff') !== false) {
        $prompt = "Extract physicians specializing in Urology into 'specialist1'.";
    } elseif (strpos($case, 'depo') !== false) {
        $prompt = "Extract physicians. Array 'specialist1': Obstetrics & Gynecology. Array 'specialist2': Neurosurgery.";
    } elseif (strpos($case, 'mva') !== false || strpos($case, 'motor vehicle') !== false) {
        $prompt = "Extract physicians specializing in Orthopedic Surgery or Traumatology into 'specialist1'.";
    } elseif (strpos($case, 'chlorpyrifos') !== false || strpos($case, 'cpf') !== false) {
        $prompt = "Extract physicians specializing in Neurology into 'specialist1'.";
    } else { // Abuse, Rideshare, Roblox, Snapchat
        $prompt = "Extract physicians specializing in Psychiatry or Mental Health into 'specialist1'.";
    }

    foreach ($hospitalsToSearch as $hospital) {
        $docData = fcScrapeJson($hospital['profileUrl'], $prompt, $docSchema);
        if (!empty($docData['specialist1'])) {
            $foundHospital = $hospital;
            $specialist1 = $docData['specialist1'] ?? [];
            $specialist2 = $docData['specialist2'] ?? [];
            break;
        }
    }

    if (!$foundHospital) {
        echo json_encode(['status' => 'error', 'message' => 'No matching active medical practitioners found at local facilities.']);
        exit;
    }

    $response = json_encode([
        'status' => 'success',
        'hospital' => $foundHospital,
        'specialist1' => array_slice($specialist1, 0, 3),
        'specialist2' => array_slice($specialist2, 0, 3),
        'location_name' => $placeName,
        'location_address' => $placeAddress
    ]);

    file_put_contents($cacheFile, $response);
    echo $response;
    exit;
}

// ========================================================
// ACTION 5: GEOMETRIC ADDRESS CALCULATOR (3-MILE RADIUS)
// ========================================================
if ($action === 'get_real_address') {
    $zip = trim($_GET['zip'] ?? '');
    $stateCode = strtoupper($_GET['state'] ?? 'CA');
    $stateName = ($stateCode === 'IL') ? 'Illinois' : 'California';

    $city = ($stateCode === 'IL') ? 'Chicago' : 'Los Angeles';
    $realAddress = null;

    $geoResults = queryFreeMapAPI("$zip, $stateName");
    if (!empty($geoResults) && isset($geoResults[0]['lat']) && isset($geoResults[0]['lon'])) {
        $base_lat = (float)$geoResults[0]['lat'];
        $base_lon = (float)$geoResults[0]['lon'];

        $radius_miles = rand(25, 35) / 10.0;
        $radius_degrees = $radius_miles / 69.0;
        $angle = rand(0, 360) * (M_PI / 180);

        $new_lat = $base_lat + ($radius_degrees * cos($angle));
        $new_lon = $base_lon + ($radius_degrees * sin($angle) / cos($base_lat * (M_PI / 180)));

        $reverse_url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$new_lat}&lon={$new_lon}&addressdetails=1";
        $ch = curl_init($reverse_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: CHC-Application-CRM/1.0']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $reverse_res = curl_exec($ch);
        curl_close($ch);

        $location = json_decode($reverse_res, true);
        if (!empty($location) && isset($location['address'])) {
            $addr = $location['address'];
            $house_number = $addr['house_number'] ?? rand(101, 9999);
            $road = $addr['road'] ?? $addr['pedestrian'] ?? "Main St";
            $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $city;
            $realAddress = trim("$house_number $road");
        }
    }

    if (empty($realAddress) || strpos($realAddress, 'Main St') !== false) {
        $commonStreets = ["Oak St", "Pine Ave", "Maple Dr", "Cedar Ln", "Elm St"];
        $realAddress = rand(101, 4999) . " " . $commonStreets[array_rand($commonStreets)];
    }

    echo json_encode(['status' => 'success', 'address' => $realAddress, 'city' => $city]);
    exit;
}

// ========================================================
// ACTION 6: DOCTOR PHONE NUMBER SCRAPER
// ========================================================
if ($action === 'get_doctor_phone') {
    $doctor = trim($_GET['doctor'] ?? '');
    $specialty = trim($_GET['specialty'] ?? '');
    $hospital = trim($_GET['hospital'] ?? '');

    $query = "$doctor $specialty $hospital phone number";
    $phone = "N/A";

    $url = 'https://search.yahoo.com/search?p=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);

    if ($html) {
        $cleanText = strip_tags($html);
        if (preg_match('/\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}/', $cleanText, $matches)) {
            $phone = trim($matches[0]);
        }
    }

    if ($phone === "N/A") {
        $results = fcSearch($query, 2);
        foreach ($results as $r) {
            $text = ($r['title'] ?? "") . " " . ($r['description'] ?? "");
            if (preg_match('/\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}/', $text, $matches)) {
                $phone = trim($matches[0]);
                break;
            }
        }
    }

    echo json_encode(['status' => 'success', 'phone' => $phone]);
    exit;
}
