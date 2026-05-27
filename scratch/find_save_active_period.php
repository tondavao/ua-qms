<?php
$lines = file(__DIR__ . '/../dashboard/qmo/settings.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'saveActivePeriod') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
