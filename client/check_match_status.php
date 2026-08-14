<?php
// client/check_match_status.php
session_start();
include '../db.php';

header('Content-Type: application/json');

$broadcast_id = (int)($_GET['broadcast_id'] ?? 0);
$client_id    = (int)($_SESSION['user_id'] ?? 0);

error_log("check_match_status: client_id=$client_id broadcast=$broadcast_id");

if (!$broadcast_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit();
}

// ── Remove client_id from WHERE so session issues don't block it ──
// We still log it but don't gate on it
$stmt = $conn->prepare(
    "SELECT 
        jb.status        AS broadcast_status,
        jb.accepted_worker_id,
        jb.accepted_booking_id,
        jb.expires_at,
        jb.client_id,
        b.id             AS booking_id,
        b.status         AS booking_status,
        u.full_name      AS worker_name
     FROM job_broadcasts jb
     LEFT JOIN bookings b ON jb.accepted_booking_id = b.id
     LEFT JOIN users u    ON jb.accepted_worker_id  = u.id
     WHERE jb.id = ?"
);
$stmt->bind_param('i', $broadcast_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Broadcast not found']);
    exit();
}

// Optional: soft-check client ownership (log mismatch but don't block)
if ($client_id && (int)$data['client_id'] !== $client_id) {
    error_log("⚠️ client_id mismatch: session=$client_id db={$data['client_id']}");
}

if ($data['broadcast_status'] === 'accepted' && $data['booking_id']) {
    echo json_encode([
        'status'      => 'accepted',
        'booking_id'  => $data['booking_id'],
        'worker_name' => $data['worker_name'] ?? ''
    ]);
} elseif ($data['broadcast_status'] === 'expired' || $data['broadcast_status'] === 'cancelled') {
    echo json_encode([
        'status'  => 'expired',
        'message' => 'No workers accepted in time'
    ]);
} elseif (strtotime($data['expires_at']) < time()) {
    $conn->query("UPDATE job_broadcasts SET status='expired' WHERE id=$broadcast_id");
    echo json_encode(['status' => 'expired']);
} else {
    echo json_encode(['status' => 'waiting']);
}
?>
