<?php
// admin/payouts.php
include '../db.php';

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

// Handle payout status update
if (isset($_POST['update_payout']) && isset($_POST['withdrawal_id']) && isset($_POST['status'])) {
    $withdrawal_id = intval($_POST['withdrawal_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $worker_id = intval($_POST['worker_id']);
    
    // Update withdrawal status
    $conn->query("UPDATE withdrawals SET status='$status' WHERE id=$withdrawal_id");
    
    // If status is completed, deduct from worker wallet (already deducted when requested, but just in case)
    if ($status == 'Completed') {
        // You might want to add logic here for marking as completed
    }
    
    // Send notification to worker
    $msg = $status == 'Completed' 
        ? "✅ Your withdrawal request of ₱" . number_format($_POST['amount'], 2) . " has been processed and sent to your GCash account."
        : "⚠️ Your withdrawal request has been " . strtolower($status) . ". Please contact support for details.";
    
    $safe_msg = $conn->real_escape_string($msg);
    $conn->query("INSERT INTO notifications (user_id, message, link) VALUES ('$worker_id', '$safe_msg', '../worker/wallet.php')");
    
    header("Location: payouts.php?msg=updated");
    exit();
}

// Get statistics
// Total Payouts Processed (sum of all completed withdrawals)
$total_payouts_sql = "SELECT SUM(amount) as total FROM withdrawals WHERE status='Completed'";
$total_payouts_res = $conn->query($total_payouts_sql);
$total_payouts = $total_payouts_res->fetch_assoc()['total'] ?? 0;

// Pending withdrawal amount and count
$pending_sql = "SELECT SUM(amount) as total, COUNT(*) as count FROM withdrawals WHERE status='Pending'";
$pending_res = $conn->query($pending_sql);
$pending_data = $pending_res->fetch_assoc();
$pending_amount = $pending_data['total'] ?? 0;
$pending_count = $pending_data['count'] ?? 0;

// Weekly payout trend (last 7 days)
$weekly_sql = "SELECT SUM(amount) as total FROM withdrawals 
               WHERE status='Completed' 
               AND request_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$weekly_res = $conn->query($weekly_sql);
$weekly_total = $weekly_res->fetch_assoc()['total'] ?? 0;

// Get previous week for comparison
$prev_week_sql = "SELECT SUM(amount) as total FROM withdrawals 
                  WHERE status='Completed' 
                  AND request_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                  AND request_date < DATE_SUB(NOW(), INTERVAL 7 DAY)";
$prev_week_res = $conn->query($prev_week_sql);
$prev_week_total = $prev_week_res->fetch_assoc()['total'] ?? 0;

$weekly_growth = $prev_week_total > 0 ? round((($weekly_total - $prev_week_total) / $prev_week_total) * 100, 1) : 0;

// Fetch all withdrawal requests with worker details
$sql = "SELECT w.*, 
               u.full_name, 
               u.email,
               u.profile_pic,
               u.municipality,
               wp.wallet_balance,
               wp.verification_status
        FROM withdrawals w
        JOIN users u ON w.worker_id = u.id
        LEFT JOIN worker_profiles wp ON u.id = wp.user_id
        ORDER BY 
            CASE 
                WHEN w.status = 'Pending' THEN 1
                WHEN w.status = 'Processing' THEN 2
                ELSE 3
            END,
            w.request_date DESC";
$withdrawals = $conn->query($sql);

// Get current date
$current_date = date('M d, Y');

// Helper function to get user avatar
function getUserAvatar($pic, $name) {
    if (!empty($pic) && file_exists("../uploads/profiles/".$pic)) {
        return "../uploads/profiles/".$pic;
    }
    return "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=6366F1&color=fff&size=128&bold=true";
}

// Helper function to format GCash number
function formatGcashNumber($number) {
    if (empty($number)) return 'N/A';
    return substr($number, 0, 4) . ' ' . substr($number, 4, 3) . ' ' . substr($number, 7);
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto Admin - Payout Management</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts -->
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
        .status-badge-pending {
            background: rgba(251, 191, 36, 0.15);
            backdrop-filter: blur(4px);
            @apply text-amber-600 dark:text-amber-400 border border-amber-200/30;
        }
        .status-badge-processing {
            background: rgba(59, 130, 246, 0.15);
            backdrop-filter: blur(4px);
            @apply text-blue-600 dark:text-blue-400 border border-blue-200/30;
        }
        .status-badge-completed {
            background: rgba(16, 185, 129, 0.15);
            backdrop-filter: blur(4px);
            @apply text-emerald-600 dark:text-emerald-400 border border-emerald-200/30;
        }
        .sparkline {
            height: 40px;
            width: 100%;
            background: linear-gradient(90deg, transparent 0%, rgba(59, 130, 246, 0.1) 50%, transparent 100%);
            mask-image: radial-gradient(circle at 50% 50%, black 20%, transparent 80%);
        }
        .tight-tracking {
            letter-spacing: 0.025em;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
    </style>
</head>
<body class="bg-[#F8FAFC] dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<!-- Main Content -->
<main class="lg:ml-72 min-h-screen p-6 md:p-12">
    
    <!-- Header -->
    <header class="flex flex-col lg:flex-row lg:items-end justify-between mb-12 gap-6">
        <div>
            <div class="flex items-center gap-4 mb-2">
                <button class="lg:hidden p-2 glass rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                    <span class="material-icons-round">menu</span>
                </button>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Payout Management</h1>
            </div>
            <p class="text-slate-500 dark:text-slate-400 font-medium tracking-wide">Review and process worker withdrawal requests</p>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <div class="glass relative flex items-center px-4 py-2.5 rounded-2xl w-full sm:w-80 group focus-within:border-primary/50 transition-all">
                <span class="material-icons-round text-slate-400 text-xl">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-sm w-full placeholder:text-slate-400 font-medium ml-2" placeholder="Search by name or ID..." type="text" id="searchInput" onkeyup="filterTable()"/>
            </div>
            <div class="flex items-center gap-2">
                <div class="glass flex items-center gap-2 px-4 py-2.5 rounded-2xl cursor-pointer hover:bg-white dark:hover:bg-slate-800 transition-all">
                    <span class="material-icons-round text-slate-400 text-lg">filter_list</span>
                    <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">Filters</span>
                </div>
                <button class="glass p-2.5 rounded-2xl text-slate-500 hover:text-primary transition-all" onclick="toggleDarkMode()">
                    <span class="material-icons-round">dark_mode</span>
                </button>
            </div>
        </div>
    </header>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
        <div class="glass-card p-6 rounded-3xl relative overflow-hidden group">
            <div class="relative z-10">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-4">Total Payouts Processed</p>
                <div class="flex items-end gap-3">
                    <h2 class="text-3xl font-bold tracking-tight">₱<?php echo number_format($total_payouts, 2); ?></h2>
                    <span class="text-xs font-bold <?php echo $weekly_growth >= 0 ? 'text-emerald-500' : 'text-red-500'; ?> bg-<?php echo $weekly_growth >= 0 ? 'emerald' : 'red'; ?>-500/10 px-2 py-0.5 rounded-lg mb-1 flex items-center gap-1">
                        <span class="material-icons-round text-sm"><?php echo $weekly_growth >= 0 ? 'trending_up' : 'trending_down'; ?></span> 
                        <?php echo abs($weekly_growth); ?>%
                    </span>
                </div>
            </div>
            <div class="absolute bottom-0 left-0 w-full h-12 flex items-end">
                <div class="sparkline bg-gradient-to-t from-primary/5 to-transparent"></div>
            </div>
        </div>
        
        <div class="glass-card p-6 rounded-3xl relative overflow-hidden">
            <div class="relative z-10">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-4">Pending Withdrawal Amount</p>
                <div class="flex items-end gap-3">
                    <h2 class="text-3xl font-bold tracking-tight">₱<?php echo number_format($pending_amount, 2); ?></h2>
                    <span class="text-[11px] font-bold text-amber-500 bg-amber-500/10 px-2 py-0.5 rounded-lg mb-1"><?php echo $pending_count; ?> Requests</span>
                </div>
            </div>
            <div class="absolute bottom-0 left-0 w-full h-12 opacity-50">
                <div class="sparkline bg-gradient-to-t from-amber-500/5 to-transparent"></div>
            </div>
        </div>
        
        <div class="glass-card p-6 rounded-3xl relative overflow-hidden">
            <div class="relative z-10">
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.15em] mb-4">Weekly Payout Trend</p>
                <div class="flex items-end gap-3">
                    <h2 class="text-3xl font-bold tracking-tight">₱<?php echo number_format($weekly_total, 2); ?></h2>
                    <span class="text-[11px] font-bold text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-lg mb-1">Last 7 Days</span>
                </div>
            </div>
            <div class="absolute bottom-0 left-0 w-full h-12">
                <div class="sparkline bg-gradient-to-t from-blue-500/10 to-transparent"></div>
            </div>
        </div>
    </div>
    
    <!-- Payouts Table -->
    <div class="bg-white dark:bg-slate-900/50 rounded-[32px] border border-slate-100 dark:border-slate-800/50 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse" id="payoutsTable">
                <thead>
                    <tr class="border-b border-slate-50 dark:border-slate-800">
                        <th class="px-8 py-6 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">Worker Profile</th>
                        <th class="px-8 py-6 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">Payout Details</th>
                        <th class="px-8 py-6 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">Request Time</th>
                        <th class="px-8 py-6 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em]">Status</th>
                        <th class="px-8 py-6 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                    <?php if ($withdrawals && $withdrawals->num_rows > 0): ?>
                        <?php while($row = $withdrawals->fetch_assoc()): 
                            $avatar = getUserAvatar($row['profile_pic'], $row['full_name']);
                            $worker_id_display = "#W-" . str_pad($row['worker_id'], 4, '0', STR_PAD_LEFT);
                            $request_date = date("M d, Y", strtotime($row['request_date']));
                            $request_time = date("h:i A", strtotime($row['request_date']));
                            
                            // Determine status styling
                            $status_class = '';
                            $status_text = $row['status'];
                            switch(strtolower($row['status'])) {
                                case 'pending':
                                    $status_class = 'status-badge-pending';
                                    break;
                                case 'processing':
                                    $status_class = 'status-badge-processing';
                                    break;
                                case 'completed':
                                    $status_class = 'status-badge-completed';
                                    break;
                                default:
                                    $status_class = 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400';
                            }
                            
                            // Payment method (default to GCash for now)
                            $payment_method = 'GCash Transfer';
                            $payment_color = 'bg-blue-500';
                        ?>
                        <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-all">
                            <td class="px-8 py-6">
                                <div class="flex items-center gap-4">
                                    <img alt="<?php echo htmlspecialchars($row['full_name']); ?>" 
                                         class="w-11 h-11 rounded-full object-cover ring-2 ring-slate-100 dark:ring-slate-800" 
                                         src="<?php echo $avatar; ?>"/>
                                    <div>
                                        <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($row['full_name']); ?></p>
                                        <p class="text-[11px] font-medium text-slate-400">ID: <?php echo $worker_id_display; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <div>
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">₱<?php echo number_format($row['amount'], 2); ?></p>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="w-2 h-2 rounded-full <?php echo $payment_color; ?>"></span>
                                        <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider"><?php echo $payment_method; ?></p>
                                    </div>
                                    <?php if (!empty($row['gcash_number'])): ?>
                                        <p class="text-[10px] text-slate-400 mt-1"><?php echo formatGcashNumber($row['gcash_number']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-8 py-6">
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-300"><?php echo $request_date; ?></p>
                                <p class="text-[11px] text-slate-400 mt-0.5"><?php echo $request_time; ?></p>
                            </td>
                            <td class="px-8 py-6">
                                <span class="<?php echo $status_class; ?> px-3 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <?php if ($row['status'] == 'Pending'): ?>
                                    <form method="POST" class="flex items-center justify-end gap-3" onsubmit="return confirm('Process this payout request?')">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="worker_id" value="<?php echo $row['worker_id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $row['amount']; ?>">
                                        
                                        <button type="button" onclick="showDetails(<?php echo $row['id']; ?>)" 
                                                class="text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-all">
                                            View Details
                                        </button>
                                        
                                        <select name="status" class="text-xs rounded-xl border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-2 py-2 mr-2">
                                            <option value="Processing">Mark as Processing</option>
                                            <option value="Completed">Mark as Completed</option>
                                            <option value="Failed">Mark as Failed</option>
                                        </select>
                                        
                                        <button type="submit" name="update_payout" 
                                                class="bg-gradient-to-r from-primary to-blue-600 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg shadow-primary/20 hover:scale-[1.02] transition-all">
                                            Update Status
                                        </button>
                                    </form>
                                <?php elseif ($row['status'] == 'Processing'): ?>
                                    <div class="flex items-center justify-end gap-3">
                                        <button type="button" onclick="showDetails(<?php echo $row['id']; ?>)" 
                                                class="text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-all">
                                            View Details
                                        </button>
                                        <span class="bg-slate-100 dark:bg-slate-800 text-slate-400 px-5 py-2.5 rounded-xl text-xs font-bold cursor-not-allowed">
                                            Processing
                                        </span>
                                    </div>
                                <?php elseif ($row['status'] == 'Completed'): ?>
                                    <div class="flex items-center justify-end gap-3">
                                        <button type="button" onclick="showReceipt(<?php echo $row['id']; ?>)" 
                                                class="text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-all">
                                            View Receipt
                                        </button>
                                        <span class="bg-emerald-50 dark:bg-emerald-900/10 text-emerald-600 dark:text-emerald-400 px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase">
                                            Success
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="flex items-center justify-end gap-3">
                                        <button type="button" onclick="showDetails(<?php echo $row['id']; ?>)" 
                                                class="text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-all">
                                            View Details
                                        </button>
                                        <span class="bg-red-50 dark:bg-red-900/10 text-red-600 dark:text-red-400 px-5 py-2.5 rounded-xl text-[10px] font-bold uppercase">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-8 py-12 text-center text-slate-400">
                                No withdrawal requests found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Table Footer -->
        <div class="px-8 py-6 flex items-center justify-between border-t border-slate-50 dark:border-slate-800">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">
                Showing <?php echo $withdrawals ? $withdrawals->num_rows : 0; ?> of <?php echo $total_payouts_sql; ?> entries
            </p>
            <div class="flex items-center gap-2">
                <button class="p-2 glass rounded-lg text-slate-400 hover:text-primary transition-all">
                    <span class="material-icons-round text-lg">chevron_left</span>
                </button>
                <button class="w-8 h-8 flex items-center justify-center bg-primary text-white text-xs font-bold rounded-lg shadow-lg shadow-primary/20">1</button>
                <?php if (($withdrawals ? $withdrawals->num_rows : 0) > 10): ?>
                <button class="w-8 h-8 flex items-center justify-center text-xs font-bold text-slate-400 hover:text-slate-600 dark:hover:text-white transition-all">2</button>
                <?php endif; ?>
                <button class="p-2 glass rounded-lg text-slate-400 hover:text-primary transition-all">
                    <span class="material-icons-round text-lg">chevron_right</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Success Message -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
    <div class="fixed bottom-24 right-6 bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700 text-green-800 dark:text-green-300 px-6 py-3 rounded-xl shadow-lg flex items-center gap-3 z-50">
        <span class="material-icons-round text-green-600 dark:text-green-400">check_circle</span>
        <span class="font-medium">Payout status updated successfully.</span>
    </div>
    
    <script>
        setTimeout(() => {
            const msg = document.querySelector('.fixed.bottom-24.right-6');
            if (msg) msg.remove();
        }, 3000);
    </script>
    <?php endif; ?>
    
</main>

<script>
    // Simple table filtering
    function filterTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toUpperCase();
        const table = document.getElementById('payoutsTable');
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let i = 0; i < rows.length; i++) {
            const nameCell = rows[i].getElementsByTagName('td')[0];
            if (nameCell) {
                const nameText = nameCell.textContent || nameCell.innerText;
                if (nameText.toUpperCase().indexOf(filter) > -1) {
                    rows[i].style.display = '';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        }
    }
    
    // Placeholder functions for view details/receipt
    function showDetails(withdrawalId) {
        alert('View details for withdrawal #' + withdrawalId + ' - This would show transaction details in a modal.');
    }
    
    function showReceipt(withdrawalId) {
        alert('View receipt for withdrawal #' + withdrawalId + ' - This would show payment receipt in a modal.');
    }
</script>

</body>
</html>