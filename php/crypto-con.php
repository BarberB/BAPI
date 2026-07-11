<?php

$dbConfig = require '/etc/web-applications/trading-app/database.php';

$mysqli = new mysqli(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database']
);

if ($mysqli->connect_errno) {
    error_log('Crypto DB connection failed: ' . $mysqli->connect_error);
    http_response_code(500);
    exit('Database connection failed.');
}

if (!$mysqli->set_charset($dbConfig['charset'] ?? 'utf8mb4')) {
    error_log('Crypto DB charset failed: ' . $mysqli->error);
    http_response_code(500);
    exit('Database connection failed.');
}
