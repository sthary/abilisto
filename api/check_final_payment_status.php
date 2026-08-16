<?php
// api/check_final_payment_status.php
// Lightweight poll target for worker/generate_receipt.php — lets the
// receipt page detect a client's Xendit/GCash payment completing (or a
// client's cash-completion confirmation) without a full page reload.
session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$worker_id  = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing booking_id']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT status, final_payment_status FROM bookings WHERE id = ? AND worker_id = ?"
);
$stmt->execute([$booking_id, $worker_id]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking not found']);
    exit();
}

echo json_encode([
    'status'               => $booking['status'],
    'final_payment_status' => $booking['final_payment_status'],
    'completed'            => $booking['status'] === 'Completed',
]);
