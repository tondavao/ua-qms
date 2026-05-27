<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SELECT o.*, c.category_name FROM offices o LEFT JOIN unit_categories c ON o.category_id = c.category_id ORDER BY o.office_id ASC");
while ($row = $res->fetch_assoc()) {
    echo "ID: {$row['office_id']} | CODE: {$row['office_code']} | NAME: {$row['office_name']} | STATUS: {$row['status']} | CAT: {$row['category_name']}\n";
}
?>
