<?php
// api/check_broadcast_status.php
session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['available' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../includes/functions/feature_flags.php';
if (!isFeatureEnabled($conn, 'feature_quickmatch_enabled')) {
    echo json_encode(['available' => false, 'reason' => 'feature_disabled']);
    exit();
}

$booking_id = $_GET['booking_id'] ?? 0;

if (!$booking_id) {
    echo json_encode(['available' => false, 'message' => 'Missing booking ID']);
    exit();
}

$worker_id = $_SESSION['user_id'];

$sql = "SELECT 
            b.*, 
            jb.status as broadcast_status, 
            jb.expires_at,
            jb.id as broadcast_id
        FROM bookings b
        LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE b.id = ? AND b.worker_id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$booking_id, $worker_id]);

if ($row = $stmt->fetch()) {
    if (!empty($row['broadcast_id'])) {
        // This is a quick match booking
        if ($row['broadcast_status'] === 'accepted') {
            echo json_encode(['available' => false, 'reason' => 'already_taken']);
        } elseif (strtotime($row['expires_at']) < time()) {
            echo json_encode(['available' => false, 'reason' => 'expired']);
        } else {
            echo json_encode(['available' => true]);
        }
    } else {
        // Regular booking
        echo json_encode(['available' => true]);
    }
} else {
    echo json_encode(['available' => false, 'reason' => 'not_found']);
}
?>