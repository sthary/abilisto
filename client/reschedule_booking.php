<?php
// client/reschedule_booking.php
include '../db_connect.php';
include '../includes/init_lang.php';
require_once '../includes/fcm_sender.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$client_id = intval($_SESSION['user_id']);

// ── Input validation ──────────────────────────────────────────────
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id'])          : 0;
$new_date   = isset($_POST['new_date'])   ? trim($_POST['new_date'])               : '';
$reason     = isset($_POST['reason'])     ? trim($_POST['reason'])                            : '';

if (!$booking_id || empty($new_date)) {
    echo json_encode(['success' => false, 'message' => 'Missing booking ID or new date.']);
    exit();
}

// Validate date format and minimum 1 hour from now
$new_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $new_date);
if (!$new_datetime) $new_datetime = DateTime::createFromFormat('Y-m-d H:i', $new_date); // fallback if seconds absent
if (!$new_datetime) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit();
}

$min_allowed = new DateTime('+1 hour');
if ($new_datetime <= $min_allowed) {
    echo json_encode(['success' => false, 'message' => 'New date must be at least 1 hour from now.']);
    exit();
}

// ── Verify booking belongs to this client and is still Pending ────
$check = $conn->prepare("SELECT id, worker_id, booking_date, status FROM bookings WHERE id = ? AND client_id = ?");
$check->execute([$booking_id, $client_id]);
$booking = $check->fetch();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit();
}

if ($booking['status'] !== 'Pending') {
    echo json_encode(['success' => false, 'message' => 'Only Pending bookings can be rescheduled.']);
    exit();
}

// Make sure new date is actually different
if ($new_date === $booking['booking_date']) {
    echo json_encode(['success' => false, 'message' => 'New date is the same as the current schedule.']);
    exit();
}

// ── Update booking_date ───────────────────────────────────────────
$update    = $conn->prepare("UPDATE bookings SET booking_date = ?, updated_at = NOW() WHERE id = ? AND client_id = ?");

if (!$update->execute([$new_date, $booking_id, $client_id])) {
    error_log("❌ Reschedule FAILED for booking #$booking_id");
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

error_log("✅ Booking #$booking_id rescheduled by client #$client_id to $new_date" . ($reason ? " (reason: $reason)" : ""));

// ── Notify worker ─────────────────────────────────────────────────
$worker_id     = intval($booking['worker_id']);
$client_name   = $_SESSION['full_name'];
$old_date_fmt  = date('M j, Y \a\t g:i A', strtotime($booking['booking_date']));
$new_date_fmt  = date('M j, Y \a\t g:i A', strtotime($new_date));
$reason_text   = $reason ? " Reason: " . ucfirst($reason) . "." : "";

// Push notification
try {
    $fcm    = new FCMv1();
    $result = $fcm->send(
        $worker_id,
        "📅 Booking Rescheduled",
        "{$client_name} moved the booking from {$old_date_fmt} to {$new_date_fmt}.{$reason_text}",
        [
            'type'       => 'reschedule',
            'booking_id' => (string)$booking_id,
            'url'        => '/abilisto/worker/view_booking.php?id=' . $booking_id
        ]
    );
    error_log("📨 PUSH (reschedule) - Worker: $worker_id, Booking: $booking_id, Result: " .
        ($result['success'] ? 'SUCCESS' : 'FAILED'));
} catch (Exception $e) {
    error_log("❌ Push notification error (reschedule): " . $e->getMessage());
}

// In-app notification to worker
$notif_msg  = "📅 Booking Rescheduled!\n\n{$client_name} changed the schedule.\nFrom: {$old_date_fmt}\nTo: {$new_date_fmt}{$reason_text}";
$notif_link = "../worker/view_booking.php?id=" . $booking_id;

if (function_exists('sendNotification')) {
    sendNotification($conn, $worker_id, $notif_msg, $notif_link);
} else {
    $ins = $conn->prepare("INSERT INTO notifications (user_id, message, link, created_at, is_read) VALUES (?,?,?,NOW(),0)");
    $ins->execute([$worker_id, $notif_msg, $notif_link]);
}

// In-app notification to client (confirmation)
$client_notif = "✅ Your booking has been rescheduled to {$new_date_fmt}.";
if (function_exists('sendNotification')) {
    sendNotification($conn, $client_id, $client_notif, "my_bookings.php");
}

echo json_encode(['success' => true]);
exit();