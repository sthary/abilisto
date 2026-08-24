<?php
include '../includes/init_lang.php';
include '../db_connect.php';
require_once '../includes/fcm_sender.php';

// ============================================
// 2. SECURITY CHECKS
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

if (!isset($_SESSION['is_phone_verified']) || $_SESSION['is_phone_verified'] == 0) {
    $uid = $_SESSION['user_id'];
    $db_check_stmt = $conn->prepare("SELECT is_phone_verified FROM users WHERE id = ?");
    $db_check_stmt->execute([$uid]);
    $db_check = $db_check_stmt->fetch();
    if ($db_check && $db_check['is_phone_verified'] == 1) {
        $_SESSION['is_phone_verified'] = 1;
    } else {
        include '../includes/abilisto_page_shell.php';
        abilistoAlertPageOpen();
        include '../includes/abilisto_alert.php';
        echo "<script>
                abilistoAlert('🚫 Action Denied.\\n\\nYou must verify your phone number before you can book a worker.').then(function(){
                    window.location.href = '../auth/verify_otp.php?email=' + encodeURIComponent('{$_SESSION['email']}');
                });
              </script></body></html>";
        exit();
    }
}

if (!isset($_GET['worker_id']) || !is_numeric($_GET['worker_id'])) {
    header("Location: dashboard.php");
    exit();
}

$worker_id = intval($_GET['worker_id']);
$client_id = intval($_SESSION['user_id']);

// ── Client first-time tour, part 2 of 2 (continued from worker_details.php) ──
// Shows whenever has_seen_booking_tour is still false — whether they arrived via
// the worker_details.php tour (?tour=1) or landed here directly without
// ever seeing part 1. This is the leg that actually marks it seen.
// Uses its own flag, separate from has_seen_tour (owned by client/dashboard.php's
// pre-existing mandatory tour) — see the note in worker_details.php for why.
if (isset($_GET['mark_tour_seen']) && $_GET['mark_tour_seen'] == '1') {
    $conn->prepare("UPDATE users SET has_seen_booking_tour = TRUE WHERE id = ?")->execute([$client_id]);
}
$tour_chk = $conn->prepare("SELECT has_seen_booking_tour FROM users WHERE id = ?");
$tour_chk->execute([$client_id]);
$show_client_tour = empty($tour_chk->fetch()['has_seen_booking_tour']);

// ============================================
// 2.5 FETCH CLIENT LOCATION
// ============================================
if (!isset($_SESSION['latitude']) || $_SESSION['latitude'] == 0) {
    $client_location_stmt = $conn->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
    $client_location_stmt->execute([$client_id]);
    $client_location = $client_location_stmt->fetch();
    if ($client_location) {
        $_SESSION['latitude']  = $client_location['latitude'];
        $_SESSION['longitude'] = $client_location['longitude'];
        error_log("Client location loaded from DB: Lat={$client_location['latitude']}, Lng={$client_location['longitude']}");
    } else {
        error_log("Could not fetch client location for ID: $client_id");
    }
}
$clientLat = isset($_SESSION['latitude'])  ? floatval($_SESSION['latitude'])  : 0;
$clientLng = isset($_SESSION['longitude']) ? floatval($_SESSION['longitude']) : 0;

// ============================================
// CASH PAYMENT ELIGIBILITY
// ============================================
$cash_check_stmt = $conn->prepare("SELECT COALESCE(cash_enabled,TRUE) AS cash_enabled FROM users WHERE id=?");
$cash_check_stmt->execute([$client_id]);
$cash_check        = $cash_check_stmt->fetch();
$cash_admin_enabled = $cash_check ? (int)$cash_check['cash_enabled'] : 1;
$report_check_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM reports_worker
                                    WHERE reported_user_id=?
                                      AND reported_user_role='client'
                                      AND status='resolved'");
$report_check_stmt->execute([$client_id]);
$report_row        = $report_check_stmt->fetch() ?: ['cnt' => 0];
$cash_report_blocked = $report_row['cnt'] > 0;
$cash_enabled      = ($cash_admin_enabled == 1) && !$cash_report_blocked;

// ============================================
// AVAILABLE GREENLOOP VOUCHERS
// ============================================
$vouchers_stmt = $conn->prepare("
    SELECT rd.id, rd.promo_code, rw.reward_name, rw.reward_value
    FROM greenloop_redemptions rd
    JOIN greenloop_rewards rw ON rd.reward_id = rw.id
    WHERE rd.user_id = ?
      AND rd.status = 'active'
      AND rd.expires_at > NOW()
      AND rw.reward_type IN ('booking_discount','free_booking')
    ORDER BY rw.reward_value DESC
");
$vouchers_stmt->execute([$client_id]);
$available_vouchers = $vouchers_stmt->fetchAll();

// ============================================
// 3. FETCH WORKER DATA
// ============================================
$sql = "SELECT u.id, u.full_name, u.latitude, u.longitude, u.profile_pic,
               wp.service_category, wp.average_rating, wp.jobs_completed
        FROM users u
        LEFT JOIN worker_profiles wp ON u.id = wp.user_id
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$worker_id]);
$worker = $stmt->fetch();
if (!$worker) { header("Location: dashboard.php"); exit(); }

// ============================================
// 3a. FETCH ACTIVE / UPCOMING JOBS  ← NEW
// ============================================
$sql_active = "SELECT b.id, b.booking_date, b.urgency_level, b.status,
                      b.problem_desc, b.location_address,
                      u.full_name AS client_name
               FROM bookings b
               JOIN users u ON u.id = b.client_id
               WHERE b.worker_id = ?
                 AND b.status IN ('Pending','Accepted')
                 AND b.booking_date > NOW()
               ORDER BY b.booking_date ASC";
$stmt_active = $conn->prepare($sql_active);
$stmt_active->execute([$worker_id]);
$active_jobs = $stmt_active->fetchAll();

// ============================================
// 3b. BUSY SLOTS (datetime strings for JS)
// ============================================
$busy_slots = array_column($active_jobs, 'booking_date');

// ============================================
// 4. DISTANCE FUNCTION
// ============================================
function getDistance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) return 0;
    $theta = $lon1 - $lon2;
    $dist  = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist  = acos(max(-1, min(1, $dist)));
    return rad2deg($dist) * 60 * 1.1515 * 1.609344;
}

$initial_distance = round(getDistance($clientLat, $clientLng, $worker['latitude'], $worker['longitude']), 2);
$initial_fee      = round(20 + ($initial_distance * 5));

// ============================================
// 5. PROCESS BOOKING SUBMISSION
// ============================================
if (isset($_POST['book_btn'])) {
    // Buffered so the two success branches below (which exit() with nothing
    // else ever rendered) can be wrapped in a real, minimal HTML document
    // with a viewport meta tag — without it, mobile browsers fall back to a
    // ~980px desktop viewport for that response and render the alert modal
    // tiny/zoomed out. The other branches (validation error, DB error) fall
    // through to the real page below as before, so their buffer is just
    // flushed through unwrapped.
    ob_start();
    include '../includes/abilisto_alert.php';
    if (empty($_POST['service_date']) || empty($_POST['problem_desc']) || empty($_POST['urgency'])) {
        echo "<script>alert('{$lang['error_fill_all_fields']}');</script>";
        echo ob_get_clean();
    } else {
        $problem_desc     = trim($_POST['problem_desc']);
        $booking_datetime = $_POST['service_date'];
        $urgency          = $_POST['urgency'];
        $payment_method   = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'Cash';

        if ($payment_method === 'Cash' && !$cash_enabled) { $payment_method = 'PayMongo'; }

        $urgency_fees = ['Normal' => 0, 'High' => 15, 'Emergency' => 25];
        $urgency_fee  = $urgency_fees[$urgency] ?? 0;

        $booking_lat = isset($_POST['latitude'])  && !empty($_POST['latitude'])  ? floatval($_POST['latitude'])  : 0;
        $booking_lng = isset($_POST['longitude']) && !empty($_POST['longitude']) ? floatval($_POST['longitude']) : 0;
        $distance    = ($booking_lat && $booking_lng)
                     ? round(getDistance($booking_lat, $booking_lng, $worker['latitude'], $worker['longitude']), 2)
                     : $initial_distance;

        $subtotal         = 20 + ($distance * 5) + $urgency_fee;
        $calculated_fee   = ($payment_method === 'PayMongo') ? round($subtotal * 0.9, 2) : $subtotal;
        $discount_amount  = $subtotal - $calculated_fee;
        $client_name      = $_SESSION['full_name'];

        // Re-validate the voucher server-side — never trust the submitted ID alone.
        $voucher_id       = isset($_POST['voucher_id']) ? intval($_POST['voucher_id']) : 0;
        $applied_voucher  = null;
        $voucher_discount = 0;
        if ($voucher_id > 0) {
            $v_stmt = $conn->prepare("
                SELECT rd.id, rd.promo_code, rw.reward_name, rw.reward_value
                FROM greenloop_redemptions rd
                JOIN greenloop_rewards rw ON rd.reward_id = rw.id
                WHERE rd.id = ? AND rd.user_id = ? AND rd.status = 'active'
                  AND rd.expires_at > NOW()
                  AND rw.reward_type IN ('booking_discount','free_booking')
            ");
            $v_stmt->execute([$voucher_id, $client_id]);
            $applied_voucher = $v_stmt->fetch();
            if ($applied_voucher) {
                $voucher_discount = min((float)$applied_voucher['reward_value'], $calculated_fee);
                $calculated_fee   = round($calculated_fee - $voucher_discount, 2);
            }
        }

        $urgency_icons  = ['Emergency' => '🚨', 'High' => '⚠️', 'Normal' => '📋'];
        $urgency_labels = ['Emergency' => 'EMERGENCY', 'High' => 'HIGH PRIORITY', 'Normal' => 'NORMAL'];
        $urgency_icon   = $urgency_icons[$urgency]  ?? '📋';
        $urgency_label  = $urgency_labels[$urgency] ?? 'NORMAL';

        $insert_stmt = $conn->prepare("INSERT INTO bookings (
            client_id, worker_id, problem_desc, booking_date, urgency_level,
            payment_method, payment_status, status, calculated_fee, voucher_discount,
            latitude, longitude, location_address, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, 'Pending', 'Pending', ?, ?,
            ?, ?, ?, NOW()
        )");

        $location_address = $_POST['location_address'] ?? '';

        try {
            $insert_stmt->execute([
                $client_id, $worker_id, $problem_desc, $booking_datetime, $urgency,
                $payment_method, $calculated_fee, $voucher_discount,
                ($booking_lat ?: null), ($booking_lng ?: null), $location_address
            ]);
            $booking_id     = $conn->lastInsertId('bookings_id_seq');

            // Verify the amount was saved
            $check_stmt = $conn->prepare("SELECT calculated_fee FROM bookings WHERE id = ?");
            $check_stmt->execute([$booking_id]);
            $check_row = $check_stmt->fetch();
            error_log("✅ Booking #$booking_id created with fee: " . $check_row['calculated_fee']);

            // Mark the voucher used — the status='active' guard makes this safe
            // against a double-submit race (a repeat attempt matches zero rows).
            if ($applied_voucher) {
                $mark_stmt = $conn->prepare("UPDATE greenloop_redemptions SET status='used', used_at=NOW(), used_booking_id=? WHERE id=? AND user_id=? AND status='active'");
                $mark_stmt->execute([$booking_id, $applied_voucher['id'], $client_id]);
            }

            $formatted_date = date('F j, Y \a\t g:i A', strtotime($booking_datetime));
            $discount_text  = ($discount_amount > 0) ? " (₱{$discount_amount} GCash discount applied!)" : "";
            if ($applied_voucher) {
                $discount_text .= " (voucher {$applied_voucher['promo_code']}: -₱{$voucher_discount})";
            }

            // Push notification
            try {
                $fcm    = new FCMv1();
                $result = $fcm->send(
                    $worker_id,
                    "{$urgency_icon} New Booking: {$urgency_label}",
                    "{$client_name} needs help on {$formatted_date}. Issue: " . substr($problem_desc, 0, 60) . (strlen($problem_desc) > 60 ? '...' : ''),
                    ['type' => 'new_booking', 'booking_id' => (string)$booking_id, 'urgency' => (string)$urgency,
                     'client_name' => (string)$client_name, 'url' => '/abilisto/worker/dashboard.php']
                );
                error_log("📨 PUSH NOTIFICATION - Worker: $worker_id, Booking: $booking_id, Result: " .
                    ($result['success'] ? 'SUCCESS' : 'FAILED: ' . (is_array($result['error']) ? json_encode($result['error']) : ($result['error'] ?? 'Unknown'))));
            } catch (Exception $e) { error_log("❌ Push notification error: " . $e->getMessage()); }

            // In-app notifications
            $notif_msg  = "{$urgency_icon} {$urgency_label} Booking Request{$discount_text}!\n\n";
            $notif_msg .= "{$client_name} needs your help on {$formatted_date}\n";
            $notif_msg .= "Issue: " . substr($problem_desc, 0, 50) . (strlen($problem_desc) > 50 ? '...' : '');
            $notif_link = "../worker/dashboard.php";

            if (function_exists('sendNotification')) {
                $result = sendNotification($conn, $worker_id, $notif_msg, $notif_link);
                error_log("📨 IN-APP NOTIFICATION - Worker: $worker_id, Booking: $booking_id, Result: " . ($result ? 'SUCCESS' : 'FAILED'));
            } else {
                $fallback_stmt = $conn->prepare("INSERT INTO notifications (user_id,message,link,created_at,is_read) VALUES (?,?,?,NOW(),0)");
                $fallback_stmt->execute([$worker_id, $notif_msg, $notif_link]);
                error_log("✅ Fallback notification sent for booking #$booking_id");
            }

            $voucher_notif_text = $applied_voucher ? " Voucher {$applied_voucher['promo_code']} applied: -₱{$voucher_discount}." : "";
            $client_notif = "✅ Booking request sent to {$worker['full_name']} for {$formatted_date}" . (($discount_amount > 0) ? " with ₱{$discount_amount} GCash discount!" : "") . $voucher_notif_text;
            if (function_exists('sendNotification')) { sendNotification($conn, $client_id, $client_notif, "../client/my_bookings.php"); }

            $voucher_js_msg = $applied_voucher ? "\\nVoucher {$applied_voucher['promo_code']} applied: -₱{$voucher_discount}" : "";
            if ($payment_method === 'PayMongo') {
                echo "<script>abilistoAlert('✅ Booking Created with 10% GCash Discount!\\n\\nOriginal: ₱{$subtotal}\\nDiscounted: ₱{$calculated_fee}\\nYou saved: ₱{$discount_amount}{$voucher_js_msg}\\n\\nYou will now be redirected to payment.', 'success').then(function(){ window.location.href='process_payment_paymongo.php?booking_id=$booking_id&amount=$calculated_fee'; });</script>";
            } else {
                $cash_msg = ($discount_amount > 0) ? "Note: You could have saved ₱{$discount_amount} by paying with GCash!" : "";
                echo "<script>abilistoAlert('✅ Booking Request Sent Successfully!\\n\\n{$urgency_icon} Urgency: {$urgency_label}\\nTotal: ₱{$calculated_fee}{$voucher_js_msg}\\n{$cash_msg}\\nThe worker will be notified immediately.', 'success').then(function(){ window.location.href='my_bookings.php'; });</script>";
            }
            $__booking_alert_buf = ob_get_clean();
            include '../includes/abilisto_page_shell.php';
            abilistoAlertPageOpen();
            echo $__booking_alert_buf . '</body></html>';
            exit();
        } catch (PDOException $e) {
            error_log("❌ Booking insert failed: " . $e->getMessage());
            echo "<script>alert('DATABASE ERROR: Unable to create booking.\\n\\nError: " . addslashes($e->getMessage()) . "');</script>";
            echo ob_get_clean();
        }
    }
}

// ============================================
// HELPERS
// ============================================
$urgency_fees = ['Normal' => 0, 'High' => 15, 'Emergency' => 25];

$avatar = !empty($worker['profile_pic'])
    ? "../uploads/profiles/" . $worker['profile_pic']
    : "https://ui-avatars.com/api/?name=" . urlencode($worker['full_name']) . "&background=3B82F6&color=fff&size=128&bold=true";

function getInitials($name) {
    $words = explode(' ', $name); $initials = '';
    foreach ($words as $w) { $initials .= strtoupper(substr($w, 0, 1)); }
    return substr($initials, 0, 2);
}
$initials = getInitials($worker['full_name']);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang']; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $lang['book_page_title']; ?> | Abilisto</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet"/>
    <link href="../includes/tour_engine.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <style type="text/tailwindcss">
        :root { --primary-blue:#146af5; --accent-blue:#00C2FF; --bg-light:#F8FAFC; }
        body { font-family:'Plus Jakarta Sans',sans-serif; }
        .glass-card { background:rgba(255,255,255,.15); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); border:1px solid rgba(255,255,255,.2); }
        .gradient-bg { background:linear-gradient(135deg,var(--primary-blue) 0%,var(--accent-blue) 100%); }
        .soft-shadow { box-shadow:0 20px 25px -5px rgba(0,0,0,.05),0 10px 10px -5px rgba(0,0,0,.02); }
        .no-scrollbar::-webkit-scrollbar{display:none} .no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
        #map{height:300px;width:100%;z-index:1;border-radius:16px}
        @keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
        @keyframes slideOutRight{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}
        @keyframes shake{0%,100%{transform:translateX(0)}10%,30%,50%,70%,90%{transform:translateX(-5px)}20%,40%,60%,80%{transform:translateX(5px)}}
        .shake-animation{animation:shake .5s ease}
        .flatpickr-calendar{background:white!important;border-radius:16px!important;box-shadow:0 20px 25px -5px rgba(0,0,0,.1)!important;border:1px solid #e2e8f0!important}
        .dark .flatpickr-calendar{background:#1e293b!important;border-color:#334155!important;color:white!important}
        .flatpickr-day.selected{background:#146af5!important;border-color:#146af5!important}
        .flatpickr-day.today{border-color:#146af5!important}
        /* Suggestion banner */
        #availabilityBanner{transition:all .3s ease}
    </style>

    <script>
        // Apply the site-wide dark-mode preference before paint — this page
        // doesn't include includes/navbar.php (which is where the theme
        // init normally happens), so it never picked up the stored choice.
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
        tailwind.config = {
            darkMode:"class",
            theme:{ extend:{
                colors:{ primary:"#146af5","background-light":"#F8FAFC","background-dark":"#0F172A" },
                borderRadius:{ DEFAULT:"1rem",xl:"1.5rem","2xl":"2rem" }
            }}
        };
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark min-h-screen transition-colors duration-300">
<?php include '../includes/abilisto_alert.php'; ?>

<div class="max-w-4xl mx-auto px-4 py-6 md:py-12">

    <!-- Worker Header -->
    <header class="gradient-bg rounded-2xl md:rounded-3xl p-4 md:p-8 text-white relative overflow-hidden soft-shadow mb-6 md:mb-8">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6">
            <div class="flex items-center gap-4 md:gap-5">
                <div class="relative flex-shrink-0">
                    <?php if (!empty($worker['profile_pic']) && file_exists("../uploads/profiles/".$worker['profile_pic'])): ?>
                        <img src="<?php echo $avatar; ?>" class="w-16 h-16 md:w-24 md:h-24 rounded-full border-2 md:border-4 border-white/20 object-cover" alt="<?php echo $worker['full_name']; ?>">
                    <?php else: ?>
                        <div class="w-16 h-16 md:w-24 md:h-24 rounded-full border-2 md:border-4 border-white/20 bg-white/20 flex items-center justify-center text-3xl md:text-4xl font-bold"><?php echo $initials; ?></div>
                    <?php endif; ?>
                    <div class="absolute bottom-0 right-0 bg-green-400 w-4 h-4 md:w-5 md:h-5 rounded-full border-2 border-primary"></div>
                </div>
                <div>
                    <h1 class="text-xl md:text-3xl font-extrabold mb-1 md:mb-2 leading-tight"><?php echo htmlspecialchars($worker['full_name']); ?></h1>
                    <div class="flex flex-wrap gap-1.5 md:gap-2">
                        <?php if ($worker['average_rating'] > 0): ?>
                        <span class="glass-card px-2 md:px-3 py-0.5 md:py-1 rounded-full text-[10px] md:text-xs font-semibold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px] md:text-sm">star</span>
                            <?php echo number_format($worker['average_rating'], 1); ?>
                        </span>
                        <?php endif; ?>
                        <span class="glass-card px-2 md:px-3 py-0.5 md:py-1 rounded-full text-[10px] md:text-xs font-semibold flex items-center gap-1" id="distanceDisplay">
                            <span class="material-symbols-outlined text-[14px] md:text-sm">location_on</span>
                            <?php echo $initial_distance; ?> km
                        </span>
                        <span class="glass-card px-2 md:px-3 py-0.5 md:py-1 rounded-full text-[10px] md:text-xs font-semibold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px] md:text-sm">bolt</span>
                            <?php echo $worker['service_category'] ?? 'General'; ?>
                        </span>
                        <?php if (!empty($active_jobs)): ?>
                        <span class="glass-card px-2 md:px-3 py-0.5 md:py-1 rounded-full text-[10px] md:text-xs font-semibold flex items-center gap-1 bg-amber-400/20 text-amber-100">
                            <span class="material-symbols-outlined text-[14px] md:text-sm">work</span>
                            <?php echo count($active_jobs); ?> active job<?php echo count($active_jobs) > 1 ? 's' : ''; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div id="tour-price-card" class="glass-card p-3 md:p-5 rounded-xl md:rounded-2xl min-w-full md:min-w-[200px]">
                <p class="text-white/70 text-[10px] md:text-xs font-medium uppercase tracking-wider mb-0.5 md:mb-1">Estimated Total</p>
                <div class="text-2xl md:text-3xl font-extrabold mb-2 md:mb-3" id="priceDisplay">₱<?php echo $initial_fee; ?></div>
                <div class="space-y-1 text-[10px] md:text-xs text-white/80 border-t border-white/10 pt-2 md:pt-3" id="urgencyNote">
                    <div class="flex justify-between"><span>Travel (<?php echo $initial_distance; ?>km)</span><span>+₱<?php echo round($initial_distance * 5, 2); ?></span></div>
                    <div class="flex justify-between"><span>Service Fee</span><span>+₱0</span></div>
                </div>
            </div>
        </div>
        <div class="absolute -top-12 -right-12 w-48 h-48 bg-white/10 rounded-full blur-3xl"></div>
    </header>

    <!-- ============================================================ -->
    <!-- ACTIVE JOBS SECTION                                           -->
    <!-- ============================================================ -->
    <?php if (!empty($active_jobs)): ?>
    <section class="bg-white dark:bg-slate-900 rounded-2xl p-5 md:p-6 soft-shadow mb-6 md:mb-8 border border-slate-100 dark:border-slate-800">
        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-500">event_busy</span>
                </div>
                <div>
                    <h2 class="text-base font-bold text-slate-800 dark:text-slate-100">Worker's Busy Schedule</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Avoid booking during these times</p>
                </div>
            </div>
            <span class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-xs font-bold px-3 py-1 rounded-full">
                <?php echo count($active_jobs); ?> upcoming
            </span>
        </div>

        <div class="overflow-x-auto no-scrollbar -mx-1 px-1">
            <div class="flex flex-nowrap gap-3 pb-2">
                <?php foreach ($active_jobs as $job):
                    $jobDate = new DateTime($job['booking_date']);
                ?>
                <div class="flex-none flex flex-col gap-1 p-3 rounded-xl border border-red-100 dark:border-red-900/30 bg-red-50/30 dark:bg-red-900/10 min-w-[130px]">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-red-400 text-base">calendar_today</span>
                        <span class="text-xs font-bold text-red-700 dark:text-red-300 whitespace-nowrap">
                            <?php echo $jobDate->format('M j, Y'); ?>
                        </span>
                    </div>
                    <span class="text-[11px] text-red-500 dark:text-red-400 whitespace-nowrap pl-6">
                        <?php echo $jobDate->format('g:i A'); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Availability Suggestion Banner (shown dynamically by JS) -->
    <div id="availabilityBanner" class="hidden mb-6 p-4 rounded-2xl bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 flex items-start gap-3">
        <span class="material-symbols-outlined text-amber-500 flex-shrink-0 mt-0.5">schedule</span>
        <div class="flex-1">
            <p class="text-sm font-bold text-amber-800 dark:text-amber-200">This time slot is too close to an active booking!</p>
            <p id="availabilityMsg" class="text-xs text-amber-700 dark:text-amber-300 mt-1"></p>
            <button id="jumpToNextSlot" class="mt-2 text-xs font-bold text-amber-800 dark:text-amber-200 underline hover:no-underline flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">jump_to_element</span>
                Jump to next available slot
            </button>
        </div>
        <button onclick="document.getElementById('availabilityBanner').classList.add('hidden')" class="flex-shrink-0 text-amber-500 hover:text-amber-700">
            <span class="material-symbols-outlined text-base">close</span>
        </button>
    </div>

    <!-- Booking Form -->
    <form action="" method="POST" id="bookingForm">
        <input type="hidden" name="worker_id" value="<?php echo $worker_id; ?>">
        <input type="hidden" id="feeInput" name="calculated_fee" value="<?php echo $initial_fee; ?>">

        <div class="space-y-6 md:space-y-8">
            <!-- Date and Urgency -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Service Date -->
                <div class="space-y-3">
                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                        <span class="material-symbols-outlined text-primary text-xl">today</span>
                        When do you need service? <span class="text-red-500">*</span>
                    </label>
                    <div class="relative group">
                        <input type="text"
                               id="serviceDateInput"
                               name="service_date"
                               class="w-full h-14 pl-12 pr-4 rounded-xl border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all text-slate-900 dark:text-white"
                               placeholder="Select date and time"
                               required
                               readonly>
                        <span class="material-symbols-outlined absolute left-4 top-4 text-slate-400 group-focus-within:text-primary transition-colors">calendar_month</span>
                    </div>
                    <!-- Conflict error -->
                    <div id="dateError" class="hidden text-red-500 text-sm flex items-center gap-2 mt-2 p-3 bg-red-50 dark:bg-red-900/20 rounded-xl">
                        <span class="material-symbols-outlined text-base">error</span>
                        <span></span>
                    </div>
                </div>

                <!-- Urgency Level -->
                <div class="space-y-3">
                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                        <span class="material-symbols-outlined text-primary text-xl">priority_high</span>
                        Urgency Level <span class="text-red-500">*</span>
                    </label>
                    <div class="relative group">
                        <select id="urgencySelect" name="urgency" class="w-full h-14 pl-12 pr-10 rounded-xl border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:ring-2 focus:ring-primary/20 focus:border-primary appearance-none transition-all text-slate-900 dark:text-white" onchange="updatePrice()" required>
                            <option value="Normal">📋 Normal Response (+₱0)</option>
                            <option value="High">⚠️ High Priority (+₱15)</option>
                            <option value="Emergency">🚨 Emergency Response (+₱25)</option>
                        </select>
                        <span class="material-symbols-outlined absolute left-4 top-4 text-slate-400">speed</span>
                        <span class="material-symbols-outlined absolute right-4 top-4 text-slate-400 pointer-events-none">expand_more</span>
                    </div>
                </div>
            </div>

            <!-- Problem Description -->
            <div class="space-y-3">
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                    <span class="material-symbols-outlined text-primary text-xl">description</span>
                    Describe the Problem <span class="text-red-500">*</span>
                </label>
                <textarea name="problem_desc"
                          class="w-full p-5 rounded-2xl border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all text-slate-900 dark:text-white resize-none"
                          placeholder="Please describe what needs to be fixed in detail..."
                          rows="4" required></textarea>
            </div>

            <!-- Service Location -->
            <div class="space-y-4">
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                    <span class="material-symbols-outlined text-primary text-xl">near_me</span>
                    Service Location <span class="text-red-500">*</span>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button type="button" id="useCurrentLocation" class="flex items-center justify-center gap-3 h-14 rounded-xl border-2 border-primary/20 hover:border-primary hover:bg-primary/5 transition-all text-primary font-bold group">
                        <span class="material-symbols-outlined transition-transform group-hover:-translate-y-1">my_location</span>
                        Use My Current Location
                    </button>
                    <button type="button" id="pinOnMap" class="flex items-center justify-center gap-3 h-14 rounded-xl border-2 border-emerald-500/20 hover:border-emerald-500 hover:bg-emerald-500/5 transition-all text-emerald-600 font-bold group">
                        <span class="material-symbols-outlined transition-transform group-hover:scale-110">location_on</span>
                        Pin on Map
                    </button>
                </div>

                <div id="mapContainer" class="hidden space-y-4 mt-4">
                    <div id="map" class="w-full h-[300px] rounded-2xl border-2 border-slate-200 dark:border-slate-700"></div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">info</span>
                        Click on the map to set the exact location, or drag the marker
                    </p>
                </div>

                <div id="selectedLocationInfo" class="hidden bg-slate-50 dark:bg-slate-800/50 rounded-2xl p-3 md:p-4 border border-slate-200 dark:border-slate-700">
                    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                        <div class="flex items-center gap-3 sm:gap-4">
                            <div class="w-9 h-9 md:w-10 md:h-10 rounded-xl bg-primary/10 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg">location_on</span>
                            </div>
                            <div class="sm:hidden font-bold text-sm text-slate-900 dark:text-white">📍 Location Pin Dropped!</div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="hidden sm:block font-bold text-sm text-slate-900 dark:text-white mb-1">📍 Location Pin Dropped!</div>
                            <div id="locationCoordinates" class="text-xs text-slate-500 dark:text-slate-400 mb-2 flex items-center gap-1"></div>
                            <div class="mt-1">
                                <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1 block">Address / Landmarks:</label>
                                <input type="text" id="location_address" name="location_address"
                                       class="w-full p-2.5 md:p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm"
                                       placeholder="e.g., Purok 3, near the basketball court" value="">
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="latitude"  id="latitude"  value="<?php echo $clientLat ?: ''; ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?php echo $clientLng ?: ''; ?>">
            </div>

            <!-- Payment Method -->
            <div id="tour-payment-method" class="space-y-4">
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                    <span class="material-symbols-outlined text-primary text-xl">payments</span>
                    Payment Method <span class="text-red-500">*</span>
                </label>

                <?php if (!$cash_enabled): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm">
                    <span class="material-symbols-outlined text-base flex-shrink-0">money_off</span>
                    <span><?php echo $cash_report_blocked ? 'Cash payment is unavailable due to a resolved report on your account.' : 'Cash payment has been disabled for your account by the administrator.'; ?></span>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="relative group <?php echo $cash_enabled ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed pointer-events-none'; ?>">
                        <input type="radio" class="peer hidden" id="cash" name="payment_method" value="Cash"
                               <?php echo !$cash_enabled ? 'disabled' : ''; ?>
                               <?php echo $cash_enabled  ? 'checked' : ''; ?>
                               onchange="updatePrice()">
                        <label for="cash" class="flex items-center gap-4 p-4 md:p-5 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 peer-checked:border-primary peer-checked:bg-primary/5 transition-all block <?php echo $cash_enabled ? 'cursor-pointer' : 'cursor-not-allowed'; ?>">
                            <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-blue-500 text-2xl md:text-3xl">account_balance_wallet</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm md:text-base text-slate-900 dark:text-slate-100">Cash on Service</h3>
                                <p class="text-primary text-lg md:text-xl font-extrabold" id="cashText">₱<?php echo $initial_fee; ?></p>
                                <?php if (!$cash_enabled): ?><p class="text-red-500 text-[10px] font-semibold mt-0.5">Not available</p><?php endif; ?>
                            </div>
                        </label>
                    </div>

                    <div class="relative group cursor-pointer">
                        <input type="radio" class="peer hidden" id="gcash" name="payment_method" value="PayMongo"
                               <?php echo !$cash_enabled ? 'checked' : ''; ?>
                               onchange="updatePrice()">
                        <label for="gcash" class="flex items-center gap-4 p-4 md:p-5 rounded-2xl border-2 border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 peer-checked:border-emerald-500 peer-checked:bg-emerald-500/5 transition-all block cursor-pointer">
                            <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-emerald-500 text-2xl md:text-3xl">smartphone</span>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <h3 class="font-bold text-sm md:text-base text-slate-900 dark:text-slate-100">GCash / Maya / Card</h3>
                                    <span class="bg-emerald-500 text-white text-[8px] md:text-[10px] font-black px-2 py-0.5 rounded-full shadow-lg shadow-emerald-200 dark:shadow-none">SAVE 10%</span>
                                </div>
                                <div class="flex items-center gap-3" id="onlineText">
                                    <p class="text-slate-400 line-through text-xs md:text-sm">₱<?php echo $initial_fee; ?></p>
                                    <p class="text-emerald-600 text-lg md:text-xl font-extrabold">₱<?php echo round($initial_fee * 0.9, 2); ?></p>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- GreenLoop Voucher -->
            <div class="space-y-3">
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-200">
                    <span class="material-symbols-outlined text-primary text-xl">redeem</span>
                    Apply a Voucher (optional)
                </label>
                <?php if (!empty($available_vouchers)): ?>
                <div class="relative group">
                    <select id="voucherSelect" name="voucher_id" class="w-full h-14 pl-12 pr-10 rounded-xl border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 focus:ring-2 focus:ring-primary/20 focus:border-primary appearance-none transition-all text-slate-900 dark:text-white" onchange="updatePrice()">
                        <option value="0">No voucher</option>
                        <?php foreach ($available_vouchers as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" data-value="<?php echo (float)$v['reward_value']; ?>">
                            <?php echo htmlspecialchars($v['reward_name']); ?> — ₱<?php echo number_format($v['reward_value'], 0); ?> off (<?php echo htmlspecialchars($v['promo_code']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="material-symbols-outlined absolute left-4 top-4 text-slate-400">redeem</span>
                    <span class="material-symbols-outlined absolute right-4 top-4 text-slate-400 pointer-events-none">expand_more</span>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 text-sm">
                    <span class="material-symbols-outlined text-base flex-shrink-0">info</span>
                    <span>No vouchers available. <a href="../greenloop/greenloop_wallet.php" class="text-primary font-bold hover:underline">Earn Green Coins</a> to redeem rewards!</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- T&C -->
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 md:p-5 rounded-2xl flex items-start gap-4 border border-slate-100 dark:border-slate-800">
                <input type="checkbox" class="mt-1 rounded text-primary focus:ring-primary/20 bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700" required>
                <p class="text-[11px] md:text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                    I agree to the <a class="text-primary font-bold hover:underline" href="#">terms and conditions</a>. I understand that booking fees are estimates and final price may vary. Cancellations made less than 2 hours before scheduled time may incur a fee.
                </p>
            </div>

            <!-- Submit -->
            <div class="space-y-4 md:space-y-6 pt-2">
                <button type="submit" name="book_btn" id="submitBtn" class="w-full h-16 gradient-bg rounded-2xl text-white font-extrabold text-base md:text-lg flex items-center justify-center gap-3 shadow-xl shadow-primary/30 hover:shadow-2xl hover:shadow-primary/40 transition-all active:scale-[0.98] group">
                    <span class="material-symbols-outlined group-hover:translate-x-1 group-hover:-translate-y-1 transition-transform">send</span>
                    Confirm Booking Request
                </button>
                <a href="worker_details.php?id=<?php echo $worker_id; ?>" class="flex items-center justify-center gap-2 text-slate-500 dark:text-slate-400 font-bold hover:text-primary transition-colors py-2 text-sm md:text-base">
                    <span class="material-symbols-outlined text-lg">arrow_back</span>
                    Back to Worker Profile
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

<script>
// ============================================
// PHP → JS DATA
// ============================================
var busySlots  = <?php echo json_encode($busy_slots); ?>;         // raw datetime strings
var urgencyFees = <?php echo json_encode($urgency_fees); ?>;
var workerLat  = <?php echo $worker['latitude']  ?: 0; ?>;
var workerLng  = <?php echo $worker['longitude'] ?: 0; ?>;
var cashEnabled = <?php echo $cash_enabled ? 'true' : 'false'; ?>;

// ============================================
// BUSY-SLOT RANGE HELPERS
// ============================================
// Each busy slot blocks ±3 hours (10800 seconds = 3h in ms)
var BUFFER_MS = 3 * 60 * 60 * 1000;

// Convert busy slots to {from, to, center, label} objects
var blockedRanges = busySlots.map(function(s) {
    var center = new Date(s);
    return {
        center: center,
        from:   new Date(center.getTime() - BUFFER_MS),
        to:     new Date(center.getTime() + BUFFER_MS),
        label:  center.toLocaleString('en-PH', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' })
    };
});

// Returns true if the given Date falls inside any blocked range
function isInBlockedRange(date) {
    for (var i = 0; i < blockedRanges.length; i++) {
        if (date >= blockedRanges[i].from && date <= blockedRanges[i].to) return true;
    }
    return false;
}

// Returns the conflicting range object (or null)
function getConflict(date) {
    for (var i = 0; i < blockedRanges.length; i++) {
        if (date >= blockedRanges[i].from && date <= blockedRanges[i].to) return blockedRanges[i];
    }
    return null;
}

// Find next available slot (after all blocked ranges, stepping 15 min forward)
function findNextAvailableSlot(fromDate) {
    var candidate = new Date(Math.max(fromDate.getTime(), Date.now() + 60 * 60 * 1000));
    // round up to nearest 15 min
    var mins = candidate.getMinutes();
    var roundedMins = Math.ceil(mins / 15) * 15;
    candidate.setMinutes(roundedMins, 0, 0);

    var maxSearch = 30 * 24 * 60 * 60 * 1000; // look at most 30 days ahead
    var step = 15 * 60 * 1000; // 15 min increments
    var limit = new Date(candidate.getTime() + maxSearch);

    while (candidate < limit) {
        if (!isInBlockedRange(candidate)) return candidate;
        candidate = new Date(candidate.getTime() + step);
    }
    return null;
}

// Build Flatpickr disable ranges (function-based, more reliable than objects)
function buildFlatpickrDisable() {
    if (blockedRanges.length === 0) return [];
    // Flatpickr accepts a function in the disable array
    return [function(date) {
        // Flatpickr passes date at midnight; we need to check if the whole day
        // contains any part of a blocked range. However since we have times,
        // we compare at the day level only for day-cells. The fine-grained check
        // happens in the onChange callback.
        //
        // To block individual day cells that are entirely inside a blocked range:
        var dayStart = new Date(date); dayStart.setHours(0,0,0,0);
        var dayEnd   = new Date(date); dayEnd.setHours(23,59,59,999);
        for (var i = 0; i < blockedRanges.length; i++) {
            // If the entire day is blocked, disable it
            if (dayStart >= blockedRanges[i].from && dayEnd <= blockedRanges[i].to) return true;
        }
        return false;
    }];
}

// ============================================
// FLATPICKR INIT
// ============================================
var fpInstance = flatpickr("#serviceDateInput", {
    enableTime: true,
    dateFormat: "Y-m-d H:i:s",
    altInput:   true,
    altFormat:  "F j, Y at h:i K",
    minDate:    "today",
    time_24hr:  false,
    minuteIncrement: 15,
    disable: buildFlatpickrDisable(),
    onChange: function(selectedDates) {
        if (selectedDates.length > 0) {
            checkAvailability(selectedDates[0]);
        }
    }
});

// ============================================
// AVAILABILITY CHECK (fine-grained, time-aware)
// ============================================
function checkAvailability(selectedDate) {
    var submitBtn = document.getElementById("submitBtn");
    var errorMsg  = document.getElementById("dateError");
    var errorSpan = errorMsg.querySelector('span:last-child');
    var banner    = document.getElementById("availabilityBanner");
    var bannerMsg = document.getElementById("availabilityMsg");

    // Reset
    submitBtn.disabled = false;
    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    errorMsg.classList.add('hidden');
    banner.classList.add('hidden');

    if (!selectedDate) return;

    var conflict = getConflict(selectedDate);
    if (conflict) {
        // Block submission
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');

        // Inline error
        errorSpan.textContent = "Worker is busy around " + conflict.label + ". Times from "
            + conflict.from.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'})
            + " to "
            + conflict.to.toLocaleTimeString('en-PH',  {hour:'2-digit',minute:'2-digit'})
            + " on that day are blocked.";
        errorMsg.classList.remove('hidden');
        errorMsg.classList.add('shake-animation');
        setTimeout(function(){ errorMsg.classList.remove('shake-animation'); }, 500);

        // Suggestion banner
        var nextSlot = findNextAvailableSlot(conflict.to);
        if (nextSlot) {
            bannerMsg.textContent = "Suggested next available slot: "
                + nextSlot.toLocaleString('en-PH', { weekday:'short', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' })
                + ". Click below to jump to it.";
            banner.classList.remove('hidden');

            // Wire up jump button
            document.getElementById('jumpToNextSlot').onclick = function() {
                fpInstance.setDate(nextSlot, true);
                banner.classList.add('hidden');
                document.getElementById('serviceDateInput').closest('.space-y-3').scrollIntoView({ behavior:'smooth', block:'center' });
            };
        } else {
            bannerMsg.textContent = "No available slots found in the next 30 days.";
            banner.classList.remove('hidden');
        }
    }
}

// ============================================
// MAP & LOCATION
// ============================================
var map, marker, mapInitialized = false;

function initMap(lat, lng) {
    var mapContainer = document.getElementById('map');
    if (!mapContainer) return;
    if (map) { map.setView([lat,lng],15); placeMarker(L.latLng(lat,lng)); map.invalidateSize(); return; }
    map = L.map('map').setView([lat,lng],15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'© OpenStreetMap'}).addTo(map);
    map.on('click', function(e){ placeMarker(e.latlng); });
    placeMarker(L.latLng(lat,lng));
    setTimeout(function(){ map.invalidateSize(); }, 100);
    mapInitialized = true;
}

function placeMarker(latlng) {
    if (marker) { marker.setLatLng(latlng); }
    else {
        marker = L.marker(latlng, {draggable:true}).addTo(map);
        marker.on('dragend', function(e){ updateLocationInfo(e.target.getLatLng()); updatePrice(); });
    }
    updateLocationInfo(latlng);
    updatePrice();
}

function updateLocationInfo(latlng) {
    document.getElementById('latitude').value  = latlng.lat.toFixed(6);
    document.getElementById('longitude').value = latlng.lng.toFixed(6);
    document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">location_on</span> Locating address...';
    document.getElementById('location_address').value = '';
    document.getElementById('selectedLocationInfo').style.display = 'block';
    tryReverseGeocode(latlng);
}

async function tryReverseGeocode(latlng) {
    var lat = latlng.lat.toFixed(6), lng = latlng.lng.toFixed(6);
    var data = null;
    try {
        var res = await fetch('../api/reverse_geocode.php?lat='+lat+'&lon='+lng);
        var json = await res.json();
        if (json && (json.display_name || json.address)) data = json;
    } catch(_) {}
    if (!data) {
        try {
            var res2 = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat='+lat+'&lon='+lng, {headers:{'User-Agent':'Abilisto/1.0','Accept-Language':'en'}});
            var json2 = await res2.json();
            if (json2 && json2.display_name) data = json2;
        } catch(_) {}
    }
    if (!data) { document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">edit_location</span> Enter address below'; return; }
    if (data.address) {
        var addr = data.address, parts = [];
        if (addr.house_number) parts.push(addr.house_number);
        if (addr.road) parts.push(addr.road);
        if (parts.length === 0) { if (addr.neighbourhood) parts.push(addr.neighbourhood); else if (addr.suburb) parts.push(addr.suburb); }
        var brgy = addr.village || addr.hamlet || addr.barangay || null;
        if (brgy) parts.push(brgy);
        var muni = addr.city || addr.town || addr.municipality || addr.county || null;
        if (muni) parts.push(muni);
        if (addr.state) parts.push(addr.state);
        var resolved = parts.length > 0 ? parts.join(', ') : (data.display_name || '');
        if (resolved) {
            document.getElementById('location_address').value = resolved;
            document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">check_circle</span> Address found';
        } else {
            document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">edit_location</span> Enter address below';
        }
    } else if (data.display_name) {
        document.getElementById('location_address').value = data.display_name;
        document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">check_circle</span> Address found';
    } else {
        document.getElementById('locationCoordinates').innerHTML = '<span class="material-symbols-outlined text-xs">edit_location</span> Enter address below';
    }
}

document.getElementById('useCurrentLocation').addEventListener('click', function() {
    if (!navigator.geolocation) { alert('Geolocation is not supported by your browser.'); return; }
    this.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> Getting location...';
    this.disabled = true;
    var btn = this;
    navigator.geolocation.getCurrentPosition(function(pos) {
        document.getElementById('mapContainer').style.display = 'block';
        initMap(pos.coords.latitude, pos.coords.longitude);
        btn.innerHTML = '<span class="material-symbols-outlined">my_location</span> Use My Current Location';
        btn.disabled = false;
        showNotification('success', 'Location detected successfully!');
    }, function(err) {
        alert('Failed to get your location.' + (err.code === err.PERMISSION_DENIED ? ' Please allow location access.' : ''));
        btn.innerHTML = '<span class="material-symbols-outlined">my_location</span> Use My Current Location';
        btn.disabled = false;
    }, {enableHighAccuracy:true, timeout:10000});
});

document.getElementById('pinOnMap').addEventListener('click', function() {
    var mc = document.getElementById('mapContainer');
    if (mc.style.display === 'none' || mc.style.display === '') {
        mc.style.display = 'block';
        var defLat = <?php echo $clientLat ?: '9.33796201'; ?>;
        var defLng = <?php echo $clientLng ?: '125.97473145'; ?>;
        setTimeout(function(){ initMap(defLat, defLng); }, 200);
        mc.scrollIntoView({behavior:'smooth', block:'center'});
    }
});

// ============================================
// PRICE UPDATE
// ============================================
function calculateDistanceJS(lat1,lon1,lat2,lon2) {
    if (!lat1||!lon1||!lat2||!lon2) return 0;
    var R=6371, dLat=(lat2-lat1)*Math.PI/180, dLon=(lon2-lon1)*Math.PI/180;
    var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)*Math.sin(dLon/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function updatePrice() {
    var lat=document.getElementById('latitude').value, lng=document.getElementById('longitude').value;
    var urgency=document.getElementById("urgencySelect").value, urgencyFee=urgencyFees[urgency]||0;
    var pmEl=document.querySelector('input[name="payment_method"]:checked');
    var pm=pmEl?pmEl.value:(cashEnabled?'Cash':'PayMongo');
    if(!cashEnabled) pm='PayMongo';
    var dist=0, distFee=0, base=20;
    if(lat&&lng&&workerLat&&workerLng){
        dist=calculateDistanceJS(parseFloat(lat),parseFloat(lng),workerLat,workerLng);
        distFee=dist*5;
        var dd=document.getElementById('distanceDisplay');
        if(dd) dd.innerHTML='<span class="material-symbols-outlined text-[14px] md:text-sm">location_on</span> '+dist.toFixed(2)+' km';
    }
    var sub=base+distFee+urgencyFee, total=pm==='PayMongo'?Math.round(sub*0.9*100)/100:sub, disc=sub-total;

    var voucherEl=document.getElementById('voucherSelect'), voucherRaw=0, voucherLabel='';
    if(voucherEl && voucherEl.value!=='0'){
        var opt=voucherEl.options[voucherEl.selectedIndex];
        voucherRaw=parseFloat(opt.getAttribute('data-value'))||0;
        voucherLabel=opt.textContent.split('—')[0].trim();
    }
    var voucherDiscount=Math.min(voucherRaw, total);
    total=Math.round((total-voucherDiscount)*100)/100;

    var pd=document.getElementById("priceDisplay");
    pd.style.transform='scale(1.1)'; setTimeout(function(){pd.style.transform='scale(1)'},200);
    pd.innerHTML = (disc>0||voucherDiscount>0)
        ? '<span class="line-through opacity-70 text-lg md:text-2xl">₱'+sub.toFixed(2)+'</span> ₱'+total.toFixed(2)
        : '₱'+total.toFixed(2);
    document.getElementById("feeInput").value=total;
    document.getElementById("urgencyNote").innerHTML=
        '<div class="flex justify-between"><span>Travel ('+dist.toFixed(2)+'km)</span><span>+₱'+distFee.toFixed(2)+'</span></div>'+
        '<div class="flex justify-between"><span>Urgency Fee ('+urgency+')</span><span>+₱'+urgencyFee+'</span></div>'+
        (voucherDiscount>0 ? '<div class="flex justify-between"><span>Voucher ('+voucherLabel+')</span><span>-₱'+voucherDiscount.toFixed(2)+'</span></div>' : '');
    var cashFinal=Math.max(0, Math.round((sub-Math.min(voucherRaw,sub))*100)/100);
    document.getElementById("cashText").innerHTML='₱'+cashFinal.toFixed(2);
    var disc2=Math.round(sub*0.9*100)/100;
    var onlineFinal=Math.max(0, Math.round((disc2-Math.min(voucherRaw,disc2))*100)/100);
    document.getElementById("onlineText").innerHTML=
        '<p class="text-slate-400 line-through text-xs md:text-sm">₱'+sub.toFixed(2)+'</p>'+
        '<p class="text-emerald-600 text-lg md:text-xl font-extrabold">₱'+onlineFinal.toFixed(2)+'</p>';
}

// ============================================
// TOAST NOTIFICATION
// ============================================
function showNotification(type, message) {
    var n=document.createElement('div');
    n.className='fixed top-6 right-6 p-4 rounded-2xl shadow-xl flex items-center gap-3 z-50 '+(type==='success'?'bg-green-50 text-green-800 border-l-4 border-green-500':'bg-red-50 text-red-800 border-l-4 border-red-500');
    n.style.animation='slideInRight .3s ease';
    n.innerHTML='<span class="material-symbols-outlined">'+(type==='success'?'check_circle':'error')+'</span><span class="text-sm font-medium">'+message+'</span><button onclick="this.parentElement.remove()" class="ml-4"><span class="material-symbols-outlined text-base">close</span></button>';
    document.body.appendChild(n);
    setTimeout(function(){ n.remove(); }, 3000);
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', function(){ updatePrice(); });
</script>

<style>
@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.animate-slideInRight{animation:slideInRight .3s ease}
</style>

<!-- ══════════════════════════════════════════
     CLIENT FIRST-LOGIN TOUR — part 2 of 2
     Picks up here whether the client arrived via worker_details.php's
     tour or landed directly on this page. This leg marks the whole
     tour seen, whether finished or skipped.
══════════════════════════════════════════ -->
<?php if ($show_client_tour): ?>
<script src="../includes/tour_engine.js"></script>
<script>
AbiTour.run({
    steps: [
        {
            target: '#tour-price-card', position: 'bottom',
            icon: 'payments', iconColor: '#146af5',
            title: 'Your Estimated Total',
            body: 'This updates live as you fill in the details below — travel distance, urgency, and any voucher you apply.',
        },
        {
            target: '#tour-payment-method', position: 'top',
            icon: 'account_balance_wallet', iconColor: '#16a34a',
            title: 'Choose How to Pay',
            body: 'Pay cash on service, or pay online via PayMongo and save 10% instantly.',
        },
        {
            target: '#submitBtn', position: 'top',
            icon: 'send', iconColor: '#146af5',
            title: 'You\'re Ready! 🚀',
            body: 'Fill in the job details above, then hit this button to send your booking request.',
        },
    ],
    onSkip: function () {
        fetch('booking.php?worker_id=<?php echo (int)$worker_id; ?>&mark_tour_seen=1', { method: 'POST' }).catch(() => {});
    },
    onComplete: function () {
        fetch('booking.php?worker_id=<?php echo (int)$worker_id; ?>&mark_tour_seen=1', { method: 'POST' }).catch(() => {});
    }
});
</script>
<?php endif; ?>

</body>
</html>