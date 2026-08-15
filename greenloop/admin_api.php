<?php
// ============================================================
// greenloop/admin_api.php
// Admin-only AJAX endpoints — PDO (Postgres)
// POST  body : JSON  { action, report_id, [rejection_reason] }
// GET   param: ?action=list_pending|stats
// ============================================================

// CRITICAL: Disable error display to prevent HTML in JSON
error_reporting(0);
ini_set('display_errors', 0);

// Use output buffering to catch any accidental output
ob_start();

include __DIR__ . '/../db_connect.php';          // provides $conn (PDO)
include __DIR__ . '/../includes/init_lang.php';

session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth guard ────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    ob_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$raw    = file_get_contents('php://input');
$input  = json_decode($raw, true) ?? [];
$action = $_GET['action'] ?? ($input['action'] ?? '');

// ── Helper: safe JSON response ────────────────────────────────
function respond(array $data, int $code = 200): void {
    ob_clean(); // Clear any warnings that slipped through
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Router ────────────────────────────────────────────────────
try {
    switch ($action) {

        // ── LIST PENDING REPORTS ──────────────────────────────
        case 'list_pending':
            $page         = max(1, (int)($_GET['page'] ?? $input['page'] ?? 1));
            $status_filter = $_GET['status_filter'] ?? $input['status_filter'] ?? 'pending,rejected';
            $per_page     = 20;
            $offset       = ($page - 1) * $per_page;

            // Build WHERE clause based on filter
            $status_list = array_map('trim', explode(',', $status_filter));
            $status_placeholders = implode(',', array_fill(0, count($status_list), '?'));

            $where_clause = "r.status IN ($status_placeholders)";

            // Count first
            $cnt_sql = "SELECT COUNT(*) FROM greenloop_reports r WHERE $where_clause";
            $cnt_stmt = $conn->prepare($cnt_sql);
            $cnt_stmt->execute($status_list);
            $total = (int)$cnt_stmt->fetchColumn();

            $sql = "
                SELECT
                    r.id,
                    r.client_id,
                    u.full_name            AS client_name,
                    u.email                AS client_email,
                    COALESCE(cat.item_name, r.item_name_custom)  AS scrap_type,
                    r.quantity,
                    r.unit,
                    r.estimated_green_coins,
                    r.ai_assessment,
                    r.ai_accepted,
                    r.image_path,
                    r.client_notes,
                    r.rejection_reason,
                    r.status,
                    r.created_at
                FROM greenloop_reports r
                JOIN  users u   ON u.id  = r.client_id
                LEFT JOIN greenloop_accepted_items cat ON cat.id = r.item_id
                WHERE $where_clause
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($status_list, [$per_page, $offset]));

            $reports = [];
            while ($row = $stmt->fetch()) {
                $reports[] = $row;
            }

            respond([
                'reports'     => $reports,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => (int)ceil($total / $per_page),
            ]);
            break;

        // ── APPROVE & BROADCAST ───────────────────────────────
        case 'approve':
            $report_id = (int)($input['report_id'] ?? 0);
            if (!$report_id) respond(['error' => 'Missing report_id.'], 400);

            // Fetch report
            $stmt = $conn->prepare("SELECT id, status, client_id FROM greenloop_reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch();

            if (!$report) respond(['error' => 'Report not found.'], 404);
            if (!in_array($report['status'], ['pending', 'rejected'])) {
                respond(['error' => "Report is already {$report['status']}."], 422);
            }

            $stmt = $conn->prepare("
                UPDATE greenloop_reports
                SET status = 'broadcasted', broadcasted_at = NOW(), rejection_reason = NULL
                WHERE id = ?
            ");
            $stmt->execute([$report_id]);

            // Notify client
            $msg = "♻️ Your GreenLoop scrap report #{$report_id} has been approved and broadcasted to junk shops!";
            $link = '../greenloop/greenloop_wallet.php';
            $cid  = (int)$report['client_id'];
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at)
                          VALUES (?, ?, ?, 0, NOW())");
            $notif_stmt->execute([$cid, $msg, $link]);

            respond(['success' => true, 'message' => "Report #{$report_id} broadcasted to all junk shops."]);
            break;

        // ── REJECT ────────────────────────────────────────────
        case 'reject':
            $report_id = (int)($input['report_id'] ?? 0);
            $reason    = trim($input['rejection_reason'] ?? '');

            if (!$report_id)         respond(['error' => 'Missing report_id.'], 400);
            if (strlen($reason) < 5) respond(['error' => 'Rejection reason must be at least 5 characters.'], 400);

            $stmt = $conn->prepare("SELECT id, status, client_id FROM greenloop_reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch();

            if (!$report) respond(['error' => 'Report not found.'], 404);
            if (in_array($report['status'], ['accepted', 'completed'])) {
                respond(['error' => "Cannot reject a report that is {$report['status']}."], 422);
            }

            $stmt = $conn->prepare("
                UPDATE greenloop_reports SET status = 'rejected', rejection_reason = ? WHERE id = ?
            ");
            $stmt->execute([$reason, $report_id]);

            // Notify client
            $cid = (int)$report['client_id'];
            $notif_msg = "❌ Your GreenLoop report #{$report_id} was not approved. Reason: {$reason}";
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at)
                          VALUES (?, ?, '../greenloop/greenloop_wallet.php', 0, NOW())");
            $notif_stmt->execute([$cid, $notif_msg]);

            respond(['success' => true, 'message' => "Report #{$report_id} rejected."]);
            break;

        // ── STATS ─────────────────────────────────────────────
        case 'stats':
            $stmt = $conn->query("
                SELECT
                    COALESCE(SUM((status = 'pending')::int), 0)     AS pending,
                    COALESCE(SUM((status = 'broadcasted')::int), 0) AS broadcasted,
                    COALESCE(SUM((status = 'accepted')::int), 0)    AS accepted,
                    COALESCE(SUM((status = 'completed')::int), 0)   AS completed,
                    COALESCE(SUM((status = 'rejected')::int), 0)    AS rejected
                FROM greenloop_reports
            ");
            $row = $stmt->fetch();
            respond($row);
            break;

        default:
            respond(['error' => "Unknown action: $action"], 400);
    }

} catch (Throwable $e) {
    ob_clean();
    error_log('[GreenLoop Admin API] ' . $e->getMessage());
    respond(['error' => 'Internal server error: ' . $e->getMessage()], 500);
}