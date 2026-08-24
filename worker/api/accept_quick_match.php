<?php
// worker/api/accept_quick_match.php
session_start();
include '../../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../includes/functions/feature_flags.php';
if (!isFeatureEnabled($conn, 'feature_quickmatch_enabled')) {
    echo json_encode(['success' => false, 'message' => 'Quick Match is temporarily unavailable']);
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
$conn->beginTransaction();

try {
    // Check if broadcast is still available and not expired
    // (original MySQL condition was JSON_CONTAINS(candidate_workers, '"id"') OR candidate_workers LIKE '%id%' —
    //  the LIKE clause is a strict superset of the JSON_CONTAINS match, so it alone is equivalent here)
    $check_stmt = $conn->prepare("SELECT * FROM job_broadcasts
                           WHERE id = ?
                           AND status = 'searching'
                           AND expires_at > NOW()
                           AND candidate_workers LIKE ?
                           FOR UPDATE");
    $check_stmt->execute([$broadcast_id, '%' . $worker_id . '%']);
    $broadcast = $check_stmt->fetch();

    if (!$broadcast) {
        throw new Exception('This job is no longer available or has expired');
    }

    // Update broadcast to accepted
    $conn->prepare("UPDATE job_broadcasts
                  SET status = 'accepted',
                      accepted_worker_id = ?,
                      accepted_booking_id = ?
                  WHERE id = ?")
         ->execute([$worker_id, $booking_id, $broadcast_id]);

    // Update the specific booking to Accepted
    $conn->prepare("UPDATE bookings
                  SET status = 'Accepted',
                      updated_at = NOW()
                  WHERE id = ? AND worker_id = ?")
         ->execute([$booking_id, $worker_id]);

    // Cancel all other pending bookings for this broadcast
    $conn->prepare("UPDATE bookings
                  SET status = 'Cancelled',
                      updated_at = NOW()
                  WHERE broadcast_id = ?
                  AND id != ?")
         ->execute([$broadcast_id, $booking_id]);

    // Get worker name and client ID for notification
    $worker_name_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $worker_name_stmt->execute([$worker_id]);
    $worker = $worker_name_stmt->fetch();
    $client_id = $broadcast['client_id'];

    $conn->commit();

    echo json_encode([
        'success' => true,
        'booking_id' => $booking_id,
        'client_id' => $client_id,
        'worker_name' => $worker['full_name']
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>