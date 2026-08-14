<?php
// client/get_worker_coords.php
include '../db.php';

if (isset($_GET['worker_id'])) {
    $id = $_GET['worker_id'];
    
    // Fetch only the coordinates
    $sql = "SELECT t_latitude, t_longitude FROM users WHERE id = $id";
    $result = $conn->query($sql);
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['lat' => $row['t_latitude'], 'lng' => $row['t_longitude']]);
    } else {
        echo json_encode(['error' => 'Not found']);
    }
}
?>