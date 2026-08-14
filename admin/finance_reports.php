<?php
// admin/finance_reports.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php");
    exit();
}

$fin_user_id = $_SESSION['user_id'];
$fin_name = $conn->query("SELECT full_name FROM users WHERE id=$fin_user_id")->fetch_assoc()['full_name'] ?? 'Finance';

$selected_year  = intval($_GET['year']  ?? date('Y'));
$selected_month = intval($_GET['month'] ?? 0); // 0 = full year
$current_date   = date('M d, Y');

// ── Helper: month labels ────────────────────────────────────
function monthLabels($year, $conn) {
    $labels = [];
    for($m=1; $m<=12; $m++) $labels[] = date('M', mktime(0,0,0,$m,1,$year));
    return $labels;
}

$month_clause = $selected_month > 0
    ? "AND MONTH(created_at) = $selected_month AND YEAR(created_at) = $selected_year"
    : "AND YEAR(created_at) = $selected_year";

$month_clause_date = $selected_month > 0
    ? "AND MONTH(expense_date) = $selected_month AND YEAR(expense_date) = $selected_year"
    : "AND YEAR(expense_date) = $selected_year";

// ── 1. MONTHLY INCOME SUMMARY ──────────────────────────────
$income_sql = "SELECT
    MONTH(created_at) as mon,
    COALESCE(SUM(CASE WHEN description LIKE '%Admin fee from booking%' THEN amount ELSE 0 END),0) as booking_fees,
    COALESCE(SUM(CASE WHEN description LIKE '%commission%' THEN amount ELSE 0 END),0) as commissions,
    COALESCE(SUM(amount),0) as total
FROM wallet_transactions
WHERE transaction_type='fee' AND user_id=1 AND user_type='admin'
AND YEAR(created_at) = $selected_year
GROUP BY MONTH(created_at)
ORDER BY mon ASC";
$income_res = $conn->query($income_sql);
$income_by_month = array_fill(1, 12, ['booking_fees'=>0,'commissions'=>0,'total'=>0]);
while($r = $income_res->fetch_assoc()) $income_by_month[$r['mon']] = $r;

// ── 2. EXPENSE BREAKDOWN ───────────────────────────────────
$exp_sql = "SELECT
    MONTH(expense_date) as mon,
    expense_type,
    COALESCE(SUM(amount),0) as total
FROM admin_expenses
WHERE YEAR(expense_date) = $selected_year
$month_clause_date
GROUP BY MONTH(expense_date), expense_type
ORDER BY mon ASC";
$exp_res = $conn->query($exp_sql);
$exp_by_month = array_fill(1, 12, ['MOOE'=>0,'PS'=>0]);
while($r = $exp_res->fetch_assoc()) $exp_by_month[$r['mon']][$r['expense_type']] = (float)$r['total'];

// ── Category breakdown for the period ─────────────────────
$cat_sql = "SELECT expense_type, category, SUM(amount) as total
            FROM admin_expenses
            WHERE YEAR(expense_date) = $selected_year $month_clause_date
            GROUP BY expense_type, category ORDER BY total DESC LIMIT 10";
$cat_res = $conn->query($cat_sql);
$categories = [];
while($r = $cat_res->fetch_assoc()) $categories[] = $r;

// ── 3. WORKER PAYOUT REPORT ───────────────────────────────
$payout_sql = "SELECT
    u.full_name, u.email,
    COUNT(w.id) as total_requests,
    COALESCE(SUM(w.amount),0) as total_requested,
    COALESCE(SUM(CASE WHEN w.status IN ('Completed','completed','Approved','approved') THEN w.amount ELSE 0 END),0) as total_paid,
    COALESCE(SUM(CASE WHEN w.status IN ('Pending','pending') THEN w.amount ELSE 0 END),0) as total_pending,
    wp.wallet_balance as current_balance,
    MAX(w.request_date) as last_request
FROM withdrawals w
LEFT JOIN users u ON u.id = w.worker_id
LEFT JOIN worker_profiles wp ON wp.user_id = w.worker_id
WHERE YEAR(w.request_date) = $selected_year
GROUP BY w.worker_id
ORDER BY total_requested DESC";
$payout_res = $conn->query($payout_sql);
$payouts = [];
$total_paid_out = 0;
while($r = $payout_res->fetch_assoc()) { $payouts[] = $r; $total_paid_out += $r['total_paid']; }

// ── 4. EQUITY OVER TIME ───────────────────────────────────
// Monthly: income − expenses per month
$equity_chart = [];
for($m=1; $m<=12; $m++) {
    $inc = (float)($income_by_month[$m]['total'] ?? 0);
    $exp = (float)(($exp_by_month[$m]['MOOE']??0) + ($exp_by_month[$m]['PS']??0));
    $equity_chart[] = round($inc - $exp, 2);
}

// ── Totals for the period ──────────────────────────────────
$period_income   = array_sum(array_column($income_by_month, 'total'));
$period_mooe     = array_sum(array_column($exp_by_month, 'MOOE'));
$period_ps       = array_sum(array_column($exp_by_month, 'PS'));
$period_expenses = $period_mooe + $period_ps;

// Worker debt (always live)
$liabilities = (float)$conn->query("SELECT COALESCE(SUM(wallet_balance),0) as t FROM worker_profiles WHERE wallet_balance>0")->fetch_assoc()['t'];
$period_equity = $period_income - $liabilities - $period_expenses;

// Available years for filter
$years_res = $conn->query("SELECT DISTINCT YEAR(created_at) as yr FROM wallet_transactions ORDER BY yr DESC");
$years = [];
while($y = $years_res->fetch_assoc()) $years[] = $y['yr'];
if(!in_array(date('Y'), $years)) array_unshift($years, date('Y'));
$notif_count = (int)($conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id=$fin_user_id AND is_read=0")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Abilisto — Financial Reports</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    tailwind.config = {
        darkMode:"class",
        theme:{ extend:{
            colors:{ fin:"#0EA5E9", equity:"#10B981", danger:"#F43F5E", expense:"#F59E0B" },
            fontFamily:{ display:["Plus Jakarta Sans","sans-serif"], body:["Plus Jakarta Sans","sans-serif"] }
        }}
    };
    if(localStorage.getItem('darkMode')==='true') document.documentElement.classList.add('dark');
    function toggleDarkMode(){ document.documentElement.classList.toggle('dark'); localStorage.setItem('darkMode', document.documentElement.classList.contains('dark')); rebuildCharts(); }
    function toggleMobileMenu(){ document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full'); document.getElementById('menu-overlay').classList.toggle('hidden'); }
</script>
<style>
    body{ font-family:'Plus Jakarta Sans',sans-serif; background:#F0F4FF; }
    .dark body{ background:#0B0F1A; }
    h1,h2,h3,.font-display{ font-family:'Plus Jakarta Sans',sans-serif; }
    .glass-card{ background:rgba(255,255,255,0.72); backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,0.6); box-shadow:0 4px 24px rgba(0,0,0,0.06); }
    .dark .glass-card{ background:rgba(17,24,39,0.72); border:1px solid rgba(255,255,255,0.07); }
    .fin-input{ background:rgba(255,255,255,0.7); border:1.5px solid rgba(0,0,0,0.1); border-radius:10px; padding:8px 12px; font-size:13px; width:100%; color:#1e293b; }
    .dark .fin-input{ background:rgba(255,255,255,0.05); border-color:rgba(255,255,255,0.1); color:#e2e8f0; }
    .fin-input:focus{ outline:none; border-color:#0EA5E9; box-shadow:0 0 0 3px rgba(14,165,233,0.15); }
    .section-title{ font-family:'Plus Jakarta Sans',sans-serif; font-size:14px; font-weight:700; margin-bottom:4px; }
    .section-sub{ font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:20px; }
    .fin-table thead th{ font-family:'Plus Jakarta Sans',sans-serif; font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,0.06); white-space:nowrap; }
    .dark .fin-table thead th{ border-color:rgba(255,255,255,0.06); }
    .fin-table tbody td{ padding:10px 14px; font-size:12px; border-bottom:1px solid rgba(0,0,0,0.04); }
    .dark .fin-table tbody td{ border-color:rgba(255,255,255,0.04); }
    .fin-table tbody tr:hover{ background:rgba(14,165,233,0.04); }
    .fin-table tfoot td{ padding:10px 14px; font-size:12px; font-weight:700; border-top:2px solid rgba(0,0,0,0.08); }
    .dark .fin-table tfoot td{ border-color:rgba(255,255,255,0.08); }
    .badge{ display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:99px; font-size:10px; font-weight:700; }
    .badge-mooe{ background:rgba(245,158,11,0.12); color:#D97706; border:1px solid rgba(245,158,11,0.2); }
    .badge-ps{ background:rgba(99,102,241,0.12); color:#6366F1; border:1px solid rgba(99,102,241,0.2); }
    .report-section{ scroll-margin-top:80px; }
    .tab-btn{ padding:8px 18px; border-radius:10px; font-size:11px; font-weight:700; transition:all .2s; cursor:pointer; }
    .tab-btn.active{ background:#0EA5E9; color:white; }
    .tab-btn:not(.active){ background:rgba(0,0,0,0.05); color:#64748b; }
    .dark .tab-btn:not(.active){ background:rgba(255,255,255,0.05); color:#94a3b8; }
    .tab-panel{ display:none; }
    .tab-panel.active{ display:block; }
    .slim-scroll::-webkit-scrollbar{ width:4px; } .slim-scroll::-webkit-scrollbar-thumb{ background:#cbd5e1; border-radius:99px; }
    .dark .slim-scroll::-webkit-scrollbar-thumb{ background:#334155; }
    @keyframes fadeUp{ from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    .anim-in{ animation:fadeUp .4s ease both; }
    @media print{
        .no-print{ display:none !important; }
        .glass-card{ box-shadow:none; border:1px solid #e2e8f0; background:white; }
        body{ background:white; }
        main{ margin-left:0 !important; }
    }
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'finance_navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between no-print">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Financial Reports</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">
                    <?php echo $selected_month > 0 ? date('F', mktime(0,0,0,$selected_month,1)).' '.$selected_year : 'Full Year '.$selected_year; ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="hidden sm:flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80 transition-all">
                <span class="material-icons-round text-sm">print</span> Print
            </button>
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
        </div>
    </header>

    <!-- Period Filter -->
    <div class="glass-card rounded-2xl p-5 mb-6 no-print">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Year</label>
                <select name="year" class="fin-input" style="width:120px">
                    <?php foreach($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y==$selected_year?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Month</label>
                <select name="month" class="fin-input" style="width:150px">
                    <option value="0" <?php echo $selected_month==0?'selected':''; ?>>Full Year</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m==$selected_month?'selected':''; ?>><?php echo date('F',mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="px-5 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center gap-2">
                <span class="material-icons-round text-sm">bar_chart</span> Generate
            </button>
        </form>
    </div>

    <!-- Period Summary Strip -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <?php
        $summary = [
            ['label'=>'Period Income','val'=>'₱'.number_format($period_income,2),'color'=>'text-fin','icon'=>'trending_up'],
            ['label'=>'Period Expenses','val'=>'₱'.number_format($period_expenses,2),'color'=>'text-expense','icon'=>'receipt_long'],
            ['label'=>'Paid Out to Workers','val'=>'₱'.number_format($total_paid_out,2),'color'=>'text-danger','icon'=>'payments'],
            ['label'=>'Net Equity','val'=>'₱'.number_format($period_equity,2),'color'=>($period_equity>=0?'text-equity':'text-danger'),'icon'=>'balance'],
        ];
        foreach($summary as $i=>$s): ?>
        <div class="glass-card rounded-2xl p-4 anim-in" style="animation-delay:<?php echo $i*.07;?>s">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-icons-round <?php echo $s['color']; ?> text-lg"><?php echo $s['icon']; ?></span>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo $s['label']; ?></p>
            </div>
            <p class="font-display text-xl font-bold <?php echo $s['color']; ?>"><?php echo $s['val']; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tab Navigation -->
    <div class="flex flex-wrap gap-2 mb-6 no-print">
        <button class="tab-btn active" onclick="switchTab('income')">📊 Income Summary</button>
        <button class="tab-btn" onclick="switchTab('expenses')">🧾 Expense Breakdown</button>
        <button class="tab-btn" onclick="switchTab('payouts')">💸 Worker Payouts</button>
        <button class="tab-btn" onclick="switchTab('equity')">📈 Equity Over Time</button>
    </div>

    <!-- ── TAB 1: MONTHLY INCOME SUMMARY ──────────────────── -->
    <div id="tab-income" class="tab-panel active">
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="section-title">Monthly Income Summary</p>
            <p class="section-sub"><?php echo $selected_year; ?> — Booking Fees + Commissions</p>

            <div class="h-64 mb-8">
                <canvas id="incomeChart"></canvas>
            </div>

            <div class="overflow-x-auto slim-scroll">
                <table class="fin-table w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Month</th>
                            <th class="text-right">Booking Fees (₱30)</th>
                            <th class="text-right">Commissions (4%)</th>
                            <th class="text-right">Total Income</th>
                            <th class="text-right">vs Prior Month</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $prev_total = 0;
                    for($m=1;$m<=12;$m++):
                        $row = $income_by_month[$m];
                        $bf  = (float)$row['booking_fees'];
                        $com = (float)$row['commissions'];
                        $tot = (float)$row['total'];
                        $diff = $m > 1 ? $tot - $prev_total : 0;
                        $highlight = ($selected_month === $m) ? 'bg-sky-50 dark:bg-sky-900/10' : '';
                    ?>
                    <tr class="<?php echo $highlight; ?>">
                        <td class="font-semibold"><?php echo date('F',mktime(0,0,0,$m,1)); ?></td>
                        <td class="text-right font-mono"><?php echo $bf > 0 ? '₱'.number_format($bf,2) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono"><?php echo $com > 0 ? '₱'.number_format($com,2) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono font-bold <?php echo $tot > 0 ? 'text-fin' : 'text-slate-300 dark:text-slate-600'; ?>">
                            <?php echo $tot > 0 ? '₱'.number_format($tot,2) : '—'; ?>
                        </td>
                        <td class="text-right text-[11px]">
                            <?php if($m===1 || $prev_total===0): ?>
                            <span class="text-slate-300 dark:text-slate-600">—</span>
                            <?php elseif($diff > 0): ?>
                            <span class="text-equity font-semibold">▲ ₱<?php echo number_format($diff,2); ?></span>
                            <?php elseif($diff < 0): ?>
                            <span class="text-danger font-semibold">▼ ₱<?php echo number_format(abs($diff),2); ?></span>
                            <?php else: ?>
                            <span class="text-slate-400">No change</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php $prev_total = $tot; endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-display uppercase tracking-wider text-slate-500">TOTAL</td>
                            <td class="text-right font-mono">₱<?php echo number_format(array_sum(array_column($income_by_month,'booking_fees')),2); ?></td>
                            <td class="text-right font-mono">₱<?php echo number_format(array_sum(array_column($income_by_month,'commissions')),2); ?></td>
                            <td class="text-right font-mono text-fin">₱<?php echo number_format($period_income,2); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ── TAB 2: EXPENSE BREAKDOWN ───────────────────────── -->
    <div id="tab-expenses" class="tab-panel">
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="section-title">Expense Breakdown</p>
            <p class="section-sub">MOOE vs Personnel Services — <?php echo $selected_year; ?></p>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="h-64"><canvas id="expChart"></canvas></div>
                <!-- Category breakdown -->
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">Top Categories</p>
                    <?php if(count($categories) > 0):
                        $cat_total = array_sum(array_column($categories,'total'));
                        foreach($categories as $cat):
                            $pct = $cat_total > 0 ? round($cat['total']/$cat_total*100) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="flex justify-between items-center mb-1">
                            <div class="flex items-center gap-2">
                                <span class="badge badge-<?php echo strtolower($cat['expense_type']); ?>"><?php echo $cat['expense_type']; ?></span>
                                <span class="text-[11px] font-medium"><?php echo htmlspecialchars($cat['category']); ?></span>
                            </div>
                            <span class="text-[11px] font-bold">₱<?php echo number_format($cat['total'],2); ?></span>
                        </div>
                        <div class="h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?php echo $cat['expense_type']==='MOOE'?'bg-expense':'bg-primary'; ?>" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-sm text-slate-400 text-center py-8">No expenses logged yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="overflow-x-auto slim-scroll">
                <table class="fin-table w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Month</th>
                            <th class="text-right">MOOE</th>
                            <th class="text-right">PS (Personnel)</th>
                            <th class="text-right">Total Expenses</th>
                            <th class="text-right">Net (Income − Exp)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for($m=1;$m<=12;$m++):
                        $mooe = (float)($exp_by_month[$m]['MOOE']??0);
                        $ps   = (float)($exp_by_month[$m]['PS']??0);
                        $tot  = $mooe + $ps;
                        $inc  = (float)($income_by_month[$m]['total']??0);
                        $net  = $inc - $tot;
                        $highlight = ($selected_month === $m) ? 'bg-amber-50 dark:bg-amber-900/10' : '';
                    ?>
                    <tr class="<?php echo $highlight; ?>">
                        <td class="font-semibold"><?php echo date('F',mktime(0,0,0,$m,1)); ?></td>
                        <td class="text-right font-mono"><?php echo $mooe > 0 ? '₱'.number_format($mooe,2) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono"><?php echo $ps > 0 ? '₱'.number_format($ps,2) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono font-bold <?php echo $tot > 0 ? 'text-expense' : 'text-slate-300 dark:text-slate-600'; ?>">
                            <?php echo $tot > 0 ? '₱'.number_format($tot,2) : '—'; ?>
                        </td>
                        <td class="text-right font-mono font-bold <?php echo $net >= 0 ? 'text-equity' : 'text-danger'; ?>">
                            <?php echo ($inc > 0 || $tot > 0) ? '₱'.number_format($net,2) : '<span class="text-slate-300 dark:text-slate-600">—</span>'; ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-display uppercase tracking-wider text-slate-500">TOTAL</td>
                            <td class="text-right font-mono">₱<?php echo number_format($period_mooe,2); ?></td>
                            <td class="text-right font-mono">₱<?php echo number_format($period_ps,2); ?></td>
                            <td class="text-right font-mono text-expense">₱<?php echo number_format($period_expenses,2); ?></td>
                            <td class="text-right font-mono <?php echo ($period_income-$period_expenses)>=0?'text-equity':'text-danger'; ?>">
                                ₱<?php echo number_format($period_income-$period_expenses,2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ── TAB 3: WORKER PAYOUT REPORT ───────────────────── -->
    <div id="tab-payouts" class="tab-panel">
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="section-title">Worker Payout Report</p>
            <p class="section-sub">Withdrawal history by worker — <?php echo $selected_year; ?></p>

            <?php if(count($payouts) > 0): ?>
            <div class="overflow-x-auto slim-scroll">
                <table class="fin-table w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Worker</th>
                            <th class="text-right">Requests</th>
                            <th class="text-right">Total Requested</th>
                            <th class="text-right">Total Paid</th>
                            <th class="text-right">Pending</th>
                            <th class="text-right">Current Wallet</th>
                            <th class="text-left">Last Request</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($payouts as $p): ?>
                    <tr>
                        <td>
                            <p class="font-medium text-[12px]"><?php echo htmlspecialchars($p['full_name']??'—'); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($p['email']??'—'); ?></p>
                        </td>
                        <td class="text-right font-mono"><?php echo $p['total_requests']; ?></td>
                        <td class="text-right font-mono font-bold text-fin">₱<?php echo number_format($p['total_requested'],2); ?></td>
                        <td class="text-right font-mono font-bold text-equity">₱<?php echo number_format($p['total_paid'],2); ?></td>
                        <td class="text-right font-mono <?php echo $p['total_pending']>0?'text-expense font-bold':'text-slate-300 dark:text-slate-600'; ?>">
                            <?php echo $p['total_pending']>0 ? '₱'.number_format($p['total_pending'],2) : '—'; ?>
                        </td>
                        <td class="text-right font-mono text-[12px] <?php echo $p['current_balance']>0?'text-equity':'text-slate-400'; ?>">
                            ₱<?php echo number_format($p['current_balance']??0,2); ?>
                        </td>
                        <td class="text-[11px] text-slate-400">
                            <?php echo $p['last_request'] ? date('M d, Y', strtotime($p['last_request'])) : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-display uppercase tracking-wider text-slate-500">TOTAL</td>
                            <td class="text-right font-mono"><?php echo array_sum(array_column($payouts,'total_requests')); ?></td>
                            <td class="text-right font-mono text-fin">₱<?php echo number_format(array_sum(array_column($payouts,'total_requested')),2); ?></td>
                            <td class="text-right font-mono text-equity">₱<?php echo number_format($total_paid_out,2); ?></td>
                            <td class="text-right font-mono text-expense">₱<?php echo number_format(array_sum(array_column($payouts,'total_pending')),2); ?></td>
                            <td></td><td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-16 text-slate-400">
                <span class="material-icons-round text-3xl block mb-2 opacity-30">payments</span>
                No withdrawal records for <?php echo $selected_year; ?>.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── TAB 4: EQUITY OVER TIME ────────────────────────── -->
    <div id="tab-equity" class="tab-panel">
        <div class="glass-card rounded-2xl p-6 mb-6">
            <p class="section-title">Equity Over Time</p>
            <p class="section-sub">Monthly net position (Income − Expenses) — <?php echo $selected_year; ?></p>

            <div class="h-72 mb-8">
                <canvas id="equityChart"></canvas>
            </div>

            <div class="overflow-x-auto slim-scroll">
                <table class="fin-table w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Month</th>
                            <th class="text-right">Income</th>
                            <th class="text-right">Expenses</th>
                            <th class="text-right">Monthly Equity</th>
                            <th class="text-right">Running Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $running = 0;
                    for($m=1;$m<=12;$m++):
                        $inc = (float)($income_by_month[$m]['total']??0);
                        $exp = (float)(($exp_by_month[$m]['MOOE']??0)+($exp_by_month[$m]['PS']??0));
                        $meq = $inc - $exp;
                        $running += $meq;
                        $highlight = ($selected_month === $m) ? 'bg-emerald-50 dark:bg-emerald-900/10' : '';
                    ?>
                    <tr class="<?php echo $highlight; ?>">
                        <td class="font-semibold"><?php echo date('F',mktime(0,0,0,$m,1)); ?></td>
                        <td class="text-right font-mono"><?php echo $inc>0?'₱'.number_format($inc,2):'<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono"><?php echo $exp>0?'₱'.number_format($exp,2):'<span class="text-slate-300 dark:text-slate-600">—</span>'; ?></td>
                        <td class="text-right font-mono font-bold <?php echo $meq>=0?'text-equity':'text-danger'; ?>">
                            <?php echo ($inc>0||$exp>0)?'₱'.number_format($meq,2):'<span class="text-slate-300 dark:text-slate-600">—</span>'; ?>
                        </td>
                        <td class="text-right font-mono font-bold <?php echo $running>=0?'text-equity':'text-danger'; ?>">
                            ₱<?php echo number_format($running,2); ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-display uppercase tracking-wider text-slate-500">YEAR TOTAL</td>
                            <td class="text-right font-mono text-fin">₱<?php echo number_format($period_income,2); ?></td>
                            <td class="text-right font-mono text-expense">₱<?php echo number_format($period_expenses,2); ?></td>
                            <td class="text-right font-mono <?php echo ($period_income-$period_expenses)>=0?'text-equity':'text-danger'; ?>">
                                ₱<?php echo number_format($period_income-$period_expenses,2); ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</main>

<script>
// ── Chart data from PHP ─────────────────────────────────────
const monthLabels = <?php echo json_encode(array_values(array_map(fn($m)=>date('M',mktime(0,0,0,$m,1)),range(1,12)))); ?>;
const incomeData  = <?php echo json_encode(array_values(array_column($income_by_month,'total'))); ?>;
const bookingFees = <?php echo json_encode(array_values(array_column($income_by_month,'booking_fees'))); ?>;
const commissions = <?php echo json_encode(array_values(array_column($income_by_month,'commissions'))); ?>;
const mooeData    = <?php echo json_encode(array_values(array_column($exp_by_month,'MOOE'))); ?>;
const psData      = <?php echo json_encode(array_values(array_column($exp_by_month,'PS'))); ?>;
const equityData  = <?php echo json_encode($equity_chart); ?>;

let incomeChart, expChart, equityChartInst;

function getTheme(){
    const dark = document.documentElement.classList.contains('dark');
    return { text: dark?'#94a3b8':'#64748b', grid: dark?'rgba(255,255,255,0.05)':'rgba(0,0,0,0.06)' };
}

function buildCharts(){
    const t = getTheme();
    const opts = (label,callback) => ({
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label: ctx => callback(ctx) }}},
        scales:{
            y:{ beginAtZero:true, grid:{color:t.grid,drawBorder:false}, ticks:{color:t.text,font:{size:10,family:'Plus Jakarta Sans'},callback:v=>'₱'+v.toLocaleString()} },
            x:{ grid:{display:false}, ticks:{color:t.text,font:{size:10,family:'Plus Jakarta Sans'}} }
        }
    });
    const fmt = ctx => ' ₱'+ctx.parsed.y.toLocaleString('en-PH',{minimumFractionDigits:2});

    // Income chart
    const ctx1 = document.getElementById('incomeChart')?.getContext('2d');
    if(ctx1){
        incomeChart = new Chart(ctx1, {
            type:'bar',
            data:{ labels:monthLabels, datasets:[
                { label:'Booking Fees', data:bookingFees, backgroundColor:'rgba(14,165,233,0.8)', borderRadius:5, barPercentage:.6 },
                { label:'Commissions', data:commissions, backgroundColor:'rgba(16,185,129,0.8)', borderRadius:5, barPercentage:.6 }
            ]},
            options:{ ...opts('',fmt), plugins:{ legend:{ display:true, labels:{color:getTheme().text,font:{size:11}} }, tooltip:{ callbacks:{ label:fmt } } }, interaction:{mode:'index',intersect:false} }
        });
    }

    // Expense chart
    const ctx2 = document.getElementById('expChart')?.getContext('2d');
    if(ctx2){
        expChart = new Chart(ctx2, {
            type:'bar',
            data:{ labels:monthLabels, datasets:[
                { label:'MOOE', data:mooeData, backgroundColor:'rgba(245,158,11,0.8)', borderRadius:5, barPercentage:.6 },
                { label:'PS',   data:psData,   backgroundColor:'rgba(99,102,241,0.8)', borderRadius:5, barPercentage:.6 }
            ]},
            options:{ ...opts('',fmt), plugins:{ legend:{ display:true, labels:{color:getTheme().text,font:{size:11}} }, tooltip:{ callbacks:{ label:fmt } } }, interaction:{mode:'index',intersect:false} }
        });
    }

    // Equity chart
    const ctx3 = document.getElementById('equityChart')?.getContext('2d');
    if(ctx3){
        const grad = ctx3.createLinearGradient(0,0,0,250);
        grad.addColorStop(0,'rgba(16,185,129,0.3)');
        grad.addColorStop(1,'rgba(16,185,129,0)');
        equityChartInst = new Chart(ctx3, {
            type:'line',
            data:{ labels:monthLabels, datasets:[{
                label:'Monthly Equity', data:equityData,
                borderColor:'#10B981', backgroundColor:grad,
                borderWidth:2.5, pointBackgroundColor:'#fff', pointBorderColor:'#10B981',
                pointBorderWidth:2, pointRadius:4, fill:true, tension:0.4
            }]},
            options: opts('',fmt)
        });
    }
}

function rebuildCharts(){
    [incomeChart,expChart,equityChartInst].forEach(c=>c&&c.destroy());
    buildCharts();
}

// ── Tab switching ───────────────────────────────────────────
function switchTab(id){
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+id).classList.add('active');
    event.target.classList.add('active');
    // Rebuild charts when switching to a chart tab
    setTimeout(()=>{ [incomeChart,expChart,equityChartInst].forEach(c=>c&&c.update()); }, 50);
}

document.addEventListener('DOMContentLoaded', buildCharts);
</script>
</body>
</html>