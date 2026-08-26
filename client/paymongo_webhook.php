<?php
// client/paymongo_webhook.php
// PayMongo webhook endpoint. Handles:
//   - payment.paid, mobilization  (metadata.type === 'mobilization')  → hold in escrow
//   - payment.paid, final payment (metadata.type === 'final_payment') → release escrow
//     if applicable, credit worker, deduct 4% commission, absorb any voucher
//   - payment.paid, top-up        (metadata.type === 'wallet_topup')  → credit worker wallet
//   - payment.failed (any type)   → mark the record Failed, clear the dead
//     checkout link so a retry generates a fresh session, notify the client/worker
//
// Register this URL in the PayMongo Dashboard, subscribed to payment.paid
// and payment.failed, and put the webhook's signing secret in
// PAYMONGO_WEBHOOK_SECRET (.env).
//
// Every branch below calls the SAME idempotent WalletManager methods the
// browser-redirect landing pages use, so whichever fires first for a given
// payment wins and the other is a safe no-op — this endpoint does not need
// to "own" processing exclusively.

require_once '../db_connect.php';
require_once '../includes/functions/wallet_manager.php';
require_once '../includes/functions/paymongo_client.php';
require_once '../config/constants.php';

$raw_input = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$webhook_secret = getenv('PAYMONGO_WEBHOOK_SECRET');

error_log("=== PayMongo Webhook Received ===");

if (!$webhook_secret) {
    error_log("paymongo_webhook: PAYMONGO_WEBHOOK_SECRET not configured — rejecting");
    http_response_code(500);
    exit('Webhook not configured');
}

$event = paymongoVerifyWebhookSignature($raw_input, $sig_header, $webhook_secret);
if (!$event) {
    http_response_code(401);
    exit('Invalid signature');
}

error_log($raw_input);

$wallet = new WalletManager($conn);

// Event shape (per the official SDK's Event entity): the actual resource
// (a Payment) lives at data.attributes.data — its own .attributes carries
// amount/status/metadata.
$event_type = $event['data']['attributes']['type'] ?? '';
$resource   = $event['data']['attributes']['data'] ?? null;
$attrs      = $resource['attributes'] ?? [];
$metadata   = $attrs['metadata'] ?? [];
$amount     = isset($attrs['amount']) ? floatval($attrs['amount']) / 100 : 0; // centavos -> pesos
// PayMongo deducts its own processing fee before depositing to us — these
// are both present directly on the Payment object's own attributes.
$gateway_fee = isset($attrs['fee']) ? floatval($attrs['fee']) / 100 : 0;
$net_amount  = isset($attrs['net_amount']) ? floatval($attrs['net_amount']) / 100 : ($amount - $gateway_fee);

$booking_id = intval($metadata['booking_id'] ?? 0);
$topup_id   = intval($metadata['topup_id'] ?? 0);
$type       = $metadata['type'] ?? '';

// ── FAILED payment: mark the record Failed and clear the dead checkout
// link so a retry (process_payment_paymongo.php / generate_receipt.php)
// generates a fresh session instead of resending the client to a link
// that can no longer be paid. Nothing was ever credited for a failed
// payment, so there's no money to unwind here — only status/UX to fix.
if ($event_type === 'payment.failed') {

    if ($type === 'mobilization' && $booking_id) {
        $conn->prepare("UPDATE bookings
                      SET payment_status = 'Failed', transaction_id = NULL, checkout_url = NULL
                      WHERE id = ? AND payment_status != 'Paid'")
             ->execute([$booking_id]);

        $res = $conn->prepare("SELECT client_id FROM bookings WHERE id = ?");
        $res->execute([$booking_id]);
        if ($row = $res->fetch()) {
            sendNotification($conn, $row['client_id'],
                "❌ Your payment failed. Please try booking again.",
                "../client/booking.php"
            );
        }
        error_log("Mobilization payment failed for booking #$booking_id (webhook)");

    } elseif ($type === 'final_payment' && $booking_id) {
        $conn->prepare("UPDATE bookings
                      SET final_payment_status = 'failed', final_payment_xendit_id = NULL, final_payment_qr = NULL
                      WHERE id = ? AND final_payment_status != 'paid'")
             ->execute([$booking_id]);

        $res = $conn->prepare("SELECT worker_id FROM bookings WHERE id = ?");
        $res->execute([$booking_id]);
        if ($row = $res->fetch()) {
            sendNotification($conn, $row['worker_id'],
                "❌ Client's final payment failed for booking #$booking_id. They'll need to retry or pay cash.",
                "../worker/dashboard.php"
            );
        }
        error_log("Final payment failed for booking #$booking_id (webhook)");

    } elseif ($type === 'wallet_topup' && $topup_id) {
        $conn->prepare("UPDATE top_ups SET status = 'failed' WHERE id = ? AND status != 'completed'")
             ->execute([$topup_id]);
        error_log("Top-up #$topup_id failed (webhook)");

    } else {
        error_log("paymongo_webhook: payment.failed with unrecognized metadata — booking_id=$booking_id topup_id=$topup_id type=$type");
    }

    http_response_code(200);
    exit('OK - failure recorded');
}

if ($event_type !== 'payment.paid') {
    error_log("paymongo_webhook: ignoring event type '$event_type'");
    http_response_code(200);
    exit('OK - ignored');
}

if ($type === 'mobilization' && $booking_id) {

    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        error_log("paymongo_webhook: booking #$booking_id not found");
        http_response_code(200);
        exit('OK - booking not found');
    }

    $result = $wallet->holdEscrowPayment($booking_id, $booking['worker_id'], $amount, $gateway_fee);

    if ($result['success']) {
        sendNotification(
            $conn, $booking['worker_id'],
            "💰 New PAID Booking! ₱" . number_format($amount, 2) . " is held in escrow. Accept to get started.",
            "../worker/dashboard.php"
        );
        error_log("✅ Escrow held for booking #$booking_id (webhook)");
    } else {
        error_log("❌ Escrow hold failed for booking #$booking_id: " . $result['message']);
    }

} elseif ($type === 'final_payment' && $booking_id) {

    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        error_log("paymongo_webhook: booking #$booking_id not found");
        http_response_code(200);
        exit('OK - booking not found');
    }

    $labor_cost       = floatval($booking['labor_materials_cost']);
    $total_final_cost = floatval($booking['total_final_cost']);
    if ($total_final_cost <= 0) {
        $total_final_cost = floatval($booking['calculated_fee']) + $labor_cost;
    }

    $mobilization_released = (
        $booking['payment_method'] === 'PayMongo' &&
        $booking['payment_status']  === 'Paid'    &&
        intval($booking['is_escrow']) === 1
    );

    // Release net of the convenience fee — that portion covers PayMongo's
    // real gateway cut and stays with the platform, matching every other
    // escrow-release call site.
    $mobilization_amount = $mobilization_released
        ? floatval($booking['calculated_fee']) - floatval($booking['convenience_fee'] ?? 0)
        : 0;
    $credit_amount        = $mobilization_released ? $labor_cost : $total_final_cost;

    $credit_result = $wallet->creditOnlineFinalPayment($booking_id, $booking['worker_id'], $mobilization_amount, $credit_amount, $gateway_fee);
    if (!$credit_result['success']) {
        error_log("⚠️ creditOnlineFinalPayment failed for booking #$booking_id: " . $credit_result['message']);
    }

    $commission_result = ['success' => true, 'commission' => 0];
    if ($total_final_cost > 0) {
        $commission_result = $wallet->processFinalPaymentCommission($booking_id, $booking['worker_id'], $total_final_cost, 'PayMongo');
        if (!$commission_result['success']) {
            error_log("⚠️ Commission failed for booking #$booking_id: " . $commission_result['message']);
        }
    }

    $conn->prepare("UPDATE bookings
                  SET final_payment_status = 'paid',
                      final_payment_method = 'PayMongo',
                      status               = 'Completed',
                      updated_at           = NOW()
                  WHERE id = ? AND final_payment_status != 'paid'")
         ->execute([$booking_id]);

    $conn->prepare("UPDATE worker_profiles SET jobs_completed = jobs_completed + 1 WHERE user_id = ?")
         ->execute([$booking['worker_id']]);

    $wallet->awardListoPoints($booking['worker_id'], $booking_id);

    $worker_gets = $credit_amount - $commission_result['commission'];
    sendNotification(
        $conn, $booking['worker_id'],
        "💸 Final PayMongo payment received! ₱" . number_format($worker_gets, 2) . " credited. Booking #$booking_id complete.",
        "../worker/wallet.php"
    );
    sendNotification(
        $conn, $booking['client_id'],
        "✅ Payment successful! Job #$booking_id is now complete. Thank you!",
        "../client/my_bookings.php"
    );

    error_log("✅ Final payment processed for booking #$booking_id (webhook). Commission: ₱{$commission_result['commission']}");

} elseif ($type === 'wallet_topup' && $topup_id) {

    $stmt = $conn->prepare("SELECT * FROM top_ups WHERE id = ?");
    $stmt->execute([$topup_id]);
    $topup = $stmt->fetch();

    if (!$topup) {
        error_log("paymongo_webhook: top_up #$topup_id not found");
        http_response_code(200);
        exit('OK - topup not found');
    }

    // Top-up is the worker's own money going into their own wallet, so
    // (unlike mobilization/final payment) they receive the NET amount —
    // same convention as topup_success.php's redirect-path handling.
    $topup_net = $net_amount > 0 ? $net_amount : floatval($topup['amount']);
    $result = $wallet->processTopUp($topup['worker_id'], $topup_net, $topup_id, $resource['id'] ?? null);

    if ($result['success']) {
        $notif_msg = $gateway_fee > 0
            ? "💰 Wallet Top-Up Successful!\n\n₱" . number_format($topup_net, 2) . " has been added to your wallet (₱" . number_format($gateway_fee, 2) . " PayMongo processing fee deducted from your ₱" . number_format($topup['amount'], 2) . " top-up)."
            : "💰 Wallet Top-Up Successful!\n\n₱" . number_format($topup_net, 2) . " has been added to your wallet.";
        sendNotification($conn, $topup['worker_id'], $notif_msg, "../worker/wallet.php");
        error_log("✅ Top-up #$topup_id processed (webhook)");
    } else {
        error_log("❌ Top-up #$topup_id failed: " . $result['message']);
    }

} else {
    error_log("paymongo_webhook: unrecognized metadata type '$type' / missing ids — booking_id=$booking_id topup_id=$topup_id");
}

// Always return 200 so PayMongo doesn't keep retrying indefinitely.
http_response_code(200);
echo 'OK';
