<?php
// admin/finance_navbar.php
// Standalone sidebar for the 'finance' role.
// Only finance-relevant navigation — no access to admin pages.

$fin_name    = $fin_name    ?? ($_SESSION['full_name'] ?? 'Finance');
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- ── Mobile overlay ──────────────────────────────────────── -->
<div id="menu-overlay"
     class="hidden fixed inset-0 bg-black/40 backdrop-blur-sm z-40 lg:hidden"
     onclick="toggleMobileMenu()"></div>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<aside id="mobile-sidebar"
       class="-translate-x-full lg:translate-x-0
              fixed top-0 left-0 h-full w-72 z-50
              flex flex-col
              transition-transform duration-300 ease-in-out
              bg-white dark:bg-[#0d1117]
              border-r border-slate-100 dark:border-slate-800/60">

    <!-- Logo / Brand -->
    <div class="px-6 py-6 border-b border-slate-100 dark:border-slate-800/60">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-sky-500 flex items-center justify-center shadow-md shadow-sky-200 dark:shadow-sky-900/40">
                <span class="material-icons-round text-white text-lg">account_balance</span>
            </div>
            <div>
                <p class="font-display font-bold text-sm leading-tight text-slate-900 dark:text-white">Abilisto</p>
                <p class="text-[9px] font-bold uppercase tracking-widest text-sky-500">Finance Dept.</p>
            </div>
        </div>
    </div>

    <!-- Nav Items -->
    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto slim-scroll">

        <p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Overview</p>

        <a href="finance.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">dashboard</span>
            Finance Dashboard
        </a>

        <div class="pt-4 pb-1">
            <p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Ledger</p>
        </div>

        <a href="finance_expenses.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance_expenses.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">receipt_long</span>
            Expense Log
            <span class="ml-auto text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400">MOOE/PS</span>
        </a>

        <a href="finance_transactions.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance_transactions.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">swap_horiz</span>
            Wallet Transactions
        </a>

        <a href="finance_topups.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance_topups.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">add_card</span>
            Top-up Records
        </a>

        <a href="finance_withdrawals.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance_withdrawals.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">payments</span>
            Withdrawals
        </a>

        <div class="pt-4 pb-1">
            <p class="text-[9px] font-bold uppercase tracking-widest text-slate-400 px-3 mb-3">Reports</p>
        </div>

        <a href="finance_reports.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all
                  <?php echo $current_page === 'finance_reports.php' ? 'bg-sky-50 dark:bg-sky-900/20 text-sky-600 dark:text-sky-400 font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800/50 hover:text-slate-900 dark:hover:text-white'; ?>">
            <span class="material-icons-round text-[18px]">bar_chart</span>
            Monthly Reports
        </a>
    </nav>

    <!-- User Profile + Logout -->
    <div class="px-4 py-4 border-t border-slate-100 dark:border-slate-800/60">
        <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/40 mb-3">
            <div class="w-8 h-8 rounded-full bg-sky-100 dark:bg-sky-900/40 flex items-center justify-center flex-shrink-0">
                <span class="material-icons-round text-sky-500 text-base">account_circle</span>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-bold truncate text-slate-800 dark:text-white"><?php echo htmlspecialchars($fin_name); ?></p>
                <p class="text-[9px] text-sky-500 font-bold uppercase tracking-wider">Finance Dept.</p>
            </div>
        </div>
        <a href="../auth/logout.php"
           class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold
                  text-slate-500 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400
                  hover:bg-red-50 dark:hover:bg-red-900/20 transition-all">
            <span class="material-icons-round text-base">logout</span>
            Sign Out
        </a>
    </div>
</aside>