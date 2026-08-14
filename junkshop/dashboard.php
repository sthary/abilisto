<?php
// ============================================================
// junkshop/dashboard.php  —  GreenLoop Junk Shop Partner
// ============================================================
include __DIR__ . '/../db_connect.php';   // $conn (PDO)

// ── Auth Guard ────────────────────────────────────────────────
if (empty($_SESSION['junkshop_id'])) {
    header('Location: login.php');
    exit;
}

$js_id   = (int)$_SESSION['junkshop_id'];
$js_name = htmlspecialchars($_SESSION['junkshop_name']  ?? 'My Shop');
$js_own  = htmlspecialchars($_SESSION['junkshop_owner'] ?? '');

// Fetch live shop data
$stmt = $conn->prepare("SELECT * FROM junkshops WHERE id = ? LIMIT 1");
$stmt->execute([$js_id]);
$shop = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $js_name ?> — GreenLoop Partner</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: { sans: ['Sora','sans-serif'], mono: ['JetBrains Mono','monospace'] },
        colors: {
          eco: {
            50:'#f0fdf4', 100:'#dcfce7', 200:'#bbf7d0', 300:'#86efac',
            400:'#4ade80', 500:'#22c55e', 600:'#16a34a', 700:'#15803d',
            800:'#166534', 900:'#14532d', 950:'#052e16'
          }
        }
      }
    }
  };
</script>
<style>
  body { font-family: 'Sora', sans-serif; }

  /* ── Animations ─────────────────────────────── */
  @keyframes card-in  { from{opacity:0;transform:translateY(12px) scale(0.98)} to{opacity:1;transform:none} }
  @keyframes card-out { to{opacity:0;transform:scale(0.96);max-height:0;padding:0;margin:0;border:none;overflow:hidden} }
  .card-in  { animation: card-in  0.32s cubic-bezier(0.34,1.56,0.64,1) forwards; }
  .card-out { animation: card-out 0.25s ease forwards; }

  @keyframes ticker { 0%,100%{opacity:1} 50%{opacity:0.35} }
  .ticker { animation: ticker 1.8s step-end infinite; }

  /* ── Cards ──────────────────────────────────── */
  .scrap-card { transition: box-shadow 0.2s ease, transform 0.2s ease; }
  .scrap-card:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(22,163,74,0.13); }
  .pickup-card { transition: box-shadow 0.15s ease; }
  .pickup-card:hover { box-shadow: 0 4px 18px rgba(22,163,74,0.10); }

  /* ── Scrollbar ──────────────────────────────── */
  ::-webkit-scrollbar { width: 4px; height: 4px; }
  ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

  /* ── Bottom nav (mobile) ────────────────────── */
  .bottom-nav-safe { padding-bottom: env(safe-area-inset-bottom, 0); }

  /* ── Info pill (address / phone) ────────────── */
  .info-pill {
    display: inline-flex; align-items: flex-start; gap: 5px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 5px 9px;
    font-size: 11px;
    color: #15803d;
    line-height: 1.4;
    word-break: break-word;
  }
  .info-pill .material-icons-round { font-size: 13px; margin-top: 1px; flex-shrink: 0; color: #16a34a; }

  .info-pill-phone {
    display: inline-flex; align-items: center; gap: 5px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 5px 9px;
    font-size: 11px;
    color: #1d4ed8;
  }
  .info-pill-phone .material-icons-round { font-size: 13px; flex-shrink: 0; color: #2563eb; }
</style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">

<div class="flex min-h-screen">

  <!-- ══ SIDEBAR (desktop only) ════════════════════════════ -->
  <aside class="hidden lg:flex flex-col w-64 bg-white border-r border-gray-200 fixed inset-y-0 left-0 z-40 shadow-sm">

    <!-- Brand -->
    <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-eco-950 border border-eco-800 flex items-center justify-center text-xl shrink-0">♻️</div>
      <div>
        <p class="font-bold text-sm leading-none text-gray-900">GreenLoop</p>
        <p class="text-[10px] font-mono text-eco-600 uppercase tracking-widest mt-0.5">Partner Portal</p>
      </div>
    </div>

    <!-- Shop card -->
    <div class="px-4 py-4 border-b border-gray-100">
      <div class="bg-eco-50 border border-eco-200 rounded-xl p-3">
        <p class="font-bold text-sm text-gray-900 truncate"><?= $js_name ?></p>
        <p class="text-xs text-gray-500 truncate mt-0.5"><?= htmlspecialchars($shop['coverage_area'] ?? 'Coverage not set') ?></p>
        <div class="flex items-center gap-2 mt-2.5">
          <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-eco-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-eco-500"></span>
          </span>
          <span class="text-[10px] font-bold text-eco-600 uppercase tracking-widest font-mono">Online</span>
        </div>
      </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 p-3 space-y-1">
      <button id="nav-feed" onclick="showTab('feed')"
        class="nav-btn w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-left text-eco-700 bg-eco-50 border border-eco-200">
        <span class="material-icons-round text-eco-600 text-base">cell_tower</span>
        Live Feed
        <span id="feed-badge" class="ml-auto hidden min-w-[20px] h-5 bg-eco-500 rounded-full text-[10px] font-bold flex items-center justify-center text-white px-1">0</span>
      </button>
      <button id="nav-pickups" onclick="showTab('pickups')"
        class="nav-btn w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-left text-gray-500 hover:bg-gray-50 hover:text-gray-800 transition-colors">
        <span class="material-icons-round text-gray-400 text-base">local_shipping</span>
        My Pickups
        <span id="pickup-badge" class="ml-auto hidden min-w-[20px] h-5 bg-violet-500 rounded-full text-[10px] font-bold flex items-center justify-center text-white px-1">0</span>
      </button>
    </nav>

    <!-- User footer -->
    <div class="px-4 py-3 border-t border-gray-100 flex items-center gap-3">
      <div class="w-8 h-8 rounded-full bg-eco-100 border border-eco-200 flex items-center justify-center text-sm font-bold text-eco-700 shrink-0">
        <?= strtoupper(substr($js_own ?: $js_name, 0, 1)) ?>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-semibold truncate text-gray-800"><?= $js_own ?: $js_name ?></p>
        <p class="text-[10px] text-gray-400">Partner account</p>
      </div>
      <a href="logout.php" class="text-gray-300 hover:text-red-400 transition-colors" title="Log out">
        <span class="material-icons-round text-lg">logout</span>
      </a>
    </div>
  </aside>

  <!-- ══ MAIN ═══════════════════════════════════════════════ -->
  <div class="flex-1 lg:ml-64 flex flex-col min-h-screen pb-16 lg:pb-0">

    <!-- Top bar -->
    <header class="sticky top-0 z-30 bg-white/95 backdrop-blur border-b border-gray-200 px-4 py-3 flex items-center justify-between gap-3 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="lg:hidden w-8 h-8 rounded-lg bg-eco-950 flex items-center justify-center text-base shrink-0">♻️</div>
        <div>
          <p class="font-bold text-sm text-gray-900" id="page-title">Live Broadcast Feed</p>
          <p class="text-[10px] font-mono text-gray-400" id="poll-label">Connecting…</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <!-- Live indicator -->
        <div class="flex items-center gap-1.5 bg-eco-50 border border-eco-200 rounded-full px-3 py-1">
          <span class="relative flex h-2 w-2">
            <span id="pulse-ring" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-eco-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-eco-500"></span>
          </span>
          <span class="text-[10px] font-mono text-eco-600 font-bold">LIVE · 5s</span>
        </div>
        <span class="text-[10px] font-mono text-gray-400 hidden sm:block" id="ts-label">—</span>
      </div>
    </header>

    <!-- Stats strip -->
    <div class="grid grid-cols-3 gap-3 px-4 pt-4">
      <div class="bg-white border border-gray-200 rounded-2xl p-3 text-center shadow-sm">
        <p class="text-xl font-bold text-eco-600" id="s-live">—</p>
        <p class="text-[10px] font-mono text-gray-400 uppercase tracking-wider mt-0.5">Live Now</p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-3 text-center shadow-sm">
        <p class="text-xl font-bold text-violet-500" id="s-acc">0</p>
        <p class="text-[10px] font-mono text-gray-400 uppercase tracking-wider mt-0.5">Accepted</p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-3 text-center shadow-sm">
        <p class="text-xl font-bold text-amber-500" id="s-done">0</p>
        <p class="text-[10px] font-mono text-gray-400 uppercase tracking-wider mt-0.5">Completed</p>
      </div>
    </div>

    <!-- ── TAB: FEED ──────────────────────────────────────── -->
    <section id="tab-feed" class="flex-1 p-4">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-eco-500 ticker inline-block"></span>
          <span class="text-xs font-bold font-mono text-eco-600 uppercase tracking-widest">BROADCAST</span>
        </div>
        <span class="text-xs text-gray-400 font-mono" id="feed-count">—</span>
      </div>

      <!-- Skeleton -->
      <div id="feed-skeleton" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php for($i=0;$i<4;$i++): ?>
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden animate-pulse shadow-sm">
          <div class="h-32 bg-gray-100"></div>
          <div class="p-4 space-y-2">
            <div class="h-4 bg-gray-100 rounded w-2/3"></div>
            <div class="h-3 bg-gray-50 rounded w-1/2"></div>
            <div class="h-10 bg-gray-100 rounded-xl mt-3"></div>
          </div>
        </div>
        <?php endfor; ?>
      </div>

      <div id="feed-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 hidden"></div>
      <div id="feed-empty" class="hidden text-center py-24">
        <div class="text-6xl mb-4 opacity-20">📡</div>
        <p class="text-gray-400 font-semibold">No broadcasts available.</p>
        <p class="text-xs text-gray-300 mt-1 font-mono">Polling every 5 s…</p>
      </div>
    </section>

    <!-- ── TAB: MY PICKUPS ────────────────────────────────── -->
    <section id="tab-pickups" class="flex-1 p-4 hidden">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-sm text-gray-900 flex items-center gap-2">
          <span class="material-icons-round text-violet-500 text-base">local_shipping</span> My Pickups
        </h2>
        <button onclick="loadPickups()" class="text-xs font-mono text-gray-400 hover:text-gray-700 flex items-center gap-1 transition-colors">
          <span class="material-icons-round text-sm">refresh</span> Refresh
        </button>
      </div>
      <div id="pickups-list" class="space-y-3">
        <div class="text-center text-gray-400 text-sm font-mono py-10 animate-pulse">Loading…</div>
      </div>
    </section>

  </div><!-- /main -->
</div><!-- /shell -->

<!-- ══ BOTTOM NAV (mobile only) ══════════════════════════ -->
<nav class="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-white border-t border-gray-200 shadow-lg bottom-nav-safe">
  <div class="flex">
    <button onclick="showTab('feed')" id="mob-nav-feed"
      class="mob-nav-btn flex-1 flex flex-col items-center gap-1 py-3 text-eco-600 border-t-2 border-eco-500">
      <span class="material-icons-round text-xl">cell_tower</span>
      <span class="text-[10px] font-bold font-mono">LIVE FEED</span>
    </button>
    <button onclick="showTab('pickups')" id="mob-nav-pickups"
      class="mob-nav-btn flex-1 flex flex-col items-center gap-1 py-3 text-gray-400 border-t-2 border-transparent">
      <span class="material-icons-round text-xl">local_shipping</span>
      <span class="text-[10px] font-bold font-mono">PICKUPS</span>
    </button>
  </div>
</nav>

<!-- ══ ACCEPT CONFIRM MODAL ══════════════════════════════ -->
<div id="acc-modal" class="hidden fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-end sm:items-center justify-center p-4">
  <div class="bg-white border border-gray-200 rounded-3xl shadow-2xl w-full max-w-sm p-6 card-in">
    <div class="text-center mb-5">
      <div class="w-14 h-14 rounded-2xl bg-eco-50 border border-eco-200 flex items-center justify-center mx-auto mb-3 text-3xl">🚛</div>
      <h3 class="font-bold text-lg text-gray-900">Accept Pickup?</h3>
      <p class="text-sm text-gray-500 mt-1 line-clamp-2" id="m-desc">—</p>
    </div>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-center">
      <p class="text-[10px] font-mono text-gray-500 uppercase tracking-widest mb-1">Estimated Reward</p>
      <p class="text-3xl font-bold text-amber-500 font-mono" id="m-coins">—</p>
      <p class="text-xs text-gray-400 mt-0.5">Green Coins</p>
    </div>
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5 mb-5 flex items-start gap-2">
      <span class="text-amber-500 mt-0.5 shrink-0">⚡</span>
      <p class="text-xs text-amber-700">First to accept wins. Item is immediately removed from all other shops' feeds.</p>
    </div>
    <div class="flex gap-3">
      <button onclick="closeAcc()" class="flex-1 py-3 rounded-xl bg-gray-100 text-sm font-semibold text-gray-500 hover:bg-gray-200 hover:text-gray-700 transition-colors">Cancel</button>
      <button onclick="confirmAcc()" id="btn-acc"
        class="flex-1 py-3 rounded-xl bg-eco-600 hover:bg-eco-500 active:scale-95 text-white text-sm font-bold transition-all flex items-center justify-center gap-2">
        <span class="material-icons-round text-base">check_circle</span> Accept
      </button>
    </div>
  </div>
</div>

<!-- ══ TOAST ══════════════════════════════════════════════ -->
<div id="toasts" class="fixed bottom-20 lg:bottom-5 right-4 z-50 flex flex-col gap-2 max-w-xs pointer-events-none"></div>

<script>
// ── Config ────────────────────────────────────────────────────
const API = '../greenloop/junkshop_api.php';
const POLL_MS = 5000;

let cardMap      = {};
let accId        = null;
let accCoins     = 0;
let pollTimer    = null;

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  pollFeed();
  pollTimer = setInterval(pollFeed, POLL_MS);
  loadPickups();
  loadPickupStats();
  setInterval(loadPickupStats, 12000);
});

// ── Polling ───────────────────────────────────────────────────
async function pollFeed() {
  try {
    const d = await apiGet('poll_feed');
    renderFeed(d.feed || []);
    document.getElementById('poll-label').textContent = 'Live · polls every 5 s';
    document.getElementById('ts-label').textContent   = new Date().toLocaleTimeString('en-PH');
    document.getElementById('s-live').textContent     = (d.feed || []).length;
    document.getElementById('pulse-ring').className   = 'animate-ping absolute inline-flex h-full w-full rounded-full bg-eco-400 opacity-75';
  } catch(e) {
    document.getElementById('poll-label').textContent = 'Reconnecting…';
    document.getElementById('pulse-ring').className   = 'animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75';
    if (e.redirect) window.location.href = e.redirect;
  }
}

// ── Feed diff-render ──────────────────────────────────────────
function renderFeed(items) {
  const grid  = document.getElementById('feed-grid');
  const empty = document.getElementById('feed-empty');
  const skel  = document.getElementById('feed-skeleton');

  if (skel && !skel.classList.contains('hidden')) {
    skel.classList.add('hidden');
    grid.classList.remove('hidden');
  }

  const incoming = new Set(items.map(i => String(i.id)));

  Object.keys(cardMap).forEach(id => {
    if (!incoming.has(id)) {
      const c = cardMap[id];
      c.classList.add('card-out');
      setTimeout(() => { c.remove(); delete cardMap[id]; }, 280);
    }
  });

  items.forEach((item, idx) => {
    const id = String(item.id);
    if (!cardMap[id]) {
      const c = makeFeedCard(item, idx);
      grid.appendChild(c);
      cardMap[id] = c;
    }
  });

  const any = Object.keys(cardMap).length > 0;
  grid.classList.toggle('hidden', !any);
  empty.classList.toggle('hidden', any);

  const badge = document.getElementById('feed-badge');
  const count = items.length;
  document.getElementById('feed-count').textContent = `${count} item${count!==1?'s':''} available`;
  if (count > 0) { badge.textContent = count; badge.classList.remove('hidden'); }
  else badge.classList.add('hidden');
}

// ── Feed card builder ─────────────────────────────────────────
function makeFeedCard(item, idx) {
  const imgSrc = item.image_path
    ? `../uploads/greenloop/${item.image_path}`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(item.scrap_type||'?')}&background=166534&color=4ade80&size=80&bold=true`;

  const ago = timeAgo(item.broadcasted_at);

  // Location & phone info rows
  const phoneRow = item.client_phone
    ? `<div class="info-pill-phone mt-2 w-full">
        <span class="material-icons-round">phone</span>
        <a href="tel:${esc(item.client_phone)}" class="font-mono font-bold hover:underline">${esc(item.client_phone)}</a>
       </div>`
    : '';

  const addrRow = item.pickup_address
    ? `<div class="info-pill mt-1.5 w-full">
        <span class="material-icons-round">location_on</span>
        <span class="break-words">${esc(item.pickup_address)}</span>
       </div>`
    : '';

  const card = document.createElement('div');
  card.id = `fc-${item.id}`;
  card.className = 'scrap-card card-in bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm';
  card.style.animationDelay = `${idx * 55}ms`;

  card.innerHTML = `
    <div class="relative h-36 bg-gray-100 overflow-hidden">
      <img src="${imgSrc}" class="w-full h-full object-cover" loading="lazy">
      <div class="absolute inset-0 bg-gradient-to-t from-white/60 to-transparent"></div>
      <div class="absolute top-2.5 left-2.5 flex items-center gap-1.5 bg-eco-600/90 backdrop-blur-sm text-white text-[10px] font-bold px-2 py-1 rounded-full">
        <span class="w-1.5 h-1.5 rounded-full bg-eco-200 animate-ping inline-block"></span> LIVE
      </div>
      <div class="absolute top-2.5 right-2.5 bg-amber-400/95 backdrop-blur-sm text-amber-900 text-[11px] font-bold px-2 py-1 rounded-full">
        🪙 ${item.estimated_green_coins ?? 0}
      </div>
      <div class="absolute bottom-2.5 left-3 right-3">
        <p class="font-bold text-gray-900 text-sm leading-tight truncate drop-shadow-sm">${esc(item.scrap_type || 'Scrap Item')}</p>
        <p class="text-[10px] font-mono text-gray-600 mt-0.5">${item.quantity??1} ${esc(item.unit||'pcs')} · ${ago}</p>
      </div>
    </div>
    <div class="p-4">
      <div class="flex items-center gap-2 mb-2">
        <span class="material-icons-round text-gray-400 text-base">person_pin</span>
        <div class="min-w-0">
          <p class="text-xs font-semibold text-gray-800 truncate">${esc(item.client_name)}</p>
          <p class="text-[10px] font-mono text-gray-400 truncate">${esc(item.client_email)}</p>
        </div>
      </div>
      ${phoneRow}
      ${addrRow}
      ${item.client_notes ? `
      <div class="bg-gray-50 rounded-xl px-3 py-2 mt-2 mb-3 border border-gray-100">
        <p class="text-xs text-gray-500 italic line-clamp-2">"${esc(item.client_notes)}"</p>
      </div>` : '<div class="mb-3"></div>'}
      <button onclick="openAcc(${item.id}, '${esc(item.scrap_type)}', ${item.estimated_green_coins??0})"
        class="w-full py-2.5 rounded-xl bg-eco-600 hover:bg-eco-500 active:scale-95 text-white font-bold text-sm
               transition-all flex items-center justify-center gap-2 group">
        <span class="material-icons-round text-base group-hover:scale-110 transition-transform">local_shipping</span>
        Accept Pickup
      </button>
    </div>`;
  return card;
}

// ── Accept modal ──────────────────────────────────────────────
function openAcc(id, name, coins) {
  accId = id; accCoins = coins;
  document.getElementById('m-desc').textContent  = name;
  document.getElementById('m-coins').textContent = coins;
  document.getElementById('acc-modal').classList.remove('hidden');
}
function closeAcc() { document.getElementById('acc-modal').classList.add('hidden'); accId = null; }
document.getElementById('acc-modal').addEventListener('click', e => { if (e.target===document.getElementById('acc-modal')) closeAcc(); });

async function confirmAcc() {
  if (!accId) return;
  const btn = document.getElementById('btn-acc');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-icons-round text-base animate-spin">sync</span> Accepting…';

  try {
    const d = await apiPost({ action: 'accept', report_id: accId });

    if (d.already_taken) {
      toast('😬 ' + d.message, 'warn'); closeAcc(); return;
    }

    toast('🚛 ' + d.message, 'ok');
    closeAcc();

    const card = cardMap[String(accId)];
    if (card) { card.classList.add('card-out'); setTimeout(() => { card.remove(); delete cardMap[String(accId)]; }, 280); }

    setTimeout(() => { loadPickups(); loadPickupStats(); }, 500);

  } catch(e) {
    toast(e.message || 'Failed to accept.', 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<span class="material-icons-round text-base">check_circle</span> Accept';
  }
}

// ── My Pickups ────────────────────────────────────────────────
async function loadPickups() {
  const list = document.getElementById('pickups-list');
  try {
    const d = await apiGet('my_pickups');
    const pickups = d.pickups || [];

    const active = pickups.filter(p => p.status === 'accepted').length;
    const badge  = document.getElementById('pickup-badge');
    if (active) { badge.textContent = active; badge.classList.remove('hidden'); }
    else badge.classList.add('hidden');

    if (!pickups.length) {
      list.innerHTML = `
        <div class="text-center py-20">
          <div class="text-5xl opacity-20 mb-3">🚛</div>
          <p class="text-gray-400 font-semibold text-sm">No pickups yet.</p>
          <p class="text-xs text-gray-300 mt-1 font-mono">Accept items from the Live Feed.</p>
        </div>`;
      return;
    }
    list.innerHTML = pickups.map(pickupCard).join('');

  } catch(e) {
    list.innerHTML = `<p class="text-red-400 text-sm text-center py-6">${esc(e.message)}</p>`;
  }
}

function pickupCard(p) {
  const done = p.status === 'completed';
  const img  = p.image_path
    ? `../uploads/greenloop/${p.image_path}`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.scrap_type||'?')}&background=166534&color=4ade80&size=80&bold=true`;

  const phoneRow = p.client_phone
    ? `<div class="info-pill-phone mt-1.5">
         <span class="material-icons-round">phone</span>
         <a href="tel:${esc(p.client_phone)}" class="font-mono font-bold hover:underline">${esc(p.client_phone)}</a>
       </div>`
    : '';

  const addrRow = p.pickup_address
    ? `<div class="info-pill mt-1.5">
         <span class="material-icons-round">location_on</span>
         <span class="break-words">${esc(p.pickup_address)}</span>
       </div>`
    : '';

  const mapsLink = (p.pickup_latitude && p.pickup_longitude)
    ? `<a href="https://www.google.com/maps?q=${p.pickup_latitude},${p.pickup_longitude}" target="_blank" rel="noopener"
          class="inline-flex items-center gap-1 text-[10px] font-mono text-eco-600 hover:underline mt-1">
         <span class="material-icons-round text-xs">open_in_new</span> Open in Maps
       </a>`
    : '';

  return `
  <div id="pc-${p.id}" class="pickup-card bg-white border ${done?'border-eco-200':'border-gray-200'} rounded-2xl p-4 shadow-sm">
    <div class="flex gap-3 items-start">
      <div class="w-14 h-14 rounded-xl overflow-hidden bg-gray-100 border border-gray-200 shrink-0">
        <img src="${img}" class="w-full h-full object-cover">
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-start justify-between gap-2 mb-1">
          <p class="font-bold text-sm leading-snug text-gray-900">${esc(p.scrap_type||'—')}</p>
          <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold font-mono border
            ${done
              ? 'bg-eco-50 text-eco-700 border-eco-200'
              : 'bg-violet-50 text-violet-600 border-violet-200'}">
            <span class="material-icons-round text-xs">${done ? 'check_circle' : 'local_shipping'}</span>
            ${done ? 'Completed' : 'In Progress'}
          </span>
        </div>

        <p class="text-xs text-gray-500 mb-1.5">
          <span class="material-icons-round text-xs align-middle">person</span>
          ${esc(p.client_name)} · ${p.quantity??1} ${esc(p.unit||'pcs')}
        </p>

        <!-- Phone & Address -->
        <div class="flex flex-col gap-0.5 mb-2">
          ${phoneRow}
          ${addrRow}
          ${mapsLink}
        </div>

        <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-400 font-mono mb-3">
          <span>🪙 ${p.estimated_green_coins ?? 0} coins</span>
          <span>Accepted ${fmtDate(p.accepted_at)}</span>
          ${done ? `<span class="text-eco-600">✓ ${fmtDate(p.completed_at)}</span>` : ''}
        </div>

        ${p.client_notes ? `<p class="text-xs text-gray-400 italic mb-3 line-clamp-2">"${esc(p.client_notes)}"</p>` : ''}

        ${!done ? `
        <button onclick="complete(${p.id})" id="cb-${p.id}"
          class="flex items-center gap-2 px-4 py-2 rounded-xl bg-eco-600 hover:bg-eco-500 active:scale-95
                 text-white text-xs font-bold transition-all">
          <span class="material-icons-round text-sm">task_alt</span> Mark as Completed
        </button>` : `
        <div class="flex items-center gap-1.5 text-eco-600 text-xs font-bold">
          <span class="material-icons-round text-sm">verified</span>
          Pickup complete
        </div>`}
      </div>
    </div>
  </div>`;
}

// ── Complete pickup ───────────────────────────────────────────
async function complete(id) {
  const btn = document.getElementById(`cb-${id}`);
  if (btn) { btn.disabled=true; btn.innerHTML='<span class="material-icons-round text-sm animate-spin">sync</span> Updating…'; }
  try {
    const d = await apiPost({ action: 'complete', report_id: id });
    toast('✅ ' + d.message, 'ok');
    setTimeout(loadPickups, 400);
    loadPickupStats();
  } catch(e) {
    if (btn) { btn.disabled=false; btn.innerHTML='<span class="material-icons-round text-sm">task_alt</span> Mark as Completed'; }
    toast(e.message, 'err');
  }
}

// ── Pickup stats ──────────────────────────────────────────────
async function loadPickupStats() {
  try {
    const d = await apiGet('my_pickups');
    const pickups = d.pickups || [];
    document.getElementById('s-acc').textContent  = pickups.filter(p=>p.status==='accepted').length;
    document.getElementById('s-done').textContent = pickups.filter(p=>p.status==='completed').length;
  } catch(e){}
}

// ── Tab switching ─────────────────────────────────────────────
function showTab(tab) {
  ['feed','pickups'].forEach(t => {
    document.getElementById(`tab-${t}`).classList.toggle('hidden', t !== tab);

    // Desktop sidebar
    const btn = document.getElementById(`nav-${t}`);
    if (t === tab) {
      btn.classList.add('text-eco-700','bg-eco-50','border','border-eco-200');
      btn.classList.remove('text-gray-500','hover:bg-gray-50','hover:text-gray-800');
      btn.querySelector('.material-icons-round').classList.replace('text-gray-400','text-eco-600');
    } else {
      btn.classList.remove('text-eco-700','bg-eco-50','border','border-eco-200');
      btn.classList.add('text-gray-500','hover:bg-gray-50','hover:text-gray-800');
      btn.querySelector('.material-icons-round').classList.replace('text-eco-600','text-gray-400');
    }

    // Mobile bottom nav
    const mob = document.getElementById(`mob-nav-${t}`);
    if (t === tab) {
      mob.classList.add('text-eco-600','border-eco-500');
      mob.classList.remove('text-gray-400','border-transparent');
    } else {
      mob.classList.remove('text-eco-600','border-eco-500');
      mob.classList.add('text-gray-400','border-transparent');
    }
  });

  document.getElementById('page-title').textContent = tab === 'feed' ? 'Live Broadcast Feed' : 'My Pickups';
  if (tab === 'pickups') loadPickups();
}

// ── API helpers ───────────────────────────────────────────────
async function apiGet(action) {
  const r = await fetch(`${API}?action=${action}`);
  const d = await r.json();
  if (d.error) { const e = new Error(d.error); e.redirect = d.redirect; throw e; }
  return d;
}
async function apiPost(body) {
  const r = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.error) { const e = new Error(d.error); e.redirect = d.redirect; throw e; }
  return d;
}

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
  const MAP = {
    ok:   'bg-eco-50 border-eco-200 text-eco-800',
    err:  'bg-red-50 border-red-200 text-red-700',
    warn: 'bg-amber-50 border-amber-200 text-amber-700'
  };
  const el = document.createElement('div');
  el.className = `${MAP[type]||MAP.ok} border px-4 py-3 rounded-xl text-sm font-semibold shadow-lg card-in pointer-events-auto`;
  el.textContent = msg;
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => { el.style.transition='opacity 0.3s'; el.style.opacity='0'; setTimeout(()=>el.remove(), 320); }, 4000);
}

// ── Tiny utils ────────────────────────────────────────────────
function esc(s)  { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmtDate(s) {
  if (!s) return '—';
  return new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
}
function timeAgo(dt) {
  if (!dt) return '—';
  const sec = Math.floor((Date.now() - new Date(dt)) / 1000);
  if (sec < 60)   return `${sec}s ago`;
  if (sec < 3600) return `${Math.floor(sec/60)}m ago`;
  return `${Math.floor(sec/3600)}h ago`;
}
</script>
</body>
</html>