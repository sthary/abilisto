<?php
// admin/hr.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}

$hr_user_id = $_SESSION['user_id'];
$hr_stmt = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$hr_stmt->execute([$hr_user_id]);
$hr_name = $hr_stmt->fetch()['full_name'] ?? 'HR';

// ── KPIs ───────────────────────────────────────────────────
$emp_stats = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN employment_status='Active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN employment_status='On Leave' THEN 1 ELSE 0 END) as on_leave,
    SUM(CASE WHEN employment_status='Terminated' THEN 1 ELSE 0 END) as terminated,
    SUM(basic_salary) as total_payroll
FROM employees")->fetch();

$payroll_stats = $conn->query("SELECT
    COUNT(*) as total_runs,
    SUM(CASE WHEN status='Released' THEN total_net ELSE 0 END) as total_released,
    SUM(CASE WHEN status='Draft' THEN 1 ELSE 0 END) as drafts,
    SUM(CASE WHEN status='For Approval' THEN 1 ELSE 0 END) as pending_approval
FROM payroll_runs")->fetch();

$worker_stats = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN role='worker' THEN 1 ELSE 0 END) as workers,
    SUM(CASE WHEN role='client' THEN 1 ELSE 0 END) as clients
FROM users WHERE role IN ('worker','client')")->fetch();

$pending_withdrawals = (int)$conn->query("SELECT COUNT(*) as c FROM withdrawals WHERE status='Pending'")->fetch()['c'];
$pending_verifications = (int)$conn->query("SELECT COUNT(*) as c FROM verification WHERE verification_status='pending'")->fetch()['c'];

// ── Recent employees ────────────────────────────────────────
$recent_emps = $conn->query("SELECT * FROM employees ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ── Payroll runs ────────────────────────────────────────────
$recent_payrolls = $conn->query("SELECT pr.*, u.full_name as generated_by_name
    FROM payroll_runs pr LEFT JOIN users u ON u.id=pr.generated_by
    ORDER BY pr.created_at DESC LIMIT 5")->fetchAll();

// ── Dept breakdown ──────────────────────────────────────────
$dept_res = $conn->query("SELECT department, COUNT(*) as cnt, SUM(basic_salary) as salary
    FROM employees WHERE employment_status='Active'
    GROUP BY department ORDER BY cnt DESC");
$depts = [];
while($d=$dept_res->fetch()) $depts[]=$d;

$current_date = date('M d, Y');
$notif_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
$notif_stmt->execute([$hr_user_id]);
$notif_count  = (int)($notif_stmt->fetch()['c']??0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — HR Dashboard</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{hr:"#8B5CF6",equity:"#10B981",danger:"#F43F5E",expense:"#F59E0B"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],body:["Plus Jakarta Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
    function toggleMobileMenu(){document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');document.getElementById('menu-overlay').classList.toggle('hidden');}
</script>
<style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#F5F3FF;}
    .dark body{background:#0B0F1A;}
    h1,h2,h3,.font-display{font-family:'Plus Jakarta Sans',sans-serif;}
    .glass-card{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.6);box-shadow:0 4px 24px rgba(0,0,0,0.06);}
    .dark .glass-card{background:rgba(17,24,39,0.75);border:1px solid rgba(255,255,255,0.07);}
    .kpi-card{position:relative;overflow:hidden;}
    .kpi-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;border-radius:4px 0 0 4px;}
    .kpi-hr::before{background:#8B5CF6;}
    .kpi-green::before{background:#10B981;}
    .kpi-red::before{background:#F43F5E;}
    .kpi-amber::before{background:#F59E0B;}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-active{background:rgba(16,185,129,.12);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .badge-leave{background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}
    .badge-terminated{background:rgba(244,63,94,.12);color:#F43F5E;border:1px solid rgba(244,63,94,.2);}
    .badge-draft{background:rgba(148,163,184,.12);color:#64748b;border:1px solid rgba(148,163,184,.2);}
    .badge-approval{background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.2);}
    .badge-released{background:rgba(16,185,129,.12);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    @keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    .anim-in{animation:fadeUp .4s ease both;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">HR Dashboard</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Human Resources Overview</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="hr_employees.php?action=add" class="hidden sm:flex items-center gap-2 px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all">
                <span class="material-icons-round text-sm">person_add</span> Add Employee
            </a>
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <div class="hidden sm:block text-right ml-2">
                <p class="text-[10px] font-bold"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-400 uppercase tracking-tighter">HR Overview</p>
            </div>
        </div>
    </header>

    <!-- Alert strip — pending items -->
    <?php if($pending_withdrawals > 0 || $pending_verifications > 0): ?>
    <div class="flex flex-wrap gap-3 mb-6">
        <?php if($pending_withdrawals > 0): ?>
        <a href="hr_withdrawals.php" class="flex items-center gap-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-xs font-bold text-red-600 dark:text-red-400 hover:opacity-90 transition-all">
            <span class="material-icons-round text-sm">account_balance_wallet</span>
            <?php echo $pending_withdrawals; ?> withdrawal<?php echo $pending_withdrawals>1?'s':''; ?> awaiting approval
            <span class="material-icons-round text-sm">arrow_forward</span>
        </a>
        <?php endif; ?>
        <?php if($pending_verifications > 0): ?>
        <a href="hr_verifications.php" class="flex items-center gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-xs font-bold text-amber-600 dark:text-amber-400 hover:opacity-90 transition-all">
            <span class="material-icons-round text-sm">verified_user</span>
            <?php echo $pending_verifications; ?> verification<?php echo $pending_verifications>1?'s':''; ?> pending review
            <span class="material-icons-round text-sm">arrow_forward</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="glass-card kpi-card kpi-hr rounded-2xl p-5 anim-in">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-hr text-lg">badge</span>
                </div>
            </div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Employees</p>
            <p class="font-display text-2xl font-bold mt-1"><?php echo number_format($emp_stats['total']??0); ?></p>
            <p class="text-[10px] text-slate-400 mt-2"><?php echo $emp_stats['active']??0; ?> active · <?php echo $emp_stats['on_leave']??0; ?> on leave</p>
        </div>
        <div class="glass-card kpi-card kpi-amber rounded-2xl p-5 anim-in" style="animation-delay:.07s">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-expense text-lg">payments</span>
                </div>
            </div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Monthly Payroll</p>
            <p class="font-display text-2xl font-bold mt-1">₱<?php echo number_format($emp_stats['total_payroll']??0,2); ?></p>
            <p class="text-[10px] text-slate-400 mt-2">Combined basic salary</p>
        </div>
        <div class="glass-card kpi-card kpi-green rounded-2xl p-5 anim-in" style="animation-delay:.14s">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-equity text-lg">receipt_long</span>
                </div>
            </div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total Released</p>
            <p class="font-display text-2xl font-bold mt-1">₱<?php echo number_format($payroll_stats['total_released']??0,2); ?></p>
            <p class="text-[10px] text-slate-400 mt-2"><?php echo $payroll_stats['total_runs']??0; ?> payroll run<?php echo ($payroll_stats['total_runs']??0)!=1?'s':''; ?></p>
        </div>
        <div class="glass-card kpi-card kpi-red rounded-2xl p-5 anim-in" style="animation-delay:.21s">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-9 h-9 rounded-xl bg-rose-100 dark:bg-rose-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-danger text-lg">pending_actions</span>
                </div>
            </div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Action Required</p>
            <p class="font-display text-2xl font-bold mt-1"><?php echo ($pending_withdrawals+$pending_verifications+($payroll_stats['pending_approval']??0)); ?></p>
            <p class="text-[10px] text-slate-400 mt-2">Pending items</p>
        </div>
    </div>

    <!-- Main content: Department breakdown + Recent activity -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

        <!-- Department Breakdown -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="font-display text-sm font-bold mb-1">Department Headcount</h3>
            <p class="text-[10px] text-slate-400 uppercase tracking-wider mb-5">Active employees by dept.</p>
            <?php if(count($depts)>0):
                $max_cnt = max(array_column($depts,'cnt'));
                $dept_colors=['Management'=>'bg-violet-500','Finance'=>'bg-sky-500','HR'=>'bg-emerald-500','Tech'=>'bg-indigo-500','Operations'=>'bg-amber-500','Support'=>'bg-rose-500','Other'=>'bg-slate-400'];
                foreach($depts as $d):
                    $pct = $max_cnt>0?round($d['cnt']/$max_cnt*100):0;
                    $col = $dept_colors[$d['department']]??'bg-slate-400';
            ?>
            <div class="mb-4">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-xs font-semibold"><?php echo $d['department']; ?></span>
                    <span class="text-xs font-bold text-slate-500"><?php echo $d['cnt']; ?> · ₱<?php echo number_format($d['salary'],0); ?></span>
                </div>
                <div class="h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full <?php echo $col; ?> rounded-full" style="width:<?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <p class="text-sm text-slate-400 text-center py-8">No employees yet.<br><a href="hr_employees.php?action=add" class="text-hr font-semibold">Add your first employee →</a></p>
            <?php endif; ?>
        </div>

        <!-- Recent Employees -->
        <div class="glass-card rounded-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-white/30 dark:border-white/10 flex justify-between items-center">
                <div>
                    <h3 class="font-display text-sm font-bold">Recent Hires</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">Latest added</p>
                </div>
                <a href="hr_employees.php" class="text-[10px] font-bold text-hr hover:underline uppercase tracking-wider">View All</a>
            </div>
            <div class="divide-y divide-slate-50 dark:divide-slate-800/50">
            <?php if(count($recent_emps)>0):
                foreach($recent_emps as $e):
                    $sclass=['Active'=>'badge-active','On Leave'=>'badge-leave','Terminated'=>'badge-terminated'][$e['employment_status']]??'badge-draft';
            ?>
            <div class="px-6 py-3.5 flex items-center justify-between hover:bg-violet-50/30 dark:hover:bg-violet-900/10 transition-all">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center flex-shrink-0">
                        <span class="text-xs font-bold text-hr"><?php echo strtoupper(substr($e['full_name'],0,2)); ?></span>
                    </div>
                    <div>
                        <p class="text-xs font-bold"><?php echo htmlspecialchars($e['full_name']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($e['position']); ?> · <?php echo $e['department']; ?></p>
                    </div>
                </div>
                <span class="badge <?php echo $sclass; ?>"><?php echo $e['employment_status']; ?></span>
            </div>
            <?php endforeach; else: ?>
            <div class="px-6 py-10 text-center text-slate-400 text-sm">No employees yet.</div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Recent Payroll Runs -->
        <div class="glass-card rounded-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-white/30 dark:border-white/10 flex justify-between items-center">
                <div>
                    <h3 class="font-display text-sm font-bold">Payroll Runs</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">Recent cycles</p>
                </div>
                <a href="hr_payroll.php" class="text-[10px] font-bold text-hr hover:underline uppercase tracking-wider">Manage</a>
            </div>
            <div class="divide-y divide-slate-50 dark:divide-slate-800/50">
            <?php if(count($recent_payrolls)>0):
                foreach($recent_payrolls as $p):
                    $ps=['Draft'=>'badge-draft','For Approval'=>'badge-approval','Released'=>'badge-released'][$p['status']]??'badge-draft';
            ?>
            <div class="px-6 py-3.5 hover:bg-violet-50/30 dark:hover:bg-violet-900/10 transition-all">
                <div class="flex justify-between items-start">
                    <p class="text-xs font-bold truncate max-w-[160px]"><?php echo htmlspecialchars($p['title']); ?></p>
                    <span class="badge <?php echo $ps; ?>"><?php echo $p['status']; ?></span>
                </div>
                <div class="flex justify-between mt-1">
                    <p class="text-[10px] text-slate-400"><?php echo date('M d',strtotime($p['period_start'])); ?> – <?php echo date('M d, Y',strtotime($p['period_end'])); ?></p>
                    <p class="text-[10px] font-bold text-equity">₱<?php echo number_format($p['total_net'],2); ?></p>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="px-6 py-10 text-center text-slate-400 text-sm">No payroll runs yet.<br><a href="hr_payroll.php?action=new" class="text-hr font-semibold">Generate first payroll →</a></div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <?php $actions=[
            ['href'=>'hr_employees.php?action=add','icon'=>'person_add','label'=>'Add Employee','color'=>'violet'],
            ['href'=>'hr_payroll.php?action=new','icon'=>'add_card','label'=>'New Payroll Run','color'=>'emerald'],
            ['href'=>'hr_withdrawals.php','icon'=>'account_balance_wallet','label'=>'Approve Withdrawals','color'=>'sky'],
            ['href'=>'hr_verifications.php','icon'=>'verified_user','label'=>'Review Verifications','color'=>'amber'],
        ];
        foreach($actions as $a): ?>
        <a href="<?php echo $a['href']; ?>" class="glass-card rounded-2xl p-4 flex flex-col items-center gap-2 hover:bg-<?php echo $a['color']; ?>-50 dark:hover:bg-<?php echo $a['color']; ?>-900/10 transition-all group text-center">
            <div class="w-10 h-10 rounded-xl bg-<?php echo $a['color']; ?>-100 dark:bg-<?php echo $a['color']; ?>-900/30 flex items-center justify-center group-hover:scale-110 transition-transform">
                <span class="material-icons-round text-<?php echo $a['color']; ?>-500 text-xl"><?php echo $a['icon']; ?></span>
            </div>
            <p class="text-[11px] font-bold text-slate-600 dark:text-slate-300"><?php echo $a['label']; ?></p>
        </a>
        <?php endforeach; ?>
    </div>

</main>
</body>
</html>