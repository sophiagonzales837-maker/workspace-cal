<?php
if (!function_exists('workspace_sync_c2c_lead_update')) {
    function workspace_sync_c2c_lead_update($conn, int $lead_id, string $updated_column): void
    {
        if (!function_exists('curl_init')) return;

        $c2c_stmt = $conn->prepare('SELECT c2c_id FROM leads WHERE id = ?');
        if (!$c2c_stmt) return;

        $c2c_stmt->bind_param('i', $lead_id);
        $c2c_stmt->execute();
        $c2c_row = $c2c_stmt->get_result()->fetch_assoc();
        $c2c_stmt->close();
        if (!$c2c_row || empty($c2c_row['c2c_id'])) return;

        $stmt = $conn->prepare('SELECT * FROM leads WHERE id = ? LIMIT 1');
        if (!$stmt) return;

        $stmt->bind_param('i', $lead_id);
        $stmt->execute();
        $lead = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lead) return;

        if (function_exists('decrypt_fields')) {
            $lead = decrypt_fields($lead);
        }

        $vertical = $lead['vertical'] ?? '';
        $updated_value = $lead[$updated_column] ?? null;
        $payload = [
            'id' => $lead_id,
            'c2c_id' => $lead['c2c_id'],
            'id_upload_token' => $lead['id_upload_token'] ?? '',
            'first_name' => $lead['first_name'] ?? '',
            'last_name' => $lead['last_name'] ?? '',
            'email' => $lead['email'] ?? '',
            'phone' => $lead['phone'] ?? '',
            'vertical' => $vertical,
            'questions' => $lead['questions'] ?? '',
            'c2c_docs_path' => $lead['c2c_docs_path'] ?? '',
            'status' => $lead['status'] ?? '',
            'admin_status' => $lead['admin_status'] ?? '',
            'closer_status' => $lead['closer_status'] ?? '',
            'quick_update' => $lead['quick_update'] ?? '',
            'updated_field' => $updated_column,
            'updated_value' => $updated_value
        ];
        if (array_key_exists($updated_column, $lead)) {
            $payload[$updated_column] = $updated_value;
        }

        $ch = curl_init('https://consumerhealthcarecompliance.com/api/receive_c2c_data.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        curl_close($ch);
    }
}
