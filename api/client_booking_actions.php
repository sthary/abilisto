<?php
// api/client_booking_actions.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../db_connect.php';
require_once '../includes/functions/wallet_manager.php';
require_once '../includes/fcm_sender.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Check authentication - this time for CLIENT
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
        throw new Exception('Unauthorized access');
    }

    $client_id = $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    $booking_id = intval($_POST['booking_id'] ?? 0);

    if (!$booking_id || !$action) {
        throw new Exception('Missing parameters');
    }

    // Initialize wallet manager
    $wallet = new WalletManager($conn);

    // Get booking details with worker info and current points
    $sql = "SELECT b.*, 
                   u.full_name as worker_name, 
                   u.fcm_token as worker_token,
                   wp.listo_points as worker_current_points
            FROM bookings b
            JOIN users u ON b.worker_id = u.id
            JOIN worker_profiles wp ON wp.user_id = b.worker_id
            WHERE b.id = ? AND b.client_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$booking_id, $client_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    switch ($action) {
        case 'confirm_completion':
            // Client confirms job is complete.
            // Valid from: 'Accepted' (GCash already paid) or 'Pending Confirmation' (Cash paid by worker)
            if (!in_array($booking['status'], ['Accepted', 'Pending Confirmation'])) {
                throw new Exception('This booking is not awaiting your confirmation.');
            }

            $conn->beginTransaction();

            try {
                // Update booking to Completed
                $update_sql = "UPDATE bookings SET
                               status = 'Completed',
                               client_confirmed_at = NOW(),
                               updated_at = NOW()
                               WHERE id = ? AND client_id = ?";
                $stmt = $conn->prepare($update_sql);

                if (!$stmt->execute([$booking_id, $client_id])) {
                    throw new Exception('Failed to update booking status');
                }

                // ── Cash payment: worker physically receives cash from client.
                //    The 4% commission was already deducted from worker wallet in
                //    notify_cash_payment.php when worker clicked "Confirm Cash".
                //    Nothing to credit to wallet here — cash stays with the worker. ──

                // ── GCash/PayMongo mobilization escrow: release now ────────────
                if ($booking['payment_method'] == 'PayMongo' &&
                    $booking['payment_status'] == 'Paid' && 
                    $booking['is_escrow'] == 1) {
                    
                    // Worker is paid calculated_fee minus the convenience fee — that
                    // portion covers PayMongo's real gateway cut and stays with the
                    // platform, matching worker/worker_action.php's accept path.
                    $release_result = $wallet->releaseEscrowPayment(
                        $booking_id,
                        $booking['worker_id'],
                        $booking['calculated_fee'] - ($booking['convenience_fee'] ?? 0)
                    );
                    
                    if (!$release_result['success']) {
                        throw new Exception('Failed to release escrow: ' . $release_result['message']);
                    }
                }
                
                // Increment worker's job count
                $update_profile = "UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?";
                $stmt = $conn->prepare($update_profile);

                if (!$stmt->execute([$booking['worker_id']])) {
                    throw new Exception('Failed to update worker job count');
                }
                
                // ⭐ AWARD LISTO POINTS HERE - after client confirmation
                // This is the key part - award points for completed job
                $points_result = $wallet->awardListoPoints($booking['worker_id'], $booking_id);
                
                // Log the result for debugging
                error_log("Listo Points awarded for booking #$booking_id: " . print_r($points_result, true));
                
                // Check if points were awarded successfully
                if (!$points_result['success']) {
                    error_log("Warning: Listo Points award failed: " . $points_result['message']);
                    // Continue anyway - don't fail the whole transaction for points
                }
                
                // Prepare notification message with Listo Points info
                $payment_released_amount = floatval($booking['calculated_fee']);

                if ($booking['final_payment_method'] === 'Cash') {
                    $worker_notification_message = "✅ Client confirmed job completion! Cash payment of ₱" .
                        number_format(floatval($booking['total_final_cost'] ?: ($booking['labor_materials_cost'] + $booking['calculated_fee'])), 2) .
                        " received. Booking #$booking_id complete.";
                } else {
                    $worker_notification_message = "✅ Client confirmed job completion! ₱" .
                        number_format($payment_released_amount, 2) .
                        " has been added to your wallet.";
                }
                
                // Add Listo Points info to notification if milestone reached
                if ($points_result['success'] && $points_result['milestone']) {
                    $worker_notification_message .= " 🎉 CONGRATULATIONS! You've earned a FREE booking credit (₱" . 
                                                   ADMIN_FEE_PER_BOOKING . ") from Listo Points! " .
                                                   "You now have " . $points_result['new_points'] . " total points.";
                } elseif ($points_result['success']) {
                    $worker_notification_message .= " ⭐ You earned " . LISTO_POINTS_PER_JOB . 
                                                   " Listo Points! Total: " . $points_result['new_points'] . " points.";
                }
                
                // Notify worker via FCM
                $fcm_notification_sent = false;
                if (!empty($booking['worker_token'])) {
                    try {
                        $fcm = new FCMv1();
                        $fcm_result = $fcm->send(
                            $booking['worker_token'],
                            $points_result['milestone'] ? "🎉 FREE CREDIT EARNED! 🎉" : "💰 Payment Released!",
                            $worker_notification_message,
                            [
                                'type' => 'payment_released',
                                'booking_id' => (string)$booking_id,
                                'listo_points_awarded' => $points_result['success'] ? LISTO_POINTS_PER_JOB : 0,
                                'listo_points_total' => $points_result['new_points'] ?? $booking['worker_current_points'],
                                'listo_milestone' => $points_result['milestone'] ?? false,
                                'free_credit_earned' => $points_result['milestone'] ?? false
                            ]
                        );
                        $fcm_notification_sent = $fcm_result['success'] ?? false;
                        error_log("FCM notification sent to worker: " . ($fcm_notification_sent ? 'Yes' : 'No'));
                    } catch (Exception $e) {
                        error_log("Failed to send FCM notification: " . $e->getMessage());
                    }
                }
                
                // Create in-app notification for worker with Listo Points info
                $in_app_sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                              VALUES (?, ?, '../worker/wallet.php', 0, NOW())";
                $in_app_stmt = $conn->prepare($in_app_sql);
                $in_app_stmt->execute([$booking['worker_id'], $worker_notification_message]);

                // Also create a notification for the client confirming completion
                $client_notif_msg = "✅ You've confirmed job completion. Thank you for using Abilisto!";
                $client_notif_sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                                    VALUES (?, ?, '../client/my_bookings.php', 0, NOW())";
                $client_notif_stmt = $conn->prepare($client_notif_sql);
                $client_notif_stmt->execute([$client_id, $client_notif_msg]);

                $conn->commit();
                
                // Build success response with Listo Points details
                $response = [
                    'success' => true,
                    'message' => 'Job confirmed! Payment released to worker.',
                    'listo_points' => [
                        'awarded' => $points_result['success'] ?? false,
                        'points_earned' => $points_result['success'] ? LISTO_POINTS_PER_JOB : 0,
                        'new_total' => $points_result['new_points'] ?? $booking['worker_current_points'],
                        'milestone_reached' => $points_result['milestone'] ?? false,
                        'free_credit_earned' => ($points_result['milestone'] ?? false) ? ADMIN_FEE_PER_BOOKING : 0,
                        'message' => $points_result['message'] ?? ''
                    ],
                    'notification_sent' => $fcm_notification_sent,
                    'payment_released' => true
                ];
                
            } catch (Exception $e) {
                $conn->rollBack();
                error_log("Transaction failed in confirm_completion: " . $e->getMessage());
                throw new Exception('Error confirming completion: ' . $e->getMessage());
            }
            break;
            
        case 'get_booking_details':
            // Optional: Get booking details including worker's current points
            $response = [
                'success' => true,
                'booking' => [
                    'id' => $booking['id'],
                    'worker_name' => $booking['worker_name'],
                    'worker_current_points' => $booking['worker_current_points'],
                    'payment_method' => $booking['payment_method'],
                    'calculated_fee' => $booking['calculated_fee'],
                    'status' => $booking['status']
                ]
            ];
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Client booking actions error: " . $e->getMessage());
}

echo json_encode($response);
?>