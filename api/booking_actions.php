<?php
// api/booking_actions.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display but keep logging
session_start();
require_once '../db_connect.php';
require_once '../includes/functions/wallet_manager.php';
require_once '../includes/functions/booking_functions.php';
require_once '../config/constants.php';
require_once '../includes/fcm_sender.php';

header('Content-Type: application/json');

// Initialize response array
$response = ['success' => false, 'message' => ''];

try {
    // Check authentication
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
        throw new Exception('Unauthorized access');
    }

    $worker_id = $_SESSION['user_id'];
    $worker_name = $_SESSION['full_name'] ?? 'Worker';
    $action = $_POST['action'] ?? '';
    $booking_id = intval($_POST['booking_id'] ?? 0);

    if (!$booking_id || !$action) {
        throw new Exception('Missing parameters');
    }

    // Initialize wallet manager
    $wallet = new WalletManager($conn);
    
    // Get booking details with client info
    $sql = "SELECT b.*, 
                   u.full_name as client_name, 
                   u.email as client_email,
                   u.fcm_token as client_token,
                   wp.free_credits,
                   wp.wallet_balance
            FROM bookings b
            JOIN users u ON b.client_id = u.id
            JOIN worker_profiles wp ON wp.user_id = b.worker_id
            WHERE b.id = ? AND b.worker_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$booking_id, $worker_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new Exception('Booking not found or not assigned to you');
    }

    // Handle different actions
    switch ($action) {
        case 'check_eligibility':
    // ── GUARD: Admin may have disabled this worker from accepting ──
    $acceptCheck = $conn->prepare(
        "SELECT can_accept_bookings FROM worker_profiles WHERE user_id = ?"
    );
    $acceptCheck->execute([$worker_id]);
    $acceptRow = $acceptCheck->fetch();
    if ($acceptRow && (int)$acceptRow['can_accept_bookings'] === 0) {
        $response = [
            'success' => true,  // ← Change to true
            'can_accept' => false,  // ← Explicitly false
            'is_restricted' => true,  // ← Add flag to identify restriction
            'message' => 'Your account has been restricted from accepting new bookings by an administrator. Please contact support for assistance.',
        ];
        echo json_encode($response);
        exit();
    }
    // ── END GUARD ──────────────────────────────────────────────────
    
    // Just check if worker can accept
    $can_accept = $wallet->canAcceptBooking($worker_id, $booking['payment_method']);
    $worker_wallet = $wallet->getWorkerWallet($worker_id);
    
    $response = [
        'success' => true,
        'can_accept' => $can_accept,
        'wallet_balance' => $worker_wallet['wallet_balance'],
        'free_credits' => $worker_wallet['free_credits'],
        'required_fee' => ADMIN_FEE_PER_BOOKING,
        'payment_method' => $booking['payment_method'],
        'message' => $can_accept ? 
            'You can accept this booking' : 
            'Insufficient funds. Need ₱' . ADMIN_FEE_PER_BOOKING
    ];
    break;
            
        case 'accept':
            // ── GUARD: Admin may have disabled this worker from accepting ──
            $acceptCheck = $conn->prepare(
                "SELECT can_accept_bookings FROM worker_profiles WHERE user_id = ?"
            );
            $acceptCheck->execute([$worker_id]);
            $acceptRow = $acceptCheck->fetch();
            if ($acceptRow && (int)$acceptRow['can_accept_bookings'] === 0) {
                throw new Exception('Your account has been restricted from accepting new bookings by an administrator. Please contact support for assistance.');
            }
            // ── END GUARD ──────────────────────────────────────────────────
            
            // Check if worker can accept
            if (!$wallet->canAcceptBooking($worker_id, $booking['payment_method'])) {
                $worker_wallet = $wallet->getWorkerWallet($worker_id);
                throw new Exception('Insufficient funds. You need ₱' . ADMIN_FEE_PER_BOOKING . 
                    ' (Balance: ₱' . $worker_wallet['wallet_balance'] . 
                    ', Free credits: ₱' . $worker_wallet['free_credits'] . ')');
            }
            
            // Start transaction
            $conn->beginTransaction();

            try {
                // Update booking status
                $update_sql = "UPDATE bookings SET status = 'Accepted', updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->execute([$booking_id]);
                
                // For cash payments, deduct fee immediately
                // For GCash/escrow payments, just mark as accepted - payment stays in escrow
                if ($booking['payment_method'] != 'Xendit') {
                    // Cash payment - deduct fee
                    $fee_result = $wallet->deductAdminFee($booking['id'], $worker_id);
                    if (!$fee_result['success']) {
                        throw new Exception($fee_result['message']);
                    }
                }
                
                // 🔔 SEND NOTIFICATION TO CLIENT
                // 🔔 SEND NOTIFICATION TO CLIENT VIA ONESIGNAL
                $notification_sent = false;
                if (!empty($booking['client_id'])) { // Target client_id directly!
                    try {
                        $fcm = new FCMv1();
                        $status_text = $booking['payment_method'] == 'Xendit' ? 
                            "Payment held in escrow until job completion." : 
                            "";
                        
                        $notif_result = $fcm->send(
                            $booking['client_id'], // Pass the raw ID
                            "✅ Booking Accepted!",
                            $worker_name . " accepted your booking. " . $status_text,
                            [
                                'type' => 'booking_accepted',
                                'booking_id' => (string)$booking['id'],
                                'worker_name' => (string)$worker_name,
                                'url' => '/abilisto/client/my_bookings.php' // Makes the push clickable!
                            ]
                        );
                        
                        $notification_sent = $notif_result['success'] ?? false;
                        
                    } catch (Exception $e) {
                        error_log("Exception sending notification: " . $e->getMessage());
                    }
                }
                
                // Also save in-app notification
                $notif_msg = "✅ " . $worker_name . " accepted your booking!";
                if ($booking['payment_method'] == 'Xendit') {
                    $notif_msg .= " Payment held in escrow. Confirm when job is done.";
                }
                
                $in_app_sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                              VALUES (?, ?, '../client/my_bookings.php', 0, NOW())";
                $in_app_stmt = $conn->prepare($in_app_sql);
                $in_app_stmt->execute([$booking['client_id'], $notif_msg]);

                $conn->commit();

                // Get updated wallet info
                $updated_wallet = $wallet->getWorkerWallet($worker_id);
                
                $response = [
                    'success' => true,
                    'message' => 'Booking accepted successfully!',
                    'new_balance' => $updated_wallet['wallet_balance'],
                    'free_credits' => $updated_wallet['free_credits'],
                    'notification_sent' => $notification_sent,
                    'redirect' => '../worker/dashboard.php'
                ];
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw new Exception('Error accepting booking: ' . $e->getMessage());
            }
            break;

        case 'reject':
            // Handle rejection logic
            $conn->beginTransaction();

            try {
                $update_sql = "UPDATE bookings SET status = 'Declined' WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->execute([$booking_id]);
                
                // Refund if escrow
                if ($booking['payment_method'] == 'Xendit' && 
                    $booking['payment_status'] == 'Paid' && 
                    $booking['is_escrow'] == 1) {
                    
                    $refund_result = $wallet->refundEscrowPayment(
                        $booking['id'], 
                        $booking['client_id'], 
                        $booking['calculated_fee']
                    );
                    
                    if (!$refund_result['success']) {
                        throw new Exception($refund_result['message']);
                    }
                }
                
                // Notify client
                // Notify client via OneSignal
                if (!empty($booking['client_id'])) {
                    try {
                        $fcm = new FCMv1();
                        $notif_result = $fcm->send(
                            $booking['client_id'],
                            "❌ Booking Declined",
                            $worker_name . " declined your booking",
                            [
                                'type' => 'booking_rejected',
                                'booking_id' => (string)$booking['id'],
                                'worker_name' => (string)$worker_name,
                                'url' => '/abilisto/client/my_bookings.php'
                            ]
                        );
                        error_log("Reject notification result: " . print_r($notif_result, true));
                    } catch (Exception $e) {
                        error_log("Failed to send rejection notification: " . $e->getMessage());
                    }
                }
                
                $conn->commit();
                
                $response = [
                    'success' => true,
                    'message' => 'Booking declined successfully'
                ];
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw new Exception('Error declining booking: ' . $e->getMessage());
            }
            break;

        case 'complete':
            // Handle completion
            $conn->beginTransaction();

            try {
                $update_sql = "UPDATE bookings SET status = 'Completed' WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->execute([$booking_id]);

                $update_profile = "UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?";
                $stmt = $conn->prepare($update_profile);
                $stmt->execute([$worker_id]);
                
                // Notify client
                // Notify client via OneSignal
        if (!empty($booking_data['client_id'])) {
            try {
                $fcm = new FCMv1();
                $notif_result = $fcm->send(
                    $booking_data['client_id'],
                    "✅ Job Completed - Please Confirm",
                    "Your worker has marked the job as done.",
                    [
                        'type' => 'job_done_pending_confirmation',
                        'booking_id' => (string)$booking_id,
                        'url' => '/abilisto/client/my_bookings.php'
                    ]
                );
                error_log("Client notification sent: " . print_r($notif_result, true));
            } catch (Exception $e) {
                error_log("Failed to send client notification: " . $e->getMessage());
            }
        }
                
                $conn->commit();
                
                // ⭐ Award Listo Points for completed job (outside the main transaction so it doesn't block completion)
                $points_result = $wallet->awardListoPoints($worker_id, $booking_id);
                error_log("Listo Points result: " . print_r($points_result, true));
                
                $response = [
                    'success'          => true,
                    'message'          => 'Job marked as completed!',
                    'listo_points'     => $points_result['new_points'] ?? 0,
                    'listo_message'    => $points_result['message'] ?? '',
                    'listo_milestone'  => $points_result['milestone'] ?? false,
                ];
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw new Exception('Error completing job: ' . $e->getMessage());
            }
            break;

        // Add after the 'complete' case (around line 300)

    case 'mark_job_done':
    // Worker marks job as done - notifies client to confirm
    if ($_SESSION['role'] !== 'worker') {
        throw new Exception('Only workers can mark jobs as done');
    }

    $conn->beginTransaction();

    try {
        // Get booking details
        $booking_sql = "SELECT b.*, u.fcm_token as client_token
                       FROM bookings b
                       JOIN users u ON b.client_id = u.id
                       WHERE b.id = ? AND b.worker_id = ?";
        $booking_stmt = $conn->prepare($booking_sql);
        $booking_stmt->execute([$booking_id, $worker_id]);
        $booking_data = $booking_stmt->fetch();

        if (!$booking_data) {
            throw new Exception('Booking not found');
        }

        // Update booking status to pending confirmation
        $update_sql = "UPDATE bookings SET
                       status = 'Accepted',
                       updated_at = NOW()
                       WHERE id = ? AND worker_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->execute([$booking_id, $worker_id]);
        
        // Notify client
        if (!empty($booking_data['client_token'])) {
            try {
                $fcm = new FCMv1();
                $notif_result = $fcm->send(
                    $booking_data['client_token'],
                    "✅ Job Completed - Please Confirm",
                    "Your worker has marked the job as done.",
                    [
                        'type' => 'job_done_pending_confirmation',
                        'booking_id' => (string)$booking_id
                    ]
                );
                error_log("Client notification sent: " . print_r($notif_result, true));
            } catch (Exception $e) {
                error_log("Failed to send client notification: " . $e->getMessage());
            }
        }
        
        // Create in-app notification for client
        $notif_msg = "✅ Your worker has marked the job as done. Please confirm completion.";
        $in_app_sql = "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                      VALUES (?, ?, '../client/my_bookings.php', 0, NOW())";
        $in_app_stmt = $conn->prepare($in_app_sql);
        $in_app_stmt->execute([$booking_data['client_id'], $notif_msg]);

        $conn->commit();

        $response = [
            'success' => true,
            'message' => 'Job marked as done! Client has been notified to confirm.'
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        throw new Exception('Error marking job as done: ' . $e->getMessage());
    }
    break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Booking actions error: " . $e->getMessage());
}

echo json_encode($response);
?>