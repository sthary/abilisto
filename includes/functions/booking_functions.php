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
        if ($payment_method === 'Xendit') {
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
                      WHERE worker_id = $worker_id 
                      AND status IN ('Pending', 'Accepted')
                      AND ABS(TIMESTAMPDIFF(HOUR, booking_date, '$datetime')) < 3";
        
        $result = $this->conn->query($check_sql);
        $row = $result->fetch_assoc();
        
        return $row['conflict'] == 0;
    }
    
    /**
     * Get worker's busy slots for the next 7 days
     */
    public function getBusySlots($worker_id, $days = 7) {
        $sql = "SELECT booking_date 
                FROM bookings 
                WHERE worker_id = $worker_id 
                AND status IN ('Pending', 'Accepted')
                AND booking_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL $days DAY)
                ORDER BY booking_date ASC";
        
        $result = $this->conn->query($sql);
        $slots = [];
        while ($row = $result->fetch_assoc()) {
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
        $problem_desc = $this->conn->real_escape_string($data['problem_desc']);
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
            $client_id, $worker_id, '$problem_desc', '$booking_date',
            '$urgency', '$payment_method', 'Pending', 'Pending',
            $calculated_fee, NOW()
        )";
        
        if ($this->conn->query($sql)) {
            $booking_id = $this->conn->insert_id;
            
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
            $booking_id, $subtotal, $discount, $total, NOW()
        )";
        
        // Create fee_logs table if not exists
        $this->conn->query("CREATE TABLE IF NOT EXISTS fee_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            subtotal DECIMAL(10,2),
            discount DECIMAL(10,2),
            total DECIMAL(10,2),
            calculated_at DATETIME,
            INDEX (booking_id)
        )");
        
        $this->conn->query($log_sql);
    }
    
    /**
     * Update booking status with validation
     */
    public function updateStatus($booking_id, $new_status, $worker_id = null) {
        // Verify ownership if worker_id provided
        if ($worker_id) {
            $check = $this->conn->query("SELECT id FROM bookings 
                                         WHERE id = $booking_id AND worker_id = $worker_id");
            if ($check->num_rows == 0) {
                return ['success' => false, 'message' => 'Unauthorized'];
            }
        }
        
        $valid_statuses = ['Pending', 'Accepted', 'Declined', 'Completed', 'Cancelled'];
        if (!in_array($new_status, $valid_statuses)) {
            return ['success' => false, 'message' => 'Invalid status'];
        }
        
        // Get current status
        $current = $this->conn->query("SELECT status FROM bookings WHERE id = $booking_id")->fetch_assoc();
        
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
        
        $this->conn->query("UPDATE bookings SET status = '$new_status' WHERE id = $booking_id");
        
        return ['success' => true, 'message' => 'Status updated'];
    }
}
?>