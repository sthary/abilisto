<?php
// worker/generate_receipt.php
session_start();
include '../db_connect.php';
include '../includes/init_lang.php';
include '../config/constants.php';
require_once '../includes/fcm_sender.php';
require_once '../includes/functions/wallet_manager.php';
require_once '../includes/functions/paymongo_client.php';

// Enable error logging
error_log("=== GENERATE RECEIPT STARTED ===");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    header("Location: dashboard.php");
    exit();
}

// Get booking details with all costs
$sql = "SELECT b.*, 
               u.full_name as client_name, 
               u.email as client_email,
               u.phone as client_phone,
               u.fcm_token as client_token,
               w.full_name as worker_name
        FROM bookings b
        JOIN users u ON b.client_id = u.id
        JOIN users w ON b.worker_id = w.id
        WHERE b.id = ? AND b.worker_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$booking_id, $worker_id]);
$booking = $stmt->fetch();

if (!$booking) {
    error_log("Booking not found for ID: $booking_id");
    header("Location: dashboard.php");
    exit();
}

error_log("Booking found: #$booking_id - Client: {$booking['client_name']}");

// Determine if mobilization was paid via PayMongo
$mobilization_paid = ($booking['payment_method'] == 'PayMongo' && $booking['payment_status'] == 'Paid');
$mobilization_amount = floatval($booking['calculated_fee']);
$labor_materials = floatval($booking['labor_materials_cost']);
$total_cost = floatval($booking['total_final_cost']);

// Calculate remaining balance
$remaining_balance = $mobilization_paid ? $labor_materials : $total_cost;

// Admin fee (4%) — read the value complete_job.php already computed and stored,
// instead of recomputing it here independently (the two calculations used to use
// different bases: labor-only vs. total, causing the actual amount charged to
// diverge from what complete_job.php showed/stored). Fallback only covers the
// edge case of reaching this page without going through complete_job.php first.
$admin_fee = floatval($booking['admin_fee_amount']);
if ($admin_fee <= 0 && $total_cost > 0) {
    $admin_fee = round($total_cost * (ADMIN_COMMISSION_PERCENT / 100), 2);
}
$worker_gets = $total_cost - $admin_fee;

error_log("Mobilization paid: " . ($mobilization_paid ? 'YES' : 'NO'));
error_log("Remaining balance: ₱$remaining_balance");

// Generate a PayMongo Checkout Session for the remaining balance
$qr_code_url = null;
$paymongo_link_id = null;
$qr_error = null;

if ($remaining_balance > 0 && $booking['final_payment_status'] != 'paid') {

    error_log("Attempting to generate PayMongo checkout session for ₱$remaining_balance");

    // Validate minimum amount
    if ($remaining_balance < 1.00) {
        $qr_error = "Amount too small for online payment. Please use cash option.";
        error_log($qr_error);
    } else {
        $reference_number = 'FINAL-' . time() . '-' . $booking_id . '-' . rand(100, 999);

        $link = paymongoCreateCheckoutSession(
            $remaining_balance,
            'Final Payment for Booking #' . $booking_id,
            $reference_number,
            ['booking_id' => $booking_id, 'worker_id' => $worker_id, 'type' => 'final_payment'],
            'https://abilisto.site/client/final_payment_success.php?booking_id=' . $booking_id,
            'https://abilisto.site/worker/dashboard.php'
        );

        if ($link['success']) {
            $qr_code_url      = $link['checkout_url'];
            $paymongo_link_id = $link['id'];

            // Save to database (column name predates the PayMongo migration)
            $update_sql = "UPDATE bookings SET
                          final_payment_qr = ?,
                          final_payment_xendit_id = ?
                          WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute([$qr_code_url, $paymongo_link_id, $booking_id]);

            error_log("✅ PayMongo Link saved: $qr_code_url");
        } else {
            error_log("❌ PayMongo API Error: " . $link['message']);
            $qr_error = "Payment gateway error: " . $link['message'];
        }
    }
}

// If remaining balance is 0, mark as completed automatically.
// Guarded by booking status so reloading this page never re-credits wallets twice.
if ($remaining_balance == 0 && $booking['status'] === 'Completed') {
    $zero_balance = true;
} elseif ($remaining_balance == 0) {
    // Nothing left to collect online — route through the same guarded
    // WalletManager methods every other settlement path uses (previously
    // this branch used raw SQL with no wallet_transactions log entry at
    // all, making this real money movement invisible to the Finance
    // dashboard, and no idempotency guard beyond the outer status check).
    error_log("Remaining balance is 0, auto-completing booking");

    try {
        $wallet = new WalletManager($conn);

        $mobilization_released = ($mobilization_paid && intval($booking['is_escrow']) === 1);
        $mobilization_amount   = $mobilization_released ? floatval($booking['calculated_fee']) : 0;

        if ($mobilization_amount > 0) {
            $wallet->creditOnlineFinalPayment($booking_id, $worker_id, $mobilization_amount, 0);
        }

        if ($total_cost > 0) {
            $wallet->processFinalPaymentCommission($booking_id, $worker_id, $total_cost, $mobilization_paid ? 'PayMongo' : 'Cash');
        }

        $conn->prepare("UPDATE bookings
                      SET status = 'Completed',
                          final_payment_status = 'paid',
                          updated_at = NOW()
                      WHERE id = ? AND status != 'Completed'")
             ->execute([$booking_id]);

        $conn->prepare("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?")
             ->execute([$worker_id]);

        $notif_msg = "✅ Job Complete! Total: ₱" . number_format($total_cost, 2) . ". Thank you for using Abilisto!";
        sendNotification($conn, $booking['client_id'], $notif_msg, "../client/my_bookings.php");

        try {
            $fcm = new FCMv1();
            $fcm->send(
                $booking['client_id'],
                "✅ Job Complete!",
                "Total: ₱" . number_format($total_cost, 2) . ". Thank you for using Abilisto!",
                ['url' => '/abilisto/client/my_bookings.php']
            );
        } catch (Exception $e) {
            error_log("Receipt push error: " . $e->getMessage());
        }

        error_log("✅ Booking auto-completed successfully");
        $zero_balance = true;

    } catch (Exception $e) {
        error_log("❌ Auto-complete failed: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>ABILISTO - Payment Receipt</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@300;500;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                    },
                    fontFamily: {
                        display: ["Space Grotesk", "sans-serif"],
                        body: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "1rem",
                        'xl': '1.5rem',
                        '2xl': '2rem',
                        '3xl': '3rem',
                    },
                },
            },
        };
    </script>
    
    <style type="text/tailwindcss">
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .text-glow-yellow {
            text-shadow: 0 0 15px rgba(234, 179, 8, 0.4);
        }
        .receipt-shadow {
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08);
        }
        .qr-glow {
            box-shadow: 0 0 50px -10px rgba(59, 130, 246, 0.3);
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
<body class="bg-background-light dark:bg-background-dark font-body text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300">
<?php include '../includes/abilisto_alert.php'; ?>

<div class="max-w-screen-xl mx-auto px-4 py-12 flex flex-col items-center animate-fadeIn">
    
    <!-- Main Receipt Card -->
    <div class="w-full max-w-2xl glass receipt-shadow rounded-2xl overflow-hidden relative mb-8">
        <div class="h-2 w-full bg-gradient-to-r from-blue-500 via-indigo-500 to-purple-500"></div>
        
        <div class="p-8 md:p-12">
            <!-- Header -->
            <header class="text-center mb-10">
                <h1 class="font-display text-4xl font-bold tracking-[0.2em] mb-2 dark:text-white">ABILISTO</h1>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400 dark:text-slate-500 font-medium">Digital E-Receipt</p>
                
                <div class="mt-8 flex justify-center">
                    <div class="glass px-6 py-2 rounded-full border border-slate-200 dark:border-slate-700 flex items-center gap-2">
                        <span class="text-[10px] uppercase tracking-widest text-slate-400">Transaction ID</span>
                        <span class="font-display font-bold text-primary">#<?php echo str_pad($booking_id, 8, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>
            </header>
            
            <!-- Line Items -->
            <div class="space-y-6 mb-12">
                <div class="flex justify-between items-center py-4 border-b border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-medium">Mobilization Fee</span>
                    <span class="font-semibold">₱<?php echo number_format($mobilization_amount, 2); ?></span>
                </div>
                
                <div class="flex justify-between items-center py-4 border-b border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-medium">Status</span>
                    <?php if ($mobilization_paid): ?>
                        <div class="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-1.5 rounded-full border border-emerald-200 dark:border-emerald-800/50">
                            <span class="material-symbols-outlined text-emerald-500 text-sm">check_circle</span>
                            <span class="text-sm font-bold text-emerald-600 dark:text-emerald-500 uppercase tracking-wider">Paid via PayMongo</span>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-2 bg-yellow-50 dark:bg-yellow-900/20 px-4 py-1.5 rounded-full border border-yellow-200 dark:border-yellow-800/50">
                            <span class="material-symbols-outlined text-yellow-500 text-sm">schedule</span>
                            <span class="text-sm font-bold text-yellow-600 dark:text-yellow-500 uppercase tracking-wider text-glow-yellow">To be paid in cash</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex justify-between items-center py-4 border-b border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-medium">Labor & Materials</span>
                    <span class="font-semibold">₱<?php echo number_format($labor_materials, 2); ?></span>
                </div>
                
                <div class="flex justify-between items-center pt-8 pb-4">
                    <span class="text-xl font-display font-bold">Total Cost</span>
                    <span class="text-3xl font-display font-extrabold text-slate-900 dark:text-white">₱<?php echo number_format($total_cost, 2); ?></span>
                </div>

                <div class="flex justify-between items-center py-3 border-t border-slate-100 dark:border-slate-800 text-sm">
                    <span class="text-slate-400 dark:text-slate-500">Abilisto Platform Commission (<?php echo number_format($booking['admin_fee_percentage'] ?? ADMIN_COMMISSION_PERCENT, 0); ?>% of labor fee)</span>
                    <span class="text-slate-400 dark:text-slate-500">− ₱<?php echo number_format($admin_fee, 2); ?></span>
                </div>
                <div class="flex justify-between items-center py-4 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl px-4 -mx-4">
                    <span class="text-lg font-display font-bold text-emerald-700 dark:text-emerald-400">You'll Receive</span>
                    <span class="text-2xl font-display font-extrabold text-emerald-700 dark:text-emerald-400">₱<?php echo number_format($worker_gets, 2); ?></span>
                </div>
            </div>
            
            <!-- Remaining Balance -->
            <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 text-center text-white shadow-xl shadow-blue-500/20 relative overflow-hidden group">
                <div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-10"></div>
                <div class="relative z-10">
                    <p class="text-sm font-medium uppercase tracking-[0.2em] opacity-80 mb-2">Remaining Balance to Pay</p>
                    <h2 class="text-5xl font-display font-bold">₱<?php echo number_format($remaining_balance, 2); ?></h2>
                </div>
            </div>
            
            <!-- Buttons -->
            <div class="mt-8 flex flex-col gap-4">
                <button onclick="window.print()" class="w-full py-4 px-6 rounded-xl glass hover:bg-slate-50 dark:hover:bg-slate-800 transition-all flex items-center justify-center gap-3 border border-slate-200 dark:border-slate-700 font-semibold group">
                    <span class="material-symbols-outlined group-hover:translate-y-0.5 transition-transform">download</span>
                    Download Receipt (JPG)
                </button>
                
                <?php if ($remaining_balance > 0 && !isset($zero_balance)): ?>
                    <?php if ($booking['status'] === 'Pending Confirmation'): ?>
                        <!-- Worker already confirmed cash — waiting for client -->
                        <div class="w-full py-5 px-6 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 text-amber-700 dark:text-amber-400 font-bold text-base flex items-center justify-center gap-3">
                            <span class="material-symbols-outlined animate-pulse">hourglass_top</span>
                            Waiting for client to confirm...
                        </div>
                    <?php elseif ($booking['final_payment_status'] !== 'paid'): ?>
                        <!-- Cash not yet confirmed -->
                        <button onclick="notifyCashPayment(<?php echo $booking_id; ?>)" 
                                class="w-full py-5 px-6 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold text-lg shadow-lg shadow-emerald-500/20 hover:scale-[1.02] active:scale-[0.98] transition-all flex items-center justify-center gap-3">
                            <span class="material-symbols-outlined">payments</span>
                            Confirm Cash Payment
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($zero_balance)): ?>
                    <div class="w-full py-5 px-6 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300 font-bold text-lg flex items-center justify-center gap-3 border border-emerald-300 dark:border-emerald-700">
                        <span class="material-symbols-outlined">check_circle</span>
                        Job Completed - No Payment Needed
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- QR Code Section (only if there's remaining balance and not already pending confirmation) -->
    <?php if ($remaining_balance > 0 && !isset($zero_balance) && $booking['status'] !== 'Pending Confirmation'): ?>
    <div class="w-full max-w-2xl grid md:grid-cols-5 gap-6 items-stretch mb-8">
        <div class="md:col-span-3 glass p-6 md:p-10 rounded-2xl flex flex-col items-center text-center receipt-shadow border-primary/20">
            <h3 class="font-display font-bold text-xl mb-4 flex items-center gap-3">
                <span class="material-symbols-outlined text-primary text-3xl">qr_code_scanner</span>
                Scan to Pay
            </h3>

            
            <?php if ($qr_error): ?>
                <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-xl text-red-600 dark:text-red-400 mb-4">
                    <span class="material-symbols-outlined">error</span>
                    <p><?php echo $qr_error; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($qr_code_url): ?>
                <div class="bg-white p-4 md:p-6 rounded-3xl qr-glow border border-slate-100 mb-8 transition-all hover:scale-[1.03] w-full aspect-square flex items-center justify-center">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?php echo urlencode($qr_code_url); ?>" 
                         alt="QR Code for Payment" 
                         class="w-full h-full object-contain">
                </div>
            <?php else: ?>
                <div class="bg-white p-4 md:p-6 rounded-3xl border border-slate-100 mb-8 w-full aspect-square flex items-center justify-center">
                    <span class="text-slate-400">QR Code Unavailable</span>
                </div>
            <?php endif; ?>
            
            <div class="space-y-3">
                <p class="text-lg font-bold text-slate-800 dark:text-slate-200">PayMongo</p>
                <p class="text-sm text-slate-400 max-w-[280px] mx-auto leading-relaxed">Position the QR code within the frame to pay instantly. Scannable from a distance.</p>
            </div>
            
            <div class="mt-8 w-full pt-6 border-t border-slate-100 dark:border-slate-800">
                <div class="flex items-center justify-center gap-2 text-yellow-600 dark:text-yellow-500 bg-yellow-50 dark:bg-yellow-900/20 py-2.5 px-4 rounded-xl text-xs font-bold uppercase tracking-wide">
                    <span class="material-symbols-outlined text-base">info</span>
                    Demo Mode Active
                </div>
            </div>
        </div>
        
        <div class="md:col-span-2 bg-blue-50/50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-800 p-8 rounded-2xl receipt-shadow flex flex-col">
            <h3 class="font-display font-bold text-lg mb-8 flex items-center gap-2 text-blue-700 dark:text-blue-400">
                <span class="material-symbols-outlined">help_center</span>
                Instructions
            </h3>
            
            <ul class="space-y-8 my-auto">
                <li class="flex flex-col gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-800/50 flex items-center justify-center">
                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-xl">qr_code_2</span>
                    </div>
                    <div>
                        <p class="font-bold text-sm mb-1 uppercase tracking-tight">Scan QR</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">Open any QR Code scaaner or your camera and scan the code. Payment is detected instantly.</p>
                    </div>
                </li>
                <li class="flex flex-col gap-3">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-800/50 flex items-center justify-center">
                        <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-xl">payments</span>
                    </div>
                    <div>
                        <p class="font-bold text-sm mb-1 uppercase tracking-tight">Cash Pay</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">If paying by cash, click 'Confirm' above to update the status.</p>
                    </div>
                </li>
            </ul>
            
            <div class="mt-8 pt-6 border-t border-blue-100/50 dark:border-blue-800/50">
                <p class="text-[10px] text-blue-400 uppercase tracking-widest text-center font-bold">Encrypted Gateway</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <footer class="mt-12 text-center text-slate-400 dark:text-slate-600 text-sm max-w-md mx-auto">
        <div class="flex items-center justify-center gap-2 mb-2">
            <span class="material-symbols-outlined text-lg">verified_user</span>
            <span class="font-medium">Securely processed by Abilisto Gateway</span>
        </div>
        <p class="text-xs opacity-70">
            This is a demonstration platform. All payments shown are simulated for design preview purposes.
        </p>
    </footer>
</div>

<script>
function notifyCashPayment(bookingId) {
    if (!confirm('Mark this job as paid in cash? The client will be notified to confirm completion.\n\nFunds will only be released after client confirmation.')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Notifying client...';
    
    fetch('../api/notify_cash_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            booking_id: bookingId
        })
    })
    .then(async response => {
        const text = await response.text();
        console.log('Cash payment notification response:', text);
        
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text);
            throw new Error('Invalid server response');
        }
    })
    .then(data => {
        if (data.success) {
            abilistoAlert('✅ Client has been notified! Waiting for their confirmation.').then(function(){
                window.location.reload(); // Reload to show "Waiting for client" state
            });
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// ── Auto-close: poll for payment completion (cash confirmed by client, or
// QR/PayMongo scanned and paid) and redirect back to the dashboard once the
// booking is actually Completed — the worker shouldn't have to sit on this
// page or manually refresh to find out the client paid. ──
<?php if (!isset($zero_balance) && $booking['status'] !== 'Completed'): ?>
(function () {
    const bookingId = <?php echo (int)$booking_id; ?>;
    const poll = setInterval(() => {
        fetch(`../api/check_final_payment_status.php?booking_id=${bookingId}`)
            .then(r => r.json())
            .then(data => {
                if (data.completed) {
                    clearInterval(poll);
                    const banner = document.createElement('div');
                    banner.className = 'fixed inset-0 bg-emerald-600/95 flex items-center justify-center z-50 text-white text-center px-6';
                    banner.innerHTML = `
                        <div>
                            <span class="material-symbols-outlined text-6xl mb-4 block">check_circle</span>
                            <p class="text-2xl font-display font-bold mb-2">Payment Received!</p>
                            <p class="text-emerald-100">Redirecting to your dashboard...</p>
                        </div>`;
                    document.body.appendChild(banner);
                    setTimeout(() => { window.location.href = 'dashboard.php'; }, 2000);
                }
            })
            .catch(() => {}); // silent — just try again next tick
    }, 5000);
})();
<?php endif; ?>

// Dark mode preference
if (localStorage.getItem('darkMode') === 'true') {
    document.documentElement.classList.add('dark');
}

// Save dark mode preference
document.querySelector('[onclick="document.documentElement.classList.toggle(\'dark\')"]')?.addEventListener('click', function() {
    const isDark = document.documentElement.classList.contains('dark');
    localStorage.setItem('darkMode', isDark);
});
</script>

</body>
</html>