<?php
// api/check_availability.php
// Check if worker is available at selected date/time

require_once '../db_connect.php';
require_once '../includes/functions/booking_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['available' => false, 'message' => 'Not logged in']);
    exit();
}

$worker_id = intval($_GET['worker_id'] ?? 0);
$datetime = $_GET['datetime'] ?? '';

if (!$worker_id || !$datetime) {
    echo json_encode(['available' => false, 'message' => 'Missing parameters']);
    exit();
}

$bookingMgr = new BookingManager($conn);
$available = $bookingMgr->isWorkerAvailable($worker_id, $datetime);

if ($available) {
    echo json_encode(['available' => true]);
} else {
    // Get busy slots for suggestion
    $slots = $bookingMgr->getBusySlots($worker_id, 3);
    $next_free = findNextFreeSlot($conn, $worker_id, $datetime);
    
    echo json_encode([
        'available' => false,
        'message' => 'Worker is busy at this time',
        'busy_slots' => array_slice($slots, 0, 3),
        'suggested_time' => $next_free
    ]);
}

/**
 * Find next available time slot
 */
function findNextFreeSlot($conn, $worker_id, $from_datetime) {
    $from = new DateTime($from_datetime);
    
    for ($i = 1; $i <= 48; $i++) { // Check next 48 hours
        $check_time = clone $from;
        $check_time->modify("+$i hours");
        $check_time_str = $check_time->format('Y-m-d H:i:s');
        
        $check_sql = "SELECT COUNT(*) as conflict
                      FROM bookings
                      WHERE worker_id = ?
                      AND status IN ('Pending', 'Accepted')
                      AND ABS(EXTRACT(EPOCH FROM (booking_date - ?::timestamp)) / 3600) < 3";

        $stmt = $conn->prepare($check_sql);
        $stmt->execute([$worker_id, $check_time_str]);
        $row = $stmt->fetch();
        
        if ($row['conflict'] == 0) {
            return $check_time->format('Y-m-d\TH:i');
        }
    }
    
    return null;
}
?>