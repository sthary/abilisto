<?php
// api/poll_pending.php
// Returns updates for quick-match bookings: new, expired, cancelled, or accepted

ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

include '../db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$worker_id = (int)$_SESSION['user_id'];

// ── Decode arrays sent by the dashboard JS ──────────────────────────────────
function decodeIds(string $key): array {
    if (empty($_POST[$key])) return [];
    $decoded = json_decode($_POST[$key], true);
    return is_array($decoded) ? array_map('intval', $decoded) : [];
}

$known_ids     = decodeIds('known_ids');
$expired_ids   = decodeIds('expired_ids');
$cancelled_ids = decodeIds('cancelled_ids');
$accepted_ids  = decodeIds('accepted_ids');

$updates = [
    'expired'   => [],
    'cancelled' => [],
    'accepted'  => [],
    'new'       => [],
];

// Helper: build a safe IN() clause
// Returns ['placeholders' => '?,?,...', 'types' => 'iii...', 'params' => [...]]
function buildIn(array $ids, string $leadType = ''): array {
    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $types  = $leadType . str_repeat('i', count($ids));
    return ['placeholders' => $ph, 'types' => $types];
}

// ============================================================
// PART 1 — EXPIRED broadcasts
//   Catches: broadcast timer ran out while still in 'searching'
// ============================================================
if (!empty($known_ids)) {
    ['placeholders' => $ph, 'types' => $types] = buildIn($known_ids, 'i');
    $params = array_merge([$worker_id], $known_ids);

    $sql = "
        SELECT b.id AS booking_id, jb.id AS broadcast_id
        FROM   bookings b
        JOIN   job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE  b.worker_id = ?
          AND  b.id IN ($ph)
          AND  b.status  = 'Pending'
          AND  (
               jb.expires_at <= NOW()            -- timer ran out
            OR jb.status     = 'expired'         -- explicitly marked expired
          )
          AND  jb.status NOT IN ('cancelled','accepted')  -- not already handled
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!in_array((int)$row['booking_id'], $expired_ids)) {
                $updates['expired'][] = [
                    'booking_id'   => (int)$row['booking_id'],
                    'broadcast_id' => (int)$row['broadcast_id'],
                    'reason'       => 'expired',
                ];
            }
        }
        $stmt->close();
    }
}

// ============================================================
// PART 2 — CANCELLED bookings / broadcasts
//   Catches BOTH:
//   (a) Client cancelled the broadcast  → jb.status = 'cancelled'
//   (b) Client cancelled the booking    → b.status  = 'Cancelled'
// ============================================================
if (!empty($known_ids)) {
    ['placeholders' => $ph, 'types' => $types] = buildIn($known_ids, 'i');
    $params = array_merge([$worker_id], $known_ids);

    $sql = "
        SELECT b.id AS booking_id,
               COALESCE(jb.id, 0) AS broadcast_id,
               CASE
                 WHEN b.status = 'Cancelled'    THEN 'booking_cancelled'
                 WHEN jb.status = 'cancelled'   THEN 'broadcast_cancelled'
                 ELSE 'cancelled'
               END AS cancel_reason
        FROM   bookings b
        LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE  b.worker_id = ?
          AND  b.id IN ($ph)
          AND  (
               b.status  = 'Cancelled'       -- client cancelled the booking itself
            OR jb.status = 'cancelled'        -- client cancelled the broadcast
          )
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!in_array((int)$row['booking_id'], $cancelled_ids)) {
                $updates['cancelled'][] = [
                    'booking_id'   => (int)$row['booking_id'],
                    'broadcast_id' => (int)$row['broadcast_id'],
                    'reason'       => $row['cancel_reason'],
                ];
            }
        }
        $stmt->close();
    }
}

// ============================================================
// PART 3 — ACCEPTED by another worker
//   Catches: broadcast won by someone else
// ============================================================
if (!empty($known_ids)) {
    ['placeholders' => $ph, 'types' => $types] = buildIn($known_ids, 'i');
    $params = array_merge([$worker_id], $known_ids);

    $sql = "
        SELECT b.id AS booking_id,
               jb.id AS broadcast_id,
               jb.accepted_worker_id
        FROM   bookings b
        JOIN   job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE  b.worker_id = ?
          AND  b.id IN ($ph)
          AND  b.status  = 'Pending'
          AND  jb.status = 'accepted'
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!in_array((int)$row['booking_id'], $accepted_ids)) {
                $accepted_by = (int)$row['accepted_worker_id'];
                $updates['accepted'][] = [
                    'booking_id'       => (int)$row['booking_id'],
                    'broadcast_id'     => (int)$row['broadcast_id'],
                    'accepted_by_me'   => ($accepted_by === $worker_id),
                    'accepted_by_other'=> ($accepted_by !== $worker_id),
                    'reason'           => 'accepted',
                ];
            }
        }
        $stmt->close();
    }
}

// ============================================================
// PART 4 — NEW bookings not yet visible on screen
// ============================================================
if (!empty($known_ids)) {
    ['placeholders' => $ph, 'types' => $types] = buildIn($known_ids, 'i');
    $exclude = "AND b.id NOT IN ($ph)";
    $params  = array_merge([$worker_id], $known_ids);
} else {
    $exclude = '';
    $types   = 'i';
    $params  = [$worker_id];
}

$sql = "
    SELECT
        b.id,
        b.service_type,
        b.problem_desc,
        b.calculated_fee,
        b.urgency_level,
        b.payment_method,
        b.payment_status,
        b.booking_date,
        b.location_address,
        b.latitude,
        b.longitude,
        u.full_name,
        u.address,
        u.phone,
        u.profile_pic,
        jb.id                                          AS broadcast_id,
        jb.expires_at                                  AS broadcast_expires_at,
        jb.status                                      AS broadcast_status,
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at)    AS seconds_remaining,
        1                                              AS is_active_quick_match
    FROM   bookings b
    JOIN   users u   ON b.client_id     = u.id
    JOIN   job_broadcasts jb ON b.broadcast_id = jb.id
    WHERE  b.worker_id = ?
      AND  b.status  = 'Pending'
      AND  jb.status = 'searching'
      AND  jb.expires_at > NOW()
      $exclude
    ORDER BY
        CASE b.urgency_level
            WHEN 'Emergency' THEN 1
            WHEN 'High'      THEN 2
            ELSE                  3
        END,
        b.created_at DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $new_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($new_rows)) {
        // Pre-load the card renderer without capturing output
        ob_start(); include_once '../worker/partials/booking_card.php'; ob_end_clean();

        foreach ($new_rows as $booking) {
            ob_start();
            renderBookingCard($booking, 'pending');
            $html = ob_get_clean();

            $updates['new'][] = [
                'id'               => (int)$booking['id'],
                'html'             => $html,
                'urgency'          => $booking['urgency_level'],
                'seconds_remaining'=> (int)$booking['seconds_remaining'],
                'broadcast_id'     => (int)$booking['broadcast_id'],
                'client_name'      => $booking['full_name'],
            ];
        }
    }
}

echo json_encode([
    'success'   => true,
    'updates'   => $updates,
    'timestamp' => time(),
]);