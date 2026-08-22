<?php
// client/paymongo_webhook.php
// PayMongo webhook endpoint. Handles:
//   - Mobilization payments (metadata.type === 'mobilization') → hold in escrow
//   - Final payments        (metadata.type === 'final_payment') → release escrow
//     if applicable, credit worker, deduct 4% commission, absorb any voucher
//   - Wallet top-ups        (metadata.type === 'wallet_topup')  → credit worker wallet
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

$booking_id = intval($metadata['booking_id'] ?? 0);
$topup_id   = intval($metadata['topup_id'] ?? 0);
$type       = $metadata['type'] ?? '';

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

    $result = $wallet->holdEscrowPayment($booking_id, $booking['worker_id'], $amount);

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

    $mobilization_amount = $mobilization_released ? floatval($booking['calculated_fee']) : 0;
    $credit_amount        = $mobilization_released ? $labor_cost : $total_final_cost;

    $credit_result = $wallet->creditOnlineFinalPayment($booking_id, $booking['worker_id'], $mobilization_amount, $credit_amount);
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

    $result = $wallet->processTopUp($topup['worker_id'], $topup['amount'], $topup_id, $resource['id'] ?? null);

    if ($result['success']) {
        sendNotification(
            $conn, $topup['worker_id'],
            "💰 Wallet Top-Up Successful!\n\n₱" . number_format($topup['amount'], 2) . " has been added to your wallet.",
            "../worker/wallet.php"
        );
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
