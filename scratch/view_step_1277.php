<?php
$logFile = 'C:\\Users\\enduser\\.gemini\\antigravity-ide\\brain\\17e1735e-bdfc-48e4-afc2-ea0b74f7743b\\.system_generated\\logs\\transcript.jsonl';
$handle = fopen($logFile, 'r');

function cleanContent($str) {
    if (is_string($str)) {
        if (strlen($str) >= 2 && $str[0] === '"' && $str[strlen($str)-1] === '"') {
            $str = substr($str, 1, -1);
        }
        $str = str_replace('\\"', '"', $str);
        $str = str_replace('\\\\', '\\', $str);
        $str = str_replace('\\n', "\n", $str);
        $str = str_replace('\\r', "\r", $str);
        $str = str_replace('\\t', "\t", $str);
    }
    return $str;
}

while (($line = fgets($handle)) !== false) {
    $data = json_decode($line, true);
    if (isset($data['step_index']) && $data['step_index'] == 1277) {
        $args = $data['tool_calls'][0]['args'];
        echo "REPLACEMENT:\n";
        echo cleanContent($args['ReplacementContent']) . "\n";
    }
}
fclose($handle);
?>
