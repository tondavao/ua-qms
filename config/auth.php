<?php
// auth.php

function isAuthenticated() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'QMO Admin') {
        return true;
    }

    $API_KEY = getenv('UA_QMS_API_KEY') ?: 'supersecretapikey';

    $auth = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if ($auth !== '' && preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
        return hash_equals($API_KEY, $matches[1]);
    }
    return false;
}
