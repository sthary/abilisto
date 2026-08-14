<?php
// admin/finance_topups.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php");
    exit();
}

$fin_user_id = $_SESSION['user_id'];
$fin_stmt = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$fin_stmt->execute([$fin_user_id]);
$fin_name = $fin_stmt->fetch()['full_name'] ?? 'Finance';

// ── Filters ────────────────────────────────────────────────
$search    = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$status_f  = $_GET['status'] ?? '';
$method_f  = $_GET['method'] ?? '';
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 25;
$offset    = ($page - 1) * $per_page;

$where = "WHERE 1=1";
$params = [];
if ($search)    { $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR t.reference_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($date_from) { $where .= " AND DATE(t.created_at) >= ?"; $params[] = $date_from; }
if ($date_to)   { $where .= " AND DATE(t.created_at) <= ?"; $params[] = $date_to; }
if ($status_f)  { $where .= " AND t.status = ?"; $params[] = $status_f; }
if ($method_f)  { $where .= " AND t.payment_method = ?"; $params[] = $method_f; }

// ── Stats ──────────────────────────────────────────────────
$stats_sql = "SELECT
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN t.status='completed' THEN t.amount ELSE 0 END),0) as completed_vol,
    COALESCE(SUM(CASE WHEN t.status='pending'   THEN t.amount ELSE 0 END),0) as pending_vol,
    COALESCE(SUM(CASE WHEN t.status='failed'    THEN t.amount ELSE 0 END),0) as failed_vol,
    COUNT(CASE WHEN t.status='completed' THEN 1 END) as completed_cnt,
    COUNT(CASE WHEN t.status='pending'   THEN 1 END) as pending_cnt
FROM top_ups t LEFT JOIN users u ON u.id = t.worker_id $where";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

$total_rows_stmt = $conn->prepare("SELECT COUNT(*) as c FROM top_ups t LEFT JOIN users u ON u.id=t.worker_id $where");
$total_rows_stmt->execute($params);
$total_rows  = (int)$total_rows_stmt->fetch()['c'];
$total_pages = ceil($total_rows / $per_page);

$data_sql = "SELECT t.*, u.full_name, u.email, u.role
             FROM top_ups t
             LEFT JOIN users u ON u.id = t.worker_id
             $where
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?";
$data_stmt = $conn->prepare($data_sql);
$data_stmt->execute(array_merge($params, [$per_page, $offset]));
$rows = $data_stmt->fetchAll();

$current_date = date('M d, Y');
$notif_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
$notif_stmt->execute([$fin_user_id]);
$notif_count  = (int)($notif_stmt->fetch()['c'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Abilisto — Top-up Records</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config = {
        darkMode:"class",
        theme:{ extend:{
            colors:{ fin:"#0EA5E9", equity:"#10B981", danger:"#F43F5E", expense:"#F59E0B" },
            fontFamily:{ display:["Plus Jakarta Sans","sans-serif"], body:["Plus Jakarta Sans","sans-serif"] }
        }}
    };
    if(localStorage.getItem('darkMode')==='true') document.documentElement.classList.add('dark');
    function toggleDarkMode(){ document.documentElement.classList.toggle('dark'); localStorage.setItem('darkMode', document.documentElement.classList.contains('dark')); }
    function toggleMobileMenu(){ document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full'); document.getElementById('menu-overlay').classList.toggle('hidden'); }
</script>
<style>
    body{ font-family:'Plus Jakarta Sans',sans-serif; background:#F0F4FF; }
    .dark body{ background:#0B0F1A; }
    h1,h2,h3,.font-display{ font-family:'Plus Jakarta Sans',sans-serif; }
    .glass-card{ background:rgba(255,255,255,0.72); backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,0.6); box-shadow:0 4px 24px rgba(0,0,0,0.06); }
    .dark .glass-card{ background:rgba(17,24,39,0.72); border:1px solid rgba(255,255,255,0.07); }
    .fin-input{ background:rgba(255,255,255,0.7); border:1.5px solid rgba(0,0,0,0.1); border-radius:10px; padding:8px 12px; font-size:13px; width:100%; color:#1e293b; transition:border-color .2s; }
    .dark .fin-input{ background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.1); color:#e2e8f0; }
    .fin-input:focus{ outline:none; border-color:#0EA5E9; box-shadow:0 0 0 3px rgba(14,165,233,0.15); }
    .fin-table thead th{ font-family:'Plus Jakarta Sans',sans-serif; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,0.06); white-space:nowrap; }
    .dark .fin-table thead th{ border-color:rgba(255,255,255,0.06); }
    .fin-table tbody td{ padding:11px 14px; font-size:13px; border-bottom:1px solid rgba(0,0,0,0.04); }
    .dark .fin-table tbody td{ border-color:rgba(255,255,255,0.04); }
    .fin-table tbody tr:hover{ background:rgba(14,165,233,0.04); }
    .badge{ display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:99px; font-size:10px; font-weight:700; }
    .badge-completed{ background:rgba(16,185,129,0.12); color:#10B981; border:1px solid rgba(16,185,129,0.2); }
    .badge-pending{ background:rgba(245,158,11,0.12); color:#F59E0B; border:1px solid rgba(245,158,11,0.2); }
    .badge-failed{ background:rgba(244,63,94,0.12); color:#F43F5E; border:1px solid rgba(244,63,94,0.2); }
    .badge-gcash{ background:rgba(14,165,233,0.1); color:#0EA5E9; border:1px solid rgba(14,165,233,0.2); }
    .badge-bank{ background:rgba(99,102,241,0.1); color:#6366F1; border:1px solid rgba(99,102,241,0.2); }
    .slim-scroll::-webkit-scrollbar{ width:4px; } .slim-scroll::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:99px; }
    .dark .slim-scroll::-webkit-scrollbar-thumb{ background:#334155; }
    @keyframes fadeUp{ from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    .anim-in{ animation:fadeUp .4s ease both; }
    .kpi-bar{ height:3px; border-radius:99px; margin-top:10px; }
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'finance_navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Top-up Records</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Worker Wallet Funding History</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <div class="hidden sm:block text-right ml-2">
                <p class="text-[10px] font-bold"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-400 uppercase tracking-tighter">Top-ups</p>
            </div>
        </div>
    </header>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="glass-card rounded-2xl p-4 anim-in">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Total Records</p>
            <p class="font-display text-2xl font-bold"><?php echo number_format($stats['total']); ?></p>
            <div class="kpi-bar bg-fin" style="width:100%"></div>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.07s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Completed Volume</p>
            <p class="font-display text-2xl font-bold text-equity">₱<?php echo number_format($stats['completed_vol'],2); ?></p>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo $stats['completed_cnt']; ?> transactions</p>
            <div class="kpi-bar bg-equity" style="width:<?php echo $stats['total']>0?round($stats['completed_cnt']/$stats['total']*100):0; ?>%"></div>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.14s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Pending Volume</p>
            <p class="font-display text-2xl font-bold text-expense">₱<?php echo number_format($stats['pending_vol'],2); ?></p>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo $stats['pending_cnt']; ?> waiting</p>
            <div class="kpi-bar bg-expense" style="width:<?php echo $stats['total']>0?round($stats['pending_cnt']/$stats['total']*100):0; ?>%"></div>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.21s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Failed Volume</p>
            <p class="font-display text-2xl font-bold text-danger">₱<?php echo number_format($stats['failed_vol'],2); ?></p>
            <div class="kpi-bar bg-danger" style="width:100%"></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Search</label>
                <input type="text" name="search" class="fin-input" placeholder="Name, email, reference…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">From</label>
                <input type="date" name="date_from" class="fin-input" value="<?php echo $date_from; ?>">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">To</label>
                <input type="date" name="date_to" class="fin-input" value="<?php echo $date_to; ?>">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Status</label>
                <select name="status" class="fin-input">
                    <option value="">All Statuses</option>
                    <?php foreach(['pending','completed','failed'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_f===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Method</label>
                <select name="method" class="fin-input">
                    <option value="">All Methods</option>
                    <option value="gcash" <?php echo $method_f==='gcash'?'selected':''; ?>>GCash</option>
                    <option value="bank" <?php echo $method_f==='bank'?'selected':''; ?>>Bank</option>
                </select>
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                <button type="submit" class="px-5 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center gap-2">
                    <span class="material-icons-round text-sm">filter_list</span> Apply
                </button>
                <a href="finance_topups.php" class="px-5 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80 flex items-center gap-2">
                    <span class="material-icons-round text-sm">clear</span> Reset
                </a>
                <span class="ml-auto text-[10px] text-slate-400 self-center"><?php echo number_format($total_rows); ?> result<?php echo $total_rows!==1?'s':''; ?></span>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden mb-6">
        <div class="overflow-x-auto slim-scroll">
            <table class="fin-table w-full">
                <thead>
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Worker</th>
                        <th class="text-left">Amount</th>
                        <th class="text-left">Method</th>
                        <th class="text-left">Reference No.</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Requested</th>
                        <th class="text-left">Completed</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(count($rows) > 0):
                    foreach($rows as $r): ?>
                <tr>
                    <td class="text-slate-400 text-[11px] font-mono">#<?php echo $r['id']; ?></td>
                    <td>
                        <p class="font-medium text-[12px]"><?php echo htmlspecialchars($r['full_name'] ?? '—'); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></p>
                    </td>
                    <td class="font-bold font-mono text-equity">₱<?php echo number_format($r['amount'],2); ?></td>
                    <td>
                        <span class="badge badge-<?php echo $r['payment_method']; ?>">
                            <span class="material-icons-round" style="font-size:10px"><?php echo $r['payment_method']==='gcash'?'phone_iphone':'account_balance'; ?></span>
                            <?php echo strtoupper($r['payment_method']); ?>
                        </span>
                    </td>
                    <td class="font-mono text-[11px] text-slate-500">
                        <?php echo $r['reference_number'] ? htmlspecialchars(substr($r['reference_number'],0,20)).'…' : '—'; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $r['status']; ?>">
                            <?php echo ucfirst($r['status']); ?>
                        </span>
                    </td>
                    <td class="text-[11px] text-slate-500 whitespace-nowrap">
                        <?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?>
                    </td>
                    <td class="text-[11px] text-slate-500 whitespace-nowrap">
                        <?php echo $r['completed_at'] ? date('M d, Y h:i A', strtotime($r['completed_at'])) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center py-16 text-slate-400">
                    <span class="material-icons-round text-3xl block mb-2 opacity-30">add_card</span>
                    No top-up records found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($total_pages > 1): ?>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
            <div class="flex gap-2">
                <?php $base = "finance_topups.php?search=".urlencode($search)."&date_from=$date_from&date_to=$date_to&status=$status_f&method=$method_f";
                for($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++): ?>
                <a href="<?php echo $base; ?>&page=<?php echo $p; ?>"
                   class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-all
                          <?php echo $p===$page?'bg-fin text-white':'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-slate-200'; ?>">
                    <?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</main>
</body>
</html>