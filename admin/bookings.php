<?php
// admin/bookings.php
include '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../auth/login.php"); 
    exit(); 
}

// STATUS OVERRIDE
if (isset($_GET['action']) && isset($_GET['bid'])) {
    $bid = intval($_GET['bid']);
    $status = $_GET['action'];
    $conn->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$status, $bid]);
    header("Location: bookings.php?msg=updated");
    exit();
}

// Get admin info for navbar
$admin_id = $_SESSION['user_id'];
$admin_stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE id = ?");
$admin_stmt->execute([$admin_id]);
$admin = $admin_stmt->fetch();
$admin_name = $admin['full_name'] ?? 'Admin';

// ─── FILTER & SORT PARAMETERS ────────────────────────────────────────────────
$sort        = isset($_GET['sort'])       ? $_GET['sort']       : 'newest';
$search_name = isset($_GET['name'])       ? trim($_GET['name'])  : '';
$search_id   = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : '';
$date_from   = isset($_GET['date_from'])  ? $_GET['date_from']  : '';
$date_to     = isset($_GET['date_to'])    ? $_GET['date_to']    : '';
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = 10;

// ─── BUILD WHERE CLAUSE ──────────────────────────────────────────────────────
$where_parts = [];
$where_params = [];
if ($search_name !== '') {
    $where_parts[] = "(c.full_name LIKE ? OR w.full_name LIKE ?)";
    $where_params[] = "%$search_name%";
    $where_params[] = "%$search_name%";
}
if ($search_id !== '') {
    $where_parts[] = "b.id = ?";
    $where_params[] = $search_id;
}
if ($date_from !== '') {
    $where_parts[] = "DATE(b.booking_date) >= ?";
    $where_params[] = $date_from;
}
if ($date_to !== '') {
    $where_parts[] = "DATE(b.booking_date) <= ?";
    $where_params[] = $date_to;
}
$where_sql = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ─── ORDER CLAUSE ────────────────────────────────────────────────────────────
switch ($sort) {
    case 'alpha_asc':   $order_sql = 'ORDER BY c.full_name ASC';   break;
    case 'alpha_desc':  $order_sql = 'ORDER BY c.full_name DESC';  break;
    case 'oldest':      $order_sql = 'ORDER BY b.booking_date ASC'; break;
    default:            $order_sql = 'ORDER BY b.booking_date DESC'; break;
}

// ─── COUNT TOTAL (for pagination) ────────────────────────────────────────────
$count_sql = "SELECT COUNT(*) as cnt
              FROM bookings b
              JOIN users c ON b.client_id = c.id
              JOIN users w ON b.worker_id = w.id
              $where_sql";
$count_stmt  = $conn->prepare($count_sql);
$count_stmt->execute($where_params);
$total_rows  = (int)$count_stmt->fetch()['cnt'];
$total_pages = max(1, ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// ─── FETCH BOOKINGS (paginated) ───────────────────────────────────────────────
$sql = "SELECT b.*,
               c.full_name as client_name,
               c.profile_pic as client_pic,
               w.full_name as worker_name,
               w.profile_pic as worker_pic
        FROM bookings b
        JOIN users c ON b.client_id = c.id
        JOIN users w ON b.worker_id = w.id
        $where_sql
        $order_sql
        LIMIT ? OFFSET ?";
$bookings_stmt = $conn->prepare($sql);
$bookings_stmt->execute(array_merge($where_params, [$per_page, $offset]));
$bookings = $bookings_stmt->fetchAll();

// ─── STATISTICS ───────────────────────────────────────────────────────────────
$today = date('Y-m-d');
$stats_sql = "SELECT
    COUNT(*) as total_bookings,
    SUM(CASE WHEN DATE(booking_date) = ? THEN 1 ELSE 0 END) as daily_bookings,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_bookings,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_bookings,
    SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
    SUM(CASE WHEN status = 'Accepted' THEN 1 ELSE 0 END) as accepted_bookings,
    SUM(CASE WHEN payment_method = 'Xendit' THEN 1 ELSE 0 END) as xendit_payments
FROM bookings";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute([$today]);
$stats = $stats_stmt->fetch();

$daily_bookings     = $stats['daily_bookings']     ?? 0;
$total_bookings     = $stats['total_bookings']     ?? 0;
$completed_bookings = $stats['completed_bookings'] ?? 0;
$pending_bookings   = $stats['pending_bookings']   ?? 0;
$cancelled_bookings = $stats['cancelled_bookings'] ?? 0;
$accepted_bookings  = $stats['accepted_bookings']  ?? 0;
$success_rate       = $total_bookings > 0 ? round(($completed_bookings / $total_bookings) * 100, 1) : 0;

// Growth
$last_month_sql = "SELECT COUNT(*) as c FROM bookings WHERE booking_date >= NOW() - INTERVAL '1 month'";
$last_month = (int)$conn->query($last_month_sql)->fetch()['c'];
$prev_month_sql = "SELECT COUNT(*) as c FROM bookings WHERE booking_date >= NOW() - INTERVAL '2 months' AND booking_date < NOW() - INTERVAL '1 month'";
$prev_month = (int)$conn->query($prev_month_sql)->fetch()['c'];
$booking_growth = $prev_month > 0 ? round((($last_month - $prev_month) / $prev_month) * 100, 1) : 0;

// ─── SPARKLINE: daily bookings last 7 days ────────────────────────────────────
$daily7_sql = "SELECT DATE(booking_date) as d, COUNT(*) as cnt
               FROM bookings
               WHERE booking_date >= CURRENT_DATE - INTERVAL '6 days'
               GROUP BY DATE(booking_date)
               ORDER BY d ASC";
$daily7_res = $conn->query($daily7_sql);
$daily7_map = [];
while ($r = $daily7_res->fetch()) { $daily7_map[$r['d']] = (int)$r['cnt']; }
$daily7_labels = []; $daily7_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily7_labels[] = date('D', strtotime($d));
    $daily7_data[]   = $daily7_map[$d] ?? 0;
}

// ─── SPARKLINE: success rate last 6 months ───────────────────────────────────
$sr_sql = "SELECT TO_CHAR(booking_date,'YYYY-MM') as ym,
                  SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as comp,
                  COUNT(*) as tot
           FROM bookings
           WHERE booking_date >= NOW() - INTERVAL '5 months'
           GROUP BY ym ORDER BY ym ASC";
$sr_res = $conn->query($sr_sql);
$sr_labels = []; $sr_data = [];
while ($r = $sr_res->fetch()) {
    $sr_labels[] = date('M', strtotime($r['ym'].'-01'));
    $sr_data[]   = $r['tot'] > 0 ? round(($r['comp']/$r['tot'])*100, 1) : 0;
}

// ─── SPARKLINE: total bookings cumulative last 6 months ──────────────────────
$tb_sql = "SELECT TO_CHAR(booking_date,'YYYY-MM') as ym, COUNT(*) as cnt
           FROM bookings
           WHERE booking_date >= NOW() - INTERVAL '5 months'
           GROUP BY ym ORDER BY ym ASC";
$tb_res = $conn->query($tb_sql);
$tb_labels = []; $tb_data = []; $running = 0;
$base_count_sql = "SELECT COUNT(*) as c FROM bookings WHERE booking_date < NOW() - INTERVAL '5 months'";
$running = (int)$conn->query($base_count_sql)->fetch()['c'];
while ($r = $tb_res->fetch()) {
    $running += (int)$r['cnt'];
    $tb_labels[] = date('M', strtotime($r['ym'].'-01'));
    $tb_data[]   = $running;
}

// ─── MAIN CHART: last 6 months completed vs pending ──────────────────────────
$monthly_sql = "SELECT
    TO_CHAR(booking_date, 'Mon') as month,
    TO_CHAR(booking_date, 'YYYY-MM') as ym,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status='Pending'   THEN 1 ELSE 0 END) as pending
FROM bookings
WHERE booking_date >= NOW() - INTERVAL '6 months'
GROUP BY ym, month ORDER BY ym ASC";
$monthly_res = $conn->query($monthly_sql);
$months = []; $monthly_completed = []; $monthly_pending = [];
while ($row = $monthly_res->fetch()) {
    $months[]            = $row['month'];
    $monthly_completed[] = (int)$row['completed'];
    $monthly_pending[]   = (int)$row['pending'];
}

// Helper
function getUserAvatar($user, $name) {
    if (!empty($user) && file_exists("../uploads/profiles/".$user)) {
        return "../uploads/profiles/".$user;
    }
    return "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=6366F1&color=fff&size=128&bold=true";
}

function buildQuery($overrides = []) {
    $params = [];
    $keys = ['sort','name','booking_id','date_from','date_to','page'];
    foreach ($keys as $k) {
        if (isset($overrides[$k])) { if ($overrides[$k] !== '') $params[$k] = $overrides[$k]; }
        elseif (!empty($_GET[$k])) { $params[$k] = $_GET[$k]; }
    }
    return count($params) ? '?' . http_build_query($params) : '?';
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto Admin - Bookings & Transactions</title>
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
            location.reload();
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
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<?php include 'navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8 relative">

    <!-- Header -->
    <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-800 dark:text-white">Bookings & Transactions</h1>
            <p class="text-sm text-slate-500">Manage and monitor all platform financial activities</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="glass flex items-center px-4 py-2.5 rounded-2xl stat-card-shadow">
                <span class="material-icons-round text-slate-400 mr-2 text-xl">calendar_today</span>
                <span class="text-sm font-medium text-slate-600 dark:text-slate-300"><?php echo date('M d, Y'); ?></span>
            </div>
            <button onclick="document.getElementById('filterPanel').classList.toggle('hidden')"
                    class="glass p-2.5 rounded-2xl stat-card-shadow text-slate-600 dark:text-slate-300 hover:text-primary transition-all">
                <span class="material-icons-round">filter_list</span>
            </button>
        </div>
    </header>

    <!-- ── FILTER PANEL ─────────────────────────────────────────────────────── -->
    <div id="filterPanel" class="<?php echo (!empty($search_name)||!empty($search_id)||!empty($date_from)||!empty($date_to)||$sort!=='newest') ? '' : 'hidden'; ?> bg-white dark:bg-slate-900 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 p-6 mb-6">
        <form method="GET" action="bookings.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Sort By</label>
                <select name="sort" class="w-full text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="newest"    <?php if($sort==='newest')    echo 'selected'; ?>>Newest First</option>
                    <option value="oldest"    <?php if($sort==='oldest')    echo 'selected'; ?>>Oldest First</option>
                    <option value="alpha_asc" <?php if($sort==='alpha_asc') echo 'selected'; ?>>Client A→Z</option>
                    <option value="alpha_desc"<?php if($sort==='alpha_desc')echo 'selected'; ?>>Client Z→A</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Client / Worker Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($search_name); ?>"
                       placeholder="Search by name…"
                       class="w-full text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary"/>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Booking ID</label>
                <input type="text" name="booking_id" value="<?php echo htmlspecialchars($search_id); ?>"
                       placeholder="e.g. 42"
                       class="w-full text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary"/>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                       class="w-full text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary"/>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                       class="w-full text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary"/>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 bg-primary text-white text-sm font-bold px-4 py-2 rounded-xl hover:bg-indigo-600 transition-all">Apply</button>
                <a href="bookings.php" class="flex items-center justify-center p-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-400 hover:text-slate-600 transition-all" title="Clear filters">
                    <span class="material-icons-round text-base">close</span>
                </a>
            </div>
        </form>
        <?php if (!empty($search_name)||!empty($search_id)||!empty($date_from)||!empty($date_to)||$sort!=='newest'): ?>
        <div class="mt-3 flex items-center gap-2 text-xs text-slate-400">
            <span class="material-icons-round text-sm text-primary">info</span>
            Showing <strong class="text-slate-600 dark:text-slate-300"><?php echo $total_rows; ?></strong> result(s) matching your filters.
        </div>
        <?php endif; ?>
    </div>

    <!-- ── STATS CARDS (3 cards, no revenue) ─────────────────────────────── -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Daily Bookings -->
        <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800">
            <div class="flex justify-between items-start mb-4">
                <p class="text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">Daily Bookings</p>
                <span class="text-[10px] font-bold <?php echo $booking_growth >= 0 ? 'text-emerald-500' : 'text-red-500'; ?> flex items-center gap-0.5 bg-slate-50 dark:bg-slate-800/50 px-1.5 py-0.5 rounded">
                    <span class="material-icons-round text-xs"><?php echo $booking_growth >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                    <?php echo abs($booking_growth); ?>%
                </span>
            </div>
            <div class="flex items-end justify-between">
                <h3 class="text-2xl font-bold"><?php echo $daily_bookings; ?></h3>
                <div class="w-24 h-8"><canvas id="dailySparkline"></canvas></div>
            </div>
        </div>
        <!-- Success Rate -->
        <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800">
            <div class="flex justify-between items-start mb-4">
                <p class="text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">Payment Success Rate</p>
                <span class="text-[10px] font-bold text-emerald-500 flex items-center gap-0.5 bg-slate-50 dark:bg-slate-800/50 px-1.5 py-0.5 rounded">
                    <span class="material-icons-round text-xs">trending_up</span> Live
                </span>
            </div>
            <div class="flex items-end justify-between">
                <h3 class="text-2xl font-bold"><?php echo $success_rate; ?>%</h3>
                <div class="w-24 h-8"><canvas id="successSparkline"></canvas></div>
            </div>
        </div>
        <!-- Total Bookings -->
        <div class="bg-white dark:bg-slate-900 p-6 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800">
            <div class="flex justify-between items-start mb-4">
                <p class="text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">Total Bookings</p>
                <span class="text-[10px] font-bold text-slate-400 flex items-center gap-0.5 bg-slate-50 dark:bg-slate-800/50 px-1.5 py-0.5 rounded">Updated now</span>
            </div>
            <div class="flex items-end justify-between">
                <h3 class="text-2xl font-bold"><?php echo $total_bookings; ?></h3>
                <div class="w-24 h-8"><canvas id="totalSparkline"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── CHARTS ROW ────────────────────────────────────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

        <!-- Monthly Trends (kept, takes 2/3 width on desktop) -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-900 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-base font-bold">Booking Trends (Last 6 Months)</h3>
                <div class="flex gap-4">
                    <span class="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                        <span class="w-3 h-3 bg-primary rounded-full inline-block"></span> Completed
                    </span>
                    <span class="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                        <span class="w-3 h-3 bg-amber-400 rounded-full inline-block"></span> Pending
                    </span>
                </div>
            </div>
            <div class="h-56"><canvas id="bookingsChart"></canvas></div>
        </div>

        <!-- Status Distribution Donut (1/3 width on desktop) -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 p-6 flex flex-col">
            <h3 class="text-base font-bold mb-1">Status Breakdown</h3>
            <p class="text-xs text-slate-400 mb-4">All-time booking distribution</p>

            <!-- Donut -->
            <div class="relative flex items-center justify-center flex-1 min-h-[160px]">
                <canvas id="statusDonut" class="max-h-44"></canvas>
                <!-- Centre label -->
                <div class="absolute flex flex-col items-center pointer-events-none">
                    <span class="text-2xl font-bold text-slate-800 dark:text-white" id="donutCenterVal"><?php echo $total_bookings; ?></span>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total</span>
                </div>
            </div>

            <!-- Legend -->
            <div class="mt-4 space-y-2">
                <?php
                $donut_items = [
                    ['label' => 'Completed', 'count' => $completed_bookings, 'color' => 'bg-emerald-500', 'text' => 'text-emerald-600 dark:text-emerald-400'],
                    ['label' => 'Pending',   'count' => $pending_bookings,   'color' => 'bg-amber-400',   'text' => 'text-amber-600 dark:text-amber-400'],
                    ['label' => 'Accepted',  'count' => $accepted_bookings,  'color' => 'bg-blue-500',    'text' => 'text-blue-600 dark:text-blue-400'],
                    ['label' => 'Cancelled', 'count' => $cancelled_bookings, 'color' => 'bg-red-400',     'text' => 'text-red-600 dark:text-red-400'],
                ];
                foreach ($donut_items as $item):
                    $pct = $total_bookings > 0 ? round(($item['count'] / $total_bookings) * 100) : 0;
                ?>
                <div class="flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full <?php echo $item['color']; ?> flex-shrink-0"></span>
                        <span class="text-slate-500 dark:text-slate-400"><?php echo $item['label']; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="font-bold <?php echo $item['text']; ?>"><?php echo $item['count']; ?></span>
                        <span class="text-slate-300 dark:text-slate-600 w-8 text-right"><?php echo $pct; ?>%</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── BOOKINGS TABLE ──────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 overflow-hidden mb-12">
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider">
                            <a href="<?php echo buildQuery(['sort'=>$sort==='alpha_asc'?'alpha_desc':'alpha_asc','page'=>1]); ?>" class="flex items-center gap-1 hover:text-primary transition-all">
                                Client <span class="material-icons-round text-xs"><?php echo $sort==='alpha_asc'?'arrow_upward':($sort==='alpha_desc'?'arrow_downward':'unfold_more'); ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider">Worker</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider">
                            <a href="<?php echo buildQuery(['sort'=>$sort==='newest'?'oldest':'newest','page'=>1]); ?>" class="flex items-center gap-1 hover:text-primary transition-all">
                                Date <span class="material-icons-round text-xs"><?php echo $sort==='oldest'?'arrow_upward':($sort==='newest'?'arrow_downward':'unfold_more'); ?></span>
                            </a>
                        </th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider">Payment</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider text-right">Admin Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800/50">
                    <?php if ($bookings && count($bookings) > 0): ?>
                        <?php foreach ($bookings as $row):
                            $client_avatar = getUserAvatar($row['client_pic'], $row['client_name']);
                            $worker_avatar = getUserAvatar($row['worker_pic'], $row['worker_name']);
                            switch($row['status']) {
                                case 'Completed': $statusClass = 'bg-emerald-50/50 text-emerald-600 dark:text-emerald-400 border-emerald-100'; $statusIcon = 'check_circle'; break;
                                case 'Pending':   $statusClass = 'bg-amber-50/50 text-amber-600 dark:text-amber-400 border-amber-100';   $statusIcon = 'pending';       break;
                                case 'Accepted':  $statusClass = 'bg-blue-50/50 text-blue-600 dark:text-blue-400 border-blue-100';      $statusIcon = 'thumb_up';      break;
                                case 'Cancelled': $statusClass = 'bg-red-50/50 text-red-600 dark:text-red-400 border-red-100';          $statusIcon = 'cancel';        break;
                                default:          $statusClass = 'bg-slate-50/50 text-slate-600 dark:text-slate-400 border-slate-100';  $statusIcon = 'help';
                            }
                            $paymentClass = $row['payment_method'] == 'Xendit' ? 'text-purple-600 dark:text-purple-400' : 'text-emerald-600 dark:text-emerald-400';
                        ?>
                        <tr class="hover:bg-slate-50/30 dark:hover:bg-slate-800/20 transition-all group">
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <img alt="<?php echo htmlspecialchars($row['client_name']); ?>"
                                         class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-800 shadow-sm"
                                         src="<?php echo $client_avatar; ?>"/>
                                    <div>
                                        <p class="text-sm font-bold"><?php echo htmlspecialchars($row['client_name']); ?></p>
                                        <p class="text-[10px] text-slate-400">#BK<?php echo str_pad($row['id'],4,'0',STR_PAD_LEFT); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <img alt="<?php echo htmlspecialchars($row['worker_name']); ?>"
                                         class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-800 shadow-sm"
                                         src="<?php echo $worker_avatar; ?>"/>
                                    <div>
                                        <p class="text-sm font-bold"><?php echo htmlspecialchars($row['worker_name']); ?></p>
                                        <p class="text-[10px] text-slate-400">Worker ID: <?php echo $row['worker_id']; ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <div>
                                    <p class="text-sm font-bold"><?php echo htmlspecialchars($row['service_type'] ?: 'General Service'); ?></p>
                                    <p class="text-[10px] text-slate-400"><?php echo date("M d, Y", strtotime($row['booking_date'])); ?></p>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <div>
                                    <p class="text-sm font-bold <?php echo $paymentClass; ?>"><?php echo htmlspecialchars($row['payment_method'] ?: 'Cash'); ?></p>
                                    <?php if ($row['transaction_id']): ?>
                                        <p class="text-[10px] text-slate-400 font-mono"><?php echo substr($row['transaction_id'], 0, 16); ?>…</p>
                                    <?php else: ?>
                                        <p class="text-[10px] text-slate-400 font-mono">N/A</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold glass <?php echo $statusClass; ?>">
                                    <span class="material-icons-round text-xs"><?php echo $statusIcon; ?></span>
                                    <?php echo strtoupper($row['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="booking_details.php?id=<?php echo $row['id']; ?>"
                                       class="px-3 py-1.5 text-[10px] font-bold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">
                                       View Details
                                    </a>
                                    <?php if($row['status'] == 'Pending' || $row['status'] == 'Accepted'): ?>
                                        <a href="?action=Cancelled&bid=<?php echo $row['id']; ?><?php echo $sort!=='newest'?'&sort='.$sort:''; ?>"
                                           onclick="return confirm('Cancel this job? This action cannot be undone.')"
                                           class="px-3 py-1.5 text-[10px] font-bold border border-red-200 dark:border-red-900/30 text-red-500 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/10 transition-all">
                                           Force Cancel
                                        </a>
                                    <?php else: ?>
                                        <button disabled class="px-3 py-1.5 text-[10px] font-bold border border-slate-200 dark:border-slate-700 text-slate-300 dark:text-slate-600 rounded-lg opacity-50 cursor-not-allowed">
                                            Force Cancel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center gap-3 text-slate-400">
                                    <span class="material-icons-round text-4xl">search_off</span>
                                    <p class="font-medium">No bookings found</p>
                                    <?php if (!empty($search_name)||!empty($search_id)||!empty($date_from)||!empty($date_to)): ?>
                                    <a href="bookings.php" class="text-sm text-primary hover:underline">Clear filters</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-5 bg-slate-50/50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between flex-wrap gap-4">
            <p class="text-xs text-slate-400 font-medium">
                Showing <strong class="text-slate-600 dark:text-slate-300"><?php echo min(($page-1)*$per_page+1, $total_rows); ?>–<?php echo min($page*$per_page, $total_rows); ?></strong>
                of <strong class="text-slate-600 dark:text-slate-300"><?php echo $total_rows; ?></strong> bookings
            </p>
            <?php if ($total_pages > 1): ?>
            <div class="flex items-center gap-1.5">
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildQuery(['page' => $page-1]); ?>" class="p-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-400 hover:text-primary hover:border-primary transition-all">
                        <span class="material-icons-round text-sm">chevron_left</span>
                    </a>
                <?php else: ?>
                    <span class="p-2 rounded-lg border border-slate-100 dark:border-slate-800 text-slate-200 dark:text-slate-700 cursor-not-allowed">
                        <span class="material-icons-round text-sm">chevron_left</span>
                    </span>
                <?php endif; ?>

                <?php
                $window = 2; $start = max(1, $page - $window); $end = min($total_pages, $page + $window);
                if ($start > 1): ?>
                    <a href="<?php echo buildQuery(['page'=>1]); ?>" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">1</a>
                    <?php if ($start > 2): ?><span class="px-1 text-slate-300">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $start; $p <= $end; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="px-3 py-1.5 text-xs font-bold bg-primary text-white rounded-lg"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo buildQuery(['page'=>$p]); ?>" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-all"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $total_pages): ?>
                    <?php if ($end < $total_pages - 1): ?><span class="px-1 text-slate-300">…</span><?php endif; ?>
                    <a href="<?php echo buildQuery(['page'=>$total_pages]); ?>" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg"><?php echo $total_pages; ?></a>
                <?php endif; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo buildQuery(['page' => $page+1]); ?>" class="p-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-400 hover:text-primary hover:border-primary transition-all">
                        <span class="material-icons-round text-sm">chevron_right</span>
                    </a>
                <?php else: ?>
                    <span class="p-2 rounded-lg border border-slate-100 dark:border-slate-800 text-slate-200 dark:text-slate-700 cursor-not-allowed">
                        <span class="material-icons-round text-sm">chevron_right</span>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
    <div id="successMsg" class="fixed bottom-24 right-10 bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700 text-green-800 dark:text-green-300 px-6 py-3 rounded-xl shadow-lg flex items-center gap-3 z-50">
        <span class="material-icons-round text-green-600 dark:text-green-400">check_circle</span>
        <span class="font-medium">Booking status updated successfully.</span>
    </div>
    <?php endif; ?>

</main>

<script>
const isDark    = document.documentElement.classList.contains('dark');
const textColor = isDark ? '#94a3b8' : '#64748b';
const gridColor = isDark ? '#334155' : '#e2e8f0';

function sparkline(id, labels, data, color) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{ data, borderColor: color, borderWidth: 2, pointRadius: 0, tension: 0.4, fill: false }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales:  { x: { display: false }, y: { display: false } },
            animation: { duration: 600 }
        }
    });
}

sparkline('dailySparkline',  <?php echo json_encode($daily7_labels); ?>, <?php echo json_encode($daily7_data); ?>, '#6366F1');
sparkline('successSparkline',<?php echo json_encode($sr_labels ?: ['']); ?>, <?php echo json_encode($sr_data ?: [0]); ?>, '#8B5CF6');
sparkline('totalSparkline',  <?php echo json_encode($tb_labels ?: ['']); ?>, <?php echo json_encode($tb_data ?: [0]); ?>, '#10B981');

// ── Monthly Trends ──────────────────────────────────────────────────────────
new Chart(document.getElementById('bookingsChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months ?: ['No Data']); ?>,
        datasets: [
            {
                label: 'Completed',
                data: <?php echo json_encode($monthly_completed ?: [0]); ?>,
                borderColor: '#6366F1', backgroundColor: 'rgba(99,102,241,0.1)',
                tension: 0.4, fill: true, pointRadius: 3
            },
            {
                label: 'Pending',
                data: <?php echo json_encode($monthly_pending ?: [0]); ?>,
                borderColor: '#F59E0B', backgroundColor: 'rgba(245,158,11,0.1)',
                tension: 0.4, fill: true, pointRadius: 3
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, precision: 0 } },
            x: { grid: { display: false }, ticks: { color: textColor } }
        }
    }
});

// ── Status Donut ────────────────────────────────────────────────────────────
new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'Pending', 'Accepted', 'Cancelled'],
        datasets: [{
            data: [
                <?php echo $completed_bookings; ?>,
                <?php echo $pending_bookings; ?>,
                <?php echo $accepted_bookings; ?>,
                <?php echo $cancelled_bookings; ?>
            ],
            backgroundColor: [
                'rgba(16,185,129,0.85)',   // emerald
                'rgba(251,191,36,0.85)',   // amber
                'rgba(99,102,241,0.85)',   // indigo
                'rgba(248,113,113,0.85)'   // red
            ],
            borderColor: isDark ? '#0f172a' : '#ffffff',
            borderWidth: 3,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '72%',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                        return ` ${ctx.label}: ${ctx.parsed} (${pct}%)`;
                    }
                }
            }
        }
    }
});

const msg = document.getElementById('successMsg');
if (msg) setTimeout(() => msg.remove(), 3000);
</script>

</body>
</html>