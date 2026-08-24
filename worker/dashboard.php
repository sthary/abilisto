<?php
// worker/dashboard.php - NEW UI VERSION with all functions preserved
// CHANGES vs previous version:
//   1. Profile query now also fetches schedule columns (schedule_mode, schedule_days,
//      schedule_time_start, schedule_time_end, schedule_label, can_accept_bookings)
//   2. New query for 'Pending Confirmation' bookings
//   3. Availability toggle replaced with smart schedule modal trigger button
//   4. New "Awaiting Confirmation" section inserted between Active Jobs and Pending Requests
//   5. can_accept_bookings = 0 shows an admin-disabled banner
include '../includes/init_lang.php';
include '../db_connect.php';
include '../auth/enforce_phone.php';
include '../includes/functions/wallet_manager.php';
include '../config/constants.php';

// Security: Worker Only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

// ── Tour: mark seen when JS pings this page with ?mark_tour_seen=1 ────────────
if (isset($_GET['mark_tour_seen']) && $_GET['mark_tour_seen'] == '1' && isset($_SESSION['user_id'])) {
    $tid = (int)$_SESSION['user_id'];
    $tour_stmt = $conn->prepare("UPDATE users SET has_seen_tour = TRUE WHERE id = ?");
    $tour_stmt->execute([$tid]);
    echo json_encode(['ok' => true]);
    exit;
}

$chk = $pdo->prepare("SELECT is_new, has_seen_tour FROM users WHERE id = ?");
$chk->execute([$_SESSION['user_id']]);
$u = $chk->fetch(PDO::FETCH_ASSOC);
if ($u && $u['is_new'] == 1) {
    header('Location: ../includes/profile_setup.php');
    exit;
}

$worker_id     = $_SESSION['user_id'];
$has_seen_tour = !empty($u['has_seen_tour']);
$wallet        = new WalletManager($conn);

// ── Get worker profile (now includes schedule + can_accept_bookings) ─────────
$profile_stmt = $conn->prepare("SELECT availability_status, wallet_balance, jobs_completed,
                                average_rating, verification_status, free_credits, free_bookings_used,
                                listo_points,
                                schedule_mode, schedule_days, schedule_time_start,
                                schedule_time_end, schedule_label, can_accept_bookings
                         FROM worker_profiles WHERE user_id = ?");
$profile_stmt->execute([$worker_id]);
$profile = $profile_stmt->fetch();

$listo_points   = (int)($profile['listo_points']        ?? 0);
$canAccept      = (int)($profile['can_accept_bookings']  ?? 1);
$scheduleMode   = $profile['schedule_mode']              ?? 'manual';
$scheduleLabel  = $profile['schedule_label']             ?? '';

// Build active-schedule day array for pre-selecting checkboxes
$scheduleDaysArr = [];
if (!empty($profile['schedule_days'])) {
    foreach (explode(',', $profile['schedule_days']) as $d) {
        $scheduleDaysArr[] = (int)trim($d);
    }
}

// Verification flag
$is_verified = !empty($profile['verification_status']) && $profile['verification_status'] !== 'Unverified';

// ── Pending bookings ──────────────────────────────────────────────────────────
$pending_sql = "SELECT
    b.*,
    u.full_name,
    u.address,
    u.phone,
    u.t_latitude,
    u.t_longitude,
    u.latitude,
    u.longitude,
    jb.id as broadcast_id,
    jb.expires_at as broadcast_expires_at,
    jb.status as broadcast_status,
    EXTRACT(EPOCH FROM (jb.expires_at - NOW())) as seconds_remaining,
    CASE
        WHEN jb.id IS NOT NULL AND jb.expires_at > NOW() AND jb.status = 'searching' THEN 1
        ELSE 0
    END as is_active_quick_match,
    CASE
        WHEN jb.id IS NOT NULL AND jb.expires_at <= NOW() THEN 1
        ELSE 0
    END as is_expired_quick_match
FROM bookings b
JOIN users u ON b.client_id = u.id
LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
WHERE b.worker_id = ?
    AND b.status = 'Pending'
    AND (
        b.broadcast_id IS NULL
        OR
        (jb.id IS NOT NULL AND jb.status = 'searching' AND jb.expires_at > NOW())
    )
    AND (jb.id IS NULL OR jb.status NOT IN ('cancelled', 'expired'))
ORDER BY
    CASE
        WHEN jb.id IS NOT NULL AND jb.expires_at > NOW() AND jb.status = 'searching' THEN 0
        WHEN jb.id IS NOT NULL AND jb.expires_at <= NOW() THEN 1
        ELSE 2
    END,
    CASE b.urgency_level
        WHEN 'Emergency' THEN 1
        WHEN 'High' THEN 2
        WHEN 'Normal' THEN 3
    END,
    b.booking_date ASC";

$pending_stmt = $conn->prepare($pending_sql);
$pending_stmt->execute([$worker_id]);
$pending_rows = $pending_stmt->fetchAll();
$pending_count = count($pending_rows);

// ── Active jobs ───────────────────────────────────────────────────────────────
$active_sql = "SELECT b.*, u.full_name, u.address, u.phone, u.latitude, u.longitude
               FROM bookings b
               JOIN users u ON b.client_id = u.id
               WHERE b.worker_id = ? AND b.status = 'Accepted'
               ORDER BY b.booking_date ASC";
$active_stmt = $conn->prepare($active_sql);
$active_stmt->execute([$worker_id]);
$active_rows = $active_stmt->fetchAll();

// ── NEW: Pending Confirmation bookings ────────────────────────────────────────
// These are jobs where the worker has confirmed cash payment but is waiting
// for the client to press "Confirm Job Complete" on their side.
$pending_conf_sql = "SELECT b.*, u.full_name, u.address, u.phone, u.latitude, u.longitude,
                            TO_CHAR(b.booking_date, 'FMMonth DD, YYYY') as formatted_date
                     FROM bookings b
                     JOIN users u ON b.client_id = u.id
                     WHERE b.worker_id = ?
                       AND b.status = 'Pending Confirmation'
                     ORDER BY b.updated_at DESC";
$pending_conf_stmt  = $conn->prepare($pending_conf_sql);
$pending_conf_stmt->execute([$worker_id]);
$pending_conf_rows  = $pending_conf_stmt->fetchAll();
$pending_conf_count = count($pending_conf_rows);

// ── Completed jobs (last 10) ──────────────────────────────────────────────────
$history_sql = "SELECT
    b.*,
    u.full_name,
    u.address,
    u.phone,
    u.latitude,
    u.longitude,
    TO_CHAR(b.booking_date, 'FMMonth DD, YYYY') as formatted_date,
    COALESCE(b.updated_at, b.created_at, b.booking_date) as sort_date
FROM bookings b
JOIN users u ON b.client_id = u.id
WHERE b.worker_id = ? AND b.status = 'Completed'
ORDER BY sort_date DESC
LIMIT 10";

$history_stmt = $conn->prepare($history_sql);
$history_stmt->execute([$worker_id]);
$history_rows = $history_stmt->fetchAll();

// ── Pending escrow amount ─────────────────────────────────────────────────────
$escrow_sql = "SELECT COUNT(*) as count, SUM(calculated_fee) as total
               FROM bookings
               WHERE worker_id = ?
               AND payment_method = 'PayMongo'
               AND payment_status = 'Paid'
               AND is_escrow = TRUE
               AND status = 'Pending'";
$escrow_stmt = $conn->prepare($escrow_sql);
$escrow_stmt->execute([$worker_id]);
$escrow = $escrow_stmt->fetch();

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Day labels for schedule modal
$dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Worker Dashboard | Abilisto</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
    <link href="../includes/tour_engine.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <!-- Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

    <!-- OneSignal -->
    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>

    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "inverse-primary": "#8980ff",
              "error-dim": "#a70138",
              "surface-container-high": "#dee3ec",
              "surface-container-highest": "#d8dde7",
              "on-secondary-fixed": "#3900a2",
              "secondary-fixed-dim": "#c9baff",
              "tertiary-fixed": "#5dfdc5",
              "surface-dim": "#cfd5df",
              "surface-variant": "#d8dde7",
              "on-tertiary": "#c7ffe4",
              "tertiary-fixed-dim": "#4aeeb7",
              "surface-container-low": "#edf1f8",
              "primary-dim": "#3700e8",
              "on-tertiary-fixed-variant": "#00694c",
              "inverse-on-surface": "#999da3",
              "outline-variant": "#aaadb4",
              "tertiary": "#00694c",
              "on-primary": "#e8e4ff",
              "tertiary-dim": "#005b42",
              "tertiary-container": "#5dfdc5",
              "inverse-surface": "#0a0f13",
              "primary-fixed-dim": "#8c84ff",
              "error-container": "#f74b6d",
              "surface-container-lowest": "#ffffff",
              "on-secondary-fixed-variant": "#5710e6",
              "secondary-fixed": "#d7caff",
              "on-background": "#2b2f34",
              "secondary": "#6229f1",
              "on-tertiary-container": "#005e44",
              "surface-container": "#e4e8f1",
              "on-surface": "#2b2f34",
              "on-error-container": "#510017",
              "on-surface-variant": "#585c62",
              "secondary-dim": "#560ce6",
              "surface-tint": "#146af5",
              "on-primary-fixed": "#000000",
              "on-error": "#ffefef",
              "primary": "#146af5",
              "surface-bright": "#f3f6fd",
              "error": "#b41340",
              "background": "#f3f6fd",
              "primary-fixed": "#9b94ff",
              "primary-container": "#9b94ff",
              "on-secondary-container": "#4d00d4",
              "on-secondary": "#f7f0ff",
              "surface": "#f3f6fd",
              "on-primary-fixed-variant": "#200096",
              "outline": "#73777d",
              "on-primary-container": "#19007c",
              "on-tertiary-fixed": "#004934"
            },
            fontFamily: {
              "headline": ["Plus Jakarta Sans"],
              "body": ["Plus Jakarta Sans"],
              "label": ["Plus Jakarta Sans"]
            },
            borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "1.5rem", "full": "9999px"},
          },
        },
      }
    </script>

    <style>
        /* ── Base ── */
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .material-symbols-rounded.filled {
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f3f6fd; }
        h1, h2, h3 { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* ── Dark mode text fix ──────────────────────────────────────────
           This page's whole Material-3 color palette above (tailwind.config)
           was only ever exported for light mode — on-surface/on-background
           etc. are static dark-gray hexes with no dark counterpart, so text
           using them stayed dark-on-dark once the navbar's global
           `.dark body` rule flips the background. These overrides give the
           text-bearing tokens an actual dark-mode value, same technique the
           navbar already uses (a `.dark` DOM-order override), so every
           existing `text-on-surface`/`text-on-surface-variant` usage on this
           page just works without having to hunt down and pair `dark:`
           classes at each individual call site. */
        .dark .text-on-surface          { color: #f1f5f9; }
        .dark .text-on-surface-variant  { color: #94a3b8; }
        .dark .text-on-background       { color: #f1f5f9; }
        .dark .bg-surface-container,
        .dark .bg-surface-container-low,
        .dark .bg-surface-container-lowest,
        .dark .bg-surface-container-high,
        .dark .bg-surface-container-highest { background-color: #1e293b; }
        .dark .border-outline-variant\/30 { border-color: rgba(148,163,184,0.3); }

        /* ── Job card hover ── */
        .job-card-hover {
            transition: all 0.3s ease;
        }
        .job-card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -15px rgba(20,106,245,0.10);
        }

        /* ── Status badge/card states (kept from original) ── */
        .expired-badge, .cancelled-badge, .accepted-badge {
            animation: pulse 2s infinite;
            z-index: 10;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        [data-booking-expired="true"]   { opacity: 0.6; filter: grayscale(0.7); }
        [data-booking-cancelled="true"] { background-color: #fef2f2; border-color: #fecaca !important; }
        [data-booking-accepted="true"]  { background-color: #f0fdf4; border-color: #bbf7d0 !important; }

        [data-booking-cancelled="true"]::before,
        [data-booking-accepted="true"]::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            border-radius: 24px;
        }
        [data-booking-cancelled="true"]::before {
            background: repeating-linear-gradient(45deg,rgba(239,68,68,.1),rgba(239,68,68,.1) 10px,transparent 10px,transparent 20px);
        }
        [data-booking-accepted="true"]::before {
            background: repeating-linear-gradient(45deg,rgba(34,197,94,.1),rgba(34,197,94,.1) 10px,transparent 10px,transparent 20px);
        }

        /* ── Quick match ── */
        .quick-match-accept:disabled,
        .quick-match-accept.opacity-50 {
            background: #94a3b8 !important;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .expired-quick-match { opacity: 0.8; filter: grayscale(0.3); }
        .timer-urgent { animation: pulse 1s infinite; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.7; }
        }

        /* ── Notification badge pulse ── */
        .notification-badge {
            animation: notificationPulse 2s infinite;
        }
        @keyframes notificationPulse {
            0%   { box-shadow: 0 0 0 0   rgba(20,106,245,0.4); }
            70%  { box-shadow: 0 0 0 6px rgba(20,106,245,0); }
            100% { box-shadow: 0 0 0 0   rgba(20,106,245,0); }
        }

        /* ── In-app notification / toast ── */
        #in-app-notification {
            position: fixed; top: 20px; right: 20px;
            background: white;
            border-left: 4px solid #146af5;
            border-radius: 4px;
            padding: 15px 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999; max-width: 350px;
            animation: slideInRight 0.3s ease;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes fadeInUp {
            from { transform: translate(-50%, 20px); opacity: 0; }
            to   { transform: translate(-50%, 0);    opacity: 1; }
        }
        .toast-message {
            position: fixed; bottom: 20px; left: 50%;
            transform: translateX(-50%);
            background: #146af5; color: white;
            padding: 10px 20px; border-radius: 4px;
            z-index: 10000;
            animation: fadeInUp 0.3s ease;
        }

        /* ── Verification modal ── */
        #unverified-modal {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 1rem;
        }
        #unverified-modal.hidden { display: none; }

        /* ── Schedule modal ── */
        #schedule-modal {
            position: fixed; inset: 0; z-index: 9998;
            display: flex; align-items: flex-end; justify-content: center;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 0;
        }
        #schedule-modal.hidden { display: none; }
        #schedule-modal-card {
            background: white;
            border-radius: 28px 28px 0 0;
            padding: 2rem 1.5rem 2.5rem;
            width: 100%; max-width: 540px;
            max-height: 92vh;
            overflow-y: auto;
            animation: slideUpModal 0.35s cubic-bezier(.4,0,.2,1) forwards;
        }
        @media (min-width: 640px) {
            #schedule-modal {
                align-items: center;
                padding: 1rem;
            }
            #schedule-modal-card {
                border-radius: 28px;
                max-height: 88vh;
            }
        }
        @keyframes slideUpModal {
            from { transform: translateY(100%); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        .modal-card {
            background: white;
            border-radius: 28px;
            padding: 2.5rem 2rem;
            max-width: 420px; width: 100%;
            text-align: center;
            box-shadow: 0 40px 80px -20px rgba(0,0,0,0.25);
            animation: modalPop 0.35s cubic-bezier(.4,0,.2,1) forwards;
        }
        @keyframes modalPop {
            from { transform: scale(0.92); opacity: 0; }
            to   { transform: scale(1);    opacity: 1; }
        }

        /* ── Day pill toggles ── */
        .day-pill input[type="checkbox"] { display: none; }
        .day-pill label {
            display: flex; align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 50%;
            font-size: 11px; font-weight: 700; letter-spacing: 0.02em;
            border: 2px solid #e4e8f1;
            color: #585c62;
            cursor: pointer;
            transition: all 0.18s;
            user-select: none;
        }
        .day-pill input:checked + label {
            background: #146af5;
            border-color: #146af5;
            color: white;
            box-shadow: 0 4px 12px rgba(20,106,245,0.3);
        }

        /* ── Section/card chrome ── */
        .section-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        /* ── Scrollbar ── */
        .overflow-x-auto::-webkit-scrollbar { height: 6px; }
        .overflow-x-auto::-webkit-scrollbar-track { background: #edf1f8; border-radius: 10px; }
        .overflow-x-auto::-webkit-scrollbar-thumb { background: #aaadb4; border-radius: 10px; }

        /* ── Mobile ── */
        @media (max-width: 640px) {
            .ambient-card .flex.gap-2 > button,
            .ambient-card .flex.gap-3 > button,
            .ambient-card .flex.gap-2 > a,
            .ambient-card .flex.gap-3 > a { min-height: 44px; }
            .quick-match-accept { width: 100%; }
            .ambient-card { overflow: hidden; }
        }

        /* Ambient card */
        .ambient-card {
            background: white;
            border: 1px solid #e4e8f1;
            box-shadow: 0 10px 40px 0 rgba(43,47,52,0.03);
            border-radius: 24px;
        }

        /* ── GREEN: Complete Job button & Accept button ── */
        .btn-complete-job,
        .btn-accept-job,
        .quick-match-accept {
            background: #16a34a !important;
            box-shadow: 0 8px 20px -4px rgba(22,163,74,0.35) !important;
        }
        .btn-complete-job:hover,
        .btn-accept-job:hover,
        .quick-match-accept:not(:disabled):hover {
            background: #15803d !important;
        }

        /* ── AI suggestion chips ── */
        .ai-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px;
            border: 1.5px solid #e4e8f1;
            font-size: 12px; font-weight: 600; color: #585c62;
            cursor: pointer; transition: all 0.18s;
            background: white;
        }
        .ai-chip:hover {
            border-color: #146af5; color: #146af5;
            background: #f0edff;
        }
        .ai-chip.active {
            border-color: #146af5; color: #146af5;
            background: #f0edff;
        }

        /* ── Schedule status indicator on the trigger button ── */
        .schedule-active-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #16a34a;
            box-shadow: 0 0 0 2px #fff, 0 0 0 3px #16a34a;
        }

        /* Tour styles moved to includes/tour_engine.css (shared with the
           client-side tour on worker_details.php / booking.php). */
    </style>
</head>
<body class="text-on-surface antialiased min-h-screen overflow-x-hidden">

<?php include '../includes/navbar.php'; ?>
<?php include '../includes/chatbot_widget.php'; ?>

<!-- ══════════════════════════════════════════
     UNVERIFIED WORKER MODAL
     CHANGE 1: Hidden via inline style when tour hasn't been seen yet,
     so the tour always finishes before this modal appears.
══════════════════════════════════════════ -->
<?php if (!$is_verified): ?>
<div id="unverified-modal" <?php if (!$has_seen_tour): ?>style="display:none;"<?php endif; ?>>
    <div class="modal-card">
        <div class="w-20 h-20 rounded-full bg-amber-50 flex items-center justify-center mx-auto mb-5">
            <span class="material-symbols-rounded filled text-amber-400" style="font-size:40px;font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 40">verified_user</span>
        </div>
        <h2 class="text-2xl font-extrabold text-on-surface mb-2 tracking-tight">You're not verified yet</h2>
        <p class="text-on-surface-variant text-sm leading-relaxed mb-8 max-w-xs mx-auto">
            Verified workers get a badge on their profile, appear higher in search results, and earn more trust from clients. It only takes a few minutes!
        </p>
        <div class="flex flex-col gap-3">
            <a href="verification.php"
               class="w-full py-4 rounded-[18px] font-bold text-white text-base flex items-center justify-center gap-2 transition-all hover:-translate-y-0.5"
               style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 8px 20px -4px rgba(245,158,11,0.4)">
                <span class="material-symbols-rounded filled text-white" style="font-variation-settings:'FILL' 1">verified</span>
                Verify Now
            </a>
            <button onclick="document.getElementById('unverified-modal').classList.add('hidden')"
                    class="w-full py-3.5 rounded-[18px] font-semibold text-on-surface-variant text-sm bg-surface-container hover:bg-surface-container-high transition-colors">
                Maybe Later
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     SMART SCHEDULE MODAL
══════════════════════════════════════════ -->
<div id="schedule-modal" class="hidden">
    <div id="schedule-modal-card">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-xl font-extrabold text-on-surface tracking-tight">Availability Schedule</h2>
                <p class="text-on-surface-variant text-xs mt-0.5">Set when you're automatically online or offline.</p>
            </div>
            <button onclick="closeScheduleModal()"
                    class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container hover:bg-surface-container-high transition-colors">
                <span class="material-symbols-outlined text-on-surface-variant" style="font-size:20px;">close</span>
            </button>
        </div>

        <!-- Current status pill -->
        <div class="mb-5 flex items-center gap-2 px-3 py-2.5 rounded-xl
                    <?php echo $profile['availability_status'] === 'Available' ? 'bg-green-50 border border-green-200' : 'bg-slate-100 border border-slate-200'; ?>">
            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0
                         <?php echo $profile['availability_status'] === 'Available' ? 'bg-green-500' : 'bg-slate-400'; ?>"></span>
            <span class="text-sm font-semibold
                         <?php echo $profile['availability_status'] === 'Available' ? 'text-green-700' : 'text-slate-600'; ?>">
                Currently <?php echo $profile['availability_status']; ?>
                <?php if ($scheduleMode === 'scheduled' && !empty($scheduleLabel)): ?>
                  &mdash; <span class="font-normal"><?php echo htmlspecialchars($scheduleLabel); ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- Admin-disabled notice -->
        <?php if (!$canAccept): ?>
        <div class="mb-5 flex items-start gap-3 px-4 py-3 bg-red-50 border border-red-200 rounded-xl">
            <span class="material-symbols-outlined text-red-500 flex-shrink-0" style="font-size:20px;font-variation-settings:'FILL' 1">block</span>
            <div>
                <p class="text-sm font-bold text-red-700">Accepting new bookings is disabled</p>
                <p class="text-xs text-red-500 mt-0.5">An administrator has temporarily disabled your ability to accept new bookings. Please contact support if you believe this is a mistake.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── MANUAL TOGGLE ── -->
        <div class="mb-6 p-4 rounded-2xl border border-outline-variant/20 bg-surface-container-lowest">
            <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-3">Quick Toggle</p>
            <form method="POST" action="update_status.php">
                <input type="hidden" name="action" value="manual">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-on-surface">Manual Override</p>
                        <p class="text-xs text-on-surface-variant">Instantly set your status. Overrides any active schedule.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="status" value="Available"
                               onchange="this.form.submit()"
                               class="sr-only peer"
                               <?php echo $profile['availability_status'] === 'Available' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-slate-300 peer-checked:bg-primary rounded-full transition-colors
                                    after:content-[''] after:absolute after:top-[3px] after:left-[3px]
                                    after:bg-white after:rounded-full after:h-[18px] after:w-[18px]
                                    after:transition-all peer-checked:after:translate-x-5
                                    after:shadow-sm"></div>
                    </label>
                </div>
            </form>
        </div>

        <!-- ── AI SMART SUGGESTIONS ── -->
        <div class="mb-5">
            <div class="flex items-center gap-2 mb-3">
                <span class="material-symbols-outlined text-primary" style="font-size:16px;font-variation-settings:'FILL' 1">auto_awesome</span>
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Smart Suggestions</p>
            </div>
            <div class="flex flex-wrap gap-2" id="ai-suggestions">
                <button class="ai-chip" onclick="applyPreset('weekdays','Mon – Fri, 8 AM – 6 PM',[1,2,3,4,5],'08:00','18:00')">
                    <span class="material-symbols-outlined" style="font-size:14px;">work</span>
                    Mon – Fri, 8 AM – 6 PM
                </button>
                <button class="ai-chip" onclick="applyPreset('mornings','Weekdays, Morning Only',[1,2,3,4,5],'07:00','12:00')">
                    <span class="material-symbols-outlined" style="font-size:14px;">wb_sunny</span>
                    Weekdays, Morning Only
                </button>
                <button class="ai-chip" onclick="applyPreset('weekend','Weekends All Day',[0,6],null,null)">
                    <span class="material-symbols-outlined" style="font-size:14px;">weekend</span>
                    Weekends All Day
                </button>
                <button class="ai-chip" onclick="applyPreset('everyday','Every Day, 9 AM – 5 PM',[0,1,2,3,4,5,6],'09:00','17:00')">
                    <span class="material-symbols-outlined" style="font-size:14px;">calendar_today</span>
                    Every Day, 9 AM – 5 PM
                </button>
                <button class="ai-chip" onclick="applyPreset('custom','Custom...', null, null, null)">
                    <span class="material-symbols-outlined" style="font-size:14px;">tune</span>
                    Custom
                </button>
            </div>
        </div>

        <!-- ── SCHEDULE BUILDER ── -->
        <form method="POST" action="update_status.php" id="scheduleForm">
            <input type="hidden" name="action" value="schedule">

            <!-- Day picker -->
            <div class="mb-5">
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-3">Active Days</p>
                <div class="flex gap-2 flex-wrap">
                    <?php foreach ($dayLabels as $idx => $label): ?>
                    <div class="day-pill">
                        <input type="checkbox"
                               name="days[]"
                               value="<?php echo $idx; ?>"
                               id="day-<?php echo $idx; ?>"
                               <?php echo in_array($idx, $scheduleDaysArr, true) ? 'checked' : ''; ?>>
                        <label for="day-<?php echo $idx; ?>"><?php echo $label; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] text-on-surface-variant mt-2 flex items-center gap-1">
                    <span class="material-symbols-outlined" style="font-size:13px;">info</span>
                    On unchecked days your status will automatically switch to <strong>Unavailable</strong> at 12:00 AM.
                </p>
            </div>

            <!-- Time window -->
            <div class="mb-5">
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-3">Time Window <span class="normal-case font-normal">(optional — leave blank for all day)</span></p>
                <div class="flex items-center gap-3">
                    <div class="flex-1">
                        <label class="text-[11px] text-on-surface-variant font-medium block mb-1">From</label>
                        <input type="time" name="time_start" id="inp-time-start"
                               value="<?php echo htmlspecialchars(substr($profile['schedule_time_start'] ?? '', 0, 5)); ?>"
                               class="w-full rounded-xl border-outline-variant/30 bg-surface-container-lowest text-sm font-medium text-on-surface focus:ring-2 focus:ring-primary/30 focus:border-primary">
                    </div>
                    <span class="text-on-surface-variant font-bold mt-4">→</span>
                    <div class="flex-1">
                        <label class="text-[11px] text-on-surface-variant font-medium block mb-1">Until</label>
                        <input type="time" name="time_end" id="inp-time-end"
                               value="<?php echo htmlspecialchars(substr($profile['schedule_time_end'] ?? '', 0, 5)); ?>"
                               class="w-full rounded-xl border-outline-variant/30 bg-surface-container-lowest text-sm font-medium text-on-surface focus:ring-2 focus:ring-primary/30 focus:border-primary">
                    </div>
                </div>
            </div>

            <!-- Natural language / timezone note -->
            <div class="mb-5 flex items-start gap-2 px-3 py-3 bg-primary/5 rounded-xl border border-primary/10">
                <span class="material-symbols-outlined text-primary flex-shrink-0" style="font-size:16px;font-variation-settings:'FILL' 1">auto_awesome</span>
                <div>
                    <p class="text-xs font-bold text-primary mb-0.5">Timezone auto-detected</p>
                    <p class="text-[11px] text-on-surface-variant" id="tz-display">Detecting…</p>
                </div>
            </div>

            <!-- Preview -->
            <div id="schedule-preview" class="mb-5 hidden px-4 py-3 bg-surface-container rounded-xl text-sm text-on-surface-variant">
                <span class="font-bold text-on-surface">Your schedule: </span>
                <span id="preview-text">—</span>
            </div>

            <!-- Label (hidden, auto-generated) -->
            <input type="hidden" name="label" id="inp-label">

            <!-- Save -->
            <div class="flex gap-3">
                <button type="submit"
                        class="flex-1 py-3.5 rounded-[18px] font-bold text-white text-sm flex items-center justify-center gap-2 transition-all hover:-translate-y-0.5"
                        style="background:linear-gradient(135deg,#146af5,#6229f1);box-shadow:0 8px 20px -4px rgba(20,106,245,0.4)">
                    <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1">save</span>
                    Save Schedule
                </button>
                <?php if ($scheduleMode === 'scheduled'): ?>
                <form method="POST" action="update_status.php" style="margin:0">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit"
                            class="h-full px-4 py-3.5 rounded-[18px] font-semibold text-on-surface-variant text-sm bg-surface-container hover:bg-surface-container-high transition-colors"
                            title="Remove schedule and return to manual">
                        <span class="material-symbols-outlined" style="font-size:18px;">delete</span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<!-- ══════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════ -->

<?php if (!$canAccept): ?>
<!-- Admin-disabled global banner -->
<div class="mx-4 sm:mx-5 md:mx-6 lg:mx-8 mt-4 max-w-7xl lg:max-w-7xl">
    <div class="flex items-start gap-3 px-5 py-4 bg-red-50 border border-red-200 rounded-2xl">
        <span class="material-symbols-outlined text-red-500 flex-shrink-0 mt-0.5" style="font-size:22px;font-variation-settings:'FILL' 1">block</span>
        <div>
            <p class="font-bold text-red-700 text-sm">Accepting new bookings is currently disabled</p>
            <p class="text-xs text-red-500 mt-0.5">An administrator has placed a restriction on your account. You can still manage your existing jobs, but you cannot accept new ones. Contact support for assistance.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<main class="p-4 sm:p-5 md:p-6 lg:p-8 space-y-5 sm:space-y-6 md:space-y-8 max-w-7xl mx-auto">

    <!-- ── Welcome Hero ─────────────────────────── -->
    <section>
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-5">
            <div>
                <h1 class="text-2xl sm:text-3xl md:text-5xl font-extrabold tracking-tight text-on-surface mb-1">
                    Welcome back, <?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]); ?>!
                </h1>
                <p class="text-on-surface-variant font-medium text-sm md:text-base">
                    Here's your Abilisto workspace overview for today.
                </p>
            </div>

            <!-- ── SMART SCHEDULE TRIGGER BUTTON ── -->
            <button onclick="openScheduleModal()"
                    id="tour-schedule-btn"
                    class="flex items-center gap-3 bg-surface-container-lowest px-4 py-3 rounded-2xl shadow-sm border border-outline-variant/10 flex-shrink-0 hover:border-primary/20 hover:bg-primary/5 transition-all group">
                <div class="relative flex-shrink-0">
                    <span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary transition-colors" style="font-size:20px;font-variation-settings:'FILL' 1">schedule</span>
                    <?php if ($scheduleMode === 'scheduled'): ?>
                    <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-green-500 rounded-full border-2 border-white"></span>
                    <?php endif; ?>
                </div>
                <div class="text-left">
                    <p class="text-xs font-bold text-on-surface leading-none">
                        <?php echo $profile['availability_status'] === 'Available' ? 'Available' : 'Unavailable'; ?>
                    </p>
                    <p class="text-[10px] text-on-surface-variant mt-0.5 leading-none">
                        <?php if ($scheduleMode === 'scheduled' && !empty($scheduleLabel)): ?>
                            <?php echo htmlspecialchars($scheduleLabel); ?>
                        <?php else: ?>
                            Tap to set schedule
                        <?php endif; ?>
                    </p>
                </div>
                <span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary transition-colors" style="font-size:16px;">expand_more</span>
            </button>
        </div>
    </section>

    <!-- ── Stats Bento Grid ─────────────────────── -->
    <section id="tour-stats" class="grid grid-cols-3 gap-2 md:gap-4">

        <!-- Jobs Done -->
        <div class="group relative overflow-hidden bg-surface-container-lowest p-3 md:p-5 rounded-xl shadow-[0_10px_40px_0_rgba(43,47,52,0.03)] transition-all hover:translate-y-[-4px]">
            <div class="flex justify-between items-start mb-2 md:mb-4">
                <div class="w-8 h-8 md:w-12 md:h-12 rounded-lg md:rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-lg md:text-2xl" style="font-variation-settings:'FILL' 1">check_circle</span>
                </div>
            </div>
            <p class="text-on-surface-variant text-[10px] md:text-sm font-medium mb-0.5 md:mb-1">Jobs Done</p>
            <h3 class="text-xl md:text-4xl font-extrabold text-on-surface"><?php echo number_format((int)($profile['jobs_completed'] ?? 0)); ?></h3>
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 transition-transform hidden md:block">
                <span class="material-symbols-outlined text-9xl">work</span>
            </div>
        </div>

        <!-- Listo Points -->
        <div class="group relative overflow-hidden bg-surface-container-lowest p-3 md:p-5 rounded-xl shadow-[0_10px_40px_0_rgba(43,47,52,0.03)] transition-all hover:translate-y-[-4px]">
            <div class="flex justify-between items-start mb-2 md:mb-4">
                <div class="w-8 h-8 md:w-12 md:h-12 rounded-lg md:rounded-xl bg-tertiary-container/30 flex items-center justify-center text-tertiary">
                    <span class="material-symbols-outlined text-lg md:text-2xl" style="font-variation-settings:'FILL' 1">stars</span>
                </div>
            </div>
            <p class="text-on-surface-variant text-[10px] md:text-sm font-medium mb-0.5 md:mb-1">Points</p>
            <h3 class="text-xl md:text-4xl font-extrabold text-on-surface"><?php echo number_format($listo_points); ?></h3>
            <div class="absolute -right-4 -bottom-4 opacity-5 group-hover:scale-110 transition-transform hidden md:block">
                <span class="material-symbols-outlined text-9xl">military_tech</span>
            </div>
        </div>

        <!-- Wallet Balance -->
        <div class="group relative overflow-hidden bg-primary p-3 md:p-5 rounded-xl shadow-[0_20px_40px_rgba(20,106,245,0.15)] transition-all hover:translate-y-[-4px]">
            <div class="flex justify-between items-start mb-2 md:mb-4">
                <div class="w-8 h-8 md:w-12 md:h-12 rounded-lg md:rounded-xl bg-white/20 flex items-center justify-center text-white">
                    <span class="material-symbols-outlined text-lg md:text-2xl">payments</span>
                </div>
            </div>
            <p class="text-white/70 text-[10px] md:text-sm font-medium mb-0.5 md:mb-1">Balance</p>
            <h3 class="text-sm md:text-3xl font-extrabold text-white truncate">₱<?php echo number_format((float)($profile['wallet_balance'] ?? 0), 2); ?></h3>
            <div class="absolute -right-4 -bottom-4 opacity-10 hidden md:block">
                <span class="material-symbols-outlined text-9xl text-white">account_balance_wallet</span>
            </div>
        </div>

    </section>

    <!-- ══════════════════════════════════════════
         ACTIVE JOBS + PENDING REQUESTS (side by side on lg)
    ══════════════════════════════════════════ -->
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-5 lg:gap-6">

        <!-- ── Active Jobs ── -->
        <div id="tour-active-jobs" class="space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="section-icon bg-tertiary/10">
                        <span class="material-symbols-outlined text-tertiary text-sm" style="font-variation-settings:'FILL' 1">work</span>
                    </div>
                    <h2 class="text-xl font-bold tracking-tight text-on-surface">Active Jobs</h2>
                </div>
            </div>

            <?php if (count($active_rows) > 0): ?>
            <div class="space-y-4">
                <?php
                include_once 'partials/booking_card.php';
                foreach ($active_rows as $job):
                    renderBookingCard($job, 'active');
                endforeach;
                ?>
            </div>
            <?php else: ?>
            <div class="bg-surface-container-lowest p-12 rounded-3xl border border-outline-variant/10 text-center shadow-[0_10px_40px_0_rgba(43,47,52,0.02)]">
                <div class="w-16 h-16 bg-surface-container rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-on-surface-variant" style="font-size:28px">calendar_month</span>
                </div>
                <h3 class="text-base font-bold text-on-surface mb-1">No active jobs</h3>
                <p class="text-on-surface-variant text-sm">Your accepted bookings will appear here.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Pending Requests ── -->
        <div id="pending-requests-section" class="space-y-5<?php echo $pending_count === 0 ? ' hidden' : ''; ?>">
            <div class="flex items-center gap-3 flex-wrap">
                <div class="section-icon bg-primary/10">
                    <span class="material-symbols-outlined text-primary text-sm" style="font-variation-settings:'FILL' 1">notifications</span>
                </div>
                <h2 class="text-xl font-bold tracking-tight text-on-surface">Pending Requests</h2>

                <?php if ($pending_count > 0): ?>
                <span id="pending-count-badge"
                      class="bg-error text-white text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider">
                    <?php echo $pending_count; ?> New
                </span>
                <?php else: ?>
                <span id="pending-count-badge"
                      class="bg-error text-white text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider hidden">
                    0 New
                </span>
                <?php endif; ?>

                <?php
                $quickMatchCount = 0;
                foreach ($pending_rows as $b) {
                    if ($b['is_active_quick_match']) $quickMatchCount++;
                }
                if ($quickMatchCount > 0):
                ?>
                <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-primary/10 text-primary uppercase tracking-wider">
                    <?php echo $quickMatchCount; ?> Quick Match
                </span>
                <?php endif; ?>
            </div>

            <div id="qm-live-grid" class="space-y-4">
                <?php
                include_once 'partials/booking_card.php';
                foreach ($pending_rows as $booking):
                    renderBookingCard($booking, 'pending');
                endforeach;
                ?>
            </div>
        </div>

    </section>

    <!-- ══════════════════════════════════════════
         NEW: AWAITING CLIENT CONFIRMATION
    ══════════════════════════════════════════ -->
    <?php if ($pending_conf_count > 0): ?>
    <section class="space-y-5">
        <div class="flex items-center gap-3">
            <div class="section-icon bg-amber-500/10">
                <span class="material-symbols-outlined text-amber-500 text-sm" style="font-variation-settings:'FILL' 1">hourglass_top</span>
            </div>
            <h2 class="text-xl font-bold tracking-tight text-on-surface">Awaiting Confirmation</h2>
            <span class="bg-amber-500 text-white text-[10px] font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider">
                <?php echo $pending_conf_count; ?>
            </span>
        </div>
        <p class="text-on-surface-variant text-sm -mt-2">
            You've confirmed payment on these jobs. Funds will be released once the client confirms completion.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
            <?php
            include_once 'partials/booking_card.php';
            foreach ($pending_conf_rows as $conf_job):
                renderBookingCard($conf_job, 'pending_confirmation');
            endforeach;
            ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── Recent History ─────────────────────── -->
    <section class="space-y-5">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="section-icon bg-surface-container">
                    <span class="material-symbols-outlined text-on-surface-variant text-sm">history</span>
                </div>
                <h2 class="text-xl font-bold tracking-tight text-on-surface">Recent History</h2>
            </div>
        </div>

        <?php if (count($history_rows) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
            <?php
            include_once 'partials/booking_card.php';
            foreach ($history_rows as $job):
                renderBookingCard($job, 'history');
            endforeach;
            ?>
        </div>
        <?php else: ?>
        <div class="bg-surface-container-lowest p-14 rounded-3xl border border-outline-variant/10 text-center shadow-[0_10px_40px_0_rgba(43,47,52,0.02)]">
            <div class="w-16 h-16 bg-surface-container rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-on-surface-variant" style="font-size:28px">schedule</span>
            </div>
            <h3 class="text-base font-bold text-on-surface mb-1">No history yet</h3>
            <p class="text-on-surface-variant text-sm">Completed jobs will appear here.</p>
        </div>
        <?php endif; ?>
    </section>

</main>

<!-- ══════════════════════════════════════════
     JAVASCRIPT (unchanged + schedule modal)
══════════════════════════════════════════ -->
<script src="js/booking-actions.js?v=<?php echo time(); ?>"></script>
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>

<script>
// ============================================
// ONESIGNAL PUSH NOTIFICATIONS
// ============================================
window.OneSignalDeferred = window.OneSignalDeferred || [];
OneSignalDeferred.push(async function(OneSignal) {
    try {
        await OneSignal.init({
            appId: "db85df82-c0c7-4f34-9510-febfa8e68f9c",
            allowLocalhostAsSecureOrigin: true,
            notifyButton: { enable: false }
        });

        <?php if(isset($_SESSION['user_id'])): ?>
        await OneSignal.login("<?php echo $_SESSION['user_id']; ?>");
        <?php endif; ?>

        OneSignal.Notifications.addEventListener("foregroundWillDisplay", (event) => {
            console.log("🔔 Notification received in foreground:", event);
            if (event.notification?.additionalData?.type === 'quick_match') {
                setTimeout(() => { window.location.reload(); }, 3000);
            }
        });

        OneSignal.Notifications.addEventListener("click", (event) => {
            console.log("🔔 Notification clicked:", event);
            window.location.reload();
        });

        console.log("✅ OneSignal initialized successfully");
    } catch (error) {
        console.error("❌ OneSignal initialization error:", error);
    }
});

// Mark job as done (dashboard-specific)
window.markJobDone = function(bookingId) {
    console.log('Marking job as done for booking:', bookingId);
    if (!confirm('Have you completed this job? The client will be notified to confirm completion.')) return;
    showLoading('Notifying client...');
    fetch('/abilisto/api/booking_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'mark_job_done', booking_id: bookingId })
    })
    .then(async response => {
        const text = await response.text();
        console.log('Mark job done response:', text);
        try { return JSON.parse(text); }
        catch (e) {
            console.error('Raw response:', text.substring(0, 500));
            throw new Error('Server returned invalid JSON. Check if API endpoint exists.');
        }
    })
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('success', data.message || 'Job marked as done');
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('error', data.message || 'Failed to mark job as done');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Mark job done error:', error);
        showNotification('error', error.message || 'Failed to mark job as done. Please try again.');
    });
};

function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-20 left-1/2 transform -translate-x-1/2 bg-primary text-white px-6 py-3 rounded-full shadow-lg z-50';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ============================================
// SCHEDULE MODAL
// ============================================
function openScheduleModal() {
    document.getElementById('schedule-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    updatePreview();
}

function closeScheduleModal() {
    document.getElementById('schedule-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

// Close on backdrop click
document.getElementById('schedule-modal').addEventListener('click', function(e) {
    if (e.target === this) closeScheduleModal();
});

// Timezone display
(function() {
    try {
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const now = new Date();
        const time = now.toLocaleTimeString('en-US', { timeZone: tz, hour: '2-digit', minute: '2-digit' });
        document.getElementById('tz-display').textContent = tz + ' — Local time: ' + time;
    } catch (e) {
        document.getElementById('tz-display').textContent = 'Timezone auto-detection unavailable.';
    }
})();

// AI preset chips
function applyPreset(id, label, days, timeStart, timeEnd) {
    // Highlight active chip
    document.querySelectorAll('.ai-chip').forEach(c => c.classList.remove('active'));
    event.currentTarget.classList.add('active');

    if (days === null) {
        // Custom — just clear and let the user build
        document.querySelectorAll('.day-pill input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('inp-time-start').value = '';
        document.getElementById('inp-time-end').value   = '';
    } else {
        // Set day checkboxes
        document.querySelectorAll('.day-pill input[type="checkbox"]').forEach(cb => {
            cb.checked = days.includes(parseInt(cb.value));
        });
        document.getElementById('inp-time-start').value = timeStart || '';
        document.getElementById('inp-time-end').value   = timeEnd   || '';
    }
    updatePreview();
}

// Live preview
function updatePreview() {
    const checked = [...document.querySelectorAll('.day-pill input:checked')].map(c => parseInt(c.value));
    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const ts = document.getElementById('inp-time-start').value;
    const te = document.getElementById('inp-time-end').value;

    if (checked.length === 0) {
        document.getElementById('schedule-preview').classList.add('hidden');
        document.getElementById('inp-label').value = '';
        return;
    }

    const dayStr = checked.map(d => dayNames[d]).join(', ');

    let timeStr = 'all day';
    if (ts && te) {
        const fmt = t => {
            const [h, m] = t.split(':').map(Number);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12  = h % 12 || 12;
            return `${h12}:${m.toString().padStart(2,'0')} ${ampm}`;
        };
        timeStr = fmt(ts) + ' – ' + fmt(te);
    }

    const label = dayStr + ', ' + timeStr;
    document.getElementById('preview-text').textContent = label;
    document.getElementById('inp-label').value = label;
    document.getElementById('schedule-preview').classList.remove('hidden');
}

// Bind preview updates to day pills and time inputs
document.querySelectorAll('.day-pill input, #inp-time-start, #inp-time-end').forEach(el => {
    el.addEventListener('change', updatePreview);
});

// ============================================
// GPS LOCATION UPDATER
// ============================================
function updateLocation() {
    console.log('📍 Attempting to get location...');
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                let lat = position.coords.latitude;
                let lng = position.coords.longitude;
                console.log('📍 Location obtained:', lat, lng);
                fetch('/api/update_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'lat=' + lat + '&lng=' + lng
                })
                .then(response => response.json())
                .then(data => { console.log('📍 Server response:', data); })
                .catch(error => { console.error('❌ Fetch error:', error); });
            },
            function(error) { console.error('❌ Geolocation error:', error); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    } else {
        console.error('❌ Geolocation not supported');
    }
}
updateLocation();
setInterval(updateLocation, 300000);

// ============================================
// QUICK MATCH TIMERS
// ============================================
function startQuickMatchTimers() {
    console.log('ℹ️ startQuickMatchTimers: boot handled by booking-actions.js IIFE');
}

const userId = <?php echo json_encode($worker_id); ?>;

document.addEventListener('DOMContentLoaded', function() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeInUp { from { transform: translate(-50%, 20px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        .animate-slideIn { animation: slideIn 0.3s ease forwards; }
        .animate-fadeInUp { animation: fadeInUp 0.3s ease forwards; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
    `;
    document.head.appendChild(style);

    // Show save-confirmation toast if redirected from schedule save
    <?php if (isset($_GET['schedule_saved'])): ?>
    showToast('✅ Schedule saved! Your availability will auto-update based on your rules.');
    <?php endif; ?>
});

// ============================================================
// ENHANCED LIVE POLLER (unchanged from original)
// ============================================================
(function startEnhancedPoller() {
    const POLL_MS = 5000;
    const seen = {
        known:     new Set(),
        expired:   new Set(),
        cancelled: new Set(),
        accepted:  new Set(),
    };

    function syncSeen() {
        seen.known.clear();
        document.querySelectorAll('[data-booking-id]').forEach(el => {
            const id = parseInt(el.dataset.bookingId);
            if (!isNaN(id)) seen.known.add(id);
        });
        document.querySelectorAll('[data-booking-expired="true"]').forEach(el  => seen.expired.add(parseInt(el.dataset.bookingId)));
        document.querySelectorAll('[data-booking-cancelled="true"]').forEach(el => seen.cancelled.add(parseInt(el.dataset.bookingId)));
        document.querySelectorAll('[data-booking-accepted="true"]').forEach(el  => seen.accepted.add(parseInt(el.dataset.bookingId)));
    }

    async function pollUpdates() {
        syncSeen();
        let data;
        try {
            const res = await fetch('/api/poll_pending.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : new URLSearchParams({
                    known_ids    : JSON.stringify([...seen.known]),
                    expired_ids  : JSON.stringify([...seen.expired]),
                    cancelled_ids: JSON.stringify([...seen.cancelled]),
                    accepted_ids : JSON.stringify([...seen.accepted]),
                }),
            });
            if (!res.ok) { console.warn('Poll HTTP', res.status); return; }
            data = await res.json();
        } catch (e) {
            console.warn('Poll network error:', e.message);
            return;
        }
        if (!data?.updates) return;
        const u = data.updates;
        (u.expired   || []).forEach(handleExpired);
        (u.cancelled || []).forEach(handleCancelled);
        (u.accepted  || []).forEach(handleAccepted);
        (u.new       || []).forEach(handleNewBooking);
    }

    function handleExpired(item) {
        const card = getCard(item.booking_id);
        if (!card || card.dataset.bookingExpired === 'true') return;
        card.dataset.bookingExpired = 'true';
        seen.expired.add(item.booking_id);
        const broadcastId = card.dataset.broadcastId;
        if (broadcastId && !card.classList.contains('qm-expired')) {
            const timerEl = card.querySelector(`.timer[data-broadcast]`);
            if (timerEl) { timerEl.textContent = 'EXPIRED'; timerEl.classList.add('text-slate-400'); }
            card.classList.add('qm-expired', 'expired-quick-match', 'opacity-60');
            disableButtons(card);
        }
        showStatusBanner(card, 'expired');
        showToastPoll('⏰ Quick Match Expired', 'This request timed out.', 'gray');
    }

    function handleCancelled(item) {
        const card = getCard(item.booking_id);
        if (!card || card.dataset.bookingCancelled === 'true') return;
        card.dataset.bookingCancelled = 'true';
        seen.cancelled.add(item.booking_id);
        showStatusBanner(card, 'cancelled', item.reason);
        disableButtons(card);
        const msg = item.reason === 'booking_cancelled'
            ? 'The client cancelled this booking.'
            : 'The client cancelled this quick-match request.';
        showToastPoll('❌ Booking Cancelled', msg, 'red');
        setTimeout(() => fadeRemoveCard(card), 5000);
    }

    function handleAccepted(item) {
        const card = getCard(item.booking_id);
        if (!card || card.dataset.bookingAccepted === 'true') return;
        if (item.accepted_by_me) return;
        card.dataset.bookingAccepted = 'true';
        seen.accepted.add(item.booking_id);
        showStatusBanner(card, 'accepted');
        disableButtons(card);
        showToastPoll('✓ Job Taken', 'Another worker accepted this request.', 'green');
        setTimeout(() => fadeRemoveCard(card), 5000);
    }

    function handleNewBooking(booking) {
        if (document.querySelector(`[data-booking-id="${booking.id}"]`)) return;
        const section = document.getElementById('pending-requests-section');
        if (section) section.classList.remove('hidden');
        const grid = document.getElementById('qm-live-grid');
        if (!grid) return;
        const tmp = document.createElement('div');
        tmp.innerHTML = booking.html;
        const newCard = tmp.firstElementChild;
        if (!newCard) return;
        newCard.setAttribute('data-booking-id', booking.id);
        grid.prepend(newCard);
        if (typeof window.initQuickMatchTimers === 'function') window.initQuickMatchTimers();
        if (booking.urgency === 'Emergency' || booking.seconds_remaining < 120) {
            showUrgentToast(booking);
        } else {
            showToastPoll('📢 New Quick Match', `${booking.urgency} request from ${booking.client_name}`, 'blue');
        }
        updatePendingBadge(+1);
    }

    function getCard(bookingId) {
        return document.querySelector(`[data-booking-id="${bookingId}"]`);
    }

    function disableButtons(card) {
        card.querySelectorAll('button, a[href]').forEach(el => {
            el.disabled = true;
            el.classList.add('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
        });
    }

    function showStatusBanner(card, type, reason) {
        if (card.querySelector('.status-banner')) return;
        const configs = {
            expired:   { bg:'#f3f6fd', border:'#aaadb4', color:'#585c62', icon:'schedule',     text:'This quick match has expired.' },
            cancelled: { bg:'#fef2f2', border:'#fecaca', color:'#b41340', icon:'cancel',        text: reason === 'booking_cancelled' ? 'The client cancelled this booking.' : 'The client cancelled this quick-match request.' },
            accepted:  { bg:'#f0fdf4', border:'#bbf7d0', color:'#00694c', icon:'check_circle',  text:'This job was accepted by another worker.' },
        };
        const cfg = configs[type] || configs.cancelled;
        const banner = document.createElement('div');
        banner.className = 'status-banner';
        banner.style.cssText = `display:flex;align-items:center;gap:8px;background:${cfg.bg};border:1px solid ${cfg.border};color:${cfg.color};font-weight:700;font-size:13px;padding:10px 14px;border-radius:12px;margin-bottom:12px;`;
        banner.innerHTML = `<span class="material-symbols-outlined" style="font-size:18px">${cfg.icon}</span>${cfg.text}`;
        card.style.position = 'relative';
        card.prepend(banner);
    }

    function fadeRemoveCard(card) {
        card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        card.style.opacity    = '0';
        card.style.transform  = 'scale(0.96)';
        setTimeout(() => {
            card.remove();
            updatePendingBadge(-1);
            const grid = document.getElementById('qm-live-grid');
            if (grid && grid.children.length === 0) {
                const section = document.getElementById('pending-requests-section');
                if (section) section.classList.add('hidden');
            }
        }, 550);
    }

    function updatePendingBadge(delta) {
        const badge = document.getElementById('pending-count-badge');
        if (!badge) return;
        const current = parseInt(badge.textContent) || 0;
        const next    = Math.max(0, current + delta);
        badge.textContent = `${next} New`;
        badge.classList.toggle('hidden', next === 0);
    }

    const COLOR_MAP = { blue:'#146af5', red:'#b41340', green:'#00694c', gray:'#585c62', orange:'#ea580c' };

    function showToastPoll(title, message, color = 'blue') {
        const bg = COLOR_MAP[color] || COLOR_MAP.blue;
        const el = document.createElement('div');
        el.style.cssText = `position:fixed;top:20px;right:20px;z-index:10050;background:${bg};color:#fff;padding:14px 18px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);font-family:'Plus Jakarta Sans',sans-serif;animation:baSlideIn .3s ease forwards;max-width:340px;`;
        el.innerHTML = `<div style="font-weight:700;font-size:14px">${title}</div><div style="font-size:12px;margin-top:3px;opacity:.9">${message}</div>`;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 6000);
    }

    function showUrgentToast(booking) {
        const secs = booking.seconds_remaining;
        const mins = Math.floor(secs / 60);
        const s    = (secs % 60).toString().padStart(2, '0');
        const el = document.createElement('div');
        el.style.cssText = `position:fixed;top:20px;right:20px;z-index:10050;background:#ea580c;color:#fff;padding:18px 20px;border-radius:16px;box-shadow:0 8px 32px rgba(234,88,12,.35);font-family:'Plus Jakarta Sans',sans-serif;animation:baSlideIn .3s ease forwards;max-width:360px;border-left:4px solid #fbbf24;`;
        el.innerHTML = `
            <div style="font-weight:800;font-size:16px">🚨 URGENT – New Quick Match!</div>
            <div style="font-size:13px;margin-top:4px;opacity:.95">${booking.urgency} · ${booking.client_name}</div>
            <div style="font-size:12px;margin-top:2px;opacity:.8">Expires in ${mins}:${s}</div>
            <button onclick="document.querySelector('[data-booking-id=\\'${booking.id}\\']')?.scrollIntoView({behavior:'smooth',block:'center'})"
                    style="margin-top:10px;width:100%;background:#fff;color:#ea580c;border:none;padding:8px;border-radius:8px;font-weight:700;cursor:pointer">
                ⚡ View Now
            </button>
        `;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 12000);
    }

    pollUpdates();
    const _timer = setInterval(pollUpdates, POLL_MS);
    window._stopPoller = () => clearInterval(_timer);
    console.log('✅ Enhanced poller active — polling every', POLL_MS, 'ms');
})();

// Debug quick match
setTimeout(function() {
    console.log('=== QUICK MATCH DEBUG ===');
    console.log('Quick match buttons:', document.querySelectorAll('[data-quick-match-accept="true"]').length);
    console.log('Quick match cards:', document.querySelectorAll('.quick-match').length);
    console.log('Accept buttons with class:', document.querySelectorAll('.quick-match-accept').length);
    console.log('Live polling active:', !!window._liveMatchInterval);
}, 1000);
</script>

<!-- ══════════════════════════════════════════
     FIRST-LOGIN TOUR
     Only renders for workers who haven't seen it yet.
     On finish/skip, POSTs to this same page with
     ?mark_tour_seen=1 so $conn marks has_seen_tour=1.
══════════════════════════════════════════ -->
<?php if (!$has_seen_tour): ?>
<script src="../includes/tour_engine.js"></script>
<script>
AbiTour.run({
    steps: [
        {
            target   : null,
            position : 'center',
            icon     : 'waving_hand',
            iconColor: '#146af5',
            title    : 'Welcome to Your Dashboard! 👋',
            body     : 'This quick tour highlights the key areas of your workspace. It only takes a minute — let\'s go!',
        },
        {
            target   : '#tour-stats',
            position : 'bottom',
            icon     : 'bar_chart',
            iconColor: '#146af5',
            title    : 'Your Stats at a Glance',
            body     : 'See your completed jobs, earned Listo Points, and current wallet balance all in one place.',
        },
        {
            target   : '#tour-schedule-btn',
            position : 'bottom',
            icon     : 'schedule',
            iconColor: '#16a34a',
            title    : 'Smart Availability Schedule',
            body     : 'Control when you\'re online. Set a recurring weekly schedule or toggle manually — clients can only book you when you\'re available.',
        },
        {
            target   : '#tour-active-jobs',
            position : 'top',
            icon     : 'work',
            iconColor: '#00694c',
            title    : 'Active Jobs',
            body     : 'Jobs you\'ve accepted live here. Hit "Mark as Done" once finished — the client gets notified to confirm.',
        },
        {
            target   : null,
            position : 'center',
            icon     : 'rocket_launch',
            iconColor: '#146af5',
            title    : 'You\'re All Set! 🚀',
            body     : 'Your dashboard updates in real time. New booking requests will pop up automatically — go get those jobs!',
        },
    ],
    onSkip: function () {
        // Mark seen — POST to this same page with a flag; PHP handles it at the top
        fetch('dashboard.php?mark_tour_seen=1', { method: 'POST' }).catch(() => {});

        // Show the unverified modal (if present) after the tour finishes/is
        // skipped — it was hidden on page load so it wouldn't overlap the tour.
        const unverifiedModal = document.getElementById('unverified-modal');
        if (unverifiedModal) unverifiedModal.style.display = '';
    }
});
</script>
<?php endif; ?>

</body>
</html>