<?php
// api/update_location.php
// Update worker's GPS location - NOW USES TRACKING COLUMNS

require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$worker_id = $_SESSION['user_id'];
$lat = floatval($_POST['lat'] ?? 0);
$lng = floatval($_POST['lng'] ?? 0);

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

// Update user's TRACKING location only - home location (latitude/longitude) stays fixed
$sql = "UPDATE users SET t_latitude = $lat, t_longitude = $lng WHERE id = $worker_id";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'Tracking location updated']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>