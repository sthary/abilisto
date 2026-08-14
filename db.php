<?php
require_once __DIR__ . '/config/env.php';

// Only show PHP errors when APP_DEBUG=1 in .env — never on live production.
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// HOSTINGER CREDENTIALS — set in .env, see .env.example
$servername = getenv('DB_HOST');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');
$dbname = getenv('DB_NAME');

// 1. Existing MySQLi Connection (For your old code)
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("MySQLi Connection failed: " . $conn->connect_error);
}

// SAKTO NGA PHILIPPINES TIME (+08:00) aron dili maguba ang 48-hour escrow logic
$conn->query("SET time_zone = '+08:00'"); 

// 2. NEW PDO Connection (For the new Business Dashboard)
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Enable error reporting for easier debugging
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set PDO timezone as well to match MySQLi
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("PDO Connection failed: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendNotification($conn, $user_id, $message, $link = '#') {
    $clean_msg = $conn->real_escape_string($message);
    $clean_link = $conn->real_escape_string($link);
    
    $sql = "INSERT INTO notifications (user_id, message, link) 
            VALUES ('$user_id', '$clean_msg', '$clean_link')";
    
    if ($conn->query($sql) === TRUE) {
        return true;
    } else {
        // This will freeze the screen and show the exact error!
        die("🚨 NOTIFICATION SQL ERROR: " . $conn->error); 
    }
}
?>