<?php
$lines = file(__DIR__ . '/../dashboard/qmo/settings.php');
foreach ($lines as $i => $line) {
    if (strpos($line, 'escapeHtml') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
