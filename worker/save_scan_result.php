<?php
// worker/save_scan_result.php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$worker_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$document_type = $data['document_type'] ?? '';
$extracted_data = $data['extracted_data'] ?? null;
$image_data = $data['image_data'] ?? '';

if (!$document_type || !$image_data) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit();
}

// Save image
$target_dir = "../uploads/verification/";
if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// Remove base64 header
$image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
$image_data = str_replace(' ', '+', $image_data);
$image_binary = base64_decode($image_data);

$file_name = time() . '_' . $worker_id . '_' . uniqid() . '.jpg';
$target_file = $target_dir . $file_name;

if (!file_put_contents($target_file, $image_binary)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save image']);
    exit();
}

// Save to database
$extracted_json = json_encode($extracted_data);
$status = $extracted_data ? 'pending' : 'pending_review';

$sql = "INSERT INTO verification_documents 
        (worker_id, document_type, file_path, extracted_data, status, uploaded_at) 
        VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("issss", $worker_id, $document_type, $target_file, $extracted_json, $status);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}