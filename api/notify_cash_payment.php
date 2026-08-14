<?php
// api/notify_cash_payment.php
session_start();
require_once '../db.php';
require_once '../includes/fcm_sender.php';
require_once '../includes/functions/wallet_functions.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Check authentication
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
        throw new Exception('Unauthorized access');
    }

    $worker_id   = $_SESSION['user_id'];
    $worker_name = $_SESSION['full_name'] ?? 'Worker';

    $data       = json_decode(file_get_contents('php://input'), true);
    $booking_id = intval($data['booking_id'] ?? 0);

    if (!$booking_id) {
        throw new Exception('Missing booking ID');
    }

    // Get booking details including cost fields for commission calculation
    $sql = "SELECT b.*, 
                   u.fcm_token  AS client_token, 
                   u.full_name  AS client_name
            FROM bookings b
            JOIN users u ON b.client_id = u.id
            WHERE b.id = ? AND b.worker_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $booking_id, $worker_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // ---------------------------------------------------------------
    // COMMISSION DEDUCTION (4% of total_final_cost) — happens immediately
    // when worker confirms cash received, before client confirmation.
    // ---------------------------------------------------------------
    $total_final_cost = floatval($booking['total_final_cost']);

    if ($total_final_cost <= 0) {
        // Fallback: use labor_materials_cost + calculated_fee if total_final_cost not set
        $total_final_cost = floatval($booking['labor_materials_cost']) + floatval($booking['calculated_fee']);
    }

    if ($total_final_cost > 0) {
        $wallet = new WalletManager($conn);
        $commission_result = $wallet->processFinalPaymentCommission(
            $booking_id,
            $worker_id,
            $total_final_cost,
            'Cash'
        );

        if (!$commission_result['success']) {
            // Log but don't block notification — commission failure should not stop the flow
            error_log("⚠️ Commission deduction failed for booking #$booking_id: " . $commission_result['message']);
        } else {
            error_log("✅ Cash commission deducted: ₱{$commission_result['commission']} for booking #$booking_id");
        }
    }

    // Mark booking as cash payment confirmed — set to Pending Confirmation
    // so the client still sees a confirmation button before we mark Completed
    $conn->query("UPDATE bookings 
                  SET final_payment_status = 'paid',
                      final_payment_method = 'Cash',
                      status               = 'Pending Confirmation',
                      updated_at           = NOW()
                  WHERE id = $booking_id");

    // ---------------------------------------------------------------
    // NOTIFY CLIENT
    // ---------------------------------------------------------------
    $notification_sent = false;

    // Push notification via FCM
    if (!empty($booking['client_token'])) {
        try {
            $fcm = new FCMv1();
            $notif_result = $fcm->send(
                $booking['client_token'],
                "💰 Cash Payment Received",
                $worker_name . " has received your cash payment. Please confirm job completion.",
                [
                    'type'        => 'cash_payment_received',
                    'booking_id'  => (string)$booking_id,
                    'worker_name' => (string)$worker_name,
                ]
            );
            $notification_sent = $notif_result['success'] ?? false;
        } catch (Exception $e) {
            error_log("FCM error: " . $e->getMessage());
        }
    }

    // In-app notification
    $notif_msg  = "💰 {$worker_name} has received your cash payment. Please confirm job completion to release funds.";
    $in_app_sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at) 
                   VALUES (?, ?, '../client/my_bookings.php', 0, NOW())";
    $in_app_stmt = $conn->prepare($in_app_sql);
    $in_app_stmt->bind_param("is", $booking['client_id'], $notif_msg);
    $in_app_stmt->execute();

    $response = [
        'success' => true,
        'message' => 'Client notified and commission processed',
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Notify cash payment error: " . $e->getMessage());
}

echo json_encode($response);
?>