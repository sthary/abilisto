<?php
// client/search.php — pre-search screen: query box, popular categories,
// recent searches (device-local via localStorage, no DB table needed).
session_start();
include '../db_connect.php';
include '../includes/init_lang.php';
include '../auth/enforce_phone.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php"); exit();
}

require_once '../includes/functions/worker_directory.php';
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Search Workers | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: {
                colors: { primary: "#146af5", "background-light": "#F8FAFC", "background-dark": "#0F172A" },
                fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"], sans: ["Plus Jakarta Sans", "sans-serif"] },
            } },
        };
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300 font-sans">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-4 md:py-8">

    <!-- Search header -->
    <div class="flex items-center gap-3 mb-6">
        <button onclick="history.back()" class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
            <span class="material-symbols-outlined">arrow_back</span>
        </button>
        <form method="GET" action="search_results.php" class="flex-1 flex items-center gap-2 bg-white dark:bg-slate-800 border-2 border-primary rounded-xl px-4 py-2.5">
            <span class="material-symbols-outlined text-slate-400">search</span>
            <input type="text" name="q" id="searchInput" autofocus autocomplete="off"
                   class="flex-1 bg-transparent border-none focus:ring-0 text-sm md:text-base p-0"
                   placeholder="Name, skill, or location...">
        </form>
    </div>

    <!-- Recent Searches -->
    <div id="recentSearchesSection" class="mb-8 hidden">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-300">Recent Searches</h2>
            <button onclick="clearRecentSearches()" class="text-xs font-semibold text-slate-400 hover:text-red-500 flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">delete</span> Clear All
            </button>
        </div>
        <div id="recentSearchesChips" class="flex flex-wrap gap-2"></div>
    </div>

    <!-- Popular Categories -->
    <div>
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-300 mb-3">Popular Categories</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <?php foreach ($MAIN_CATEGORIES as $name => $cfg): ?>
            <a href="search_results.php?main=<?php echo urlencode($name); ?>"
               class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all">
                <div class="w-10 h-10 shrink-0 bg-blue-100 dark:bg-blue-900/30 text-primary rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl"><?php echo $cfg['icon']; ?></span>
                </div>
                <span class="text-sm font-semibold leading-tight"><?php echo htmlspecialchars($name); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

</main>

<script>
    const RECENT_KEY = 'abilisto_recent_searches';

    function getRecentSearches() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; }
        catch (e) { return []; }
    }

    function clearRecentSearches() {
        localStorage.removeItem(RECENT_KEY);
        renderRecentSearches();
    }

    function renderRecentSearches() {
        const terms = getRecentSearches();
        const section = document.getElementById('recentSearchesSection');
        const chips = document.getElementById('recentSearchesChips');
        chips.innerHTML = '';
        if (terms.length === 0) { section.classList.add('hidden'); return; }
        section.classList.remove('hidden');
        terms.forEach(term => {
            const a = document.createElement('a');
            a.href = 'search_results.php?q=' + encodeURIComponent(term);
            a.className = 'px-4 py-2 rounded-full text-xs md:text-sm font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors';
            a.textContent = term;
            chips.appendChild(a);
        });
    }

    renderRecentSearches();
</script>

</body>
</html>
