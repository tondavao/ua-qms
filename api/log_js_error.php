<?php
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    $logMsg = date('Y-m-d H:i:s') . " - JS Error: " . ($data['message'] ?? '') . " | Source: " . ($data['source'] ?? '') . ":" . ($data['lineno'] ?? '') . ":" . ($data['colno'] ?? '') . "\nStack: " . ($data['stack'] ?? '') . "\n\n";
    file_put_contents(__DIR__ . '/../js_errors.log', $logMsg, FILE_APPEND);
}
echo json_encode(["success" => true]);
