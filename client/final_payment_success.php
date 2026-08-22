<?php
// client/final_payment_success.php
// Landing page after client successfully pays the FINAL payment via
// PayMongo (GCash/Maya/Card). This page:
//   1. Verifies the payment with PayMongo (fails CLOSED if unconfirmed)
//   2. Releases any escrowed mobilization fee + credits worker (idempotent)
//   3. Deducts the 4% commission + absorbs any voucher discount (idempotent)
//   4. Awards Listo Points to worker
//   5. Marks booking as Completed
//   6. Notifies the worker
//   7. Shows a success screen to the client
//
// Both this page and client/paymongo_webhook.php call the exact same
// WalletManager methods for this — whichever runs first for a given
// payment wins, the other is a safe no-op (see creditOnlineFinalPayment /
// processFinalPaymentCommission in wallet_manager.php).

session_start();
require_once '../db_connect.php';
require_once '../includes/init_lang.php';
require_once '../includes/functions/wallet_manager.php';
require_once '../includes/functions/paymongo_client.php';
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
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        $error = "Booking not found.";
    } elseif ($booking['final_payment_status'] === 'paid') {
        $already_done  = true;
        $points_result = ['success' => false, 'milestone' => false, 'new_points' => intval($booking['worker_current_points']), 'message' => ''];
        error_log("Booking #$booking_id already marked paid — skipping re-processing.");
    } else {
        // ---------------------------------------------------------------
        // Verify with PayMongo that the Checkout Session is actually PAID.
        // Fail CLOSED — this URL alone proves nothing (anyone can visit it
        // without paying), so an unconfirmed/erroring check must credit
        // nothing rather than assume success.
        // ---------------------------------------------------------------
        $link_id      = $booking['final_payment_xendit_id'] ?? null; // column name predates the PayMongo migration
        $gateway_paid = false;

        if ($link_id) {
            $verify = paymongoRetrieveCheckoutSession($link_id);
            $gateway_paid = $verify['success'] && $verify['paid'];
            if (!$gateway_paid) {
                error_log("Final payment verification failed for booking #$booking_id, link $link_id: " . ($verify['message'] ?? ''));
            }
        } else {
            error_log("⚠️ No PayMongo link id found for booking #$booking_id — cannot verify");
        }

        if (!$gateway_paid) {
            $error = "Payment not yet confirmed by the payment gateway. Please wait a moment and refresh.";
        } else {
            $labor_cost       = floatval($booking['labor_materials_cost']);
            $total_final_cost = floatval($booking['total_final_cost']);
            if ($total_final_cost <= 0) {
                $total_final_cost = floatval($booking['calculated_fee']) + $labor_cost;
            }

            $mobilization_released = (
                $booking['payment_method']  === 'PayMongo' &&
                $booking['payment_status']   === 'Paid'    &&
                intval($booking['is_escrow']) === 1
            );
            $mobilization_amount = $mobilization_released ? floatval($booking['calculated_fee']) : 0;
            $step2_credit         = $mobilization_released ? $labor_cost : $total_final_cost;

            $credit_result = $wallet_credit_result = null;
            $wallet = new WalletManager($conn);

            $credit_result = $wallet->creditOnlineFinalPayment($booking_id, $booking['worker_id'], $mobilization_amount, $step2_credit);
            if (!$credit_result['success']) {
                $error = "Processing error: " . $credit_result['message'];
                error_log("❌ creditOnlineFinalPayment failed for booking #$booking_id: " . $credit_result['message']);
            } else {
                $commission_result = ['success' => true, 'commission' => 0];
                if ($total_final_cost > 0) {
                    $commission_result = $wallet->processFinalPaymentCommission($booking_id, $booking['worker_id'], $total_final_cost, 'PayMongo');
                    if (!$commission_result['success']) {
                        error_log("⚠️ Commission failed for booking #$booking_id: " . $commission_result['message']);
                    }
                }

                $jobs_stmt = $conn->prepare("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?");
                $jobs_stmt->execute([$booking['worker_id']]);

                $points_result = $wallet->awardListoPoints($booking['worker_id'], $booking_id);
                if (!$points_result['success']) {
                    error_log("⚠️ Listo Points award failed: " . $points_result['message']);
                }

                $complete_stmt = $conn->prepare(
                    "UPDATE bookings
                     SET final_payment_status = 'paid',
                         final_payment_method = 'PayMongo',
                         status               = 'Completed',
                         updated_at           = NOW()
                     WHERE id = ? AND final_payment_status != 'paid'"
                );
                $complete_stmt->execute([$booking_id]);

                $client_name = $booking['client_name'];
                $amount_fmt  = number_format($step2_credit, 2);

                $worker_notif_title = $points_result['milestone']
                    ? "🎉 Payment + FREE Credit Earned!"
                    : "💸 PayMongo Payment Received!";
                $worker_notif_body = "{$client_name} paid ₱{$amount_fmt} via PayMongo for booking #$booking_id. Job complete!";
                if ($points_result['success'] && $points_result['milestone']) {
                    $worker_notif_body .= " 🎉 You earned a FREE booking credit! ({$points_result['new_points']} Listo Points total)";
                } elseif ($points_result['success']) {
                    $worker_notif_body .= " ⭐ +" . LISTO_POINTS_PER_JOB . " Listo Points! ({$points_result['new_points']} total)";
                }

                if (!empty($booking['worker_token'])) {
                    try {
                        $fcm = new FCMv1();
                        $fcm->send(
                            $booking['worker_id'], $worker_notif_title, $worker_notif_body,
                            [
                                'type' => 'final_payment_paymongo',
                                'booking_id' => (string)$booking_id,
                                'listo_points_awarded' => $points_result['success'] ? LISTO_POINTS_PER_JOB : 0,
                                'listo_points_total' => $points_result['new_points'] ?? intval($booking['worker_current_points']),
                                'listo_milestone' => $points_result['milestone'] ? 'true' : 'false',
                            ]
                        );
                    } catch (Exception $e) {
                        error_log("FCM to worker failed: " . $e->getMessage());
                    }
                }

                $notif_stmt = $conn->prepare(
                    "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                     VALUES (?, ?, '../worker/wallet.php', 0, NOW())"
                );
                $notif_stmt->execute([$booking['worker_id'], $worker_notif_body]);

                $client_notif    = "✅ Payment of ₱{$amount_fmt} sent to {$booking['worker_name']}. Job #$booking_id complete!";
                $client_notif_st = $conn->prepare(
                    "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                     VALUES (?, ?, '../client/my_bookings.php', 0, NOW())"
                );
                $client_notif_st->execute([$booking['client_user_id'], $client_notif]);

                error_log("✅ PayMongo final payment fully processed for booking #$booking_id. Commission: ₱{$commission_result['commission']}. Listo Points: +{$points_result['new_points']}");
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
            <div class="w-24 h-24 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6 animate-pop">
                <span class="material-symbols-outlined text-emerald-500 text-5xl">check_circle</span>
            </div>

            <div class="animate-fadeUp">
                <h1 class="font-display text-3xl font-bold text-slate-800 mb-2">Payment Successful!</h1>
                <p class="text-slate-400 text-sm uppercase tracking-widest mb-8">PayMongo</p>

                <?php if ($booking): ?>
                <div class="bg-slate-50 rounded-2xl p-6 text-left space-y-4 mb-6">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Booking #</span>
                        <span class="font-semibold"><?php echo str_pad($booking_id, 8, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Worker</span>
                        <span class="font-semibold"><?php echo htmlspecialchars($booking['worker_name']); ?></span>
                    </div>
                    <?php
                        $display_labor    = floatval($booking['labor_materials_cost']);
                        $display_total    = floatval($booking['total_final_cost']);
                        $display_mob      = max(0, round($display_total - $display_labor, 2));
                        $mobilization_bundled = $display_mob > 0.01;
                        $convenience_fee  = round($display_total * 0.023, 2);
                    ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Labor &amp; Materials</span>
                        <span class="font-bold text-emerald-600 text-base">
                            ₱<?php echo number_format($display_labor, 2); ?>
                        </span>
                    </div>
                    <?php if ($mobilization_bundled): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Mobilization Fee <span class="text-xs">(included, not paid separately)</span></span>
                        <span class="font-bold text-emerald-600 text-base">
                            ₱<?php echo number_format($display_mob, 2); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Convenience Fee (est. 2.3%)</span>
                        <span class="font-medium text-slate-600">₱<?php echo number_format($convenience_fee, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-sm border-t pt-3">
                        <span class="text-slate-500 font-semibold">Total Charged to You</span>
                        <span class="font-bold text-slate-800 text-base">
                            ₱<?php echo number_format($display_total + $convenience_fee, 2); ?>
                        </span>
                    </div>
                    <div class="flex justify-between text-sm border-t pt-3">
                        <span class="text-slate-500">Method</span>
                        <span class="font-semibold">PayMongo</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-500">Status</span>
                        <span class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold uppercase">Completed</span>
                    </div>
                </div>

                <?php if ($points_result['milestone']): ?>
                <div class="bg-gradient-to-r from-yellow-400 to-orange-400 rounded-2xl p-5 mb-6 text-white text-center">
                    <p class="text-3xl mb-1">🎉</p>
                    <p class="font-display font-bold text-lg">Your Worker Earned a FREE Credit!</p>
                    <p class="text-sm opacity-90 mt-1"><?php echo htmlspecialchars($points_result['message']); ?></p>
                </div>
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