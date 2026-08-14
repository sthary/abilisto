<?php
// api/chat_push.php
require_once '../db_connect.php';
require_once '../includes/fcm_sender.php';

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['booking_id']) && isset($data['sender_id'])) {
    $booking_id = (int)$data['booking_id'];
    $sender_id = (int)$data['sender_id'];
    $sender_name = $data['sender_name'] ?? 'New Message';
    $message = $data['message'] ?? 'Sent an attachment 📎';

    // Find out who the OTHER person is in this booking
    $sql = "SELECT client_id, worker_id FROM bookings WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if ($booking) {
        // If the sender is the client, target the worker. Otherwise, target the client.
        $target_user_id = ($sender_id == $booking['client_id']) ? $booking['worker_id'] : $booking['client_id'];

        try {
            $fcm = new FCMv1();
            $fcm->send(
                $target_user_id,
                "💬 " . $sender_name,
                substr($message, 0, 50) . (strlen($message) > 50 ? '...' : ''),
                ['url' => '/abilisto/chat.php?booking_id=' . $booking_id]
            );
        } catch (Exception $e) {
            error_log("Chat push error: " . $e->getMessage());
        }
    }
}
?>