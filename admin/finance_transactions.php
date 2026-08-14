<?php
// admin/finance_transactions.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php");
    exit();
}

$fin_user_id = $_SESSION['user_id'];
$fin_user_sql = "SELECT full_name FROM users WHERE id = ?";
$fin_stmt = $conn->prepare($fin_user_sql);
$fin_stmt->execute([$fin_user_id]);
$fin_name = $fin_stmt->fetch()['full_name'] ?? 'Finance';

// ── Filters ────────────────────────────────────────────────
$search     = $_GET['search'] ?? '';
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to'] ?? '';
$type_f     = $_GET['type'] ?? '';
$user_type_f= $_GET['user_type'] ?? '';
$page       = max(1, intval($_GET['page'] ?? 1));
$per_page   = 25;
$offset     = ($page - 1) * $per_page;

// ── WHERE builder ──────────────────────────────────────────
$where = "WHERE 1=1";
$params = [];
if ($search)     { $where .= " AND (wt.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($date_from)  { $where .= " AND DATE(wt.created_at) >= ?"; $params[] = $date_from; }
if ($date_to)    { $where .= " AND DATE(wt.created_at) <= ?"; $params[] = $date_to; }
if ($type_f)     { $where .= " AND wt.transaction_type = ?"; $params[] = $type_f; }
if ($user_type_f){ $where .= " AND wt.user_type = ?"; $params[] = $user_type_f; }

// ── Summary stats ──────────────────────────────────────────
$stats_sql = "SELECT
    COUNT(*) as total_txns,
    COALESCE(SUM(CASE WHEN transaction_type IN ('credit','fee') AND user_type='admin' THEN amount ELSE 0 END),0) as total_in,
    COALESCE(SUM(CASE WHEN transaction_type IN ('debit','withdrawal') AND user_type='admin' THEN amount ELSE 0 END),0) as total_out,
    COALESCE(SUM(CASE WHEN transaction_type='fee' THEN amount ELSE 0 END),0) as total_fees
FROM wallet_transactions wt
LEFT JOIN users u ON u.id = wt.user_id
$where";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

// ── Paginated data ─────────────────────────────────────────
$total_rows_sql = "SELECT COUNT(*) as cnt FROM wallet_transactions wt LEFT JOIN users u ON u.id = wt.user_id $where";
$total_rows_stmt = $conn->prepare($total_rows_sql);
$total_rows_stmt->execute($params);
$total_rows = (int)$total_rows_stmt->fetch()['cnt'];
$total_pages = ceil($total_rows / $per_page);

$data_sql = "SELECT wt.*, u.full_name, u.email, u.role
             FROM wallet_transactions wt
             LEFT JOIN users u ON u.id = wt.user_id
             $where
             ORDER BY wt.created_at DESC
             LIMIT ? OFFSET ?";
$data_stmt = $conn->prepare($data_sql);
$data_stmt->execute(array_merge($params, [$per_page, $offset]));
$rows = $data_stmt->fetchAll();

$current_date = date('M d, Y');
$notif_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
$notif_stmt->execute([$fin_user_id]);
$notif_count = (int)($notif_stmt->fetch()['c'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Abilisto — Wallet Transactions</title>
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
    .badge-credit{ background:rgba(16,185,129,0.12); color:#10B981; border:1px solid rgba(16,185,129,0.2); }
    .badge-debit{ background:rgba(244,63,94,0.12); color:#F43F5E; border:1px solid rgba(244,63,94,0.2); }
    .badge-fee{ background:rgba(14,165,233,0.12); color:#0EA5E9; border:1px solid rgba(14,165,233,0.2); }
    .badge-refund{ background:rgba(245,158,11,0.12); color:#F59E0B; border:1px solid rgba(245,158,11,0.2); }
    .badge-withdrawal{ background:rgba(139,92,246,0.12); color:#8B5CF6; border:1px solid rgba(139,92,246,0.2); }
    .badge-admin{ background:rgba(99,102,241,0.1); color:#6366F1; }
    .badge-worker{ background:rgba(14,165,233,0.1); color:#0EA5E9; }
    .slim-scroll::-webkit-scrollbar{ width:4px; } .slim-scroll::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:99px; }
    .dark .slim-scroll::-webkit-scrollbar-thumb{ background:#334155; }
    @keyframes fadeUp{ from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    .anim-in{ animation:fadeUp .4s ease both; }
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
                <h1 class="font-display text-lg font-bold leading-tight">Wallet Transactions</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Full Ledger — All Entries</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <div class="hidden sm:block text-right ml-2">
                <p class="text-[10px] font-bold"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-400 uppercase tracking-tighter">Transactions</p>
            </div>
        </div>
    </header>

    <!-- Summary KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php
        $kpis = [
            ['label'=>'Total Entries','value'=>number_format($stats['total_txns']),'icon'=>'receipt','color'=>'fin','suffix'=>''],
            ['label'=>'Admin Earnings','value'=>number_format($stats['total_in'],2),'icon'=>'trending_up','color'=>'equity','suffix'=>'₱'],
            ['label'=>'Admin Outflows','value'=>number_format($stats['total_out'],2),'icon'=>'trending_down','color'=>'danger','suffix'=>'₱'],
            ['label'=>'Total Fees Collected','value'=>number_format($stats['total_fees'],2),'icon'=>'toll','color'=>'expense','suffix'=>'₱'],
        ];
        foreach($kpis as $i=>$k): ?>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:<?php echo $i*.07; ?>s">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-icons-round text-<?php echo $k['color']; ?> text-lg"><?php echo $k['icon']; ?></span>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo $k['label']; ?></p>
            </div>
            <p class="font-display text-xl font-bold"><?php echo $k['suffix'].$k['value']; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Search</label>
                <input type="text" name="search" class="fin-input" placeholder="Name, email, description…" value="<?php echo htmlspecialchars($search); ?>">
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
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Type</label>
                <select name="type" class="fin-input">
                    <option value="">All Types</option>
                    <?php foreach(['credit','debit','fee','refund','withdrawal'] as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $type_f===$t?'selected':''; ?>><?php echo ucfirst($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">User Type</label>
                <select name="user_type" class="fin-input">
                    <option value="">All</option>
                    <option value="admin" <?php echo $user_type_f==='admin'?'selected':''; ?>>Admin</option>
                    <option value="worker" <?php echo $user_type_f==='worker'?'selected':''; ?>>Worker</option>
                </select>
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
                <button type="submit" class="px-5 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center gap-2">
                    <span class="material-icons-round text-sm">filter_list</span> Apply Filter
                </button>
                <a href="finance_transactions.php" class="px-5 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80 transition-all flex items-center gap-2">
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
                        <th class="text-left">Date & Time</th>
                        <th class="text-left">User</th>
                        <th class="text-left">User Type</th>
                        <th class="text-left">Type</th>
                        <th class="text-left">Description</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Balance After</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(count($rows) > 0):
                    foreach($rows as $r):
                        $sign = in_array($r['transaction_type'],['credit','fee']) ? '+' : '−';
                        $amt_color = in_array($r['transaction_type'],['credit','fee']) ? 'text-equity' : 'text-danger';
                        if($r['transaction_type']==='fee' && $r['user_type']==='worker') { $sign='−'; $amt_color='text-danger'; }
                        if($r['transaction_type']==='fee' && $r['user_type']==='admin')  { $sign='+'; $amt_color='text-equity'; }
                ?>
                <tr>
                    <td class="text-slate-400 text-[11px] font-mono">#<?php echo $r['id']; ?></td>
                    <td class="whitespace-nowrap">
                        <p class="text-[12px] font-medium"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo date('h:i A', strtotime($r['created_at'])); ?></p>
                    </td>
                    <td>
                        <p class="font-medium text-[12px]"><?php echo htmlspecialchars($r['full_name'] ?? 'System'); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></p>
                    </td>
                    <td>
                        <span class="badge <?php echo $r['user_type']==='admin'?'badge-admin':'badge-worker'; ?>">
                            <?php echo ucfirst($r['user_type']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $r['transaction_type']; ?>">
                            <?php echo ucfirst($r['transaction_type']); ?>
                        </span>
                    </td>
                    <td class="max-w-[220px]">
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate" title="<?php echo htmlspecialchars($r['description']); ?>">
                            <?php echo htmlspecialchars($r['description'] ?? '—'); ?>
                        </p>
                    </td>
                    <td class="text-right font-bold font-mono <?php echo $amt_color; ?> whitespace-nowrap">
                        <?php echo $sign; ?>₱<?php echo number_format($r['amount'],2); ?>
                    </td>
                    <td class="text-right font-mono text-[12px] text-slate-500 whitespace-nowrap">
                        ₱<?php echo number_format($r['balance_after'],2); ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center py-16 text-slate-400">
                    <span class="material-icons-round text-3xl block mb-2 opacity-30">swap_horiz</span>
                    No transactions found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
            <div class="flex gap-2">
                <?php
                $base = "finance_transactions.php?search=".urlencode($search)."&date_from=$date_from&date_to=$date_to&type=$type_f&user_type=$user_type_f";
                for($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++):
                ?>
                <a href="<?php echo $base; ?>&page=<?php echo $p; ?>"
                   class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-all
                          <?php echo $p===$page ? 'bg-fin text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-slate-200'; ?>">
                    <?php echo $p; ?>
                </a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</main>
</body>
</html>