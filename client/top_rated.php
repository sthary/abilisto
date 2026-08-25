<?php
// client/top_rated.php — "View All" destination for the dashboard's Top
// Rated Workers preview. Plain vertical list of the wide horizontal
// worker-list-row card (renderWorkerCardList), sorted by rating desc.
session_start();
include '../db_connect.php';
include '../includes/init_lang.php';
include '../auth/enforce_phone.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php"); exit();
}

require_once '../includes/functions/worker_directory.php';

// Geofence: only show workers within 30km of the client's saved location.
$client_coords = getUserCoords($conn, (int)$_SESSION['user_id']);

$workers = searchWorkers($conn, [
    'sort'       => 'rating',
    'client_lat' => $client_coords['lat'],
    'client_lng' => $client_coords['lng'],
    'radius_km'  => 30,
]);
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Top Rated Workers | Abilisto</title>
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
        .worker-card-list:hover { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
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

<main class="max-w-3xl mx-auto px-4 md:px-6 py-4 md:py-8">

    <div class="flex items-center gap-3 mb-5">
        <button onclick="history.back()" class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
            <span class="material-symbols-outlined">arrow_back</span>
        </button>
        <div class="flex items-center gap-2">
            <div class="w-9 h-9 md:w-10 md:h-10 bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-lg md:text-xl">military_tech</span>
            </div>
            <h1 class="text-lg md:text-2xl font-bold tracking-tight">Top Rated Workers</h1>
        </div>
    </div>

    <?php if (count($workers) > 0): ?>
    <div class="space-y-3">
        <?php foreach ($workers as $worker): renderWorkerCardList($worker, $BADGE_CONFIG, $SUB_ICONS); endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 md:p-16 text-center border border-slate-200 dark:border-slate-700">
        <div class="w-20 h-20 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-4xl text-amber-600 dark:text-amber-400">military_tech</span>
        </div>
        <h3 class="text-xl font-bold mb-2">No Workers Yet</h3>
        <p class="text-slate-500 dark:text-slate-400 mb-6 max-w-md mx-auto">Check back once workers have completed some jobs and earned ratings.</p>
        <a href="dashboard.php" class="inline-flex items-center gap-2 bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-blue-600 transition-colors">
            <span class="material-symbols-outlined">home</span> Back to Dashboard
        </a>
    </div>
    <?php endif; ?>

</main>

</body>
</html>
