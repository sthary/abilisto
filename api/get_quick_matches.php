<?php
// /abilisto/api/get_quick_matches.php
// ─────────────────────────────────────────────────────────────
// Polled every 4 seconds by booking-actions.js.
// Returns all active quick-match bookings assigned to the
// currently logged-in worker whose broadcast is still 'searching'
// and has NOT expired yet.
//
// Response shape:
// {
//   "success": true,
//   "matches": [
//     {
//       "booking_id":        123,
//       "broadcast_id":      45,
//       "seconds_remaining": 87,
//       "client_name":       "Maria Santos",
//       "service_type":      "Plumbing",
//       "address":           "Barangay X, Hinatuan",
//       "booking_date":      "June 10, 2025",
//       "calculated_fee":    "350.00"
//     }
//   ]
// }
// ─────────────────────────────────────────────────────────────

// ── Bootstrap ──────────────────────────────────────────────
require_once __DIR__ . '/../db_connect.php';   // starts session, gives $pdo / $conn

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/functions/feature_flags.php';
if (!isFeatureEnabled($conn, 'feature_quickmatch_enabled')) {
    echo json_encode(['success' => true, 'matches' => []]);
    exit();
}
header('X-Content-Type-Options: nosniff');

// ── Auth guard ─────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'worker') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only accept GET (or HEAD)
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$worker_id = (int) $_SESSION['user_id'];

// ── Query ───────────────────────────────────────────────────
// Mirrors the WHERE conditions in dashboard.php's $pending_sql,
// but restricted to quick-match bookings only and returns just
// the fields the card renderer needs.
// ── DEBUG: return raw broadcast info to diagnose status values ──────
// Set to true temporarily to see what's actually in the DB.
// Remove / set false in production.
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';

if ($debug_mode) {
    $debug_sql = "
        SELECT
            b.id            AS booking_id,
            b.status        AS booking_status,
            b.broadcast_id,
            jb.id           AS broadcast_id,
            jb.status       AS broadcast_status,
            jb.expires_at,
            EXTRACT(EPOCH FROM (jb.expires_at - NOW())) AS secs_left,
            NOW()           AS server_now
        FROM bookings b
        LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE b.worker_id = ?
        ORDER BY b.id DESC
        LIMIT 20
    ";
    $ds = $pdo->prepare($debug_sql);
    $ds->execute([$worker_id]);
    $debug_rows = $ds->fetchAll();
    echo json_encode(['debug' => true, 'rows' => $debug_rows], JSON_PRETTY_PRINT);
    exit;
}

// ── Main query ───────────────────────────────────────────────────────
// We intentionally do NOT filter on jb.status so we catch the broadcast
// regardless of what status value your system uses while it's still live.
// The only hard requirement is: broadcast exists, not yet expired, and
// booking is still available to accept (not already Accepted/Completed).
$sql = "
    SELECT
        b.id                                                        AS booking_id,
        jb.id                                                       AS broadcast_id,
        jb.status                                                   AS broadcast_status,
        b.status                                                    AS booking_status,
        EXTRACT(EPOCH FROM (jb.expires_at - NOW()))                 AS seconds_remaining,
        u.full_name                                                  AS client_name,
        b.service_type,
        u.address,
        TO_CHAR(b.booking_date, 'FMMonth DD, YYYY')                 AS booking_date,
        b.calculated_fee
    FROM bookings b
    JOIN users          u  ON b.client_id   = u.id
    JOIN job_broadcasts jb ON b.broadcast_id = jb.id
    WHERE
        b.worker_id = ?
        AND b.status NOT IN ('Accepted', 'Completed', 'Cancelled', 'Rejected')
        AND jb.expires_at > NOW()
        AND jb.status NOT IN ('cancelled', 'expired', 'accepted')
    ORDER BY jb.expires_at ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$worker_id]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[get_quick_matches] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// ── Sanitise & cast output ──────────────────────────────────
$matches = array_map(function (array $row): array {
    return [
        'booking_id'        => (int)   $row['booking_id'],
        'broadcast_id'      => (int)   $row['broadcast_id'],
        'seconds_remaining' => max(0, (int) $row['seconds_remaining']),
        'client_name'       => (string) ($row['client_name']  ?? ''),
        'service_type'      => (string) ($row['service_type'] ?? ''),
        'address'           => (string) ($row['address']      ?? ''),
        'booking_date'      => (string) ($row['booking_date'] ?? ''),
        'calculated_fee'    => $row['calculated_fee'] !== null
                                    ? number_format((float) $row['calculated_fee'], 2, '.', '')
                                    : null,
    ];
}, $rows);

echo json_encode([
    'success' => true,
    'matches' => $matches,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;