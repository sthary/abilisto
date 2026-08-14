<?php
// worker/check_my_sessions.php
require_once '../db_connect.php';

$worker_id = $_SESSION['user_id'] ?? 0;

echo "<h2>Available Quick Match Sessions for Worker ID: $worker_id</h2>";

// Get all pending broadcasts for this worker
$query = "
    SELECT 
        qb.*,
        qs.id as session_id,
        qs.service_category,
        qs.problem_desc,
        qs.urgency_level,
        qs.expires_at,
        u.full_name as client_name,
        EXTRACT(EPOCH FROM (qs.expires_at - NOW())) / 60 as minutes_left
    FROM quick_match_broadcasts qb
    JOIN quick_match_sessions qs ON qb.session_id = qs.id
    JOIN users u ON qs.client_id = u.id
    WHERE qb.worker_id = ?
    AND qb.status = 'pending'
    AND qs.status = 'matching'
    AND qs.expires_at > NOW()
    ORDER BY qs.urgency_level DESC, qs.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->execute([$worker_id]);
$rows = $stmt->fetchAll();

if (count($rows) === 0) {
    echo "<p style='color: #666;'>No active quick match sessions found.</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr>
            <th>Session ID</th>
            <th>Client</th>
            <th>Service</th>
            <th>Problem</th>
            <th>Urgency</th>
            <th>Time Left</th>
            <th>Action</th>
          </tr>";

    foreach ($rows as $row) {
        $urgency_color = $row['urgency_level'] == 'Emergency' ? '#dc2626' : 
                        ($row['urgency_level'] == 'Urgent' ? '#d97706' : '#2563eb');
        
        echo "<tr>";
        echo "<td>{$row['session_id']}</td>";
        echo "<td>{$row['client_name']}</td>";
        echo "<td>{$row['service_category']}</td>";
        echo "<td>" . htmlspecialchars(substr($row['problem_desc'], 0, 50)) . "...</td>";
        echo "<td style='color: $urgency_color; font-weight: bold;'>{$row['urgency_level']}</td>";
        echo "<td>{$row['minutes_left']} minutes</td>";
        echo "<td><a href='quick_match_view.php?session_id={$row['session_id']}' target='_blank'>View Request</a></td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Also show expired/completed sessions
echo "<h3>Recent History</h3>";
$historyQuery = "
    SELECT 
        qb.*,
        qs.id as session_id,
        qs.service_category,
        qs.urgency_level,
        u.full_name as client_name,
        qs.status as session_status
    FROM quick_match_broadcasts qb
    JOIN quick_match_sessions qs ON qb.session_id = qs.id
    JOIN users u ON qs.client_id = u.id
    WHERE qb.worker_id = ?
    AND qb.status != 'pending'
    ORDER BY qb.responded_at DESC
    LIMIT 10
";

$stmt2 = $conn->prepare($historyQuery);
$stmt2->execute([$worker_id]);
$historyRows = $stmt2->fetchAll();

if (count($historyRows) > 0) {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; margin-top: 10px;'>";
    echo "<tr>
            <th>Session ID</th>
            <th>Client</th>
            <th>Service</th>
            <th>Your Response</th>
            <th>Session Status</th>
            <th>Responded At</th>
          </tr>";

    foreach ($historyRows as $row) {
        $status_color = $row['status'] == 'accepted' ? '#10b981' : 
                       ($row['status'] == 'declined' ? '#ef4444' : '#f59e0b');
        
        echo "<tr>";
        echo "<td>{$row['session_id']}</td>";
        echo "<td>{$row['client_name']}</td>";
        echo "<td>{$row['service_category']}</td>";
        echo "<td style='color: $status_color;'><strong>{$row['status']}</strong></td>";
        echo "<td>{$row['session_status']}</td>";
        echo "<td>{$row['responded_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>