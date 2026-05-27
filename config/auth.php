<?php
// auth.php

// Example: Simple API key check
function isAuthenticated() {
    // Replace with your secret API key
    $API_KEY = 'supersecretapikey';

    // Check header for 'Authorization: Bearer <key>'
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            $token = $matches[1];
            return $token === $API_KEY;
        }
    }
    return false;
}
