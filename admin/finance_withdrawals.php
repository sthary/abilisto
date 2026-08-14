<?php
// admin/finance_withdrawals.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php");
    exit();
}

$fin_user_id = $_SESSION['user_id'];
$fin_name = $conn->query("SELECT full_name FROM users WHERE id=$fin_user_id")->fetch_assoc()['full_name'] ?? 'Finance';

// ── Filters ────────────────────────────────────────────────
$search    = $conn->real_escape_string($_GET['search'] ?? '');
$date_from = $conn->real_escape_string($_GET['date_from'] ?? '');
$date_to   = $conn->real_escape_string($_GET['date_to'] ?? '');
$status_f  = $conn->real_escape_string($_GET['status'] ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 25;
$offset    = ($page - 1) * $per_page;

$where = "WHERE 1=1";
if ($search)    $where .= " AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR w.gcash_number LIKE '%$search%')";
if ($date_from) $where .= " AND DATE(w.request_date) >= '$date_from'";
if ($date_to)   $where .= " AND DATE(w.request_date) <= '$date_to'";
if ($status_f)  $where .= " AND w.status = '$status_f'";

// ── Stats ──────────────────────────────────────────────────
$stats_sql = "SELECT
    COUNT(*) as total,
    COALESCE(SUM(w.amount),0) as total_requested,
    COALESCE(SUM(CASE WHEN w.status IN ('Completed','completed','approved','Approved') THEN w.amount ELSE 0 END),0) as total_paid,
    COALESCE(SUM(CASE WHEN w.status IN ('Pending','pending') THEN w.amount ELSE 0 END),0) as total_pending,
    COUNT(CASE WHEN w.status IN ('Pending','pending') THEN 1 END) as pending_cnt
FROM withdrawals w LEFT JOIN users u ON u.id = w.worker_id $where";
$stats = $conn->query($stats_sql)->fetch_assoc();

$total_rows  = (int)$conn->query("SELECT COUNT(*) as c FROM withdrawals w LEFT JOIN users u ON u.id=w.worker_id $where")->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);

$data_sql = "SELECT w.*, u.full_name, u.email, wp.wallet_balance
             FROM withdrawals w
             LEFT JOIN users u ON u.id = w.worker_id
             LEFT JOIN worker_profiles wp ON wp.user_id = w.worker_id
             $where
             ORDER BY w.request_date DESC
             LIMIT $per_page OFFSET $offset";
$rows = $conn->query($data_sql);

$current_date = date('M d, Y');
$notif_count  = (int)($conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id=$fin_user_id AND is_read=0")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Abilisto — Withdrawals</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config = {
        darkMode:"class",
        theme:{ extend:{
            colors:{ fin:"#0EA5E9", equity:"#10B981", danger:"#F43F5E", expense:"#F59E0B" },
            fontFamily:{ display:["Syne","sans-serif"], body:["DM Sans","sans-serif"] }
        }}
    };
    if(localStorage.getItem('darkMode')==='true') document.documentElement.classList.add('dark');
    function toggleDarkMode(){ document.documentElement.classList.toggle('dark'); localStorage.setItem('darkMode', document.documentElement.classList.contains('dark')); }
    function toggleMobileMenu(){ document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full'); document.getElementById('menu-overlay').classList.toggle('hidden'); }
</script>
<style>
    body{ font-family:'DM Sans',sans-serif; background:#F0F4FF; }
    .dark body{ background:#0B0F1A; }
    h1,h2,h3,.font-display{ font-family:'Syne',sans-serif; }
    .glass-card{ background:rgba(255,255,255,0.72); backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,0.6); box-shadow:0 4px 24px rgba(0,0,0,0.06); }
    .dark .glass-card{ background:rgba(17,24,39,0.72); border:1px solid rgba(255,255,255,0.07); }
    .fin-input{ background:rgba(255,255,255,0.7); border:1.5px solid rgba(0,0,0,0.1); border-radius:10px; padding:8px 12px; font-size:13px; width:100%; color:#1e293b; transition:border-color .2s; }
    .dark .fin-input{ background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.1); color:#e2e8f0; }
    .fin-input:focus{ outline:none; border-color:#0EA5E9; box-shadow:0 0 0 3px rgba(14,165,233,0.15); }
    .fin-table thead th{ font-family:'Syne',sans-serif; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,0.06); white-space:nowrap; }
    .dark .fin-table thead th{ border-color:rgba(255,255,255,0.06); }
    .fin-table tbody td{ padding:12px 14px; font-size:13px; border-bottom:1px solid rgba(0,0,0,0.04); }
    .dark .fin-table tbody td{ border-color:rgba(255,255,255,0.04); }
    .fin-table tbody tr:hover{ background:rgba(14,165,233,0.04); }
    .badge{ display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:99px; font-size:10px; font-weight:700; }
    .badge-completed,.badge-approved{ background:rgba(16,185,129,0.12); color:#10B981; border:1px solid rgba(16,185,129,0.2); }
    .badge-pending{ background:rgba(245,158,11,0.12); color:#F59E0B; border:1px solid rgba(245,158,11,0.2); }
    .badge-rejected,.badge-failed{ background:rgba(244,63,94,0.12); color:#F43F5E; border:1px solid rgba(244,63,94,0.2); }
    .slim-scroll::-webkit-scrollbar{ width:4px; } .slim-scroll::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:99px; }
    .dark .slim-scroll::-webkit-scrollbar-thumb{ background:#334155; }
    @keyframes fadeUp{ from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    .anim-in{ animation:fadeUp .4s ease both; }
    .readonly-banner{ background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); border-radius:14px; padding:12px 18px; display:flex; align-items:center; gap:10px; margin-bottom:24px; }
    .dark .readonly-banner{ background:rgba(245,158,11,0.05); }
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
                <h1 class="font-display text-lg font-bold leading-tight">Withdrawals</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Worker Payout Requests — View Only</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <div class="hidden sm:block text-right ml-2">
                <p class="text-[10px] font-bold"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-400 uppercase tracking-tighter">Withdrawals</p>
            </div>
        </div>
    </header>

    <!-- Read-only notice -->
    <div class="readonly-banner">
        <span class="material-icons-round text-expense text-xl">info</span>
        <div>
            <p class="text-xs font-bold text-expense">View Only — Approval handled by HR Department</p>
            <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">You can monitor withdrawal requests and amounts here, but only HR can approve or reject them.</p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="glass-card rounded-2xl p-4 anim-in">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Total Requests</p>
            <p class="font-display text-2xl font-bold"><?php echo number_format($stats['total']); ?></p>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.07s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Total Requested</p>
            <p class="font-display text-2xl font-bold text-fin">₱<?php echo number_format($stats['total_requested'],2); ?></p>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.14s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Total Paid Out</p>
            <p class="font-display text-2xl font-bold text-equity">₱<?php echo number_format($stats['total_paid'],2); ?></p>
        </div>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:.21s">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Pending Payout</p>
            <p class="font-display text-2xl font-bold text-expense">₱<?php echo number_format($stats['total_pending'],2); ?></p>
            <?php if($stats['pending_cnt'] > 0): ?>
            <p class="text-[10px] text-expense font-semibold mt-1"><?php echo $stats['pending_cnt']; ?> awaiting HR approval</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Search</label>
                <input type="text" name="search" class="fin-input" placeholder="Name, email, GCash no…" value="<?php echo htmlspecialchars($search); ?>">
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
                    <?php foreach(['Pending','Completed','Rejected'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_f===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center justify-center gap-1">
                    <span class="material-icons-round text-sm">filter_list</span> Apply
                </button>
                <a href="finance_withdrawals.php" class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80 flex items-center justify-center gap-1">
                    <span class="material-icons-round text-sm">clear</span> Reset
                </a>
            </div>
            <div class="sm:col-span-2 lg:col-span-5">
                <span class="text-[10px] text-slate-400"><?php echo number_format($total_rows); ?> result<?php echo $total_rows!==1?'s':''; ?></span>
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
                        <th class="text-left">GCash No.</th>
                        <th class="text-left">Current Wallet</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Requested</th>
                        <th class="text-left">Processed</th>
                        <th class="text-left">Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($rows && $rows->num_rows > 0):
                    while($r = $rows->fetch_assoc()):
                        $status_key = strtolower($r['status']);
                ?>
                <tr>
                    <td class="text-slate-400 text-[11px] font-mono">#<?php echo $r['id']; ?></td>
                    <td>
                        <p class="font-medium text-[12px]"><?php echo htmlspecialchars($r['full_name'] ?? '—'); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></p>
                    </td>
                    <td class="font-bold font-mono text-danger">₱<?php echo number_format($r['amount'],2); ?></td>
                    <td class="font-mono text-[12px] text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($r['gcash_number']); ?></td>
                    <td class="font-mono text-[12px] <?php echo $r['wallet_balance']>=0?'text-equity':'text-danger'; ?>">
                        ₱<?php echo number_format($r['wallet_balance'] ?? 0, 2); ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $status_key; ?>">
                            <?php echo htmlspecialchars($r['status']); ?>
                        </span>
                    </td>
                    <td class="text-[11px] text-slate-500 whitespace-nowrap">
                        <?php echo date('M d, Y', strtotime($r['request_date'])); ?><br>
                        <span class="text-[10px] text-slate-400"><?php echo date('h:i A', strtotime($r['request_date'])); ?></span>
                    </td>
                    <td class="text-[11px] text-slate-500 whitespace-nowrap">
                        <?php echo $r['processed_at'] ? date('M d, Y', strtotime($r['processed_at'])) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?>
                    </td>
                    <td class="max-w-[140px]">
                        <?php if($r['notes']): ?>
                        <p class="text-[10px] text-slate-400 truncate" title="<?php echo htmlspecialchars($r['notes']); ?>"><?php echo htmlspecialchars($r['notes']); ?></p>
                        <?php else: ?>
                        <span class="text-slate-300 dark:text-slate-600 text-[11px]">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="text-center py-16 text-slate-400">
                    <span class="material-icons-round text-3xl block mb-2 opacity-30">payments</span>
                    No withdrawal requests found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($total_pages > 1): ?>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
            <div class="flex gap-2">
                <?php $base = "finance_withdrawals.php?search=".urlencode($search)."&date_from=$date_from&date_to=$date_to&status=$status_f";
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