<?php
// client/final_payment_success.php
// Landing page after client successfully pays via GCash/Maya (Xendit invoice).
// This page:
//   1. Verifies the booking and Xendit payment status
//   2. Credits worker wallet with net amount (labor+materials portion only — mobilization was separate)
//   3. Calls processFinalPaymentCommission (4%) — deducts from worker, credits admin
//   4. Awards Listo Points to worker
//   5. Marks booking as Completed
//   6. Notifies the worker
//   7. Shows a success screen to the client
//
// NOTE on the 2.3% GCash/Maya convenience fee:
//   Xendit charges the CLIENT an extra 2.3% on top of the invoice amount.
//   This means the client pays: labor_materials_cost × 1.023
//   The worker receives: labor_materials_cost (the original amount, unchanged)
//   The 2.3% goes entirely to Xendit/GCash — it does NOT affect the worker's payout
//   or the 4% admin commission calculation. Both are based on the original amounts.

session_start();
require_once '../db.php';
require_once '../includes/init_lang.php';
require_once '../includes/functions/wallet_functions.php';
require_once '../includes/fcm_sender.php';
require_once '../config/constants.php';

error_log("=== FINAL PAYMENT SUCCESS PAGE ===");

$booking_id = intval($_GET['booking_id'] ?? 0);
$error      = null;
$booking    = null;
$points_result = ['success' => false, 'milestone' => false, 'new_points' => 0, 'message' => ''];

if (!$booking_id) {
    $error = "Invalid booking reference.";
} else {
    // Fetch booking with worker + client info + current listo points
    $sql = "SELECT b.*,
                   uc.full_name  AS client_name,
                   uc.id         AS client_user_id,
                   uw.full_name  AS worker_name,
                   uw.fcm_token  AS worker_token,
                   wp.listo_points AS worker_current_points
            FROM bookings b
            JOIN users          uc ON b.client_id  = uc.id
            JOIN users          uw ON b.worker_id  = uw.id
            JOIN worker_profiles wp ON wp.user_id  = b.worker_id
            WHERE b.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        $error = "Booking not found.";
    } elseif ($booking['final_payment_status'] === 'paid') {
        // Already processed — just show success screen, do nothing
        $already_done  = true;
        $points_result = ['success' => false, 'milestone' => false, 'new_points' => intval($booking['worker_current_points']), 'message' => ''];
        error_log("Booking #$booking_id already marked paid — skipping re-processing.");
    } else {
        // ---------------------------------------------------------------
        // Verify with Xendit that the invoice is actually PAID
        // ---------------------------------------------------------------
        $xendit_id   = $booking['final_payment_xendit_id'] ?? null;
        $xendit_paid = false;

        if ($xendit_id) {
            $secret_key = getenv('XENDIT_SECRET_KEY');
            $ch = curl_init("https://api.xendit.co/v2/invoices/$xendit_id");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($secret_key . ':')
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
            $xres      = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $xdata       = json_decode($xres, true);
                $xendit_paid = (isset($xdata['status']) && strtoupper($xdata['status']) === 'PAID');
                error_log("Xendit invoice $xendit_id status: " . ($xdata['status'] ?? 'unknown'));
            } else {
                error_log("Xendit verification HTTP $http_code for invoice $xendit_id");
                // Allow through in dev/demo mode — REMOVE this fallback in production
                $xendit_paid = true;
            }
        } else {
            // No xendit ID stored — demo/dev fallback
            $xendit_paid = true;
            error_log("⚠️ No Xendit ID found for booking #$booking_id — skipping verification (demo mode)");
        }

        if (!$xendit_paid) {
            $error = "Payment not yet confirmed by the payment gateway. Please wait a moment and refresh.";
        } else {
            // ---------------------------------------------------------------
            // All good — process the payment in a single transaction
            // ---------------------------------------------------------------
            $conn->begin_transaction();

            try {
                $wallet = new WalletManager($conn);

                // Determine amounts
                // labor_materials_cost = what the client is paying now (final payment portion)
                // total_final_cost     = full job cost (mobilization + labor/materials) — used for 4% commission
                $labor_cost       = floatval($booking['labor_materials_cost']);
                $total_final_cost = floatval($booking['total_final_cost']);

                if ($total_final_cost <= 0) {
                    $total_final_cost = floatval($booking['calculated_fee']) + $labor_cost;
                }

                // ── STEP 1: Release mobilization escrow to worker (if mobilization was paid via GCash) ──
                // When mobilization was paid via Xendit, the ₱calculated_fee was held in admin escrow.
                // Now that the job is done, release it to the worker.
                if (
                    $booking['payment_method']  === 'Xendit' &&
                    $booking['payment_status']   === 'Paid'  &&
                    intval($booking['is_escrow']) === 1
                ) {
                    $release = $wallet->releaseEscrowPayment(
                        $booking_id,
                        $booking['worker_id'],
                        floatval($booking['calculated_fee'])
                    );
                    if (!$release['success']) {
                        throw new Exception("Escrow release failed: " . $release['message']);
                    }
                    error_log("✅ Mobilization escrow released: ₱{$booking['calculated_fee']} → worker #{$booking['worker_id']}");
                }

                // ── STEP 2: Credit worker wallet with labor+materials amount ──
                // The client paid labor_materials_cost via GCash. Credit this to worker now.
                // Note: The 2.3% Xendit convenience fee was paid by the CLIENT on top of this amount.
                // It does NOT reduce what the worker receives — Xendit takes that fee from the client's side.
                if ($labor_cost > 0) {
                    $upd_wallet = $conn->prepare(
                        "UPDATE worker_profiles 
                         SET wallet_balance = wallet_balance + ?
                         WHERE user_id = ?"
                    );
                    $upd_wallet->bind_param("di", $labor_cost, $booking['worker_id']);
                    if (!$upd_wallet->execute()) {
                        throw new Exception("Failed to credit worker wallet: " . $conn->error);
                    }

                    // Log the credit transaction
                    $credit_desc = $conn->real_escape_string(
                        "GCash/Maya final payment received for booking #$booking_id"
                    );
                    $conn->query(
                        "INSERT INTO wallet_transactions
                             (user_id, user_type, transaction_type, amount, reference_id,
                              reference_type, description, balance_after, created_at)
                         SELECT {$booking['worker_id']}, 'worker', 'credit', $labor_cost,
                                $booking_id, 'final_payment', '$credit_desc', wallet_balance, NOW()
                         FROM worker_profiles
                         WHERE user_id = {$booking['worker_id']}"
                    );
                    error_log("✅ Worker #{$booking['worker_id']} credited ₱$labor_cost for booking #$booking_id");
                }

                // ── STEP 3: Deduct 4% admin commission from worker, credit admin ──
                // Based on total_final_cost (mobilization + labor/materials).
                // Worker wallet was just credited above so there's sufficient balance.
                // The 2.3% GCash convenience fee is NOT included here — it was paid by
                // the client directly to Xendit/GCash and is not part of our platform fee.
                $commission_result = ['success' => true, 'commission' => 0];
                if ($total_final_cost > 0) {
                    $commission_result = $wallet->processFinalPaymentCommission(
                        $booking_id,
                        $booking['worker_id'],
                        $total_final_cost,
                        'GCash'
                    );
                    if (!$commission_result['success']) {
                        // Log but don't abort — admin can reconcile manually
                        error_log("⚠️ Commission failed for booking #$booking_id: " . $commission_result['message']);
                    } else {
                        error_log("✅ 4% commission deducted: ₱{$commission_result['commission']}");
                    }
                }

                // ── STEP 4: Increment worker job count ──
                $conn->query(
                    "UPDATE worker_profiles 
                     SET jobs_completed = jobs_completed + 1 
                     WHERE user_id = {$booking['worker_id']}"
                );

                // ── STEP 5: Award Listo Points to worker ──
                // +5 points per completed job; every 50 points = ₱30 free booking credit
                $points_result = $wallet->awardListoPoints($booking['worker_id'], $booking_id);
                error_log("Listo Points result for booking #$booking_id: " . print_r($points_result, true));
                if (!$points_result['success']) {
                    // Non-fatal — log for admin review
                    error_log("⚠️ Listo Points award failed: " . $points_result['message']);
                }

                // ── STEP 6: Mark booking as Completed ──
                $conn->query(
                    "UPDATE bookings 
                     SET final_payment_status = 'paid',
                         final_payment_method = 'GCash',
                         status               = 'Completed',
                         updated_at           = NOW()
                     WHERE id = $booking_id"
                );

                // ── STEP 7: Notify worker ──
                $client_name = $booking['client_name'];
                $amount_fmt  = number_format($labor_cost, 2);
                $total_fmt   = number_format($total_final_cost, 2);

                $worker_notif_title = $points_result['milestone']
                    ? "🎉 Payment + FREE Credit Earned!"
                    : "💸 GCash Payment Received!";

                $worker_notif_body = "{$client_name} paid ₱{$amount_fmt} via GCash for booking #$booking_id. Job complete!";

                if ($points_result['success'] && $points_result['milestone']) {
                    $worker_notif_body .= " 🎉 You earned a FREE booking credit! ({$points_result['new_points']} Listo Points total)";
                } elseif ($points_result['success']) {
                    $worker_notif_body .= " ⭐ +" . LISTO_POINTS_PER_JOB . " Listo Points! ({$points_result['new_points']} total)";
                }

                // FCM push notification to worker
                if (!empty($booking['worker_token'])) {
                    try {
                        $fcm = new FCMv1();
                        $fcm->send(
                            $booking['worker_id'],
                            $worker_notif_title,
                            $worker_notif_body,
                            [
                                'type'                => 'final_payment_gcash',
                                'booking_id'          => (string)$booking_id,
                                'listo_points_awarded'=> $points_result['success'] ? LISTO_POINTS_PER_JOB : 0,
                                'listo_points_total'  => $points_result['new_points'] ?? intval($booking['worker_current_points']),
                                'listo_milestone'     => $points_result['milestone'] ? 'true' : 'false',
                            ]
                        );
                    } catch (Exception $e) {
                        error_log("FCM to worker failed: " . $e->getMessage());
                    }
                }

                // In-app notification for worker
                $escaped_worker_notif = $conn->real_escape_string($worker_notif_body);
                $notif_stmt = $conn->prepare(
                    "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                     VALUES (?, ?, '../worker/wallet.php', 0, NOW())"
                );
                $notif_stmt->bind_param("is", $booking['worker_id'], $escaped_worker_notif);
                $notif_stmt->execute();

                // In-app notification for client
                $client_notif    = "✅ Payment of ₱{$amount_fmt} sent to {$booking['worker_name']}. Job #$booking_id complete!";
                $client_notif_st = $conn->prepare(
                    "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                     VALUES (?, ?, '../client/my_bookings.php', 0, NOW())"
                );
                $client_notif_st->bind_param("is", $booking['client_user_id'], $client_notif);
                $client_notif_st->execute();

                $conn->commit();
                error_log("✅ GCash final payment fully processed for booking #$booking_id. Commission: ₱{$commission_result['commission']}. Listo Points: +{$points_result['new_points']}");

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Processing error: " . $e->getMessage();
                error_log("❌ final_payment_success failed for booking #$booking_id: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Payment Complete | Abilisto</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,1&display=swap" rel="stylesheet"/>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-display { font-family: 'Space Grotesk', sans-serif; }
        @keyframes pop {
            0%   { transform: scale(0.5); opacity: 0; }
            70%  { transform: scale(1.1); }
            100% { transform: scale(1);   opacity: 1; }
        }
        .animate-pop { animation: pop 0.5s ease forwards; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeUp { animation: fadeUp 0.5s ease 0.3s both; }
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 to-teal-100 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md">

    <?php if ($error): ?>
    <!-- ── Error State ── -->
    <div class="bg-white rounded-3xl shadow-xl p-10 text-center">
        <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-outlined text-red-500 text-4xl">error</span>
        </div>
        <h1 class="font-display text-2xl font-bold text-slate-800 mb-3">Payment Not Confirmed</h1>
        <p class="text-slate-500 mb-8"><?php echo htmlspecialchars($error); ?></p>
        <a href="../client/my_bookings.php"
           class="inline-block px-8 py-3 bg-slate-800 text-white rounded-xl font-semibold hover:bg-slate-700 transition">
            Back to My Bookings
        </a>
    </div>

    <?php else: ?>
    <!-- ── Success State ── -->
    <div class="bg-white rounded-3xl shadow-xl overflow-hidden">
        <div class="h-2 bg-gradient-to-r from-emerald-400 to-teal-500"></div>

        <div class="p-10 text-center">
            <!-- Animated checkmark -->
            <div class="w-24 h-24 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6 animate-pop">
                <span class="material-symbols-outlined text-emerald-500 text-5xl">check_circle</span>
            </div>

            <div class="animate-fadeUp">
                <h1 class="font-display text-3xl font-bold text-slate-800 mb-2">Payment Successful!</h1>
                <p class="text-slate-400 text-sm uppercase tracking-widest mb-8">Xendit</p>

                <?php if ($booking): ?>
                <!-- Booking Summary -->
                <div class="bg-slate-50 rounded-2xl p-6 text-left space-y-4 mb-6">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Booking #</span>
                        <span class="font-semibold"><?php echo str_pad($booking_id, 8, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Worker</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($booking['worker_name']); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Labor &amp; Materials</span>
                        <span class="font-bold text-emerald-600 text-base">
                            ₱<?php echo number_format(floatval($booking['labor_materials_cost']), 2); ?>
                        </span>
                    </div>
                    <?php
                        // Show the convenience fee the client actually paid to Xendit
                        $convenience_fee = round(floatval($booking['labor_materials_cost']) * 0.023, 2);
                    ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Convenience Fee (2.3%)</span>
                        <span class="font-medium text-slate-600">₱<?php echo number_format($convenience_fee, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-sm border-t pt-3">
                        <span class="text-slate-500 font-semibold">Total Charged to You</span>
                        <span class="font-bold text-slate-800 text-base">
                            ₱<?php echo number_format(floatval($booking['labor_materials_cost']) + $convenience_fee, 2); ?>
                        </span>
                    </div>
                    <div class="flex justify-between text-sm border-t pt-3">
                        <span class="text-slate-500">Method</span>
                        <span class="font-semibold">Xendit</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Status</span>
                        <span class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold uppercase">Completed</span>
                    </div>
                </div>

                <!-- Listo Points Banner — milestone reached -->
                <?php if ($points_result['milestone']): ?>
                <div class="bg-gradient-to-r from-yellow-400 to-orange-400 rounded-2xl p-5 mb-6 text-white text-center">
                    <p class="text-3xl mb-1">🎉</p>
                    <p class="font-display font-bold text-lg">Your Worker Earned a FREE Credit!</p>
                    <p class="text-sm opacity-90 mt-1"><?php echo htmlspecialchars($points_result['message']); ?></p>
                </div>

                <!-- Listo Points Banner — regular points -->
                <?php elseif ($points_result['success']): ?>
                <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 mb-6 flex items-center gap-3 text-left">
                    <span class="text-2xl">⭐</span>
                    <div>
                        <p class="text-sm font-bold text-blue-700">Listo Points Awarded</p>
                        <p class="text-xs text-blue-500">
                            +<?php echo LISTO_POINTS_PER_JOB; ?> points to your worker
                            (<?php echo $points_result['new_points']; ?> total)
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>

                <p class="text-slate-500 text-sm mb-8">
                    <?php echo isset($already_done)
                        ? 'This payment was already processed. Your booking is complete.'
                        : 'Your payment has been received and the job is now marked as complete. The worker has been notified.';
                    ?>
                </p>

                <a href="../client/my_bookings.php"
                   class="block w-full py-4 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-xl font-bold text-lg shadow-lg shadow-emerald-500/20 hover:scale-[1.02] active:scale-[0.98] transition-all">
                    View My Bookings
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-slate-400 mt-6">Secured by Abilisto Gateway</p>
</div>

</body>
</html>