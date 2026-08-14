<?php
// admin/dashboard.php
include '../db.php';
include '../includes/init_lang.php';

// Security Check
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

// ============================================
// SYSTEM STATS
// ============================================

// User Counts
$clients_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'client'";
$clients_res = $conn->query($clients_sql);
$clients = $clients_res->fetch_assoc()['count'] ?? 0;

$workers_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'worker'";
$workers_res = $conn->query($workers_sql);
$workers = $workers_res->fetch_assoc()['count'] ?? 0;

$business_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'business'";
$business_res = $conn->query($business_sql);
$business = $business_res->fetch_assoc()['count'] ?? 0;

$total_users = $clients + $workers + $business;

// User growth (last 30 days)
$new_users_sql = "SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$new_users_res = $conn->query($new_users_sql);
$new_users = $new_users_res->fetch_assoc()['count'] ?? 0;
$user_growth = $total_users > 0 ? round(($new_users / $total_users) * 100, 1) : 0;

// Bookings Stats
$total_bookings_sql = "SELECT COUNT(*) as count FROM bookings";
$total_bookings_res = $conn->query($total_bookings_sql);
$total_bookings = $total_bookings_res->fetch_assoc()['count'] ?? 0;

$completed_bookings_sql = "SELECT COUNT(*) as count FROM bookings WHERE status = 'Completed'";
$completed_bookings_res = $conn->query($completed_bookings_sql);
$completed_bookings = $completed_bookings_res->fetch_assoc()['count'] ?? 0;

$pending_bookings_sql = "SELECT COUNT(*) as count FROM bookings WHERE status = 'Pending'";
$pending_bookings_res = $conn->query($pending_bookings_sql);
$pending_bookings = $pending_bookings_res->fetch_assoc()['count'] ?? 0;

$cancelled_bookings_sql = "SELECT COUNT(*) as count FROM bookings WHERE status = 'Cancelled'";
$cancelled_bookings_res = $conn->query($cancelled_bookings_sql);
$cancelled_bookings = $cancelled_bookings_res->fetch_assoc()['count'] ?? 0;

$success_rate = $total_bookings > 0 ? round(($completed_bookings / $total_bookings) * 100, 1) : 0;

// Service category distribution
$category_sql = "SELECT sub_category AS service_category, COUNT(DISTINCT worker_id) as count
                 FROM worker_skills
                 WHERE sub_category IS NOT NULL AND sub_category != ''
                 GROUP BY sub_category
                 ORDER BY count DESC
                 LIMIT 5";
$category_res = $conn->query($category_sql);
$categories = [];
$total_workers_with_categories = 0;
while ($cat = $category_res->fetch_assoc()) {
    $categories[] = $cat;
    $total_workers_with_categories += $cat['count'];
}

// ============================================
// BOOKING HEATMAP DATA
// Last 10 weeks × 7 days of week
// ============================================
$heatmap_sql = "SELECT
                    DAYOFWEEK(booking_date) AS dow,   -- 1=Sun … 7=Sat
                    FLOOR(DATEDIFF(CURDATE(), DATE(booking_date)) / 7) AS weeks_ago,
                    COUNT(*) AS cnt
                FROM bookings
                WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 10 WEEK)
                GROUP BY dow, weeks_ago
                ORDER BY weeks_ago DESC, dow ASC";
$heatmap_res = $conn->query($heatmap_sql);

// Build a [weeks_ago][dow] matrix  (weeks_ago 0 = current week)
$heatmap_matrix = [];
for ($w = 9; $w >= 0; $w--) {
    $heatmap_matrix[$w] = array_fill(1, 7, 0); // dow 1-7
}
$heatmap_max = 1;
while ($r = $heatmap_res->fetch_assoc()) {
    $w   = (int)$r['weeks_ago'];
    $dow = (int)$r['dow'];
    if ($w <= 9 && $dow >= 1 && $dow <= 7) {
        $heatmap_matrix[$w][$dow] = (int)$r['cnt'];
        $heatmap_max = max($heatmap_max, (int)$r['cnt']);
    }
}

// Pass to JS as flat array: [ {w, d, v}, … ]
$heatmap_cells = [];
for ($w = 9; $w >= 0; $w--) {
    for ($d = 1; $d <= 7; $d++) {
        $heatmap_cells[] = ['w' => 9 - $w, 'd' => $d - 1, 'v' => $heatmap_matrix[$w][$d]];
    }
}

// Week labels (relative dates)
$week_labels = [];
for ($w = 9; $w >= 0; $w--) {
    $week_labels[] = date('M d', strtotime("-{$w} weeks monday"));
}

// Daily user signups for the past 7 days
$daily_sql = "SELECT DATE_FORMAT(created_at, '%a') as day, COUNT(*) as count
              FROM users
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              GROUP BY DATE(created_at)
              ORDER BY created_at ASC";
$daily_res = $conn->query($daily_sql);
$daily_labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$daily_counts = array_fill_keys($daily_labels, 0);
while ($row = $daily_res->fetch_assoc()) {
    if (isset($daily_counts[$row['day']])) $daily_counts[$row['day']] = (int)$row['count'];
}
$daily_data = array_values($daily_counts);

// Recent users
$recent_users_sql = "SELECT id, full_name, email, role, created_at, profile_pic
                     FROM users ORDER BY created_at DESC LIMIT 5";
$recent_users = $conn->query($recent_users_sql);

// Notification count
$notif_count_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = $admin_id AND is_read = 0";
$notif_count_res = $conn->query($notif_count_sql);
$notif_count = $notif_count_res->fetch_assoc()['count'] ?? 0;

function getUserAvatar($user) {
    if (!empty($user['profile_pic']) && file_exists("../uploads/profiles/".$user['profile_pic'])) {
        return "../uploads/profiles/".$user['profile_pic'];
    }
    return "https://ui-avatars.com/api/?name=" . urlencode($user['full_name']) . "&background=6366F1&color=fff&size=128&bold=true";
}

$current_date = date('M d, Y');
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto Admin - Analytical Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        accent: "#8B5CF6",
                    },
                    fontFamily: { display: ["Plus Jakarta Sans","sans-serif"], sans: ["Plus Jakarta Sans","sans-serif"] },
                    borderRadius: { DEFAULT: "12px", 'xl': '16px', '2xl': '24px' },
                },
            },
        };
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
            updateChartsTheme();
        }
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-sidebar');
            const overlay = document.getElementById('menu-overlay');
            menu.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
        if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');
    </script>
    <style type="text/tailwindcss">
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass {
            background: rgba(255,255,255,0.7);
            @apply backdrop-blur-md;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .dark .glass { background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.1); }
        .stat-card-shadow { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.04), 0 8px 10px -6px rgba(0,0,0,0.04); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .heat-cell {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 4px;
            cursor: default;
            transition: transform 0.1s ease, opacity 0.1s ease;
        }
        .heat-cell:hover { transform: scale(1.25); opacity: 1 !important; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<?php include 'navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8 relative">

    <!-- Header -->
    <header class="glass sticky top-4 left-0 right-0 z-40 px-4 py-3 rounded-2xl mb-6 flex items-center justify-between stat-card-shadow">
        <div class="flex items-center gap-3 flex-1 mr-4">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div class="flex items-center bg-slate-100 dark:bg-slate-800/50 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 w-full max-w-[400px]">
                <span class="material-icons-round text-slate-400 text-lg mr-2">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-sm w-full placeholder:text-slate-400 p-0" placeholder="Search..." type="text"/>
            </div>
        </div>
        <div class="flex items-center gap-1 md:gap-3">
            <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400 transition-all" onclick="toggleDarkMode()">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <button class="relative p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400 transition-all">
                <span class="material-icons-round text-xl">notifications</span>
                <?php if ($notif_count > 0): ?>
                <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-slate-900"></span>
                <?php endif; ?>
            </button>
            <div class="hidden sm:block h-6 w-[1px] bg-slate-200 dark:bg-slate-700 mx-1"></div>
            <div class="hidden sm:block text-right">
                <p class="text-[10px] font-bold text-slate-800 dark:text-white"><?php echo $current_date; ?></p>
                <p class="text-[8px] text-slate-500 uppercase tracking-tighter">Overview</p>
            </div>
        </div>
    </header>

    <!-- Stats Cards (3 cards, no revenue) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-6 mb-6">

        <!-- Active Users -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 transition-all duration-300">
            <div class="flex justify-between items-start mb-3">
                <div class="p-2 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg">
                    <span class="material-icons-round text-xl">people</span>
                </div>
                <span class="text-[10px] font-bold text-blue-500 flex items-center gap-0.5">
                    <span class="material-icons-round text-sm">trending_up</span> +<?php echo $user_growth; ?>%
                </span>
            </div>
            <p class="text-slate-500 dark:text-slate-400 text-xs font-medium">Active Users</p>
            <h3 class="text-xl font-bold mt-1"><?php echo $total_users; ?></h3>
            <?php if ($total_users > 0):
                $client_percent  = round(($clients  / $total_users) * 100);
                $worker_percent  = round(($workers  / $total_users) * 100);
                $business_percent= round(($business / $total_users) * 100);
            ?>
            <div class="mt-4 flex gap-1 h-1 w-full rounded-full overflow-hidden">
                <div class="h-full bg-blue-500"    style="width:<?php echo $client_percent; ?>%"   title="Clients"></div>
                <div class="h-full bg-indigo-400"  style="width:<?php echo $worker_percent; ?>%"   title="Workers"></div>
                <div class="h-full bg-purple-400"  style="width:<?php echo $business_percent; ?>%" title="Business"></div>
            </div>
            <div class="flex justify-between mt-2 text-[8px] text-slate-400 uppercase font-bold">
                <span><?php echo $client_percent; ?>% Clients</span>
                <span><?php echo $worker_percent; ?>% Workers</span>
                <span><?php echo $business_percent; ?>% Biz</span>
            </div>
            <?php else: ?>
            <div class="mt-4 text-center text-xs text-slate-400">No users yet</div>
            <?php endif; ?>
        </div>

        <!-- Total Bookings -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 transition-all duration-300">
            <div class="flex justify-between items-start mb-3">
                <div class="p-2 bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-lg">
                    <span class="material-icons-round text-xl">calendar_month</span>
                </div>
                <span class="text-[10px] font-bold text-slate-400">P: <?php echo $pending_bookings; ?> | C: <?php echo $cancelled_bookings; ?></span>
            </div>
            <p class="text-slate-500 dark:text-slate-400 text-xs font-medium">Total Bookings</p>
            <h3 class="text-xl font-bold mt-1"><?php echo $total_bookings; ?></h3>
            <?php if ($total_bookings > 0):
                $completion_percent = round(($completed_bookings / $total_bookings) * 100);
                $pending_percent    = round(($pending_bookings   / $total_bookings) * 100);
                $cancelled_percent  = round(($cancelled_bookings / $total_bookings) * 100);
            ?>
            <div class="mt-4 flex gap-1 h-1 w-full rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500" style="width:<?php echo $completion_percent; ?>%" title="Completed"></div>
                <div class="h-full bg-amber-500"   style="width:<?php echo $pending_percent; ?>%"   title="Pending"></div>
                <div class="h-full bg-red-500"     style="width:<?php echo $cancelled_percent; ?>%"  title="Cancelled"></div>
            </div>
            <?php else: ?>
            <div class="mt-4 text-center text-xs text-slate-400">No bookings yet</div>
            <?php endif; ?>
        </div>

        <!-- Success Rate -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 transition-all duration-300">
            <div class="flex justify-between items-start mb-3">
                <div class="p-2 bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 rounded-lg">
                    <span class="material-icons-round text-xl">verified</span>
                </div>
                <span class="text-[10px] font-bold text-purple-500">
                    <?php echo $success_rate >= 80 ? 'EXCELLENT' : ($success_rate >= 50 ? 'GOOD' : 'NEEDS WORK'); ?>
                </span>
            </div>
            <p class="text-slate-500 dark:text-slate-400 text-xs font-medium">Success Rate</p>
            <h3 class="text-xl font-bold mt-1"><?php echo $success_rate; ?>%</h3>
            <div class="mt-4 h-1 w-full bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <div class="h-full bg-purple-500" style="width:<?php echo $success_rate; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- ══ BOOKING ACTIVITY HEATMAP ══════════════════════════════════════════ -->
    <div class="bg-white dark:bg-slate-900 p-5 md:p-8 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
            <div>
                <h4 class="text-lg font-bold">Booking Activity</h4>
                <p class="text-xs text-slate-400">Daily booking density — past 10 weeks</p>
            </div>
            <!-- Legend -->
            <div class="flex items-center gap-2 text-[10px] text-slate-400 font-medium">
                <span>Less</span>
                <div class="flex gap-1">
                    <div class="w-3.5 h-3.5 rounded-sm bg-slate-100 dark:bg-slate-800"></div>
                    <div class="w-3.5 h-3.5 rounded-sm bg-indigo-200 dark:bg-indigo-900/60"></div>
                    <div class="w-3.5 h-3.5 rounded-sm bg-indigo-400 dark:bg-indigo-600"></div>
                    <div class="w-3.5 h-3.5 rounded-sm bg-indigo-500 dark:bg-indigo-500"></div>
                    <div class="w-3.5 h-3.5 rounded-sm bg-indigo-700 dark:bg-indigo-300"></div>
                </div>
                <span>More</span>
            </div>
        </div>

        <div class="flex gap-3 overflow-x-auto pb-2">
            <!-- Day-of-week labels (left column) -->
            <div class="flex flex-col justify-around flex-shrink-0 text-[9px] font-bold text-slate-400 uppercase tracking-wider pr-1" style="padding-top: 24px;">
                <span>Sun</span>
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
            </div>

            <!-- Grid: 10 columns (weeks), each column = 7 cells -->
            <div class="flex-1 min-w-0">
                <!-- Week date labels -->
                <div id="heatWeekLabels" class="grid mb-1.5 text-[8px] text-slate-400 font-medium" style="grid-template-columns: repeat(10, 1fr); gap: 4px;">
                    <?php foreach ($week_labels as $wl): ?>
                    <div class="truncate text-center"><?php echo $wl; ?></div>
                    <?php endforeach; ?>
                </div>
                <!-- Cells -->
                <div id="heatGrid" class="grid" style="grid-template-columns: repeat(10, 1fr); grid-template-rows: repeat(7, 1fr); gap: 4px;">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- Summary row -->
        <div class="mt-5 pt-4 border-t border-slate-100 dark:border-slate-800 grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
            <?php
            // Peak day of week
            $dow_totals = array_fill(0, 7, 0);
            foreach ($heatmap_cells as $cell) { $dow_totals[$cell['d']] += $cell['v']; }
            $peak_dow_idx = array_search(max($dow_totals), $dow_totals);
            $dow_names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            $peak_day = $dow_names[$peak_dow_idx];
            // Busiest week
            $week_totals = array_fill(0, 10, 0);
            foreach ($heatmap_cells as $cell) { $week_totals[$cell['w']] += $cell['v']; }
            $peak_week_idx = array_search(max($week_totals), $week_totals);
            $peak_week_val = max($week_totals);
            // Average per active day
            $active_days = count(array_filter($heatmap_cells, fn($c) => $c['v'] > 0));
            $avg_per_day = $active_days > 0 ? round(array_sum(array_column($heatmap_cells, 'v')) / $active_days, 1) : 0;
            ?>
            <div>
                <p class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Peak Day</p>
                <p class="text-base font-bold text-indigo-600 dark:text-indigo-400 mt-0.5"><?php echo $peak_day; ?></p>
            </div>
            <div>
                <p class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Busiest Week</p>
                <p class="text-base font-bold text-indigo-600 dark:text-indigo-400 mt-0.5"><?php echo $peak_week_val; ?> bookings</p>
            </div>
            <div>
                <p class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Avg / Active Day</p>
                <p class="text-base font-bold text-indigo-600 dark:text-indigo-400 mt-0.5"><?php echo $avg_per_day; ?></p>
            </div>
            <div>
                <p class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Total (10 wks)</p>
                <p class="text-base font-bold text-indigo-600 dark:text-indigo-400 mt-0.5"><?php echo array_sum(array_column($heatmap_cells, 'v')); ?></p>
            </div>
        </div>
    </div>

    <!-- Service Categories & User Growth -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Service Categories -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800">
            <h4 class="text-sm font-bold mb-6">Service Categories</h4>
            <?php if (count($categories) > 0):
                $colors = ['#6366F1','#8B5CF6','#F59E0B','#10B981','#EC4899'];
                $color_index = 0;
                $total = $total_workers_with_categories;
                $category_names = []; $category_counts = []; $category_colors = [];
                foreach ($categories as $cat) {
                    $category_names[]  = $cat['service_category'];
                    $category_counts[] = $cat['count'];
                    $category_colors[] = $colors[$color_index % count($colors)];
                    $color_index++;
                }
            ?>
            <div class="flex flex-col sm:flex-row items-center gap-6">
                <div class="relative w-40 h-40 flex-shrink-0">
                    <canvas id="categoryChart"></canvas>
                    <div class="absolute inset-0 flex items-center justify-center flex-col">
                        <span class="text-lg font-bold"><?php echo $total; ?></span>
                        <span class="text-[8px] text-slate-400 uppercase">Workers</span>
                    </div>
                </div>
                <div class="w-full space-y-3">
                    <?php foreach ($categories as $index => $cat):
                        $percentage = $total > 0 ? round(($cat['count'] / $total) * 100) : 0;
                    ?>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full" style="background-color:<?php echo $colors[$index % count($colors)]; ?>"></span>
                            <?php echo $cat['service_category']; ?>
                        </span>
                        <span class="font-bold"><?php echo $percentage; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                    <?php
                    $other_percentage = $total > 0 ? 100 - array_sum(array_column($categories,'count')) / $total * 100 : 0;
                    if ($other_percentage > 5): ?>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-slate-200 dark:bg-slate-700"></span> Others
                        </span>
                        <span class="font-bold"><?php echo round($other_percentage); ?>%</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-8 text-slate-400">No category data available</div>
            <?php endif; ?>
        </div>

        <!-- User Growth Chart -->
        <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800">
            <div class="flex justify-between items-center mb-6">
                <h4 class="text-sm font-bold">User Growth</h4>
                <span class="text-[9px] text-slate-400 uppercase font-bold tracking-wider">Past 7 Days</span>
            </div>
            <div class="h-48"><canvas id="userGrowthChart"></canvas></div>
        </div>
    </div>

    <!-- Recent Users -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 overflow-hidden mb-20">
        <div class="px-5 py-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
            <div>
                <h4 class="text-base font-bold">Newest Members</h4>
                <p class="text-[10px] text-slate-400">Recent registration queue</p>
            </div>
            <a href="users.php" class="text-[10px] font-bold text-primary uppercase tracking-wider hover:underline">View All</a>
        </div>
        <div class="divide-y divide-slate-50 dark:divide-slate-800/50">
            <?php if ($recent_users && $recent_users->num_rows > 0): ?>
                <?php while($user = $recent_users->fetch_assoc()):
                    $avatar = getUserAvatar($user);
                    $role_color = $user['role'] == 'worker' ? 'indigo' : ($user['role'] == 'business' ? 'purple' : 'blue');
                ?>
                <div class="p-5 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-all">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <img alt="<?php echo $user['full_name']; ?>" class="w-12 h-12 rounded-full border-2 border-white dark:border-slate-700 shadow-sm object-cover" src="<?php echo $avatar; ?>"/>
                            <div>
                                <p class="text-sm font-bold"><?php echo $user['full_name']; ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo $user['email']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex gap-2">
                            <span class="px-2 py-0.5 text-[9px] font-bold rounded-full bg-<?php echo $role_color; ?>-50 dark:bg-<?php echo $role_color; ?>-900/20 text-<?php echo $role_color; ?>-600 dark:text-<?php echo $role_color; ?>-400 border border-<?php echo $role_color; ?>-100 dark:border-<?php echo $role_color; ?>-800">
                                <?php echo strtoupper($user['role']); ?>
                            </span>
                            <div class="flex items-center gap-1.5 ml-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                <span class="text-[9px] font-bold text-slate-500 uppercase">Active</span>
                            </div>
                        </div>
                        <span class="text-[9px] text-slate-400 font-medium">Joined <?php echo date("M d", strtotime($user['created_at'])); ?></span>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="p-8 text-center text-slate-400">No recent users found</div>
            <?php endif; ?>
        </div>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
            <p class="text-[10px] text-slate-400">Showing <?php echo $recent_users ? min(5, $recent_users->num_rows) : 0; ?> results</p>
        </div>
    </div>
</main>

<script>
// ── Heatmap ───────────────────────────────────────────────────────────────────
const heatCells  = <?php echo json_encode($heatmap_cells); ?>;
const heatMax    = <?php echo max($heatmap_max, 1); ?>;
const isDarkInit = document.documentElement.classList.contains('dark');

function heatColor(v) {
    if (v === 0) return isDarkInit ? '#1e293b' : '#f1f5f9';
    const t = Math.min(v / heatMax, 1);
    // Interpolate from indigo-200 → indigo-700
    const stops = [
        [199,210,254],  // indigo-200
        [165,180,252],  // indigo-300
        [129,140,248],  // indigo-400
        [99,102,241],   // indigo-500
        [79,70,229],    // indigo-600
        [55,48,163],    // indigo-700
    ];
    const idx  = Math.min(Math.floor(t * (stops.length - 1)), stops.length - 2);
    const frac = t * (stops.length - 1) - idx;
    const c = stops[idx].map((c, i) => Math.round(c + frac * (stops[idx+1][i] - c)));
    return `rgb(${c[0]},${c[1]},${c[2]})`;
}

const grid = document.getElementById('heatGrid');
// Sort cells: col-major order (week 0..9, dow 0..6) so CSS grid fills correctly
const sorted = [...heatCells].sort((a,b) => a.w !== b.w ? a.w - b.w : a.d - b.d);
sorted.forEach(cell => {
    const div = document.createElement('div');
    div.className = 'heat-cell';
    div.style.backgroundColor = heatColor(cell.v);
    div.style.opacity = cell.v === 0 ? '0.5' : '1';
    div.title = `${cell.v} booking${cell.v !== 1 ? 's' : ''}`;
    grid.appendChild(div);
});

// ── Charts ───────────────────────────────────────────────────────────────────
let categoryChart, userGrowthChart;

document.addEventListener('DOMContentLoaded', function() {
    const isDark    = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#94a3b8' : '#64748b';
    const gridColor = isDark ? 'rgba(51,65,85,0.3)' : 'rgba(226,232,240,0.5)';

    // Category donut
    if (document.getElementById('categoryChart')) {
        categoryChart = new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($category_names ?? []); ?>,
                datasets: [{
                    data: <?php echo json_encode($category_counts ?? []); ?>,
                    backgroundColor: <?php echo json_encode($category_colors ?? []); ?>,
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '70%',
                plugins: { legend: { display: false }, tooltip: { enabled: true } }
            }
        });
    }

    // User growth bar
    userGrowthChart = new Chart(document.getElementById('userGrowthChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($daily_labels); ?>,
            datasets: [{
                label: 'New Users',
                data: <?php echo json_encode($daily_data); ?>,
                backgroundColor: 'rgba(99,102,241,0.7)',
                borderRadius: 4,
                barPercentage: 0.6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: ctx => ctx.parsed.y + ' new user' + (ctx.parsed.y !== 1 ? 's' : '') }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { stepSize: 1, color: textColor, font: { size: 10 } } },
                x: { grid: { display: false, drawBorder: false }, ticks: { color: textColor, font: { size: 10 } } }
            }
        }
    });
});

function updateChartsTheme() {
    const isDark    = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#94a3b8' : '#64748b';
    const gridColor = isDark ? 'rgba(51,65,85,0.3)' : 'rgba(226,232,240,0.5)';
    if (userGrowthChart) {
        userGrowthChart.options.scales.y.grid.color = gridColor;
        userGrowthChart.options.scales.y.ticks.color = textColor;
        userGrowthChart.options.scales.x.ticks.color = textColor;
        userGrowthChart.update();
    }
}

document.addEventListener('click', function(event) {
    const menu = document.getElementById('mobile-sidebar');
    const menuButton = event.target.closest('[onclick="toggleMobileMenu()"]');
    if (menu && !menu.classList.contains('-translate-x-full') && !menuButton && !menu.contains(event.target) && window.innerWidth < 1024) {
        toggleMobileMenu();
    }
});
</script>
</body>
</html>