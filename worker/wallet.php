<?php
// worker/wallet.php — Merged Wallet + Top Up page with Listo Points
include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';
include '../config/constants.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$wallet = new WalletManager($conn);

// Fetch worker profile including listo_points
$sql = "SELECT wallet_balance, free_credits, free_bookings_used, jobs_completed,
               average_rating, verification_status,
               COALESCE(listo_points, 0) AS listo_points
        FROM worker_profiles WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$worker_id]);
$profile = $stmt->fetch();
$balance = $profile['wallet_balance'] ?? 0;

// Listo Points constants are now defined in wallet_manager.php
// No need to redefine them here

$listo_points      = intval($profile['listo_points'] ?? 0);
$points_progress   = $listo_points % LISTO_POINTS_FOR_FREE;      // 0-49 within current cycle
$points_percentage = round(($points_progress / LISTO_POINTS_FOR_FREE) * 100);
$points_to_next    = LISTO_POINTS_FOR_FREE - $points_progress;

// Fetch pending earnings
$pending_sql = "SELECT COUNT(*) as count, SUM(calculated_fee) as total
                FROM bookings
                WHERE worker_id = ?
                AND payment_method = 'PayMongo'
                AND payment_status = 'Paid'
                AND status = 'Pending'";
$pending_stmt = $conn->prepare($pending_sql);
$pending_stmt->execute([$worker_id]);
$pending = $pending_stmt->fetch();

// Handle Withdrawal Request
if (isset($_POST['withdraw_btn'])) {
    $amount = floatval($_POST['amount']);
    $gcash  = $_POST['gcash_number'];

    if ($amount > $balance) {
        $error = "Insufficient balance!";
    } elseif ($amount < 100) {
        $error = "Minimum withdrawal is ₱100.";
    } else {
        $conn->beginTransaction();
        try {
            $new_balance = $balance - $amount;
            $upd_stmt = $conn->prepare("UPDATE worker_profiles SET wallet_balance = ? WHERE user_id = ?");
            $upd_stmt->execute([$new_balance, $worker_id]);
            $wd_stmt = $conn->prepare("INSERT INTO withdrawals (worker_id, amount, gcash_number, status, request_date)
                         VALUES (?, ?, ?, 'Pending', NOW())");
            $wd_stmt->execute([$worker_id, $amount, $gcash]);
            $withdrawal_id = $conn->lastInsertId('withdrawals_id_seq');
            $wt_stmt = $conn->prepare("INSERT INTO wallet_transactions
                         (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after)
                         VALUES (?, 'worker', 'withdrawal', ?, ?, 'withdrawal', ?, ?)");
            $wt_stmt->execute([$worker_id, $amount, $withdrawal_id, "Withdrawal request to GCash $gcash", $new_balance]);
            $conn->commit();
            $balance = $new_balance;
            $success = "Withdrawal requested! Admin will process it shortly.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Handle Top-Up form
if (isset($_POST['topup_btn'])) {
    $amount         = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'] ?? 'gcash';

    if ($amount < MIN_TOPUP) {
        $topup_error = "Minimum top-up is ₱" . MIN_TOPUP;
    } elseif ($amount > MAX_TOPUP) {
        $topup_error = "Maximum top-up is ₱" . MAX_TOPUP;
    } elseif ($payment_method === 'gcash') {
        header("Location: process_topup_paymongo.php?amount=$amount");
        exit();
    } else {
        $topup_error = "Invalid payment method selected.";
    }
}

// Fetch wallet info for free credits display
$wallet_info = $wallet->getWorkerWallet($worker_id);

// Fetch recent top-ups (last 5, shown in top-up tab)
$topups_sql = "SELECT * FROM top_ups WHERE worker_id = ? ORDER BY created_at DESC LIMIT 5";
$topups_stmt = $conn->prepare($topups_sql);
$topups_stmt->execute([$worker_id]);
$topups = $topups_stmt->fetchAll();

// Active tab logic
$active_tab = $_GET['tab'] ?? 'overview';
if (!in_array($active_tab, ['overview', 'topup', 'withdraw'])) $active_tab = 'overview';

// Handle filter for transaction history
$filter = $_GET['filter'] ?? 'all';
$filter_where = '';
if ($filter === 'credits')     $filter_where = "AND wt.transaction_type = 'credit' AND wt.reference_type != 'listo_points' AND wt.reference_type != 'listo_reward'";
if ($filter === 'withdrawals') $filter_where = "AND wt.transaction_type = 'withdrawal'";
if ($filter === 'refunds')     $filter_where = "AND wt.transaction_type = 'refund'";
if ($filter === 'points')      $filter_where = "AND (wt.reference_type = 'listo_points' OR wt.reference_type = 'listo_reward')";

$transactions_sql = "SELECT wt.*, b.id as booking_ref, b.status as booking_status
                     FROM wallet_transactions wt
                     LEFT JOIN bookings b ON wt.reference_id = b.id AND wt.reference_type = 'booking'
                     WHERE wt.user_id = ? AND wt.user_type = 'worker' $filter_where
                     ORDER BY wt.created_at DESC
                     LIMIT 50";
$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_stmt->execute([$worker_id]);
$transactions = $transactions_stmt->fetchAll();

function getStatusBadge($status) {
    switch($status) {
        case 'Gold':      return ['bg' => 'rgba(255,215,0,0.1)',   'color' => '#b8860b', 'icon' => 'workspace_premium'];
        case 'Silver':    return ['bg' => 'rgba(192,192,192,0.1)', 'color' => '#4a4a4a', 'icon' => 'military_tech'];
        case 'Bronze':    return ['bg' => 'rgba(205,127,50,0.1)',  'color' => '#8b4513', 'icon' => 'verified'];
        case 'Community': return ['bg' => 'rgba(52,152,219,0.1)',  'color' => '#2980b9', 'icon' => 'groups'];
        default:          return ['bg' => 'rgba(108,117,125,0.1)', 'color' => '#6c757d', 'icon' => 'schedule'];
    }
}
$statusStyle = getStatusBadge($profile['verification_status'] ?? 'None');
?>
<!DOCTYPE html>
<html class="light" lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Wallet | Abilisto</title>

    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        "card-light": "#FFFFFF",
                        "card-dark": "#1E293B",
                        brand: { purple: "#6366F1", blue: "#146af5", green: "#10B981" }
                    },
                    fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
                    borderRadius: { DEFAULT: "12px", '2xl': '24px' },
                }
            }
        };
    </script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-font-smoothing: antialiased; }
        .glass-card {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .gradient-bg  { background: linear-gradient(135deg, #146af5 0%, #2DD4BF 100%); }
        .gradient-green{ background: linear-gradient(135deg, #10B981 0%, #059669 100%); }
        .gradient-amber{ background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); }
        .gradient-points{ background: linear-gradient(135deg, #8B5CF6 0%, #EC4899 100%); }
        .custom-shadow { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06), 0 8px 10px -6px rgba(0,0,0,0.04); }
        .material-symbols-rounded { font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 24; }
        .material-symbols-rounded.filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes slideUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
        .animate-slideUp { animation: slideUp 0.45s ease forwards; }
        @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
        .points-progress-bar {
            background: linear-gradient(90deg, #8B5CF6, #EC4899, #8B5CF6);
            background-size: 200% auto;
            animation: shimmer 2s linear infinite;
        }
        /* Tab styles */
        .tab-btn { transition: all .2s; }
        .tab-btn.active { background: white; color: #146af5; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .dark .tab-btn.active { background: #1e293b; color: #60a5fa; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        /* Badge pill */
        .badge-listo { background: linear-gradient(135deg,#8B5CF620,#EC489920); border:1px solid #8B5CF640; color:#8B5CF6; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark min-h-screen transition-colors duration-300">

<?php include '../includes/navbar.php'; ?>

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6 animate-slideUp">

    <!-- ═══════════════ HERO BALANCE CARD ═══════════════ -->
    <div class="gradient-bg rounded-2xl p-6 md:p-10 shadow-2xl shadow-blue-500/20 relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-cyan-400/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10">
            <!-- Verification Badge -->
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full mb-4 text-xs font-semibold"
                 style="background:<?php echo $statusStyle['bg']; ?>;color:<?php echo $statusStyle['color']; ?>;border:1px solid <?php echo $statusStyle['color']; ?>22;">
                <span class="material-symbols-rounded text-sm filled"><?php echo $statusStyle['icon']; ?></span>
                <?php echo $profile['verification_status'] ?? 'Not Verified'; ?>
            </div>

            <p class="text-white/70 text-xs uppercase tracking-widest font-semibold mb-1">Available Balance</p>
            <h2 class="text-4xl md:text-6xl font-extrabold text-white mb-6 flex items-baseline gap-2">
                ₱<?php echo number_format($balance, 2); ?>
                <span class="text-lg md:text-2xl font-medium opacity-60">PHP</span>
            </h2>

            <!-- Stats row -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="glass-card rounded-2xl p-3 text-center">
                    <p class="text-xl md:text-2xl font-extrabold text-white"><?php echo $profile['jobs_completed'] ?? 0; ?></p>
                    <p class="text-[10px] text-white/60 uppercase font-semibold tracking-wide">Jobs Done</p>
                </div>
                <div class="glass-card rounded-2xl p-3 text-center">
                    <p class="text-xl md:text-2xl font-extrabold text-white"><?php echo number_format($profile['average_rating'] ?? 0, 1); ?></p>
                    <p class="text-[10px] text-white/60 uppercase font-semibold tracking-wide">Rating</p>
                </div>
                <div class="glass-card rounded-2xl p-3 text-center">
                    <p class="text-xl md:text-2xl font-extrabold text-amber-300">₱<?php echo number_format($wallet_info['free_credits'], 2); ?></p>
                    <p class="text-[10px] text-white/60 uppercase font-semibold tracking-wide">Free Credits</p>
                </div>
                <!-- Listo Points pill -->
                <div class="glass-card rounded-2xl p-3 text-center">
                    <p class="text-xl md:text-2xl font-extrabold text-purple-200">
                        <span class="material-symbols-rounded text-base align-middle filled text-purple-300">stars</span>
                        <?php echo $listo_points; ?>
                    </p>
                    <p class="text-[10px] text-white/60 uppercase font-semibold tracking-wide">Listo Points</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════ LISTO POINTS CARD ═══════════════ -->
    <div class="bg-card-light dark:bg-card-dark rounded-2xl p-5 md:p-6 custom-shadow border border-slate-200/50 dark:border-slate-800">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 gradient-points rounded-xl flex items-center justify-center shadow-md shadow-purple-500/20">
                    <span class="material-symbols-rounded text-white filled">stars</span>
                </div>
                <div>
                    <h3 class="font-bold text-slate-800 dark:text-slate-100">Listo Points</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Earn 5 points per completed job · 50 points = 1 free booking credit</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge-listo px-3 py-1 rounded-full text-xs font-bold">
                    <?php echo $listo_points; ?> pts total
                </span>
                <?php if ($points_to_next <= 10): ?>
                <span class="bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 px-3 py-1 rounded-full text-xs font-bold animate-pulse">
                    🔥 Almost there!
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="mb-2">
            <div class="flex justify-between text-xs text-slate-500 dark:text-slate-400 mb-1.5">
                <span><?php echo $points_progress; ?> / <?php echo LISTO_POINTS_FOR_FREE; ?> pts this cycle</span>
                <span><?php echo $points_to_next; ?> pts until next free credit</span>
            </div>
            <div class="w-full h-3 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full points-progress-bar transition-all duration-700"
                     style="width: <?php echo $points_percentage; ?>%;"></div>
            </div>
        </div>

        <!-- How it works -->
        <div class="grid grid-cols-3 gap-3 mt-4">
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-3 text-center">
                <span class="material-symbols-rounded text-blue-500 text-xl filled">assignment_turned_in</span>
                <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mt-1">Complete a Job</p>
                <p class="text-[10px] text-slate-400">Earn +<?php echo LISTO_POINTS_PER_JOB; ?> pts</p>
            </div>
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-3 text-center">
                <span class="material-symbols-rounded text-purple-500 text-xl filled">stars</span>
                <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mt-1">Reach 50 Points</p>
                <p class="text-[10px] text-slate-400">Auto milestone</p>
            </div>
            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-xl p-3 text-center">
                <span class="material-symbols-rounded text-green-500 text-xl filled">card_giftcard</span>
                <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 mt-1">Get Free Credit</p>
                <p class="text-[10px] text-slate-400">Worth ₱<?php echo ADMIN_FEE_PER_BOOKING; ?></p>
            </div>
        </div>
    </div>

    <!-- ═══════════════ ALERTS ═══════════════ -->
    <?php if (($pending['count'] ?? 0) > 0): ?>
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-800/30 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">hourglass_top</span>
        </div>
        <div class="flex-1">
            <h4 class="font-bold text-amber-800 dark:text-amber-300 text-sm">Pending Earnings</h4>
            <p class="text-amber-700 dark:text-amber-400 text-xs">₱<?php echo number_format($pending['total'] ?? 0, 2); ?> from <?php echo $pending['count']; ?> booking(s) awaiting acceptance.</p>
        </div>
        <span class="text-xs font-medium text-amber-600 dark:text-amber-400 bg-white dark:bg-amber-900/30 px-3 py-1 rounded-full flex-shrink-0">Pending</span>
    </div>
    <?php endif; ?>

    <?php if ($wallet_info['free_credits'] > 0): ?>
    <div class="bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-400 p-4 rounded-xl flex justify-between items-center">
        <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-amber-500 filled">card_giftcard</span>
            <div>
                <h4 class="font-bold text-sm text-slate-800 dark:text-slate-200">You have free accept credits!</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">Use them for booking fees (₱<?php echo ADMIN_FEE_PER_BOOKING; ?> per booking)</p>
            </div>
        </div>
        <div class="text-lg font-extrabold text-amber-500">₱<?php echo number_format($wallet_info['free_credits'], 2); ?></div>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl p-4 flex items-center gap-3">
        <span class="material-symbols-rounded text-red-500">error</span>
        <p class="text-sm font-medium text-red-700 dark:text-red-300"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl p-4 flex items-center gap-3">
        <span class="material-symbols-rounded text-green-500">check_circle</span>
        <p class="text-sm font-medium text-green-700 dark:text-green-300"><?php echo htmlspecialchars($success); ?></p>
    </div>
    <?php endif; ?>

    <!-- ═══════════════ TABS ═══════════════ -->
    <div class="bg-slate-100 dark:bg-slate-800/60 p-1.5 rounded-2xl flex gap-1">
        <button onclick="switchTab('overview')" id="tab-overview"
                class="tab-btn flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2 <?php echo $active_tab==='overview'?'active':''; ?>">
            <span class="material-symbols-rounded text-base">account_balance_wallet</span>
            <span class="hidden sm:inline">Overview</span>
        </button>
        <button onclick="switchTab('topup')" id="tab-topup"
                class="tab-btn flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2 <?php echo $active_tab==='topup'?'active':''; ?>">
            <span class="material-symbols-rounded text-base">add_circle</span>
            <span class="hidden sm:inline">Top Up</span>
        </button>
        <button onclick="switchTab('withdraw')" id="tab-withdraw"
                class="tab-btn flex-1 py-2.5 px-4 rounded-xl text-sm font-semibold text-slate-500 dark:text-slate-400 flex items-center justify-center gap-2 <?php echo $active_tab==='withdraw'?'active':''; ?>">
            <span class="material-symbols-rounded text-base">payments</span>
            <span class="hidden sm:inline">Withdraw</span>
        </button>
    </div>

    <!-- ════════════════════════════════════════
         TAB: OVERVIEW (Transaction History)
    ════════════════════════════════════════ -->
    <div id="panel-overview" class="tab-panel <?php echo $active_tab==='overview'?'active':''; ?>">
        <div class="bg-card-light dark:bg-card-dark rounded-2xl p-6 md:p-8 custom-shadow border border-slate-200/50 dark:border-slate-800">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary">history</span>
                    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">Transaction History</h3>
                </div>
                <!-- Filter tabs -->
                <div class="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
                    <?php
                    $filters = ['all'=>'All','credits'=>'Credits','withdrawals'=>'Withdrawals','refunds'=>'Refunds','points'=>'Points'];
                    foreach ($filters as $f => $label):
                        $active_class = $filter===$f ? 'bg-primary text-white shadow-md shadow-primary/20' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400';
                    ?>
                    <a href="?tab=overview&filter=<?php echo $f; ?>"
                       class="px-4 py-2 <?php echo $active_class; ?> text-xs font-semibold rounded-full whitespace-nowrap transition-colors hover:opacity-90">
                        <?php echo $label; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($transactions && count($transactions) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($transactions as $tx):
                    $is_listo_points = ($tx['reference_type'] === 'listo_points');
                    $is_listo_reward = ($tx['reference_type'] === 'listo_reward');
                    $is_listo = $is_listo_points || $is_listo_reward;
                    
                    switch($tx['transaction_type']) {
                        case 'credit':     
                            if ($is_listo_points) {
                                $icon = 'stars'; 
                                $icon_bg = 'bg-purple-100 dark:bg-purple-900/30'; 
                                $amount_class = 'text-purple-600 dark:text-purple-400';
                            } elseif ($is_listo_reward) {
                                $icon = 'card_giftcard'; 
                                $icon_bg = 'bg-amber-100 dark:bg-amber-900/30'; 
                                $amount_class = 'text-amber-600 dark:text-amber-400';
                            } else {
                                $icon = 'add_circle';    
                                $icon_bg = 'bg-green-100 dark:bg-green-900/30'; 
                                $amount_class = 'text-green-600 dark:text-green-400'; 
                            }
                            break;
                        case 'fee':        
                            $icon = 'credit_card';   
                            $icon_bg = 'bg-amber-100 dark:bg-amber-900/30'; 
                            $amount_class = 'text-amber-600 dark:text-amber-400'; 
                            break;
                        case 'withdrawal': 
                            $icon = 'output';        
                            $icon_bg = 'bg-red-100 dark:bg-red-900/30';    
                            $amount_class = 'text-red-600 dark:text-red-400'; 
                            break;
                        case 'refund':     
                            $icon = 'undo';          
                            $icon_bg = 'bg-blue-100 dark:bg-blue-900/30';  
                            $amount_class = 'text-blue-600 dark:text-blue-400'; 
                            break;
                        default:           
                            $icon = 'swap_horiz';    
                            $icon_bg = 'bg-purple-100 dark:bg-purple-900/30'; 
                            $amount_class = 'text-purple-600 dark:text-purple-400';
                    }
                    
                    $sign = '';
                    if ($tx['transaction_type'] == 'credit' && !$is_listo) {
                        $sign = '+';
                    } elseif ($tx['transaction_type'] == 'withdrawal' || $tx['transaction_type'] == 'fee') {
                        $sign = '-';
                    }
                ?>
                <div class="flex items-start gap-4 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <div class="w-11 h-11 rounded-xl <?php echo $icon_bg; ?> flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-rounded <?php echo $amount_class; ?> filled"><?php echo $icon; ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start gap-2">
                            <p class="font-semibold text-slate-800 dark:text-slate-200 text-sm truncate">
                                <?php echo htmlspecialchars($tx['description']); ?>
                            </p>
                            <span class="font-bold <?php echo $amount_class; ?> whitespace-nowrap text-sm">
                                <?php 
                                if ($is_listo_points) {
                                    echo '⭐ +' . $tx['amount'] . ' pts';
                                } elseif ($is_listo_reward) {
                                    echo '🎉 FREE ₱' . number_format($tx['amount'], 2);
                                } else {
                                    echo $sign . '₱' . number_format($tx['amount'], 2);
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-400">
                            <span><?php echo date('M d, Y · h:i A', strtotime($tx['created_at'])); ?></span>
                            <?php if ($tx['reference_id'] && !$is_listo): ?>
                            <span>· Ref #<?php echo $tx['reference_id']; ?></span>
                            <?php endif; ?>
                            <?php if ($is_listo_points): ?>
                            <span class="badge-listo px-2 py-0.5 rounded-full text-[10px] font-bold">Listo Points</span>
                            <?php elseif ($is_listo_reward): ?>
                            <span class="bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 px-2 py-0.5 rounded-full text-[10px] font-bold">Free Credit Reward</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="py-12 flex flex-col items-center text-center">
                <div class="w-16 h-16 bg-blue-50 dark:bg-slate-800 rounded-full flex items-center justify-center mb-4">
                    <span class="material-symbols-rounded text-primary text-3xl">receipt_long</span>
                </div>
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-2">No Transactions Yet</h4>
                <p class="text-slate-400 text-sm max-w-xs">Your transaction history will appear here once you start accepting jobs.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: TOP UP
    ════════════════════════════════════════ -->
    <div id="panel-topup" class="tab-panel <?php echo $active_tab==='topup'?'active':''; ?>">

        <!-- Top-up error / success -->
        <?php if (isset($topup_error)): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl p-4 mb-4 flex items-center gap-3">
            <span class="material-symbols-rounded text-red-500">error</span>
            <p class="text-sm font-medium text-red-700 dark:text-red-300"><?php echo htmlspecialchars($topup_error); ?></p>
        </div>
        <?php endif; ?>

        <div class="bg-card-light dark:bg-card-dark rounded-2xl p-6 md:p-8 custom-shadow border border-slate-200/50 dark:border-slate-800 mb-6">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-9 h-9 gradient-green rounded-xl flex items-center justify-center shadow-md shadow-emerald-500/20">
                    <span class="material-symbols-rounded text-white filled">add_circle</span>
                </div>
                <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">Add Funds via PayMongo</h3>
            </div>

            <form method="POST" id="topupForm">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left: Amount -->
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Select Amount</p>
                        <div class="grid grid-cols-3 gap-2 mb-4">
                            <?php foreach ([100,200,500,1000] as $preset): ?>
                            <button type="button" class="preset-btn py-3 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all text-sm" onclick="setAmount(<?php echo $preset; ?>)">₱<?php echo number_format($preset); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="preset-btn col-span-2 py-3 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all text-sm" onclick="customAmount()">Custom</button>
                        </div>

                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Enter Amount (₱)</label>
                        <input type="number" name="amount" id="amountInput"
                               class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-xl py-3 px-5 text-2xl font-extrabold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary/30 outline-none transition-all"
                               value="200" min="<?php echo MIN_TOPUP; ?>" max="<?php echo MAX_TOPUP; ?>" step="1" required>
                        <p class="text-xs text-slate-400 mt-2">
                            <span class="material-symbols-rounded text-xs align-middle">info</span>
                            Min ₱<?php echo MIN_TOPUP; ?> · Max ₱<?php echo MAX_TOPUP; ?>
                        </p>
                    </div>

                    <!-- Right: Payment Method + Submit -->
                    <div class="flex flex-col justify-between">
                        <div class="mb-6">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Payment Method</p>
                            <label class="relative block cursor-pointer">
                                <input type="radio" name="payment_method" value="gcash" class="sr-only peer" checked>
                                <div class="p-4 rounded-xl border-2 border-primary bg-primary/5 flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-lg bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center">
                                        <span class="material-symbols-rounded text-3xl text-primary">smartphone</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-800 dark:text-slate-100">PayMongo</p>
                                        <p class="text-xs text-primary font-medium">Instant · Secure</p>
                                    </div>
                                    <div class="ml-auto">
                                        <span class="material-symbols-rounded text-primary filled">check_circle</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <input type="hidden" name="payment_method" value="gcash">
                        <button type="submit" name="topup_btn"
                                class="w-full gradient-green text-white py-4 rounded-xl font-bold shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 transition-all flex items-center justify-center gap-2 active:scale-[0.98]">
                            Proceed to Payment
                            <span class="material-symbols-rounded">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Recent Top-Ups -->
        <?php if ($topups && count($topups) > 0): ?>
        <div class="bg-card-light dark:bg-card-dark rounded-2xl p-6 custom-shadow border border-slate-200/50 dark:border-slate-800">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-primary">history</span>
                <h3 class="text-md font-bold text-slate-800 dark:text-slate-100">Recent Top-Ups</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-slate-400 uppercase text-[10px] font-bold tracking-widest border-b border-slate-100 dark:border-slate-700">
                            <th class="pb-3">Date</th>
                            <th class="pb-3 text-right pr-4">Amount</th>
                            <th class="pb-3 hidden sm:table-cell">Reference</th>
                            <th class="pb-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                        <?php
                        foreach ($topups as $topup):
                            $s = $topup['status'];
                            $sc = $s==='completed' ? 'bg-emerald-500/10 text-emerald-600' : ($s==='pending' ? 'bg-amber-500/10 text-amber-600' : 'bg-red-500/10 text-red-600');
                            $si = $s==='completed' ? 'check_circle' : ($s==='pending' ? 'pending' : 'error');
                        ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="py-3 text-slate-500 text-xs"><?php echo date('M d, Y h:i A', strtotime($topup['created_at'])); ?></td>
                            <td class="py-3 font-bold text-slate-800 dark:text-slate-100 text-right pr-4">₱<?php echo number_format($topup['amount'], 2); ?></td>
                            <td class="py-3 font-mono text-[10px] text-slate-400 hidden sm:table-cell"><?php echo $topup['reference_number'] ?? 'N/A'; ?></td>
                            <td class="py-3 text-right">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $sc; ?> inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-xs"><?php echo $si; ?></span>
                                    <?php echo ucfirst($s); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════
         TAB: WITHDRAW
    ════════════════════════════════════════ -->
    <div id="panel-withdraw" class="tab-panel <?php echo $active_tab==='withdraw'?'active':''; ?>">
        <div class="bg-card-light dark:bg-card-dark rounded-2xl p-6 md:p-8 custom-shadow border border-slate-200/50 dark:border-slate-800">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center shadow-md shadow-blue-500/20">
                    <span class="material-symbols-rounded text-white">payments</span>
                </div>
                <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">Cash Out to GCash</h3>
            </div>

            <form method="POST">
                <div class="grid md:grid-cols-2 gap-6 mb-6">
                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">savings</span> Amount to Withdraw
                        </label>
                        <input type="number" name="amount"
                               class="w-full px-4 py-3.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all dark:text-white"
                               placeholder="Enter amount (min. ₱100)"
                               min="100" max="<?php echo $balance; ?>" step="0.01" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-base">smartphone</span> GCash Number
                        </label>
                        <input type="text" name="gcash_number"
                               class="w-full px-4 py-3.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition-all dark:text-white"
                               value="<?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?>"
                               placeholder="09xxxxxxxxx" pattern="[0-9]{11}" title="11-digit GCash number" required>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-primary/10 border border-blue-100 dark:border-primary/20 p-4 rounded-xl flex items-center gap-3 mb-6">
                    <span class="material-symbols-rounded text-primary">info</span>
                    <p class="text-sm text-blue-800 dark:text-blue-300">
                        Available balance: <span class="font-bold">₱<?php echo number_format($balance, 2); ?></span>
                        &nbsp;·&nbsp; Processed within 1–2 business days.
                    </p>
                </div>

                <button type="submit" name="withdraw_btn"
                        class="w-full py-4 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold rounded-xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 active:scale-[0.98] disabled:opacity-50 disabled:cursor-not-allowed"
                        <?php echo $balance < 100 ? 'disabled' : ''; ?>>
                    <span class="material-symbols-rounded">send</span>
                    <?php echo $balance < 100 ? 'Minimum ₱100 required' : 'Withdraw Funds'; ?>
                </button>
            </form>
        </div>
    </div>

    <footer class="text-center pt-4 pb-8">
        <p class="text-slate-400 dark:text-slate-500 text-xs">© 2026 Abilisto. All rights reserved.</p>
    </footer>
</div>

<script>
// ── Dark mode ──
const html = document.documentElement;
if (localStorage.getItem('darkMode') === 'true') html.classList.add('dark');
document.getElementById('darkToggle').addEventListener('click', () => {
    html.classList.toggle('dark');
    localStorage.setItem('darkMode', html.classList.contains('dark'));
});

// ── Tabs ──
function switchTab(name) {
    ['overview','topup','withdraw'].forEach(t => {
        document.getElementById('panel-' + t).classList.toggle('active', t === name);
        document.getElementById('tab-' + t).classList.toggle('active', t === name);
    });
    history.replaceState(null, '', '?tab=' + name);
}

// ── Top-up preset buttons ──
function setAmount(amount) {
    document.getElementById('amountInput').value = amount;
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.remove('border-primary','bg-primary/5','text-primary');
        btn.classList.add('border-slate-100','dark:border-slate-700');
    });
    event.currentTarget.classList.add('border-primary','bg-primary/5','text-primary');
}
function customAmount() {
    document.getElementById('amountInput').value = '';
    document.getElementById('amountInput').focus();
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.remove('border-primary','bg-primary/5','text-primary');
    });
}

// ── Top-up form validation ──
document.getElementById('topupForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amountInput').value);
    const min = <?php echo MIN_TOPUP; ?>, max = <?php echo MAX_TOPUP; ?>;
    if (isNaN(amount) || amount < min) { e.preventDefault(); alert('Minimum top-up is ₱' + min); }
    else if (amount > max)            { e.preventDefault(); alert('Maximum top-up is ₱' + max); }
});
</script>
</body>
</html>