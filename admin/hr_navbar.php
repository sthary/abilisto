<?php
// admin/hr_navbar.php
$hr_name     = $hr_name ?? ($_SESSION['full_name'] ?? 'HR');
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div id="menu-overlay" class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-40 lg:hidden" onclick="toggleMobileMenu()"></div>

<aside id="mobile-sidebar"
       class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 h-full w-72 z-50 flex flex-col
              transition-transform duration-300 ease-in-out
              bg-white dark:bg-[#0d1117]
              border-r border-slate-100 dark:border-slate-800/60">

    <!-- Brand -->
    <div class="px-6 py-6 border-b border-slate-100 dark:border-slate-800/60">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-violet-500 flex items-center justify-center shadow-md shadow-violet-200 dark:shadow-violet-900/40">
                <span class="material-icons-round text-white text-lg">groups</span>
            </div>
            <div>
                <p class="font-display font-bold text-sm leading-tight text-slate-900 dark:text-white">Abilisto</p>
                <p class="text-[9px] font-bold uppercase tracking-widest text-violet-500">HR Department</p>
            </div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto slim-scroll">

        <p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Overview</p>
        <a href="hr.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">dashboard</span>HR Dashboard
        </a>

        <div class="pt-4 pb-1"><p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Workforce</p></div>

        <a href="hr_employees.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr_employees.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">badge</span>Employees
        </a>

        <a href="hr_payroll.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr_payroll.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">payments</span>Payroll
        </a>

        <a href="hr_workers.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr_workers.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">construction</span>Platform Workers
        </a>

        <div class="pt-4 pb-1"><p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Operations</p></div>

        <a href="hr_withdrawals.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr_withdrawals.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">account_balance_wallet</span>
            Worker Withdrawals
            <?php
            // Show pending badge
            global $conn;
            if(isset($conn)){
                $pnd = $conn->query("SELECT COUNT(*) as c FROM withdrawals WHERE status='Pending'")->fetch_assoc()['c']??0;
                if($pnd>0) echo '<span class="ml-auto text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-500">'.$pnd.'</span>';
            }
            ?>
        </a>

        <a href="hr_verifications.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $current_page==='hr_verifications.php'?'bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-400 font-semibold':'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">verified_user</span>Verifications
            <?php
            if(isset($conn)){
                $vnd = $conn->query("SELECT COUNT(*) as c FROM verification WHERE verification_status='pending'")->fetch_assoc()['c']??0;
                if($vnd>0) echo '<span class="ml-auto text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-600">'.$vnd.'</span>';
            }
            ?>
        </a>
    </nav>

    <!-- User + Logout -->
    <div class="px-4 py-4 border-t border-slate-100 dark:border-slate-800/60">
        <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/40 mb-3">
            <div class="w-8 h-8 rounded-full bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center flex-shrink-0">
                <span class="material-icons-round text-violet-500 text-base">account_circle</span>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-bold truncate text-slate-800 dark:text-white"><?php echo htmlspecialchars($hr_name); ?></p>
                <p class="text-[9px] text-violet-500 font-bold uppercase tracking-wider">HR Department</p>
            </div>
        </div>
        <a href="../auth/logout.php" class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all">
            <span class="material-icons-round text-base">logout</span>Sign Out
        </a>
    </div>
</aside>