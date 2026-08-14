<?php
// upload.php — Handles media uploads for chat
// Saves file to /uploads/chat/, records to DB, returns JSON with URL

include 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$sender_id  = (int) $_SESSION['user_id'];
$booking_id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;

if (!$booking_id) {
    echo json_encode(['success' => false, 'error' => 'Missing booking_id']);
    exit;
}

// Verify the user belongs to this booking
$check = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND (client_id = ? OR worker_id = ?)");
$check->execute([$booking_id, $sender_id, $sender_id]);
if (!$check->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file received']);
    exit;
}

$file    = $_FILES['file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit;
}

$max_size = 20 * 1024 * 1024; // 20MB
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 20MB.']);
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = __DIR__ . '/uploads/chat/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$filename  = uniqid('chat_', true) . '.' . $ext;
$dest      = $upload_dir . $filename;
$file_url  = '/uploads/chat/' . $filename; // relative URL served by Apache/Nginx

$is_video = in_array($ext, ['mp4', 'mov']);
$msg_type = $is_video ? 'video' : 'image';

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
    exit;
}

// Save to messages table
// message = empty string for media, attachment_path = file URL, message_type = image|video
$stmt = $conn->prepare(
    "INSERT INTO messages (booking_id, sender_id, message, attachment_path, message_type, \"timestamp\")
     VALUES (?, ?, '', ?, ?, NOW())"
);
$stmt->execute([$booking_id, $sender_id, $file_url, $msg_type]);
$message_id = $conn->lastInsertId('messages_id_seq');

echo json_encode([
    'success'    => true,
    'url'        => $file_url,
    'message_id' => $message_id,
    'type'       => $msg_type   // 'image' or 'video'
]);