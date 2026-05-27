<?php
require 'c:/laragon/www/ua-qms/config/db.php';
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('active_academic_year', 'active_semester')");
while($row = $r->fetch_assoc()) {
    print_r($row);
}
echo "\n--- Documents ---\n";
$r = $conn->query("SELECT document_id, file_name, status, academic_year, semester FROM documents ORDER BY document_id DESC LIMIT 5");
while($row = $r->fetch_assoc()) {
    print_r($row);
}
