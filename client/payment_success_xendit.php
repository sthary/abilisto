<?php
// client/payment_success_xendit.php
// FIXED: Use actual booking amount, no recovery code

error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';

// Enable detailed logging
error_log("=== payment_success_xendit.php called ===");
error_log("GET params: " . print_r($_GET, true));

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    error_log("User not logged in or not client");
    header("Location: ../auth/login.php");
    exit();
}

$client_id = $_SESSION['user_id'];
error_log("Client ID: $client_id");

$wallet = new WalletManager($conn);

// Get booking ID from URL
if (!isset($_GET['booking_id']) && !isset($_GET['external_id'])) {
    error_log("No booking_id or external_id in URL");
    header("Location: dashboard.php");
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);
error_log("Booking ID from URL: $booking_id");

// If we have external_id from Xendit, extract booking_id
if (isset($_GET['external_id']) && !$booking_id) {
    $external_id = $_GET['external_id'];
    error_log("External ID: $external_id");
    // Format: INV-timestamp-booking_id-random
    $parts = explode('-', $external_id);
    if (count($parts) >= 3) {
        $booking_id = intval($parts[2]);
        error_log("Extracted booking ID from external_id: $booking_id");
    }
}

if (!$booking_id) {
    error_log("No valid booking ID found");
    header("Location: dashboard.php?error=invalid_booking");
    exit();
}

// Verify booking belongs to this client
$check_sql = "SELECT b.*, u.full_name as client_name, u.email
              FROM bookings b
              JOIN users u ON b.client_id = u.id
              WHERE b.id = ? AND b.client_id = ?";
error_log("Check SQL: $check_sql");

$check_stmt = $conn->prepare($check_sql);
$check_stmt->execute([$booking_id, $client_id]);
$booking = $check_stmt->fetch();

if (!$booking) {
    error_log("Booking $booking_id not found for client $client_id");
    header("Location: dashboard.php?error=not_found");
    exit();
}

error_log("Booking found: " . print_r($booking, true));

$worker_id = $booking['worker_id'];
$amount = floatval($booking['calculated_fee']);

error_log("=== AMOUNT FROM DATABASE ===");
error_log("Booking ID: $booking_id");
error_log("Calculated fee from DB: " . $booking['calculated_fee']);
error_log("Amount after floatval: $amount");

// Check if amount is valid
if ($amount <= 0) {
    error_log("❌ CRITICAL: Amount is zero or negative in database!");
    error_log("Full booking record: " . print_r($booking, true));
    throw new Exception("Invalid booking amount: ₱$amount");
}

// Get worker info for display
$worker_stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE id = ?");
$worker_stmt->execute([$worker_id]);
$worker = $worker_stmt->fetch();
$worker_name = $worker['full_name'] ?? 'Worker';
$worker_pic = !empty($worker['profile_pic']) && file_exists("../uploads/profiles/".$worker['profile_pic']) 
    ? "../uploads/profiles/".$worker['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($worker_name) . "&background=0d59f2&color=fff&size=64&bold=true";

// Begin transaction
$conn->beginTransaction();

try {
    error_log("Starting transaction for booking $booking_id");

    // Update booking payment status
    $update_sql = "UPDATE bookings
                   SET payment_status = 'Paid',
                       status = 'Pending',
                       is_escrow = TRUE
                   WHERE id = ? AND client_id = ?";

    error_log("Update SQL: $update_sql");

    $update_stmt = $conn->prepare($update_sql);
    if (!$update_stmt->execute([$booking_id, $client_id])) {
        throw new Exception("Failed to update booking");
    }
    error_log("Booking updated successfully");
    
    // HOLD payment in admin wallet (escrow)
    error_log("Calling holdEscrowPayment($booking_id, $worker_id, $amount)");
    $escrow_result = $wallet->holdEscrowPayment($booking_id, $worker_id, $amount);
    
    error_log("holdEscrowPayment result: " . print_r($escrow_result, true));
    
    if (!$escrow_result['success']) {
        throw new Exception("Escrow failed: " . ($escrow_result['message'] ?? 'Unknown error'));
    }
    
    // Send notification to worker
    $notif_msg = "💰 New PAID Booking Request!\n\n" .
                 $_SESSION['full_name'] . " has paid ₱$amount via GCash.\n" .
                 "Payment is held in escrow. Accept to release payment.";
    
    $notif_link = "../worker/view_booking.php?id=" . $booking_id;
    
    if (function_exists('sendNotification')) {
        sendNotification($conn, $worker_id, $notif_msg, $notif_link);
        error_log("Notification sent to worker $worker_id");
    }
    
    // Send notification to client
    $client_notif = "✅ Payment Successful! Your ₱$amount payment is now in escrow.\n" .
                    "The worker will be notified and funds will be released when they accept.";
    
    sendNotification($conn, $client_id, $client_notif, "../client/my_bookings.php");
    error_log("Notification sent to client $client_id");
    
    $conn->commit();
    error_log("Transaction committed successfully");
    
    // Success - show success page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8"/>
        <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
        <title>Payment Successful | Abilisto</title>
        
        <!-- Tailwind -->
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
        
        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
        
        <script id="tailwind-config">
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            "primary": "#146af5",
                            "off-white": "#F8FAFC",
                            "escrow-blue": "#E3F2FD",
                            "dark-text": "#1A1A1A",
                        },
                        fontFamily: {
                            "display": ["Plus Jakarta Sans", "sans-serif"]
                        },
                        borderRadius: {
                            "DEFAULT": "0.25rem",
                            "lg": "0.5rem",
                            "xl": "0.75rem",
                            "2xl": "1rem",
                            "3xl": "1.5rem",
                            "full": "9999px"
                        },
                    },
                },
            }
        </script>
        
        <style type="text/tailwindcss">
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
            }
            .glass-card {
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(203, 213, 225, 0.5);
            }
            .success-glow {
                box-shadow: 0 0 40px rgba(34, 197, 94, 0.15);
            }
            .radial-bg {
                background: radial-gradient(circle at top center, #f0f7ff 0%, #F8FAFC 100%);
            }
            .ambient-shadow {
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08);
            }
            
            /* Animation */
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .animate-fadeIn {
                animation: fadeIn 0.5s ease forwards;
            }
        </style>
    </head>
    <body class="bg-off-white font-display antialiased text-dark-text">
        <?php include '../includes/navbar.php'; ?>
        
        <div class="relative min-h-screen w-full overflow-hidden radial-bg flex flex-col items-center justify-center p-4 animate-fadeIn">
            <div class="absolute top-0 left-0 w-full h-full pointer-events-none">
                <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-primary/5 blur-[120px] rounded-full"></div>
                <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-emerald-500/5 blur-[120px] rounded-full"></div>
            </div>
            
            <div class="w-full max-w-[520px] glass-card rounded-3xl p-8 md:p-12 relative z-10 ambient-shadow">
                <div class="flex flex-col items-center text-center">
                    
                    <!-- Success Icon -->
                    <div class="mb-8 relative">
                        <div class="size-24 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-500 success-glow">
                            <span class="material-symbols-outlined !text-5xl font-bold">check_circle</span>
                        </div>
                    </div>
                    
                    <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2 text-dark-text">
                        Payment Successful!
                    </h1>
                    
                    <p class="text-slate-500 text-sm md:text-base mb-8">
                        Your transaction has been processed securely.
                    </p>
                    
                    <!-- Amount -->
                    <div class="w-full mb-10">
                        <div class="text-5xl font-black text-dark-text mb-6">
                            ₱<?php echo number_format($amount, 2); ?>
                        </div>
                        <div class="w-full h-px bg-gradient-to-r from-transparent via-slate-200 to-transparent"></div>
                    </div>
                    
                    <!-- Escrow Badge -->
                    <div class="w-full bg-escrow-blue border border-blue-100 rounded-2xl p-6 mb-8 text-left relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4 opacity-10">
                            <span class="material-symbols-outlined !text-6xl text-blue-900">verified_user</span>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="bg-blue-200 p-2 rounded-lg text-blue-700 shrink-0">
                                <span class="material-symbols-outlined">shield_lock</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-blue-900 mb-1">Payment Held in Escrow</h3>
                                <p class="text-blue-800/80 text-sm leading-relaxed">
                                    Funds are securely held and will only be released once the worker accepts your booking.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Worker Notification -->
                    <div class="flex items-center gap-3 bg-slate-100 px-4 py-2 rounded-full border border-slate-200 mb-10">
                        <div class="size-6 rounded-full overflow-hidden bg-slate-200">
                            <img src="<?php echo $worker_pic; ?>" class="w-full h-full object-cover" alt="<?php echo $worker_name; ?>">
                        </div>
                        <span class="text-xs font-medium text-slate-600">Notifying <?php echo $worker_name; ?>...</span>
                        <span class="material-symbols-outlined text-[14px] animate-spin text-primary">sync</span>
                    </div>
                    
                    <!-- View Bookings Button -->
                    <a href="my_bookings.php" class="w-full py-4 bg-primary hover:bg-blue-600 text-white font-bold rounded-2xl transition-all shadow-[0_8px_30px_rgb(13,89,242,0.2)] flex items-center justify-center gap-2 group">
                        <span>View My Bookings</span>
                        <span class="material-symbols-outlined transition-transform group-hover:translate-x-1">arrow_forward</span>
                    </a>
                    
                    <!-- Auto-redirect Timer -->
                    <div class="mt-8 flex items-center gap-2 px-4 py-1.5 rounded-full border border-slate-200 bg-slate-50">
                        <span class="material-symbols-outlined text-xs text-slate-400">schedule</span>
                        <span class="text-[10px] uppercase tracking-widest text-slate-500 font-bold" id="timer">Redirecting to bookings in 5s...</span>
                    </div>
                </div>
            </div>
            
            <!-- Footer Links -->
            <div class="mt-8 flex gap-6 text-slate-500">
                <a class="text-xs hover:text-dark-text transition-colors" href="#">Help Center</a>
                <a class="text-xs hover:text-dark-text transition-colors" href="#">Transaction Receipt</a>
                <a class="text-xs hover:text-dark-text transition-colors" href="#">Support</a>
            </div>
        </div>
        
        <script>
            // Auto-redirect after 5 seconds with countdown
            let seconds = 5;
            const timerElement = document.getElementById('timer');
            
            const countdown = setInterval(function() {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(countdown);
                    window.location.href = 'my_bookings.php';
                } else {
                    timerElement.textContent = `Redirecting to bookings in ${seconds}s...`;
                }
            }, 1000);
        </script>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    $conn->rollBack();
    error_log("❌ ERROR in payment_success_xendit.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Show error page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8"/>
        <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
        <title>Payment Error | Abilisto</title>
        
        <!-- Tailwind -->
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
        
        <!-- Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
        
        <script id="tailwind-config">
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            "primary": "#146af5",
                            "off-white": "#F8FAFC",
                            "dark-text": "#1A1A1A",
                        },
                        fontFamily: {
                            "display": ["Plus Jakarta Sans", "sans-serif"]
                        },
                    },
                },
            }
        </script>
    </head>
    <body class="bg-off-white font-display antialiased text-dark-text">
        
        <div class="relative min-h-screen w-full overflow-hidden radial-bg flex flex-col items-center justify-center p-4">
            <div class="w-full max-w-[520px] glass-card rounded-3xl p-8 md:p-12 relative z-10 ambient-shadow">
                <div class="flex flex-col items-center text-center">
                    
                    <!-- Error Icon -->
                    <div class="mb-8 relative">
                        <div class="size-24 rounded-full bg-red-100 flex items-center justify-center text-red-500">
                            <span class="material-symbols-outlined !text-5xl font-bold">error</span>
                        </div>
                    </div>
                    
                    <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2 text-dark-text">
                        Payment Error
                    </h1>
                    
                    <p class="text-slate-500 text-sm md:text-base mb-8">
                        There was an error processing your payment.
                    </p>
                    
                    <!-- Error Message -->
                    <div class="w-full bg-red-50 border border-red-100 rounded-2xl p-6 mb-8 text-left">
                        <p class="text-red-700 text-sm">
                            <?php echo htmlspecialchars($e->getMessage()); ?>
                        </p>
                    </div>
                    
                    <!-- Back to Bookings Button -->
                    <a href="my_bookings.php" class="w-full py-4 bg-primary hover:bg-blue-600 text-white font-bold rounded-2xl transition-all shadow-[0_8px_30px_rgb(13,89,242,0.2)] flex items-center justify-center gap-2 group">
                        <span>Go to My Bookings</span>
                        <span class="material-symbols-outlined transition-transform group-hover:translate-x-1">arrow_forward</span>
                    </a>
                    
                    <!-- Support Link -->
                    <div class="mt-8">
                        <a href="#" class="text-xs text-slate-500 hover:text-primary transition-colors">
                            Contact Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>