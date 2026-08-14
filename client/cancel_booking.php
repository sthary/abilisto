<?php
// client/cancel_booking.php
include '../db.php';

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
    "SELECT id FROM bookings 
     WHERE id = ? AND client_id = ? AND status = 'Pending'"
);
$check->bind_param("ii", $booking_id, $client_id);
$check->execute();

if ($check->get_result()->num_rows === 0) {
    // Either not their booking, or no longer Pending
    $_SESSION['error'] = "This booking cannot be cancelled. It may have already been accepted or does not exist.";
    header("Location: my_bookings.php");
    exit();
}

// Perform the cancellation
$update = $conn->prepare(
    "UPDATE bookings SET status = 'Cancelled' WHERE id = ? AND client_id = ? AND status = 'Pending'"
);
$update->bind_param("ii", $booking_id, $client_id);

if ($update->execute() && $update->affected_rows > 0) {
    $_SESSION['success'] = "Booking #$booking_id has been cancelled successfully.";
} else {
    $_SESSION['error'] = "Something went wrong. Please try again.";
}

$update->close();
$conn->close();

header("Location: my_bookings.php");
exit();
?>