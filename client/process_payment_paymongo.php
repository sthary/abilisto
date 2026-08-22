<?php
// client/process_payment_paymongo.php
// Creates the mobilization-fee booking (Cash or PayMongo) and, for
// PayMongo, redirects the client to a hosted PayMongo Payment Link.

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/booking_functions.php';
include '../includes/functions/paymongo_client.php';
include '../config/constants.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = $_SESSION['user_id'];
$bookingMgr = new BookingManager($conn);

// ============================================
// CASE 1: Coming from booking.php redirect (GET)
// ============================================
if (isset($_GET['booking_id'])) {
    $booking_id = intval($_GET['booking_id']);

    $booking_sql = "SELECT b.*, u.full_name, u.email
                    FROM bookings b
                    JOIN users u ON b.client_id = u.id
                    WHERE b.id = ? AND b.client_id = ?";
    $booking_stmt = $conn->prepare($booking_sql);
    $booking_stmt->execute([$booking_id, $client_id]);
    $booking = $booking_stmt->fetch();

    if (!$booking) {
        $_SESSION['error'] = "Booking not found.";
        header("Location: dashboard.php");
        exit();
    }
    $worker_id = $booking['worker_id'];
    $amount    = floatval($booking['calculated_fee']);

    if ($amount <= 0) {
        $_SESSION['error'] = "Invalid booking amount. Please contact support.";
        header("Location: booking.php?worker_id=$worker_id");
        exit();
    }

    // Already has a checkout link? Just send them back to it instead of
    // creating a duplicate PayMongo Link for the same booking.
    if (!empty($booking['transaction_id']) && !empty($booking['checkout_url'])) {
        header("Location: " . $booking['checkout_url']);
        exit();
    }

    $reference_number = 'INV-' . time() . '-' . $booking_id . '-' . rand(100, 999);

    $link = paymongoCreateLink(
        $amount,
        'Mobilization Fee for Booking #' . $booking_id,
        $reference_number,
        ['booking_id' => $booking_id, 'client_id' => $client_id, 'worker_id' => $worker_id, 'type' => 'mobilization']
    );

    if ($link['success']) {
        $update_stmt = $conn->prepare("UPDATE bookings SET transaction_id = ?, checkout_url = ? WHERE id = ?");
        if ($update_stmt->execute([$link['id'], $link['checkout_url'], $booking_id])) {
            header("Location: " . $link['checkout_url']);
            exit();
        }
        $_SESSION['error'] = "Payment processing error. Please contact support.";
        header("Location: booking.php?worker_id=$worker_id");
    } else {
        error_log("❌ PayMongo Link creation failed for booking #$booking_id: " . $link['message']);
        $_SESSION['error'] = "Payment gateway error. Please try again later.";
        header("Location: booking.php?worker_id=$worker_id");
    }
    exit();
}

// ============================================
// CASE 2: New booking via POST
// ============================================
if (isset($_POST['book_btn'])) {

    // Handle cash booking
    if ($_POST['payment_method'] === 'Cash') {
        $required = ['worker_id', 'service_date', 'urgency', 'problem_desc'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $_SESSION['error'] = 'Please fill all required fields';
                header("Location: booking.php?worker_id=" . $_POST['worker_id']);
                exit();
            }
        }

        $worker_id     = intval($_POST['worker_id']);
        $booking_date  = $_POST['service_date'];
        $urgency       = $_POST['urgency'];
        $problem_desc  = $_POST['problem_desc'];

        $distance  = floatval($_SESSION['last_distance'] ?? 0);
        $fee_calc  = $bookingMgr->calculateFee($distance, $urgency, 'Cash');
        $amount    = $fee_calc['total'];

        $booking_data = [
            'client_id'      => $client_id,
            'worker_id'      => $worker_id,
            'problem_desc'   => $problem_desc,
            'booking_date'   => $booking_date,
            'urgency'        => $urgency,
            'payment_method' => 'Cash',
            'calculated_fee' => $amount,
            'subtotal'       => $fee_calc['subtotal'],
            'discount'       => $fee_calc['discount'],
        ];

        $booking_id = $bookingMgr->createBooking($booking_data);

        if ($booking_id) {
            $worker_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $worker_stmt->execute([$worker_id]);
            $worker = $worker_stmt->fetch();

            $notif_msg = "📋 New Cash Booking Request!\n\n" .
                         $_SESSION['full_name'] . " needs your services on " .
                         date('M d, h:i A', strtotime($booking_date)) . "\n" .
                         "Fee: ₱$amount (to be paid upon service)";

            sendNotification($conn, $worker_id, $notif_msg, "../worker/dashboard.php");
            sendNotification($conn, $client_id,
                "✅ Cash booking request sent to " . $worker['full_name'],
                "../client/my_bookings.php"
            );

            $_SESSION['success'] = "Booking created successfully! Please prepare ₱$amount as payment upon worker arrival.";
            header("Location: my_bookings.php");
        } else {
            $_SESSION['error'] = "Failed to create booking. Please try again.";
            header("Location: booking.php?worker_id=$worker_id");
        }
        exit();
    }

    // Handle GCash/Maya/Card via PayMongo
    if ($_POST['payment_method'] === 'PayMongo') {
        $worker_id    = intval($_POST['worker_id']);
        $booking_date = $_POST['service_date'];
        $urgency      = $_POST['urgency'];
        $problem_desc = $_POST['problem_desc'];

        $distance = floatval($_SESSION['last_distance'] ?? 0);
        $fee_calc = $bookingMgr->calculateFee($distance, $urgency, 'PayMongo');
        $amount   = $fee_calc['total'];

        $booking_data = [
            'client_id'      => $client_id,
            'worker_id'      => $worker_id,
            'problem_desc'   => $problem_desc,
            'booking_date'   => $booking_date,
            'urgency'        => $urgency,
            'payment_method' => 'PayMongo',
            'calculated_fee' => $amount,
            'subtotal'       => $fee_calc['subtotal'],
            'discount'       => $fee_calc['discount'],
        ];

        $booking_id = $bookingMgr->createBooking($booking_data);

        if (!$booking_id) {
            $_SESSION['error'] = "Failed to create booking. Please try again.";
            header("Location: booking.php?worker_id=$worker_id");
            exit();
        }

        header("Location: process_payment_paymongo.php?booking_id=$booking_id");
        exit();
    }
}

header("Location: dashboard.php");
exit();
?>