<?php
// worker/api/accept_quick_match.php
session_start();
include '../../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// At the top of accept_quick_match.php, right after session_start()
error_log("=== ACCEPT QUICK MATCH CALLED ===");
error_log("POST data: " . file_get_contents('php://input'));

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = intval($data['booking_id'] ?? 0);
$broadcast_id = intval($data['broadcast_id'] ?? 0);
$worker_id = intval($_SESSION['user_id']);

if (!$booking_id || !$broadcast_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // Check if broadcast is still available and not expired
    $check = $conn->query("SELECT * FROM job_broadcasts 
                           WHERE id = '$broadcast_id' 
                           AND status = 'searching' 
                           AND expires_at > NOW() 
                           AND (JSON_CONTAINS(candidate_workers, '\"$worker_id\"') OR candidate_workers LIKE '%$worker_id%')
                           FOR UPDATE");
    
    if ($check->num_rows === 0) {
        throw new Exception('This job is no longer available or has expired');
    }
    
    $broadcast = $check->fetch_assoc();
    
    // Update broadcast to accepted
    $conn->query("UPDATE job_broadcasts 
                  SET status = 'accepted', 
                      accepted_worker_id = '$worker_id',
                      accepted_booking_id = '$booking_id'
                  WHERE id = '$broadcast_id'");
    
    // Update the specific booking to Accepted
    $conn->query("UPDATE bookings 
                  SET status = 'Accepted', 
                      updated_at = NOW() 
                  WHERE id = '$booking_id' AND worker_id = '$worker_id'");
    
    // Cancel all other pending bookings for this broadcast
    $conn->query("UPDATE bookings 
                  SET status = 'Cancelled', 
                      updated_at = NOW() 
                  WHERE broadcast_id = '$broadcast_id' 
                  AND id != '$booking_id'");
    
    // Get worker name and client ID for notification
    $worker = $conn->query("SELECT full_name FROM users WHERE id = '$worker_id'")->fetch_assoc();
    $client_id = $broadcast['client_id'];
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'booking_id' => $booking_id,
        'client_id' => $client_id,
        'worker_name' => $worker['full_name']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>