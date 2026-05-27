<?php

$root = realpath(dirname(__DIR__));
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$request_path = rawurldecode((string) parse_url($request_uri, PHP_URL_PATH));

if ($request_path === '' || $request_path === false) {
    $request_path = '/';
}

$request_path = '/' . ltrim($request_path, '/');

if ($request_path === '/') {
    $request_path = '/index.php';
} elseif (substr($request_path, -1) === '/') {
    $request_path .= 'index.php';
} elseif (pathinfo($request_path, PATHINFO_EXTENSION) === '') {
    $candidate = $root . $request_path . '.php';
    if (is_file($candidate)) {
        $request_path .= '.php';
    }
}

$segments = explode('/', trim($request_path, '/'));
$blocked_roots = ['config', 'database', 'deployment', 'includes', 'scratch', 'tmp'];

if (in_array($segments[0] ?? '', $blocked_roots, true)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$target = realpath($root . $request_path);

if (
    $target === false ||
    strncmp($target, $root, strlen($root)) !== 0 ||
    !is_file($target) ||
    strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'php'
) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$script_name = str_replace('\\', '/', substr($target, strlen($root)));
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['SCRIPT_FILENAME'] = $target;
$_SERVER['SCRIPT_NAME'] = $script_name;
$_SERVER['PHP_SELF'] = $script_name;

chdir(dirname($target));
require $target;
