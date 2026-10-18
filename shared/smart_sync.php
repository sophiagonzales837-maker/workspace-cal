<?php
function workspace_normalize_sync_phone(string $phone): string
{
    $clean_phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
    if ($clean_phone !== '' && strpos($clean_phone, '+1') !== 0) {
        $clean_phone = '+1' . ltrim($clean_phone, '1+');
    }
    return $clean_phone;
}

function workspace_prepare_lead_sync_changes(mysqli $conn, int $lead_id, array $input): array
{
    $allowed_fields = [
        'customer_name',
        'first_name',
        'last_name',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip_code',
        'vertical',
        'quick_update',
        'application_text',
        'questions',
        'verifier_id',
        'verifier_name'
    ];
    $encrypted_fields = [
        'customer_name',
        'first_name',
        'last_name',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip_code',
        'application_text',
        'questions',
        'verifier_id',
        'verifier_name'
    ];
    $fields = array_values(array_intersect(array_keys($input), $allowed_fields));
    if (!$fields) return [];

    $select_fields = implode(', ', array_map(static fn($field) => '`' . $field . '`', $fields));
    $stmt = $conn->prepare('SELECT ' . $select_fields . ' FROM leads WHERE id = ? LIMIT 1');
    if (!$stmt) return [];
    $stmt->bind_param('i', $lead_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) return [];

    $changes = [];
    foreach ($fields as $field) {
        $new_value = trim((string)$input[$field]);
        if ($field === 'phone') $new_value = workspace_normalize_sync_phone($new_value);
        $old_value = (string)($current[$field] ?? '');
        if (in_array($field, $encrypted_fields, true)) $old_value = (string)decryptData($old_value);
        if ($field === 'phone') $old_value = workspace_normalize_sync_phone($old_value);
        if ($old_value === $new_value) continue;

        $changes[$field] = $new_value;
        if ($field === 'phone') $changes['phone_hash'] = createBlindHash($new_value);
    }
    return $changes;
}

function workspace_send_lead_sync_update(int $lead_id, array $changes): bool
{
    $allowed_fields = [
        'customer_name',
        'first_name',
        'last_name',
        'phone',
        'phone_hash',
        'email',
        'address',
        'city',
        'state',
        'zip_code',
        'vertical',
        'quick_update',
        'application_text',
        'questions',
        'verifier_id',
        'verifier_name'
    ];
    $payload = ['id' => $lead_id];
    foreach ($changes as $field => $value) {
        if (in_array($field, $allowed_fields, true)) $payload[$field] = $value;
    }
    if (count($payload) === 1) return true;
    $payload['auth_key'] = 'MakeMoney$$';
    return workspace_post_smart_sync_payload($payload);
}

function workspace_sync_c2c_id_to_live(int $lead_id, string $c2c_id): bool
{
    return workspace_post_smart_sync_payload([
        'id' => $lead_id,
        'c2c_id' => $c2c_id,
        'auth_key' => 'MakeMoney$$'
    ]);
}

function workspace_post_smart_sync_payload(array $payload): bool
{
    if (!function_exists('curl_init')) return false;
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return false;

    $curl = curl_init('https://consumerhealthcarecompliance.com/api/sync_update_lead.php');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    $response = curl_exec($curl);
    $http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $http_status < 200 || $http_status >= 300) {
        error_log('[Lead sync] Live request failed: ' . curl_error($curl) . ' (HTTP ' . $http_status . ')');
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    $result = json_decode((string)$response, true);
    return is_array($result) && ($result['status'] ?? '') === 'success';
}
