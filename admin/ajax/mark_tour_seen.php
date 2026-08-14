<?php
session_start();
include '../db_connect.php';

// Mark that the user has seen the tour
if (isset($_SESSION['user_id'])) {
    $_SESSION['has_seen_tour'] = true;
    
    // Store in database to persist across sessions
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET has_seen_tour = TRUE WHERE id = ?");
    $stmt->execute([$user_id]);
    
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
}
?>