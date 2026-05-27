<?php
require __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW TABLES LIKE 'compliance_tracking'");
echo "compliance_tracking exists: " . $res->num_rows . "\n";
