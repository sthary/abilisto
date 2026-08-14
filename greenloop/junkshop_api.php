<?php
// ============================================================
// greenloop/junkshop_api.php
// Junk Shop AJAX endpoints — PDO (Postgres), separate session
// POST body: JSON { action, report_id }
// GET  param: ?action=poll_feed|my_pickups
// ============================================================

// CRITICAL: Disable error display to prevent HTML in JSON
error_reporting(0);
ini_set('display_errors', 0);

// Use output buffering to catch any accidental output
ob_start();

include __DIR__ . '/../db_connect.php';
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth guard — junkshops use their own session key ─────────
if (empty($_SESSION['junkshop_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.', 'redirect' => '../junkshop/login.php']);
    exit;
}

$junkshop_id   = (int)$_SESSION['junkshop_id'];
$junkshop_name = $_SESSION['junkshop_name'] ?? 'A junk shop';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true) ?? [];
$action = $_GET['action'] ?? ($input['action'] ?? '');

function respond(array $data, int $code = 200): void {
    ob_clean(); // Clear any warnings that slipped through
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Router ────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── POLL BROADCAST FEED ───────────────────────────────
        case 'poll_feed':
            $stmt = $conn->query("
                SELECT
                    r.id,
                    COALESCE(cat.item_name, r.item_name_custom) AS scrap_type,
                    r.quantity,
                    r.unit,
                    r.estimated_green_coins,
                    r.ai_assessment,
                    r.image_path,
                    r.client_notes,
                    r.broadcasted_at,
                    r.pickup_latitude,
                    r.pickup_longitude,
                    r.pickup_address,
                    u.full_name  AS client_name,
                    u.email      AS client_email,
                    u.phone      AS client_phone
                FROM greenloop_reports r
                JOIN  users u    ON u.id  = r.client_id
                LEFT JOIN greenloop_accepted_items cat ON cat.id = r.item_id
                WHERE r.status = 'broadcasted'
                ORDER BY r.broadcasted_at DESC
                LIMIT 50
            ");

            $log_stmt = $conn->prepare("INSERT INTO greenloop_broadcast_log
                          (report_id, junkshop_id, seen_at)
                          VALUES (?, ?, NOW())
                          ON CONFLICT (report_id, junkshop_id) DO NOTHING");

            $feed = [];
            while ($row = $stmt->fetch()) {
                $feed[] = $row;

                // Audit log (ON CONFLICT DO NOTHING = no duplicates)
                $rid = (int)$row['id'];
                $safe_js_id = (int)$junkshop_id;
                $log_stmt->execute([$rid, $safe_js_id]);
            }

            respond(['feed' => $feed, 'timestamp' => date('c')]);
            break;


        // ── ACCEPT PICKUP (first-to-click wins) ───────────────
        case 'accept':
            $report_id = (int)($input['report_id'] ?? 0);
            if (!$report_id) {
                respond(['error' => 'Missing report_id.'], 400);
            }

            // Atomic claim: UPDATE only succeeds if still broadcasted
            $stmt = $conn->prepare("
                UPDATE greenloop_reports
                SET status = 'accepted', junkshop_id = ?, accepted_at = NOW()
                WHERE id = ? AND status = 'broadcasted'
            ");

            $stmt->execute([$junkshop_id, $report_id]);

            $affected = $stmt->rowCount();

            if ($affected === 0) {
                respond([
                    'success'       => false,
                    'already_taken' => true,
                    'message'       => 'Sorry — another junk shop just accepted this one!'
                ]);
            }

            // Fetch client_id for notification
            $stmt2 = $conn->prepare("SELECT client_id FROM greenloop_reports WHERE id = ?");
            $stmt2->execute([$report_id]);
            $row = $stmt2->fetch();

            if ($row) {
                $cid = (int)$row['client_id'];
                $notif_msg = "🚛 {$junkshop_name} has accepted your scrap pickup (Report #{$report_id})! They will contact you shortly.";
                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at)
                              VALUES (?, ?, 'greenloop/greenloop_wallet.php', 0, NOW())");
                $notif_stmt->execute([$cid, $notif_msg]);
            }

            respond(['success' => true, 'message' => 'Pickup accepted! Check My Pickups below.']);
            break;


        // ── MY PICKUPS ────────────────────────────────────────
        case 'my_pickups':
            $stmt = $conn->prepare("
                SELECT
                    r.id,
                    COALESCE(cat.item_name, r.item_name_custom) AS scrap_type,
                    r.quantity,
                    r.unit,
                    r.estimated_green_coins,
                    r.actual_green_coins_awarded,
                    r.image_path,
                    r.client_notes,
                    r.status,
                    r.accepted_at,
                    r.completed_at,
                    r.pickup_latitude,
                    r.pickup_longitude,
                    r.pickup_address,
                    u.full_name  AS client_name,
                    u.email      AS client_email,
                    u.phone      AS client_phone
                FROM greenloop_reports r
                JOIN  users u   ON u.id  = r.client_id
                LEFT JOIN greenloop_accepted_items cat ON cat.id = r.item_id
                WHERE r.junkshop_id = ?
                  AND r.status IN ('accepted', 'completed')
                ORDER BY r.accepted_at DESC
                LIMIT 50
            ");

            $stmt->execute([$junkshop_id]);

            $pickups = [];
            while ($row = $stmt->fetch()) {
                $pickups[] = $row;
            }

            respond(['pickups' => $pickups]);
            break;


        // ── MARK AS COMPLETED ─────────────────────────────────
        case 'complete':
            $report_id = (int)($input['report_id'] ?? 0);
            if (!$report_id) {
                respond(['error' => 'Missing report_id.'], 400);
            }

            // Verify this shop owns the report and it's accepted
            $stmt = $conn->prepare("
                SELECT id, status, client_id, estimated_green_coins, 
                       COALESCE(item_name_custom, 'Scrap Item') as item_name
                FROM greenloop_reports
                WHERE id = ? AND junkshop_id = ? AND status = 'accepted'
            ");

            $stmt->execute([$report_id, $junkshop_id]);

            $report = $stmt->fetch();

            if (!$report) {
                respond(['error' => 'Report not found, not yours, or already completed.'], 404);
            }

            // Mark as completed
            $stmt2 = $conn->prepare("
                UPDATE greenloop_reports
                SET status = 'completed', completed_at = NOW()
                WHERE id = ? AND junkshop_id = ?
            ");

            $stmt2->execute([$report_id, $junkshop_id]);

            // ================================================================
            // AWARD GREEN COINS TO CLIENT
            // ================================================================
            require_once __DIR__ . '/greenloop_db.php';
            
            $coins_to_award = (float)$report['estimated_green_coins'];
            $client_id      = (int)$report['client_id'];
            $item_name      = $report['item_name'];
            
            // Award the coins using PDO (from greenloop_db.php)
            $awarded = gc_award(
                $pdo,
                $client_id,
                $coins_to_award,
                'scrap_report',
                $report_id,
                "Scrap pickup completed — Report #{$report_id}: {$item_name}"
            );
            
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at)
                          VALUES (?, ?, 'greenloop/greenloop_wallet.php', 0, NOW())");

            if ($awarded) {
                // Update the report with actual coins awarded
                $stmt3 = $conn->prepare("
                    UPDATE greenloop_reports
                    SET actual_green_coins_awarded = ?, coins_awarded_at = NOW()
                    WHERE id = ?
                ");
                $stmt3->execute([$coins_to_award, $report_id]);

                // Notify user that coins were awarded
                $cid = (int)$client_id;
                $coins = (int)$coins_to_award;
                $notif_msg = "🎉 You earned {$coins} Green Coins from Report #{$report_id}! The junk shop \"{$junkshop_name}\" has completed your pickup.";
                $notif_stmt->execute([$cid, $notif_msg]);
            } else {
                // Log error but still mark as completed
                error_log("Failed to award Green Coins for report #{$report_id} to user #{$client_id}");

                // Notify user about completion (without coins)
                $cid = (int)$client_id;
                $notif_msg = "✅ {$junkshop_name} has completed your scrap pickup (Report #{$report_id}).";
                $notif_stmt->execute([$cid, $notif_msg]);
            }

            respond(['success' => true, 'message' => 'Pickup marked as completed! Coins awarded to client.']);
            break;


        default:
            respond(['error' => "Unknown action: $action"], 400);
    }

} catch (Throwable $e) {
    ob_clean();
    error_log('[GreenLoop JunkShop API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(['error' => 'Internal server error: ' . $e->getMessage()], 500);
}