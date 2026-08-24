<?php
// client/search_results.php — results screen: pinned editable search bar,
// category/municipality/sort filters, and the (padding-fixed) worker grid.
session_start();
include '../db_connect.php';
include '../includes/init_lang.php';
include '../auth/enforce_phone.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php"); exit();
}

require_once '../includes/functions/worker_directory.php';

$q            = isset($_GET['q'])            ? trim($_GET['q'])            : '';
$filter_main  = isset($_GET['main'])         ? trim($_GET['main'])         : '';
$filter_sub   = isset($_GET['sub'])          ? trim($_GET['sub'])          : '';
$municipality = isset($_GET['municipality']) ? trim($_GET['municipality']) : '';
$sort         = isset($_GET['sort']) && $_GET['sort'] === 'jobs' ? 'jobs' : 'rating';

$workers = searchWorkers($conn, [
    'q'            => $q,
    'main'         => $filter_main,
    'sub'          => $filter_sub,
    'municipality' => $municipality,
    'sort'         => $sort,
]);

$page_title = $filter_sub ? "Workers offering: $filter_sub"
            : ($filter_main ? $filter_main
            : ($q !== '' ? 'Search results for "' . $q . '"' : 'All Workers'));
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Search Results | Abilisto</title>
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
    <style>
        .card-shadow { box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .worker-card:hover { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .badge-icon { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:999px; }
        .badge-icon .material-symbols-outlined { font-size: 12px !important; }
        .rating-star { font-size: 10px !important; }
        @media (min-width: 1024px) { .rating-star { font-size: 15px !important; } }
        .skill-tag-colored {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 6px 2px 5px; border-radius: 20px;
            font-size: 10px; font-weight: 700; white-space: nowrap;
            border-width: 1px;
        }
        .skill-tag-colored .material-symbols-outlined { font-size: 11px !important; }
        @media (min-width: 1024px) {
            .skill-tag-colored {
                gap: 4px;
                padding: 3px 8px 3px 6px;
                font-size: 11px;
            }
            .skill-tag-colored .material-symbols-outlined { font-size: 13px !important; }
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300 font-sans">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-8">

    <!-- Pinned search bar -->
    <div class="flex items-center gap-3 mb-5">
        <button onclick="history.back()" class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
            <span class="material-symbols-outlined">arrow_back</span>
        </button>
        <form method="GET" action="search_results.php" class="flex-1 flex items-center gap-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5">
            <span class="material-symbols-outlined text-slate-400">search</span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" autocomplete="off"
                   class="flex-1 bg-transparent border-none focus:ring-0 text-sm md:text-base p-0"
                   placeholder="Name, skill, or location...">
            <?php if ($filter_main)  echo '<input type="hidden" name="main" value="'.htmlspecialchars($filter_main).'">'; ?>
            <?php if ($municipality) echo '<input type="hidden" name="municipality" value="'.htmlspecialchars($municipality).'">'; ?>
            <?php if ($sort !== 'rating') echo '<input type="hidden" name="sort" value="'.htmlspecialchars($sort).'">'; ?>
            <button type="submit" class="text-primary font-bold text-sm px-2">Go</button>
        </form>
    </div>

    <!-- Category pills -->
    <div class="flex overflow-x-auto no-scrollbar gap-2 mb-4">
        <a href="search_results.php<?php echo $q ? '?q='.urlencode($q) : ''; ?>"
           class="whitespace-nowrap <?php echo (empty($filter_main) && empty($filter_sub)) ? 'bg-primary text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300'; ?> px-4 py-2 rounded-full text-xs md:text-sm font-semibold flex items-center gap-2 hover:border-primary/50 shrink-0 transition-all">
            <span class="material-symbols-outlined text-lg">grid_view</span> All
        </a>
        <?php foreach ($MAIN_CATEGORIES as $name => $cfg): $isOn = ($filter_main === $name); ?>
        <a href="search_results.php?main=<?php echo urlencode($name).($q ? '&q='.urlencode($q) : ''); ?>"
           class="whitespace-nowrap <?php echo $isOn ? 'bg-primary text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300'; ?> px-4 py-2 rounded-full text-xs md:text-sm font-semibold flex items-center gap-2 hover:border-primary/50 shrink-0 transition-all">
            <span class="material-symbols-outlined text-lg"><?php echo $cfg['icon']; ?></span>
            <?php echo htmlspecialchars($name); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Municipality + Sort filters -->
    <form method="GET" action="search_results.php" class="flex flex-wrap items-center gap-3 mb-6">
        <?php if ($q)           echo '<input type="hidden" name="q" value="'.htmlspecialchars($q).'">'; ?>
        <?php if ($filter_main) echo '<input type="hidden" name="main" value="'.htmlspecialchars($filter_main).'">'; ?>
        <?php if ($filter_sub)  echo '<input type="hidden" name="sub" value="'.htmlspecialchars($filter_sub).'">'; ?>

        <select name="municipality" onchange="this.form.submit()"
                class="text-xs md:text-sm font-semibold bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg py-2 px-3">
            <option value="">All Municipalities</option>
            <?php foreach ($MUNICIPALITIES as $m): ?>
            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $municipality === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="sort" onchange="this.form.submit()"
                class="text-xs md:text-sm font-semibold bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg py-2 px-3">
            <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top Rated</option>
            <option value="jobs"   <?php echo $sort === 'jobs'   ? 'selected' : ''; ?>>Most Jobs Completed</option>
        </select>
    </form>

    <h1 class="text-lg md:text-2xl font-bold mb-5 tracking-tight"><?php echo htmlspecialchars($page_title); ?></h1>

    <?php if (count($workers) > 0): ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3 lg:gap-6">
        <?php foreach ($workers as $worker): renderWorkerCard($worker, $BADGE_CONFIG, $SUB_ICONS); endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 md:p-16 text-center border border-slate-200 dark:border-slate-700">
        <div class="w-20 h-20 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-4xl text-primary">handyman</span>
        </div>
        <h3 class="text-xl font-bold mb-2">No Workers Found</h3>
        <p class="text-slate-500 dark:text-slate-400 mb-6 max-w-md mx-auto">
            <?php echo $q ? 'No workers match "'.htmlspecialchars($q).'". Try a different term.' : 'No workers are available in this category yet.'; ?>
        </p>
        <a href="dashboard.php" class="inline-flex items-center gap-2 bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-blue-600 transition-colors">
            <span class="material-symbols-outlined">refresh</span> Clear Filters
        </a>
    </div>
    <?php endif; ?>

</main>

<script>
    const RECENT_KEY = 'abilisto_recent_searches';
    const term = <?php echo json_encode($q); ?>;
    if (term && term.trim() !== '') {
        try {
            let terms = JSON.parse(localStorage.getItem(RECENT_KEY)) || [];
            terms = terms.filter(t => t.toLowerCase() !== term.toLowerCase());
            terms.unshift(term);
            terms = terms.slice(0, 8);
            localStorage.setItem(RECENT_KEY, JSON.stringify(terms));
        } catch (e) {}
    }
</script>

</body>
</html>
