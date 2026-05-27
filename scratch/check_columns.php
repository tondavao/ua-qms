<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("DESCRIBE documents");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
