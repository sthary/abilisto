<?php
// api/process_cash_payment.php
require_once '../db.php';
require_once '../includes/functions/wallet_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = intval($data['booking_id'] ?? 0);
$worker_id = $_SESSION['user_id'];

// Get booking details
$sql = "SELECT b.*, u.fcm_token 
        FROM bookings b
        JOIN users u ON b.client_id = u.id
        WHERE b.id = ? AND b.worker_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $booking_id, $worker_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit();
}

$conn->begin_transaction();

try {
    // Update booking
    $update = "UPDATE bookings SET 
               final_payment_status = 'paid',
               status = 'Completed',
               updated_at = NOW()
               WHERE id = ?";
    $stmt = $conn->prepare($update);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    
    // Calculate admin fee (2%)
    $total = floatval($booking['total_final_cost']);
    $admin_fee = round($total * 0.02, 2);
    $worker_gets = $total - $admin_fee;
    
    // Credit worker's wallet
    $conn->query("UPDATE worker_profiles 
                  SET wallet_balance = wallet_balance + $worker_gets,
                      jobs_completed = jobs_completed + 1
                  WHERE user_id = $worker_id");
    
    // Add admin fee
    $conn->query("UPDATE admin_wallet 
                  SET balance = balance + $admin_fee,
                      total_earned = total_earned + $admin_fee
                  WHERE id = 1");
    
    // Notify client
    sendNotification($conn, $booking['client_id'],
        "✅ Cash payment received! Job completed. Thank you!",
        "../client/my_bookings.php"
    );
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Payment recorded']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>