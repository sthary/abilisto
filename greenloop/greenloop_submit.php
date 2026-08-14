<?php
// ============================================================
// greenloop_submit.php — API endpoint to submit a scrap report
// Called via fetch() from greenloop_report.php
// Method: POST  |  Content-Type: application/json
// ============================================================

require_once __DIR__ . '/greenloop_db.php';
session_start();

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in first.']);
    exit;
}

$client_id = (int)$_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$booking_id         = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;
$item_id            = !empty($input['item_id']) ? (int)$input['item_id'] : null;
$item_name_custom   = trim($input['item_name_custom'] ?? '');
$quantity           = max(0.1, (float)($input['quantity'] ?? 1));
$unit               = trim($input['unit'] ?? 'piece');
$client_notes       = trim($input['client_notes'] ?? '');
$ai_assessment      = trim($input['ai_assessment'] ?? '');
$ai_accepted        = isset($input['ai_accepted']) ? (int)(bool)$input['ai_accepted'] : null;
$estimated_coins    = max(0, (float)($input['estimated_green_coins'] ?? 0));

// ── LOCATION FIELDS (PATCHED) ─────────────────────────────────
$pickup_lat  = isset($input['pickup_latitude'])  && $input['pickup_latitude']  !== null && $input['pickup_latitude']  !== ''
                 ? (float)$input['pickup_latitude']  : null;
$pickup_lng  = isset($input['pickup_longitude']) && $input['pickup_longitude'] !== null && $input['pickup_longitude'] !== ''
                 ? (float)$input['pickup_longitude'] : null;
$pickup_addr = isset($input['pickup_address'])   && $input['pickup_address']   !== ''
                 ? trim($input['pickup_address'])  : null;

// Validate — need at least one item identifier
if (!$item_id && strlen($item_name_custom) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Please select or describe an item.']);
    exit;
}

// Verify booking belongs to this client (if provided)
if ($booking_id) {
    $stmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND client_id = ? AND status = 'Completed'");
    $stmt->execute([$booking_id, $client_id]);
    if (!$stmt->fetch()) {
        $booking_id = null; // just nullify, don't block submission
    }
}

// Get unit/coins from item catalog if item_id provided
if ($item_id) {
    $stmt = $pdo->prepare("SELECT unit, green_coins_per_unit FROM greenloop_accepted_items WHERE id = ? AND is_active = 1");
    $stmt->execute([$item_id]);
    $catalog = $stmt->fetch();
    if ($catalog) {
        $unit = $catalog['unit'];
        $estimated_coins = round($catalog['green_coins_per_unit'] * $quantity, 2);
    }
}

// Insert the report (PATCHED with location columns)
$stmt = $pdo->prepare("
    INSERT INTO greenloop_reports
      (client_id, booking_id, item_id, item_name_custom, quantity, unit,
       ai_assessment, ai_accepted, estimated_green_coins, client_notes,
       pickup_latitude, pickup_longitude, pickup_address, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$stmt->execute([
    $client_id,
    $booking_id,
    $item_id ?: null,
    $item_name_custom ?: null,
    $quantity,
    $unit,
    $ai_assessment ?: null,
    $ai_accepted,
    $estimated_coins,
    $client_notes ?: null,
    $pickup_lat,
    $pickup_lng,
    $pickup_addr,
]);
$report_id = $pdo->lastInsertId();

// Send a notification (uses your existing notifications table)
try {
    $item_label = $item_name_custom ?: "Item #{$item_id}";
    $location_note = $pickup_addr ? " Pickup location saved." : "";
    $pdo->prepare("
        INSERT INTO notifications (user_id, message, link, is_read, created_at)
        VALUES (?, ?, 'greenloop/greenloop_wallet.php', 0, NOW())
    ")->execute([
        $client_id,
        "♻️ GreenLoop report submitted! Your scrap report #{$report_id} is pending review. You'll earn up to {$estimated_coins} Green Coins once collected & verified.{$location_note}"
    ]);
} catch (Exception $e) {
    // Non-fatal — notification failure shouldn't block the report
}

echo json_encode([
    'success'    => true,
    'report_id'  => (int)$report_id,
    'estimated_green_coins' => $estimated_coins,
    'message'    => "Report submitted! You'll earn up to {$estimated_coins} Green Coins once our team verifies your items.",
]);