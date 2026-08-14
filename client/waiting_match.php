<?php
// client/waiting_match.php
session_start();
include '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$broadcast_id = $_GET['broadcast_id'] ?? 0;
$client_id = $_SESSION['user_id'];

// Verify this broadcast belongs to the client
$broadcast_stmt = $conn->prepare("SELECT * FROM job_broadcasts
                          WHERE id = ? AND client_id = ?");
$broadcast_stmt->execute([$broadcast_id, $client_id]);
$broadcast = $broadcast_stmt->fetch();

if (!$broadcast) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Waiting for Match | Abilisto</title>

    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            min-height: 100vh;
            overflow: hidden;
        }

        /* ─── City Scene (identical look to quick_match) ─── */
        .city-scene {
            position: fixed;
            inset: 0;
            perspective: 900px;
            overflow: hidden;
            z-index: 0;
            transition: opacity .5s ease;
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
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.07) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.07) 1px, transparent 1px);
            background-size: 60px 60px;
        }
        .building {
            position: absolute;
            border-radius: 4px 4px 0 0;
            transition: box-shadow .4s;
        }
        .building::before {
            content: '';
            position: absolute;
            inset: 2px;
            background: repeating-linear-gradient(
                180deg,
                rgba(255,255,255,.1) 0px, rgba(255,255,255,.1) 5px,
                transparent 5px, transparent 13px
            );
            border-radius: 2px;
        }
        .building.lit {
            box-shadow: 0 0 28px rgba(250,204,21,.32), 0 0 55px rgba(250,204,21,.12);
        }
        .road-h { position:absolute; height:18px; width:100%; background:rgba(255,255,255,.04); }
        .road-v { position:absolute; width:18px; height:100%; background:rgba(255,255,255,.04); }
        @keyframes sway {
            0%,100% { transform: translateY(0); }
            50%     { transform: translateY(-3px); }
        }

        /* ─── HUD ─── */
        #mainHud {
            position: fixed;
            inset: 0;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity .4s ease, transform .5s cubic-bezier(.55,.055,.675,.19);
        }

        /* ─── Pin + Signal rings ─── */
        .pin-wrap {
            position: relative;
            width: 190px; height: 190px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .signal-ring {
            position: absolute;
            border-radius: 50%;
            border: 2px solid rgba(250,204,21,.6);
            animation: signal-expand 2.6s ease-out infinite;
        }
        .signal-ring:nth-child(2) { animation-delay: .87s; }
        .signal-ring:nth-child(3) { animation-delay: 1.74s; }
        @keyframes signal-expand {
            0%   { width: 50px; height: 50px; opacity: .9; }
            100% { width: 190px; height: 190px; opacity: 0; }
        }

        .pin-icon {
            position: relative; z-index: 2;
            animation: pin-bob 2.2s ease-in-out infinite;
            filter: drop-shadow(0 10px 28px rgba(0,0,0,.4));
        }
        @keyframes pin-bob {
            0%,100% { transform: translateY(0) scale(1); }
            50%     { transform: translateY(-7px) scale(1.05); }
        }
        .pin-shadow {
            position: absolute; bottom: 16px;
            width: 42px; height: 14px;
            background: rgba(0,0,0,.28);
            border-radius: 50%; filter: blur(7px);
            z-index: 1;
            animation: shadow-pulse 2.2s ease-in-out infinite;
        }
        @keyframes shadow-pulse {
            0%,100% { transform: scaleX(1); opacity: .45; }
            50%     { transform: scaleX(.65); opacity: .2; }
        }

        /* ─── Text block ─── */
        .text-block {
            text-align: center;
            padding: 0 1.5rem;
            max-width: 440px;
            margin-top: 1.6rem;
        }

        /* Motivational rotator */
        .motivation-wrap { min-height: 26px; margin-bottom: .6rem; }
        .motivation-text {
            font-size: .82rem; font-weight: 700;
            color: rgba(250,204,21,.95);
            letter-spacing: .01em;
            opacity: 0; transform: translateY(9px);
            transition: opacity .45s ease, transform .45s ease;
        }
        .motivation-text.show { opacity: 1; transform: translateY(0); }

        .main-title {
            font-size: clamp(1.65rem, 5vw, 2.5rem);
            font-weight: 900; color: #fff;
            text-shadow: 0 4px 24px rgba(0,0,0,.3);
            letter-spacing: -.025em;
            line-height: 1.15;
            margin-bottom: .5rem;
        }
        .sub-title {
            font-size: .88rem; color: rgba(255,255,255,.5);
            font-weight: 500; margin-bottom: 1.5rem;
        }

        /* Timer */
        .timer-pill {
            display: inline-flex; align-items: center; gap: .55rem;
            background: rgba(255,255,255,.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 999px;
            padding: .55rem 1.5rem;
            margin-bottom: 1.8rem;
        }
        .timer-dot {
            width: 8px; height: 8px;
            background: #facc15; border-radius: 50%;
            animation: blink 1s ease-in-out infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.25} }
        .timer-value {
            font-size: 1.55rem; font-weight: 800; color: #fff;
            font-variant-numeric: tabular-nums; letter-spacing: .05em;
        }

        .cancel-btn {
            display: inline-block;
            font-size: .68rem; font-weight: 800;
            letter-spacing: .18em; text-transform: uppercase;
            color: rgba(50, 47, 47, 0.32);
            padding: .55rem 1.4rem;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 999px;
            text-decoration: none;
            transition: all .2s;
        }
        .cancel-btn:hover {
            color: rgba(255,255,255,.65);
            border-color: rgba(255,255,255,.3);
            background: rgba(255,255,255,.07);
        }

        /* ─── Collapse ripple ─── */
        #collapseRipple {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at center, rgba(99,102,241,.96) 0%, rgba(168,85,247,.88) 55%, transparent 100%);
            transform: scale(0);
            border-radius: 50%;
            z-index: 90;
            pointer-events: none;
            opacity: 0;
            transition: transform .7s cubic-bezier(.22,1,.36,1), opacity .3s ease;
        }

        /* ─── Success overlay ─── */
        #successOverlay {
            position: fixed;
            inset: 0; z-index: 100;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center; padding: 2rem;
            pointer-events: none;
            opacity: 0;
            transition: opacity .35s ease;
        }

        /* Handshake */
        .handshake-wrap {
            position: relative;
            opacity: 0;
            transform: scale(.25) rotate(-10deg);
            transition: opacity .5s ease, transform .75s cubic-bezier(.34,1.56,.64,1);
        }
        .handshake-emoji {
            display: block;
            font-size: clamp(5rem, 22vw, 9.5rem);
            line-height: 1;
            filter: drop-shadow(0 20px 48px rgba(0,0,0,.35));
        }
        .handshake-glow {
            position: absolute; inset: -30px;
            background: radial-gradient(circle, rgba(250,204,21,.3) 0%, transparent 70%);
            border-radius: 50%; filter: blur(22px);
            z-index: -1;
        }

        .match-title {
            font-size: clamp(2.2rem, 9vw, 3.8rem);
            font-weight: 900; color: #fff;
            text-shadow: 0 4px 32px rgba(0,0,0,.3);
            letter-spacing: -.03em;
            margin-top: 1.2rem;
            opacity: 0; transform: translateY(22px);
            transition: opacity .5s ease, transform .5s ease;
        }
        .match-sub {
            font-size: 1rem; font-weight: 600;
            color: rgba(255,255,255,.65);
            margin-top: .5rem;
            opacity: 0; transform: translateY(14px);
            transition: opacity .5s ease, transform .5s ease;
        }
        .redirect-badge {
            margin-top: 1.6rem;
            display: inline-flex; align-items: center; gap: .55rem;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            backdrop-filter: blur(10px);
            padding: .55rem 1.5rem;
            border-radius: 999px;
            opacity: 0; transform: translateY(14px);
            transition: opacity .5s ease, transform .5s ease;
        }
        .redirect-dot {
            width: 8px; height: 8px;
            background: #4ade80; border-radius: 50%;
            animation: blink 1s infinite;
        }
        .redirect-text {
            font-size: .78rem; font-weight: 700;
            color: #fff; letter-spacing: .06em;
        }

        /* Sparkle */
        .sparkle {
            position: fixed; pointer-events: none;
            animation: sparkle-pop .75s ease forwards;
        }
        @keyframes sparkle-pop {
            0%   { opacity: 1; transform: scale(0) rotate(0deg); }
            60%  { opacity: 1; transform: scale(1.5) rotate(25deg); }
            100% { opacity: 0; transform: scale(.9) rotate(45deg) translateY(-35px); }
        }

        /* Particles */
        .particle {
            position: fixed; border-radius: 50%;
            pointer-events: none;
            animation: float-up linear forwards;
        }
        @keyframes float-up {
            0%   { opacity:1; transform: translateY(0) scale(1); }
            100% { opacity:0; transform: translateY(-150px) scale(0); }
        }
    </style>
</head>
<body>

<!-- City Background -->
<div class="city-scene" id="cityScene">
    <div class="city-plane" id="cityPlane"></div>
    <div id="cityParticles"></div>
</div>

<!-- Collapse ripple -->
<div id="collapseRipple"></div>

<!-- Main HUD -->
<div id="mainHud">
    <!-- Pin + signal rings -->
    <div class="pin-wrap">
        <div class="signal-ring"></div>
        <div class="signal-ring"></div>
        <div class="signal-ring"></div>
        <div class="pin-icon">
            <svg width="62" height="78" viewBox="0 0 62 78" fill="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="pg" cx="38%" cy="28%" r="65%">
                        <stop offset="0%" stop-color="#818cf8"/>
                        <stop offset="100%" stop-color="#4f46e5"/>
                    </radialGradient>
                    <filter id="glow">
                        <feGaussianBlur stdDeviation="3" result="blur"/>
                        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                    </filter>
                </defs>
                <path d="M31 1C17.19 1 6 12.19 6 26C6 42.5 31 77 31 77C31 77 56 42.5 56 26C56 12.19 44.81 1 31 1Z"
                      fill="url(#pg)" stroke="rgba(255,255,255,.55)" stroke-width="2" filter="url(#glow)"/>
                <circle cx="31" cy="26" r="13" fill="rgba(255,255,255,.95)"/>
                <circle cx="31" cy="26" r="5.5" fill="#4f46e5"/>
                <ellipse cx="26" cy="20" rx="4" ry="2.5" fill="rgba(255,255,255,.45)" transform="rotate(-22 26 20)"/>
            </svg>
        </div>
        <div class="pin-shadow"></div>
    </div>

    <!-- Text -->
    <div class="text-block">
        <div class="motivation-wrap">
            <p class="motivation-text show" id="motivText">✨ Your perfect match is just moments away!</p>
        </div>
        <h1 class="main-title">Waiting for Workers<br>to Accept</h1>
        <p class="sub-title">Broadcasting to top-rated workers near you</p>

        <div class="timer-pill">
            <span class="timer-dot"></span>
            <span class="timer-value" id="timer">5:00</span>
        </div>

        <br>
        <button onclick="cancelSearch()" class="cancel-btn" id="cancelBtn">Cancel Search</button>
    </div>
</div>

<!-- Success Overlay -->
<div id="successOverlay">
    <div class="handshake-wrap" id="handshakeWrap">
        <div class="handshake-glow"></div>
        <span class="handshake-emoji">🤝</span>
    </div>
    <h1 class="match-title" id="matchTitle">Match Found!</h1>
    <p class="match-sub" id="matchSub">A worker is on their way to help you.</p>
    <div class="redirect-badge" id="redirectBadge">
        <span class="redirect-dot"></span>
        <span class="redirect-text">Connecting to chat...</span>
    </div>
</div>

<!-- Audio -->
<audio id="notificationSound" preload="auto">
    <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
</audio>

<script>
// ── Config ────────────────────────────────────────────
const broadcastId = <?php echo $broadcast_id; ?>;
let timeLeft  = 300;
let matchFound = false;

// ── DOM refs ──────────────────────────────────────────
const timerEl       = document.getElementById('timer');
const mainHud       = document.getElementById('mainHud');
const successOvl    = document.getElementById('successOverlay');
const collapseEl    = document.getElementById('collapseRipple');
const handshakeEl   = document.getElementById('handshakeWrap');
const matchTitleEl  = document.getElementById('matchTitle');
const matchSubEl    = document.getElementById('matchSub');
const redirectEl    = document.getElementById('redirectBadge');
const citySceneEl   = document.getElementById('cityScene');
const cityPlaneEl   = document.getElementById('cityPlane');
const motivEl       = document.getElementById('motivText');
const notifSound    = document.getElementById('notificationSound');
const particlesEl   = document.getElementById('cityParticles');

// ── City Builder ──────────────────────────────────────
const BCOLORS = [
    'rgba(99,102,241,.45)','rgba(129,140,248,.35)',
    'rgba(168,85,247,.40)','rgba(139,92,246,.35)',
    'rgba(79,70,229,.50)', 'rgba(109,40,217,.35)',
    'rgba(67,56,202,.40)', 'rgba(147,51,234,.35)',
];
let buildings = [];

function buildCity() {
    cityPlaneEl.innerHTML = '';
    buildings = [];

    [25, 45, 65, 85].forEach(p => {
        const rh = document.createElement('div');
        rh.className = 'road-h'; rh.style.top = p + '%';
        cityPlaneEl.appendChild(rh);

        const rv = document.createElement('div');
        rv.className = 'road-v'; rv.style.left = p + '%';
        cityPlaneEl.appendChild(rv);
    });

    for (let r = 0; r < 6; r++) {
        for (let c = 0; c < 8; c++) {
            if (Math.random() < .25) continue;
            const b = document.createElement('div');
            b.className = 'building';
            const w    = 28 + Math.random() * 32;
            const h    = 28 + Math.random() * 65;
            const left = 8  + c * 12 + Math.random() * 4;
            const top  = 8  + r * 14 + Math.random() * 4;
            b.style.cssText = `width:${w}px;height:${h}px;left:${left}%;top:${top}%;
                background:${BCOLORS[Math.floor(Math.random()*BCOLORS.length)]};
                animation:sway ${3+Math.random()*2}s ease-in-out ${Math.random()*2}s infinite;
                z-index:${Math.floor(h)};`;
            cityPlaneEl.appendChild(b);
            buildings.push(b);
        }
    }
}

// Ambient building lights — simulate workers being notified
function ambientLights() {
    if (matchFound) return;
    const unlit = buildings.filter(b => !b.classList.contains('lit'));
    if (unlit.length) {
        const pick = unlit[Math.floor(Math.random() * unlit.length)];
        pick.classList.add('lit');
        setTimeout(() => pick.classList.remove('lit'), 1600 + Math.random()*1400);
    }
    setTimeout(ambientLights, 500 + Math.random()*400);
}

window.addEventListener('load', () => { buildCity(); ambientLights(); });
window.addEventListener('resize', buildCity);

// ── Motivational messages ─────────────────────────────
const MSGS = [
    '✨ Your perfect match is just moments away!',
    '🚀 Workers nearby are being notified right now!',
    '💪 Skilled professionals are reviewing your request!',
    '⚡ Hang tight — great help is coming your way!',
    '🌟 You\'re at the top of the queue — almost there!',
    '🔔 Your signal is reaching nearby experts!',
    '🛠️ Someone is about to raise their hand for you!',
    '🏃 A skilled worker might already be heading out!',
    '💡 Good things come to those who wait — briefly!',
    '🎯 We\'re matching you with the best fit right now!',
    '🤞 Any second now... workers are responding!',
    '🌍 Scanning the area for the perfect pro!',
];
let msgIdx = 0;

function rotateMotive() {
    motivEl.classList.remove('show');
    setTimeout(() => {
        msgIdx = (msgIdx + 1) % MSGS.length;
        motivEl.textContent = MSGS[msgIdx];
        motivEl.classList.add('show');
    }, 450);
}
setInterval(rotateMotive, 3800);

// ── Timer ─────────────────────────────────────────────
const timerInterval = setInterval(() => {
    if (matchFound) return;
    timeLeft--;
    const m = Math.floor(timeLeft / 60);
    const s = timeLeft % 60;
    timerEl.textContent = `${m}:${s.toString().padStart(2,'0')}`;
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        clearInterval(pollInterval);
        // Mark broadcast as expired in DB so ghost bookings get filtered out
        fetch('../api/cancel_broadcast.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ broadcast_id: broadcastId, reason: 'expired' })
        }).catch(() => {}).finally(() => {
            alert('No workers accepted in time. Please try again.');
            window.location.href = 'dashboard.php';
        });
        return;
    }
}, 1000);

// ── Particles ─────────────────────────────────────────
function spawnParticles(n) {
    const cx = window.innerWidth  / 2;
    const cy = window.innerHeight / 2;
    for (let i = 0; i < n; i++) {
        const p = document.createElement('div');
        p.className = 'particle';
        const sz = 4 + Math.random() * 8;
        p.style.cssText = `
            width:${sz}px; height:${sz}px;
            left:${cx + (Math.random()-.5)*320}px;
            top:${cy  + (Math.random()-.5)*320}px;
            background:${Math.random()>.5 ? '#facc15' : '#a78bfa'};
            animation-duration:${.5+Math.random()*.9}s;`;
        particlesEl.appendChild(p);
        setTimeout(() => p.remove(), 1500);
    }
}

// ── Sparkles around handshake ─────────────────────────
function spawnSparkles() {
    const EMOJIS = ['✨','⭐','💫','🌟','🎉','🎊'];
    const rect = handshakeEl.getBoundingClientRect();
    const cx = rect.left + rect.width  / 2;
    const cy = rect.top  + rect.height / 2;
    for (let i = 0; i < 14; i++) {
        const sp = document.createElement('div');
        sp.className = 'sparkle';
        const angle  = (i / 14) * Math.PI * 2;
        const radius = 55 + Math.random() * 90;
        sp.style.cssText = `
            font-size:${1+Math.random()*1.5}rem;
            left:${cx + Math.cos(angle)*radius - 16}px;
            top:${cy  + Math.sin(angle)*radius - 16}px;
            animation-delay:${Math.random()*.45}s;`;
        sp.textContent = EMOJIS[Math.floor(Math.random()*EMOJIS.length)];
        document.body.appendChild(sp);
        setTimeout(() => sp.remove(), 1300);
    }
}

// ── Accept animation ──────────────────────────────────
function triggerAccepted(workerName) {
    matchFound = true;
    clearInterval(timerInterval);
    clearInterval(pollInterval);

    // 1. Shrink + fade HUD
    mainHud.style.opacity   = '0';
    mainHud.style.transform = 'scale(.82)';

    setTimeout(() => {
        mainHud.style.display = 'none';

        // 2. City fades out
        citySceneEl.style.opacity = '0';

        // 3. Ripple collapses from center
        collapseEl.style.opacity   = '1';
        collapseEl.style.transform = 'scale(2.8)';

        spawnParticles(40);
    }, 360);

    // 4. After ripple peaks, reveal success
    setTimeout(() => {
        collapseEl.style.transition = 'opacity .35s ease';
        collapseEl.style.opacity    = '0';

        successOvl.style.pointerEvents = 'auto';
        successOvl.style.opacity       = '1';

        // Bounce in handshake
        handshakeEl.style.opacity   = '1';
        handshakeEl.style.transform = 'scale(1) rotate(0deg)';

        spawnSparkles();

        // Stagger in text
        setTimeout(() => {
            if (workerName) matchSubEl.textContent = `${workerName} is on their way to help you.`;
            matchTitleEl.style.opacity   = '1';
            matchTitleEl.style.transform = 'translateY(0)';
        }, 380);

        setTimeout(() => {
            matchSubEl.style.opacity   = '1';
            matchSubEl.style.transform = 'translateY(0)';
        }, 580);

        setTimeout(() => {
            redirectEl.style.opacity   = '1';
            redirectEl.style.transform = 'translateY(0)';
            spawnSparkles();
        }, 780);

        // Extra sparkle bursts
        setTimeout(spawnSparkles, 1050);
        setTimeout(() => spawnParticles(28), 1200);

    }, 950);
}

// ── Polling ───────────────────────────────────────────
const pollInterval = setInterval(() => {
    if (matchFound) return;
    fetch(`check_match_status.php?broadcast_id=${broadcastId}`)
        .then(r => r.json())
        .then(data => {
            if (data.status === 'accepted' && !matchFound) {
                try { notifSound.play(); } catch(e) {}
                if (Notification.permission === 'granted') {
                    new Notification('✅ Match Found!', {
                        body: `${data.worker_name || 'A worker'} accepted your job!`,
                        icon: '/abilisto/assets/icon.png'
                    });
                }
                triggerAccepted(data.worker_name || '');
                setTimeout(() => {
                    window.location.href = `../chat.php?booking_id=${data.booking_id}`;
                }, 4000);
            }
        })
        .catch(() => {});
}, 2000);

// Check on visibility change
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !matchFound) {
        fetch(`check_match_status.php?broadcast_id=${broadcastId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'accepted' && !matchFound) {
                    try { notifSound.play(); } catch(e) {}
                    triggerAccepted(data.worker_name || '');
                    setTimeout(() => {
                        window.location.href = `../chat.php?booking_id=${data.booking_id}`;
                    }, 4000);
                }
            })
            .catch(() => {});
    }
});

// WebSocket support (if enabled)
<?php if (isset($_GET['use_socket']) && $_GET['use_socket'] == 1): ?>
const socket = io('http://localhost:3001', { transports: ['websocket','polling'] });
socket.on('connect', () => socket.emit('join_broadcast', { broadcast_id: broadcastId }));
socket.on('worker_accepted', data => {
    if (!matchFound) {
        try { notifSound.play(); } catch(e) {}
        triggerAccepted(data.worker_name || '');
        setTimeout(() => {
            window.location.href = `../chat.php?booking_id=${data.booking_id}`;
        }, 4000);
    }
});
<?php endif; ?>

// ── Cancel Search ─────────────────────────────────────
async function cancelSearch() {
    if (!confirm('Cancel your search? Workers will no longer be notified.')) return;

    const btn = document.getElementById('cancelBtn');
    btn.textContent = 'Cancelling...';
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.5';

    try {
        const res = await fetch('../api/cancel_broadcast.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ broadcast_id: broadcastId })
        });

        const data = await res.json();

        if (data.success) {
            // Stop all polling/timers so nothing fires after cancel
            clearInterval(timerInterval);
            clearInterval(pollInterval);
            window.location.href = 'dashboard.php';
        } else {
            // If a worker just accepted, let the UI know
            alert(data.message || 'Could not cancel. Please try again.');
            btn.textContent = 'Cancel Search';
            btn.style.pointerEvents = '';
            btn.style.opacity = '';
        }
    } catch (err) {
        alert('Network error. Please try again.');
        btn.textContent = 'Cancel Search';
        btn.style.pointerEvents = '';
        btn.style.opacity = '';
    }
}

// Notification permission
if (Notification.permission === 'default') Notification.requestPermission();
</script>
</body>
</html>