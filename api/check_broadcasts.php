<?php
// api/check_broadcasts.php
// Lightweight polling endpoint — worker dashboard calls this every 10s
// to check if any quick match broadcasts have been cancelled or expired.
// Returns JSON: { "statuses": { "booking_id": "searching|cancelled|expired|accepted" } }

session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions/feature_flags.php';
if (!isFeatureEnabled($conn, 'feature_quickmatch_enabled')) {
    echo json_encode(['statuses' => []]);
    exit();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$worker_id = intval($_SESSION['user_id']);

// Expect a JSON body: { "booking_ids": [1, 2, 3] }
$input      = json_decode(file_get_contents('php://input'), true);
$booking_ids = array_map('intval', $input['booking_ids'] ?? []);

if (empty($booking_ids)) {
    echo json_encode(['statuses' => []]);
    exit();
}

// Build a safe IN clause
$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));

$sql  = "SELECT b.id AS booking_id,
                COALESCE(jb.status, 'none')                          AS broadcast_status,
                CASE WHEN jb.expires_at <= NOW() THEN 1 ELSE 0 END  AS is_expired,
                b.status                                              AS booking_status
         FROM bookings b
         LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
         WHERE b.worker_id = ? AND b.id IN ($placeholders)";

$stmt = $conn->prepare($sql);

// Bind worker_id + all booking_ids
$bind_params = array_merge([$worker_id], $booking_ids);
$stmt->execute($bind_params);

$statuses = [];
while ($row = $stmt->fetch()) {
    $bid = $row['booking_id'];

    if ($row['broadcast_status'] === 'cancelled') {
        $statuses[$bid] = 'cancelled';
    } elseif ($row['is_expired'] || $row['broadcast_status'] === 'expired') {
        $statuses[$bid] = 'expired';
    } elseif ($row['broadcast_status'] === 'accepted') {
        $statuses[$bid] = 'accepted';
    } else {
        $statuses[$bid] = 'searching'; // still live
    }
}

echo json_encode(['statuses' => $statuses]);
?>