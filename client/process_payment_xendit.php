<?php
// client/process_payment_xendit.php
// UPDATED: Now uses amount from database instead of URL parameter

include '../db.php';
include '../includes/init_lang.php';
include '../includes/functions/booking_functions.php';
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
    
    error_log("=== PROCESS PAYMENT GET ===");
    error_log("Booking ID from URL: $booking_id");
    
    // Get booking details from database
    $booking_sql = "SELECT b.*, u.full_name, u.email 
                    FROM bookings b
                    JOIN users u ON b.client_id = u.id
                    WHERE b.id = $booking_id AND b.client_id = $client_id";
    $booking_res = $conn->query($booking_sql);
    
    if ($booking_res->num_rows === 0) {
        error_log("Booking not found: $booking_id");
        $_SESSION['error'] = "Booking not found.";
        header("Location: dashboard.php");
        exit();
    }
    
    $booking = $booking_res->fetch_assoc();
    $worker_id = $booking['worker_id'];
    $full_name = $booking['full_name'];
    $email = $booking['email'];
    $amount = floatval($booking['calculated_fee']); // Get amount from database
    
    error_log("Booking found - Amount from DB: $amount");
    
    // Validate amount
    if ($amount <= 0) {
        error_log("❌ Invalid amount in database: $amount");
        $_SESSION['error'] = "Invalid booking amount. Please contact support.";
        header("Location: booking.php?worker_id=$worker_id");
        exit();
    }
    
    // Check if booking already has a transaction ID (already processed)
    if (!empty($booking['transaction_id'])) {
        error_log("Booking already has transaction ID: " . $booking['transaction_id']);
        // Redirect to Xendit checkout if available
        if (!empty($booking['checkout_url'])) {
            header("Location: " . $booking['checkout_url']);
            exit();
        }
    }
    
    // Prepare Xendit API Data
    $external_id = 'INV-' . time() . '-' . $booking_id . '-' . rand(100, 999);
    
    $secret_key = getenv('XENDIT_SECRET_KEY');
    
    $data = [
        'external_id' => $external_id,
        'amount' => $amount,
        'description' => 'Mobilization Fee for Booking #' . $booking_id,
        'invoice_duration' => 86400,
        'currency' => 'PHP',
        'customer' => [
            'given_names' => $full_name,
            'email' => $email
        ],
        'success_redirect_url' => 'https://abilisto.site/client/payment_success_xendit.php?booking_id=' . $booking_id,
        'failure_redirect_url' => 'https://abilisto.site/client/booking.php?worker_id=' . $worker_id,
        'metadata' => [
            'booking_id' => $booking_id,
            'client_id' => $client_id,
            'worker_id' => $worker_id
        ]
    ];
    
    error_log("Xendit Request: " . json_encode($data));
    
    // Call Xendit API
    $ch = curl_init('https://api.xendit.co/v2/invoices');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($http_code == 200 && isset($result['invoice_url'])) {
        // Update booking with Xendit details
        $update_sql = "UPDATE bookings 
                       SET transaction_id = '{$result['id']}', 
                           checkout_url = '{$result['invoice_url']}'
                       WHERE id = $booking_id";
        
        if ($conn->query($update_sql)) {
            error_log("✅ Redirecting to Xendit: " . $result['invoice_url']);
            // Redirect to Xendit checkout
            header("Location: " . $result['invoice_url']);
            exit();
        } else {
            error_log("❌ Failed to update booking with Xendit data: " . $conn->error);
            $_SESSION['error'] = "Payment processing error. Please contact support.";
            header("Location: booking.php?worker_id=$worker_id");
        }
    } else {
        // Log error
        error_log("❌ Xendit API Error (HTTP $http_code): " . print_r($result, true));
        
        $_SESSION['error'] = "Payment gateway error. Please try again later.";
        header("Location: booking.php?worker_id=$worker_id");
    }
    exit();
}

// ============================================
// CASE 2: New booking via POST (existing code)
// ============================================
if (isset($_POST['book_btn'])) {
    
    // Handle cash booking
    if ($_POST['payment_method'] === 'Cash') {
        // Validate required fields
        $required = ['worker_id', 'service_date', 'urgency', 'problem_desc'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                $_SESSION['error'] = 'Please fill all required fields';
                header("Location: booking.php?worker_id=" . $_POST['worker_id']);
                exit();
            }
        }
        
        $worker_id = intval($_POST['worker_id']);
        $booking_date = $_POST['service_date'];
        $urgency = $_POST['urgency'];
        $problem_desc = $conn->real_escape_string($_POST['problem_desc']);
        
        // Calculate fee
        $distance = floatval($_SESSION['last_distance'] ?? 0);
        $fee_calc = $bookingMgr->calculateFee($distance, $urgency, 'Cash');
        $amount = $fee_calc['total'];
        
        // Create booking
        $booking_data = [
            'client_id' => $client_id,
            'worker_id' => $worker_id,
            'problem_desc' => $problem_desc,
            'booking_date' => $booking_date,
            'urgency' => $urgency,
            'payment_method' => 'Cash',
            'calculated_fee' => $amount,
            'subtotal' => $fee_calc['subtotal'],
            'discount' => $fee_calc['discount']
        ];
        
        $booking_id = $bookingMgr->createBooking($booking_data);
        
        if ($booking_id) {
            // Get worker info
            $worker = $conn->query("SELECT full_name FROM users WHERE id = $worker_id")->fetch_assoc();
            
            // Send notification to worker
            $notif_msg = "📋 New Cash Booking Request!\n\n" .
                         $_SESSION['full_name'] . " needs your services on " . 
                         date('M d, h:i A', strtotime($booking_date)) . "\n" .
                         "Fee: ₱$amount (to be paid upon service)";
            
            sendNotification($conn, $worker_id, $notif_msg, "../worker/view_booking.php?id=$booking_id");
            
            // Send notification to client
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
    
    // Handle GCash/Xendit payment
    if ($_POST['payment_method'] === 'Xendit') {
        // Validate
        $worker_id = intval($_POST['worker_id']);
        $booking_date = $_POST['service_date'];
        $urgency = $_POST['urgency'];
        $problem_desc = $conn->real_escape_string($_POST['problem_desc']);
        
        // Calculate fee with discount
        $distance = floatval($_SESSION['last_distance'] ?? 0);
        $fee_calc = $bookingMgr->calculateFee($distance, $urgency, 'Xendit');
        $amount = $fee_calc['total'];
        
        error_log("=== CREATING GCASH BOOKING ===");
        error_log("Worker ID: $worker_id");
        error_log("Amount calculated: $amount");
        
        // Create booking first (with Pending payment)
        $booking_data = [
            'client_id' => $client_id,
            'worker_id' => $worker_id,
            'problem_desc' => $problem_desc,
            'booking_date' => $booking_date,
            'urgency' => $urgency,
            'payment_method' => 'Xendit',
            'calculated_fee' => $amount,
            'subtotal' => $fee_calc['subtotal'],
            'discount' => $fee_calc['discount']
        ];
        
        $booking_id = $bookingMgr->createBooking($booking_data);
        
        if (!$booking_id) {
            error_log("❌ Failed to create booking. Last SQL error: " . $conn->error);
            $_SESSION['error'] = "Failed to create booking. Please try again.";
            header("Location: booking.php?worker_id=$worker_id");
            exit();
        }
        
        error_log("✅ Booking created successfully with ID: $booking_id");
        
        // Verify the amount was saved correctly
        $check_sql = "SELECT calculated_fee FROM bookings WHERE id = $booking_id";
        $check_res = $conn->query($check_sql);
        $check_row = $check_res->fetch_assoc();
        error_log("✅ Amount in database: " . $check_row['calculated_fee']);
        
        // Now redirect to self with just the booking_id (amount will be fetched from DB)
        error_log("Redirecting to process_payment_xendit.php?booking_id=$booking_id");
        header("Location: process_payment_xendit.php?booking_id=$booking_id");
        exit();
    }
}

// If we get here, something went wrong
header("Location: dashboard.php");
exit();
?>