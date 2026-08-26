<?php
// worker/worker_action.php
include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';

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

    $check_stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND worker_id = ?");
    $check_stmt->execute([$b_id, $worker_id]);
    $booking = $check_stmt->fetch();

    if ($booking) {
        $amount = $booking['calculated_fee'];
        // Worker is paid calculated_fee minus the convenience fee — that
        // portion exists purely to cover PayMongo's real gateway cut and
        // stays with the platform. Declines still refund the client the
        // full $amount they were charged, so that path is untouched.
        $worker_payout = $amount - ($booking['convenience_fee'] ?? 0);
        $wallet = new WalletManager($conn);

        $conn->beginTransaction();
        try {
            switch ($action) {
                // ==========================
                // CASE: ACCEPT
                // ==========================
                case 'accepted':
                    $conn->prepare("UPDATE bookings SET status = 'Accepted' WHERE id = ?")->execute([$b_id]);

                    // Escrow release goes through WalletManager — same guarded,
                    // idempotent path used everywhere else, including the
                    // ₱30 acceptance fee it deducts as part of releasing.
                    if ($booking['payment_method'] == 'PayMongo' && $booking['payment_status'] == 'Paid') {
                        $release_result = $wallet->releaseEscrowPayment($b_id, $worker_id, $worker_payout);
                        $msg = $release_result['success']
                            ? "Booking Accepted! Payment of ₱$worker_payout released to your wallet."
                            : "Booking Accepted!";
                    } else {
                        $msg = "Booking Accepted!";
                    }

                    sendNotification($conn, $booking['client_id'], "✅ Your booking has been ACCEPTED!", "../client/my_bookings.php");
                    break;

                // ==========================
                // CASE: DECLINE
                // ==========================
                case 'declined':
                    $conn->prepare("UPDATE bookings SET status = 'Declined' WHERE id = ?")->execute([$b_id]);

                    if ($booking['payment_method'] == 'PayMongo' && $booking['payment_status'] == 'Paid') {
                        $refund_result = $wallet->refundEscrowPayment($b_id, $booking['client_id'], $amount);
                        $msg = $refund_result['success']
                            ? "Booking Declined. Client has been refunded."
                            : "Booking Declined.";
                    } else {
                        $msg = "Booking Declined.";
                    }
                    sendNotification($conn, $booking['client_id'], "❌ Your booking was declined.", "../client/my_bookings.php");
                    break;

                // ==========================
                // CASE: COMPLETED
                // ==========================
                case 'completed':
                    $conn->prepare("UPDATE bookings SET status = 'Completed' WHERE id = ? AND status != 'Completed'")->execute([$b_id]);
                    $conn->prepare("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?")->execute([$worker_id]);
                    $msg = "Job marked as Completed!";
                    sendNotification($conn, $booking['client_id'], "🎉 Job Completed! Please leave a review.", "../client/my_bookings.php");
                    break;
            }

            $conn->commit();
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