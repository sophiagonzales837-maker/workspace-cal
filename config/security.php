<?php
// FILE: config/security.php

require_once __DIR__ . '/env.php';

// Securely grab the environment values from the vault
$env = workspace_load_env();

if (!defined('BASE_PATH')) define('BASE_PATH', $env['BASE_PATH'] ?? '/workspace/');
if (!defined('BASE_URL')) define('BASE_URL', 'http://employees.kingstonsolutions.co.in/workspace/');

if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', $env['ENCRYPTION_KEY']);
if (!defined('ENCRYPTION_METHOD')) define('ENCRYPTION_METHOD', 'AES-256-CBC');

if (!function_exists('workspace_role_folders')) {
    function workspace_role_folders(): array
    {
        return [
            'Admin' => 'admin',
            'Agent' => 'agent',
            'Closer' => 'closer',
            'Verifier' => 'verifier',
            'Client' => 'client',
            'Auditor' => 'admin',
            'Jr. Admin' => 'jr_admin',
            'Jr Admin' => 'jr_admin',
            'Jr_Admin' => 'jr_admin',
            'Quality Check' => 'quality_checker',
            'Quality Checker' => 'quality_checker',
            'QC' => 'quality_checker'
        ];
    }
}

if (!function_exists('encryptData')) {
    function encryptData($data)
    {
        if (empty($data)) return $data;
        $iv_length = openssl_cipher_iv_length(ENCRYPTION_METHOD);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
}

if (!function_exists('decryptData')) {
    function decryptData($data)
    {
        if (empty($data)) return $data;

        $decoded = base64_decode($data, true);
        if ($decoded === false || strpos($decoded, '::') === false) {
            return $data;
        }

        list($encrypted_data, $iv) = explode('::', $decoded, 2);

        $iv_length = openssl_cipher_iv_length(ENCRYPTION_METHOD);
        if (strlen($iv) !== $iv_length) {
            return "[Data Truncated in DB]";
        }

        $decrypted = openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
        return $decrypted !== false ? $decrypted : "[Decryption Failed]";
    }
}

if (!function_exists('decrypt_fields')) {
    function decrypt_fields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && $value !== '') {
                $data[$key] = decryptData($value);
            }
        }
        return $data;
    }
}

if (!function_exists('createBlindHash')) {
    function createBlindHash($data)
    {
        if (empty($data)) return '';
        return hash_hmac('sha256', strtolower(trim($data)), ENCRYPTION_KEY);
    }
}

if (!function_exists('protect')) {
    function protect($allowed_roles)
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.gc_maxlifetime', 43200);
            ini_set('session.cookie_lifetime', 43200);
            session_set_cookie_params(43200);
            session_start();
        }
        if (!is_array($allowed_roles)) {
            $allowed_roles = [$allowed_roles];
        }
        $session_role = $_SESSION['role'] ?? null;
        $jr_admin_roles = ['Jr. Admin', 'Jr Admin', 'Jr_Admin'];
        $role_is_allowed = $session_role !== null && in_array($session_role, $allowed_roles, true);
        if (!$role_is_allowed && $session_role !== null && in_array($session_role, $jr_admin_roles, true)) {
            $role_is_allowed = (bool)array_intersect($jr_admin_roles, $allowed_roles);
        }
        if (!$role_is_allowed) {
            header("Location: " . BASE_PATH . "auth/login.php");
            exit();
        }
    }
}

if (!defined('WORKSPACE_PUSHER_KEY') && !empty($env['PUSHER_KEY'])) define('WORKSPACE_PUSHER_KEY', $env['PUSHER_KEY']);
if (!defined('WORKSPACE_PUSHER_SECRET') && !empty($env['PUSHER_SECRET'])) define('WORKSPACE_PUSHER_SECRET', $env['PUSHER_SECRET']);
if (!defined('WORKSPACE_PUSHER_APP_ID') && !empty($env['PUSHER_APP_ID'])) define('WORKSPACE_PUSHER_APP_ID', $env['PUSHER_APP_ID']);
if (!defined('WORKSPACE_PUSHER_CLUSTER') && !empty($env['PUSHER_CLUSTER'])) define('WORKSPACE_PUSHER_CLUSTER', $env['PUSHER_CLUSTER']);

if (!function_exists('notify')) {
    function notify($user_id, $message, $link = null, $conn = null)
    {
        global $conn;
        if (!$conn) return false;

        $user_id = (int)$user_id;
        $message = mysqli_real_escape_string($conn, $message);
        $link = $link ? mysqli_real_escape_string($conn, $link) : null;

        $query = "INSERT INTO notifications (user_id, message, link) VALUES ($user_id, '$message', " . ($link ? "'$link'" : "NULL") . ")";
        $result = mysqli_query($conn, $query);

        if ($result && function_exists('workspace_pusher_server')) {
            try {
                $pusher = workspace_pusher_server();
                $pusher->trigger('user-' . $user_id . '-notifications', 'new-notification', [
                    'user_id' => $user_id,
                    'message' => $message,
                    'link' => $link
                ]);
            } catch (Exception $e) {
                error_log('[PUSHER] Notification trigger failed: ' . $e->getMessage());
            }
        }

        return $result;
    }
}
