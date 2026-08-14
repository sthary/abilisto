<?php
// webhooks/xendit_webhook.php  (or wherever your Xendit callback URL points)
// Handles ALL Xendit invoice events:
//   - Mobilization (INV-...) → hold in escrow
//   - Final payment  (FINAL-...) → process 4% commission, complete booking
//   - Expired / Failed        → mark booking failed

require_once '../db.php';
require_once '../includes/functions/wallet_functions.php';

// ── Read & validate payload ────────────────────────────────────────────────
$raw_input = file_get_contents('php://input');
$data      = json_decode($raw_input, true);

error_log("=== Xendit Webhook Received ===");
error_log($raw_input);

if (!$data) {
    http_response_code(400);
    exit('Invalid payload');
}

// ── (Uncomment in production) Verify Xendit callback token ────────────────
// $token = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
// if ($token !== 'YOUR_XENDIT_WEBHOOK_TOKEN') {
//     http_response_code(401);
//     exit('Unauthorized');
// }

$wallet      = new WalletManager($conn);
$event       = $data['event']       ?? '';
$external_id = $data['external_id'] ?? '';
$amount      = floatval($data['amount'] ?? 0);

// ── Route by external_id prefix so we never mix mobilization & final ───────
$is_final        = strpos($external_id, 'FINAL-') === 0;   // FINAL-timestamp-bookingId-rand
$is_mobilization = strpos($external_id, 'INV-')   === 0;   // INV-timestamp-bookingId-rand

// Helper: extract booking_id from either format (PREFIX-timestamp-bookingId[-rand])
function extractBookingId(string $external_id): int {
    $parts = explode('-', $external_id);
    return intval($parts[2] ?? 0);
}

// ── Main event switch ──────────────────────────────────────────────────────
switch ($event) {

    // ── PAID ────────────────────────────────────────────────────────────────
    case 'invoice.paid':

        $booking_id = extractBookingId($external_id);
        if (!$booking_id) {
            error_log("Could not extract booking_id from external_id: $external_id");
            http_response_code(200);
            exit('OK - no booking id');
        }

        // Fetch booking
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();

        if (!$booking) {
            error_log("Booking #$booking_id not found for external_id: $external_id");
            http_response_code(200);
            exit('OK - booking not found');
        }

        // ── FINAL PAYMENT ────────────────────────────────────────────────
        if ($is_final) {
            error_log("Processing FINAL payment for booking #$booking_id");

            // Guard: already processed?
            if ($booking['final_payment_status'] === 'paid') {
                error_log("Final payment already processed for booking #$booking_id — skipping");
                http_response_code(200);
                exit('OK - already processed');
            }

            $total_final_cost = floatval($booking['total_final_cost']);
            if ($total_final_cost <= 0) {
                $total_final_cost = floatval($booking['labor_materials_cost'])
                                  + floatval($booking['calculated_fee']);
            }

            // Deduct 4% commission from worker, credit admin
            $commission_result = ['success' => true, 'commission' => 0];
            if ($total_final_cost > 0) {
                $commission_result = $wallet->processFinalPaymentCommission(
                    $booking_id,
                    $booking['worker_id'],
                    $total_final_cost,
                    'GCash'
                );
                if (!$commission_result['success']) {
                    error_log("⚠️ Commission failed for booking #$booking_id: " . $commission_result['message']);
                }
            }

            // Mark booking completed
            $conn->query("UPDATE bookings 
                          SET final_payment_status = 'paid',
                              final_payment_method = 'GCash',
                              status               = 'Completed',
                              updated_at           = NOW()
                          WHERE id = $booking_id");

            // Increment jobs_completed on worker profile
            $conn->query("UPDATE worker_profiles 
                          SET jobs_completed = jobs_completed + 1 
                          WHERE user_id = {$booking['worker_id']}");

            // Notify worker
            $worker_gets = $total_final_cost - $commission_result['commission'];
            sendNotification(
                $conn,
                $booking['worker_id'],
                "💸 Final GCash payment received! ₱" . number_format($worker_gets, 2) . " credited. Booking #$booking_id complete.",
                "../worker/wallet.php"
            );

            // Notify client
            sendNotification(
                $conn,
                $booking['client_id'],
                "✅ Payment successful! Job #$booking_id is now complete. Thank you!",
                "../client/my_bookings.php"
            );

            error_log("✅ Final payment fully processed for booking #$booking_id. Commission: ₱{$commission_result['commission']}");

        // ── MOBILIZATION / ESCROW ────────────────────────────────────────
        } elseif ($is_mobilization) {
            error_log("Processing MOBILIZATION escrow for booking #$booking_id");

            // Guard: already in escrow?
            if (!empty($booking['is_escrow'])) {
                error_log("Already in escrow for booking #$booking_id — skipping");
                http_response_code(200);
                exit('OK - already escrowed');
            }

            $result = $wallet->holdEscrowPayment($booking_id, $booking['worker_id'], $amount);

            if ($result['success']) {
                sendNotification(
                    $conn,
                    $booking['worker_id'],
                    "💰 New PAID Booking! ₱" . number_format($amount, 2) . " is held in escrow. Accept to get started.",
                    "../worker/view_booking.php?id=$booking_id"
                );
                error_log("✅ Escrow held for booking #$booking_id");
            } else {
                error_log("❌ Escrow failed for booking #$booking_id: " . $result['message']);
            }

        } else {
            error_log("Unknown external_id format, cannot route: $external_id");
        }

        break;

    // ── EXPIRED / FAILED ────────────────────────────────────────────────────
    case 'invoice.expired':
    case 'invoice.failed':

        $booking_id = extractBookingId($external_id);
        if (!$booking_id) break;

        if ($is_final) {
            // Final payment expired — just flag it, don't touch wallets
            $conn->query("UPDATE bookings 
                          SET final_payment_status = 'failed', updated_at = NOW() 
                          WHERE id = $booking_id AND final_payment_status != 'paid'");
            error_log("Final payment expired/failed for booking #$booking_id");

        } else {
            // Mobilization expired — mark booking failed
            $conn->query("UPDATE bookings 
                          SET payment_status = 'Failed', updated_at = NOW() 
                          WHERE id = $booking_id");

            // Fetch client_id from DB (the original code used $booking_id['client_id'] which is wrong)
            $res = $conn->query("SELECT client_id FROM bookings WHERE id = $booking_id");
            if ($res && $row = $res->fetch_assoc()) {
                sendNotification(
                    $conn,
                    $row['client_id'],
                    "❌ Your payment expired or failed. Please try booking again.",
                    "../client/booking.php"
                );
            }

            error_log("Mobilization payment expired/failed for booking #$booking_id");
        }

        break;

    default:
        error_log("Unhandled Xendit event: $event");
        break;
}

// Always return 200 so Xendit doesn't keep retrying indefinitely
http_response_code(200);
echo 'OK';
?>