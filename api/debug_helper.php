<?php
// Secure to local requests only or QMO session
session_start();
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'QMO Admin')) {
    exit('Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (isset($input['log'])) {
    file_put_contents(__DIR__ . '/../debug_log.txt', $input['log'] . "\n", FILE_APPEND);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
