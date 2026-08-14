<?php
// admin/get_violations.php  — AJAX endpoint (Support Admin)
include '../db.php';
include '../includes/init_lang.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([]); exit();
}

$user_id = intval($_GET['user_id'] ?? 0);
if (!$user_id) { echo json_encode([]); exit(); }

$res = $conn->query("SELECT vl.*, u.full_name as actioned_by_name
    FROM violation_logs vl
    LEFT JOIN users u ON u.id = vl.actioned_by
    WHERE vl.user_id = $user_id
    ORDER BY vl.created_at DESC
    LIMIT 20");

$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = [
        'report_id'    => $r['report_id'],
        'report_type'  => $r['report_type'],
        'violation_num'=> $r['violation_num'],
        'action_taken' => $r['action_taken'],
        'notes'        => $r['notes'] ?? '',
        'actioned_by'  => $r['actioned_by_name'] ?? 'Support',
        'created_at'   => date('M d, Y h:i A', strtotime($r['created_at'])),
    ];
}

echo json_encode($rows);