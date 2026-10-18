<?php
function syncUserToLive(mysqli $conn, int $user_id): bool
{
    $stmt = $conn->prepare('SELECT id, username, password, role, status, team, is_global_verifier FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        error_log('[User sync] Local query prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        error_log('[User sync] Local query failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) return false;

    $user['auth_key'] = 'MakeMoney$$';
    $payload = json_encode($user, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false || !function_exists('curl_init')) return false;

    $curl = curl_init('https://consumerhealthcarecompliance.com/api/sync_user.php');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($curl);
    $http_status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false || $http_status < 200 || $http_status >= 300) {
        error_log('[User sync] Live request failed: ' . curl_error($curl) . ' (HTTP ' . $http_status . ')');
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    $result = json_decode((string)$response, true);
    return is_array($result) && ($result['status'] ?? '') === 'success';
}
