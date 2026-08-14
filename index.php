<?php
// Check if the user is inside the Median App
$userAgent = $_SERVER['HTTP_USER_AGENT'];

if (strpos($userAgent, 'median') !== false || strpos($userAgent, 'Median') !== false) {
    header("Location: login.php");
    exit();
}

include 'includes/init_lang.php';

// ============================================================
// GREENLOOP IMPACT STATS - Live from Database
// ============================================================
require_once __DIR__ . '/greenloop/greenloop_db.php';

function getImpactStats($pdo, $period = 'today') {
    $dateCondition = '';
    $trendCondition = '';
    
    switch ($period) {
        case 'today':
            $dateCondition = "DATE(r.completed_at) = CURDATE()";
            $trendCondition = "DATE(r.completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $dateCondition = "YEARWEEK(r.completed_at, 1) = YEARWEEK(CURDATE(), 1)";
            $trendCondition = "YEARWEEK(r.completed_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
            break;
        case 'month':
            $dateCondition = "DATE_FORMAT(r.completed_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $trendCondition = "DATE_FORMAT(r.completed_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
            break;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_scraps,
            COALESCE(SUM(r.quantity), 0) as total_quantity,
            COALESCE(SUM(r.actual_green_coins_awarded), 0) as total_coins
        FROM greenloop_reports r
        WHERE r.status = 'completed' AND {$dateCondition}
    ");
    $stmt->execute();
    $current = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as prev_total
        FROM greenloop_reports r
        WHERE r.status = 'completed' AND {$trendCondition}
    ");
    $stmt->execute();
    $prev = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT r.id) as ewaste_count,
            COALESCE(SUM(r.quantity), 0) as ewaste_qty
        FROM greenloop_reports r
        LEFT JOIN greenloop_accepted_items i ON r.item_id = i.id
        WHERE r.status = 'completed' 
            AND {$dateCondition}
            AND (
                i.category IN ('Electrical', 'Appliance', 'Automotive')
                OR r.item_name_custom LIKE '%electr%'
                OR r.item_name_custom LIKE '%motor%'
                OR r.item_name_custom LIKE '%battery%'
                OR r.item_name_custom LIKE '%wire%'
                OR r.item_name_custom LIKE '%circuit%'
            )
    ");
    $stmt->execute();
    $ewaste = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(i.category, 'Other') as category,
            COUNT(*) as count,
            COALESCE(SUM(r.quantity), 0) as total_qty
        FROM greenloop_reports r
        LEFT JOIN greenloop_accepted_items i ON r.item_id = i.id
        WHERE r.status = 'completed' AND {$dateCondition}
        GROUP BY i.category
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    $prevTotal = $prev['prev_total'] ?? 0;
    $currentTotal = $current['total_scraps'] ?? 0;
    $trendPercent = $prevTotal > 0 ? round((($currentTotal - $prevTotal) / $prevTotal) * 100) : 0;
    $estimatedWeight = round(($current['total_quantity'] ?? 0) * 2.8);
    $ewasteWeight = round(($ewaste['ewaste_qty'] ?? 0) * 3.2);
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as all_time_scraps,
            COALESCE(SUM(actual_green_coins_awarded), 0) as all_time_coins,
            COUNT(DISTINCT client_id) as total_contributors
        FROM greenloop_reports 
        WHERE status = 'completed'
    ");
    $stmt->execute();
    $allTime = $stmt->fetch();
    
    return [
        'total_scraps'       => $currentTotal,
        'total_weight'       => $estimatedWeight,
        'ewaste_count'       => $ewaste['ewaste_count'] ?? 0,
        'ewaste_weight'      => $ewasteWeight,
        'coins_earned'       => round($current['total_coins'] ?? 0),
        'trend_percent'      => $trendPercent,
        'categories'         => $categories,
        'all_time_scraps'    => $allTime['all_time_scraps'] ?? 0,
        'all_time_coins'     => round($allTime['all_time_coins'] ?? 0),
        'total_contributors' => $allTime['total_contributors'] ?? 0
    ];
}

$impactStats = getImpactStats($pdo, 'today');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto - Expert help at your doorstep | Abilidad. Bilis. Listo.</title>
    <meta name="description" content="Abilisto connects you with verified professional workers for home emergencies, maintenance, and installations. Find trusted local experts in Surigao del Sur instantly.">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#146af5",
                        "background-light": "#f5f7f8",
                        "background-dark": "#101722",
                    },
                    fontFamily: { "display": ["Inter", "sans-serif"] },
                    borderRadius: {"DEFAULT": "1rem", "lg": "2rem", "xl": "3rem", "full": "9999px"},
                },
            },
        }
    </script>
    
    <style>
        /* ── SCROLL REVEAL ── */
        .sr {
            opacity: 0;
            transform: translateY(36px);
            transition: opacity 0.75s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.75s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sr.visible { opacity: 1; transform: translateY(0); }

        /* stagger children */
        .sr-group > * {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity 0.65s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.65s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sr-group.visible > *:nth-child(1) { opacity:1; transform:none; transition-delay: 0.05s; }
        .sr-group.visible > *:nth-child(2) { opacity:1; transform:none; transition-delay: 0.15s; }
        .sr-group.visible > *:nth-child(3) { opacity:1; transform:none; transition-delay: 0.25s; }
        .sr-group.visible > *:nth-child(4) { opacity:1; transform:none; transition-delay: 0.35s; }
        .sr-group.visible > *:nth-child(5) { opacity:1; transform:none; transition-delay: 0.45s; }

        /* fade only variant */
        .sr-fade {
            opacity: 0;
            transition: opacity 0.8s ease;
        }
        .sr-fade.visible { opacity: 1; }

        /* slide from left */
        .sr-left {
            opacity: 0;
            transform: translateX(-36px);
            transition: opacity 0.75s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.75s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sr-left.visible { opacity: 1; transform: none; }

        /* slide from right */
        .sr-right {
            opacity: 0;
            transform: translateX(36px);
            transition: opacity 0.75s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.75s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sr-right.visible { opacity: 1; transform: none; }

        /* scale up */
        .sr-scale {
            opacity: 0;
            transform: scale(0.94);
            transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .sr-scale.visible { opacity: 1; transform: scale(1); }

        /* ── IMPACT FILTER ── */
        .impact-filter { transition: all 0.2s ease; }
        .impact-filter.active { background: #146af5; color: white; }

        /* ── GREENLOOP SECTION ── */
        .gl-card {
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .gl-card:hover { transform: translateY(-3px); box-shadow: 0 16px 40px rgba(20,106,245,0.08); }
        .gl-card::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 0.25s ease;
            border-radius: inherit;
        }
        .gl-card:hover::before { opacity: 1; }

        /* number count-up flash */
        @keyframes numFlash {
            0%   { opacity: 0.4; transform: scale(0.95); }
            100% { opacity: 1;   transform: scale(1); }
        }
        .num-flash { animation: numFlash 0.35s ease forwards; }

        /* pulse dot */
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.5; transform: scale(1.4); }
        }
        .live-dot { animation: pulseDot 2s ease infinite; }

        /* leaf spin on hover */
        .leaf-spin { transition: transform 0.6s cubic-bezier(0.34,1.56,0.64,1); }
        .leaf-spin:hover { transform: rotate(20deg) scale(1.08); }

        @media (max-width: 768px) { .hero-title { font-size: 2.5rem; } }
    </style>
</head>

<body class="font-display bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 antialiased">

<!-- ── NAVBAR ── -->
<nav class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
    <div class="max-w-7xl mx-auto flex items-center justify-between bg-white/70 dark:bg-slate-900/70 backdrop-blur-md border border-white/20 dark:border-slate-800/50 rounded-full px-4 md:px-8 py-3 shadow-sm">
        <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Logo image instead of icon -->
            <img src="/1.png" alt="Abilisto Logo" class="size-8 rounded-lg object-cover">
            <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">Abi<span class="text-primary">listo</span></h1>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <a href="auth/login.php" class="px-3 md:px-5 py-2 text-sm font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 rounded-full transition-all whitespace-nowrap">Login</a>
            <a href="auth/signup_role.php" class="bg-primary hover:bg-blue-600 text-white px-4 md:px-6 py-2 rounded-full text-sm font-bold shadow-lg shadow-primary/20 transition-all whitespace-nowrap">Signup</a>
        </div>
    </div>
</nav>

<!-- ── HERO ── -->
<section class="relative pt-32 pb-20 overflow-hidden">
    <div class="absolute inset-0 -z-10 opacity-30">
        <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-primary/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/2"></div>
        <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-primary/5 rounded-full blur-[100px] translate-y-1/2 -translate-x-1/2"></div>
    </div>
    <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
        <div class="space-y-8">
            <div class="space-y-4">
                <span class="sr inline-block px-4 py-1.5 bg-primary/10 text-primary text-xs font-bold uppercase tracking-wider rounded-full" style="transition-delay:0.05s">Abilidad. Bilis. Listo</span>
                <h1 class="sr hero-title text-5xl lg:text-7xl font-black leading-[1.1] text-slate-900 dark:text-white" style="transition-delay:0.12s">
                    Expert help at your <span class="text-primary">doorstep</span>
                </h1>
                <p class="sr text-lg text-slate-600 dark:text-slate-400 max-w-lg leading-relaxed" style="transition-delay:0.2s">
                    Instantly connect with verified professionals for your home emergencies, maintenance, and installations.
                </p>
            </div>
            
            <div class="sr bg-white dark:bg-slate-900 p-2 rounded-2xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-100 dark:border-slate-800 max-w-xl" style="transition-delay:0.28s">
                <div class="flex items-center gap-2">
                    <div class="flex-1 flex items-center px-4">
                        <span class="material-symbols-outlined text-slate-400">search</span>
                        <input class="w-full border-none bg-transparent focus:ring-0 text-slate-900 dark:text-white placeholder:text-slate-400 text-sm py-4" placeholder="Describe your emergency..." type="text"/>
                    </div>
                    <a href="auth/signup_role.php?emergency=all" class="bg-primary text-white px-8 py-3.5 rounded-xl font-bold hover:scale-[1.02] active:scale-95 transition-all">Get Help Now</a>
                </div>
            </div>
            
            <div class="sr flex flex-wrap gap-3" style="transition-delay:0.34s">
                <a href="auth/signup_role.php?emergency=plumbing" class="flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-full hover:border-primary transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-primary text-sm">water_drop</span>
                    <span class="text-sm font-medium">Leaking Pipe</span>
                </a>
                <a href="auth/signup_role.php?emergency=electrical" class="flex items-center gap-2 px-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-full hover:border-primary transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-yellow-500 text-sm">bolt</span>
                    <span class="text-sm font-medium">Power Outage</span>
                </a>
                <a href="auth/signup_role.php?emergency=emergency" class="flex items-center gap-2 px-4 py-2 bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-900/30 rounded-full hover:border-red-500 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-red-500 text-sm">emergency</span>
                    <span class="text-sm font-medium text-red-600 dark:text-red-400">Emergency</span>
                </a>
            </div>
            
            <div class="sr-group grid grid-cols-1 md:grid-cols-2 gap-4 pt-4" style="transition-delay:0.4s">
                <a href="auth/signup_role.php?role=client" class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 hover:border-primary transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="size-12 bg-primary/10 rounded-xl flex items-center justify-center text-primary text-2xl group-hover:scale-110 transition-transform">
                            <i class="fa-regular fa-hand-point-up"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg">Need a service?</h3>
                            <p class="text-sm text-slate-500">Find skilled workers near you</p>
                        </div>
                    </div>
                </a>
                <a href="auth/signup_role.php?role=worker" class="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 hover:border-primary transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="size-12 bg-green-100 dark:bg-green-900/20 rounded-xl flex items-center justify-center text-green-600 dark:text-green-400 text-2xl group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-screwdriver-wrench"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg">I am a skilled worker</h3>
                            <p class="text-sm text-slate-500">Get jobs and grow your business</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        
        <div class="sr-right relative">
            <div class="aspect-[4/5] rounded-xl overflow-hidden shadow-2xl">
                <img class="w-full h-full object-cover" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDoFxdRd7c2pZLRABU8_gFGnl5_iGb-4QQPm0lvBSu0AQ5iyqXB0EMZCkKlcIK3xQU-sJCvPOVmkjSK_GkPzUxU9X5G9szRIExHMEgRi8ulBhDDV1GVUVVp9auscoRlQB8hCEOw2K9DtncDGy4ZdRRWuDP83v1T01DknsUmmNlq7_EPkaqgk1sEjWDZZhRdOcAMXIzMh3Gv93BqF2Rbd4RJQZaMRFGbq0fhnDNfhkO8mam4wk6lZ8U48UFe21m3_EAlPm97dg84P4Q7" alt="Professional electrician"/>
            </div>
            <div class="absolute -bottom-6 -left-6 bg-white dark:bg-slate-900 p-6 rounded-2xl shadow-xl flex items-center gap-4 border border-slate-100 dark:border-slate-800">
                <div class="flex -space-x-3">
                    <img class="size-10 rounded-full border-2 border-white dark:border-slate-900" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDsdUrWdUe1yVPv3gLu_JjotZAuCasgB7so-myw5g5Fisxbx7ry5v8uUYvwzGfbo25NP6xg9827YPi7gXW7_b7f7Qb397_1Q-Mp_s-yPJHZLrzhEOB080gjpSjxFt7JcBqb6Y8RT76CZ8dURpxmThuP9ph0kvxer51P3Q6Goa-rldp-8fhv92i-CwQEEf-i6sTWwNzk1y8qipfILD6ToJ3onjsaQdM_o9VufbvKEBMVwGGvf6Yjr5QgaSxUOWXIaxWrrKwqzCDrQBUq" alt="User"/>
                    <img class="size-10 rounded-full border-2 border-white dark:border-slate-900" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBenE7g5rdnxgY8XqAs3MC_Ed15Cy7L23twnGIOLGhOfc5RA93aSTJzEHdLzipnVSbsVwcTyQvFJ5yhjBn0SwTxuD7u9xaxLb4Yzq_hyznZKNm7Qm_u-8bwIePkIRlmrc7Fkkv7Ifwp6kpXFykWfIcwKo__DG7YT2IPmMu2Ub4M2KVVebKiwFYTUuyDvOsKdHfDfkhM6TR3BSUl6-QOxOoEu10sFGwXiwV7fOXGI7XXvGPy-MhcT1OwuTtRcXLYDvO5O9O5sePMiKpW" alt="User"/>
                    <img class="size-10 rounded-full border-2 border-white dark:border-slate-900" src="https://lh3.googleusercontent.com/aida-public/AB6AXuB7V7YN-pWIIUnDZtj3Or2Txtum86eeMDyW_XakgwyEDjXmBIiNTT9P0Mzim_uzQtnD6gHe8gmE-rybUSZMfCZUHDHTj0FXDLN31oGFw_4VfxF78YNJksbSFYOJBT6kuqjST_jr0_9O4XYPyeQBSBr1N2yyBQcWPR7zggUZJXtNGge3NIW4NenYgyD8DQlWfoq4_Yv0LApQ5g4FQt1aHAjpQpWMfd4YUmksrilxZ-12WEduSE61yxoKUXXsd5zoOON_ChrgLJ94x-6m" alt="User"/>
                </div>
                <div>
                    <p class="text-xs text-slate-500 font-medium">Trusted by</p>
                    <p class="text-sm font-bold"><?= number_format($impactStats['total_contributors']) ?>+ Contributors</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- GREENLOOP IMPACT SECTION — restyled to match landing page  -->
<!-- ══════════════════════════════════════════════════════════ -->
<section class="py-24 bg-white dark:bg-slate-950 overflow-hidden">
    <div class="max-w-7xl mx-auto px-6">

        <!-- Header -->
        <div class="sr flex flex-col lg:flex-row lg:items-end lg:justify-between gap-8 mb-14">
            <div class="flex items-start gap-5">
                <!-- GreenLoop logo badge -->
                <div class="relative flex-shrink-0 leaf-spin">
                    <img src="/2.png" alt="GreenLoop" class="size-16 lg:size-20 object-contain drop-shadow-md">
                </div>
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-green-100 dark:bg-green-900/30 rounded-full mb-3">
                        <span class="live-dot inline-block w-2 h-2 rounded-full bg-green-500"></span>
                        <span class="text-xs font-bold uppercase tracking-wider text-green-700 dark:text-green-400">Live Impact · GreenLoop</span>
                    </div>
                    <h2 class="text-4xl lg:text-5xl font-black leading-tight text-slate-900 dark:text-white">
                        The Good We Do<br><span class="text-green-600 dark:text-green-400">Together</span>
                    </h2>
                </div>
            </div>
            <div class="sr lg:text-right max-w-sm" style="transition-delay:0.15s">
                <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed mb-2">
                    Every scrap recovered is a step towards a cleaner Surigao del Sur.
                </p>
                <p class="text-slate-700 dark:text-slate-300 font-semibold text-sm">
                    <span class="text-green-600 font-black text-lg"><?= number_format($impactStats['all_time_scraps']) ?></span> items saved from landfills &nbsp;·&nbsp;
                    <span class="text-yellow-500 font-black text-lg">🟢 <?= number_format($impactStats['all_time_coins']) ?></span> Green Coins earned
                </p>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="sr flex justify-start mb-10" style="transition-delay:0.1s">
            <div class="inline-flex bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-full p-1 gap-1">
                <button onclick="switchImpactFilter('today')" id="filter-today" class="impact-filter active px-5 py-2 rounded-full text-sm font-semibold">Today</button>
                <button onclick="switchImpactFilter('week')"  id="filter-week"  class="impact-filter px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-400">This Week</button>
                <button onclick="switchImpactFilter('month')" id="filter-month" class="impact-filter px-5 py-2 rounded-full text-sm font-semibold text-slate-600 dark:text-slate-400">This Month</button>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="sr-group grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

            <!-- Total Scraps -->
            <div class="gl-card bg-slate-50 dark:bg-slate-900 rounded-2xl p-6 border border-slate-200 dark:border-slate-800">
                <div class="flex items-start justify-between mb-5">
                    <div class="size-11 bg-green-100 dark:bg-green-900/40 rounded-xl flex items-center justify-center text-2xl">📦</div>
                    <span id="trend-badge" class="text-xs font-bold px-2.5 py-1 rounded-full
                        <?= $impactStats['trend_percent'] >= 0 ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/30 text-red-600' ?>">
                        <?php if ($impactStats['trend_percent'] > 0): ?>+<?= $impactStats['trend_percent'] ?>%
                        <?php elseif ($impactStats['trend_percent'] < 0): ?><?= $impactStats['trend_percent'] ?>%
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Scraps Recovered</p>
                <p class="text-4xl font-black text-slate-900 dark:text-white mb-2" id="total-scraps"><?= number_format($impactStats['total_scraps']) ?></p>
                <p class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="inline-block w-1.5 h-1.5 bg-green-500 rounded-full flex-shrink-0"></span>
                    <span id="scrap-weight"><?= number_format($impactStats['total_weight']) ?> kg</span> diverted from landfills
                </p>
            </div>

            <!-- E-Waste (accent card) -->
            <div class="gl-card bg-amber-50 dark:bg-amber-950/20 rounded-2xl p-6 border-2 border-amber-200 dark:border-amber-800 shadow-lg shadow-amber-100/50 dark:shadow-none">
                <div class="flex items-start justify-between mb-5">
                    <div class="size-11 bg-amber-100 dark:bg-amber-900/50 rounded-xl flex items-center justify-center text-2xl">⚡</div>
                    <span class="text-xs font-bold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 px-2.5 py-1 rounded-full">E-Waste</span>
                </div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">E-Waste Recovered</p>
                <p class="text-4xl font-black text-slate-900 dark:text-white mb-2" id="ewaste-total"><?= number_format($impactStats['ewaste_count']) ?></p>
                <p class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="inline-block w-1.5 h-1.5 bg-amber-500 rounded-full flex-shrink-0"></span>
                    <span id="ewaste-weight"><?= number_format($impactStats['ewaste_weight']) ?> kg</span> safely processed
                </p>
            </div>

            <!-- Green Coins -->
            <div class="gl-card bg-slate-50 dark:bg-slate-900 rounded-2xl p-6 border border-slate-200 dark:border-slate-800">
                <div class="flex items-start justify-between mb-5">
                    <div class="size-11 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center text-2xl">🟢</div>
                </div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Coins Earned</p>
                <p class="text-4xl font-black text-slate-900 dark:text-white mb-2" id="coins-earned"><?= number_format($impactStats['coins_earned']) ?></p>
                <p class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="inline-block w-1.5 h-1.5 bg-yellow-400 rounded-full flex-shrink-0"></span>
                    Rewarding eco-friendly actions
                </p>
            </div>

            <!-- Contributors -->
            <div class="gl-card bg-slate-50 dark:bg-slate-900 rounded-2xl p-6 border border-slate-200 dark:border-slate-800">
                <div class="flex items-start justify-between mb-5">
                    <div class="size-11 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center text-2xl">👥</div>
                </div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Contributors</p>
                <p class="text-4xl font-black text-slate-900 dark:text-white mb-2" id="contributors"><?= number_format($impactStats['total_contributors']) ?></p>
                <p class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="inline-block w-1.5 h-1.5 bg-blue-500 rounded-full flex-shrink-0"></span>
                    Making a difference daily
                </p>
            </div>
        </div>

        <!-- Most Recovered Categories -->
        <div class="sr bg-slate-50 dark:bg-slate-900 rounded-2xl p-6 border border-slate-200 dark:border-slate-800" style="transition-delay:0.2s">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-5 flex items-center gap-2">
                <span>📊</span> Most Recovered Items
            </h4>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3" id="category-list">
                <?php if (empty($impactStats['categories'])): ?>
                    <p class="text-slate-400 text-sm col-span-full text-center py-6">No data yet for this period. Be the first to contribute! ♻️</p>
                <?php else: ?>
                    <?php foreach ($impactStats['categories'] as $cat): ?>
                    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center hover:border-green-300 dark:hover:border-green-700 transition-colors">
                        <p class="font-black text-slate-900 dark:text-white text-base mb-1"><?= htmlspecialchars($cat['category'] ?? 'Other') ?></p>
                        <p class="text-xs text-slate-500"><?= $cat['count'] ?> items</p>
                        <p class="text-[10px] font-bold text-green-600 dark:text-green-400 mt-1"><?= round($cat['total_qty'] ?? 0) ?> units</p>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- CTA Strip -->
        <div class="sr mt-8 flex flex-col sm:flex-row items-center justify-between gap-4 bg-green-600 rounded-2xl px-8 py-5" style="transition-delay:0.25s">
            <div class="flex items-center gap-4">
                <img src="/2.png" alt="GreenLoop" class="size-10 object-contain">
                <div>
                    <p class="font-bold text-white text-lg leading-tight">Turn your scrap into rewards</p>
                    <p class="text-green-100 text-sm">Earn Green Coins every time you recycle</p>
                </div>
            </div>
            <a href="greenloop/greenloop_report.php" class="bg-white text-green-700 font-bold px-6 py-3 rounded-xl hover:bg-green-50 transition-colors whitespace-nowrap text-sm shadow-lg">
                Start Recycling →
            </a>
        </div>

    </div>
</section>

<!-- ── TIERS SECTION ── -->
<section class="py-24 bg-background-light dark:bg-background-dark">
    <div class="max-w-7xl mx-auto px-6">
        <div class="sr text-center max-w-2xl mx-auto mb-16">
            <h2 class="text-3xl font-bold mb-4">Skilled Workers You Can Trust</h2>
            <p class="text-slate-600 dark:text-slate-400">Every worker is verified through TESDA or community-vouched. Choose the expertise level that fits your needs.</p>
        </div>
        <div class="sr-group grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="p-8 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-primary/50 transition-all group">
                <div class="size-12 rounded-lg bg-yellow-400/10 flex items-center justify-center mb-6">
                    <i class="fa-solid fa-medal text-yellow-500 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">Gold</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">TESDA NC III - Master-level expertise for complex industrial jobs.</p>
                <ul class="mt-4 space-y-2 text-sm text-slate-500">
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> 5+ years experience</li>
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Priority scheduling</li>
                </ul>
            </div>
            <div class="p-8 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-primary/50 transition-all">
                <div class="size-12 rounded-lg bg-slate-400/10 flex items-center justify-center mb-6">
                    <i class="fa-solid fa-award text-slate-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">Silver</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">TESDA NC II - Professional expertise for residential & commercial.</p>
                <ul class="mt-4 space-y-2 text-sm text-slate-500">
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> 3+ years experience</li>
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Warranty on work</li>
                </ul>
            </div>
            <div class="p-8 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-primary/50 transition-all">
                <div class="size-12 rounded-lg bg-orange-400/10 flex items-center justify-center mb-6">
                    <i class="fa-solid fa-certificate text-orange-500 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">Bronze</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">TESDA NC I - Basic certification for routine home repairs.</p>
                <ul class="mt-4 space-y-2 text-sm text-slate-500">
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> 1+ years experience</li>
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Affordable rates</li>
                </ul>
            </div>
            <div class="p-8 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-primary/50 transition-all">
                <div class="size-12 rounded-lg bg-primary/10 flex items-center justify-center mb-6">
                    <i class="fa-solid fa-users text-primary text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">Community</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">Local heroes vouched by neighbors for simple tasks.</p>
                <ul class="mt-4 space-y-2 text-sm text-slate-500">
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Community-vouched</li>
                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500"></i> Lower rates</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ── PRICING SECTION ── -->
<section class="py-24">
    <div class="max-w-5xl mx-auto px-6">
        <div class="sr text-center mb-16">
            <h2 class="text-3xl font-bold mb-4">Transparent Pricing</h2>
            <p class="text-slate-600 dark:text-slate-400">No hidden fees. Pay only for the quality you receive.</p>
        </div>
        <div class="sr-group grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-white dark:bg-slate-900 p-10 rounded-xl border border-slate-200 dark:border-slate-800">
                <h3 class="text-xl font-bold mb-2">Cash Payment</h3>
                <p class="text-slate-500 mb-8">Standard booking process</p>
                <div class="flex items-baseline gap-1 mb-8">
                    <span class="text-4xl font-black">₱20</span>
                    <span class="text-slate-500">base mobilization fee</span>
                </div>
                <ul class="space-y-4 mb-10">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>Pay in cash after service</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>No digital payment needed</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>Receipt provided by worker</span></li>
                </ul>
                <a href="auth/signup_role.php" class="block w-full py-4 border-2 border-slate-200 dark:border-slate-700 rounded-xl font-bold text-center hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Choose Cash</a>
            </div>
            <div class="bg-white dark:bg-slate-900 p-10 rounded-xl border-2 border-primary shadow-2xl shadow-primary/10 relative">
                <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-primary text-white px-4 py-1 rounded-full text-xs font-bold uppercase tracking-widest">Popular</div>
                <h3 class="text-xl font-bold mb-2">Online Payment</h3>
                <p class="text-slate-500 mb-8">10% Xendit discount</p>
                <div class="flex items-baseline gap-1 mb-8">
                    <span class="text-4xl font-black">₱18</span>
                    <span class="text-slate-500">base mobilization fee</span>
                </div>
                <ul class="space-y-4 mb-10">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span class="font-semibold"><strong>10% discount</strong> with Xendit</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span class="font-semibold">Faster booking confirmation</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>Secure digital receipt</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>Priority matching</span></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check-circle text-primary"></i><span>Money-back guarantee</span></li>
                </ul>
                <a href="auth/signup_role.php" class="block w-full py-4 bg-primary text-white rounded-xl font-bold text-center hover:bg-blue-600 transition-colors">Choose Online</a>
            </div>
        </div>
        <div class="sr text-center mt-10" style="transition-delay:0.2s">
            <p class="text-slate-500 text-sm"><i class="fa-solid fa-circle-info me-2"></i>The mobilization fee ensures our workers can cover travel costs and stay ready for your emergency.</p>
        </div>
    </div>
</section>

<!-- ── SOCIAL PROOF ── -->
<section class="py-24 bg-white dark:bg-slate-950 overflow-hidden">
    <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-20">
        <div>
            <div class="sr mb-10">
                <h2 class="text-3xl font-bold mb-3">Trusted by Your Neighbors</h2>
                <p class="text-slate-600 dark:text-slate-400">Real jobs completed by real workers in your community.</p>
            </div>
            <div class="sr-group space-y-6">
                <div class="flex items-center gap-6 p-4 bg-background-light dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800">
                    <div class="size-16 rounded-lg bg-slate-200 dark:bg-slate-800 flex-shrink-0 flex items-center justify-center">
                        <i class="fa-solid fa-wrench text-primary text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold mb-1">Plumbing Fix</h4>
                        <p class="text-sm text-slate-500 mb-2">📍 Carrascal</p>
                        <div class="flex items-center gap-2">
                            <div class="size-5 rounded-full bg-slate-200 overflow-hidden flex items-center justify-center"><i class="fa-solid fa-user text-xs text-slate-500"></i></div>
                            <span class="text-xs text-slate-600 dark:text-slate-400 font-medium italic">Fixed by: Ronald T. Gamba</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-6 p-4 bg-background-light dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800">
                    <div class="size-16 rounded-lg bg-slate-200 dark:bg-slate-800 flex-shrink-0 flex items-center justify-center">
                        <i class="fa-solid fa-bolt text-yellow-500 text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold mb-1">Electrical Repair</h4>
                        <p class="text-sm text-slate-500 mb-2">📍 Cantilan</p>
                        <div class="flex items-center gap-2">
                            <div class="size-5 rounded-full bg-slate-200 overflow-hidden flex items-center justify-center"><i class="fa-solid fa-user text-xs text-slate-500"></i></div>
                            <span class="text-xs text-slate-600 dark:text-slate-400 font-medium italic">Fixed by: Emanriec B. Mabitasan</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-6 p-4 bg-background-light dark:bg-slate-900 rounded-xl border border-slate-100 dark:border-slate-800">
                    <div class="size-16 rounded-lg bg-slate-200 dark:bg-slate-800 flex-shrink-0 flex items-center justify-center">
                        <i class="fa-solid fa-snowflake text-blue-500 text-2xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold mb-1">Aircon Servicing</h4>
                        <p class="text-sm text-slate-500 mb-2">📍 Madrid</p>
                        <div class="flex items-center gap-2">
                            <div class="size-5 rounded-full bg-slate-200 overflow-hidden flex items-center justify-center"><i class="fa-solid fa-user text-xs text-slate-500"></i></div>
                            <span class="text-xs text-slate-600 dark:text-slate-400 font-medium italic">Fixed by: Glenn A. Madronial</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="sr-right bg-primary/5 dark:bg-slate-900 rounded-[3rem] p-12 relative flex flex-col justify-center">
            <div class="mb-8"><i class="fa-solid fa-quote-left text-primary text-6xl opacity-50"></i></div>
            <p class="text-2xl font-bold leading-relaxed mb-8 text-slate-800 dark:text-slate-200">
                "I found a plumber in minutes! My pipe burst at 10 AM. Within 30 minutes, a verified worker arrived and fixed it. The mobilization fee was totally worth it. Ganahan ko!"
            </p>
            <div class="flex items-center gap-4">
                <img class="size-14 rounded-full border-4 border-white dark:border-slate-800" src="https://ui-avatars.com/api/?name=Maria+Santos&background=0d6efd&color=fff&size=64" alt="Maria Santos"/>
                <div>
                    <h5 class="font-bold text-lg">Maria Turja Pame</h5>
                    <div class="flex">
                        <i class="fa-solid fa-star text-yellow-400"></i><i class="fa-solid fa-star text-yellow-400"></i>
                        <i class="fa-solid fa-star text-yellow-400"></i><i class="fa-solid fa-star text-yellow-400"></i>
                        <i class="fa-solid fa-star text-yellow-400"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── PROMISE SECTION ── -->
<section class="py-24 bg-slate-900 text-white">
    <div class="max-w-7xl mx-auto px-6">
        <div class="sr text-center mb-16">
            <h2 class="text-3xl font-bold mb-4 text-white">Our 3-Strike Promise</h2>
            <p class="text-slate-300">We hold every worker and client to the highest standard</p>
        </div>
        <div class="sr-group grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="p-8 rounded-xl bg-white/5 border border-white/10 hover:bg-white/8 transition-colors">
                <i class="fa-solid fa-handshake text-primary text-4xl mb-4"></i>
                <h4 class="text-xl font-bold mb-2 text-white">No-Show Protection</h4>
                <p class="text-slate-300 text-sm">Workers who miss appointments without notice get removed. Your time matters.</p>
            </div>
            <div class="p-8 rounded-xl bg-white/5 border border-white/10 hover:bg-white/8 transition-colors">
                <i class="fa-solid fa-shield text-primary text-4xl mb-4"></i>
                <h4 class="text-xl font-bold mb-2 text-white">Quality Guarantee</h4>
                <p class="text-slate-300 text-sm">If work isn't done right, we help make it right. Every worker is verified.</p>
            </div>
            <div class="p-8 rounded-xl bg-white/5 border border-white/10 hover:bg-white/8 transition-colors">
                <i class="fa-solid fa-clock text-primary text-4xl mb-4"></i>
                <h4 class="text-xl font-bold mb-2 text-white">Response Time</h4>
                <p class="text-slate-300 text-sm">Fast response. Low chance of delay. No ghosting.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── FOOTER ── -->
<footer class="bg-slate-950 text-white pt-20 pb-10">
    <div class="max-w-7xl mx-auto px-6">
        <div class="sr-group grid grid-cols-1 md:grid-cols-2 gap-12 mb-20">
            <div class="space-y-6">
                <div class="flex items-center gap-2">
                    <img src="/1.png" alt="Abilisto" class="size-8 rounded-lg object-cover">
                    <h2 class="text-xl font-bold tracking-tight uppercase">Abilisto</h2>
                </div>
                <p class="text-slate-400 text-sm leading-relaxed">Abilidad. Bilis. Listo.<br>Connecting skilled workers with customers who need them, fast.</p>
                <div class="flex gap-4">
                    <a class="size-10 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center hover:bg-primary transition-colors" href="https://www.facebook.com/profile.php?id=61590436745827" target="_blank"><i class="fa-brands fa-facebook-f text-sm"></i></a>
                    <a class="size-10 rounded-full bg-slate-900 border border-slate-800 flex items-center justify-center hover:bg-primary transition-colors" href="https://www.instagram.com/sthxrysm.gr?igsh=d2lpY2wzZ2RlZTg0" target="_blank"><i class="fa-brands fa-instagram text-sm"></i></a>
                </div>
            </div>
            <div class="space-y-6">
                <h4 class="font-bold mb-6 text-lg">Contact & Address</h4>
                <ul class="space-y-4 text-slate-400 text-sm">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-phone text-primary"></i><a href="tel:09639159674" class="hover:text-white transition-colors">09639159674</a></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-envelope text-primary"></i><a href="mailto:abilistodevunit@abilisto.site" class="hover:text-white transition-colors">abilistodevunit@abilisto.site</a></li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-location-dot text-primary"></i><span>Prk. Piyape Burgos, Cortes, Surigao del Sur</span></li>
                </ul>
            </div>
        </div>
        <hr class="border-slate-900 my-8">
        <div class="flex flex-col md:flex-row justify-between items-center gap-6">
            <p class="text-slate-500 text-xs">© 2026 Abilisto. All rights reserved. Made with <i class="fa-solid fa-heart text-red-500"></i> for Surigao del Sur</p>
            <div class="flex gap-8 text-xs text-slate-500">
                <a class="hover:text-white" href="privacy.html">Privacy Policy</a>
                <a class="hover:text-white" href="terms.html">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<script>
// ── SCROLL REVEAL ENGINE ──────────────────────────────────────
const srObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            srObserver.unobserve(entry.target); // fire once
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('.sr, .sr-fade, .sr-left, .sr-right, .sr-scale, .sr-group').forEach(el => {
    srObserver.observe(el);
});

// Hero elements fire on load (already in viewport)
window.addEventListener('load', () => {
    document.querySelectorAll('.sr, .sr-group').forEach(el => {
        const rect = el.getBoundingClientRect();
        if (rect.top < window.innerHeight) el.classList.add('visible');
    });
});

// ── IMPACT FILTER ─────────────────────────────────────────────
async function switchImpactFilter(period) {
    document.querySelectorAll('.impact-filter').forEach(btn => {
        btn.classList.remove('active', 'bg-primary', 'text-white');
        btn.classList.add('text-slate-600');
    });
    const activeBtn = document.getElementById(`filter-${period}`);
    activeBtn.classList.add('active');
    activeBtn.classList.remove('text-slate-600');

    try {
        const response = await fetch(`greenloop/impact_api.php?period=${period}`);
        const data = await response.json();

        animateNumber('total-scraps', data.total_scraps);
        animateNumber('ewaste-total', data.ewaste_count);
        animateNumber('coins-earned', data.coins_earned);
        document.getElementById('scrap-weight').textContent = `${data.total_weight.toLocaleString()} kg`;
        document.getElementById('ewaste-weight').textContent = `${data.ewaste_weight.toLocaleString()} kg`;

        const trendBadge = document.getElementById('trend-badge');
        if (data.trend_percent > 0)      trendBadge.textContent = `+${data.trend_percent}%`;
        else if (data.trend_percent < 0) trendBadge.textContent = `${data.trend_percent}%`;
        else                             trendBadge.textContent = '—';

        const catList = document.getElementById('category-list');
        if (data.categories && data.categories.length > 0) {
            catList.innerHTML = data.categories.map(cat => `
                <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                    <p class="font-black text-slate-900 dark:text-white text-base mb-1">${cat.category || 'Other'}</p>
                    <p class="text-xs text-slate-500">${cat.count} items</p>
                    <p class="text-[10px] font-bold text-green-600 dark:text-green-400 mt-1">${Math.round(cat.total_qty)} units</p>
                </div>
            `).join('');
        } else {
            catList.innerHTML = '<p class="text-slate-400 text-sm col-span-full text-center py-6">No data yet for this period. Be the first to contribute! ♻️</p>';
        }
    } catch (error) {
        console.error('Failed to fetch impact stats:', error);
    }
}

function animateNumber(elementId, newValue) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const currentValue = parseInt(el.textContent.replace(/,/g, '')) || 0;
    const diff = newValue - currentValue;
    const steps = 20;
    let step = 0;
    el.classList.add('num-flash');
    const timer = setInterval(() => {
        step++;
        el.textContent = Math.round(currentValue + (diff * step / steps)).toLocaleString();
        if (step >= steps) {
            clearInterval(timer);
            el.textContent = newValue.toLocaleString();
            el.classList.remove('num-flash');
        }
    }, 20);
}

// ── SMOOTH SCROLL ─────────────────────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth' });
    });
});
</script>

</body>
</html>