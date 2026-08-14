<?php
// worker/quick_match_respond.php
require_once '../db_connect.php';
require_once '../ajax_auth_check.php';

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    sendUnauthorized('Please login as worker');
}

$worker_id = intval($_SESSION['user_id']);
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['session_id']) || !isset($input['broadcast_id']) || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit();
}

$session_id = intval($input['session_id']);
$broadcast_id = intval($input['broadcast_id']);
$action = $input['action'];

// Verify this broadcast belongs to this worker and is still pending
$checkStmt = $conn->prepare("
    SELECT qb.id, qs.*
    FROM quick_match_broadcasts qb
    JOIN quick_match_sessions qs ON qb.session_id = qs.id
    WHERE qb.id = ? AND qb.worker_id = ? AND qb.status = 'pending'
");
$checkStmt->execute([$broadcast_id, $worker_id]);
$broadcast = $checkStmt->fetch();

if (!$broadcast) {
    echo json_encode([
        'success' => false,
        'message' => 'This request is no longer available or has expired'
    ]);
    exit();
}

// Check if session is still valid
if (strtotime($broadcast['expires_at']) < time()) {
    echo json_encode([
        'success' => false, 
        'message' => 'This request has expired'
    ]);
    exit();
}

$conn->beginTransaction();

try {
    // Check if already accepted by someone else
    $acceptedCheck = $conn->prepare("
        SELECT id FROM quick_match_broadcasts
        WHERE session_id = ? AND status = 'accepted'
    ");
    $acceptedCheck->execute([$session_id]);
    $accepted = $acceptedCheck->fetch();

    if ($accepted && $action === 'accept') {
        throw new Exception('This job has already been accepted by another worker.');
    }

    // Update broadcast status
    $status = $action === 'accept' ? 'accepted' : 'declined';
    $updateStmt = $conn->prepare("
        UPDATE quick_match_broadcasts
        SET status = ?, responded_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$status, $broadcast_id]);

    if ($action === 'accept') {
        // Update session status
        $updateSession = $conn->prepare("
            UPDATE quick_match_sessions SET status = 'matched' WHERE id = ?
        ");
        $updateSession->execute([$session_id]);

        // Get worker name
        $workerInfoStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $workerInfoStmt->execute([$worker_id]);
        $workerInfo = $workerInfoStmt->fetch();
        $workerName = $workerInfo['full_name'];

        // Create booking
        $booking_date = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $bookingStmt = $conn->prepare("
            INSERT INTO bookings
            (client_id, worker_id, service_type, problem_desc, urgency_level, status, booking_date, payment_method, payment_status)
            VALUES (?, ?, ?, ?, ?, 'Accepted', ?, 'Cash', 'Pending')
            RETURNING id
        ");
        $bookingStmt->execute([
            $broadcast['client_id'],
            $worker_id,
            $broadcast['service_category'],
            $broadcast['problem_desc'],
            $broadcast['urgency_level'],
            $booking_date
        ]);
        $booking_id = $bookingStmt->fetchColumn();

        // Create notification for client using your sendNotification function
        $message = "🎉 " . $workerName . " has accepted your quick match request! They're on their way.";
        $link = "../client/view_booking.php?id=" . $booking_id;
        sendNotification($conn, $broadcast['client_id'], $message, $link);

        // Mark other broadcasts as expired
        $expireStmt = $conn->prepare("
            UPDATE quick_match_broadcasts
            SET status = 'expired'
            WHERE session_id = ? AND id != ?
        ");
        $expireStmt->execute([$session_id, $broadcast_id]);

        $conn->commit();

        echo json_encode([
            'success' => true,
            'booking_id' => $booking_id,
            'message' => 'Job accepted successfully!'
        ]);

    } else {
        // Just declined, check if all workers declined
        $statsStmt = $conn->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined
            FROM quick_match_broadcasts
            WHERE session_id = ?
        ");
        $statsStmt->execute([$session_id]);
        $stats = $statsStmt->fetch();

        if ($stats['total'] == $stats['declined']) {
            // All workers declined, update session
            $updateSession = $conn->prepare("
                UPDATE quick_match_sessions SET status = 'expired' WHERE id = ?
            ");
            $updateSession->execute([$session_id]);

            // Notify client
            $message = "😔 Unfortunately, all available workers have declined your request. Please try again.";
            $link = "../client/quick_match.php";
            sendNotification($conn, $broadcast['client_id'], $message, $link);
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Request declined'
        ]);
    }

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>