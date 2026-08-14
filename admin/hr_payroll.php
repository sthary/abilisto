<?php
// admin/hr_payroll.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}

$hr_user_id = $_SESSION['user_id'];
$hr_name    = $conn->query("SELECT full_name FROM users WHERE id=$hr_user_id")->fetch_assoc()['full_name'] ?? 'HR';

// ── Philippine statutory deduction helpers ──────────────────
function computeSSS(float $salary): array {
    // 2024 SSS table: EE=4.5%, ER=9.5%, total 14% — min ₱135/EE, max ₱900/EE
    $ee = min(900, max(135, round($salary * 0.045, 2)));
    $er = min(1900, max(285, round($salary * 0.095, 2)));
    return ['ee' => $ee, 'er' => $er];
}
function computePhilHealth(float $salary): array {
    // 2024: 5% of monthly salary, split 50/50 — min ₱500 total
    $total = max(500, round($salary * 0.05, 2));
    $half  = round($total / 2, 2);
    return ['ee' => $half, 'er' => $half];
}
function computePagIBIG(float $salary): array {
    $ee = $salary <= 1500 ? round($salary * 0.01, 2) : min(100, round($salary * 0.02, 2));
    $er = min(100, round($salary * 0.02, 2));
    return ['ee' => $ee, 'er' => $er];
}
function computeWithholdingTax(float $taxable): float {
    // TRAIN Law 2023 annual tax brackets, monthly equivalent
    $annual = $taxable * 12;
    if ($annual <= 250000)     return 0;
    if ($annual <= 400000)     return round((($annual - 250000) * 0.15) / 12, 2);
    if ($annual <= 800000)     return round((22500 + ($annual - 400000) * 0.20) / 12, 2);
    if ($annual <= 2000000)    return round((102500 + ($annual - 800000) * 0.25) / 12, 2);
    if ($annual <= 8000000)    return round((402500 + ($annual - 2000000) * 0.30) / 12, 2);
    return round((2202500 + ($annual - 8000000) * 0.35) / 12, 2);
}

// ── Handle POST actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create new payroll run ──────────────────────────────
    if ($action === 'create_run') {
        $title     = $conn->real_escape_string(trim($_POST['title']??''));
        $p_start   = $conn->real_escape_string($_POST['period_start']??'');
        $p_end     = $conn->real_escape_string($_POST['period_end']??'');
        $pay_date  = $conn->real_escape_string($_POST['pay_date']??'');
        $pay_freq  = $conn->real_escape_string($_POST['pay_frequency']??'Monthly');
        $notes     = $conn->real_escape_string(trim($_POST['notes']??''));

        // Get active employees matching frequency
        $emps = $conn->query("SELECT * FROM employees WHERE employment_status='Active' AND pay_frequency='$pay_freq'");
        if (!$emps || $emps->num_rows === 0) {
            header("Location: hr_payroll.php?error=no_employees"); exit();
        }

        $conn->query("INSERT INTO payroll_runs (title,period_start,period_end,pay_date,pay_frequency,status,notes,generated_by)
                      VALUES ('$title','$p_start','$p_end','$pay_date','$pay_freq','Draft','$notes',$hr_user_id)");
        $run_id = $conn->insert_id;

        $total_gross = $total_ded = $total_net = $emp_count = 0;

        // Calculate working days in period (Mon–Sat)
        $start_ts = strtotime($p_start);
        $end_ts   = strtotime($p_end);
        $working_days = 0;
        for ($d = $start_ts; $d <= $end_ts; $d += 86400) {
            $dow = (int)date('N', $d);
            if ($dow <= 6) $working_days++;
        }

        while ($emp = $emps->fetch_assoc()) {
            $basic = (float)$emp['basic_salary'];
            // Pro-rate if semi-monthly / weekly
            $period_basic = match($pay_freq) {
                'Semi-monthly' => $basic / 2,
                'Weekly'       => $basic / 4,
                default        => $basic,
            };

            $sss  = computeSSS($basic);
            $ph   = computePhilHealth($basic);
            $pib  = computePagIBIG($basic);

            // Taxable = basic − employee statutory deductions
            $taxable    = $period_basic - $sss['ee'] - $ph['ee'] - $pib['ee'];
            $withholding = computeWithholdingTax(max(0,$taxable));

            $total_deductions = $sss['ee'] + $ph['ee'] + $pib['ee'] + $withholding;
            $gross  = $period_basic;
            $net    = $gross - $total_deductions;

            $conn->query("INSERT INTO payroll_items
                (payroll_run_id,employee_id,basic_pay,gross_pay,
                 sss_ee,sss_er,philhealth_ee,philhealth_er,pagibig_ee,pagibig_er,
                 withholding_tax,total_deductions,net_pay,days_worked)
                VALUES
                ($run_id,{$emp['id']},$period_basic,$gross,
                 {$sss['ee']},{$sss['er']},{$ph['ee']},{$ph['er']},{$pib['ee']},{$pib['er']},
                 $withholding,$total_deductions,$net,$working_days)");

            $total_gross += $gross;
            $total_ded   += $total_deductions;
            $total_net   += $net;
            $emp_count++;
        }

        $conn->query("UPDATE payroll_runs SET
            total_gross=$total_gross, total_deductions=$total_ded,
            total_net=$total_net, employee_count=$emp_count
            WHERE id=$run_id");

        header("Location: hr_payroll.php?msg=created&run=$run_id"); exit();
    }

    // ── Update run status ───────────────────────────────────
    if ($action === 'update_status') {
        $run_id    = intval($_POST['run_id']??0);
        $new_status= $conn->real_escape_string($_POST['new_status']??'');
        $allowed   = ['Draft','For Approval','Approved','Released','Cancelled'];
        if (!in_array($new_status,$allowed)) { header("Location: hr_payroll.php"); exit(); }

        $conn->query("UPDATE payroll_runs SET status='$new_status' WHERE id=$run_id");

        // ── When Released → auto-log to admin_expenses as PS ──
        if ($new_status === 'Released') {
            $run = $conn->query("SELECT * FROM payroll_runs WHERE id=$run_id")->fetch_assoc();
            $desc = $conn->real_escape_string("Payroll: ".($run['title']??'')." — ".($run['employee_count']??0)." employees");
            $conn->query("INSERT INTO admin_expenses
                (expense_type,category,description,amount,expense_date,logged_by)
                VALUES ('PS','Staff Salary','$desc',{$run['total_net']},'{$run['pay_date']}',$hr_user_id)");
            $exp_id = $conn->insert_id;
            $conn->query("UPDATE payroll_runs SET expense_id=$exp_id, released_at=NOW(), approved_by=$hr_user_id, approved_at=NOW() WHERE id=$run_id");
        }

        header("Location: hr_payroll.php?msg=status_updated"); exit();
    }

    // ── Update individual payroll item ──────────────────────
    if ($action === 'update_item') {
        $item_id    = intval($_POST['item_id']??0);
        $allowances = floatval($_POST['allowances']??0);
        $bonuses    = floatval($_POST['bonuses']??0);
        $overtime   = floatval($_POST['overtime_pay']??0);
        $absent_ded = floatval($_POST['absent_deduction']??0);
        $late_ded   = floatval($_POST['late_deduction']??0);
        $other_ded  = floatval($_POST['other_deductions']??0);
        $days_absent= floatval($_POST['days_absent']??0);
        $item_notes = $conn->real_escape_string(trim($_POST['notes']??''));

        $item = $conn->query("SELECT * FROM payroll_items WHERE id=$item_id")->fetch_assoc();
        $gross = (float)$item['basic_pay'] + $allowances + $bonuses + $overtime;
        $total_ded = (float)$item['sss_ee'] + (float)$item['philhealth_ee'] + (float)$item['pagibig_ee'] + (float)$item['withholding_tax'] + $absent_ded + $late_ded + $other_ded;
        $net = $gross - $total_ded;

        $conn->query("UPDATE payroll_items SET
            allowances=$allowances, bonuses=$bonuses, overtime_pay=$overtime,
            absent_deduction=$absent_ded, late_deduction=$late_ded, other_deductions=$other_ded,
            gross_pay=$gross, total_deductions=$total_ded, net_pay=$net,
            days_absent=$days_absent, notes='$item_notes'
            WHERE id=$item_id");

        // Recalculate run totals
        $run_id = intval($item['payroll_run_id']);
        $totals = $conn->query("SELECT SUM(gross_pay) as tg, SUM(total_deductions) as td, SUM(net_pay) as tn FROM payroll_items WHERE payroll_run_id=$run_id")->fetch_assoc();
        $conn->query("UPDATE payroll_runs SET total_gross={$totals['tg']}, total_deductions={$totals['td']}, total_net={$totals['tn']} WHERE id=$run_id");

        echo json_encode(['success'=>true,'net'=>$net,'gross'=>$gross,'total_ded'=>$total_ded]);
        exit();
    }
}

// ── Fetch runs ──────────────────────────────────────────────
$runs_res = $conn->query("SELECT pr.*, u.full_name as gen_by_name
    FROM payroll_runs pr LEFT JOIN users u ON u.id=pr.generated_by
    ORDER BY pr.created_at DESC");
$runs = [];
while($r=$runs_res->fetch_assoc()) $runs[]=$r;

// ── Selected run detail ─────────────────────────────────────
$selected_run = null;
$run_items    = [];
if (isset($_GET['run'])) {
    $rid = intval($_GET['run']);
    $selected_run = $conn->query("SELECT pr.*, u.full_name as gen_by_name
        FROM payroll_runs pr LEFT JOIN users u ON u.id=pr.generated_by WHERE pr.id=$rid")->fetch_assoc();
    if ($selected_run) {
        $items_res = $conn->query("SELECT pi.*, e.full_name, e.employee_code, e.position, e.department, e.gcash_number
            FROM payroll_items pi JOIN employees e ON e.id=pi.employee_id
            WHERE pi.payroll_run_id=$rid ORDER BY e.full_name ASC");
        while($it=$items_res->fetch_assoc()) $run_items[]=$it;
    }
}

$current_date = date('M d, Y');
$show_new_form = isset($_GET['action']) && $_GET['action']==='new';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — Payroll</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
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
    .hr-input{background:rgba(255,255,255,0.8);border:1.5px solid rgba(0,0,0,0.1);border-radius:10px;padding:8px 12px;font-size:13px;width:100%;color:#1e293b;transition:border-color .2s;}
    .dark .hr-input{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;}
    .hr-input:focus{outline:none;border-color:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,.15);}
    .hr-label{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;display:block;margin-bottom:4px;}
    .dark .hr-label{color:#94a3b8;}
    .fin-table thead th{font-family:'Plus Jakarta Sans',sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;padding:10px 12px;border-bottom:1px solid rgba(0,0,0,0.06);white-space:nowrap;}
    .dark .fin-table thead th{border-color:rgba(255,255,255,0.06);}
    .fin-table tbody td{padding:10px 12px;font-size:12px;border-bottom:1px solid rgba(0,0,0,0.04);}
    .dark .fin-table tbody td{border-color:rgba(255,255,255,0.04);}
    .fin-table tfoot td{padding:10px 12px;font-size:12px;font-weight:700;border-top:2px solid rgba(0,0,0,0.08);}
    .dark .fin-table tfoot td{border-color:rgba(255,255,255,0.08);}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-draft{background:rgba(148,163,184,.12);color:#64748b;border:1px solid rgba(148,163,184,.2);}
    .badge-approval{background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.2);}
    .badge-approved{background:rgba(14,165,233,.12);color:#0EA5E9;border:1px solid rgba(14,165,233,.2);}
    .badge-released{background:rgba(16,185,129,.12);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .badge-cancelled{background:rgba(244,63,94,.12);color:#F43F5E;border:1px solid rgba(244,63,94,.2);}
    /* Finance badge */
    .finance-sync{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:99px;font-size:9px;font-weight:700;background:rgba(16,185,129,.1);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    .form-panel{position:fixed;top:0;right:0;width:100%;max-width:520px;height:100%;z-index:60;background:white;box-shadow:-8px 0 40px rgba(0,0,0,.12);transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;}
    .dark .form-panel{background:#111827;}
    .form-panel.open{transform:translateX(0);}
    .form-overlay{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:59;display:none;}
    .form-overlay.open{display:block;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}
    #toast.success{background:#10B981;color:white;}
    #toast.error{background:#F43F5E;color:white;}
    @media print{.no-print{display:none!important;}.glass-card{box-shadow:none;border:1px solid #e2e8f0;background:white;}body{background:white;}main{margin-left:0!important;}}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>

<div id="toast"></div>
<div class="form-overlay <?php echo $show_new_form?'open':''; ?>" id="form-overlay" onclick="closeForm()"></div>

<!-- New Payroll Run Form Panel -->
<div class="form-panel <?php echo $show_new_form?'open':''; ?>" id="payroll-form-panel">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
        <div>
            <h2 class="font-display text-base font-bold">New Payroll Run</h2>
            <p class="text-[10px] text-slate-400 uppercase tracking-wider">Configure & generate payroll</p>
        </div>
        <button onclick="closeForm()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400">
            <span class="material-icons-round">close</span>
        </button>
    </div>
    <form method="POST" class="flex-1 overflow-y-auto slim-scroll p-6 space-y-4">
        <input type="hidden" name="action" value="create_run">

        <div>
            <label class="hr-label">Payroll Title</label>
            <input type="text" name="title" class="hr-input" required
                   placeholder="e.g. March 2026 — Full Month"
                   value="<?php echo date('F Y'); ?> — Full Month">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="hr-label">Period Start</label>
                <input type="date" name="period_start" class="hr-input" required value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div>
                <label class="hr-label">Period End</label>
                <input type="date" name="period_end" class="hr-input" required value="<?php echo date('Y-m-t'); ?>">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="hr-label">Pay Date</label>
                <input type="date" name="pay_date" class="hr-input" required value="<?php echo date('Y-m-t'); ?>">
            </div>
            <div>
                <label class="hr-label">Pay Frequency</label>
                <select name="pay_frequency" class="hr-input">
                    <option value="Monthly">Monthly</option>
                    <option value="Semi-monthly">Semi-monthly</option>
                    <option value="Weekly">Weekly</option>
                </select>
            </div>
        </div>
        <div>
            <label class="hr-label">Notes <span class="normal-case text-[9px] opacity-60">(optional)</span></label>
            <textarea name="notes" class="hr-input resize-none" rows="2" placeholder="Any notes about this payroll cycle…"></textarea>
        </div>

        <!-- Info box -->
        <div class="bg-violet-50 dark:bg-violet-900/10 border border-violet-200 dark:border-violet-800 rounded-xl p-4">
            <p class="text-xs font-bold text-hr mb-1">What happens when you generate?</p>
            <ul class="text-[11px] text-slate-500 dark:text-slate-400 space-y-1 list-disc list-inside">
                <li>Includes all <strong>Active</strong> employees matching the pay frequency</li>
                <li>Auto-computes SSS, PhilHealth, Pag-IBIG, and withholding tax</li>
                <li>Creates a <strong>Draft</strong> run you can review and adjust</li>
                <li>When <strong>Released</strong>, automatically logs to Finance as a PS expense</li>
            </ul>
        </div>

        <div class="pb-4">
            <button type="submit" class="w-full py-3 bg-hr text-white font-bold text-sm rounded-xl hover:opacity-90 transition-all flex items-center justify-center gap-2">
                <span class="material-icons-round text-base">calculate</span>
                Generate Payroll
            </button>
        </div>
    </form>
</div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between no-print">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Payroll</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Generate · Review · Release to Finance</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <?php if($selected_run): ?>
            <button onclick="window.print()" class="hidden sm:flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80">
                <span class="material-icons-round text-sm">print</span> Print
            </button>
            <?php endif; ?>
            <button onclick="openForm()" class="flex items-center gap-2 px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all">
                <span class="material-icons-round text-sm">add_card</span> New Run
            </button>
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
        </div>
    </header>

    <?php
    $msgs=['created'=>['success','Payroll run generated successfully.'],'status_updated'=>['success','Payroll status updated.'],'no_employees'=>['error','No active employees found for this pay frequency.']];
    if(isset($_GET['msg'])&&isset($msgs[$_GET['msg']])){$m=$msgs[$_GET['msg']];echo "<script>document.addEventListener('DOMContentLoaded',()=>showToast('{$m[1]}','{$m[0]}'));</script>";}
    if(isset($_GET['error'])&&isset($msgs[$_GET['error']])){$m=$msgs[$_GET['error']];echo "<script>document.addEventListener('DOMContentLoaded',()=>showToast('{$m[1]}','{$m[0]}'));</script>";}
    ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Payroll Runs List -->
        <div class="lg:col-span-1 no-print">
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-white/30 dark:border-white/10">
                    <h3 class="font-display text-sm font-bold">Payroll Runs</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider"><?php echo count($runs); ?> total</p>
                </div>
                <div class="divide-y divide-slate-50 dark:divide-slate-800/50 max-h-[calc(100vh-250px)] overflow-y-auto slim-scroll">
                <?php if(count($runs)>0): foreach($runs as $r):
                    $active = isset($_GET['run']) && (int)$_GET['run']===$r['id'];
                    $sbadge=['Draft'=>'badge-draft','For Approval'=>'badge-approval','Approved'=>'badge-approved','Released'=>'badge-released','Cancelled'=>'badge-cancelled'][$r['status']]??'badge-draft';
                ?>
                <a href="hr_payroll.php?run=<?php echo $r['id']; ?>"
                   class="block px-5 py-4 hover:bg-violet-50/30 dark:hover:bg-violet-900/10 transition-all <?php echo $active?'bg-violet-50 dark:bg-violet-900/20 border-l-2 border-hr':''; ?>">
                    <div class="flex justify-between items-start mb-1">
                        <p class="text-xs font-bold truncate max-w-[160px]"><?php echo htmlspecialchars($r['title']); ?></p>
                        <span class="badge <?php echo $sbadge; ?> flex-shrink-0"><?php echo $r['status']; ?></span>
                    </div>
                    <p class="text-[10px] text-slate-400"><?php echo date('M d',strtotime($r['period_start'])); ?> – <?php echo date('M d, Y',strtotime($r['period_end'])); ?></p>
                    <div class="flex justify-between mt-1.5">
                        <p class="text-[10px] text-slate-400"><?php echo $r['employee_count']; ?> employees</p>
                        <p class="text-[11px] font-bold text-equity">₱<?php echo number_format($r['total_net'],2); ?></p>
                    </div>
                    <?php if($r['expense_id']): ?>
                    <span class="finance-sync mt-1.5 block w-fit"><span class="material-icons-round" style="font-size:9px">sync</span>Synced to Finance</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; else: ?>
                <div class="px-5 py-10 text-center text-slate-400 text-sm">No payroll runs yet.<br><button onclick="openForm()" class="text-hr font-semibold">Generate first run →</button></div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Run Detail Panel -->
        <div class="lg:col-span-2">
        <?php if($selected_run): ?>
            <div class="glass-card rounded-2xl mb-4 p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="font-display text-base font-bold"><?php echo htmlspecialchars($selected_run['title']); ?></h2>
                        <p class="text-[10px] text-slate-400 mt-0.5">
                            Pay date: <?php echo date('M d, Y',strtotime($selected_run['pay_date'])); ?> ·
                            <?php echo $selected_run['pay_frequency']; ?> ·
                            <?php echo $selected_run['employee_count']; ?> employees
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <?php if($selected_run['expense_id']): ?>
                        <span class="finance-sync"><span class="material-icons-round" style="font-size:10px">sync</span>Finance Logged</span>
                        <?php endif; ?>
                        <!-- Status progression -->
                        <?php
                        $transitions = [
                            'Draft'        => ['next'=>'For Approval','label'=>'Submit for Approval','color'=>'bg-amber-500'],
                            'For Approval' => ['next'=>'Approved',    'label'=>'Approve Payroll',   'color'=>'bg-sky-500'],
                            'Approved'     => ['next'=>'Released',    'label'=>'Release & Send to Finance','color'=>'bg-equity'],
                        ];
                        $t = $transitions[$selected_run['status']] ?? null;
                        if ($t && $selected_run['status']!=='Released' && $selected_run['status']!=='Cancelled'):
                        ?>
                        <form method="POST" onsubmit="return confirm('<?php echo addslashes($t['label']); ?>?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="run_id" value="<?php echo $selected_run['id']; ?>">
                            <input type="hidden" name="new_status" value="<?php echo $t['next']; ?>">
                            <button type="submit" class="<?php echo $t['color']; ?> text-white text-xs font-bold px-4 py-2 rounded-xl hover:opacity-90 transition-all flex items-center gap-2">
                                <span class="material-icons-round text-sm">arrow_forward</span>
                                <?php echo $t['label']; ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if($selected_run['status']==='Draft'): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this payroll run?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="run_id" value="<?php echo $selected_run['id']; ?>">
                            <input type="hidden" name="new_status" value="Cancelled">
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-danger px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 transition-all">Cancel Run</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Run summary strip -->
                <div class="grid grid-cols-3 gap-4 mt-4 pt-4 border-t border-white/30 dark:border-white/10">
                    <div><p class="text-[10px] text-slate-400">Total Gross</p><p class="font-display font-bold text-base">₱<?php echo number_format($selected_run['total_gross'],2); ?></p></div>
                    <div><p class="text-[10px] text-slate-400">Total Deductions</p><p class="font-display font-bold text-base text-danger">₱<?php echo number_format($selected_run['total_deductions'],2); ?></p></div>
                    <div><p class="text-[10px] text-slate-400">Net Pay</p><p class="font-display font-bold text-base text-equity">₱<?php echo number_format($selected_run['total_net'],2); ?></p></div>
                </div>
            </div>

            <!-- Payslip Table -->
            <div class="glass-card rounded-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-white/30 dark:border-white/10">
                    <h3 class="font-display text-sm font-bold">Employee Payslips</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">
                        <?php echo $selected_run['status']==='Draft'?'Click any row to adjust allowances/deductions':'Read-only — payroll is locked'; ?>
                    </p>
                </div>
                <div class="overflow-x-auto slim-scroll">
                    <table class="fin-table w-full">
                        <thead>
                            <tr>
                                <th class="text-left">Employee</th>
                                <th class="text-right">Basic Pay</th>
                                <th class="text-right">Allowances</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">SSS</th>
                                <th class="text-right">PhilHealth</th>
                                <th class="text-right">Pag-IBIG</th>
                                <th class="text-right">Tax</th>
                                <th class="text-right">Other Ded.</th>
                                <th class="text-right font-bold text-equity">Net Pay</th>
                                <?php if($selected_run['status']==='Draft'): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($run_items as $it): ?>
                        <tr id="row-<?php echo $it['id']; ?>" class="<?php echo $selected_run['status']==='Draft'?'cursor-pointer hover:bg-violet-50/40 dark:hover:bg-violet-900/10':''; ?>">
                            <td>
                                <p class="font-semibold text-[12px]"><?php echo htmlspecialchars($it['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo $it['employee_code']; ?> · <?php echo $it['department']; ?></p>
                            </td>
                            <td class="text-right font-mono">₱<?php echo number_format($it['basic_pay'],2); ?></td>
                            <td class="text-right font-mono text-[11px]" id="all-<?php echo $it['id']; ?>">
                                <?php echo ($it['allowances']+$it['bonuses']+$it['overtime_pay'])>0?'₱'.number_format($it['allowances']+$it['bonuses']+$it['overtime_pay'],2):'—'; ?>
                            </td>
                            <td class="text-right font-mono font-bold" id="gr-<?php echo $it['id']; ?>">₱<?php echo number_format($it['gross_pay'],2); ?></td>
                            <td class="text-right font-mono text-[11px] text-danger">₱<?php echo number_format($it['sss_ee'],2); ?></td>
                            <td class="text-right font-mono text-[11px] text-danger">₱<?php echo number_format($it['philhealth_ee'],2); ?></td>
                            <td class="text-right font-mono text-[11px] text-danger">₱<?php echo number_format($it['pagibig_ee'],2); ?></td>
                            <td class="text-right font-mono text-[11px] text-danger">₱<?php echo number_format($it['withholding_tax'],2); ?></td>
                            <td class="text-right font-mono text-[11px] text-danger" id="od-<?php echo $it['id']; ?>">
                                <?php echo ($it['absent_deduction']+$it['late_deduction']+$it['other_deductions'])>0?'₱'.number_format($it['absent_deduction']+$it['late_deduction']+$it['other_deductions'],2):'—'; ?>
                            </td>
                            <td class="text-right font-mono font-bold text-equity" id="net-<?php echo $it['id']; ?>">₱<?php echo number_format($it['net_pay'],2); ?></td>
                            <?php if($selected_run['status']==='Draft'): ?>
                            <td>
                                <button onclick="openAdjust(<?php echo htmlspecialchars(json_encode($it)); ?>)"
                                        class="p-1.5 text-slate-300 hover:text-hr transition-colors rounded-lg hover:bg-violet-50 dark:hover:bg-violet-900/20">
                                    <span class="material-icons-round text-base">tune</span>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="font-display uppercase tracking-wider text-slate-500">TOTAL</td>
                                <td class="text-right font-mono">₱<?php echo number_format(array_sum(array_column($run_items,'basic_pay')),2); ?></td>
                                <td></td>
                                <td class="text-right font-mono">₱<?php echo number_format($selected_run['total_gross'],2); ?></td>
                                <td class="text-right font-mono text-danger">₱<?php echo number_format(array_sum(array_column($run_items,'sss_ee')),2); ?></td>
                                <td class="text-right font-mono text-danger">₱<?php echo number_format(array_sum(array_column($run_items,'philhealth_ee')),2); ?></td>
                                <td class="text-right font-mono text-danger">₱<?php echo number_format(array_sum(array_column($run_items,'pagibig_ee')),2); ?></td>
                                <td class="text-right font-mono text-danger">₱<?php echo number_format(array_sum(array_column($run_items,'withholding_tax')),2); ?></td>
                                <td></td>
                                <td class="text-right font-mono text-equity">₱<?php echo number_format($selected_run['total_net'],2); ?></td>
                                <?php if($selected_run['status']==='Draft'): ?><td></td><?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <div class="glass-card rounded-2xl flex flex-col items-center justify-center py-24 text-center">
                <span class="material-icons-round text-4xl text-slate-300 dark:text-slate-600 mb-4">payments</span>
                <p class="font-display font-bold text-slate-500">Select a payroll run to view details</p>
                <p class="text-xs text-slate-400 mt-1">or <button onclick="openForm()" class="text-hr font-semibold">generate a new one</button></p>
            </div>
        <?php endif; ?>
        </div>
    </div>

</main>

<!-- Adjustment Modal -->
<div id="adjust-overlay" class="hidden fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
        <div class="flex justify-between items-center px-6 py-4 border-b border-slate-100 dark:border-slate-800">
            <div>
                <h3 class="font-display font-bold text-sm" id="adj-name">Adjust Payslip</h3>
                <p class="text-[10px] text-slate-400 uppercase tracking-wider">Override earnings/deductions</p>
            </div>
            <button onclick="closeAdjust()" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg"><span class="material-icons-round">close</span></button>
        </div>
        <form id="adj-form" class="p-6 space-y-4">
            <input type="hidden" id="adj-item-id">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="hr-label">Allowances</label><input type="number" id="adj-allowances" class="hr-input" step="0.01" min="0" value="0"></div>
                <div><label class="hr-label">Bonuses</label><input type="number" id="adj-bonuses" class="hr-input" step="0.01" min="0" value="0"></div>
                <div><label class="hr-label">Overtime Pay</label><input type="number" id="adj-overtime" class="hr-input" step="0.01" min="0" value="0"></div>
                <div><label class="hr-label">Days Absent</label><input type="number" id="adj-absent-days" class="hr-input" step="0.5" min="0" value="0"></div>
                <div><label class="hr-label">Absent Deduction</label><input type="number" id="adj-absent" class="hr-input" step="0.01" min="0" value="0"></div>
                <div><label class="hr-label">Late Deduction</label><input type="number" id="adj-late" class="hr-input" step="0.01" min="0" value="0"></div>
                <div class="col-span-2"><label class="hr-label">Other Deductions</label><input type="number" id="adj-other" class="hr-input" step="0.01" min="0" value="0"></div>
                <div class="col-span-2"><label class="hr-label">Notes</label><input type="text" id="adj-notes" class="hr-input" placeholder="Reason for adjustment…"></div>
            </div>
            <button type="button" onclick="saveAdjust()" class="w-full py-2.5 bg-hr text-white font-bold text-sm rounded-xl hover:opacity-90 transition-all flex items-center justify-center gap-2">
                <span class="material-icons-round text-base">save</span>Save Adjustment
            </button>
        </form>
    </div>
</div>

<script>
function openForm(){document.getElementById('payroll-form-panel').classList.add('open');document.getElementById('form-overlay').classList.add('open');}
function closeForm(){document.getElementById('payroll-form-panel').classList.remove('open');document.getElementById('form-overlay').classList.remove('open');}
<?php if($show_new_form): ?>openForm();<?php endif; ?>

function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className=`show ${type}`;setTimeout(()=>{t.className=type;},3500);}

function openAdjust(item){
    document.getElementById('adj-name').textContent = item.full_name + ' — Adjustment';
    document.getElementById('adj-item-id').value    = item.id;
    document.getElementById('adj-allowances').value = item.allowances || 0;
    document.getElementById('adj-bonuses').value    = item.bonuses || 0;
    document.getElementById('adj-overtime').value   = item.overtime_pay || 0;
    document.getElementById('adj-absent-days').value= item.days_absent || 0;
    document.getElementById('adj-absent').value     = item.absent_deduction || 0;
    document.getElementById('adj-late').value       = item.late_deduction || 0;
    document.getElementById('adj-other').value      = item.other_deductions || 0;
    document.getElementById('adj-notes').value      = item.notes || '';
    document.getElementById('adjust-overlay').classList.remove('hidden');
}
function closeAdjust(){document.getElementById('adjust-overlay').classList.add('hidden');}

async function saveAdjust(){
    const fd = new FormData();
    fd.append('action','update_item');
    fd.append('item_id',         document.getElementById('adj-item-id').value);
    fd.append('allowances',      document.getElementById('adj-allowances').value);
    fd.append('bonuses',         document.getElementById('adj-bonuses').value);
    fd.append('overtime_pay',    document.getElementById('adj-overtime').value);
    fd.append('days_absent',     document.getElementById('adj-absent-days').value);
    fd.append('absent_deduction',document.getElementById('adj-absent').value);
    fd.append('late_deduction',  document.getElementById('adj-late').value);
    fd.append('other_deductions',document.getElementById('adj-other').value);
    fd.append('notes',           document.getElementById('adj-notes').value);

    const res  = await fetch(window.location.href, {method:'POST', body:fd});
    const data = await res.json();

    if(data.success){
        const id = document.getElementById('adj-item-id').value;
        const all = parseFloat(document.getElementById('adj-allowances').value)+parseFloat(document.getElementById('adj-bonuses').value)+parseFloat(document.getElementById('adj-overtime').value);
        const od  = parseFloat(document.getElementById('adj-absent').value)+parseFloat(document.getElementById('adj-late').value)+parseFloat(document.getElementById('adj-other').value);
        document.getElementById(`net-${id}`).textContent = '₱'+parseFloat(data.net).toLocaleString('en-PH',{minimumFractionDigits:2});
        document.getElementById(`gr-${id}`).textContent  = '₱'+parseFloat(data.gross).toLocaleString('en-PH',{minimumFractionDigits:2});
        document.getElementById(`all-${id}`).textContent = all>0?'₱'+all.toLocaleString('en-PH',{minimumFractionDigits:2}):'—';
        document.getElementById(`od-${id}`).textContent  = od>0?'₱'+od.toLocaleString('en-PH',{minimumFractionDigits:2}):'—';
        closeAdjust();
        showToast('Adjustment saved.','success');
    }
}
</script>
</body>
</html>