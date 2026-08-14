<?php
// admin/greenloop_reports.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$admin_res = $conn->query("SELECT full_name, profile_pic FROM users WHERE id = $admin_id");
$admin     = $admin_res->fetch_assoc();

$notif_res   = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = $admin_id AND is_read = 0");
$notif_count = $notif_res->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GreenLoop Reports — Abilisto Admin</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: {
      extend: {
        fontFamily: { display: ['Inter','sans-serif'], sans: ['Inter','sans-serif'] },
        colors: {
          primary: '#6366F1',
          'background-light': '#F8FAFC',
          'background-dark':  '#0F172A',
        },
        borderRadius: { DEFAULT: '12px', xl: '16px', '2xl': '24px' }
      }
    }
  };
  function toggleDarkMode() {
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
  }
  if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');
</script>
<style>
  body { font-family: 'Inter', sans-serif; }
  .glass {
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.3);
  }
  .dark .glass { background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.1); }
  .stat-shadow { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.04), 0 8px 10px -6px rgba(0,0,0,0.04); }
  @keyframes slide-up { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
  .slide-up { animation: slide-up 0.25s ease forwards; }
  @keyframes fade-shrink { to{opacity:0;transform:scale(0.97);max-height:0;overflow:hidden;margin-bottom:0;padding:0} }
  .fade-shrink { animation: fade-shrink 0.28s ease forwards; }
  .report-row { transition: box-shadow 0.15s ease; }
  .report-row:hover { box-shadow: 0 4px 24px rgba(99,102,241,0.10); }
  .img-zoom { transition: transform 0.3s ease; }
  .img-zoom:hover { transform: scale(1.06); }
</style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<?php include 'navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

  <!-- ── PAGE HEADER ─────────────────────────────────────── -->
  <header class="glass sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between stat-shadow">
    <div class="flex items-center gap-3">
      <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg" onclick="toggleMobileMenu?.()">
        <span class="material-icons-round text-slate-500">menu</span>
      </button>
      <span class="text-xl">♻️</span>
      <div>
        <h1 class="font-bold text-base leading-tight">GreenLoop Reports</h1>
        <p class="text-[10px] text-slate-400 uppercase tracking-wider">Review &amp; Broadcast Scrap</p>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 transition-all">
        <span class="material-icons-round text-xl">dark_mode</span>
      </button>
      <button onclick="loadReports()" class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
        <span class="material-icons-round text-base">refresh</span>
        <span class="hidden sm:inline">Refresh</span>
      </button>
    </div>
  </header>

  <!-- ── STAT CARDS ──────────────────────────────────────── -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <?php
    $stats = [
      ['id'=>'stat-pending',     'icon'=>'hourglass_empty', 'label'=>'Pending',     'color'=>'amber'],
      ['id'=>'stat-broadcasted', 'icon'=>'cell_tower',      'label'=>'Live',        'color'=>'blue'],
      ['id'=>'stat-accepted',    'icon'=>'local_shipping',  'label'=>'Accepted',    'color'=>'purple'],
      ['id'=>'stat-completed',   'icon'=>'check_circle',    'label'=>'Completed',   'color'=>'emerald'],
    ];
    foreach($stats as $s): ?>
    <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl stat-shadow border border-slate-100 dark:border-slate-800">
      <div class="flex items-center gap-2 mb-2">
        <div class="p-1.5 bg-<?=$s['color']?>-100 dark:bg-<?=$s['color']?>-900/30 text-<?=$s['color']?>-600 dark:text-<?=$s['color']?>-400 rounded-lg">
          <span class="material-icons-round text-base"><?=$s['icon']?></span>
        </div>
        <span class="text-xs font-medium text-slate-500 dark:text-slate-400"><?=$s['label']?></span>
      </div>
      <div class="text-2xl font-bold" id="<?=$s['id']?>">—</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── FILTER BAR ──────────────────────────────────────── -->
  <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl px-4 py-3 mb-5 flex flex-col sm:flex-row items-start sm:items-center gap-3">
    <div class="flex items-center bg-slate-100 dark:bg-slate-800 px-3 py-2 rounded-xl flex-1 max-w-xs">
      <span class="material-icons-round text-slate-400 text-base mr-2">search</span>
      <input id="q" type="text" placeholder="Search name, scrap type…"
             class="bg-transparent border-0 focus:ring-0 text-sm w-full p-0 placeholder:text-slate-400 dark:text-white">
    </div>
    <div class="flex items-center gap-2 sm:ml-auto flex-wrap">
      <span class="text-xs text-slate-500">View:</span>
      <div class="flex rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 text-xs font-semibold" id="status-tabs">
        <button data-filter="pending,rejected"  onclick="setFilter(this)" class="filter-btn active-filter px-3 py-2">Pending</button>
        <button data-filter="broadcasted"       onclick="setFilter(this)" class="filter-btn px-3 py-2">Live</button>
        <button data-filter="accepted"          onclick="setFilter(this)" class="filter-btn px-3 py-2">Accepted</button>
        <button data-filter="completed"         onclick="setFilter(this)" class="filter-btn px-3 py-2">Done</button>
        <button data-filter=""                  onclick="setFilter(this)" class="filter-btn px-3 py-2">All</button>
      </div>
    </div>
  </div>

  <style>
    .filter-btn { background: white; color: #64748b; transition: all 0.15s; }
    .dark .filter-btn { background: rgb(15,23,42); color: #94a3b8; }
    .filter-btn:hover { background: #f1f5f9; color: #1e293b; }
    .dark .filter-btn:hover { background: #1e293b; }
    .active-filter { background: #6366F1 !important; color: white !important; }
  </style>

  <!-- ── REPORTS CONTAINER ───────────────────────────────── -->
  <div id="reports-wrap" class="space-y-4">
    <!-- Skeleton -->
    <div id="skeleton">
      <?php for($i=0;$i<4;$i++): ?>
      <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-100 dark:border-slate-800 animate-pulse flex gap-4">
        <div class="w-20 h-20 rounded-xl bg-slate-200 dark:bg-slate-700 flex-shrink-0"></div>
        <div class="flex-1 space-y-2 py-1">
          <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded w-1/3"></div>
          <div class="h-3 bg-slate-100 dark:bg-slate-800 rounded w-1/2"></div>
          <div class="h-3 bg-slate-100 dark:bg-slate-800 rounded w-1/4"></div>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <div id="report-list" class="space-y-4 hidden"></div>
    <div id="empty-state" class="hidden bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 p-16 text-center">
      <div class="text-5xl mb-3">📭</div>
      <p class="font-semibold text-slate-500">No reports found for this filter.</p>
    </div>
  </div>

  <!-- ── PAGINATION ──────────────────────────────────────── -->
  <div id="pagination" class="flex justify-center gap-2 mt-6 hidden"></div>

</main>

<!-- ══ LIGHTBOX ═══════════════════════════════════════════ -->
<div id="lightbox" class="hidden fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 cursor-zoom-out" onclick="document.getElementById('lightbox').classList.add('hidden')">
  <img id="lb-img" src="" class="max-h-[90vh] max-w-full rounded-2xl object-contain shadow-2xl">
</div>

<!-- ══ REJECT MODAL ═══════════════════════════════════════ -->
<div id="rej-modal" class="hidden fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
  <div class="bg-white dark:bg-slate-900 rounded-2xl border border-red-200 dark:border-red-900 shadow-2xl w-full max-w-md p-6">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-11 h-11 rounded-xl bg-red-100 dark:bg-red-900/40 flex items-center justify-center">
        <span class="material-icons-round text-red-500 text-xl">cancel</span>
      </div>
      <div>
        <p class="font-bold text-base">Reject Report</p>
        <p class="text-xs text-slate-400">Report <span class="font-mono" id="rej-id">—</span></p>
      </div>
      <button onclick="closeRej()" class="ml-auto text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
        <span class="material-icons-round">close</span>
      </button>
    </div>

    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
      Reason <span class="text-red-500">*</span>
    </label>
    <textarea id="rej-reason" rows="4"
      placeholder="e.g. Unclear photo — please retake in better lighting, or: Item not in our accepted categories."
      class="w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-red-400 dark:text-white resize-none mb-3"></textarea>

    <!-- Quick chips -->
    <div class="flex flex-wrap gap-2 mb-5">
      <?php foreach(['Unclear image','Item not accepted','Suspected hazardous','Duplicate report','Photo quality too low'] as $r): ?>
      <button type="button" onclick="document.getElementById('rej-reason').value='<?=$r?>'"
        class="px-2.5 py-1 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-900/30 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 border border-slate-200 dark:border-slate-700 transition-colors font-medium"><?=$r?></button>
      <?php endforeach; ?>
    </div>

    <p class="text-xs text-slate-400 mb-4">This reason is sent to the user as a notification.</p>

    <div class="flex gap-3">
      <button onclick="closeRej()" class="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-sm font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">Cancel</button>
      <button onclick="submitRej()" id="btn-rej"
        class="flex-1 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white text-sm font-bold transition-colors flex items-center justify-center gap-2">
        <span class="material-icons-round text-base">cancel</span> Confirm Reject
      </button>
    </div>
  </div>
</div>

<!-- ══ TOAST ══════════════════════════════════════════════ -->
<div id="toasts" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 max-w-xs pointer-events-none"></div>

<script>
const API = '/greenloop/admin_api.php';
let activeFilter   = 'pending,rejected';
let rejReportId    = null;
let allRows        = [];

// ── Boot ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadReports();
  setInterval(loadStats, 20000);
  document.getElementById('q').addEventListener('input', debounce(() => renderRows(allRows), 280));
});

// ── Stats ─────────────────────────────────────────────────────
async function loadStats() {
  try {
    const d = await apiFetch({ action: 'stats' });
    ['pending','broadcasted','accepted','completed'].forEach(k => {
      const el = document.getElementById(`stat-${k}`);
      if (el) el.textContent = d[k] ?? 0;
    });
  } catch(e){}
}

// ── Reports ───────────────────────────────────────────────────
async function loadReports(page = 1) {
  show('skeleton'); hide('report-list'); hide('empty-state'); hide('pagination');

  try {
    const d = await apiFetch({ action: 'list_pending', page, status_filter: activeFilter });
    hide('skeleton');
    allRows = d.reports || [];
    renderRows(allRows);
    renderPaging(d.total_pages, d.page);
  } catch(e) {
    hide('skeleton');
    toast(e.message || 'Load failed', 'error');
  }
}

function renderRows(rows) {
  const q = document.getElementById('q').value.toLowerCase();
  const filtered = q ? rows.filter(r =>
    [r.client_name, r.scrap_type, r.id+''].some(v => (v||'').toLowerCase().includes(q))
  ) : rows;

  const list = document.getElementById('report-list');
  if (!filtered.length) { hide('report-list'); show('empty-state'); return; }
  hide('empty-state'); show('report-list');
  list.innerHTML = filtered.map((r, i) => rowHtml(r, i)).join('');
}

function rowHtml(r, idx) {
  const STATUS = {
    pending:     { bg:'bg-amber-100 dark:bg-amber-900/30',  txt:'text-amber-700 dark:text-amber-300',  icon:'hourglass_empty', lbl:'Pending'  },
    rejected:    { bg:'bg-red-100 dark:bg-red-900/30',      txt:'text-red-700 dark:text-red-300',      icon:'cancel',          lbl:'Rejected' },
    broadcasted: { bg:'bg-blue-100 dark:bg-blue-900/30',    txt:'text-blue-700 dark:text-blue-300',    icon:'cell_tower',      lbl:'Live'     },
    accepted:    { bg:'bg-purple-100 dark:bg-purple-900/30',txt:'text-purple-700 dark:text-purple-300',icon:'local_shipping',  lbl:'Accepted' },
    completed:   { bg:'bg-emerald-100 dark:bg-emerald-900/30',txt:'text-emerald-700 dark:text-emerald-300',icon:'check_circle',lbl:'Done'     },
  };
  const s  = STATUS[r.status] || STATUS.pending;
  const img = r.image_path
    ? `../uploads/greenloop/${r.image_path}`
    : `https://ui-avatars.com/api/?name=${encodeURIComponent(r.scrap_type||'?')}&background=14532d&color=4ade80&size=80&bold=true`;

  const canAct = (r.status === 'pending' || r.status === 'rejected');

  const aiChip = r.ai_accepted != null
    ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border
        ${r.ai_accepted == 1
          ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800'
          : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800'}">
        <span class="material-icons-round text-xs">smart_toy</span>
        AI: ${r.ai_accepted == 1 ? 'Accepted' : 'Flagged'}
      </span>`
    : '';

  return `
  <div id="row-${r.id}" class="report-row slide-up bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800
       rounded-2xl p-5 hover:border-primary/30 dark:hover:border-primary/30"
       style="animation-delay:${idx*35}ms">
    <div class="flex gap-4 items-start">
      <!-- Thumb -->
      <div class="w-[72px] flex-shrink-0">
        <div class="w-[72px] h-[72px] rounded-xl overflow-hidden bg-slate-100 dark:bg-slate-800
             border border-slate-200 dark:border-slate-700 cursor-zoom-in"
             onclick="zoom('${img}')">
          <img src="${img}" class="img-zoom w-full h-full object-cover" alt="">
        </div>
        <p class="text-center text-[9px] font-mono text-slate-400 mt-1">#${r.id}</p>
      </div>

      <!-- Body -->
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
          <div>
            <p class="font-bold text-[15px] leading-snug">${esc(r.scrap_type || '—')}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
              <span class="material-icons-round text-xs align-middle">person</span>
              ${esc(r.client_name)} · <span class="font-mono">${esc(r.client_email)}</span>
            </p>
          </div>
          <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border ${s.bg} ${s.txt} border-current/20">
            <span class="material-icons-round text-xs">${s.icon}</span>${s.lbl}
          </span>
        </div>

        <!-- Meta -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400 mb-3">
          <span class="flex items-center gap-1">
            <span class="material-icons-round text-sm text-amber-500">toll</span>
            <strong class="text-amber-600 dark:text-amber-400">${r.estimated_green_coins ?? 0}</strong>&nbsp;est. coins
          </span>
          <span class="flex items-center gap-1">
            <span class="material-icons-round text-sm">inventory_2</span>
            ${r.quantity ?? 1}&nbsp;${esc(r.unit || 'pcs')}
          </span>
          <span class="flex items-center gap-1">
            <span class="material-icons-round text-sm">schedule</span>
            ${fmtDate(r.created_at)}
          </span>
          ${aiChip}
        </div>

        ${r.client_notes
          ? `<p class="text-xs text-slate-500 bg-slate-50 dark:bg-slate-800/60 rounded-lg px-3 py-2 mb-3 italic line-clamp-2">"${esc(r.client_notes)}"</p>`
          : ''}
        ${r.rejection_reason
          ? `<p class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2 mb-3">
               <strong>Prev. rejection:</strong> ${esc(r.rejection_reason)}
             </p>`
          : ''}
        ${r.ai_assessment
          ? `<p class="text-xs text-slate-400 bg-slate-50 dark:bg-slate-800/50 rounded-lg px-3 py-2 mb-3 font-mono line-clamp-2">
               🤖 ${esc(r.ai_assessment.substring(0,140))}${r.ai_assessment.length>140?'…':''}
             </p>`
          : ''}

        <!-- Action buttons -->
        ${canAct ? `
        <div class="flex flex-wrap gap-2 pt-1">
          <button onclick="approve(${r.id})" id="appbtn-${r.id}"
            class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500
                   text-white text-xs font-bold transition-all active:scale-95 hover:shadow-lg hover:shadow-emerald-500/20">
            <span class="material-icons-round text-sm">cell_tower</span> Approve &amp; Broadcast
          </button>
          <button onclick="openRej(${r.id})"
            class="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-red-200 dark:border-red-800
                   bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40
                   text-red-600 dark:text-red-400 text-xs font-bold transition-all active:scale-95">
            <span class="material-icons-round text-sm">cancel</span> Reject
          </button>
        </div>` : ''}
      </div>
    </div>
  </div>`;
}

// ── Approve ───────────────────────────────────────────────────
async function approve(id) {
  const btn = document.getElementById(`appbtn-${id}`);
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-icons-round text-sm animate-spin">sync</span> Broadcasting…'; }
  try {
    const d = await apiFetch({ action: 'approve', report_id: id });
    if (d.error) throw new Error(d.error);
    toast('📡 ' + d.message, 'success');
    removeRow(id);
    loadStats();
  } catch(e) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-icons-round text-sm">cell_tower</span> Approve & Broadcast'; }
    toast(e.message, 'error');
  }
}

// ── Reject Modal ──────────────────────────────────────────────
function openRej(id)  { rejReportId = id; document.getElementById('rej-id').textContent = '#'+id; document.getElementById('rej-reason').value=''; show('rej-modal'); setTimeout(()=>document.getElementById('rej-reason').focus(),80); }
function closeRej()   { hide('rej-modal'); rejReportId = null; }
document.getElementById('rej-modal').addEventListener('click', e => { if(e.target===document.getElementById('rej-modal')) closeRej(); });

async function submitRej() {
  const reason = document.getElementById('rej-reason').value.trim();
  if (reason.length < 5) { toast('Enter a reason (min 5 chars)', 'warning'); return; }

  const btn = document.getElementById('btn-rej');
  btn.disabled = true; btn.innerHTML = '<span class="material-icons-round text-sm animate-spin">sync</span> Rejecting…';

  try {
    const d = await apiFetch({ action: 'reject', report_id: rejReportId, rejection_reason: reason });
    if (d.error) throw new Error(d.error);
    toast('Report rejected.', 'info');
    closeRej();
    removeRow(rejReportId);
    loadStats();
  } catch(e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false; btn.innerHTML = '<span class="material-icons-round text-sm">cancel</span> Confirm Reject';
  }
}

// ── Filter tabs ───────────────────────────────────────────────
function setFilter(btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active-filter'));
  btn.classList.add('active-filter');
  activeFilter = btn.dataset.filter;
  loadReports(1);
}

// ── Pagination ────────────────────────────────────────────────
function renderPaging(total, cur) {
  const p = document.getElementById('pagination');
  if (total <= 1) { hide('pagination'); return; }
  show('pagination');
  let h = '';
  for (let i = 1; i <= total; i++) {
    h += i === cur
      ? `<button class="w-9 h-9 rounded-xl bg-primary text-white text-sm font-bold">${i}</button>`
      : `<button onclick="loadReports(${i})" class="w-9 h-9 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">${i}</button>`;
  }
  p.innerHTML = h;
}

// ── Helpers ───────────────────────────────────────────────────
function removeRow(id) {
  const el = document.getElementById(`row-${id}`);
  if (el) { el.classList.add('fade-shrink'); setTimeout(() => el.remove(), 300); }
}
function zoom(src) {
  document.getElementById('lb-img').src = src;
  document.getElementById('lightbox').classList.remove('hidden');
}
function show(id) { document.getElementById(id)?.classList.remove('hidden'); }
function hide(id) { document.getElementById(id)?.classList.add('hidden'); }
function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fmtDate(s) {
  if (!s) return '—';
  return new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
}
function debounce(fn, ms) { let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }

async function apiFetch(body) {
  const r = await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.error) throw new Error(d.error);
  return d;
}

function toast(msg, type = 'success') {
  const MAP = { success:'bg-emerald-600', error:'bg-red-600', warning:'bg-amber-500', info:'bg-blue-600' };
  const el = document.createElement('div');
  el.className = `${MAP[type]||MAP.success} text-white px-4 py-3 rounded-xl text-sm font-semibold shadow-xl pointer-events-auto slide-up`;
  el.textContent = msg;
  document.getElementById('toasts').appendChild(el);
  setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity 0.3s'; setTimeout(()=>el.remove(),300); }, 3500);
}
</script>
</body>
</html>