<?php
require_once '../db_connect.php';
session_start();

if ($_SESSION['role'] !== 'business') exit;

$app_id = $_POST['app_id'];
$status = $_POST['status'];

$pdo->beginTransaction();

try {
    // Update Application Status
    $stmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ?");
    $stmt->execute([$status, $app_id]);

    // If Accepted (Contacted), reduce slots (increase slots_filled)
    if ($status === 'Contacted') {
        // Get Post ID
        $get_post = $pdo->prepare("SELECT post_id FROM applications WHERE id = ?");
        $get_post->execute([$app_id]);
        $post_id = $get_post->fetchColumn();

        // Increase filled count
        $update_post = $pdo->prepare("UPDATE business_posts SET slots_filled = slots_filled + 1 WHERE id = ?");
        $update_post->execute([$post_id]);

        // Check if full to close post
        $check_full = $pdo->prepare("SELECT max_slots, slots_filled FROM business_posts WHERE id = ?");
        $check_full->execute([$post_id]);
        $data = $check_full->fetch();

        if ($data['slots_filled'] >= $data['max_slots']) {
            $pdo->prepare("UPDATE business_posts SET status = 'Full' WHERE id = ?")->execute([$post_id]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}