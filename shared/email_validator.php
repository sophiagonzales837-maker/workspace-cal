<?php
session_start();
header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');

// 1. Format Check
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['valid' => false, 'message' => 'Invalid format']);
    exit;
}

// 2. Domain (MX Record) Check
$domain = substr(strrchr($email, '@'), 1);
if (checkdnsrr($domain, 'MX')) {
    echo json_encode(['valid' => true, 'message' => 'Domain Verified']);
} else {
    echo json_encode(['valid' => false, 'message' => 'Dead/Fake Domain']);
}
