<?php
// worker/worker_action.php
include '../db_connect.php';
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
    $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND worker_id = ?");
    $check_stmt->execute([$b_id, $worker_id]);
    $booking = $check_stmt->fetch();

    if ($booking) {
        $amount = $booking['calculated_fee'];

        $conn->beginTransaction();
        try {
            switch ($action) {
                // ==========================
                // CASE: ACCEPT (Pasulod ang kwarta sa Worker)
                // ==========================
                case 'accepted':
                    $conn->prepare("UPDATE bookings SET status = 'Accepted' WHERE id = ?")->execute([$b_id]);

                    // ESCROW: Kung Xendit/Paid, ibalhin ang kwarta Admin -> Worker
                    if ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid') {
                        // 1. Deduct from Admin (Release Hold)
                        $conn->prepare("UPDATE admin_wallet SET balance = balance - ? WHERE id = 1")->execute([$amount]);

                        // 2. Add to Worker
                        $conn->prepare("UPDATE worker_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ?")->execute([$amount, $worker_id]);

                        // 3. Record Transactions
                        $conn->prepare("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description) VALUES (?, 'worker', 'credit', ?, ?, 'booking', 'Payment released from escrow')")->execute([$worker_id, $amount, $b_id]);

                        // 4. Mark as credited
                        $conn->prepare("UPDATE bookings SET wallet_credited = TRUE WHERE id = ?")->execute([$b_id]);

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
                    $conn->prepare("UPDATE bookings SET status = 'Declined' WHERE id = ?")->execute([$b_id]);

                    // ESCROW: Kung Paid, i-refund (sa record lang sa)
                    if ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid') {
                        // 1. Deduct from Admin (Release Hold)
                        $conn->prepare("UPDATE admin_wallet SET balance = balance - ? WHERE id = 1")->execute([$amount]);

                        // 2. Update Booking to Refunded
                        $conn->prepare("UPDATE bookings SET payment_status = 'Refunded' WHERE id = ?")->execute([$b_id]);

                        // 3. Log Refund
                        $conn->prepare("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description) VALUES (1, 'admin', 'refund', ?, ?, 'booking', 'Refund for declined booking')")->execute([$amount, $b_id]);

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
                    $conn->prepare("UPDATE bookings SET status = 'Completed' WHERE id = ?")->execute([$b_id]);
                    $conn->prepare("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?")->execute([$worker_id]);
                    $msg = "Job marked as Completed!";
                    sendNotification($conn, $booking['client_id'], "🎉 Job Completed! Please leave a review.", "../client/my_bookings.php");
                    break;
            }

            $conn->commit();
            // Redirect balik sa dashboard with success message
            header("Location: dashboard.php?msg=" . urlencode($msg));
            exit();

        } catch (Exception $e) {
            $conn->rollBack();
            header("Location: dashboard.php?error=Transaction Failed");
            exit();
        }
    }
}
header("Location: dashboard.php");
exit();
?>