<?php
// client/confirm_quick_match.php
session_start();
include '../db.php';
include '../includes/fcm_sender.php';


if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: browser POSTs live lat/lng + reverse-geocoded address once geolocation
// resolves. This runs AFTER the transaction has committed but BEFORE the page
// redirects — the JS holds the redirect until this call returns (or times out).
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_location') {
    header('Content-Type: application/json');

    $ajax_client = (int)$_SESSION['user_id'];
    $bid     = isset($_POST['broadcast_id']) ? (int)$_POST['broadcast_id']  : 0;
    $lat     = isset($_POST['lat'])          ? (float)$_POST['lat']         : 0;
    $lng     = isset($_POST['lng'])          ? (float)$_POST['lng']         : 0;
    $address = isset($_POST['address'])      ? trim($_POST['address'])       : '';

    if (!$bid || !$lat || !$lng) {
        echo json_encode(['ok' => false, 'error' => 'Missing data']);
        exit;
    }

    // Verify broadcast belongs to this client
    $chk = $conn->prepare("SELECT id FROM job_broadcasts WHERE id = ? AND client_id = ?");
    $chk->bind_param('ii', $bid, $ajax_client);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $chk->close();

    // 1. Patch the broadcast row
    $stmt = $conn->prepare(
        "UPDATE job_broadcasts
         SET latitude = ?, longitude = ?, client_lat = ?, client_lng = ?, location_address = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ddddsi', $lat, $lng, $lat, $lng, $address, $bid);
    $stmt->execute();
    $stmt->close();

    // 2. Patch every booking created from this broadcast
    $stmt = $conn->prepare(
        "UPDATE bookings
         SET latitude = ?, longitude = ?, location_address = ?
         WHERE broadcast_id = ? AND client_id = ?"
    );
    $stmt->bind_param('ddsii', $lat, $lng, $address, $bid, $ajax_client);
    $stmt->execute();
    $stmt->close();

    error_log("✅ Geo saved for broadcast #$bid — lat=$lat, lng=$lng, addr=$address");
    echo json_encode(['ok' => true, 'address' => $address]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($_SESSION['quick_match']['workers'])) {
    header("Location: quick_match.php?step=1");
    exit();
}

$client_id = $_SESSION['user_id'];
$all_workers = $_SESSION['quick_match']['workers'];

// Respect the user's exclusions: only broadcast to workers whose IDs were submitted
if (!empty($_POST['selected_workers']) && is_array($_POST['selected_workers'])) {
    $selected_ids = array_map('intval', $_POST['selected_workers']);
    $workers = array_values(array_filter($all_workers, fn($w) => in_array((int)$w['id'], $selected_ids)));
} else {
    // Fallback: send to all (shouldn't happen in normal flow)
    $workers = $all_workers;
}

$category = $_SESSION['quick_match']['sub_category'] ?? $_SESSION['quick_match']['category'] ?? '';
$problems = is_array($_SESSION['quick_match']['problems']) ?
            implode(", ", $_SESSION['quick_match']['problems']) :
            $_SESSION['quick_match']['problems'];
$urgency = $_SESSION['quick_match']['urgency'];
$fee = $_SESSION['quick_match']['fee'];

// Escape before use in raw SQL below — these values originate from user POST input
// relayed through the session in quick_match.php, and were never escaped there.
$category = $conn->real_escape_string($category);
$problems = $conn->real_escape_string($problems);
$urgency  = $conn->real_escape_string($urgency);

// Get client info
$client = $conn->query("SELECT full_name, latitude, longitude FROM users WHERE id = '$client_id'")->fetch_assoc();
$client_name = $client['full_name'];
$lat = $client['latitude'] ?? 0;
$lng = $client['longitude'] ?? 0;

// Start transaction
$conn->begin_transaction();
error_log("DEBUG: workers count = " . count($workers) . " | broadcast category = " . $category);

try {
    // Create broadcast
    $expires_at = date('Y-m-d H:i:s', strtotime('+485 minutes'));
    $worker_ids = json_encode(array_column($workers, 'id'));
    
    $sql = "INSERT INTO job_broadcasts 
            (client_id, category, problem_tags, urgency, latitude, longitude,
             client_lat, client_lng, status, expires_at, candidate_workers, is_quick_match, created_at) 
            VALUES 
            ('$client_id', '$category', '$problems', '$urgency', '$lat', '$lng',
             '$lat', '$lng', 'searching', '$expires_at', '$worker_ids', 1, NOW())";
    
    if (!$conn->query($sql)) {
        throw new Exception("Error creating broadcast: " . $conn->error);
    }
    
    $broadcast_id = $conn->insert_id;
    
    // ============================================
    // 🔔 PUSH NOTIFICATIONS
    // ============================================
    $sent_count = 0;
    $fcm = new FCMv1();
    
    foreach ($workers as $index => $worker) {
        $worker_id = $worker['id'];
        $score = $worker['score'] ?? (100 - ($index * 5));
        
        $sql = "INSERT INTO job_candidates (broadcast_id, worker_id, score) 
                VALUES ('$broadcast_id', '$worker_id', '$score')";
        if (!$conn->query($sql)) {
            throw new Exception("Error inserting candidate: " . $conn->error);
        }
        
        $booking_date = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $check_sql = "SELECT id FROM bookings WHERE broadcast_id = '$broadcast_id' AND worker_id = '$worker_id'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result->num_rows == 0) {
            $sql = "INSERT INTO bookings 
                    (client_id, worker_id, service_type, problem_desc, calculated_fee, 
                     urgency_level, status, booking_date, payment_method, broadcast_id,
                     latitude, longitude, location_address, created_at) 
                    VALUES 
                    ('$client_id', '$worker_id', '$category', '$problems', '$fee', 
                     '$urgency', 'Pending', '$booking_date', 'Cash', '$broadcast_id',
                     '$lat', '$lng', '', NOW())";
            
            if (!$conn->query($sql)) {
                throw new Exception("Error creating booking: " . $conn->error);
            }
            
            $booking_id = $conn->insert_id;
            error_log("✅ Created booking #$booking_id for worker #$worker_id from broadcast #$broadcast_id");
        } else {
            $booking = $check_result->fetch_assoc();
            $booking_id = $booking['id'];
            error_log("⚠️ Booking already exists: #$booking_id for worker #$worker_id");
        }
        
       // ============================================
        // 🔔 SEND PUSH NOTIFICATION VIA ONESIGNAL
        // ============================================
        // No more SQL query needed for tokens! We just target $worker_id
        
        try {
            $title = $urgency === 'Emergency' ? "🚨 EMERGENCY: Quick Match" : "🔔 Quick Match Available";
            $body = $urgency === 'Emergency'
                ? "URGENT: $client_name needs immediate help with $category!"
                : "$client_name needs help with $category. Tap to respond.";
            
            $result = $fcm->send(
                $worker_id,  // <-- Targeting the Worker ID directly!
                $title,
                $body,
                [
                    'type' => 'quick_match',
                    'broadcast_id' => (string)$broadcast_id,
                    'urgency' => (string)$urgency,
                    'client_name' => (string)$client_name,
                    'url' => '/abilisto/worker/dashboard.php' // Changed click_url to url
                ]
            );
            
            if ($result['success']) {
                $sent_count++;
                error_log("✅ OneSignal broadcast sent to worker: $worker_id");
            } else {
                error_log("⚠️ OneSignal broadcast failed for worker $worker_id: " . 
                         (is_array($result['error']) ? json_encode($result['error']) : ($result['error'] ?? 'Unknown')));
            }
        } catch (Exception $e) {
            error_log("❌ Push notification error for worker $worker_id: " . $e->getMessage());
        }
    }
        
    $conn->commit();
    unset($_SESSION['quick_match']);

    // Pass data to the HTML
    $worker_count = count($workers);
    $worker_names = array_column($workers, 'name');
    $urgency_label = $urgency === 'Emergency' ? '🚨 EMERGENCY' : ($urgency === 'High' ? '⚡ URGENT' : '📋 NORMAL');

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Broadcasting... | Abilisto</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            min-height: 100vh;
            overflow: hidden;
        }

        /* ─── City Scene ─── */
        .city-scene {
            position: fixed; inset: 0;
            perspective: 900px; overflow: hidden; z-index: 0;
        }
        .city-plane {
            position: absolute;
            width: 200%; height: 200%;
            left: -50%; top: 10%;
            transform: rotateX(55deg) rotateZ(-45deg);
            transform-style: preserve-3d;
        }
        .city-plane::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.07) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.07) 1px, transparent 1px);
            background-size: 60px 60px;
        }
        .building {
            position: absolute;
            border-radius: 4px 4px 0 0;
            transition: box-shadow .35s;
        }
        .building::before {
            content: '';
            position: absolute; inset: 2px;
            background: repeating-linear-gradient(
                180deg,
                rgba(255,255,255,.1) 0px, rgba(255,255,255,.1) 5px,
                transparent 5px, transparent 13px
            );
            border-radius: 2px;
        }
        .building.lit {
            box-shadow: 0 0 28px rgba(250,204,21,.35), 0 0 55px rgba(250,204,21,.12);
        }
        .building.notified {
            box-shadow: 0 0 35px rgba(99,255,180,.5), 0 0 70px rgba(99,255,180,.2);
        }
        .road-h { position:absolute; height:18px; width:100%; background:rgba(255,255,255,.04); }
        .road-v { position:absolute; width:18px; height:100%; background:rgba(255,255,255,.04); }

        @keyframes sway {
            0%,100% { transform: translateY(0); }
            50%     { transform: translateY(-3px); }
        }

        /* ─── HUD ─── */
        #hud {
            position: fixed; inset: 0; z-index: 20;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 2rem 1.5rem;
            gap: 0;
        }

        /* ─── Broadcast icon ─── */
        .broadcast-icon {
            position: relative;
            width: 110px; height: 110px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 2rem;
            opacity: 0;
            transform: scale(.5);
            animation: pop-in .6s cubic-bezier(.34,1.56,.64,1) .2s forwards;
        }
        @keyframes pop-in {
            to { opacity: 1; transform: scale(1); }
        }
        .broadcast-bg {
            position: absolute; inset: 0; border-radius: 50%;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.25);
            backdrop-filter: blur(10px);
        }
        .broadcast-wave {
            position: absolute; border-radius: 50%;
            border: 2px solid rgba(250,204,21,.5);
            animation: wave-out 2s ease-out infinite;
        }
        .broadcast-wave:nth-child(2) { animation-delay: .65s; }
        .broadcast-wave:nth-child(3) { animation-delay: 1.3s; }
        @keyframes wave-out {
            0%   { width: 80px; height: 80px; opacity: .8; }
            100% { width: 200px; height: 200px; opacity: 0; }
        }
        .broadcast-emoji {
            position: relative; z-index: 2;
            font-size: 2.8rem;
            animation: spin-once .8s cubic-bezier(.34,1.56,.64,1) .4s both;
        }
        @keyframes spin-once {
            from { transform: rotate(-30deg) scale(.6); opacity: 0; }
            to   { transform: rotate(0deg) scale(1); opacity: 1; }
        }

        /* ─── Title ─── */
        .title {
            font-size: clamp(1.7rem, 5vw, 2.6rem);
            font-weight: 900; color: #fff;
            text-shadow: 0 4px 24px rgba(0,0,0,.28);
            letter-spacing: -.03em; line-height: 1.1;
            text-align: center;
            opacity: 0; transform: translateY(18px);
            animation: rise-in .55s ease .5s forwards;
            margin-bottom: .5rem;
        }
        .subtitle {
            font-size: .9rem; font-weight: 500;
            color: rgba(255,255,255,.5);
            text-align: center;
            opacity: 0; transform: translateY(14px);
            animation: rise-in .55s ease .7s forwards;
            margin-bottom: 2rem;
        }
        @keyframes rise-in {
            to { opacity: 1; transform: translateY(0); }
        }

        /* ─── Worker notification list ─── */
        .worker-list {
            display: flex; flex-direction: column; gap: .7rem;
            width: 100%; max-width: 360px;
            margin-bottom: 2rem;
        }
        .worker-row {
            display: flex; align-items: center; gap: .85rem;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            backdrop-filter: blur(10px);
            border-radius: 14px; padding: .75rem 1rem;
            opacity: 0; transform: translateX(-24px);
            transition: opacity .45s ease, transform .45s ease, background .3s ease, border-color .3s ease;
        }
        .worker-row.visible { opacity: 1; transform: translateX(0); }
        .worker-row.sent {
            background: rgba(99,255,180,.1);
            border-color: rgba(99,255,180,.25);
        }

        .worker-avatar {
            width: 38px; height: 38px; flex-shrink: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border: 2px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 800; color: #fff;
        }
        .worker-info { flex: 1; min-width: 0; }
        .worker-name {
            font-size: .85rem; font-weight: 700; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .worker-dist {
            font-size: .7rem; font-weight: 500;
            color: rgba(255,255,255,.45); margin-top: 1px;
        }

        /* Status indicator */
        .worker-status {
            flex-shrink: 0;
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .75rem;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            transition: all .4s cubic-bezier(.34,1.56,.64,1);
        }
        .worker-status.pending { animation: status-spin 1s linear infinite; }
        @keyframes status-spin {
            to { transform: rotate(360deg); }
        }
        .worker-status.done {
            background: rgba(99,255,180,.2);
            border-color: rgba(99,255,180,.4);
            animation: status-pop .5s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes status-pop {
            0%   { transform: scale(.6); }
            60%  { transform: scale(1.25); }
            100% { transform: scale(1); }
        }

        /* ─── Progress bar ─── */
        .progress-wrap {
            width: 100%; max-width: 360px;
            opacity: 0; transform: translateY(10px);
            animation: rise-in .5s ease 1s forwards;
        }
        .progress-label {
            display: flex; justify-content: space-between;
            font-size: .7rem; font-weight: 700;
            color: rgba(255,255,255,.45);
            letter-spacing: .08em; text-transform: uppercase;
            margin-bottom: .5rem;
        }
        .progress-track {
            width: 100%; height: 5px; border-radius: 999px;
            background: rgba(255,255,255,.12);
            overflow: hidden;
        }
        .progress-fill {
            height: 100%; border-radius: 999px;
            background: linear-gradient(90deg, #facc15, #f59e0b);
            width: 0%;
            transition: width .5s ease;
        }

        /* ─── Urgency badge ─── */
        .urgency-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .35rem 1rem; border-radius: 999px;
            font-size: .7rem; font-weight: 800;
            letter-spacing: .1em; text-transform: uppercase;
            margin-bottom: 1.5rem;
            opacity: 0;
            animation: rise-in .45s ease .35s forwards;
        }
        .urgency-badge.emergency { background: rgba(239,68,68,.2); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; }
        .urgency-badge.high      { background: rgba(245,158,11,.18); border: 1px solid rgba(245,158,11,.3); color: #fcd34d; }
        .urgency-badge.normal    { background: rgba(99,102,241,.2); border: 1px solid rgba(99,102,241,.3); color: #a5b4fc; }

        /* ─── Desktop: side-by-side square cards ─── */
        @media (min-width: 768px) {
            #hud {
                justify-content: center;
                padding: 1.5rem 2rem;
            }
            .worker-list {
                flex-direction: row;
                flex-wrap: nowrap;
                max-width: 860px;
                gap: .75rem;
                align-items: stretch;
                margin-bottom: 1.5rem;
            }
            .worker-row {
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                gap: .55rem;
                padding: 1rem .75rem;
                flex: 1;
                transform: translateY(20px);
            }
            .worker-row.visible { transform: translateY(0); }
            .worker-info { min-width: 0; width: 100%; }
            .worker-name { white-space: normal; word-break: break-word; font-size: .8rem; }
            .worker-dist { font-size: .65rem; }
            .progress-wrap { max-width: 860px; }
        }

        /* Particle */
        .particle {
            position: fixed; border-radius: 50%;
            pointer-events: none;
            animation: float-up linear forwards;
        }
        @keyframes float-up {
            0%   { opacity:1; transform: translateY(0) scale(1); }
            100% { opacity:0; transform: translateY(-140px) scale(0); }
        }
    </style>
</head>
<body>

<!-- City Background -->
<div class="city-scene">
    <div class="city-plane" id="cityPlane"></div>
    <div id="cityParticles"></div>
</div>

<!-- HUD -->
<div id="hud">

    <!-- Urgency Badge -->
    <div class="urgency-badge <?php echo strtolower($urgency); ?>">
        <span><?php echo $urgency_label; ?></span>
        <span>·</span>
        <span><?php echo htmlspecialchars($category ?? ''); ?></span>
    </div>

    <!-- Broadcast icon with waves -->
    <div class="broadcast-icon">
        <div class="broadcast-wave"></div>
        <div class="broadcast-wave"></div>
        <div class="broadcast-wave"></div>
        <div class="broadcast-bg"></div>
        <span class="broadcast-emoji">📡</span>
    </div>

    <h1 class="title">Broadcasting to Workers</h1>
    <p class="subtitle">Notifying the best matches near you</p>

    <!-- Worker list -->
    <div class="worker-list" id="workerList">
        <?php foreach ($workers as $i => $w): 
            $initials = strtoupper(substr(explode(' ', $w['name'])[0], 0, 1) . substr(explode(' ', $w['name'])[1] ?? $w['name'], 0, 1));
        ?>
        <div class="worker-row" id="row-<?php echo $i; ?>">
            <div class="worker-avatar"><?php echo $initials; ?></div>
            <div class="worker-info">
                <div class="worker-name"><?php echo htmlspecialchars($w['name']); ?></div>
                <div class="worker-dist"><?php echo $w['distance']; ?> km away · <?php echo $w['verification_status'] ?? 'Verified'; ?></div>
            </div>
            <div class="worker-status pending" id="status-<?php echo $i; ?>">⟳</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Progress bar -->
    <div class="progress-wrap">
        <div class="progress-label">
            <span>Broadcasting</span>
            <span id="progressLabel">0 / <?php echo $worker_count; ?> notified</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="progressFill"></div>
        </div>
    </div>

</div>

<script>
const WORKER_COUNT = <?php echo $worker_count; ?>;
const BROADCAST_ID = <?php echo $broadcast_id; ?>;
const SENT_COUNT   = <?php echo $sent_count; ?>;

// ── City Builder ──────────────────────────────────────
const cityPlane = document.getElementById('cityPlane');
const BCOLORS = [
    'rgba(99,102,241,.45)','rgba(129,140,248,.35)',
    'rgba(168,85,247,.40)','rgba(139,92,246,.35)',
    'rgba(79,70,229,.50)', 'rgba(109,40,217,.35)',
];
let buildings = [];

function buildCity() {
    cityPlane.innerHTML = '';
    buildings = [];
    [25, 45, 65, 85].forEach(p => {
        const rh = document.createElement('div');
        rh.className = 'road-h'; rh.style.top = p + '%';
        cityPlane.appendChild(rh);
        const rv = document.createElement('div');
        rv.className = 'road-v'; rv.style.left = p + '%';
        cityPlane.appendChild(rv);
    });
    for (let r = 0; r < 6; r++) {
        for (let c = 0; c < 8; c++) {
            if (Math.random() < .25) continue;
            const b = document.createElement('div');
            b.className = 'building';
            const w = 28 + Math.random() * 32, h = 28 + Math.random() * 65;
            const left = 8 + c*12 + Math.random()*4, top = 8 + r*14 + Math.random()*4;
            b.style.cssText = `width:${w}px;height:${h}px;left:${left}%;top:${top}%;
                background:${BCOLORS[Math.floor(Math.random()*BCOLORS.length)]};
                animation:sway ${3+Math.random()*2}s ease-in-out ${Math.random()*2}s infinite;
                z-index:${Math.floor(h)};`;
            cityPlane.appendChild(b);
            buildings.push(b);
        }
    }
}
buildCity();

// Ambient lights
function ambientLight() {
    const unlit = buildings.filter(b => !b.classList.contains('lit') && !b.classList.contains('notified'));
    if (unlit.length) {
        const b = unlit[Math.floor(Math.random() * unlit.length)];
        b.classList.add('lit');
        setTimeout(() => b.classList.remove('lit'), 1400 + Math.random()*1200);
    }
    setTimeout(ambientLight, 450 + Math.random()*350);
}
ambientLight();

// Green "notified" flash when a worker is sent
function flashCity() {
    const picks = [...buildings].sort(() => Math.random()-.5).slice(0, 5);
    picks.forEach((b, i) => {
        setTimeout(() => {
            b.classList.remove('lit');
            b.classList.add('notified');
            setTimeout(() => b.classList.remove('notified'), 900);
        }, i * 120);
    });
}

// Particles
function spawnParticles(n) {
    const cont = document.getElementById('cityParticles');
    const cx = window.innerWidth / 2, cy = window.innerHeight / 2;
    for (let i = 0; i < n; i++) {
        const p = document.createElement('div');
        p.className = 'particle';
        const sz = 3 + Math.random() * 7;
        p.style.cssText = `width:${sz}px;height:${sz}px;
            left:${cx+(Math.random()-.5)*280}px;
            top:${cy+(Math.random()-.5)*280}px;
            background:${Math.random()>.5?'#facc15':'#a78bfa'};
            animation-duration:${.5+Math.random()*.8}s;`;
        cont.appendChild(p);
        setTimeout(() => p.remove(), 1300);
    }
}

// ── Animate worker rows in, one by one ───────────────
const totalWorkers = WORKER_COUNT;
let notified = 0;

// Will be set to true once geo save finishes (or times out)
let geoSaveDone = false;

// Redirect fires only when BOTH animation is done AND geo is saved
let animationDone = false;
function maybeRedirect() {
    if (animationDone && geoSaveDone) {
        spawnParticles(30);
        setTimeout(() => {
            window.location.href = `waiting_match.php?broadcast_id=${BROADCAST_ID}`;
        }, 600);
    }
}

function animateWorkers() {
    for (let i = 0; i < totalWorkers; i++) {
        const row    = document.getElementById(`row-${i}`);
        const status = document.getElementById(`status-${i}`);

        setTimeout(() => {
            if (row) row.classList.add('visible');
        }, 800 + i * 320);

        const sentDelay = 1200 + i * 320 + 500;
        setTimeout(() => {
            if (status) {
                status.classList.remove('pending');
                status.classList.add('done');
                status.textContent = '✓';
            }
            if (row) row.classList.add('sent');

            notified++;
            const pct = (notified / totalWorkers) * 100;
            document.getElementById('progressFill').style.width = pct + '%';
            document.getElementById('progressLabel').textContent =
                `${notified} / ${totalWorkers} notified`;

            flashCity();
            spawnParticles(12);
        }, sentDelay);
    }

    // Animation complete — attempt redirect
    const animDoneDelay = 1200 + (totalWorkers - 1) * 320 + 500 + 900;
    setTimeout(() => {
        animationDone = true;
        maybeRedirect();
    }, animDoneDelay);
}

// ── Geolocation → Nominatim → POST to server ─────────
// Uses the same reverse_geocode.php proxy your booking.php uses,
// with a direct Nominatim fallback so it works either way.
async function reverseGeocode(lat, lng) {
    // Try your own proxy first (same as booking.php)
    try {
        const res  = await fetch(`../api/reverse_geocode.php?lat=${lat}&lon=${lng}`);
        const data = await res.json();
        if (data && data.display_name) return data.display_name;
    } catch (_) {}

    // Direct Nominatim fallback
    try {
        const res  = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`,
            { headers: { 'User-Agent': 'Abilisto/1.0', 'Accept-Language': 'en' } }
        );
        const data = await res.json();
        if (data && data.display_name) return data.display_name;
    } catch (_) {}

    return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
}

async function saveGeoLocation(lat, lng, address) {
    const body = new URLSearchParams({
        action:       'save_location',
        broadcast_id: BROADCAST_ID,
        lat:          lat,
        lng:          lng,
        address:      address,
    });

    const res  = await fetch(window.location.pathname, { method: 'POST', body });
    const data = await res.json();
    return data.ok === true;
}

(async function captureLocation() {
    // Hard timeout: if geo takes longer than 8 s total, unblock redirect anyway
    const geoTimeout = setTimeout(() => {
        console.warn('⏱ Geo timeout — redirecting without location');
        geoSaveDone = true;
        maybeRedirect();
    }, 8000);

    if (!navigator.geolocation) {
        clearTimeout(geoTimeout);
        geoSaveDone = true;
        maybeRedirect();
        return;
    }

    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;

            const address = await reverseGeocode(lat, lng);
            await saveGeoLocation(lat, lng, address);

            clearTimeout(geoTimeout);
            geoSaveDone = true;
            maybeRedirect();
        },
        (err) => {
            // Permission denied / unavailable — don't block redirect
            console.warn('Geolocation error:', err.message);
            clearTimeout(geoTimeout);
            geoSaveDone = true;
            maybeRedirect();
        },
        { enableHighAccuracy: true, timeout: 7000, maximumAge: 0 }
    );
})();

// Kick off city animation immediately
setTimeout(animateWorkers, 100);
</script>
</body>
</html>
    <?php

} catch (Exception $e) {
    $conn->rollback();
    error_log("QUICK MATCH ERROR: " . $e->getMessage());
    die("Error: " . $e->getMessage());
}
?>