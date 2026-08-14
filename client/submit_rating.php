<?php
// client/submit_rating.php
session_start();
include '../db_connect.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    $booking_id = (int)$_POST['booking_id'];
    $worker_id = (int)$_POST['worker_id'];
    $client_id = $_SESSION['user_id'];
    $rating = (int)$_POST['rating'];
    $comment = trim($_POST['comment']);
    
    // Validate rating
    if ($rating < 1 || $rating > 5) {
        $_SESSION['error'] = "Invalid rating value.";
        header("Location: my_bookings.php");
        exit();
    }
    
    // Check if booking belongs to this client and is completed
    $check_sql = "SELECT id FROM bookings WHERE id = ? AND client_id = ? AND status = 'Completed'";
    $stmt = $conn->prepare($check_sql);
    $stmt->execute([$booking_id, $client_id]);

    if (!$stmt->fetch()) {
        $_SESSION['error'] = "Invalid booking or booking not completed.";
        header("Location: my_bookings.php");
        exit();
    }

    // Check if review already exists
    $check_review = "SELECT id FROM reviews WHERE booking_id = ?";
    $stmt = $conn->prepare($check_review);
    $stmt->execute([$booking_id]);

    if ($stmt->fetch()) {
        $_SESSION['error'] = "You have already reviewed this booking.";
        header("Location: my_bookings.php");
        exit();
    }

    // Insert the Review using prepared statement
    $sql = "INSERT INTO reviews (booking_id, worker_id, client_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $inserted = $stmt->execute([$booking_id, $worker_id, $client_id, $rating, $comment]);

    if ($inserted) {
        // Recalculate Worker's Average
        $avg_query = "SELECT AVG(rating) as new_avg, COUNT(*) as count FROM reviews WHERE worker_id = ?";
        $stmt = $conn->prepare($avg_query);
        $stmt->execute([$worker_id]);
        $data = $stmt->fetch();

        $new_avg = number_format($data['new_avg'], 2);
        $new_count = $data['count'];

        // Update Worker Profile
        $update_sql = "UPDATE worker_profiles
                       SET average_rating = ?, rating_count = ?
                       WHERE user_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->execute([$new_avg, $new_count, $worker_id]);

        $_SESSION['success'] = "Thank you! Your rating has been submitted.";
    } else {
        $_SESSION['error'] = "Error submitting review.";
    }

    header("Location: my_bookings.php");
    exit();
} else {
    header("Location: my_bookings.php");
    exit();
}
?>