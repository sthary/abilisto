<?php
require_once __DIR__ . '/config/dotenv.php';

// Only show PHP errors when APP_DEBUG=1 in .env — never on live production.
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Supabase Postgres credentials — set in .env, see .env.example
$pg_host = getenv('PG_HOST');
$pg_port = getenv('PG_PORT');
$pg_name = getenv('PG_NAME');
$pg_user = getenv('PG_USER');
$pg_pass = getenv('PG_PASS');

try {
    $conn = new PDO("pgsql:host=$pg_host;port=$pg_port;dbname=$pg_name", $pg_user, $pg_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // SAKTO NGA PHILIPPINES TIME (+08:00) aron dili maguba ang 48-hour escrow logic
    $conn->exec("SET TIME ZONE '+08:00'");
} catch (PDOException $e) {
    die("Postgres connection failed: " . $e->getMessage());
}

// Same connection under both names — some files use $pdo (added originally
// for "the new Business Dashboard"), most use $conn. One PDO instance now.
$pdo = $conn;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendNotification($conn, $user_id, $message, $link = '#') {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    if ($stmt->execute([$user_id, $message, $link])) {
        return true;
    } else {
        // This will freeze the screen and show the exact error!
        die("🚨 NOTIFICATION SQL ERROR: " . implode(' ', $stmt->errorInfo()));
    }
}
