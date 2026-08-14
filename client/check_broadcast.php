<?php
// client/check_broadcast.php
session_start();
include '../db.php';

$client_id = $_SESSION['user_id'] ?? 1;

// Get latest broadcast
$sql = "SELECT * FROM job_broadcasts WHERE client_id = '$client_id' ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);
$broadcast = $result->fetch_assoc();

echo "<h2>Latest Broadcast</h2>";
echo "<pre>";
print_r($broadcast);
echo "</pre>";

if ($broadcast) {
    // Get candidates
    $sql = "SELECT * FROM job_candidates WHERE broadcast_id = '{$broadcast['id']}'";
    $result = $conn->query($sql);
    
    echo "<h3>Candidates:</h3>";
    echo "<pre>";
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
    echo "</pre>";
    
    // Get bookings
    $sql = "SELECT * FROM bookings WHERE client_id = '$client_id' ORDER BY id DESC LIMIT 5";
    $result = $conn->query($sql);
    
    echo "<h3>Recent Bookings:</h3>";
    echo "<pre>";
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
    echo "</pre>";
}
?>