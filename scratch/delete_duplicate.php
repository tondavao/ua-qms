<?php
require_once __DIR__ . '/../config/db.php';
$conn->query("DELETE FROM offices WHERE office_id = 10");
echo "Deleted office ID 10.\n";
?>
