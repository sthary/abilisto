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
require_once __DIR__ . '/../db.php';   // starts session, gives $pdo / $conn

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
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
            TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at) AS secs_left,
            NOW()           AS server_now
        FROM bookings b
        LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
        WHERE b.worker_id = ?
        ORDER BY b.id DESC
        LIMIT 20
    ";
    if (isset($pdo) && $pdo instanceof PDO) {
        $ds = $pdo->prepare($debug_sql);
        $ds->execute([$worker_id]);
        $debug_rows = $ds->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ds = $conn->prepare($debug_sql);
        $ds->bind_param('i', $worker_id);
        $ds->execute();
        $debug_rows = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
    }
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
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at)                 AS seconds_remaining,
        u.full_name                                                  AS client_name,
        b.service_type,
        u.address,
        DATE_FORMAT(b.booking_date, '%M %d, %Y')                    AS booking_date,
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
    // Prefer PDO if available (matches secured verification.php style),
    // fall back to mysqli if that's what db.php exposes.
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$worker_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $worker_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } else {
        throw new RuntimeException('No valid DB connection available.');
    }
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