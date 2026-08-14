<?php
// admin/finance_expenses.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php"); exit();
}

$fin_user_id = $_SESSION['user_id'];
$fin_name    = $conn->query("SELECT full_name FROM users WHERE id=$fin_user_id")->fetch_assoc()['full_name'] ?? 'Finance';

// ============================================================
// HANDLE AJAX POSTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // ── Add manual expense ────────────────────────────────
    if ($_POST['action'] === 'add_expense') {
        $type  = $conn->real_escape_string($_POST['expense_type'] ?? '');
        $cat   = $conn->real_escape_string($_POST['category']     ?? '');
        $desc  = $conn->real_escape_string($_POST['description']  ?? '');
        $amt   = floatval($_POST['amount']  ?? 0);
        $date  = $conn->real_escape_string($_POST['expense_date'] ?? date('Y-m-d'));

        if (!in_array($type, ['MOOE','PS']) || $amt <= 0 || empty($cat)) {
            echo json_encode(['success'=>false,'message'=>'Invalid input.']); exit();
        }
        $conn->query("INSERT INTO admin_expenses (expense_type,category,description,amount,expense_date,logged_by)
                      VALUES ('$type','$cat','$desc',$amt,'$date',$fin_user_id)");
        echo json_encode(['success'=>true,'id'=>$conn->insert_id]); exit();
    }

    // ── Delete manual expense ─────────────────────────────
    if ($_POST['action'] === 'delete_expense') {
        $eid = intval($_POST['expense_id'] ?? 0);
        // Prevent deleting payroll-linked entries
        $linked = $conn->query("SELECT id FROM payroll_runs WHERE expense_id=$eid")->num_rows;
        if ($linked > 0) { echo json_encode(['success'=>false,'message'=>'Cannot delete — linked to a payroll run.']); exit(); }
        $conn->query("DELETE FROM admin_expenses WHERE id=$eid");
        echo json_encode(['success'=>true]); exit();
    }

    // ── Approve payroll run → log to admin_expenses as PS ──
    if ($_POST['action'] === 'approve_payroll') {
        $run_id = intval($_POST['run_id'] ?? 0);

        // Verify the run exists and is in 'Approved' status (set by HR)
        $run = $conn->query("SELECT * FROM payroll_runs WHERE id=$run_id AND status='Approved'")->fetch_assoc();
        if (!$run) { echo json_encode(['success'=>false,'message'=>'Payroll run not found or not yet approved by HR.']); exit(); }
        if ($run['expense_id']) { echo json_encode(['success'=>false,'message'=>'Already logged to expenses.']); exit(); }

        $desc = $conn->real_escape_string("Payroll: {$run['title']} — {$run['employee_count']} employee(s)");
        $amt  = (float)$run['total_net'];
        $date = $run['pay_date'];

        $conn->query("INSERT INTO admin_expenses (expense_type,category,description,amount,expense_date,logged_by)
                      VALUES ('PS','Staff Salary','$desc',$amt,'$date',$fin_user_id)");
        $exp_id = $conn->insert_id;

        $conn->query("UPDATE payroll_runs SET
            status='Released', expense_id=$exp_id,
            released_at=NOW(), approved_by=$fin_user_id, approved_at=NOW()
            WHERE id=$run_id");

        // Notify HR that Finance approved it
        $hr_id = (int)$conn->query("SELECT id FROM users WHERE role='hr' LIMIT 1")->fetch_assoc()['id'];
        if ($hr_id) {
            $notif = $conn->real_escape_string("✅ Finance has approved payroll run: {$run['title']}. It has been logged as a PS expense.");
            $conn->query("INSERT INTO notifications (user_id,message,link) VALUES ($hr_id,'$notif','../admin/hr_payroll.php?run=$run_id')");
        }

        echo json_encode(['success'=>true,'expense_id'=>$exp_id,'amount'=>$amt,'desc'=>$run['title']]); exit();
    }

    // ── Reject payroll run ────────────────────────────────
    if ($_POST['action'] === 'reject_payroll') {
        $run_id = intval($_POST['run_id'] ?? 0);
        $reason = $conn->real_escape_string(trim($_POST['reason'] ?? ''));
        $conn->query("UPDATE payroll_runs SET status='For Approval', notes=CONCAT(COALESCE(notes,''),' | Finance rejected: $reason') WHERE id=$run_id AND status='Approved'");

        $hr_id = (int)$conn->query("SELECT id FROM users WHERE role='hr' LIMIT 1")->fetch_assoc()['id'];
        if ($hr_id) {
            $notif = $conn->real_escape_string("⚠️ Finance rejected payroll run #$run_id. Reason: $reason");
            $conn->query("INSERT INTO notifications (user_id,message,link) VALUES ($hr_id,'$notif','../admin/hr_payroll.php?run=$run_id')");
        }
        echo json_encode(['success'=>true]); exit();
    }
}

// ============================================================
// DATA QUERIES
// ============================================================

// ── Payroll runs awaiting Finance approval (HR set to Approved) ──
$pending_payrolls_res = $conn->query("SELECT pr.*, u.full_name as gen_by
    FROM payroll_runs pr
    LEFT JOIN users u ON u.id = pr.generated_by
    WHERE pr.status = 'Approved' AND pr.expense_id IS NULL
    ORDER BY pr.pay_date ASC");
$pending_payrolls = [];
while ($r = $pending_payrolls_res->fetch_assoc()) $pending_payrolls[] = $r;

// ── Filters ──────────────────────────────────────────────────
$search    = $conn->real_escape_string($_GET['search']   ?? '');
$type_f    = $conn->real_escape_string($_GET['type']     ?? '');
$date_from = $conn->real_escape_string($_GET['date_from']?? '');
$date_to   = $conn->real_escape_string($_GET['date_to']  ?? '');
$source_f  = $conn->real_escape_string($_GET['source']   ?? ''); // 'manual' | 'payroll' | ''
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 25;
$offset    = ($page - 1) * $per_page;

$where = "WHERE 1=1";
if ($search)    $where .= " AND (ae.category LIKE '%$search%' OR ae.description LIKE '%$search%' OR u.full_name LIKE '%$search%')";
if ($type_f)    $where .= " AND ae.expense_type='$type_f'";
if ($date_from) $where .= " AND ae.expense_date>='$date_from'";
if ($date_to)   $where .= " AND ae.expense_date<='$date_to'";
if ($source_f === 'payroll')  $where .= " AND pr.id IS NOT NULL";
if ($source_f === 'manual')   $where .= " AND pr.id IS NULL";

// ── Summary stats ─────────────────────────────────────────────
$stats = $conn->query("SELECT
    COUNT(*) as total,
    COALESCE(SUM(ae.amount),0) as total_all,
    COALESCE(SUM(CASE WHEN ae.expense_type='MOOE' THEN ae.amount ELSE 0 END),0) as total_mooe,
    COALESCE(SUM(CASE WHEN ae.expense_type='PS'   THEN ae.amount ELSE 0 END),0) as total_ps,
    COALESCE(SUM(CASE WHEN pr.id IS NOT NULL THEN ae.amount ELSE 0 END),0) as total_payroll_ps
FROM admin_expenses ae
LEFT JOIN users u ON u.id = ae.logged_by
LEFT JOIN payroll_runs pr ON pr.expense_id = ae.id
$where")->fetch_assoc();

$total_rows  = (int)$conn->query("SELECT COUNT(*) as c FROM admin_expenses ae LEFT JOIN users u ON u.id=ae.logged_by LEFT JOIN payroll_runs pr ON pr.expense_id=ae.id $where")->fetch_assoc()['c'];
$total_pages = ceil($total_rows / $per_page);

$rows = $conn->query("SELECT ae.*, u.full_name as logged_by_name, pr.id as payroll_run_id, pr.title as payroll_title, pr.employee_count, pr.total_gross, pr.period_start, pr.period_end
    FROM admin_expenses ae
    LEFT JOIN users u ON u.id = ae.logged_by
    LEFT JOIN payroll_runs pr ON pr.expense_id = ae.id
    $where
    ORDER BY ae.expense_date DESC, ae.created_at DESC
    LIMIT $per_page OFFSET $offset");

// This month breakdown by category
$breakdown = $conn->query("SELECT expense_type, category, SUM(amount) as total
    FROM admin_expenses
    WHERE MONTH(expense_date)=MONTH(NOW()) AND YEAR(expense_date)=YEAR(NOW())
    GROUP BY expense_type, category ORDER BY total DESC LIMIT 8");

$current_date = date('M d, Y');
$notif_count  = (int)($conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id=$fin_user_id AND is_read=0")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — Expense Log</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{fin:"#0EA5E9",equity:"#10B981",danger:"#F43F5E",expense:"#F59E0B",hr:"#8B5CF6"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],body:["Plus Jakarta Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
    function toggleMobileMenu(){document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');document.getElementById('menu-overlay').classList.toggle('hidden');}
</script>
<style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#F0F4FF;} .dark body{background:#0B0F1A;}
    h1,h2,h3,.font-display{font-family:'Plus Jakarta Sans',sans-serif;}
    .glass-card{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.6);box-shadow:0 4px 24px rgba(0,0,0,0.06);}
    .dark .glass-card{background:rgba(17,24,39,0.75);border:1px solid rgba(255,255,255,0.07);}
    .fin-input{background:rgba(255,255,255,0.8);border:1.5px solid rgba(0,0,0,0.1);border-radius:10px;padding:8px 12px;font-size:13px;width:100%;color:#1e293b;transition:border-color .2s;}
    .dark .fin-input{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;}
    .fin-input:focus{outline:none;border-color:#0EA5E9;box-shadow:0 0 0 3px rgba(14,165,233,.15);}
    .fin-label{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;display:block;margin-bottom:4px;}
    .dark .fin-label{color:#94a3b8;}
    .fin-table thead th{font-family:'Plus Jakarta Sans',sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;padding:10px 14px;border-bottom:1px solid rgba(0,0,0,0.06);white-space:nowrap;}
    .dark .fin-table thead th{border-color:rgba(255,255,255,0.06);}
    .fin-table tbody td{padding:11px 14px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.04);}
    .dark .fin-table tbody td{border-color:rgba(255,255,255,0.04);}
    .fin-table tbody tr:hover{background:rgba(14,165,233,0.04);}
    .fin-table tbody tr.payroll-row{background:rgba(139,92,246,0.03);}
    .fin-table tbody tr.payroll-row:hover{background:rgba(139,92,246,0.07);}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-mooe{background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.3);}
    .badge-ps{background:rgba(99,102,241,.12);color:#6366F1;border:1px solid rgba(99,102,241,.3);}
    .badge-payroll{background:rgba(139,92,246,.12);color:#8B5CF6;border:1px solid rgba(139,92,246,.3);}
    /* Pending payroll alert card */
    .payroll-alert{background:linear-gradient(135deg,rgba(139,92,246,0.08),rgba(14,165,233,0.06));border:1.5px solid rgba(139,92,246,0.25);border-radius:16px;padding:16px 20px;}
    .dark .payroll-alert{background:linear-gradient(135deg,rgba(139,92,246,0.12),rgba(14,165,233,0.08));border-color:rgba(139,92,246,0.3);}
    /* Form panel */
    .form-panel{position:fixed;top:0;right:0;width:100%;max-width:440px;height:100%;z-index:60;background:white;box-shadow:-8px 0 40px rgba(0,0,0,.12);transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;}
    .dark .form-panel{background:#111827;}
    .form-panel.open{transform:translateX(0);}
    .form-overlay{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:59;display:none;}
    .form-overlay.open{display:block;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}
    #toast.success{background:#10B981;color:white;}#toast.error{background:#F43F5E;color:white;}#toast.info{background:#0EA5E9;color:white;}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    @keyframes slideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
    .slide-in{animation:slideIn .3s ease both;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'finance_navbar.php'; ?>

<div id="toast"></div>
<div class="form-overlay" id="form-overlay" onclick="closeForm()"></div>

<!-- Add Expense Slide Panel -->
<div class="form-panel" id="expense-panel">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
        <div><h2 class="font-display text-base font-bold">Log Expense</h2><p class="text-[10px] text-slate-400 uppercase tracking-wider">MOOE / PS manual entry</p></div>
        <button onclick="closeForm()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400"><span class="material-icons-round">close</span></button>
    </div>
    <div class="flex-1 overflow-y-auto slim-scroll p-6 space-y-4">
        <!-- Type toggle -->
        <div>
            <label class="fin-label">Expense Type</label>
            <div class="grid grid-cols-2 gap-2">
                <label class="relative cursor-pointer">
                    <input type="radio" name="exp_type" value="MOOE" class="sr-only peer" checked>
                    <div class="text-center py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-500 peer-checked:border-expense peer-checked:bg-amber-50 dark:peer-checked:bg-amber-900/20 peer-checked:text-expense transition-all">
                        MOOE<p class="text-[8px] font-normal mt-0.5 opacity-70">Maintenance & Ops</p>
                    </div>
                </label>
                <label class="relative cursor-pointer">
                    <input type="radio" name="exp_type" value="PS" class="sr-only peer">
                    <div class="text-center py-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-500 peer-checked:border-fin peer-checked:bg-sky-50 dark:peer-checked:bg-sky-900/20 peer-checked:text-fin transition-all">
                        PS<p class="text-[8px] font-normal mt-0.5 opacity-70">Personnel Services</p>
                    </div>
                </label>
            </div>
        </div>
        <div>
            <label class="fin-label">Category</label>
            <input type="text" id="exp_category" class="fin-input" placeholder="e.g. Internet Hosting, Staff Salary…" list="cat-list">
            <datalist id="cat-list">
                <option value="Internet & Hosting"><option value="Domain & SSL"><option value="Office Supplies">
                <option value="Equipment Repair"><option value="Staff Salary"><option value="Freelancer Fee">
                <option value="Allowances"><option value="Training & Dev"><option value="SMS / Notifications">
            </datalist>
        </div>
        <div>
            <label class="fin-label">Description <span class="normal-case text-[9px] opacity-60">(optional)</span></label>
            <textarea id="exp_desc" class="fin-input resize-none" rows="2" placeholder="Brief note…"></textarea>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="fin-label">Amount (₱)</label><input type="number" id="exp_amount" class="fin-input" step="0.01" min="0.01" placeholder="0.00"></div>
            <div><label class="fin-label">Date</label><input type="date" id="exp_date" class="fin-input" value="<?php echo date('Y-m-d'); ?>"></div>
        </div>
        <button onclick="submitExpense()" id="exp-submit-btn" class="w-full py-3 bg-fin text-white font-bold text-sm rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
            <span class="material-icons-round text-base">save</span>Log Expense
        </button>
    </div>
</div>

<!-- Reject Payroll Modal -->
<div id="reject-overlay" class="hidden fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-sm shadow-2xl p-6">
        <h3 class="font-display font-bold text-sm mb-1">Reject Payroll Run</h3>
        <p class="text-[10px] text-slate-400 mb-4">Provide a reason — it will be sent to HR.</p>
        <input type="hidden" id="reject-run-id">
        <textarea id="reject-reason" class="fin-input resize-none mb-4" rows="3" placeholder="Reason for rejection…"></textarea>
        <div class="flex gap-3">
            <button onclick="document.getElementById('reject-overlay').classList.add('hidden')" class="flex-1 py-2 border border-slate-200 dark:border-slate-700 text-xs font-bold rounded-xl text-slate-500">Cancel</button>
            <button onclick="confirmReject()" class="flex-1 py-2 bg-danger text-white text-xs font-bold rounded-xl hover:opacity-90">Confirm Reject</button>
        </div>
    </div>
</div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()"><span class="material-icons-round">menu</span></button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Expense Log</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">MOOE · PS · Payroll Approvals</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openForm()" class="flex items-center gap-2 px-4 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all">
                <span class="material-icons-round text-sm">add_circle</span> Log Expense
            </button>
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400"><span class="material-icons-round text-xl">dark_mode</span></button>
            <div class="hidden sm:block text-right ml-2"><p class="text-[10px] font-bold"><?php echo $current_date; ?></p></div>
        </div>
    </header>

    <!-- ── PENDING PAYROLL APPROVALS ──────────────────────────── -->
    <?php if (count($pending_payrolls) > 0): ?>
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-3">
            <span class="material-icons-round text-hr text-lg">pending_actions</span>
            <h2 class="font-display text-sm font-bold">Payroll Runs Awaiting Finance Approval</h2>
            <span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-hr/10 text-hr border border-hr/20"><?php echo count($pending_payrolls); ?></span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($pending_payrolls as $pr): ?>
        <div class="payroll-alert slide-in">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <p class="font-bold text-sm text-slate-800 dark:text-white"><?php echo htmlspecialchars($pr['title']); ?></p>
                    <p class="text-[10px] text-slate-500 mt-0.5">
                        Generated by <strong><?php echo htmlspecialchars($pr['gen_by']); ?></strong> ·
                        Pay date: <strong><?php echo date('M d, Y', strtotime($pr['pay_date'])); ?></strong>
                    </p>
                </div>
                <span class="badge badge-payroll flex-shrink-0">
                    <span class="material-icons-round" style="font-size:10px">groups</span>HR Approved
                </span>
            </div>
            <!-- Payroll summary -->
            <div class="grid grid-cols-3 gap-3 mb-4 bg-white/60 dark:bg-white/5 rounded-xl p-3">
                <div class="text-center">
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">Employees</p>
                    <p class="font-display font-bold text-base"><?php echo $pr['employee_count']; ?></p>
                </div>
                <div class="text-center">
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">Gross</p>
                    <p class="font-display font-bold text-base">₱<?php echo number_format($pr['total_gross'],2); ?></p>
                </div>
                <div class="text-center">
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">Net Pay</p>
                    <p class="font-display font-bold text-base text-equity">₱<?php echo number_format($pr['total_net'],2); ?></p>
                </div>
            </div>
            <p class="text-[10px] text-slate-400 mb-3">
                Period: <?php echo date('M d',strtotime($pr['period_start'])); ?> – <?php echo date('M d, Y',strtotime($pr['period_end'])); ?> ·
                <?php echo $pr['pay_frequency']; ?>
            </p>
            <!-- Action buttons -->
            <div class="flex gap-2">
                <button onclick="approvePayroll(<?php echo $pr['id']; ?>, '<?php echo addslashes(htmlspecialchars($pr['title'])); ?>', <?php echo $pr['total_net']; ?>)"
                        class="flex-1 py-2.5 bg-equity text-white text-xs font-bold rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
                    <span class="material-icons-round text-sm">check_circle</span>
                    Approve & Log as PS (₱<?php echo number_format($pr['total_net'],2); ?>)
                </button>
                <button onclick="openReject(<?php echo $pr['id']; ?>)"
                        class="px-4 py-2.5 border border-danger/30 text-danger text-xs font-bold rounded-xl hover:bg-red-50 dark:hover:bg-red-900/10 active:scale-95 transition-all flex items-center gap-1">
                    <span class="material-icons-round text-sm">close</span>Reject
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php $kpis=[
            ['Total Entries',  number_format($stats['total']),              'receipt_long','text-fin'],
            ['Total OPEX',    '₱'.number_format($stats['total_all'],2),     'payments',    'text-slate-700 dark:text-slate-200'],
            ['MOOE',          '₱'.number_format($stats['total_mooe'],2),    'build',       'text-expense'],
            ['PS (incl. Payroll)','₱'.number_format($stats['total_ps'],2),  'people',      'text-hr'],
        ]; foreach($kpis as $i=>$k): ?>
        <div class="glass-card rounded-2xl p-4" style="animation:fadeUp .4s <?php echo $i*.07; ?>s ease both">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-icons-round <?php echo $k[3]; ?> text-lg"><?php echo $k[2]; ?></span>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo $k[0]; ?></p>
            </div>
            <p class="font-display text-xl font-bold <?php echo $k[3]; ?>"><?php echo $k[1]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Main: Table + This-month sidebar -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        <!-- Expense Table (span 3) -->
        <div class="lg:col-span-3">
            <!-- Filters -->
            <div class="glass-card rounded-2xl p-5 mb-4">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[160px]">
                        <label class="fin-label">Search</label>
                        <input type="text" name="search" class="fin-input" placeholder="Category, description…" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div><label class="fin-label">From</label><input type="date" name="date_from" class="fin-input" value="<?php echo $date_from; ?>"></div>
                    <div><label class="fin-label">To</label><input type="date" name="date_to" class="fin-input" value="<?php echo $date_to; ?>"></div>
                    <div>
                        <label class="fin-label">Type</label>
                        <select name="type" class="fin-input" style="min-width:110px">
                            <option value="">All Types</option>
                            <option value="MOOE" <?php echo $type_f==='MOOE'?'selected':''; ?>>MOOE</option>
                            <option value="PS"   <?php echo $type_f==='PS'?'selected':''; ?>>PS</option>
                        </select>
                    </div>
                    <div>
                        <label class="fin-label">Source</label>
                        <select name="source" class="fin-input" style="min-width:120px">
                            <option value="">All Sources</option>
                            <option value="manual"  <?php echo $source_f==='manual'?'selected':''; ?>>Manual</option>
                            <option value="payroll" <?php echo $source_f==='payroll'?'selected':''; ?>>Payroll</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-fin text-white text-xs font-bold rounded-xl hover:opacity-90 flex items-center gap-1">
                            <span class="material-icons-round text-sm">filter_list</span>Filter
                        </button>
                        <a href="finance_expenses.php" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-500 text-xs font-bold rounded-xl hover:opacity-80 flex items-center gap-1">
                            <span class="material-icons-round text-sm">clear</span>Reset
                        </a>
                    </div>
                    <span class="text-[10px] text-slate-400 self-center ml-auto"><?php echo number_format($total_rows); ?> result<?php echo $total_rows!=1?'s':''; ?></span>
                </form>
            </div>

            <!-- Table -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="overflow-x-auto slim-scroll">
                    <table class="fin-table w-full" id="expense-table">
                        <thead>
                            <tr>
                                <th class="text-left">Date</th>
                                <th class="text-left">Type</th>
                                <th class="text-left">Source</th>
                                <th class="text-left">Category / Description</th>
                                <th class="text-right">Amount</th>
                                <th class="text-left">Logged By</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expense-tbody">
                        <?php if ($rows && $rows->num_rows > 0):
                            while ($r = $rows->fetch_assoc()):
                                $is_payroll = !empty($r['payroll_run_id']);
                        ?>
                        <tr class="<?php echo $is_payroll ? 'payroll-row' : ''; ?>" data-id="<?php echo $r['id']; ?>">
                            <td class="whitespace-nowrap text-slate-500 text-[11px]"><?php echo date('M d, Y', strtotime($r['expense_date'])); ?></td>
                            <td>
                                <span class="badge badge-<?php echo strtolower($r['expense_type']); ?>"><?php echo $r['expense_type']; ?></span>
                            </td>
                            <td>
                                <?php if ($is_payroll): ?>
                                <span class="badge badge-payroll">
                                    <span class="material-icons-round" style="font-size:10px">groups</span>Payroll
                                </span>
                                <?php else: ?>
                                <span class="text-[10px] text-slate-400">Manual</span>
                                <?php endif; ?>
                            </td>
                            <td class="max-w-[220px]">
                                <p class="font-semibold text-[12px]"><?php echo htmlspecialchars($r['category']); ?></p>
                                <?php if ($is_payroll): ?>
                                <p class="text-[10px] text-hr truncate">
                                    <span class="material-icons-round" style="font-size:10px;vertical-align:middle">people</span>
                                    <?php echo htmlspecialchars($r['payroll_title']); ?> · <?php echo $r['employee_count']; ?> emp.
                                </p>
                                <?php elseif (!empty($r['description'])): ?>
                                <p class="text-[10px] text-slate-400 truncate"><?php echo htmlspecialchars($r['description']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-bold font-mono">₱<?php echo number_format($r['amount'], 2); ?></td>
                            <td class="text-[11px] text-slate-400"><?php echo htmlspecialchars($r['logged_by_name'] ?? '—'); ?></td>
                            <td class="text-right">
                                <?php if (!$is_payroll): ?>
                                <button onclick="deleteExpense(<?php echo $r['id']; ?>, this)"
                                        class="p-1.5 text-slate-300 dark:text-slate-600 hover:text-danger transition-colors rounded-lg hover:bg-red-50 dark:hover:bg-red-900/10">
                                    <span class="material-icons-round text-base">delete_outline</span>
                                </button>
                                <?php else: ?>
                                <a href="hr_payroll.php?run=<?php echo $r['payroll_run_id']; ?>" target="_blank"
                                   class="p-1.5 text-slate-300 dark:text-slate-600 hover:text-hr transition-colors rounded-lg hover:bg-violet-50 dark:hover:bg-violet-900/10"
                                   title="View payroll run">
                                    <span class="material-icons-round text-base">open_in_new</span>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="7" class="text-center py-16 text-slate-400">
                            <span class="material-icons-round text-3xl block mb-2 opacity-30">receipt_long</span>
                            No expenses found. <button onclick="openForm()" class="text-fin font-semibold">Log your first expense →</button>
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
                    <div class="flex gap-2">
                        <?php $base="finance_expenses.php?search=".urlencode($search)."&type=$type_f&source=$source_f&date_from=$date_from&date_to=$date_to";
                        for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
                        <a href="<?php echo $base; ?>&page=<?php echo $p; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold transition-all <?php echo $p===$page?'bg-fin text-white':'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-slate-200'; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- This Month Sidebar (span 1) -->
        <div class="lg:col-span-1">
            <div class="glass-card rounded-2xl overflow-hidden sticky top-24">
                <div class="px-5 py-4 border-b border-white/30 dark:border-white/10">
                    <h3 class="font-display text-sm font-bold">This Month</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider"><?php echo date('F Y'); ?></p>
                </div>
                <div class="p-5 space-y-3">
                <?php if ($breakdown && $breakdown->num_rows > 0):
                    $month_total = 0;
                    $breakdown_rows = [];
                    while($b=$breakdown->fetch_assoc()){ $breakdown_rows[]=$b; $month_total+=$b['total']; }
                    foreach($breakdown_rows as $b):
                        $pct = $month_total>0 ? round($b['total']/$month_total*100) : 0;
                        $col = $b['expense_type']==='MOOE' ? 'bg-expense' : 'bg-hr';
                ?>
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <div class="flex items-center gap-1.5">
                            <span class="badge badge-<?php echo strtolower($b['expense_type']); ?>"><?php echo $b['expense_type']; ?></span>
                            <span class="text-[10px] font-medium text-slate-600 dark:text-slate-300 truncate max-w-[90px]"><?php echo htmlspecialchars($b['category']); ?></span>
                        </div>
                        <span class="text-[10px] font-bold">₱<?php echo number_format($b['total'],2); ?></span>
                    </div>
                    <div class="h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full <?php echo $col; ?> rounded-full" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                </div>
                <?php endforeach;
                    // Month total
                    echo '<div class="pt-3 mt-2 border-t border-slate-100 dark:border-slate-800"><div class="flex justify-between"><span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Month Total</span><span class="font-display font-bold text-sm">₱'.number_format($month_total,2).'</span></div></div>';
                else: ?>
                <p class="text-center text-sm text-slate-400 py-6">No expenses this month.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</main>

<script>
// ── Form panel ──────────────────────────────────────────────
function openForm(){document.getElementById('expense-panel').classList.add('open');document.getElementById('form-overlay').classList.add('open');}
function closeForm(){document.getElementById('expense-panel').classList.remove('open');document.getElementById('form-overlay').classList.remove('open');}

// ── Toast ───────────────────────────────────────────────────
function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className=`show ${type}`;setTimeout(()=>{t.className=type;},3500);}

// ── Submit manual expense ───────────────────────────────────
async function submitExpense(){
    const type  = document.querySelector('input[name="exp_type"]:checked')?.value;
    const cat   = document.getElementById('exp_category').value.trim();
    const desc  = document.getElementById('exp_desc').value.trim();
    const amt   = parseFloat(document.getElementById('exp_amount').value);
    const date  = document.getElementById('exp_date').value;

    if (!cat)          { showToast('Enter a category.','error'); return; }
    if (!amt || amt<=0){ showToast('Enter a valid amount.','error'); return; }
    if (!date)         { showToast('Pick a date.','error'); return; }

    const btn = document.getElementById('exp-submit-btn');
    btn.disabled=true; btn.innerHTML='<span class="material-icons-round text-base animate-spin">sync</span> Saving…';

    const fd=new FormData();
    fd.append('action','add_expense');fd.append('expense_type',type);fd.append('category',cat);
    fd.append('description',desc);fd.append('amount',amt);fd.append('expense_date',date);

    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();

    if(data.success){
        showToast('Expense logged!','success');
        closeForm();
        // Inject row into table without reload
        const tbody=document.getElementById('expense-tbody');
        const noRow=tbody.querySelector('[colspan]');
        if(noRow)noRow.parentElement.remove();

        const typeClass=type==='MOOE'?'badge-mooe':'badge-ps';
        const dateLabel=new Date().toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});
        const tr=document.createElement('tr');
        tr.dataset.id=data.id;
        tr.innerHTML=`
            <td class="whitespace-nowrap text-slate-500 text-[11px]">${dateLabel}</td>
            <td><span class="badge ${typeClass}">${type}</span></td>
            <td><span class="text-[10px] text-slate-400">Manual</span></td>
            <td class="max-w-[220px]"><p class="font-semibold text-[12px]">${escHtml(cat)}</p>${desc?`<p class="text-[10px] text-slate-400 truncate">${escHtml(desc)}</p>`:''}</td>
            <td class="text-right font-bold font-mono">₱${amt.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',')}</td>
            <td class="text-[11px] text-slate-400"><?php echo htmlspecialchars($fin_name); ?></td>
            <td class="text-right"><button onclick="deleteExpense(${data.id},this)" class="p-1.5 text-slate-300 dark:text-slate-600 hover:text-danger transition-colors rounded-lg hover:bg-red-50 dark:hover:bg-red-900/10"><span class="material-icons-round text-base">delete_outline</span></button></td>`;
        tbody.insertBefore(tr, tbody.firstChild);

        document.getElementById('exp_category').value='';
        document.getElementById('exp_desc').value='';
        document.getElementById('exp_amount').value='';
    } else {
        showToast(data.message||'Error saving.','error');
    }
    btn.disabled=false;btn.innerHTML='<span class="material-icons-round text-base">save</span>Log Expense';
}

// ── Delete manual expense ───────────────────────────────────
async function deleteExpense(id, btn){
    if(!confirm('Delete this expense entry?'))return;
    const fd=new FormData();fd.append('action','delete_expense');fd.append('expense_id',id);
    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
        const tr=btn.closest('tr');
        tr.style.opacity='0';tr.style.transform='translateX(20px)';tr.style.transition='all .3s ease';
        setTimeout(()=>tr.remove(),300);
        showToast('Expense removed.','success');
    } else {
        showToast(data.message||'Cannot delete.','error');
    }
}

// ── Approve payroll ─────────────────────────────────────────
async function approvePayroll(runId, title, amount){
    if(!confirm(`Approve "${title}" and log ₱${parseFloat(amount).toLocaleString('en-PH',{minimumFractionDigits:2})} as a PS expense?`))return;
    const fd=new FormData();fd.append('action','approve_payroll');fd.append('run_id',runId);
    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
        showToast('Payroll approved and logged to expenses!','success');
        setTimeout(()=>location.reload(),1200);
    } else {
        showToast(data.message||'Error approving.','error');
    }
}

// ── Reject payroll ──────────────────────────────────────────
function openReject(runId){
    document.getElementById('reject-run-id').value=runId;
    document.getElementById('reject-reason').value='';
    document.getElementById('reject-overlay').classList.remove('hidden');
}
async function confirmReject(){
    const runId=document.getElementById('reject-run-id').value;
    const reason=document.getElementById('reject-reason').value.trim();
    if(!reason){showToast('Please provide a reason.','error');return;}
    const fd=new FormData();fd.append('action','reject_payroll');fd.append('run_id',runId);fd.append('reason',reason);
    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
        document.getElementById('reject-overlay').classList.add('hidden');
        showToast('Payroll run returned to HR.','info');
        setTimeout(()=>location.reload(),1200);
    }
}

function escHtml(str){return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// CSS animation
document.head.insertAdjacentHTML('beforeend','<style>@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}</style>');
</script>
</body>
</html>