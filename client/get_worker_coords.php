<?php
// client/get_worker_coords.php
include '../db_connect.php';

if (isset($_GET['worker_id'])) {
    $id = $_GET['worker_id'];

    // Fetch only the coordinates
    $sql = "SELECT t_latitude, t_longitude FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    if ($row = $stmt->fetch()) {
        echo json_encode(['lat' => $row['t_latitude'], 'lng' => $row['t_longitude']]);
    } else {
        echo json_encode(['error' => 'Not found']);
    }
}
?>