<?php
// client/cancel_booking.php
include '../db_connect.php';
require_once '../includes/functions/wallet_manager.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security: must be logged in as client
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id  = (int) $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$booking_id) {
    header("Location: my_bookings.php");
    exit();
}

// Verify the booking belongs to this client AND is still Pending
// (prevents cancelling other people's bookings or already-accepted ones)
$check = $conn->prepare(
    "SELECT id, payment_method, payment_status, is_escrow, calculated_fee
     FROM bookings
     WHERE id = ? AND client_id = ? AND status = 'Pending'"
);
$check->execute([$booking_id, $client_id]);
$booking = $check->fetch();

if (!$booking) {
    // Either not their booking, or no longer Pending
    $_SESSION['error'] = "This booking cannot be cancelled. It may have already been accepted or does not exist.";
    header("Location: my_bookings.php");
    exit();
}

// Perform the cancellation
$update = $conn->prepare(
    "UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND client_id = ? AND status = 'Pending'"
);
$update->execute([$booking_id, $client_id]);

if ($update->rowCount() > 0) {
    // A PayMongo payment already held in escrow (client paid, worker hasn't
    // accepted/declined yet) must be reversed here too — otherwise the money
    // stays stuck in admin_wallet forever with no refund and no record of
    // owing the client anything.
    if ($booking['payment_method'] === 'PayMongo' &&
        $booking['payment_status'] === 'Paid' &&
        (int)$booking['is_escrow'] === 1) {
        $wallet = new WalletManager($conn);
        $wallet->refundEscrowPayment($booking_id, $client_id, $booking['calculated_fee']);
    }
    $_SESSION['success'] = "Booking #$booking_id has been cancelled successfully.";
} else {
    $_SESSION['error'] = "Something went wrong. Please try again.";
}

header("Location: my_bookings.php");
exit();
?>