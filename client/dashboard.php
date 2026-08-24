<?php
// client/dashboard.php — v2 (Dynamic Categorization & Contextual Badging with Tour)
include '../db_connect.php';
include '../includes/init_lang.php';
include '../auth/enforce_phone.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php"); exit();
}

$uid = $_SESSION['user_id'];

require_once '../includes/functions/feature_flags.php';
require_once '../includes/functions/worker_directory.php';
$quickmatch_enabled = isFeatureEnabled($conn, 'feature_quickmatch_enabled');

// Tour / Welcome logic
$tour_check_stmt = $conn->prepare("SELECT has_seen_tour FROM users WHERE id = ?");
$tour_check_stmt->execute([$uid]);
$tour_check = $tour_check_stmt->fetch();
$has_seen_tour = ($tour_check && $tour_check['has_seen_tour'] == 1);
$show_tour_modal = !$has_seen_tour; // Show tour modal if they haven't seen it
$show_welcome = !isset($_SESSION['has_seen_welcome']);
if ($show_welcome) $_SESSION['has_seen_welcome'] = true;

// ── Filter params ─────────────────────────────────────────
$filter_sub  = isset($_GET['sub'])   ? trim($_GET['sub'])   : '';
$filter_main = isset($_GET['main'])  ? trim($_GET['main'])  : '';
$search_q    = isset($_GET['search'])? trim($_GET['search']): '';

// ── Badge config / categories / query — includes/functions/worker_directory.php ──
$all_workers = searchWorkers($conn, [
    'sub'  => $filter_sub,
    'main' => $filter_main,
    'q'    => $search_q,
]);

// Organise rows
$workers_by_main = [];
foreach ($all_workers as $row) {
    $workers_by_main[$row['main_category']][] = $row;
}

// Available main categories
$active_mains = array_keys($workers_by_main);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Find Skilled Workers | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    
    <!-- Intro.js for guided tours -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/introjs.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intro.js@7.2.0/themes/introjs-modern.css">
    <script src="https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/intro.min.js"></script>

    <style>
        /* Custom Intro.js theme to match your minimalist design */
        .introjs-tooltip {
            background: white !important;
            border-radius: 1rem !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            padding: 0.25rem !important;
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            border: 1px solid #e2e8f0 !important;
            max-width: 350px !important;
        }
        
        .dark .introjs-tooltip {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #f1f5f9 !important;
        }
        
        .introjs-tooltip-header {
            padding: 1rem 1rem 0.5rem 1rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        
        .dark .introjs-tooltip-header {
            border-bottom-color: #334155 !important;
        }
        
        .introjs-tooltip-title {
            font-weight: 700 !important;
            font-size: 1.125rem !important;
            color: #0f172a !important;
        }
        
        .dark .introjs-tooltip-title {
            color: #f1f5f9 !important;
        }
        
        .introjs-tooltiptext {
            padding: 1rem !important;
            font-size: 0.95rem !important;
            line-height: 1.5 !important;
            color: #334155 !important;
        }
        
        .dark .introjs-tooltiptext {
            color: #cbd5e1 !important;
        }
        
        .introjs-tooltipbuttons {
            padding: 0.75rem 1rem 1rem 1rem !important;
            border-top: 1px solid #e2e8f0 !important;
            display: flex !important;
            gap: 0.5rem !important;
            justify-content: flex-end !important;
        }
        
        .dark .introjs-tooltipbuttons {
            border-top-color: #334155 !important;
        }
        
        .introjs-button {
            border-radius: 0.75rem !important;
            padding: 0.5rem 1rem !important;
            font-size: 0.875rem !important;
            font-weight: 600 !important;
            border: 1px solid #e2e8f0 !important;
            background: white !important;
            color: #334155 !important;
            text-shadow: none !important;
            box-shadow: none !important;
            transition: all 0.2s !important;
        }
        
        .introjs-button:hover {
            background: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
            box-shadow: none !important;
        }
        
        .dark .introjs-button {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }
        
        .dark .introjs-button:hover {
            background: #475569 !important;
            border-color: #64748b !important;
        }
        
        .introjs-skipbutton {
            color: #94a3b8 !important;
        }
        
        .introjs-prevbutton {
            margin-right: 0 !important;
        }
        
        .introjs-nextbutton {
            background: #146af5 !important;
            color: white !important;
            border-color: #0f52c2 !important;
        }

        .introjs-nextbutton:hover {
            background: #0f52c2 !important;
            color: white !important;
        }

        .dark .introjs-nextbutton {
            background: #146af5 !important;
            color: white !important;
        }
        
        .introjs-bullets {
            padding: 0.5rem 1rem 0 1rem !important;
        }
        
        .introjs-bullets ul li a {
            background: #cbd5e1 !important;
        }
        
        .introjs-bullets ul li a.active {
            background: #146af5 !important;
        }
        
        .introjs-arrow {
            display: none !important;
        }
        
        .introjs-helperLayer {
            border-radius: 1rem !important;
            box-shadow: 0 0 0 5000px rgba(0, 0, 0, 0.5), 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
            background: transparent !important;
        }
        
        .dark .introjs-helperLayer {
            box-shadow: 0 0 0 5000px rgba(0, 0, 0, 0.7), 0 4px 6px -1px rgba(0, 0, 0, 0.3) !important;
        }
        
        /* Tour highlight effect */
        .tour-highlight {
            transition: all 0.3s ease;
        }
        
        .introjs-showElement {
            transform: scale(1.02);
            z-index: 999999 !important;
        }
        
        /* Custom animations */
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); }
            50% { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.5); }
        }
        
        .tour-pulse {
            animation: pulse 2s infinite;
        }

        /* Modal animations */
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
        
        .modal-animate {
            animation: slideUp 0.4s ease forwards;
        }

        /* Disable body scroll when modal is open */
        body.modal-open {
            overflow: hidden;
        }
    </style>

    <script>
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: { primary: "#146af5", "background-light": "#F8FAFC", "background-dark": "#0F172A" },
                fontFamily: { display: ["Plus Jakarta Sans","sans-serif"], sans: ["Plus Jakarta Sans","sans-serif"] },
            },
        },
    };
    </script>

    <style type="text/tailwindcss">
        .glass { background:rgba(255,255,255,0.1); backdrop-filter:blur(12px); border:1px solid rgba(255,255,255,0.2); }
        .hero-gradient { background: linear-gradient(135deg, #146af5 0%, #2DD4BF 100%); }
        .card-shadow { box-shadow: 0 20px 25px -5px rgba(59,130,246,0.05), 0 8px 10px -6px rgba(59,130,246,0.05); }
        .no-scrollbar::-webkit-scrollbar { display:none; }
        .no-scrollbar { -ms-overflow-style:none; scrollbar-width:none; }

        .badge-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 999px;
            font-size: 12px;
        }
        .badge-icon .material-symbols-outlined {
            font-size: 14px !important;
            font-variation-settings: 'FILL' 1;
        }
        .rating-star { font-size: 10px !important; }
        @media (min-width: 1024px) { .rating-star { font-size: 15px !important; } }

        .skill-tag-colored {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px 3px 6px; border-radius: 20px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
            border-width: 1px;
        }
        .skill-tag-colored .material-symbols-outlined {
            font-size: 13px !important;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300 font-sans <?php echo $show_tour_modal ? 'modal-open' : ''; ?>">

<?php include '../includes/chatbot_widget.php'; ?>
<?php include '../includes/navbar.php'; ?>

<?php
// Email verification banner
$chk_stmt = $conn->prepare("SELECT is_email_verified FROM users WHERE id = ?");
$chk_stmt->execute([$uid]);
$chk = $chk_stmt->fetch();
if ($chk && $chk['is_email_verified'] == 0): ?>
<div class="bg-yellow-50 dark:bg-yellow-900/20 border-b border-yellow-200 dark:border-yellow-800 py-3">
    <div class="max-w-7xl mx-auto px-4 md:px-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-yellow-600 dark:text-yellow-400">warning</span>
            <span class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Your email is not verified.</span>
        </div>
        <a href="../auth/resend_email.php" class="text-sm font-bold text-yellow-800 dark:text-yellow-200 underline">Resend Link</a>
    </div>
</div>
<?php endif; ?>

<main class="max-w-7xl mx-auto px-4 md:px-6 py-4 md:py-6">

    <!-- Hero / Search -->
    <section class="relative hero-gradient rounded-2xl md:rounded-3xl overflow-hidden p-5 md:p-10 mb-6 md:mb-8" id="tour-search">
        <div class="absolute inset-0 opacity-20 pointer-events-none">
            <svg height="100%" preserveAspectRatio="none" viewBox="0 0 100 100" width="100%">
                <path d="M0 100 C 20 0 50 0 100 100 Z" fill="white"></path>
            </svg>
        </div>
        <div class="relative z-10 flex flex-col items-center text-center max-w-2xl mx-auto">
            <h1 class="hidden md:block text-2xl md:text-5xl font-extrabold text-white mb-3 md:mb-4 tracking-tight">
                Find the Perfect <br class="hidden md:block"/> Skilled Worker
            </h1>
            <p class="hidden md:block text-blue-50 text-sm md:text-lg mb-6 md:mb-8 opacity-90">Connect with verified professionals in your area</p>
            <form method="GET" action="dashboard.php" class="w-full glass rounded-xl md:rounded-2xl p-1.5 flex items-center">
                <span class="material-symbols-outlined text-white/70 ml-3 text-xl">search</span>
                <input type="text" name="search"
                       class="w-full bg-transparent border-none text-white placeholder:text-white/60 focus:ring-0 text-sm md:text-base py-2 px-3 cursor-pointer"
                       placeholder="Name, skill, or location..."
                       value="<?php echo htmlspecialchars($search_q); ?>"
                       readonly
                       onclick="window.location.href='search.php'">
                <?php if ($filter_sub)  echo '<input type="hidden" name="sub"  value="'.htmlspecialchars($filter_sub).'">'; ?>
                <?php if ($filter_main) echo '<input type="hidden" name="main" value="'.htmlspecialchars($filter_main).'">'; ?>
                <button type="button" onclick="window.location.href='search.php'" class="bg-white text-primary font-bold px-4 md:px-6 py-2 md:py-3 rounded-lg md:rounded-xl hover:bg-blue-50 transition-all shadow-lg text-sm whitespace-nowrap">
                    Search
                </button>
            </form>
            <?php if ($search_q): ?>
            <div class="mt-4 text-white/80 text-sm flex items-center gap-2">
                <span>Showing results for "<?php echo htmlspecialchars($search_q); ?>"</span>
                <a href="dashboard.php<?php echo $filter_main ? '?main='.urlencode($filter_main) : ($filter_sub ? '?sub='.urlencode($filter_sub) : ''); ?>" class="text-white underline">Clear</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Category filter pills -->
    <section class="mb-6 md:mb-8" id="tour-categories">
        <div class="flex overflow-x-auto no-scrollbar gap-2 md:gap-3 pb-2">
            <a href="dashboard.php<?php echo $search_q ? '?search='.urlencode($search_q) : ''; ?>"
               class="whitespace-nowrap <?php echo (empty($filter_main) && empty($filter_sub)) ? 'bg-primary text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300'; ?> px-4 md:px-5 py-2.5 rounded-full text-xs md:text-sm font-semibold flex items-center gap-2 hover:border-primary/50 shrink-0 transition-all">
                <span class="material-symbols-outlined text-lg">grid_view</span>
                All
            </a>
            <?php foreach ($active_mains as $main):
                $cfg  = $MAIN_CATEGORIES[$main] ?? ['icon'=>'work'];
                $isOn = ($filter_main === $main);
                $href = 'dashboard.php?main='.urlencode($main).($search_q ? '&search='.urlencode($search_q) : '');
            ?>
            <a href="<?php echo $href; ?>"
               class="whitespace-nowrap <?php echo $isOn ? 'bg-primary text-white' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300'; ?> px-4 md:px-5 py-2.5 rounded-full text-xs md:text-sm font-semibold flex items-center gap-2 hover:border-primary/50 shrink-0 transition-all">
                <span class="material-symbols-outlined text-lg"><?php echo $cfg['icon']; ?></span>
                <?php echo htmlspecialchars($main); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Quick Match CTA (hidden during tour) -->
    <?php if (!$show_tour_modal && $quickmatch_enabled): ?>
    <div class="mb-6 flex justify-end" id="tour-quickmatch">
        <a href="quick_match.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-3 rounded-xl font-bold hover:shadow-lg transition-all hover:scale-105">
            <span class="material-symbols-outlined">bolt</span>
            Try Quick Match Now!
        </a>
    </div>
    <?php endif; ?>

    <!-- Workers Display -->
    <?php if (count($all_workers) > 0): ?>

        <!-- Single main-category or sub-category filter: flat grid -->
        <?php if ($filter_main !== '' || $filter_sub !== '' || $search_q !== ''): ?>
            <?php
            $section_title = $filter_sub   ? "Workers offering: $filter_sub"
                           : ($filter_main ? $filter_main
                           : 'Search results for "'.htmlspecialchars($search_q).'"');
            ?>
            <div class="mb-8" id="tour-workers">
                <h2 class="text-lg md:text-2xl font-bold mb-4 tracking-tight"><?php echo $section_title; ?></h2>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3 lg:gap-6">
                    <?php foreach ($all_workers as $worker):
                        renderWorkerCard($worker, $BADGE_CONFIG, $SUB_ICONS);
                    endforeach; ?>
                </div>
            </div>

        <!-- No filter: grouped horizontal scroll by main_category -->
        <?php else: ?>
            <?php foreach ($active_mains as $index => $main):
                if (empty($workers_by_main[$main])) continue;
                $cfg = $MAIN_CATEGORIES[$main] ?? ['icon'=>'work'];
                $sectionId = ($index === 0) ? 'tour-category-section' : '';
            ?>
            <div class="mb-8" id="<?php echo $sectionId; ?>">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2 md:gap-3">
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-blue-100 dark:bg-blue-900/30 text-primary rounded-lg md:rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl"><?php echo $cfg['icon']; ?></span>
                        </div>
                        <h2 class="text-lg md:text-xl font-bold tracking-tight"><?php echo htmlspecialchars($main); ?></h2>
                        <span class="text-xs font-bold text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full">
                            <?php echo count($workers_by_main[$main]); ?>
                        </span>
                    </div>
                    <a href="search_results.php?main=<?php echo urlencode($main); ?>" class="text-primary text-sm font-bold flex items-center gap-1 hover:underline">
                        View All <span class="material-symbols-outlined text-lg">arrow_right_alt</span>
                    </a>
                </div>
                <!-- Horizontal scroll -->
                <div class="flex overflow-x-auto no-scrollbar gap-3 lg:gap-5 pb-3 -mx-4 px-4">
                    <?php foreach ($workers_by_main[$main] as $worker):
                        renderWorkerCardHorizontal($worker, $BADGE_CONFIG, $SUB_ICONS);
                    endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 md:p-16 text-center border border-slate-200 dark:border-slate-700">
            <div class="w-20 h-20 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-4xl text-primary">handyman</span>
            </div>
            <h3 class="text-xl font-bold mb-2">No Workers Found</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-6 max-w-md mx-auto">
                <?php echo $search_q ? 'No workers match "'.htmlspecialchars($search_q).'". Try a different term.'
                    : 'No workers are available in this category yet.'; ?>
            </p>
            <a href="dashboard.php" class="inline-flex items-center gap-2 bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-blue-600 transition-colors">
                <span class="material-symbols-outlined">refresh</span> Clear Filters
            </a>
        </div>
    <?php endif; ?>

</main>

<?php if($show_tour_modal): ?>
<!-- Tour Modal (First-time users) - Mandatory Tour -->
<div class="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-[9999] p-4" id="tourModal">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8 modal-animate">
        <div class="text-center">
            <div class="w-24 h-24 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                <span class="material-symbols-outlined text-5xl text-white">tour</span>
            </div>
            <h2 class="text-3xl font-bold mb-3 bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">Welcome to Abilisto! 👋</h2>
            <p class="text-slate-600 dark:text-slate-300 mb-6 text-lg">Let's take a quick tour to help you get started</p>
            
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 mb-8 text-left">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 text-2xl">info</span>
                    <div>
                        <p class="font-semibold text-blue-800 dark:text-blue-200 mb-1">This short tour will show you:</p>
                        <ul class="text-sm text-blue-700 dark:text-blue-300 space-y-2">
                            <li class="flex items-center gap-2">• How to search for workers</li>
                            <li class="flex items-center gap-2">• Browse by categories</li>
                            <li class="flex items-center gap-2">• Understand worker badges</li>
                            <li class="flex items-center gap-2">• Navigate worker profiles</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="space-y-3">
                <button onclick="startMandatoryTour()" class="block w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white px-6 py-4 rounded-xl font-bold hover:shadow-lg transform hover:scale-105 transition-all duration-300">
                    <span class="flex items-center justify-center gap-3 text-lg">
                        <span class="material-symbols-outlined">play_circle</span>
                        Start Tour Now
                    </span>
                </button>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-4">This tour helps you make the most of Abilisto ✨</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if(!$show_tour_modal && $show_welcome && $quickmatch_enabled): ?>
<!-- Quick Match Modal (After Tour) -->
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[9999] p-4" id="quickMatchModal">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8 modal-animate">
        <div class="text-center">
            <div class="w-20 h-20 bg-gradient-to-br from-purple-500 to-blue-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                <span class="material-symbols-outlined text-4xl text-white">bolt</span>
            </div>
            <h2 class="text-2xl font-bold mb-3">Ready to find workers? ⚡</h2>
            <p class="text-slate-500 dark:text-slate-400 mb-8">Try our AI-powered Quick Match to find the perfect worker for your job in seconds.</p>
            <div class="space-y-3">
                <a href="quick_match.php" class="block w-full bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-4 rounded-xl font-bold hover:shadow-lg transition-all transform hover:scale-105">
                    <span class="flex items-center justify-center gap-2 text-lg">
                        <span class="material-symbols-outlined">bolt</span>
                        Try Quick Match
                    </span>
                </a>
                <button onclick="closeQuickMatchModal()" class="block w-full px-6 py-3 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300 transition-colors text-sm font-medium">
                    Maybe later, let me browse
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Tour configuration
const tourSteps = [
    {
        element: '#tour-search',
        intro: `
            <div class="text-left">
                <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-blue-500">search</span>
                    Smart Search
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">Search for workers by name, skill, or location. Our smart search helps you find exactly what you need.</p>
                <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
                    <p class="text-xs font-medium text-blue-700 dark:text-blue-300 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">lightbulb</span>
                        Tip: Try "electrician" or "Manila"
                    </p>
                </div>
            </div>
        `,
        position: 'bottom'
    },
    {
        element: '#tour-categories',
        intro: `
            <div class="text-left">
                <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-purple-500">category</span>
                    Browse by Category
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">Filter workers by their main expertise. Each category shows relevant professionals with their specific skills.</p>
                <div class="mt-3 flex gap-1 flex-wrap">
                    <span class="px-3 py-1 bg-slate-100 dark:bg-slate-700 rounded-full text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">build</span> Construction
                    </span>
                    <span class="px-3 py-1 bg-slate-100 dark:bg-slate-700 rounded-full text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">directions_car</span> Automotive
                    </span>
                    <span class="px-3 py-1 bg-slate-100 dark:bg-slate-700 rounded-full text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">computer</span> Tech
                    </span>
                </div>
            </div>
        `,
        position: 'bottom'
    },
    {
        element: '#tour-category-section',
        intro: `
            <div class="text-left">
                <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-green-500">groups</span>
                    Worker Cards
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Each card shows important information at a glance:</p>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-start gap-2">
                        <span class="w-5 h-5 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <span class="material-symbols-outlined text-xs text-blue-600">person</span>
                        </span>
                        <span><span class="font-semibold">Profile & name</span> with verification badge</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="w-5 h-5 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <span class="material-symbols-outlined text-xs text-amber-600">sell</span>
                        </span>
                        <span><span class="font-semibold">Colored skill tags</span> showing certification levels</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="w-5 h-5 bg-yellow-100 dark:bg-yellow-900/30 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                            <span class="material-symbols-outlined text-xs text-yellow-600">star</span>
                        </span>
                        <span><span class="font-semibold">Rating & reviews</span> from previous clients</span>
                    </li>
                </ul>
            </div>
        `,
        position: 'right'
    },
    {
        element: '.worker-card:first-child',
        intro: `
            <div class="text-left">
                <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-500">workspace_premium</span>
                    Understanding Badges
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Skills are color-coded by verification level:</p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                        <span class="w-6 h-6 bg-amber-500 rounded-full flex items-center justify-center">
                            <span class="material-symbols-outlined text-xs text-white">workspace_premium</span>
                        </span>
                        <div>
                            <span class="font-bold text-amber-700 dark:text-amber-300">NC III Gold</span>
                            <p class="text-xs text-amber-600 dark:text-amber-400">Highest certification, expert level</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-slate-50 dark:bg-slate-800 rounded-lg">
                        <span class="w-6 h-6 bg-slate-500 rounded-full flex items-center justify-center">
                            <span class="material-symbols-outlined text-xs text-white">military_tech</span>
                        </span>
                        <div>
                            <span class="font-bold text-slate-700 dark:text-slate-300">NC II Silver</span>
                            <p class="text-xs text-slate-600 dark:text-slate-400">Advanced skills, experienced</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                        <span class="w-6 h-6 bg-orange-500 rounded-full flex items-center justify-center">
                            <span class="material-symbols-outlined text-xs text-white">shield</span>
                        </span>
                        <div>
                            <span class="font-bold text-orange-700 dark:text-orange-300">NC I Bronze</span>
                            <p class="text-xs text-orange-600 dark:text-orange-400">Entry-level certified</p>
                        </div>
                    </div>
                </div>
            </div>
        `,
        position: 'right'
    },
    {
        element: '#tour-quickmatch',
        intro: `
            <div class="text-left">
                <h3 class="text-lg font-bold mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-purple-500">bolt</span>
                    Quick Match
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-300">Our AI-powered matching tool finds the perfect worker for your specific job requirements in seconds.</p>
                <div class="mt-4 p-3 bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-900/20 dark:to-blue-900/20 rounded-xl">
                    <p class="text-xs font-medium flex items-center gap-2">
                        <span class="material-symbols-outlined text-purple-500">auto_awesome</span>
                        Just describe your task and we'll handle the rest
                    </p>
                </div>
            </div>
        `,
        position: 'left'
    }
];

// Start mandatory tour (first-time users)
function startMandatoryTour() {
    // Close the tour modal
    document.getElementById('tourModal').remove();
    document.body.classList.remove('modal-open');
    
    // Initialize and start the tour
    const intro = introJs();
    
    intro.setOptions({
        steps: tourSteps,
        showBullets: true,
        showProgress: true,
        showStepNumbers: false,
        exitOnOverlayClick: false, // Prevent exiting by clicking overlay
        disableInteraction: true,
        nextLabel: 'Continue →',
        prevLabel: '← Back',
        skipLabel: '✕ Skip',
        doneLabel: 'Finish Tour ✓',
        tooltipClass: 'custom-tooltip',
        highlightClass: 'tour-highlight',
        scrollToElement: true,
        scrollPadding: 100
    });
    
    intro.oncomplete(function() {
        // Mark tour as completed in database
        fetch('../includes/mark_tour_complete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        }).then(() => {
            // Show success message
            showTourCompleteMessage();
            
            // Show Quick Match modal after a short delay
            setTimeout(() => {
                showQuickMatchModal();
            }, 1000);
        });
    });
    
    intro.onexit(function() {
        // If user tries to skip, force them to complete the tour
        if (confirm('The tour helps you understand how to use Abilisto. Would you like to continue the tour?')) {
            startMandatoryTour();
        } else {
            // If they really want to skip, mark as completed but still show Quick Match
            fetch('../includes/mark_tour_complete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            }).then(() => {
                document.body.classList.remove('modal-open');
                showQuickMatchModal();
            });
        }
    });
    
    intro.start();
    
    // Add pulse animation to highlighted elements
    document.querySelectorAll('.introjs-showElement').forEach(el => {
        el.classList.add('tour-pulse');
    });
}

// Show Quick Match modal after tour
function showQuickMatchModal() {
    // Create and show Quick Match modal
    const modalHtml = `
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-[9999] p-4" id="quickMatchModal">
            <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8 modal-animate">
                <div class="text-center">
                    <div class="w-20 h-20 bg-gradient-to-br from-purple-500 to-blue-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                        <span class="material-symbols-outlined text-4xl text-white">bolt</span>
                    </div>
                    <h2 class="text-2xl font-bold mb-3">Ready to find workers? ⚡</h2>
                    <p class="text-slate-500 dark:text-slate-400 mb-8">Try our AI-powered Quick Match to find the perfect worker for your job in seconds.</p>
                    <div class="space-y-3">
                        <a href="quick_match.php" class="block w-full bg-gradient-to-r from-purple-600 to-blue-500 text-white px-6 py-4 rounded-xl font-bold hover:shadow-lg transition-all transform hover:scale-105">
                            <span class="flex items-center justify-center gap-2 text-lg">
                                <span class="material-symbols-outlined">bolt</span>
                                Try Quick Match
                            </span>
                        </a>
                        <button onclick="closeQuickMatchModal()" class="block w-full px-6 py-3 text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300 transition-colors text-sm font-medium">
                            Maybe later, let me browse
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.body.classList.add('modal-open');
}

// Close Quick Match modal
function closeQuickMatchModal() {
    const modal = document.getElementById('quickMatchModal');
    if (modal) {
        modal.remove();
        document.body.classList.remove('modal-open');
    }
}

// Show completion message
function showTourCompleteMessage() {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-6 right-6 bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-4 rounded-xl shadow-lg z-[10000] flex items-center gap-3 animate-slide-up';
    toast.innerHTML = `
        <span class="material-symbols-outlined text-2xl">check_circle</span>
        <div>
            <p class="font-bold text-lg">Tour Completed! 🎉</p>
            <p class="text-sm opacity-90">You're ready to find skilled workers</p>
        </div>
        <button onclick="this.parentElement.remove()" class="ml-4 hover:bg-white/20 p-1 rounded-full transition-all">
            <span class="material-symbols-outlined text-sm">close</span>
        </button>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentElement) toast.remove();
    }, 5000);
}

// Auto-start tour if show_tour_modal is true
<?php if ($show_tour_modal): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure body has modal-open class
    document.body.classList.add('modal-open');
    
    // Prevent closing modal by clicking outside
    const modal = document.getElementById('tourModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                // Do nothing - modal can't be closed by clicking outside
                return;
            }
        });
    }
});
<?php endif; ?>

// OneSignal
window.OneSignalDeferred = window.OneSignalDeferred || [];
OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({ appId: "db85df82-c0c7-4f34-9510-febfa8e68f9c" });
    <?php if(isset($_SESSION['user_id'])): ?>OneSignal.login("<?php echo $_SESSION['user_id']; ?>");<?php endif; ?>
});

// Horizontal scroll with wheel
document.querySelectorAll('.flex.overflow-x-auto').forEach(c => {
    c.addEventListener('wheel', e => { 
        if(e.deltaY !== 0){ 
            e.preventDefault(); 
            c.scrollLeft += e.deltaY; 
        } 
    });
});

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slide-up {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .animate-slide-up {
        animation: slide-up 0.3s ease forwards;
    }
`;
document.head.appendChild(style);
</script>

<?php
// Create the mark_tour_complete.php file if it doesn't exist
if (!file_exists('../includes/mark_tour_complete.php')) {
    $markTourContent = '<?php
session_start();
include "../db_connect.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit();
}

$uid = intval($_SESSION["user_id"]);
$stmt = $conn->prepare("UPDATE users SET has_seen_tour = TRUE WHERE id = ?");
$stmt->execute([$uid]);

echo json_encode(["success" => true]);
?>';
    file_put_contents('../includes/mark_tour_complete.php', $markTourContent);
}
?>

</body>
</html>