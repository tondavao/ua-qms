<?php
$lines = file(__DIR__ . '/../dashboard/qmo/settings.php');
foreach ($lines as $i => $line) {
    if (stripos($line, 'editOffice') !== false || stripos($line, 'edit_office') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
