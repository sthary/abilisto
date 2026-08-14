<?php
// admin/wallet.php
include '../db.php';
include '../includes/init_lang.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Get admin info for navbar
$admin_id = $_SESSION['user_id'];
$admin_sql = "SELECT full_name, profile_pic FROM users WHERE id = $admin_id";
$admin_res = $conn->query($admin_sql);
$admin = $admin_res->fetch_assoc();
$admin_name = $admin['full_name'] ?? 'Admin';
$admin_avatar = !empty($admin['profile_pic']) && file_exists("../uploads/profiles/".$admin['profile_pic']) 
    ? "../uploads/profiles/".$admin['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($admin_name) . "&background=6366F1&color=fff&size=128&bold=true";

// Fetch admin wallet balance
$wallet_sql = "SELECT * FROM admin_wallet WHERE id = 1";
$wallet_res = $conn->query($wallet_sql);
$wallet = $wallet_res->fetch_assoc();

// Calculate escrow balance (total held in escrow from bookings)
$escrow_sql = "SELECT SUM(calculated_fee) as escrow_total FROM bookings WHERE is_escrow = 1 AND escrow_status = 'held'";
$escrow_res = $conn->query($escrow_sql);
$escrow_total = $escrow_res->fetch_assoc()['escrow_total'] ?? 0;

// Calculate available for withdrawal (balance - pending payouts)
$pending_payouts_sql = "SELECT SUM(amount) as pending_total FROM withdrawals WHERE status = 'Pending'";
$pending_payouts_res = $conn->query($pending_payouts_sql);
$pending_payouts = $pending_payouts_res->fetch_assoc()['pending_total'] ?? 0;
$available_for_withdrawal = $wallet['balance'] - $pending_payouts;

// Fetch recent transactions
$transactions_sql = "SELECT wt.*, u.full_name 
                     FROM wallet_transactions wt
                     LEFT JOIN users u ON wt.user_id = u.id
                     WHERE wt.user_type = 'admin' OR wt.reference_type IS NOT NULL
                     ORDER BY wt.created_at DESC 
                     LIMIT 50";
$transactions = $conn->query($transactions_sql);

// Fetch pending withdrawals
$withdrawals_sql = "SELECT w.*, u.full_name, u.email, wp.wallet_balance,
                    wp.verification_status, u.profile_pic
                    FROM withdrawals w
                    JOIN users u ON w.worker_id = u.id
                    JOIN worker_profiles wp ON w.worker_id = wp.user_id
                    WHERE w.status = 'Pending'
                    ORDER BY w.request_date ASC";
$pending_withdrawals = $conn->query($withdrawals_sql);

// Handle withdrawal approval/rejection
if (isset($_POST['process_withdrawal'])) {
    $withdrawal_id = intval($_POST['withdrawal_id']);
    $action = $_POST['action'];
    $worker_id = intval($_POST['worker_id']);
    $amount = floatval($_POST['amount']);
    
    if ($action === 'approve') {
        // Update withdrawal status
        $conn->query("UPDATE withdrawals SET status = 'Approved' WHERE id = $withdrawal_id");
        
        // Update admin wallet - subtract the amount (since we're paying out)
        $new_balance = $wallet['balance'] - $amount;
        $new_withdrawn = $wallet['total_withdrawn'] + $amount;
        $conn->query("UPDATE admin_wallet SET balance = $new_balance, total_withdrawn = $new_withdrawn WHERE id = 1");
        
        // Record transaction
        $conn->query("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after) 
                     VALUES (1, 'admin', 'debit', $amount, $withdrawal_id, 'withdrawal', 'Withdrawal payout to worker #$worker_id', $new_balance)");
        
        $message = "Withdrawal approved and payment processed.";
    } else {
        // Reject - refund the money back to worker
        $conn->query("UPDATE withdrawals SET status = 'Rejected' WHERE id = $withdrawal_id");
        $conn->query("UPDATE worker_profiles SET wallet_balance = wallet_balance + $amount WHERE user_id = $worker_id");
        
        // Record refund transaction for worker
        $worker_balance_res = $conn->query("SELECT wallet_balance FROM worker_profiles WHERE user_id = $worker_id");
        $worker_balance = $worker_balance_res->fetch_assoc()['wallet_balance'];
        
        $conn->query("INSERT INTO wallet_transactions (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after) 
                     VALUES ($worker_id, 'worker', 'credit', $amount, $withdrawal_id, 'withdrawal', 'Withdrawal rejected - funds returned', $worker_balance)");
        
        $message = "Withdrawal rejected and funds returned to worker.";
    }
    
    // Refresh data
    header("Location: wallet.php?msg=" . urlencode($message));
    exit();
}

// Get current date
$current_date = date('M d, Y');

// Get revenue vs payouts data for chart (last 7 days)
$chart_sql = "SELECT 
                DATE_FORMAT(created_at, '%b %d') as date,
                SUM(CASE WHEN transaction_type = 'fee' THEN amount ELSE 0 END) as revenue,
                SUM(CASE WHEN transaction_type = 'debit' AND reference_type = 'withdrawal' THEN amount ELSE 0 END) as payout
              FROM wallet_transactions
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY DATE(created_at)
              ORDER BY created_at ASC
              LIMIT 7";
$chart_data = $conn->query($chart_sql);

$chart_dates = [];
$chart_revenues = [];
$chart_payouts = [];
$max_chart_value = 0;

while ($row = $chart_data->fetch_assoc()) {
    $chart_dates[] = $row['date'];
    $chart_revenues[] = $row['revenue'];
    $chart_payouts[] = $row['payout'];
    $max_chart_value = max($max_chart_value, $row['revenue'], $row['payout']);
}

if (empty($chart_dates)) {
    // Default data if no transactions
    $chart_dates = ['MAY 01', 'MAY 05', 'MAY 10', 'MAY 15', 'MAY 20', 'MAY 25', 'MAY 30'];
    $chart_revenues = [60, 80, 70, 90, 40, 75, 95];
    $chart_payouts = [30, 40, 50, 60, 20, 35, 55];
    $max_chart_value = 100;
}

// Helper function to get transaction icon and color (using Material Icons Round names)
function getTransactionIcon($type) {
    switch($type) {
        case 'fee': return ['loyalty', 'blue', 'Commission'];
        case 'debit': return ['payments', 'rose', 'Payout'];
        case 'credit': return ['upload', 'emerald', 'Top-up'];
        case 'refund': return ['assignment_return', 'purple', 'Refund'];
        case 'withdrawal': return ['account_balance', 'amber', 'Withdrawal'];
        default: return ['receipt', 'slate', 'Transaction'];
    }
}

// Helper function to get status badge
function getStatusBadge($type, $status = null) {
    if ($status === 'pending' || ($type === 'debit' && !$status)) {
        return ['Pending', 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'];
    } elseif ($status === 'approved' || $type === 'fee' || $type === 'credit') {
        return ['Settled', 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'];
    } elseif ($status === 'rejected') {
        return ['Failed', 'text-rose-600 bg-rose-50 dark:bg-rose-500/10'];
    }
    return ['Completed', 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'];
}

// Helper function to get time elapsed
function time_elapsed_string($datetime, $full = false) {
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $timezone);
    $ago = new DateTime($datetime, new DateTimeZone('UTC'));
    $ago->setTimezone($timezone);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Helper function to get user avatar
function getUserAvatar($pic, $name) {
    if (!empty($pic) && file_exists("../uploads/profiles/".$pic)) {
        return "../uploads/profiles/".$pic;
    }
    return "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=6366F1&color=fff&size=128&bold=true";
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto Admin - Wallet Dashboard</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts - Using Material Icons Round to match navbar -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#3B82F6",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        accent: "#8B5CF6",
                    },
                    fontFamily: {
                        display: ["Inter", "sans-serif"],
                        sans: ["Inter", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        'xl': '16px',
                        '2xl': '24px',
                        '3xl': '32px',
                    },
                },
            },
        };
        
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
        }
        
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-sidebar');
            const overlay = document.getElementById('menu-overlay');
            menu.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
        
        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    
    <style type="text/tailwindcss">
        @layer base {
            body { font-family: 'Inter', sans-serif; }
        }
        .glass {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.05);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .chart-bar {
            @apply rounded-t-full transition-all duration-500;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 0px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        /* Material Icons Round styling */
        .material-icons-round {
            font-family: 'Material Icons Round';
            font-weight: normal;
            font-style: normal;
            line-height: 1;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
            font-feature-settings: 'liga';
        }
    </style>
</head>
<body class="bg-[#F8FAFC] dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<!-- Mobile Menu Overlay -->
<div class="fixed inset-0 bg-slate-900/50 z-[60] hidden transition-opacity" id="menu-overlay" onclick="toggleMobileMenu()"></div>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-6 md:p-12 relative">
    
    <!-- Success Message -->
    <?php if (isset($_GET['msg'])): ?>
    <div class="mb-6 p-4 rounded-xl bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 border border-green-200 dark:border-green-800 flex items-center justify-between">
        <span><?php echo htmlspecialchars($_GET['msg']); ?></span>
        <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-slate-700">
            <span class="material-icons-round text-sm">close</span>
        </button>
    </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header class="flex flex-col lg:flex-row lg:items-center justify-between mb-10 gap-6">
        <div>
            <div class="flex items-center gap-4 mb-1">
                <button class="lg:hidden p-2 glass rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                    <span class="material-icons-round">menu</span>
                </button>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Admin Wallet</h1>
            </div>
            <p class="text-slate-500 dark:text-slate-400 font-medium tracking-[0.05em] uppercase text-xs">Platform Treasury & Financial Control</p>
        </div>
        <div class="flex items-center gap-4">
            <button class="glass p-2.5 rounded-2xl text-slate-500 hover:text-primary transition-all" onclick="toggleDarkMode()">
                <span class="material-icons-round">dark_mode</span>
            </button>
            <a href="withdrawals.php" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-2xl text-sm font-bold shadow-xl shadow-primary/25 transition-all flex items-center gap-2">
                <span class="material-icons-round text-xl">account_balance</span>
                Withdrawal Requests
                <?php if ($pending_withdrawals && $pending_withdrawals->num_rows > 0): ?>
                <span class="bg-white text-primary text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold">
                    <?php echo $pending_withdrawals->num_rows; ?>
                </span>
                <?php endif; ?>
            </a>
        </div>
    </header>
    
    <!-- Main Balance Card -->
    <section class="mb-12">
        <div class="bg-gradient-to-br from-primary via-blue-600 to-accent p-8 md:p-12 rounded-[2.5rem] shadow-2xl shadow-primary/20 relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white/10 rounded-full blur-3xl -mr-20 -mt-20"></div>
            <div class="relative z-10">
                <p class="text-white/80 font-bold uppercase tracking-[0.2em] text-[10px] mb-2">Total Platform Balance</p>
                <h2 class="text-5xl md:text-6xl font-black text-white tracking-tight mb-12">₱<?php echo number_format($wallet['balance'], 2); ?></h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="glass p-6 rounded-3xl border-white/20">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="material-icons-round text-white/60">lock</span>
                            <span class="text-[10px] font-bold text-white/80 uppercase tracking-widest">Escrow Balance</span>
                        </div>
                        <p class="text-2xl font-bold text-white">₱<?php echo number_format($escrow_total, 2); ?></p>
                    </div>
                    <div class="glass p-6 rounded-3xl border-white/20">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="material-icons-round text-white/60">percent</span>
                            <span class="text-[10px] font-bold text-white/80 uppercase tracking-widest">Total Commission</span>
                        </div>
                        <p class="text-2xl font-bold text-white">₱<?php echo number_format($wallet['total_earned'], 2); ?></p>
                    </div>
                    <div class="glass p-6 rounded-3xl border-white/20">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="material-icons-round text-white/60">check_circle</span>
                            <span class="text-[10px] font-bold text-white/80 uppercase tracking-widest">Available for Withdrawal</span>
                        </div>
                        <p class="text-2xl font-bold text-white">₱<?php echo number_format($available_for_withdrawal, 2); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Pending Withdrawals Section -->
    <?php if ($pending_withdrawals && $pending_withdrawals->num_rows > 0): ?>
    <section class="mb-12">
        <div class="glass-card rounded-[2rem] overflow-hidden border border-amber-200 dark:border-amber-800/30">
            <div class="p-8 border-b border-amber-100 dark:border-amber-800/30 flex items-center justify-between bg-amber-50/30 dark:bg-amber-900/10">
                <h3 class="text-xl font-bold tracking-tight flex items-center gap-2">
                    <span class="material-icons-round text-amber-500">pending_actions</span>
                    Pending Withdrawal Requests
                </h3>
                <span class="px-4 py-1.5 glass rounded-full text-[10px] font-bold text-amber-600 bg-amber-50 dark:bg-amber-500/10">
                    <?php echo $pending_withdrawals->num_rows; ?> pending
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-slate-50/50 dark:bg-slate-800/20 text-left">
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Worker</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">GCash Number</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Amount</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Request Date</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Worker Balance</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php while ($row = $pending_withdrawals->fetch_assoc()): 
                            $avatar = getUserAvatar($row['profile_pic'], $row['full_name']);
                        ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo $avatar; ?>" alt="<?php echo htmlspecialchars($row['full_name']); ?>" class="w-10 h-10 rounded-xl object-cover">
                                    <div>
                                        <p class="text-sm font-bold"><?php echo htmlspecialchars($row['full_name']); ?></p>
                                        <p class="text-[10px] text-slate-400">ID: #<?php echo $row['worker_id']; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6 text-sm font-medium"><?php echo htmlspecialchars($row['gcash_number']); ?></td>
                            <td class="px-8 py-6">
                                <span class="text-rose-500 font-bold text-sm">-₱<?php echo number_format($row['amount'], 2); ?></span>
                            </td>
                            <td class="px-8 py-6 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo date('M d, Y h:i A', strtotime($row['request_date'])); ?>
                            </td>
                            <td class="px-8 py-6 text-sm">₱<?php echo number_format($row['wallet_balance'], 2); ?></td>
                            <td class="px-8 py-6">
                                <div class="flex gap-2">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="worker_id" value="<?php echo $row['worker_id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $row['amount']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" name="process_withdrawal" 
                                                class="px-3 py-1.5 text-xs font-bold bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-all"
                                                onclick="return confirm('Approve this withdrawal? This will deduct ₱<?php echo number_format($row['amount'], 2); ?> from admin wallet.')">
                                            Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="worker_id" value="<?php echo $row['worker_id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $row['amount']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" name="process_withdrawal" 
                                                class="px-3 py-1.5 text-xs font-bold bg-rose-500 text-white rounded-lg hover:bg-rose-600 transition-all"
                                                onclick="return confirm('Reject this withdrawal? Funds will be returned to worker.')">
                                            Reject
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>
    
    <!-- Revenue vs Payouts Chart -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 mb-12">
        <div class="xl:col-span-12 glass-card p-8 rounded-3xl">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10">
                <div>
                    <h3 class="text-xl font-bold tracking-tight">Revenue vs Payouts</h3>
                    <p class="text-sm text-slate-400 font-medium">Last 30 days financial activity</p>
                </div>
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-primary"></div>
                        <span class="text-xs font-bold text-slate-500">Revenue</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 rounded-full bg-slate-200 dark:bg-slate-700"></div>
                        <span class="text-xs font-bold text-slate-500">Payouts</span>
                    </div>
                </div>
            </div>
            <div class="flex items-end justify-between h-48 gap-2 md:gap-4 overflow-x-auto pb-2 scrollbar-hide">
                <?php for ($i = 0; $i < min(7, count($chart_dates)); $i++): 
                    $revenue_percent = $max_chart_value > 0 ? ($chart_revenues[$i] / $max_chart_value) * 100 : 0;
                    $payout_percent = $max_chart_value > 0 ? ($chart_payouts[$i] / $max_chart_value) * 100 : 0;
                ?>
                <div class="flex flex-col items-center gap-2 min-w-[24px]">
                    <div class="flex gap-1 h-32 items-end">
                        <div class="w-2 md:w-3 bg-primary chart-bar" style="height: <?php echo $revenue_percent; ?>%"></div>
                        <div class="w-2 md:w-3 bg-slate-200 dark:bg-slate-700 chart-bar" style="height: <?php echo $payout_percent; ?>%"></div>
                    </div>
                    <span class="text-[9px] font-bold text-slate-400"><?php echo $chart_dates[$i]; ?></span>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Transactions -->
    <section class="mb-20">
        <div class="glass-card rounded-[2rem] overflow-hidden">
            <div class="p-8 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="text-xl font-bold tracking-tight">Recent Activity</h3>
                <a href="transactions.php" class="flex items-center gap-2 text-primary font-bold text-sm hover:underline">
                    View All
                    <span class="material-icons-round text-sm">arrow_forward</span>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-slate-50/50 dark:bg-slate-800/20 text-left">
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Type</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Reference/User</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Amount</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Status</th>
                            <th class="px-8 py-5 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($transactions && $transactions->num_rows > 0): ?>
                            <?php while ($tx = $transactions->fetch_assoc()): 
                                list($icon, $color, $type_label) = getTransactionIcon($tx['transaction_type']);
                                $is_positive = in_array($tx['transaction_type'], ['credit', 'fee']);
                                $amount_prefix = $is_positive ? '+' : '-';
                                $amount_class = $is_positive ? 'text-emerald-500' : 'text-rose-500';
                                list($status_text, $status_class) = getStatusBadge($tx['transaction_type'], $tx['status'] ?? null);
                            ?>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <td class="px-8 py-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-<?php echo $color; ?>-50 dark:bg-<?php echo $color; ?>-900/20 text-<?php echo $color; ?>-600 flex items-center justify-center">
                                            <span class="material-icons-round text-xl"><?php echo $icon; ?></span>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold"><?php echo $type_label; ?></p>
                                            <?php if ($tx['reference_id']): ?>
                                            <p class="text-[10px] text-slate-400 font-medium">Ref #<?php echo $tx['reference_id']; ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <p class="text-sm font-medium"><?php echo htmlspecialchars($tx['full_name'] ?? ($tx['user_type'] == 'admin' ? 'Admin' : 'System')); ?></p>
                                    <?php if ($tx['description']): ?>
                                    <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars(substr($tx['description'], 0, 40)) . (strlen($tx['description'] ?? '') > 40 ? '...' : ''); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-8 py-6">
                                    <span class="<?php echo $amount_class; ?> font-bold text-sm">
                                        <?php echo $amount_prefix; ?>₱<?php echo number_format($tx['amount'], 2); ?>
                                    </span>
                                </td>
                                <td class="px-8 py-6">
                                    <span class="px-4 py-1.5 glass rounded-full text-[10px] font-bold <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="px-8 py-6 text-xs text-slate-400 font-medium">
                                    <?php echo time_elapsed_string($tx['created_at']); ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-8 py-12 text-center text-slate-400">
                                    <span class="material-icons-round text-4xl mb-2 block">receipt</span>
                                    No transactions found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-8 bg-slate-50/30 dark:bg-slate-800/10 flex justify-center border-t border-slate-100 dark:border-slate-800">
                <a href="transactions.php" class="text-sm font-bold text-slate-500 hover:text-primary transition-all">Load More History</a>
            </div>
        </div>
    </section>
</main>

<!-- Mobile FAB -->
<button class="fixed bottom-8 right-8 w-14 h-14 bg-primary text-white rounded-full shadow-2xl flex items-center justify-center hover:scale-110 active:scale-95 transition-all z-50 lg:hidden" onclick="toggleMobileMenu()">
    <span class="material-icons-round text-2xl">account_balance_wallet</span>
</button>

</body>
</html>