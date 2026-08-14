<?php
// client/check_broadcast.php
session_start();
include '../db_connect.php';

$client_id = $_SESSION['user_id'] ?? 1;

// Get latest broadcast
$sql = "SELECT * FROM job_broadcasts WHERE client_id = ? ORDER BY id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([$client_id]);
$broadcast = $stmt->fetch();

echo "<h2>Latest Broadcast</h2>";
echo "<pre>";
print_r($broadcast);
echo "</pre>";

if ($broadcast) {
    // Get candidates
    $sql = "SELECT * FROM job_candidates WHERE broadcast_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$broadcast['id']]);

    echo "<h3>Candidates:</h3>";
    echo "<pre>";
    while ($row = $stmt->fetch()) {
        print_r($row);
    }
    echo "</pre>";

    // Get bookings
    $sql = "SELECT * FROM bookings WHERE client_id = ? ORDER BY id DESC LIMIT 5";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$client_id]);

    echo "<h3>Recent Bookings:</h3>";
    echo "<pre>";
    while ($row = $stmt->fetch()) {
        print_r($row);
    }
    echo "</pre>";
}
?>