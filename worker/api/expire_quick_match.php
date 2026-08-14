<?php
// worker/api/expire_quick_match.php
session_start();
include '../../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;
$worker_id = $_SESSION['user_id'];

// Get the broadcast ID for this booking
$sql = "SELECT broadcast_id FROM bookings WHERE id = ? AND worker_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$booking_id, $worker_id]);
$row = $stmt->fetch();

if ($row) {
    $broadcast_id = $row['broadcast_id'];

    if ($broadcast_id) {
        // Update broadcast to expired if still searching
        $update = "UPDATE job_broadcasts SET status = 'expired'
                   WHERE id = ? AND status = 'searching'";
        $stmt2 = $conn->prepare($update);
        $stmt2->execute([$broadcast_id]);
    }

    // Optionally update booking status
    // You might want to leave it as pending or mark as expired
}

echo json_encode(['success' => true]);
?>