<?php
// db.php

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}


$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'ua_qms';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS');
$port = (int) (getenv('DB_PORT') ?: 3306);

if ($pass === false) {
    $pass = getenv('DB_PASSWORD');
}

if ($pass === false) {
    $pass = '';
}

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
