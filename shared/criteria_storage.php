<?php

function workspace_criteria_query(mysqli $conn, string $sql): mysqli_result|bool
{
    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Criteria storage query failed: ' . $conn->error);
    }
    return $result;
}

function workspace_criteria_table_columns(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect criteria storage schema: ' . $conn->error);
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect criteria storage schema: ' . $error);
    }
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[(string)$row['COLUMN_NAME']] = true;
    }
    $stmt->close();
    return $columns;
}

function workspace_criteria_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }
    $length = preg_match_all('/./us', $value);
    return $length === false ? strlen($value) : $length;
}

function workspace_ensure_criteria_schema(mysqli $conn, bool $migrate_legacy = false): void
{
    workspace_criteria_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_code VARCHAR(100) NOT NULL UNIQUE,
            sort_order INT DEFAULT 0,
            is_hidden TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $client_columns = workspace_criteria_table_columns($conn, 'clients');
    foreach ([
        'sort_order' => 'INT DEFAULT 0',
        'is_hidden' => 'TINYINT(1) DEFAULT 0'
    ] as $column => $definition) {
        if (!isset($client_columns[$column])) {
            workspace_criteria_query($conn, "ALTER TABLE clients ADD COLUMN `$column` $definition");
        }
    }

    workspace_criteria_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS verticals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vertical_name VARCHAR(150) NOT NULL UNIQUE,
            sort_order INT DEFAULT 0,
            is_hidden TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    workspace_criteria_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS criteria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            vertical_id INT NOT NULL,
            is_active TINYINT(1) DEFAULT 0,
            criteria_text TEXT DEFAULT NULL,
            bad_states TEXT DEFAULT NULL,
            age_criteria TEXT DEFAULT NULL,
            qualifying_questions TEXT DEFAULT NULL,
            other_criteria TEXT DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_client_vertical (client_id, vertical_id),
            INDEX idx_client (client_id),
            INDEX idx_vertical (vertical_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $criteria_columns = workspace_criteria_table_columns($conn, 'criteria');
    if (!isset($criteria_columns['criteria_text'])) {
        workspace_criteria_query($conn, 'ALTER TABLE criteria ADD COLUMN criteria_text TEXT DEFAULT NULL AFTER is_active');
        $legacy_criteria_columns = ['bad_states', 'age_criteria', 'qualifying_questions', 'other_criteria'];
        if (!array_diff($legacy_criteria_columns, array_keys($criteria_columns))) {
            workspace_criteria_query(
                $conn,
                "UPDATE criteria
                 SET criteria_text = CONCAT_WS('\n',
                     IF(NULLIF(TRIM(bad_states), '') IS NULL, NULL, CONCAT('Bad States:', '\n', bad_states)),
                     IF(NULLIF(TRIM(age_criteria), '') IS NULL, NULL, CONCAT('Age / Conditions:', '\n', age_criteria)),
                     IF(NULLIF(TRIM(qualifying_questions), '') IS NULL, NULL, CONCAT('Qualifying Questions:', '\n', qualifying_questions)),
                     IF(NULLIF(TRIM(other_criteria), '') IS NULL, NULL, CONCAT('Other Criteria:', '\n', other_criteria))
                 )
                 WHERE NULLIF(TRIM(bad_states), '') IS NOT NULL
                    OR NULLIF(TRIM(age_criteria), '') IS NOT NULL
                    OR NULLIF(TRIM(qualifying_questions), '') IS NOT NULL
                    OR NULLIF(TRIM(other_criteria), '') IS NOT NULL"
            );
        }
    }

    if ($migrate_legacy) {
        workspace_migrate_legacy_criteria($conn);
    }
}

function workspace_insert_criteria_client(mysqli $conn, string $client_code, int $sort_order, bool $is_hidden): void
{
    $columns = workspace_criteria_table_columns($conn, 'clients');
    $fields = ['client_code', 'sort_order', 'is_hidden'];
    $types = 'sii';
    $values = [$client_code, $sort_order, $is_hidden ? 1 : 0];

    if (isset($columns['display_name'])) {
        $fields[] = 'display_name';
        $types .= 's';
        $values[] = $client_code;
    }
    if (isset($columns['password'])) {
        $fields[] = 'password';
        $types .= 's';
        $values[] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    $quoted_fields = implode(', ', array_map(static fn($field) => '`' . $field . '`', $fields));
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $conn->prepare(
        "INSERT INTO clients ($quoted_fields) VALUES ($placeholders)
         ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_hidden = VALUES(is_hidden)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare criteria client insert: ' . $conn->error);
    }
    $params = [$types];
    foreach ($values as $index => &$value) {
        $params[] = &$value;
    }
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save criteria client: ' . $error);
    }
    $stmt->close();
}

function workspace_add_criteria_client(mysqli $conn, string $client_code): void
{
    $columns = workspace_criteria_table_columns($conn, 'clients');
    $fields = ['client_code', 'sort_order', 'is_hidden'];
    $select_values = ['?', 'COALESCE(MAX(sort_order), 0) + 1', '0'];
    $types = 's';
    $values = [$client_code];

    if (isset($columns['display_name'])) {
        $fields[] = 'display_name';
        $select_values[] = '?';
        $types .= 's';
        $values[] = $client_code;
    }
    if (isset($columns['password'])) {
        $fields[] = 'password';
        $select_values[] = '?';
        $types .= 's';
        $values[] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    $quoted_fields = implode(', ', array_map(static fn($field) => '`' . $field . '`', $fields));
    $stmt = $conn->prepare(
        'INSERT INTO clients (' . $quoted_fields . ')
         SELECT ' . implode(', ', $select_values) . ' FROM clients'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare criteria client insert: ' . $conn->error);
    }
    $params = [$types];
    foreach ($values as $index => &$value) {
        $params[] = &$value;
    }
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to add criteria client: ' . $error);
    }
    $stmt->close();
}

function workspace_migrate_legacy_criteria(mysqli $conn): void
{
    $vertical_count = workspace_criteria_query($conn, 'SELECT COUNT(*) AS total FROM verticals');
    $count_row = $vertical_count->fetch_assoc();
    if ((int)($count_row['total'] ?? 0) > 0) {
        return;
    }

    $legacy = $conn->query("SELECT field_value FROM inhouse_criteria WHERE field_name = 'matrix_config' ORDER BY id DESC LIMIT 1");
    if (!$legacy) {
        if (stripos($conn->error, "doesn't exist") !== false || stripos($conn->error, 'does not exist') !== false) {
            return;
        }
        throw new RuntimeException('Unable to read legacy criteria configuration: ' . $conn->error);
    }
    if ($legacy->num_rows === 0) {
        return;
    }

    $config = json_decode((string)$legacy->fetch_assoc()['field_value'], true);
    if (!is_array($config)) {
        return;
    }

    $client_order = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        is_array($config['client_order'] ?? null) ? $config['client_order'] : []
    ), static fn($value) => $value !== '')));
    $client_hidden = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        is_array($config['client_hidden'] ?? null) ? $config['client_hidden'] : []
    ), static fn($value) => $value !== '')));
    $vertical_order = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        is_array($config['vertical_order'] ?? null) ? $config['vertical_order'] : []
    ), static fn($value) => $value !== '')));
    $vertical_hidden = array_values(array_unique(array_filter(array_map(
        static fn($value) => trim((string)$value),
        is_array($config['vertical_hidden'] ?? null) ? $config['vertical_hidden'] : []
    ), static fn($value) => $value !== '')));

    $clients = array_values(array_unique(array_merge($client_order, $client_hidden)));
    $verticals = array_values(array_unique(array_merge($vertical_order, $vertical_hidden)));
    if (!$verticals) {
        return;
    }

    $client_hidden_map = array_fill_keys($client_hidden, true);
    $vertical_hidden_map = array_fill_keys($vertical_hidden, true);
    $legacy_values = [];
    $legacy_cells = $conn->query("SELECT field_name, field_value FROM inhouse_criteria WHERE field_name LIKE 'internal_%'");
    if (!$legacy_cells) {
        throw new RuntimeException('Unable to read legacy criteria cells: ' . $conn->error);
    }
    while ($row = $legacy_cells->fetch_assoc()) {
        $legacy_values[(string)$row['field_name']] = (string)$row['field_value'];
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start legacy criteria migration: ' . $conn->error);
    }
    try {
        foreach ($clients as $index => $client_code) {
            workspace_insert_criteria_client(
                $conn,
                $client_code,
                $index,
                isset($client_hidden_map[$client_code])
            );
        }
        $vertical_stmt = $conn->prepare(
            'INSERT INTO verticals (vertical_name, sort_order, is_hidden) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_hidden = VALUES(is_hidden)'
        );
        if (!$vertical_stmt) {
            throw new RuntimeException('Unable to prepare criteria vertical migration: ' . $conn->error);
        }
        foreach ($verticals as $index => $vertical_name) {
            $sort_order = $index;
            $is_hidden = isset($vertical_hidden_map[$vertical_name]) ? 1 : 0;
            $vertical_stmt->bind_param('sii', $vertical_name, $sort_order, $is_hidden);
            if (!$vertical_stmt->execute()) {
                throw new RuntimeException('Unable to migrate criteria vertical: ' . $vertical_stmt->error);
            }
        }
        $vertical_stmt->close();

        $client_ids = [];
        $client_result = workspace_criteria_query($conn, 'SELECT id, client_code FROM clients');
        while ($row = $client_result->fetch_assoc()) {
            $client_ids[(string)$row['client_code']] = (int)$row['id'];
        }
        $vertical_ids = [];
        $vertical_result = workspace_criteria_query($conn, 'SELECT id, vertical_name FROM verticals');
        while ($row = $vertical_result->fetch_assoc()) {
            $vertical_ids[(string)$row['vertical_name']] = (int)$row['id'];
        }

        $criteria_stmt = $conn->prepare(
            'INSERT INTO criteria (client_id, vertical_id, is_active, criteria_text, bad_states, age_criteria, qualifying_questions, other_criteria)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), criteria_text = VALUES(criteria_text), bad_states = VALUES(bad_states),
                 age_criteria = VALUES(age_criteria), qualifying_questions = VALUES(qualifying_questions),
                 other_criteria = VALUES(other_criteria)'
        );
        if (!$criteria_stmt) {
            throw new RuntimeException('Unable to prepare criteria cell migration: ' . $conn->error);
        }
        foreach ($clients as $client_code) {
            if (!isset($client_ids[$client_code])) continue;
            foreach ($verticals as $vertical_name) {
                if (!isset($vertical_ids[$vertical_name])) continue;
                $active_key = 'internal_active:' . $client_code . ':' . $vertical_name;
                $field_prefix = 'internal_field:' . $client_code . ':' . $vertical_name . ':';
                $is_active = !empty($legacy_values[$active_key]) ? 1 : 0;
                $bad_states = $legacy_values[$field_prefix . 'bad_states'] ?? '';
                $age_criteria = $legacy_values[$field_prefix . 'age_criteria'] ?? '';
                $qualifying_questions = $legacy_values[$field_prefix . 'qualifying_questions'] ?? '';
                $other_criteria = $legacy_values[$field_prefix . 'other_criteria'] ?? '';
                if (
                    !array_key_exists($active_key, $legacy_values)
                    && !array_key_exists($field_prefix . 'bad_states', $legacy_values)
                    && !array_key_exists($field_prefix . 'age_criteria', $legacy_values)
                    && !array_key_exists($field_prefix . 'qualifying_questions', $legacy_values)
                    && !array_key_exists($field_prefix . 'other_criteria', $legacy_values)
                ) {
                    continue;
                }
                $client_id = $client_ids[$client_code];
                $vertical_id = $vertical_ids[$vertical_name];
                $criteria_sections = [];
                foreach ([
                    'Bad States' => $bad_states,
                    'Age / Conditions' => $age_criteria,
                    'Qualifying Questions' => $qualifying_questions,
                    'Other Criteria' => $other_criteria
                ] as $label => $section_value) {
                    if (trim($section_value) !== '') {
                        $criteria_sections[] = $label . ":\n" . $section_value;
                    }
                }
                $criteria_text = implode("\n\n", $criteria_sections);
                $criteria_stmt->bind_param(
                    'iiisssss',
                    $client_id,
                    $vertical_id,
                    $is_active,
                    $criteria_text,
                    $bad_states,
                    $age_criteria,
                    $qualifying_questions,
                    $other_criteria
                );
                if (!$criteria_stmt->execute()) {
                    throw new RuntimeException('Unable to migrate criteria cell: ' . $criteria_stmt->error);
                }
            }
        }
        $criteria_stmt->close();
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit legacy criteria migration: ' . $conn->error);
        }
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function workspace_invalidate_criteria_cache(): void
{
    if (
        !function_exists('workspace_pusher_server')
        || !defined('WORKSPACE_PUSHER_KEY')
        || !defined('WORKSPACE_PUSHER_SECRET')
        || !defined('WORKSPACE_PUSHER_APP_ID')
        || !defined('WORKSPACE_PUSHER_CLUSTER')
    ) {
        return;
    }

    try {
        workspace_pusher_server()->trigger('global-updates', 'cache-invalidate', ['section' => 'criteria']);
    } catch (Throwable $error) {
        error_log('[Criteria] Cache invalidation failed: ' . $error->getMessage());
    }
}
