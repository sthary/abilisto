<?php
// worker/complete_job.php
include '../db.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_functions.php';
include '../config/constants.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    header("Location: dashboard.php");
    exit();
}

// Get booking details
$sql = "SELECT b.*, u.full_name as client_name, u.fcm_token 
        FROM bookings b
        JOIN users u ON b.client_id = u.id
        WHERE b.id = ? AND b.worker_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $booking_id, $worker_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: dashboard.php");
    exit();
}

// Get worker's minimum standard rate
$rate_sql = "SELECT minimum_standard_rate FROM worker_profiles WHERE user_id = ?";
$rate_stmt = $conn->prepare($rate_sql);
$rate_stmt->bind_param("i", $worker_id);
$rate_stmt->execute();
$rate_row = $rate_stmt->get_result()->fetch_assoc();
$minimum_standard_rate = floatval($rate_row['minimum_standard_rate'] ?? 0);

// Platform commission rate — see config/constants.php (ADMIN_COMMISSION_PERCENT)
$COMMISSION_RATE = ADMIN_COMMISSION_PERCENT;

// Handle form submission
if (isset($_POST['submit_completion'])) {
    $labor_fee      = floatval($_POST['labor_fee'] ?? 0);
    $materials_cost = floatval($_POST['materials_cost'] ?? 0);

    $error = null;

    // Validation
    if ($labor_fee <= 0) {
        $error = "Please enter a valid Labor Fee.";
    } elseif ($minimum_standard_rate > 0 && $labor_fee < $minimum_standard_rate) {
        $error = "Labor Fee (₱" . number_format($labor_fee, 2) . ") cannot be lower than your Minimum Standard Rate of ₱" . number_format($minimum_standard_rate, 2) . ". Please enter an honest amount.";
    } elseif ($materials_cost < 0) {
        $error = "Materials cost cannot be negative.";
    }

    if (!$error) {
        $mobilization = floatval($booking['calculated_fee']);
        $labor_materials = $labor_fee + $materials_cost;

        // Total client pays depends on mobilization payment status
        if ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid') {
            $total = $labor_materials; // Mobilization already settled
        } else {
            $total = $mobilization + $labor_materials;
        }

        // 4% commission only on labor fee (not materials)
        $admin_fee = round($labor_fee * ($COMMISSION_RATE / 100), 2);
        $worker_gets = $total - $admin_fee;

        // Update booking with separated costs
        $update_sql = "UPDATE bookings SET 
                       labor_cost = ?,
                       materials_cost = ?,
                       labor_materials_cost = ?,
                       total_final_cost = ?,
                       admin_fee_amount = ?,
                       admin_fee_percentage = ?
                       WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ddddddi", $labor_fee, $materials_cost, $labor_materials, $total, $admin_fee, $COMMISSION_RATE, $booking_id);

        if ($stmt->execute()) {
            header("Location: generate_receipt.php?booking_id=$booking_id");
            exit();
        } else {
            $error = "Failed to save costs: " . $conn->error;
        }
    }
}

$mobilization_paid = ($booking['payment_method'] == 'Xendit' && $booking['payment_status'] == 'Paid');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Complete Job | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet"/>
    
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
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        '3xl': '24px',
                    },
                },
            },
        };
    </script>
    
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .inner-glass {
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .dark .inner-glass {
            background: rgba(15, 23, 42, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .glow-yellow {
            box-shadow: 0 0 15px rgba(234, 179, 8, 0.3);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeIn {
            animation: fadeIn 0.5s ease forwards;
        }
        /* Shake animation for validation error */
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%     { transform: translateX(-6px); }
            40%     { transform: translateX(6px); }
            60%     { transform: translateX(-4px); }
            80%     { transform: translateX(4px); }
        }
        .shake { animation: shake 0.4s ease; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<main class="w-full max-w-[560px] glass-card rounded-[32px] shadow-2xl overflow-hidden p-6 md:p-10 animate-fadeIn">
    
    <!-- Header -->
    <header class="text-center mb-10">
        <h1 class="font-display text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight">
            Complete Job
        </h1>
        <p class="text-slate-500 dark:text-slate-400 mt-2 text-sm font-medium">Finalize your work and generate invoice</p>
    </header>
    
    <?php if (isset($error)): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl flex items-center gap-3">
        <span class="material-symbols-outlined text-red-500">error</span>
        <p class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="space-y-6">
        
        <!-- Client Info Section -->
        <section class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 p-5 rounded-3xl border border-blue-100/50 dark:border-blue-500/10">
            <div class="flex items-start gap-4 mb-4">
                <div class="bg-white dark:bg-slate-800 p-2 rounded-xl shadow-sm">
                    <span class="material-symbols-outlined text-primary text-xl">person</span>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-blue-600/70 dark:text-blue-400/70 mb-0.5">Client</p>
                    <p class="text-slate-900 dark:text-slate-100 font-semibold text-lg">
                        <?php echo htmlspecialchars($booking['client_name']); ?>
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="bg-white dark:bg-slate-800 p-2 rounded-xl shadow-sm">
                    <span class="material-symbols-outlined text-primary text-xl">report_problem</span>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-blue-600/70 dark:text-blue-400/70 mb-0.5">Problem Reported</p>
                    <p class="text-slate-700 dark:text-slate-300 font-medium italic">
                        "<?php echo htmlspecialchars($booking['problem_desc']); ?>"
                    </p>
                </div>
            </div>
        </section>
        
        <!-- Mobilization Fee Status -->
        <div class="inner-glass p-4 rounded-2xl flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-slate-400 dark:text-slate-500">payments</span>
                <span class="text-slate-600 dark:text-slate-300 font-medium">
                    Mobilization Fee: <span class="text-slate-900 dark:text-white font-bold">₱<?php echo number_format($booking['calculated_fee'], 2); ?></span>
                </span>
            </div>
            <?php if ($mobilization_paid): ?>
                <span class="bg-emerald-500 text-white text-[11px] font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse"></span>
                    Paid via GCash
                </span>
            <?php else: ?>
                <span class="bg-yellow-400 text-yellow-900 text-[11px] font-extrabold uppercase px-3 py-1.5 rounded-full glow-yellow flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 bg-yellow-900 rounded-full animate-pulse"></span>
                    To be paid in cash
                </span>
            <?php endif; ?>
        </div>

        <!-- Minimum Standard Rate Notice -->
        <?php if ($minimum_standard_rate > 0): ?>
        <div class="inner-glass p-4 rounded-2xl flex items-center gap-3 border border-amber-200/60 dark:border-amber-700/30 bg-amber-50/60 dark:bg-amber-900/10">
            <div class="flex-shrink-0 w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-sm">shield_check</span>
            </div>
            <p class="text-xs text-slate-600 dark:text-slate-400 font-medium">
                Your <span class="text-amber-700 dark:text-amber-400 font-bold">Minimum Standard Rate</span> is 
                <span class="text-amber-700 dark:text-amber-400 font-bold">₱<?php echo number_format($minimum_standard_rate, 2); ?></span>. 
                Your Labor Fee must meet or exceed this amount.
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Cost Input Form -->
        <form method="POST" id="completionForm">
            <div class="space-y-4">

                <!-- ── Labor Fee ── -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 ml-1" for="labor_fee">
                        Labor Fee
                        <span class="ml-1 text-xs font-normal text-slate-400">(your service charge)</span>
                    </label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                            <span class="text-slate-400 dark:text-slate-500 font-semibold text-lg">₱</span>
                        </div>
                        <input type="number"
                               name="labor_fee"
                               id="labor_fee"
                               class="block w-full pl-10 pr-4 py-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white text-xl font-semibold rounded-3xl focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all placeholder:text-slate-300 dark:placeholder:text-slate-600"
                               placeholder="e.g. 500.00"
                               step="0.01"
                               min="0.01"
                               value="<?php echo isset($_POST['labor_fee']) ? htmlspecialchars($_POST['labor_fee']) : ''; ?>"
                               required>
                    </div>
                    <!-- Min rate inline hint -->
                    <div id="laborHint" class="text-xs px-1 hidden">
                        <!-- filled by JS -->
                    </div>
                </div>

                <!-- ── Materials Cost ── -->
                <div class="space-y-2">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 ml-1" for="materials_cost">
                        Materials Cost
                        <span class="ml-1 text-xs font-normal text-slate-400">(parts / supplies purchased)</span>
                    </label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                            <span class="text-slate-400 dark:text-slate-500 font-semibold text-lg">₱</span>
                        </div>
                        <input type="number"
                               name="materials_cost"
                               id="materials_cost"
                               class="block w-full pl-10 pr-4 py-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white text-xl font-semibold rounded-3xl focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all placeholder:text-slate-300 dark:placeholder:text-slate-600"
                               placeholder="0.00 (if none)"
                               step="0.01"
                               min="0"
                               value="<?php echo isset($_POST['materials_cost']) ? htmlspecialchars($_POST['materials_cost']) : ''; ?>">
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500 px-1">Leave at ₱0.00 if no materials were used.</p>
                </div>
            </div>

            <!-- ── GCash Honesty Notice ── -->
            <div class="mt-5 p-4 rounded-2xl border border-blue-200/70 dark:border-blue-700/30 bg-blue-50/60 dark:bg-blue-900/10 flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-500 text-lg flex-shrink-0 mt-0.5">tips_and_updates</span>
                <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                    <span class="font-bold text-blue-600 dark:text-blue-400">Tip:</span> If your client pays via GCash, the payment link will reflect the exact amounts you enter here. 
                    Underreporting your costs would mean the client is billed less — you would lose that money. Enter accurate figures.
                </p>
            </div>
            
            <!-- Summary Section -->
            <section class="border border-slate-200 dark:border-slate-700 rounded-3xl p-6 bg-white/30 dark:bg-slate-900/30 mt-6">
                <h3 class="font-display font-bold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">analytics</span>
                    Summary
                </h3>
                
                <div class="space-y-3">
                    <!-- Mobilization row -->
                    <div class="flex justify-between text-sm tracking-wide text-slate-500 dark:text-slate-400">
                        <span>Mobilization Fee 
                            <?php if ($mobilization_paid): ?>
                                <span class="text-emerald-600 text-xs">(Paid via GCash)</span>
                            <?php else: ?>
                                <span class="text-yellow-600 text-xs">(Cash)</span>
                            <?php endif; ?>
                        </span>
                        <span class="font-mono font-medium">₱<?php echo number_format($booking['calculated_fee'], 2); ?></span>
                    </div>

                    <!-- Labor row -->
                    <div class="flex justify-between text-sm tracking-wide text-slate-500 dark:text-slate-400">
                        <span>Labor Fee</span>
                        <span class="font-mono font-medium" id="previewLabor">₱0.00</span>
                    </div>

                    <!-- Materials row -->
                    <div class="flex justify-between text-sm tracking-wide text-slate-500 dark:text-slate-400">
                        <span>Materials Cost</span>
                        <span class="font-mono font-medium" id="previewMaterials">₱0.00</span>
                    </div>
                    
                    <!-- Total -->
                    <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center">
                        <span class="font-display font-extrabold text-slate-900 dark:text-white uppercase tracking-widest text-xs">Client Pays</span>
                        <span class="text-2xl font-display font-extrabold text-primary" id="previewTotal">₱0.00</span>
                    </div>
                </div>
                
                <!-- Commission Info -->
                <div class="mt-6 inner-glass p-3 rounded-xl flex items-center gap-3 border-blue-100/30">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-sm">info</span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 font-medium">
                        <span class="text-blue-600 dark:text-blue-400 font-bold">4% platform commission</span> 
                        on your Labor Fee → <span id="adminFee" class="font-bold">₱0.00</span> deducted from your payout.
                        Materials are <span class="font-bold text-slate-700 dark:text-slate-300">not</span> commissionable.
                    </p>
                </div>

                <!-- Worker payout preview -->
                <div class="mt-3 inner-glass p-3 rounded-xl flex items-center gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                        <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-sm">account_balance_wallet</span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 font-medium">
                        Your estimated payout: <span id="workerPayout" class="font-bold text-emerald-600 dark:text-emerald-400">₱0.00</span>
                    </p>
                </div>
                
                <!-- Hidden inputs for JS -->
                <input type="hidden" id="mobilization" value="<?php echo $booking['calculated_fee']; ?>">
                <input type="hidden" id="isMobilizationPaid" value="<?php echo $mobilization_paid ? 'true' : 'false'; ?>">
                <input type="hidden" id="minStandardRate" value="<?php echo $minimum_standard_rate; ?>">
            </section>
            
            <!-- Submit Button -->
            <button type="submit" name="submit_completion" id="submitBtn"
                    class="w-full bg-gradient-to-r from-primary to-blue-700 hover:to-blue-800 text-white font-display font-bold py-5 px-8 rounded-3xl shadow-lg shadow-primary/20 hover:shadow-primary/40 transform hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-center gap-2 group mt-6">
                Generate Receipt
                <span class="material-symbols-outlined transition-transform group-hover:translate-x-1">arrow_forward_ios</span>
            </button>
        </form>
        
        <p class="text-center text-[10px] text-slate-400 dark:text-slate-600 font-medium uppercase tracking-[0.2em]">
            Secure Marketplace Checkout © 2026 Abilisto
        </p>
    </div>
</main>

<script>
    const laborInput     = document.getElementById('labor_fee');
    const materialsInput = document.getElementById('materials_cost');
    const previewLabor   = document.getElementById('previewLabor');
    const previewMats    = document.getElementById('previewMaterials');
    const previewTotal   = document.getElementById('previewTotal');
    const adminFeeSpan   = document.getElementById('adminFee');
    const workerPayout   = document.getElementById('workerPayout');
    const laborHint      = document.getElementById('laborHint');
    const submitBtn      = document.getElementById('submitBtn');

    const mobilization      = parseFloat(document.getElementById('mobilization').value);
    const isMobilizationPaid = document.getElementById('isMobilizationPaid').value === 'true';
    const minRate           = parseFloat(document.getElementById('minStandardRate').value) || 0;

    const COMMISSION = <?= ADMIN_COMMISSION_PERCENT / 100 ?>; // kept in sync with config/constants.php (ADMIN_COMMISSION_PERCENT)

    function recalc() {
        const labor     = parseFloat(laborInput.value) || 0;
        const materials = parseFloat(materialsInput.value) || 0;

        // Update labor hint (min rate check)
        if (minRate > 0) {
            if (labor > 0 && labor < minRate) {
                laborHint.textContent = `⚠ Must be at least ₱${minRate.toFixed(2)} (your Minimum Standard Rate).`;
                laborHint.className   = 'text-xs px-1 text-red-500 font-medium';
                laborHint.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                laborHint.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        previewLabor.textContent = '₱' + labor.toFixed(2);
        previewMats.textContent  = '₱' + materials.toFixed(2);

        // Total the client pays
        const laborMaterials = labor + materials;
        let total;
        if (isMobilizationPaid) {
            total = laborMaterials;
        } else {
            total = mobilization + laborMaterials;
        }

        // Commission on labor only
        const adminFee = labor * COMMISSION;
        const payout   = total - adminFee;

        previewTotal.textContent  = '₱' + total.toFixed(2);
        adminFeeSpan.textContent  = '₱' + adminFee.toFixed(2);
        workerPayout.textContent  = '₱' + payout.toFixed(2);
    }

    // Client-side guard before form submits
    document.getElementById('completionForm').addEventListener('submit', function(e) {
        const labor = parseFloat(laborInput.value) || 0;
        if (minRate > 0 && labor < minRate) {
            e.preventDefault();
            laborInput.classList.add('border-red-500');
            laborInput.closest('.relative').classList.add('shake');
            setTimeout(() => laborInput.closest('.relative').classList.remove('shake'), 500);
            laborHint.textContent = `⚠ Labor Fee must be at least ₱${minRate.toFixed(2)}.`;
            laborHint.className   = 'text-xs px-1 text-red-500 font-bold';
            laborHint.classList.remove('hidden');
        }
    });

    laborInput.addEventListener('input', recalc);
    materialsInput.addEventListener('input', recalc);

    // Dark mode
    if (localStorage.getItem('darkMode') === 'true') {
        document.documentElement.classList.add('dark');
    }
    document.querySelector('[onclick="document.documentElement.classList.toggle(\'dark\')"]')?.addEventListener('click', function() {
        localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
    });

    // Init
    recalc();
</script>

</body>
</html>