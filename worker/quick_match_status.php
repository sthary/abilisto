<?php
// client/quick_match_status.php
require_once '../db_connect.php';
require_once '../ajax_auth_check.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    sendUnauthorized('Please login as client');
}

$client_id = intval($_SESSION['user_id']);
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

if ($session_id === 0) {
    echo json_encode(['error' => 'Invalid session ID']);
    exit();
}

// Get session status with prepared statement
$stmt = $conn->prepare("
    SELECT status, expires_at
    FROM quick_match_sessions
    WHERE id = ? AND client_id = ?
");
$stmt->execute([$session_id, $client_id]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['error' => 'Session not found']);
    exit();
}

// Get workers status
$stmt2 = $conn->prepare("
    SELECT
        qmb.status,
        u.full_name
    FROM quick_match_broadcasts qmb
    JOIN users u ON qmb.worker_id = u.id
    WHERE qmb.session_id = ?
    ORDER BY qmb.id
");
$stmt2->execute([$session_id]);
$workerList = [];
while ($worker = $stmt2->fetch()) {
    $workerList[] = [
        'full_name' => $worker['full_name'],
        'status' => $worker['status']
    ];
}

// Check if session has expired
if (strtotime($session['expires_at']) < time()) {
    $session['status'] = 'expired';
}

echo json_encode([
    'success' => true,
    'status' => $session['status'],
    'expires_at' => $session['expires_at'],
    'workers' => $workerList
]);
?>