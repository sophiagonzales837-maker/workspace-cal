<?php
// FILE: config/env.php

if (!function_exists('workspace_load_env')) {
    function workspace_load_env($filePath = null) {
        static $cache = [];

        $filePath = $filePath ?: __DIR__ . '/.env';
        if (isset($cache[$filePath])) {
            return $cache[$filePath];
        }

        $values = [];
        if (!is_file($filePath)) {
            return $cache[$filePath] = $values;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $cache[$filePath] = $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $cache[$filePath] = $values;
    }
}
?>