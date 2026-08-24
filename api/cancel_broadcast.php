<?php
// api/cancel_broadcast.php
// Called by waiting_match.php when client clicks "Cancel Search"
// 1. Sets job_broadcasts.status = 'cancelled'
// 2. Sets all related bookings.status = 'Cancelled' (the ghost bookings created per worker)
// Both in one transaction so they're always in sync.

session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../includes/functions/feature_flags.php';
if (!isFeatureEnabled($conn, 'feature_quickmatch_enabled')) {
    echo json_encode(['success' => false, 'message' => 'Quick Match is temporarily unavailable']);
    exit();
}

$client_id   = intval($_SESSION['user_id']);
$input        = json_decode(file_get_contents('php://input'), true);
$broadcast_id = intval($input['broadcast_id'] ?? 0);
$reason       = ($input['reason'] ?? '') === 'expired' ? 'expired' : 'cancelled';
$new_status   = $reason === 'expired' ? 'expired' : 'cancelled';

if (!$broadcast_id) {
    echo json_encode(['success' => false, 'message' => 'Missing broadcast ID']);
    exit();
}

// Verify the broadcast belongs to this client and is still cancellable
$stmt = $conn->prepare(
    "SELECT id, status FROM job_broadcasts WHERE id = ? AND client_id = ?"
);
$stmt->execute([$broadcast_id, $client_id]);
$broadcast = $stmt->fetch();

if (!$broadcast) {
    echo json_encode(['success' => false, 'message' => 'Broadcast not found']);
    exit();
}

if ($broadcast['status'] === 'accepted') {
    echo json_encode(['success' => false, 'message' => 'A worker already accepted — cannot cancel']);
    exit();
}

if ($broadcast['status'] === 'cancelled') {
    // Already cancelled — treat as success so UI can still redirect
    echo json_encode(['success' => true, 'message' => 'Already cancelled']);
    exit();
}

// ── Transaction: cancel broadcast + all its pending ghost bookings ──
$conn->beginTransaction();

try {
    // 1. Cancel/expire the broadcast
    $stmt = $conn->prepare(
        "UPDATE job_broadcasts SET status = ? WHERE id = ? AND client_id = ?"
    );
    $stmt->execute([$new_status, $broadcast_id, $client_id]);

    // 2. Cancel all Pending bookings tied to this broadcast
    //    (these were created upfront in confirm_quick_match.php, one per candidate worker)
    $stmt = $conn->prepare(
        "UPDATE bookings SET status = 'Cancelled' WHERE broadcast_id = ? AND status = 'Pending'"
    );
    $stmt->execute([$broadcast_id]);

    $cancelled_bookings = $stmt->rowCount();

    $conn->commit();

    error_log("✅ Broadcast #$broadcast_id marked '$new_status' by client #$client_id. Cancelled $cancelled_bookings ghost bookings.");

    echo json_encode([
        'success'            => true,
        'message'            => 'Search cancelled successfully',
        'cancelled_bookings' => $cancelled_bookings
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    error_log("❌ cancel_broadcast error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error — please try again']);
}
?>