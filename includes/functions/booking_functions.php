<?php
// includes/functions/booking_functions.php
require_once __DIR__ . '/../../config/constants.php';

class BookingManager {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Calculate booking fee based on distance, urgency, and payment method
     */
    public function calculateFee($distance, $urgency, $payment_method) {
        $base = BASE_FEE;
        $distance_fee = $distance * PER_KM_FEE;
        $urgency_fee = URGENCY_FEES[$urgency] ?? 0;

        $subtotal = $base + $distance_fee + $urgency_fee;

        // Apply GCash discount
        if ($payment_method === 'PayMongo') {
            $discount = ($subtotal * GCASH_DISCOUNT_PERCENT) / 100;
            $total = $subtotal - $discount;
            return [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'base' => $base,
                'distance_fee' => $distance_fee,
                'urgency_fee' => $urgency_fee
            ];
        }

        return [
            'subtotal' => $subtotal,
            'discount' => 0,
            'total' => $subtotal,
            'base' => $base,
            'distance_fee' => $distance_fee,
            'urgency_fee' => $urgency_fee
        ];
    }

    /**
     * Check if worker is available at given time (3-hour buffer)
     */
    public function isWorkerAvailable($worker_id, $datetime) {
        $check_sql = "SELECT COUNT(*) as conflict
                      FROM bookings
                      WHERE worker_id = ?
                      AND status IN ('Pending', 'Accepted')
                      AND ABS(EXTRACT(EPOCH FROM (booking_date - ?::timestamp)) / 3600) < 3";

        $stmt = $this->conn->prepare($check_sql);
        $stmt->execute([$worker_id, $datetime]);
        $row = $stmt->fetch();

        return $row['conflict'] == 0;
    }

    /**
     * Get worker's busy slots for the next 7 days
     */
    public function getBusySlots($worker_id, $days = 7) {
        $sql = "SELECT booking_date
                FROM bookings
                WHERE worker_id = ?
                AND status IN ('Pending', 'Accepted')
                AND booking_date BETWEEN NOW() AND (NOW() + (? || ' days')::interval)
                ORDER BY booking_date ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$worker_id, intval($days)]);
        $slots = [];
        while ($row = $stmt->fetch()) {
            $slots[] = $row['booking_date'];
        }
        return $slots;
    }

    /**
     * Create new booking
     */
    public function createBooking($data) {
        $client_id = $data['client_id'];
        $worker_id = $data['worker_id'];
        $problem_desc = $data['problem_desc'];
        $booking_date = $data['booking_date'];
        $urgency = $data['urgency'];
        $payment_method = $data['payment_method'];
        $calculated_fee = $data['calculated_fee'];
        $subtotal = $data['subtotal'] ?? $calculated_fee;
        $discount = $data['discount'] ?? 0;

        $sql = "INSERT INTO bookings (
            client_id, worker_id, problem_desc, booking_date,
            urgency_level, payment_method, payment_status, status,
            calculated_fee, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, 'Pending', 'Pending',
            ?, NOW()
        )";

        $stmt = $this->conn->prepare($sql);
        if ($stmt->execute([$client_id, $worker_id, $problem_desc, $booking_date, $urgency, $payment_method, $calculated_fee])) {
            $booking_id = $this->conn->lastInsertId('bookings_id_seq');

            // Log the fee calculation for audit
            $this->logFeeCalculation($booking_id, $subtotal, $discount, $calculated_fee);

            return $booking_id;
        }

        return false;
    }

    /**
     * Log fee calculation for audit purposes
     */
    private function logFeeCalculation($booking_id, $subtotal, $discount, $total) {
        $log_sql = "INSERT INTO fee_logs (
            booking_id, subtotal, discount, total, calculated_at
        ) VALUES (
            ?, ?, ?, ?, NOW()
        )";

        $stmt = $this->conn->prepare($log_sql);
        $stmt->execute([$booking_id, $subtotal, $discount, $total]);
    }

    /**
     * Update booking status with validation
     */
    public function updateStatus($booking_id, $new_status, $worker_id = null) {
        // Verify ownership if worker_id provided
        if ($worker_id) {
            $check = $this->conn->prepare("SELECT id FROM bookings
                                         WHERE id = ? AND worker_id = ?");
            $check->execute([$booking_id, $worker_id]);
            if (!$check->fetch()) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
        }

        $valid_statuses = ['Pending', 'Accepted', 'Declined', 'Completed', 'Cancelled'];
        if (!in_array($new_status, $valid_statuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }

        // Get current status
        $current_stmt = $this->conn->prepare("SELECT status FROM bookings WHERE id = ?");
        $current_stmt->execute([$booking_id]);
        $current = $current_stmt->fetch();

        // Validate status transition
        $allowed = [
            'Pending' => ['Accepted', 'Declined', 'Cancelled'],
            'Accepted' => ['Completed', 'Cancelled'],
            'Declined' => [],
            'Completed' => [],
            'Cancelled' => []
        ];

        if (!in_array($new_status, $allowed[$current['status']])) {
            return ['success' => false, 'message' => 'Invalid status transition'];
        }

        $update = $this->conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $update->execute([$new_status, $booking_id]);

        return ['success' => true, 'message' => 'Status updated'];
    }
}
