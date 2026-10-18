<?php
// FILE: workspace/config/pusher.php

require_once __DIR__ . '/env.php';

// Securely grab the Pusher keys from the vault
$env = workspace_load_env();

if (!defined('WORKSPACE_PUSHER_KEY') && !empty($env['PUSHER_KEY'])) define('WORKSPACE_PUSHER_KEY', $env['PUSHER_KEY']);
if (!defined('WORKSPACE_PUSHER_SECRET') && !empty($env['PUSHER_SECRET'])) define('WORKSPACE_PUSHER_SECRET', $env['PUSHER_SECRET']);
if (!defined('WORKSPACE_PUSHER_APP_ID') && !empty($env['PUSHER_APP_ID'])) define('WORKSPACE_PUSHER_APP_ID', $env['PUSHER_APP_ID']);
if (!defined('WORKSPACE_PUSHER_CLUSTER') && !empty($env['PUSHER_CLUSTER'])) define('WORKSPACE_PUSHER_CLUSTER', $env['PUSHER_CLUSTER']);

if (!function_exists('workspace_pusher_encode_query')) {
    function workspace_pusher_encode_query(array $params) {
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('workspace_pusher_request')) {
    function workspace_pusher_request($method, $path, array $queryParams, $body = null) {
        if (!defined('WORKSPACE_PUSHER_KEY') || !defined('WORKSPACE_PUSHER_SECRET') || !defined('WORKSPACE_PUSHER_APP_ID') || !defined('WORKSPACE_PUSHER_CLUSTER')) {
            return false;
        }

        $query = workspace_pusher_encode_query($queryParams);
        $bodyString = $body === null ? '' : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES));
        $stringToSign = strtoupper($method) . "\n" . $path . "\n" . $query;
        $signature = hash_hmac('sha256', $stringToSign, WORKSPACE_PUSHER_SECRET);
        $url = 'https://api-' . WORKSPACE_PUSHER_CLUSTER . '.pusher.com' . $path . '?' . $query . '&auth_signature=' . $signature;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $bodyString,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
            ]);
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                error_log("Pusher cURL Error: " . curl_error($ch));
            }
            
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $response !== false && $httpCode >= 200 && $httpCode < 300;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => "Content-Type: application/json\r\n",
                'content' => $bodyString,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) { return false; }
        return isset($http_response_header[0]) && preg_match('/^HTTP\/\S+\s+2\d\d/', $http_response_header[0]);
    }
}

if (!function_exists('workspace_trigger_pusher_event')) {
    function workspace_trigger_pusher_event($channel, $event, array $payload) {
        $path = '/apps/' . rawurlencode(WORKSPACE_PUSHER_APP_ID) . '/events';
        
        $body = [
            'name' => $event,
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];

        // NEW LOGIC: Support ringing multiple personal channels at once
        if (is_array($channel)) {
            $body['channels'] = array_slice($channel, 0, 100); // Pusher max limit is 100
        } else {
            $body['channel'] = $channel;
        }

        $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES);

        $queryParams = [
            'auth_key' => WORKSPACE_PUSHER_KEY,
            'auth_timestamp' => time(),
            'auth_version' => '1.0',
            'body_md5' => md5($bodyString),
        ];

        return workspace_pusher_request('POST', $path, $queryParams, $body);
    }
}

if (!function_exists('workspace_pusher_server')) {
    function workspace_pusher_server() {
        static $pusher = null;
        if ($pusher !== null) { return $pusher; }

        $pusher = new class {
            public function trigger($channel, $event, array $payload) {
                $result = workspace_trigger_pusher_event($channel, $event, $payload);
                if (!$result) throw new Exception("Custom Pusher request failed.");
                return true;
            }
        };
        return $pusher;
    }
}
?>