<?php
// api/update_location.php
session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

// Create a log file
$log_file = __DIR__ . '/location_debug.log';

function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

writeLog("=== UPDATE LOCATION CALLED ===");
writeLog("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
writeLog("Session role: " . ($_SESSION['role'] ?? 'NOT SET'));

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    writeLog("❌ Unauthorized access");
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$worker_id = $_SESSION['user_id'];
$lat = floatval($_POST['lat'] ?? 0);
$lng = floatval($_POST['lng'] ?? 0);

writeLog("Worker ID: $worker_id");
writeLog("Received coordinates: lat=$lat, lng=$lng");

if (!$lat || !$lng) {
    writeLog("❌ Invalid coordinates");
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

// Get current values before update
$before_stmt = $conn->prepare("SELECT t_latitude, t_longitude, latitude, longitude FROM users WHERE id = ?");
$before_stmt->execute([$worker_id]);
$before = $before_stmt->fetch();
writeLog("BEFORE - tracking: ({$before['t_latitude']}, {$before['t_longitude']}), home: ({$before['latitude']}, {$before['longitude']})");

// Update ONLY tracking location
$sql = "UPDATE users SET t_latitude = ?, t_longitude = ? WHERE id = ?";
writeLog("SQL: $sql");
$update_stmt = $conn->prepare($sql);

if ($update_stmt->execute([$lat, $lng, $worker_id])) {
    // Get values after update
    $after_stmt = $conn->prepare("SELECT t_latitude, t_longitude, latitude, longitude FROM users WHERE id = ?");
    $after_stmt->execute([$worker_id]);
    $after = $after_stmt->fetch();
    writeLog("AFTER - tracking: ({$after['t_latitude']}, {$after['t_longitude']}), home: ({$after['latitude']}, {$after['longitude']})");

    // Check if they match what we set
    if ($after['t_latitude'] == $lat && $after['t_longitude'] == $lng) {
        writeLog("✅ Update successful - tracking coordinates match");
    } else {
        writeLog("⚠️ Update may have been overwritten - tracking doesn't match what we set");
    }

    echo json_encode(['success' => true, 'message' => 'Tracking location updated']);
} else {
    writeLog("❌ Database error");
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>