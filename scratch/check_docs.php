<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SELECT d.document_id, d.file_name, d.status, d.academic_year, d.semester, o.office_code FROM documents d JOIN offices o ON d.office_id = o.office_id");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}
?>
