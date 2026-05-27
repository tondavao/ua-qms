<?php
require 'c:/laragon/www/ua-qms/config/db.php';
$r = $conn->query("SELECT COUNT(*) FROM offices WHERE status='Active' AND is_archived=FALSE");
print_r($r->fetch_row());
