<?php
// admin/finance.php
include '../db_connect.php';
include '../includes/init_lang.php';

// ── Security Check ─────────────────────────────────────────
// Strictly finance role only. Admins are redirected — use
// your main dashboard.php for super-admin access.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = $_SESSION['user_id']; // finance user id

// ============================================================
// HANDLE EXPENSE FORM SUBMISSION (AJAX)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'add_expense') {
        $type    = $_POST['expense_type'] ?? '';
        $cat     = $_POST['category'] ?? '';
        $desc    = $_POST['description'] ?? '';
        $amount  = floatval($_POST['amount'] ?? 0);
        $date    = $_POST['expense_date'] ?? date('Y-m-d');

        if (!in_array($type, ['MOOE', 'PS']) || $amount <= 0 || empty($cat)) {
            echo json_encode(['success' => false, 'message' => 'Invalid input.']);
            exit();
        }

        $sql = "INSERT INTO admin_expenses (expense_type, category, description, amount, expense_date, logged_by)
                VALUES (?, ?, ?, ?, ?, ?)";
        try {
            $conn->prepare($sql)->execute([$type, $cat, $desc, $amount, $date, $admin_id]);
            echo json_encode(['success' => true, 'message' => 'Expense logged successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_POST['action'] === 'delete_expense') {
        $eid = intval($_POST['expense_id'] ?? 0);
        $conn->prepare("DELETE FROM admin_expenses WHERE id = ?")->execute([$eid]);
        echo json_encode(['success' => true]);
        exit();
    }
}

// ============================================================
// INCOME CALCULATIONS
// ============================================================

// 1. Platform booking fees (₱30 per booking from worker wallets)
$booking_fees_sql = "SELECT SUM(amount) as total FROM wallet_transactions
                     WHERE transaction_type = 'fee'
                     AND user_id = 1
                     AND user_type = 'admin'
                     AND description LIKE '%Admin fee from booking%'";
$booking_fees = (float)($conn->query($booking_fees_sql)->fetch()['total'] ?? 0);

// 2. 4% commission from completed bookings
$commission_sql = "SELECT SUM(amount) as total FROM wallet_transactions
                   WHERE transaction_type = 'fee'
                   AND user_id = 1
                   AND user_type = 'admin'
                   AND description LIKE '%commission%'";
$commission = (float)($conn->query($commission_sql)->fetch()['total'] ?? 0);

// 3. Top-up fees (₱30 processing fee per completed top-up — adjust if your rate differs)
// Currently top-ups go directly to worker wallets; platform fee = 0 unless you charge one.
// Using completed top-up count × ₱0 as placeholder; set your fee rate below.
$TOPUP_FEE_RATE = 0; // Set to e.g. 30 if you charge ₱30 per top-up
$topup_sql = "SELECT COUNT(*) as cnt, SUM(amount) as volume FROM top_ups WHERE status = 'completed'";
$topup_row = $conn->query($topup_sql)->fetch();
$topup_count  = (int)($topup_row['cnt'] ?? 0);
$topup_volume = (float)($topup_row['volume'] ?? 0);
$topup_fees   = $topup_count * $TOPUP_FEE_RATE;

$total_income = $booking_fees + $commission + $topup_fees;

// ============================================================
// LIABILITIES — Money owed to workers (their wallet balances)
// ============================================================
$liabilities_sql = "SELECT SUM(wallet_balance) as total FROM worker_profiles WHERE wallet_balance > 0";
$total_liabilities = (float)($conn->query($liabilities_sql)->fetch()['total'] ?? 0);

// Worker count in debt
$worker_debt_sql = "SELECT COUNT(*) as cnt FROM worker_profiles WHERE wallet_balance > 0";
$workers_owed = (int)($conn->query($worker_debt_sql)->fetch()['cnt'] ?? 0);

// ============================================================
// EXPENSES — includes manual entries AND released payroll PS
// Both sources write to admin_expenses, so one query covers all.
// payroll_runs.expense_id links a run to its admin_expenses row.
// ============================================================
$mooe_sql = "SELECT COALESCE(SUM(amount),0) as total FROM admin_expenses WHERE expense_type = 'MOOE'";
$ps_sql   = "SELECT COALESCE(SUM(amount),0) as total FROM admin_expenses WHERE expense_type = 'PS'";
$total_mooe = (float)($conn->query($mooe_sql)->fetch()['total'] ?? 0);
$total_ps   = (float)($conn->query($ps_sql)->fetch()['total'] ?? 0);
$total_expenses = $total_mooe + $total_ps;

// Payroll sub-total (PS entries that came from released payroll runs)
$payroll_ps_sql = "SELECT COALESCE(SUM(ae.amount),0) as total
    FROM admin_expenses ae
    INNER JOIN payroll_runs pr ON pr.expense_id = ae.id
    WHERE ae.expense_type = 'PS' AND pr.status = 'Released'";
$total_payroll_ps = (float)($conn->query($payroll_ps_sql)->fetch()['total'] ?? 0);

// Pending payroll count (approved by HR, awaiting Finance sign-off)
$pending_payroll_count = (int)($conn->query("SELECT COUNT(*) as c FROM payroll_runs WHERE status='Approved' AND expense_id IS NULL")->fetch()['c'] ?? 0);

// ============================================================
// EQUITY FORMULA: Income - Liabilities - Expenses
// ============================================================
$equity = $total_income - $total_liabilities - $total_expenses;

// Admin wallet current balance
$admin_balance_sql = "SELECT balance FROM admin_wallet WHERE id = 1";
$admin_balance = (float)($conn->query($admin_balance_sql)->fetch()['balance'] ?? 0);

// ============================================================
// SIMULATED PAYMONGO BALANCE (calculated from DB)
// We track what physically passed through PayMongo/GCash/Maya
// by checking payment_method fields — Cash is excluded entirely
// since it never touches PayMongo.
// ============================================================

// IN 1: Mobilization fees paid via PayMongo (calculated_fee column)
// payment_method = 'PayMongo' AND payment_status = 'Paid'
$xendit_in_mobilization_sql = "SELECT COALESCE(SUM(calculated_fee), 0) as total
    FROM bookings
    WHERE payment_method = 'PayMongo'
    AND payment_status = 'Paid'";
$xendit_in_mobilization = (float)($conn->query($xendit_in_mobilization_sql)->fetch()['total'] ?? 0);

// IN 2: Final payments received via PayMongo/GCash/Maya
// final_payment_method IN ('PayMongo','GCash','Maya') AND final_payment_status = 'paid'
$xendit_in_final_sql = "SELECT COALESCE(SUM(total_final_cost), 0) as total
    FROM bookings
    WHERE final_payment_status = 'paid'
    AND final_payment_method IN ('PayMongo', 'GCash', 'Maya', 'gcash', 'maya')";
$xendit_in_final = (float)($conn->query($xendit_in_final_sql)->fetch()['total'] ?? 0);

// IN 3: Worker top-ups completed via GCash/bank (workers funded their wallets)
$xendit_in_topups_sql = "SELECT COALESCE(SUM(amount), 0) as total
    FROM top_ups
    WHERE status = 'completed'";
$xendit_in_topups = (float)($conn->query($xendit_in_topups_sql)->fetch()['total'] ?? 0);

// OUT: Completed worker withdrawals (money sent OUT of PayMongo to workers)
$xendit_out_withdrawals_sql = "SELECT COALESCE(SUM(amount), 0) as total
    FROM withdrawals
    WHERE status IN ('Completed', 'completed', 'approved', 'Approved')";
$xendit_out_withdrawals = (float)($conn->query($xendit_out_withdrawals_sql)->fetch()['total'] ?? 0);

// Final simulated balance
$xendit_total_in  = $xendit_in_mobilization + $xendit_in_final + $xendit_in_topups;
$xendit_total_out = $xendit_out_withdrawals;
$xendit_simulated_balance = $xendit_total_in - $xendit_total_out;

// Solvency ratio: can we cover worker debt with our simulated PayMongo balance?
$solvency_ratio = $total_liabilities > 0 ? round(($xendit_simulated_balance / $total_liabilities) * 100, 1) : 100;
$is_solvent = $xendit_simulated_balance >= $total_liabilities;

// ============================================================
// REVENUE CHART — Monthly income vs expenses (last 6 months)
// ============================================================
$chart_income_sql = "SELECT
    TO_CHAR(created_at, 'Mon YYYY') as month_label,
    TO_CHAR(created_at, 'YYYY-MM') as month_key,
    SUM(amount) as total
FROM wallet_transactions
WHERE transaction_type = 'fee'
  AND user_id = 1
  AND user_type = 'admin'
  AND created_at >= NOW() - INTERVAL '6 months'
GROUP BY TO_CHAR(created_at, 'YYYY-MM'), TO_CHAR(created_at, 'Mon YYYY')
ORDER BY TO_CHAR(created_at, 'YYYY-MM') ASC";

$chart_income_res = $conn->query($chart_income_sql);
$chart_income_map = [];
while ($r = $chart_income_res->fetch()) {
    $chart_income_map[$r['month_key']] = ['label' => $r['month_label'], 'income' => (float)$r['total']];
}

$chart_expenses_sql = "SELECT
    TO_CHAR(expense_date, 'Mon YYYY') as month_label,
    TO_CHAR(expense_date, 'YYYY-MM') as month_key,
    SUM(amount) as total
FROM admin_expenses
WHERE expense_date >= NOW() - INTERVAL '6 months'
GROUP BY TO_CHAR(expense_date, 'YYYY-MM'), TO_CHAR(expense_date, 'Mon YYYY')
ORDER BY TO_CHAR(expense_date, 'YYYY-MM') ASC";

$chart_expenses_res = $conn->query($chart_expenses_sql);
$chart_expenses_map = [];
while ($r = $chart_expenses_res->fetch()) {
    $chart_expenses_map[$r['month_key']] = (float)$r['total'];
}

// Merge both maps into a unified 6-month array
$all_keys = [];
for ($i = 5; $i >= 0; $i--) {
    $all_keys[] = date('Y-m', strtotime("-$i months"));
}

$chart_labels   = [];
$chart_incomes  = [];
$chart_exps     = [];
$chart_equity   = [];

foreach ($all_keys as $key) {
    $chart_labels[]  = isset($chart_income_map[$key]) ? $chart_income_map[$key]['label'] : date('M Y', strtotime($key . '-01'));
    $inc = $chart_income_map[$key]['income'] ?? 0;
    $exp = $chart_expenses_map[$key] ?? 0;
    $chart_incomes[] = $inc;
    $chart_exps[]    = $exp;
    $chart_equity[]  = round($inc - $exp, 2);
}

// ============================================================
// RECENT EXPENSES LIST
// ============================================================
$recent_expenses_sql = "SELECT e.*, u.full_name as logged_by_name, pr.id as payroll_run_id, pr.title as payroll_title, pr.employee_count
                        FROM admin_expenses e LEFT JOIN payroll_runs pr ON pr.expense_id = e.id
                        LEFT JOIN users u ON u.id = e.logged_by
                        ORDER BY e.expense_date DESC, e.created_at DESC
                        LIMIT 20";
$recent_expenses = $conn->query($recent_expenses_sql)->fetchAll();

// ============================================================
// EXPENSE BREAKDOWN BY CATEGORY (for current month)
// ============================================================
$breakdown_sql = "SELECT expense_type, category, SUM(amount) as total
                  FROM admin_expenses
                  WHERE EXTRACT(MONTH FROM expense_date) = EXTRACT(MONTH FROM NOW()) AND EXTRACT(YEAR FROM expense_date) = EXTRACT(YEAR FROM NOW())
                  GROUP BY expense_type, category
                  ORDER BY total DESC";
$breakdown_res = $conn->query($breakdown_sql);
$breakdown = [];
while ($r = $breakdown_res->fetch()) {
    $breakdown[] = $r;
}

// Finance user navbar info
$fin_user_stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE id = ?");
$fin_user_stmt->execute([$admin_id]);
$fin_user     = $fin_user_stmt->fetch();
$fin_name     = $fin_user['full_name'] ?? 'Finance';

$notif_count_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_count_stmt = $conn->prepare($notif_count_sql);
$notif_count_stmt->execute([$admin_id]);
$notif_count = (int)($notif_count_stmt->fetch()['count'] ?? 0);
$current_date = date('M d, Y');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto — Finance Department</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>

    <!-- Fonts: Syne (display) + DM Sans (body) -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary:   "#146af5",
                        fin:       "#0EA5E9",   // finance accent — sky blue
                        equity:    "#10B981",   // equity green
                        danger:    "#F43F5E",   // liability red
                        expense:   "#F59E0B",   // expense amber
                        "bg-light": "#F0F4FF",
                        "bg-dark":  "#0B0F1A",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        body:    ["Plus Jakarta Sans", "sans-serif"],
                    },
                },
            },
        };

        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }

        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
            rebuildCharts();
        }

        function toggleMobileMenu() {
            document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');
            document.getElementById('menu-overlay').classList.toggle('hidden');
        }
    </script>

    <style>
        /* ── Base ──────────────────────────────────────── */
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #F0F4FF; }
        .dark body, .dark { background: #0B0F1A; }

        h1, h2, h3, .font-display { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* ── Glassmorphism card ────────────────────────── */
        .glass-card {
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255,255,255,0.6);
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
        }
        .dark .glass-card {
            background: rgba(17,24,39,0.72);
            border: 1px solid rgba(255,255,255,0.07);
            box-shadow: 0 4px 32px rgba(0,0,0,0.4);
        }

        /* ── KPI card accent bar ───────────────────────── */
        .kpi-card { position: relative; overflow: hidden; }
        .kpi-card::before {
            content: '';
            position: absolute; top: 0; left: 0;
            width: 4px; height: 100%;
            border-radius: 4px 0 0 4px;
        }
        .kpi-income::before  { background: #0EA5E9; }
        .kpi-equity::before  { background: #10B981; }
        .kpi-liability::before { background: #F43F5E; }
        .kpi-expense::before { background: #F59E0B; }

        /* ── Animated number counter ───────────────────── */
        .count-up { display: inline-block; }

        /* ── Badge pills ───────────────────────────────── */
        .badge-mooe {
            background: rgba(245,158,11,0.12);
            color: #D97706;
            border: 1px solid rgba(245,158,11,0.3);
        }
        .badge-ps {
            background: rgba(99,102,241,0.12);
            color: #6366F1;
            border: 1px solid rgba(99,102,241,0.3);
        }
        .dark .badge-mooe { color: #FCD34D; }
        .dark .badge-ps   { color: #A5B4FC; }

        /* ── Solvency bar ──────────────────────────────── */
        .solvency-track {
            height: 8px;
            background: rgba(0,0,0,0.08);
            border-radius: 99px;
            overflow: hidden;
        }
        .dark .solvency-track { background: rgba(255,255,255,0.08); }
        .solvency-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 1.2s cubic-bezier(.4,0,.2,1);
        }

        /* ── Table ─────────────────────────────────────── */
        .fin-table thead th {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94a3b8;
            padding: 10px 14px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .dark .fin-table thead th { border-color: rgba(255,255,255,0.06); }
        .fin-table tbody td {
            padding: 12px 14px;
            font-size: 13px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .dark .fin-table tbody td { border-color: rgba(255,255,255,0.04); }
        .fin-table tbody tr:hover { background: rgba(14,165,233,0.04); }

        /* ── Form inputs ───────────────────────────────── */
        .fin-input {
            background: rgba(255,255,255,0.6);
            border: 1.5px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 13px;
            width: 100%;
            transition: border-color .2s, box-shadow .2s;
            color: #1e293b;
        }
        .dark .fin-input {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.1);
            color: #e2e8f0;
        }
        .fin-input:focus {
            outline: none;
            border-color: #0EA5E9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.15);
        }
        .fin-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            display: block;
            margin-bottom: 4px;
        }
        .dark .fin-label { color: #94a3b8; }

        /* ── Entrance animation ────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .anim-in { animation: fadeUp .45s ease both; }
        .anim-in:nth-child(1) { animation-delay: .05s; }
        .anim-in:nth-child(2) { animation-delay: .10s; }
        .anim-in:nth-child(3) { animation-delay: .15s; }
        .anim-in:nth-child(4) { animation-delay: .20s; }

        /* ── Toast ─────────────────────────────────────── */
        #toast {
            position: fixed;
            bottom: 24px; right: 24px;
            z-index: 9999;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            transform: translateY(80px);
            opacity: 0;
            transition: all .3s ease;
            pointer-events: none;
        }
        #toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        #toast.success { background: #10B981; color: white; }
        #toast.error   { background: #F43F5E; color: white; }

        /* ── PayMongo placeholder badge ──────────────────── */
        .xendit-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            background: rgba(245,158,11,0.12);
            color: #D97706;
            border: 1px dashed rgba(245,158,11,0.4);
        }

        /* scrollbar */
        .slim-scroll::-webkit-scrollbar { width: 4px; }
        .slim-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        .dark .slim-scroll::-webkit-scrollbar-thumb { background: #334155; }
    </style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">

<?php include 'finance_navbar.php'; ?>

<!-- Toast -->
<div id="toast"></div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- ── Top Bar ──────────────────────────────────────────── -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500"
                    onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Finance Department</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Platform Equity & Cost Center</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()"
                    class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <button class="relative p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">notifications</span>
                <?php if ($notif_count > 0): ?>
                <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-slate-900"></span>
                <?php endif; ?>
            </button>
            <div class="hidden sm:block text-right ml-2">
                <p class="text-[10px] font-bold"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-400 uppercase tracking-tighter">Fiscal Overview</p>
            </div>
        </div>
    </header>

    <!-- ── Equity Hero Banner ────────────────────────────────── -->
    <div class="glass-card rounded-2xl p-6 md:p-8 mb-8 relative overflow-hidden">
        <!-- Background grid decoration -->
        <div class="absolute inset-0 opacity-[0.03] dark:opacity-[0.06]" style="background-image: repeating-linear-gradient(0deg, #0EA5E9 0, #0EA5E9 1px, transparent 0, transparent 50%), repeating-linear-gradient(90deg, #0EA5E9 0, #0EA5E9 1px, transparent 0, transparent 50%); background-size: 40px 40px;"></div>

        <div class="relative flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 mb-1">Platform Net Equity</p>
                <div class="flex items-end gap-3">
                    <h2 class="font-display text-4xl md:text-5xl font-bold <?php echo $equity >= 0 ? 'text-equity' : 'text-danger'; ?>">
                        ₱<span id="hero-equity"><?php echo number_format($equity, 2); ?></span>
                    </h2>
                    <?php if ($equity >= 0): ?>
                    <span class="mb-2 inline-flex items-center gap-1 text-equity text-xs font-bold bg-emerald-50 dark:bg-emerald-900/20 px-2 py-1 rounded-lg">
                        <span class="material-icons-round text-sm">trending_up</span> POSITIVE
                    </span>
                    <?php else: ?>
                    <span class="mb-2 inline-flex items-center gap-1 text-danger text-xs font-bold bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded-lg">
                        <span class="material-icons-round text-sm">trending_down</span> DEFICIT
                    </span>
                    <?php endif; ?>
                </div>
                <p class="mt-2 text-xs text-slate-500 font-mono">
                    = Income (₱<?php echo number_format($total_income,2); ?>) − Liabilities (₱<?php echo number_format($total_liabilities,2); ?>) − Expenses (₱<?php echo number_format($total_expenses,2); ?>)
                </p>
            </div>

            <!-- Solvency check -->
            <div class="bg-white/60 dark:bg-white/5 border border-white/50 dark:border-white/10 rounded-xl p-5 min-w-[240px]">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Solvency Check</p>
                    <span class="material-icons-round text-lg <?php echo $is_solvent ? 'text-equity' : 'text-danger'; ?>">
                        <?php echo $is_solvent ? 'check_circle' : 'warning'; ?>
                    </span>
                </div>

                <!-- Simulated PayMongo Balance -->
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[10px] text-slate-400">Simulated PayMongo Balance</p>
                    <span class="xendit-badge">
                        <span class="material-icons-round" style="font-size:10px">database</span>
                        FROM DB
                    </span>
                </div>
                <p class="text-lg font-bold font-display <?php echo $xendit_simulated_balance >= 0 ? 'text-fin' : 'text-danger'; ?> mb-3">
                    ₱<?php echo number_format($xendit_simulated_balance, 2); ?>
                </p>

                <!-- IN / OUT breakdown -->
                <div class="space-y-1.5 mb-3">
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-400 flex items-center gap-1">
                            <span class="material-icons-round text-equity" style="font-size:11px">arrow_downward</span>
                            Mobilization (PayMongo)
                        </span>
                        <span class="font-semibold text-equity">+₱<?php echo number_format($xendit_in_mobilization, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-400 flex items-center gap-1">
                            <span class="material-icons-round text-equity" style="font-size:11px">arrow_downward</span>
                            Final Payments (GCash/PayMongo)
                        </span>
                        <span class="font-semibold text-equity">+₱<?php echo number_format($xendit_in_final, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-400 flex items-center gap-1">
                            <span class="material-icons-round text-equity" style="font-size:11px">arrow_downward</span>
                            Worker Top-ups
                        </span>
                        <span class="font-semibold text-equity">+₱<?php echo number_format($xendit_in_topups, 2); ?></span>
                    </div>
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-400 flex items-center gap-1">
                            <span class="material-icons-round text-danger" style="font-size:11px">arrow_upward</span>
                            Worker Withdrawals
                        </span>
                        <span class="font-semibold text-danger">−₱<?php echo number_format($xendit_out_withdrawals, 2); ?></span>
                    </div>
                </div>

                <!-- Solvency bar: can PayMongo cover worker debt? -->
                <div class="pt-3 border-t border-white/30 dark:border-white/10">
                    <p class="text-[10px] text-slate-400 mb-1">Can we pay all workers right now?</p>
                    <div class="solvency-track mb-2">
                        <div class="solvency-fill <?php echo $is_solvent ? 'bg-equity' : 'bg-danger'; ?>"
                             style="width: <?php echo min(100, max(0, $solvency_ratio)); ?>%"></div>
                    </div>
                    <div class="flex justify-between text-[10px] font-semibold">
                        <span class="text-slate-500">Worker Debt: ₱<?php echo number_format($total_liabilities, 2); ?></span>
                        <span class="<?php echo $is_solvent ? 'text-equity' : 'text-danger'; ?>">
                            <?php echo $is_solvent ? '✓ SAFE' : '⚠ AT RISK'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── KPI Cards ─────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

        <!-- Total Income -->
        <div class="glass-card kpi-card kpi-income rounded-2xl p-5 anim-in">
            <div class="flex justify-between items-start mb-3">
                <div class="w-10 h-10 rounded-xl bg-sky-100 dark:bg-sky-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-fin text-xl">account_balance</span>
                </div>
                <span class="text-[9px] font-bold uppercase tracking-wider text-fin bg-sky-50 dark:bg-sky-900/20 px-2 py-1 rounded-lg">Income</span>
            </div>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">Total Income</p>
            <h3 class="font-display text-2xl font-bold mt-1">₱<?php echo number_format($total_income, 2); ?></h3>
            <div class="mt-4 space-y-1.5">
                <div class="flex justify-between text-[10px] text-slate-400">
                    <span>Booking Fees</span>
                    <span class="font-semibold text-slate-600 dark:text-slate-300">₱<?php echo number_format($booking_fees, 2); ?></span>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400">
                    <span>4% Commissions</span>
                    <span class="font-semibold text-slate-600 dark:text-slate-300">₱<?php echo number_format($commission, 2); ?></span>
                </div>
                <div class="flex justify-between text-[10px] text-slate-400">
                    <span>Top-up Fees (<?php echo $topup_count; ?> txns)</span>
                    <span class="font-semibold text-slate-600 dark:text-slate-300">₱<?php echo number_format($topup_fees, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Total Liabilities -->
        <div class="glass-card kpi-card kpi-liability rounded-2xl p-5 anim-in">
            <div class="flex justify-between items-start mb-3">
                <div class="w-10 h-10 rounded-xl bg-rose-100 dark:bg-rose-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-danger text-xl">account_balance_wallet</span>
                </div>
                <span class="text-[9px] font-bold uppercase tracking-wider text-danger bg-rose-50 dark:bg-rose-900/20 px-2 py-1 rounded-lg">Owed</span>
            </div>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">Worker Debt</p>
            <h3 class="font-display text-2xl font-bold mt-1">₱<?php echo number_format($total_liabilities, 2); ?></h3>
            <p class="mt-4 text-[10px] text-slate-400">
                Owed to <strong class="text-danger"><?php echo $workers_owed; ?> worker<?php echo $workers_owed !== 1 ? 's' : ''; ?></strong> 
                with positive wallet balances.
            </p>
            <div class="mt-3 h-1 w-full bg-rose-100 dark:bg-rose-900/30 rounded-full overflow-hidden">
                <?php $liab_pct = $total_income > 0 ? min(100, round(($total_liabilities / $total_income) * 100)) : 0; ?>
                <div class="h-full bg-danger rounded-full" style="width: <?php echo $liab_pct; ?>%"></div>
            </div>
            <p class="text-[9px] text-slate-400 mt-1"><?php echo $liab_pct; ?>% of total income</p>
        </div>

        <!-- Total Expenses -->
        <div class="glass-card kpi-card kpi-expense rounded-2xl p-5 anim-in">
            <div class="flex justify-between items-start mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-expense text-xl">receipt_long</span>
                </div>
                <span class="text-[9px] font-bold uppercase tracking-wider text-expense bg-amber-50 dark:bg-amber-900/20 px-2 py-1 rounded-lg">OpEx</span>
            </div>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">Total Expenses</p>
            <h3 class="font-display text-2xl font-bold mt-1">₱<?php echo number_format($total_expenses, 2); ?></h3>
            <div class="mt-4 space-y-1.5">
                <div class="flex justify-between text-[10px]">
                    <span class="text-slate-400">MOOE</span>
                    <span class="badge-mooe text-[9px] font-bold px-2 py-0.5 rounded-lg">₱<?php echo number_format($total_mooe, 2); ?></span>
                </div>
                <div class="flex justify-between text-[10px]">
                    <span class="text-slate-400">Personnel (PS)</span>
                    <span class="badge-ps text-[9px] font-bold px-2 py-0.5 rounded-lg">₱<?php echo number_format($total_ps, 2); ?></span>
                </div>
                <?php if ($total_payroll_ps > 0): ?>
                <div class="flex justify-between text-[10px] pt-1 border-t border-slate-100 dark:border-slate-800">
                    <span class="text-slate-400 flex items-center gap-1"><span class="material-icons-round" style="font-size:10px">groups</span>incl. Payroll</span>
                    <span class="text-[9px] font-bold text-violet-500">₱<?php echo number_format($total_payroll_ps, 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($pending_payroll_count > 0): ?>
                <a href="finance_expenses.php" class="flex items-center justify-between text-[9px] font-bold mt-1 px-2 py-1.5 rounded-lg bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 border border-violet-200 dark:border-violet-800 hover:opacity-90 transition-all">
                    <span class="flex items-center gap-1"><span class="material-icons-round" style="font-size:10px">pending_actions</span><?php echo $pending_payroll_count; ?> payroll pending approval</span>
                    <span class="material-icons-round" style="font-size:10px">arrow_forward</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Net Equity -->
        <div class="glass-card kpi-card kpi-equity rounded-2xl p-5 anim-in">
            <div class="flex justify-between items-start mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-equity text-xl">balance</span>
                </div>
                <span class="text-[9px] font-bold uppercase tracking-wider <?php echo $equity >= 0 ? 'text-equity bg-emerald-50 dark:bg-emerald-900/20' : 'text-danger bg-rose-50 dark:bg-rose-900/20'; ?> px-2 py-1 rounded-lg">
                    <?php echo $equity >= 0 ? 'Healthy' : 'Deficit'; ?>
                </span>
            </div>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider">Net Equity</p>
            <h3 class="font-display text-2xl font-bold mt-1 <?php echo $equity >= 0 ? 'text-equity' : 'text-danger'; ?>">
                ₱<?php echo number_format($equity, 2); ?>
            </h3>
            <p class="mt-4 text-[10px] text-slate-400 leading-relaxed">
                Income minus all liabilities and logged operating expenses.
            </p>
            <div class="mt-3 h-1 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <?php $eq_pct = $total_income > 0 ? min(100, max(0, round(($equity / $total_income) * 100))) : 0; ?>
                <div class="h-full <?php echo $equity >= 0 ? 'bg-equity' : 'bg-danger'; ?> rounded-full" style="width: <?php echo $eq_pct; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- ── Charts Row ─────────────────────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-8">

        <!-- Income vs Expenses Chart (span 3) -->
        <div class="lg:col-span-3 glass-card rounded-2xl p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="font-display text-base font-bold">Income vs Expenses</h3>
                    <p class="text-[10px] text-slate-400 uppercase tracking-wider">Past 6 months</p>
                </div>
                <div class="flex gap-3 text-[10px] font-semibold">
                    <span class="flex items-center gap-1.5 text-fin"><span class="w-2.5 h-2.5 rounded-sm bg-fin inline-block"></span> Income</span>
                    <span class="flex items-center gap-1.5 text-expense"><span class="w-2.5 h-2.5 rounded-sm bg-expense inline-block"></span> Expenses</span>
                </div>
            </div>
            <div class="h-64">
                <canvas id="incomeExpenseChart"></canvas>
            </div>
        </div>

        <!-- Equity Over Time (span 2) -->
        <div class="lg:col-span-2 glass-card rounded-2xl p-6">
            <div class="mb-6">
                <h3 class="font-display text-base font-bold">Equity Trend</h3>
                <p class="text-[10px] text-slate-400 uppercase tracking-wider">Net position over time</p>
            </div>
            <div class="h-64">
                <canvas id="equityChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── Expense Logger + Recent Expenses ──────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-20">

        <!-- Logger Form (span 2) -->
        <div class="lg:col-span-2 glass-card rounded-2xl p-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                    <span class="material-icons-round text-expense text-base">add_circle</span>
                </div>
                <div>
                    <h3 class="font-display text-sm font-bold">Log Expense</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">MOOE / PS</p>
                </div>
            </div>

            <div class="space-y-4" id="expense-form">
                <!-- Type -->
                <div>
                    <label class="fin-label">Expense Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="exp_type" value="MOOE" class="sr-only peer" checked>
                            <div class="text-center py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-500
                                        peer-checked:border-expense peer-checked:bg-amber-50 dark:peer-checked:bg-amber-900/20
                                        peer-checked:text-expense transition-all">
                                MOOE
                                <p class="text-[8px] font-normal mt-0.5 opacity-70">Maintenance & Ops</p>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="exp_type" value="PS" class="sr-only peer">
                            <div class="text-center py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-500
                                        peer-checked:border-primary peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-900/20
                                        peer-checked:text-primary transition-all">
                                PS
                                <p class="text-[8px] font-normal mt-0.5 opacity-70">Personnel Services</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Category -->
                <div>
                    <label class="fin-label">Category</label>
                    <input type="text" id="exp_category" class="fin-input"
                           placeholder="e.g. Server Hosting, Staff Salary…"
                           list="category-suggestions">
                    <datalist id="category-suggestions">
                        <option value="Internet & Hosting">
                        <option value="Domain & SSL">
                        <option value="SMS / Push Notification">
                        <option value="Office Supplies">
                        <option value="Equipment Repair">
                        <option value="Staff Salary">
                        <option value="Freelancer Fee">
                        <option value="Allowances">
                        <option value="Training & Dev">
                    </datalist>
                </div>

                <!-- Description -->
                <div>
                    <label class="fin-label">Description <span class="normal-case text-[9px] opacity-60">(optional)</span></label>
                    <textarea id="exp_desc" class="fin-input resize-none" rows="2"
                              placeholder="Brief note about this expense…"></textarea>
                </div>

                <!-- Amount + Date -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="fin-label">Amount (₱)</label>
                        <input type="number" id="exp_amount" class="fin-input" step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div>
                        <label class="fin-label">Date</label>
                        <input type="date" id="exp_date" class="fin-input" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <!-- Submit -->
                <button onclick="submitExpense()" id="submit-btn"
                        class="w-full py-3 rounded-xl bg-expense text-white font-bold text-sm
                               hover:opacity-90 active:scale-95 transition-all
                               flex items-center justify-center gap-2">
                    <span class="material-icons-round text-base">save</span>
                    Log Expense
                </button>
            </div>

            <!-- This-month breakdown -->
            <?php if (count($breakdown) > 0): ?>
            <div class="mt-6 pt-5 border-t border-white/30 dark:border-white/10">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">This Month's Breakdown</p>
                <div class="space-y-2">
                    <?php foreach ($breakdown as $b): ?>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="<?php echo $b['expense_type'] === 'MOOE' ? 'badge-mooe' : 'badge-ps'; ?> text-[9px] font-bold px-1.5 py-0.5 rounded">
                                <?php echo $b['expense_type']; ?>
                            </span>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 truncate max-w-[110px]"><?php echo htmlspecialchars($b['category']); ?></span>
                        </div>
                        <span class="text-[11px] font-bold">₱<?php echo number_format($b['total'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Expenses Table (span 3) -->
        <div class="lg:col-span-3 glass-card rounded-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-white/30 dark:border-white/10 flex items-center justify-between">
                <div>
                    <h3 class="font-display text-sm font-bold">Recent Expenses</h3>
                    <p class="text-[9px] text-slate-400 uppercase tracking-wider">MOOE · PS · Payroll</p>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($pending_payroll_count > 0): ?>
                    <a href="finance_expenses.php" class="flex items-center gap-1 text-[9px] font-bold px-2 py-1 rounded-lg bg-violet-100 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400 border border-violet-200 dark:border-violet-800 animate-pulse">
                        <span class="material-icons-round" style="font-size:11px">pending_actions</span>
                        <?php echo $pending_payroll_count; ?> payroll pending
                    </a>
                    <?php endif; ?>
                    <a href="finance_expenses.php" class="text-[9px] font-bold uppercase tracking-wider text-slate-400 hover:text-fin transition-colors">View All →</a>
                </div>
            </div>

            <div class="overflow-auto slim-scroll" style="max-height: 480px;">
                <table class="fin-table w-full" id="expense-table">
                    <thead>
                        <tr>
                            <th class="text-left">Date</th>
                            <th class="text-left">Type</th>
                            <th class="text-left">Source</th>
                            <th class="text-left">Category</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="expense-tbody">
                        <?php if ($recent_expenses && count($recent_expenses) > 0): ?>
                            <?php foreach ($recent_expenses as $exp):
                                $is_payroll = !empty($exp['payroll_run_id']);
                            ?>
                            <tr data-id="<?php echo $exp['id']; ?>" class="<?php echo $is_payroll ? 'bg-violet-50/30 dark:bg-violet-900/5' : ''; ?>">
                                <td class="text-slate-500 dark:text-slate-400 whitespace-nowrap text-[11px]">
                                    <?php echo date('M d, Y', strtotime($exp['expense_date'])); ?>
                                </td>
                                <td>
                                    <span class="<?php echo $exp['expense_type'] === 'MOOE' ? 'badge-mooe' : 'badge-ps'; ?> text-[9px] font-bold px-2 py-0.5 rounded-md">
                                        <?php echo $exp['expense_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_payroll): ?>
                                    <span class="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-md bg-violet-100 dark:bg-violet-900/30 text-violet-600 dark:text-violet-400">
                                        <span class="material-icons-round" style="font-size:10px">groups</span>Payroll
                                    </span>
                                    <?php else: ?>
                                    <span class="text-[9px] text-slate-400">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <p class="font-medium text-slate-800 dark:text-slate-100 text-[12px]"><?php echo htmlspecialchars($exp['category']); ?></p>
                                    <?php if ($is_payroll): ?>
                                    <p class="text-[10px] text-violet-500 truncate max-w-[160px]"><?php echo htmlspecialchars($exp['payroll_title'] ?? ''); ?> · <?php echo $exp['employee_count']; ?> emp.</p>
                                    <?php elseif (!empty($exp['description'])): ?>
                                    <p class="text-[10px] text-slate-400 truncate max-w-[160px]"><?php echo htmlspecialchars($exp['description']); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-bold font-mono">₱<?php echo number_format($exp['amount'], 2); ?></td>
                                <td class="text-right">
                                    <?php if (!$is_payroll): ?>
                                    <button onclick="deleteExpense(<?php echo $exp['id']; ?>, this)"
                                            class="text-slate-300 dark:text-slate-600 hover:text-danger transition-colors p-1 rounded">
                                        <span class="material-icons-round text-base">delete_outline</span>
                                    </button>
                                    <?php else: ?>
                                    <a href="finance_expenses.php" class="text-slate-300 dark:text-slate-600 hover:text-fin transition-colors p-1 rounded block">
                                        <span class="material-icons-round text-base">open_in_new</span>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-12 text-slate-400">
                                <span class="material-icons-round text-3xl block mb-2 opacity-40">receipt_long</span>
                                No expenses logged yet.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<script>
// ── Chart Data from PHP ──────────────────────────────────────
const chartLabels  = <?php echo json_encode($chart_labels); ?>;
const chartIncomes = <?php echo json_encode($chart_incomes); ?>;
const chartExps    = <?php echo json_encode($chart_exps); ?>;
const chartEquity  = <?php echo json_encode($chart_equity); ?>;

let incomeExpChart, equityChart;

document.addEventListener('DOMContentLoaded', buildCharts);

function getTheme() {
    const dark = document.documentElement.classList.contains('dark');
    return {
        text:   dark ? '#94a3b8' : '#64748b',
        grid:   dark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.06)',
        bg:     dark ? '#111827' : '#ffffff',
    };
}

function buildCharts() {
    const t = getTheme();

    // ── Income vs Expenses Bar chart ─────────────────────────
    const ctx1 = document.getElementById('incomeExpenseChart').getContext('2d');
    incomeExpChart = new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Income',
                    data: chartIncomes,
                    backgroundColor: 'rgba(14,165,233,0.8)',
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.55,
                },
                {
                    label: 'Expenses',
                    data: chartExps,
                    backgroundColor: 'rgba(245,158,11,0.7)',
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.55,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2})
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: t.grid, drawBorder: false },
                    ticks: {
                        color: t.text,
                        font: { size: 10, family: 'DM Sans' },
                        callback: v => '₱' + v.toLocaleString()
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: t.text, font: { size: 10, family: 'DM Sans' } }
                }
            }
        }
    });

    // ── Equity Over Time Line chart ──────────────────────────
    const ctx2 = document.getElementById('equityChart').getContext('2d');
    const gradEq = ctx2.createLinearGradient(0, 0, 0, 200);
    gradEq.addColorStop(0, 'rgba(16,185,129,0.3)');
    gradEq.addColorStop(1, 'rgba(16,185,129,0)');

    equityChart = new Chart(ctx2, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Net Equity',
                data: chartEquity,
                borderColor: '#10B981',
                backgroundColor: gradEq,
                borderWidth: 2.5,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#10B981',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2})
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: t.grid, drawBorder: false },
                    ticks: {
                        color: t.text,
                        font: { size: 10, family: 'DM Sans' },
                        callback: v => '₱' + v.toLocaleString()
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: t.text, font: { size: 10, family: 'DM Sans' } }
                }
            }
        }
    });
}

function rebuildCharts() {
    if (incomeExpChart) incomeExpChart.destroy();
    if (equityChart)    equityChart.destroy();
    buildCharts();
}

// ── Expense form submission ──────────────────────────────────
function submitExpense() {
    const type   = document.querySelector('input[name="exp_type"]:checked')?.value;
    const cat    = document.getElementById('exp_category').value.trim();
    const desc   = document.getElementById('exp_desc').value.trim();
    const amount = parseFloat(document.getElementById('exp_amount').value);
    const date   = document.getElementById('exp_date').value;

    if (!cat)         { showToast('Please enter a category.', 'error'); return; }
    if (!amount || amount <= 0) { showToast('Enter a valid amount.', 'error'); return; }
    if (!date)        { showToast('Pick a date.', 'error'); return; }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-icons-round text-base animate-spin">sync</span> Saving…';

    const fd = new FormData();
    fd.append('action', 'add_expense');
    fd.append('expense_type',  type);
    fd.append('category',      cat);
    fd.append('description',   desc);
    fd.append('amount',        amount);
    fd.append('expense_date',  date);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Expense logged!', 'success');
                // Inject row at top of table
                const tbody = document.getElementById('expense-tbody');
                const noRow = tbody.querySelector('[colspan]');
                if (noRow) noRow.parentElement.remove();

                const typeClass = type === 'MOOE' ? 'badge-mooe' : 'badge-ps';
                const now = new Date();
                const dateLabel = now.toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' });
                const newId = Date.now(); // temp until reload

                const tr = document.createElement('tr');
                tr.dataset.id = newId;
                tr.innerHTML = `
                    <td class="text-slate-500 dark:text-slate-400 whitespace-nowrap">${dateLabel}</td>
                    <td><span class="${typeClass} text-[9px] font-bold px-2 py-0.5 rounded-md">${type}</span></td>
                    <td><p class="font-medium text-slate-800 dark:text-slate-100">${escHtml(cat)}</p>${desc ? `<p class="text-[10px] text-slate-400 truncate max-w-[160px]">${escHtml(desc)}</p>` : ''}</td>
                    <td class="text-right font-bold font-mono">₱${amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</td>
                    <td class="text-right"><button onclick="deleteExpense(this.closest('tr').dataset.id, this)" class="text-slate-300 dark:text-slate-600 hover:text-danger transition-colors p-1 rounded"><span class="material-icons-round text-base">delete_outline</span></button></td>
                `;
                tbody.insertBefore(tr, tbody.firstChild);

                // Reset form
                document.getElementById('exp_category').value = '';
                document.getElementById('exp_desc').value     = '';
                document.getElementById('exp_amount').value   = '';
            } else {
                showToast(data.message || 'Error saving.', 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-icons-round text-base">save</span> Log Expense';
        });
}

function deleteExpense(id, btn) {
    if (!confirm('Delete this expense entry?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_expense');
    fd.append('expense_id', id);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.closest('tr').style.opacity = '0';
                btn.closest('tr').style.transform = 'translateX(20px)';
                btn.closest('tr').style.transition = 'all .3s ease';
                setTimeout(() => btn.closest('tr').remove(), 300);
                showToast('Expense removed.', 'success');
            }
        });
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent  = msg;
    t.className    = `show ${type}`;
    setTimeout(() => { t.className = type; }, 3000);
}

// Close mobile menu on outside click
document.addEventListener('click', e => {
    const menu    = document.getElementById('mobile-sidebar');
    const overlay = document.getElementById('menu-overlay');
    if (menu && !menu.classList.contains('-translate-x-full')
        && !e.target.closest('[onclick="toggleMobileMenu()"]')
        && !menu.contains(e.target)
        && window.innerWidth < 1024) {
        toggleMobileMenu();
    }
});
</script>
</body>
</html>