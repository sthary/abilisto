<?php
// worker/worker_action.php
include '../db.php';
include '../includes/init_lang.php';

session_start();

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

if (isset($_GET['action']) && isset($_GET['booking_id'])) {
    $b_id = intval($_GET['booking_id']);
    $action = strtolower($_GET['action']);
    $worker_id = $_SESSION['user_id'];

    // Kuhaon ang booking details para sa validation
    $check = $conn->query("SELECT calculated_fee, payment_method, payment_status, status FROM bookings WHERE id = $b_id AND worker_id = $worker_id");
    
    if ($check->num_rows > 0) {
        $booking = $check->fetch_assoc();
        $amount = $booking['calculated_fee'];

        $conn->begin_transaction();
        try {
            switch ($action) {
                // ==========================
                // CASE: ACCEPT (Pasulod ang kwarta sa Worker)
                // ==========================
                case 'accepted':
                    $conn->query("UPDATE bookings SET status = 'Accepted' WHERE id = $b_id");
                    
                    // ESCROW: Kung Xendit/Paid, ibalhin ang kwarta Admin -> Worker
                    if ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid') {
                        // 1. Deduct from Admin (Release Hold)
                        $conn->query("UPDATE admin_wallet SET balance = balance - $amount WHERE id = 1");
                        
                        // 2. Add to Worker
                        $conn->query("UPDATE worker_profiles SET wallet_balance = wallet_balance + $amount WHERE user_id = $worker_id");
                        
                        // 3. Record Transactions
                        $conn->query("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description) VALUES ($worker_id, 'worker', 'credit', $amount, $b_id, 'booking', 'Payment released from escrow')");
                        
                        // 4. Mark as credited
                        $conn->query("UPDATE bookings SET wallet_credited = 1 WHERE id = $b_id");
                        
                        $msg = "Booking Accepted! Payment of ₱$amount released to your wallet.";
                    } else {
                        $msg = "Booking Accepted!";
                    }
                    
                    // Notify Client
                    sendNotification($conn, $booking['client_id'], "✅ Your booking has been ACCEPTED!", "../client/my_bookings.php");
                    break;

                // ==========================
                // CASE: DECLINE (I-refund ang Client)
                // ==========================
                case 'declined':
                    $conn->query("UPDATE bookings SET status = 'Declined' WHERE id = $b_id");

                    // ESCROW: Kung Paid, i-refund (sa record lang sa)
                    if ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid') {
                        // 1. Deduct from Admin (Release Hold)
                        $conn->query("UPDATE admin_wallet SET balance = balance - $amount WHERE id = 1");
                        
                        // 2. Update Booking to Refunded
                        $conn->query("UPDATE bookings SET payment_status = 'Refunded' WHERE id = $b_id");
                        
                        // 3. Log Refund
                        $conn->query("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description) VALUES (1, 'admin', 'refund', $amount, $b_id, 'booking', 'Refund for declined booking')");
                        
                        $msg = "Booking Declined. Client has been refunded.";
                    } else {
                        $msg = "Booking Declined.";
                    }
                    sendNotification($conn, $booking['client_id'], "❌ Your booking was declined.", "../client/my_bookings.php");
                    break;

                // ==========================
                // CASE: COMPLETED
                // ==========================
                case 'completed':
                    $conn->query("UPDATE bookings SET status = 'Completed' WHERE id = $b_id");
                    $conn->query("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = $worker_id");
                    $msg = "Job marked as Completed!";
                    sendNotification($conn, $booking['client_id'], "🎉 Job Completed! Please leave a review.", "../client/my_bookings.php");
                    break;
            }

            $conn->commit();
            // Redirect balik sa dashboard with success message
            header("Location: dashboard.php?msg=" . urlencode($msg));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            header("Location: dashboard.php?error=Transaction Failed");
            exit();
        }
    }
}
header("Location: dashboard.php");
exit();
?>