<?php
// worker/process_scan.php
session_start();
require_once '../db.php';
require_once '../includes/services/DocumentAIService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$worker_id = $_SESSION['user_id'];
$document_type = $_POST['document_type'] ?? '';

if (!$document_type) {
    echo json_encode(['success' => false, 'message' => 'Missing document type']);
    exit();
}

// Check if file was uploaded
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No document uploaded']);
    exit();
}

$tmp_file = $_FILES['document']['tmp_name'];
$file_name = time() . '_' . $worker_id . '_scan.jpg';

// Initialize Document AI
$docAI = new DocumentAIService();

// Process based on document type
if ($document_type == 'id') {
    $result = $docAI->extractIDInfo($tmp_file);
} else {
    $result = $docAI->extractNCInfo($tmp_file);
}

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Processing failed']);
    exit();
}

// Return extracted data
echo json_encode([
    'success' => true,
    'data' => $result['data'] ?? ['text' => $result['text']]
]);