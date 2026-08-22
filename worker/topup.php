<?php
// worker/topup.php
// Worker wallet top-up page

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

// Get current wallet info
$wallet_info = $wallet->getWorkerWallet($worker_id);

// Handle top-up form submission
if (isset($_POST['topup_btn'])) {
    $amount = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    
    if ($amount < MIN_TOPUP) {
        $error = "Minimum top-up is ₱" . MIN_TOPUP;
    } elseif ($amount > MAX_TOPUP) {
        $error = "Maximum top-up is ₱" . MAX_TOPUP;
    } else {
        // Only GCash payment method is supported
        if ($payment_method == 'gcash') {
            // Redirect to GCash payment
            header("Location: process_topup_paymongo.php?amount=$amount");
            exit();
        } else {
            $error = "Invalid payment method selected.";
        }
    }
}

// Get recent top-ups
$topups_sql = "SELECT * FROM top_ups
               WHERE worker_id = ?
               ORDER BY created_at DESC
               LIMIT 5";
$topups_stmt = $conn->prepare($topups_sql);
$topups_stmt->execute([$worker_id]);
$topups = $topups_stmt->fetchAll();
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Top Up Wallet | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
    <!-- Font Awesome (for backward compatibility) -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#10B981", 
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        brand: {
                            purple: "#6366F1",
                            blue: "#146af5",
                        }
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        'xl': "16px",
                        '2xl': "24px",
                    },
                },
            },
        };
    </script>
    
    <style type="text/tailwindcss">
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .gradient-header {
            background: linear-gradient(135deg, #6366F1 0%, #146af5 100%);
        }
        .gradient-green {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        }
        .custom-shadow {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 48;
        }
        
        /* Animation */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-slideUp {
            animation: slideUp 0.5s ease forwards;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark min-h-screen transition-colors duration-300">

<?php include '../includes/navbar.php'; ?>

<div class="max-w-6xl mx-auto px-4 py-6 space-y-6 animate-slideUp">
    
    <!-- Page Header -->
    <header class="gradient-header p-6 rounded-2xl text-white shadow-xl flex flex-col md:flex-row justify-between items-center relative overflow-hidden">
        <div class="relative z-10 flex items-center gap-4 w-full">
            <div class="bg-white/20 p-2.5 rounded-xl backdrop-blur-md">
                <span class="material-symbols-outlined text-2xl block">account_balance_wallet</span>
            </div>
            <div>
                <h1 class="text-xl font-bold tracking-tight">Top Up Wallet</h1>
                <p class="text-blue-100 text-sm opacity-90 hidden sm:block">Add funds to unlock more jobs and pay booking fees.</p>
            </div>
        </div>
        <div class="absolute -right-8 -top-8 w-32 h-32 bg-white opacity-10 rounded-full blur-2xl"></div>
    </header>
    
    <!-- Wallet Summary Cards -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Main Balance -->
        <div class="gradient-green p-5 rounded-2xl text-white custom-shadow transform transition hover:scale-[1.01]">
            <p class="text-[10px] font-bold opacity-80 uppercase tracking-widest mb-1">Available Balance</p>
            <div class="text-3xl font-bold mb-2">₱<?php echo number_format($wallet_info['wallet_balance'], 2); ?></div>
            <div class="flex items-center gap-1.5 text-xs opacity-90">
                <span class="material-symbols-outlined text-sm">check_circle</span>
                Ready for use
            </div>
        </div>
        
        <!-- Free Credits -->
        <div class="glass p-5 rounded-2xl custom-shadow flex flex-col justify-center">
            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1">Free Credits</p>
            <div class="text-3xl font-bold text-amber-500 dark:text-amber-400">₱<?php echo number_format($wallet_info['free_credits'], 2); ?></div>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">For cash booking fees</p>
        </div>
        
        <!-- Free Bookings Used -->
        <div class="glass p-5 rounded-2xl custom-shadow flex flex-col justify-center">
            <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1">Free Bookings Used</p>
            <div class="text-3xl font-bold text-slate-800 dark:text-slate-100"><?php echo $wallet_info['free_bookings_used']; ?> / <?php echo FREE_BOOKINGS_LIMIT; ?></div>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">First 3 cash bookings</p>
        </div>
    </section>
    
    <!-- Free Credits Alert -->
    <?php if ($wallet_info['free_credits'] > 0): ?>
    <div class="bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 p-4 rounded-xl flex justify-between items-center">
        <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-amber-500">card_giftcard</span>
            <div>
                <h4 class="font-bold text-sm text-slate-800 dark:text-slate-200">You have free credits!</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Use them for cash booking fees (₱30 per booking)</p>
            </div>
        </div>
        <div class="text-lg font-bold text-amber-500">₱<?php echo number_format($wallet_info['free_credits'], 2); ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Error Alert -->
    <?php if (isset($error)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500 p-4 rounded-xl flex items-center gap-3">
        <span class="material-symbols-outlined text-red-500">error</span>
        <p class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo $error; ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Success Alert -->
    <?php if (isset($success)): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 p-4 rounded-xl flex items-center gap-3">
        <span class="material-symbols-outlined text-green-500">check_circle</span>
        <p class="text-sm font-medium text-green-700 dark:text-green-400"><?php echo $success; ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Top-Up Form -->
    <section class="bg-white dark:bg-slate-800/50 rounded-2xl p-6 custom-shadow border border-slate-100 dark:border-slate-700">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                <span class="material-symbols-outlined text-primary text-xl">add_circle</span>
            </div>
            <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100">Add Funds</h2>
        </div>
        
        <form method="POST" id="topupForm">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column - Amount Selection -->
                <div>
                    <div class="mb-6">
                        <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-3 uppercase tracking-widest">Select Amount</p>
                        <div class="grid grid-cols-3 gap-3">
                            <button type="button" class="preset-btn py-3 px-4 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary dark:hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all hover:bg-primary/5 active:scale-95 text-sm" onclick="setAmount(100)">₱100</button>
                            <button type="button" class="preset-btn py-3 px-4 rounded-xl border-2 border-primary bg-primary/5 text-primary font-bold transition-all active:scale-95 text-sm" onclick="setAmount(200)">₱200</button>
                            <button type="button" class="preset-btn py-3 px-4 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary dark:hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all hover:bg-primary/5 active:scale-95 text-sm" onclick="setAmount(500)">₱500</button>
                            <button type="button" class="preset-btn py-3 px-4 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary dark:hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all hover:bg-primary/5 active:scale-95 text-sm" onclick="setAmount(1000)">₱1,000</button>
                            <button type="button" class="preset-btn py-3 px-4 rounded-xl border-2 border-slate-100 dark:border-slate-700 hover:border-primary dark:hover:border-primary text-slate-700 dark:text-slate-200 font-bold transition-all hover:bg-primary/5 active:scale-95 text-sm col-span-2" onclick="customAmount()">Custom</button>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-2 uppercase tracking-widest">Enter Amount (₱)</label>
                        <input type="number" 
                               name="amount" 
                               id="amountInput" 
                               class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-xl py-3 px-5 text-xl font-bold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-primary/20 focus:bg-white dark:focus:bg-slate-900 transition-all outline-none" 
                               value="200" 
                               min="<?php echo MIN_TOPUP; ?>" 
                               max="<?php echo MAX_TOPUP; ?>" 
                               step="1" 
                               required>
                    </div>
                    
                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        <span class="material-symbols-outlined text-xs align-middle">info</span>
                        Min: ₱<?php echo MIN_TOPUP; ?> | Max: ₱<?php echo MAX_TOPUP; ?>
                    </p>
                </div>
                
                <!-- Right Column - Payment Method (GCash Only) -->
                <div class="flex flex-col justify-between">
                    <div class="mb-6">
                        <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-3 uppercase tracking-widest">Payment Method</p>
                        <div class="grid grid-cols-1 gap-4">
                            <!-- GCash Only -->
                            <label class="relative block cursor-pointer group">
                                <input type="radio" name="payment_method" value="gcash" class="sr-only peer" checked>
                                <div class="p-4 rounded-xl border-2 border-primary bg-primary/5 transition-all flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-primary">smartphone</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-800 dark:text-slate-100">GCash</p>
                                        <p class="text-xs text-primary font-medium">Processing: Instant</p>
                                    </div>
                                    <div class="ml-auto">
                                        <span class="material-symbols-outlined text-primary">check_circle</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <input type="hidden" name="payment_method" id="paymentMethodInput" value="gcash">
                    
                    <button type="submit" name="topup_btn" id="submitBtn" class="w-full gradient-green text-white py-4 rounded-xl text-md font-bold shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 transition-all flex items-center justify-center gap-2 transform active:scale-[0.98]">
                        Proceed to Payment
                        <span class="material-symbols-outlined text-xl">arrow_forward</span>
                    </button>
                </div>
            </div>
        </form>
    </section>
    
    <!-- Recent Top-Ups -->
    <?php if ($topups && count($topups) > 0): ?>
    <section class="bg-white dark:bg-slate-800/50 rounded-2xl p-6 custom-shadow border border-slate-100 dark:border-slate-700">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-blue-500/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-500 text-xl">history</span>
                </div>
                <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100">Recent Top-Ups</h2>
            </div>
            <a href="wallet.php" class="text-primary font-bold text-xs hover:underline flex items-center gap-1">
                View All <span class="material-symbols-outlined text-xs">arrow_outward</span>
            </a>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-slate-400 dark:text-slate-500 uppercase text-[10px] font-bold tracking-widest border-b border-slate-100 dark:border-slate-700">
                        <th class="pb-3">Date</th>
                        <th class="pb-3 text-right pr-4">Amount</th>
                        <th class="pb-3">Reference</th>
                        <th class="pb-3 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800">
                    <?php foreach ($topups as $topup): ?>
                    <tr class="group hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <td class="py-4 text-slate-600 dark:text-slate-400 text-xs font-medium">
                            <?php echo date('M d, Y, h:i A', strtotime($topup['created_at'])); ?>
                        </td>
                        <td class="py-4 font-bold text-slate-800 dark:text-slate-100 text-md text-right pr-4">
                            ₱<?php echo number_format($topup['amount'], 2); ?>
                        </td>
                        <td class="py-4 font-mono text-[10px] text-slate-400">
                            <?php echo $topup['reference_number'] ?? 'N/A'; ?>
                        </td>
                        <td class="py-4 flex justify-end">
                            <?php
                            $status = $topup['status'];
                            $statusClass = '';
                            $statusIcon = '';
                            
                            if ($status == 'completed') {
                                $statusClass = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
                                $statusIcon = 'check_circle';
                            } elseif ($status == 'pending') {
                                $statusClass = 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
                                $statusIcon = 'pending';
                            } else {
                                $statusClass = 'bg-red-500/10 text-red-600 dark:text-red-400';
                                $statusIcon = 'error';
                            }
                            ?>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $statusClass; ?> flex items-center gap-1 w-fit">
                                <span class="material-symbols-outlined text-xs"><?php echo $statusIcon; ?></span>
                                <?php echo ucfirst($status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<!-- Dark Mode Toggle -->
<button class="fixed bottom-6 right-6 w-10 h-10 rounded-full bg-white dark:bg-slate-700 shadow-xl flex items-center justify-center text-slate-600 dark:text-slate-300 hover:scale-110 transition-all z-50 border border-slate-100 dark:border-slate-600" onclick="document.documentElement.classList.toggle('dark')">
    <span class="material-symbols-outlined dark:hidden">dark_mode</span>
    <span class="material-symbols-outlined hidden dark:block">light_mode</span>
</button>

<script>
    // Set amount from preset
    function setAmount(amount) {
        document.getElementById('amountInput').value = amount;
        
        // Remove selected class from all preset buttons
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.classList.remove('border-primary', 'bg-primary/5', 'text-primary');
            btn.classList.add('border-slate-100', 'dark:border-slate-700', 'text-slate-700', 'dark:text-slate-200');
        });
        
        // Add selected class to clicked button
        event.target.classList.remove('border-slate-100', 'dark:border-slate-700', 'text-slate-700', 'dark:text-slate-200');
        event.target.classList.add('border-primary', 'bg-primary/5', 'text-primary');
    }
    
    function customAmount() {
        document.getElementById('amountInput').value = '';
        document.getElementById('amountInput').focus();
        
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.classList.remove('border-primary', 'bg-primary/5', 'text-primary');
            btn.classList.add('border-slate-100', 'dark:border-slate-700', 'text-slate-700', 'dark:text-slate-200');
        });
    }
    
    // Validate form before submit
    document.getElementById('topupForm').addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amountInput').value);
        const min = <?php echo MIN_TOPUP; ?>;
        const max = <?php echo MAX_TOPUP; ?>;
        
        if (isNaN(amount) || amount < min) {
            e.preventDefault();
            alert('Please enter an amount of at least ₱' + min);
        } else if (amount > max) {
            e.preventDefault();
            alert('Maximum top-up amount is ₱' + max);
        }
    });
    
    // Dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        document.documentElement.classList.add('dark');
    }
    
    // Save dark mode preference
    document.querySelector('[onclick="document.documentElement.classList.toggle(\'dark\')"]')?.addEventListener('click', function() {
        const isDark = document.documentElement.classList.contains('dark');
        localStorage.setItem('darkMode', isDark);
    });
</script>

</body>
</html>