<?php
// db.php – use DBWrapper (PDO) for Supabase/PostgreSQL
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

$host = getenv('DB_HOST') ?: '';
$db   = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$port = (int) (getenv('DB_PORT') ?: 5432);

// Build DSN for PostgreSQL (Supabase)
$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

require __DIR__ . '/DBWrapper.php';
$conn = new DBWrapper($dsn, $user, $pass);
$conn->set_charset('utf8');
?>
