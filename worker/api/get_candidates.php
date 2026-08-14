<?php
// worker/api/get_candidates.php
session_start();
include '../../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$worker_id = $_SESSION['user_id'];

$sql = "SELECT 
            b.*,
            u.full_name as client_name,
            u.address,
            jc.score
        FROM job_candidates jc
        JOIN job_broadcasts b ON jc.broadcast_id = b.id
        JOIN users u ON b.client_id = u.id
        WHERE jc.worker_id = '$worker_id'
            AND b.status = 'searching'
            AND b.expires_at > NOW()
        ORDER BY jc.score DESC, b.created_at DESC";

$result = $conn->query($sql);
$jobs = [];

while ($row = $result->fetch_assoc()) {
    $jobs[] = $row;
}

echo json_encode(['success' => true, 'jobs' => $jobs]);
?>