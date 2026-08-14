<?php
// api/save_message.php
include '../db_connect.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$booking_id   = isset($data['booking_id'])  ? (int)$data['booking_id']      : 0;
$sender_id    = isset($data['sender_id'])   ? (int)$data['sender_id']       : 0;
$message      = isset($data['message'])     ? trim($data['message'])        : '';
$message_type = isset($data['message_type'])? trim($data['message_type'])   : 'text';

if (!$booking_id || !$sender_id || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

if ($sender_id !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit();
}

$check = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND (client_id = ? OR worker_id = ?)");
$check->execute([$booking_id, $sender_id, $sender_id]);
if (!$check->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to this booking']);
    exit();
}

$stmt = $conn->prepare("INSERT INTO messages (booking_id, sender_id, message, message_type) VALUES (?, ?, ?, ?)");

if ($stmt->execute([$booking_id, $sender_id, $message, $message_type])) {
    echo json_encode(['success' => true, 'message_id' => $conn->lastInsertId('messages_id_seq')]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save message']);
}
?>