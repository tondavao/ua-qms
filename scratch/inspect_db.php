<?php
require_once __DIR__ . '/../config/db.php';
echo "--- Academic Years ---\n";
$res = $conn->query("SELECT * FROM academic_years");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "\n--- Semesters ---\n";
$res = $conn->query("SELECT * FROM semesters");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
?>
