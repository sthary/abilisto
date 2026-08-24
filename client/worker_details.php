<?php
// client/worker_details.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

// ── Client first-time tour, part 1 of 2 (continues on booking.php) ────────
// This page is viewable without login, so the tour only applies to an
// actually-logged-in client who hasn't completed it yet.
// Uses its own has_seen_booking_tour flag (NOT has_seen_tour) — client/dashboard.php
// has its own separate, pre-existing "mandatory tour" (Intro.js) that already owns
// has_seen_tour and marks it TRUE on completion. Sharing that flag meant this tour
// never got a turn: the dashboard tour fires first on login and consumes it.
// "Skip tour" pings this same page with ?mark_tour_seen=1, same pattern as
// worker/dashboard.php's tour.
if (isset($_GET['mark_tour_seen']) && $_GET['mark_tour_seen'] == '1' && isset($_SESSION['user_id'])) {
    $conn->prepare("UPDATE users SET has_seen_booking_tour = TRUE WHERE id = ?")->execute([$_SESSION['user_id']]);
}

$show_client_tour = false;
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'client') {
    $tour_chk = $conn->prepare("SELECT has_seen_booking_tour FROM users WHERE id = ?");
    $tour_chk->execute([$_SESSION['user_id']]);
    $show_client_tour = empty($tour_chk->fetch()['has_seen_booking_tour']);
}

$worker_id = intval($_GET['id']);

// 1. Fetch Worker Profile with skills
$sql = "SELECT
            u.id,
            u.full_name,
            u.municipality,
            u.address,
            u.latitude,
            u.longitude,
            u.phone,
            u.email,
            u.profile_pic,
            wp.service_category,
            wp.bio,
            wp.verification_status,
            wp.availability_status,
            wp.average_rating,
            wp.rating_count,
            wp.jobs_completed,
            wp.minimum_standard_rate,
            STRING_AGG(
                CONCAT_WS('||', ws.sub_category, ws.badge_level, COALESCE(ws.nc_level,''), CASE WHEN ws.is_verified THEN '1' ELSE '0' END),
                ';;'
                ORDER BY
                    CASE ws.badge_level WHEN 'Gold' THEN 1 WHEN 'Silver' THEN 2 WHEN 'Bronze' THEN 3 WHEN 'Community' THEN 4 WHEN 'Unverified' THEN 5 ELSE 6 END,
                    ws.sub_category
            ) AS skill_badge_data
        FROM users u
        JOIN worker_profiles wp ON u.id = wp.user_id
        LEFT JOIN worker_skills ws ON ws.worker_id = u.id
        WHERE u.id = ?
        GROUP BY u.id, wp.user_id";

$stmt = $conn->prepare($sql);
$stmt->execute([$worker_id]);
$worker = $stmt->fetch();

if (!$worker) die("Worker not found.");

// 2. Fetch Reviews
$rev_sql = "SELECT reviews.*, COALESCE(users.full_name, 'Unknown User') as reviewer_name, users.profile_pic as reviewer_pic
            FROM reviews
            LEFT JOIN users ON reviews.client_id = users.id
            WHERE reviews.worker_id = ?
            ORDER BY reviews.created_at DESC";
$rev_stmt = $conn->prepare($rev_sql);
$rev_stmt->execute([$worker_id]);
$reviews = $rev_stmt->fetchAll();

// 3. Fetch Portfolio
$port_sql = "SELECT image_path FROM portfolio_images WHERE user_id = ? ORDER BY uploaded_at DESC";
$port_stmt = $conn->prepare($port_sql);
$port_stmt->execute([$worker_id]);
$portfolio = $port_stmt->fetchAll();

// Badge configuration (same as dashboard)
$BADGE_CONFIG = [
    'Gold'      => ['label'=>'NC III Gold',     'color'=>'#D97706', 'bg'=>'bg-amber-100',  'text'=>'text-amber-800',  'icon'=>'workspace_premium', 'border'=>'border-amber-500', 'light-bg'=>'bg-amber-50'],
    'Silver'    => ['label'=>'NC II Silver',    'color'=>'#64748B', 'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'military_tech', 'border'=>'border-slate-500', 'light-bg'=>'bg-slate-50'],
    'Bronze'    => ['label'=>'NC I Bronze',     'color'=>'#92400E', 'bg'=>'bg-orange-100', 'text'=>'text-orange-800', 'icon'=>'shield', 'border'=>'border-orange-700', 'light-bg'=>'bg-orange-50'],
    'Community' => ['label'=>'Community Cert',  'color'=>'#6D28D9', 'bg'=>'bg-violet-100', 'text'=>'text-violet-800', 'icon'=>'groups', 'border'=>'border-violet-600', 'light-bg'=>'bg-violet-50'],
    'Unverified'=> ['label'=>'Unverified',      'color'=>'#94A3B8', 'bg'=>'bg-slate-50',   'text'=>'text-slate-400',  'icon'=>'', 'border'=>'border-slate-300', 'light-bg'=>'bg-slate-50'],
];

// Sub-category icon lookup
$SUB_ICONS = [
    'Electrical'    => 'bolt',
    'Plumbing'      => 'plumbing',
    'Carpentry'     => 'carpenter',
    'Masonry'       => 'domain',
    'Welding'       => 'whatshot',
    'Construction'  => 'build',
    'Automotive'    => 'directions_car',
    'Motorcycle'    => 'two_wheeler',
    'Electronics'   => 'memory',
    'Aircon Ref'    => 'ac_unit',
    'Computer Tech' => 'computer',
    'Domestic Work' => 'cleaning_services',
    'Caregiving'    => 'elderly',
    'Massage'       => 'spa',
    'Beauty Care'   => 'content_cut',
    'Cookery'       => 'restaurant',
    'Baking'        => 'bakery_dining',
    'Graphic_Design'=> 'palette',
    'Photography'   => 'camera_alt',
    'Videography'   => 'videocam',
    'Music'         => 'music_note',
    'Arts Crafts'   => 'brush',
    'Others'        => 'more_horiz',
];

// Helper: parse skill_badge_data string into array of skill objects
function parseSkillBadges(string $raw): array {
    if (empty($raw)) return [];
    $out = [];
    foreach (explode(';;', $raw) as $chunk) {
        $parts = explode('||', $chunk . '||||');
        $out[] = [
            'sub'         => $parts[0],
            'badge'       => $parts[1] ?: 'Unverified',
            'nc_level'    => $parts[2],
            'is_verified' => (bool)($parts[3] ?? 0),
        ];
    }
    return $out;
}

/**
 * Renders colored skill tags based on badge level (Gold/Silver/Bronze etc.)
 */
function renderColoredSkillTags(array $skills, array $badgeCfg, array $subIcons, $limit = null): void {
    $count = 0;
    foreach ($skills as $s) {
        if ($limit && $count >= $limit) {
            echo '<span class="skill-tag-colored bg-slate-100 border border-slate-300 text-slate-600">+' . (count($skills) - $limit) . ' more</span>';
            break;
        }
        $icon = $subIcons[$s['sub']] ?? 'work';
        $cfg = $badgeCfg[$s['badge']] ?? $badgeCfg['Unverified'];
        
        $bgClass = $cfg['light-bg'] ?? 'bg-slate-50';
        $borderClass = $cfg['border'] ?? 'border-slate-300';
        $textClass = $cfg['text'] ?? 'text-slate-400';
        
        echo '<span class="skill-tag-colored ' . $bgClass . ' border ' . $borderClass . ' ' . $textClass . '">';
        echo '<span class="material-symbols-outlined">' . $icon . '</span>';
        echo htmlspecialchars($s['sub']);
        echo '</span> ';
        $count++;
    }
}

// Parse skills
$skills = parseSkillBadges($worker['skill_badge_data'] ?? '');

// Get highest badge level for this worker
$highestBadge = 'Unverified';
foreach ($skills as $s) {
    if ($s['badge'] !== 'Unverified') {
        $badgePriority = ['Gold' => 4, 'Silver' => 3, 'Bronze' => 2, 'Community' => 1, 'Unverified' => 0];
        if ($badgePriority[$s['badge']] > $badgePriority[$highestBadge]) {
            $highestBadge = $s['badge'];
        }
    }
}
$badgeIcon = $BADGE_CONFIG[$highestBadge]['icon'] ?? '';

// Function to offset coordinates randomly (320-500 meters)
function offsetLocation($lat, $lng) {
    if (empty($lat) || empty($lng)) {
        return ['lat' => $lat, 'lng' => $lng];
    }
    
    // Earth's radius in meters
    $earthRadius = 6371000;
    
    // Random distance between 320 and 500 meters
    $distance = mt_rand(320, 500);
    
    // Random angle in radians
    $angle = deg2rad(mt_rand(0, 360));
    
    // Calculate offset in radians
    $latOffset = $distance * cos($angle) / $earthRadius;
    $lngOffset = $distance * sin($angle) / ($earthRadius * cos(deg2rad($lat)));
    
    // Apply offset
    $newLat = $lat + rad2deg($latOffset);
    $newLng = $lng + rad2deg($lngOffset);
    
    return [
        'lat' => $newLat,
        'lng' => $newLng
    ];
}

// Get original coordinates
$originalLat = !empty($worker['latitude']) ? floatval($worker['latitude']) : 9.317;
$originalLng = !empty($worker['longitude']) ? floatval($worker['longitude']) : 125.96;

// Apply offset to protect privacy
$offsetCoords = offsetLocation($originalLat, $originalLng);
$maskedLat = $offsetCoords['lat'];
$maskedLng = $offsetCoords['lng'];

// Helper function to get initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Format minimum standard rate
$minRate = number_format($worker['minimum_standard_rate'], 2);
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $worker['full_name']; ?> | Worker Profile</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet"/>
    <link href="../includes/tour_engine.css" rel="stylesheet"/>
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                    },
                    boxShadow: {
                        'ambient': '0 20px 50px -12px rgba(0, 0, 0, 0.08)',
                        'glow': '0 0 20px rgba(20, 106, 245, 0.2)',
                    }
                },
            },
        };
    </script>
    
    <style type="text/tailwindcss">
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .gradient-bg {
            background: linear-gradient(135deg, #3b82f6 0%, #146af5 100%);
        }
        .profile-glow {
            box-shadow: 0 0 30px rgba(20, 106, 245, 0.3);
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        
        /* Badge icon styles (same as dashboard) */
        .badge-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 999px;
            font-size: 12px;
        }
        .badge-icon .material-symbols-outlined {
            font-size: 14px !important;
            font-variation-settings: 'FILL' 1;
        }
        
        /* Colored skill tag (same as dashboard) */
        .skill-tag-colored {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px 3px 6px; border-radius: 20px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
            border-width: 1px;
        }
        .skill-tag-colored .material-symbols-outlined {
            font-size: 13px !important;
        }
        
        /* Leaflet map container */
        #viewMap {
            height: 250px;
            width: 100%;
            z-index: 1;
            border-radius: 12px;
        }
        .custom-marker {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Fade out animation */
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .fade-out {
            animation: fadeOut 1s ease forwards;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300">

<main class="max-w-7xl mx-auto px-4 py-6 lg:py-8 lg:px-8">
    <button onclick="history.back()"
            class="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 dark:text-slate-400 hover:text-primary dark:hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-lg">arrow_back</span>
        Back
    </button>
    <div class="flex flex-col lg:flex-row gap-8">
        
        <!-- Left Column - Profile Card -->
        <aside class="w-full lg:w-1/3 space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-3xl overflow-hidden shadow-ambient border border-slate-200/50 dark:border-slate-800/50 lg:sticky lg:top-24">
                
                <!-- Cover Image -->
                <div class="h-32 bg-gradient-to-br from-blue-400 to-blue-600 relative">
                    <div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-white via-transparent to-transparent"></div>
                </div>
                
                <!-- Avatar -->
                <div class="px-6 pb-8 -mt-16 text-center relative z-10 lg:px-8">
                    <div class="relative inline-block mb-4">
                        <div class="w-28 h-28 lg:w-32 lg:h-32 rounded-full border-4 border-white dark:border-slate-900 overflow-hidden shadow-xl profile-glow bg-slate-200">
                            <?php 
                                $uploadsDir = "../uploads/profiles/";
                                $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($worker['full_name']) . "&background=2563eb&color=fff&size=128&bold=true&length=2";
                                $hasImage = !empty($worker['profile_pic']) && file_exists($uploadsDir . $worker['profile_pic']);
                                $initials = getInitials($worker['full_name']);
                            ?>
                            <?php if ($hasImage): ?>
                                <img src="<?php echo $uploadsDir . htmlspecialchars($worker['profile_pic']); ?>" class="w-full h-full object-cover" alt="<?php echo $worker['full_name']; ?>">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-2xl">
                                    <?php echo $initials; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($worker['availability_status'] == 'Available'): ?>
                        <div class="absolute bottom-1 right-1 w-6 h-6 bg-emerald-500 border-4 border-white dark:border-slate-900 rounded-full"></div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Name with badge icon (ONLY badge here) -->
                    <div class="flex items-center justify-center gap-1 mb-2">
                        <h1 class="text-2xl lg:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            <?php echo explode(' ', $worker['full_name'])[0]; ?>
                        </h1>
                        <?php if ($badgeIcon): ?>
                        <span class="badge-icon <?php echo $BADGE_CONFIG[$highestBadge]['bg'] ?? 'bg-slate-100'; ?> <?php echo $BADGE_CONFIG[$highestBadge]['text'] ?? 'text-slate-700'; ?>">
                            <span class="material-symbols-outlined"><?php echo $badgeIcon; ?></span>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Availability status only (removed badge) -->
                    <div class="mt-2 flex justify-center">
                        <span class="<?php echo ($worker['availability_status'] == 'Available') ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'; ?> px-3 py-1 rounded-full text-[10px] lg:text-xs font-semibold flex items-center gap-1.5">
                            <?php if ($worker['availability_status'] == 'Available'): ?>
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <?php else: ?>
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                            <?php endif; ?>
                            <?php echo $worker['availability_status'] ?? 'Unavailable'; ?>
                        </span>
                    </div>
                    
                    <!-- Location only (removed service category) -->
                    <div class="mt-4 flex items-center justify-center gap-1 text-slate-500 dark:text-slate-400 text-xs lg:text-sm font-medium">
                        <span class="material-icons-round text-red-400 text-base">location_on</span>
                        <?php echo $worker['municipality']; ?>
                    </div>
                    
                    <!-- Skills Section - All skills with colored tags -->
                    <?php if (!empty($skills)): ?>
                    <div class="mt-6 text-left">
                        <h3 class="text-[10px] lg:text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-3">Skills & Certifications</h3>
                        <div class="flex flex-wrap gap-1.5">
                            <?php renderColoredSkillTags($skills, $BADGE_CONFIG, $SUB_ICONS); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Minimum Standard Rate Display -->
                    <?php if ($worker['minimum_standard_rate'] > 0): ?>
                    <div class="mt-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-2xl border border-blue-100 dark:border-blue-800">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="material-icons-round text-primary text-xl">payments</span>
                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Minimum Rate</span>
                            </div>
                            <span class="text-2xl font-extrabold text-primary">₱<?php echo $minRate; ?></span>
                        </div>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500 text-left mt-2">Starting price for standard jobs</p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Desktop Action Buttons -->
                    <div class="hidden lg:block mt-8">
                        <?php if ($worker['availability_status'] == 'Available'): ?>
                            <a href="booking.php?worker_id=<?php echo $worker_id; ?>" id="tour-book-desktop" class="flex w-full py-4 gradient-bg text-white font-bold rounded-xl shadow-glow hover:scale-[1.02] active:scale-[0.98] transition-all items-center justify-center gap-2">
                                <span class="material-icons-round text-xl">event_available</span>
                                Book This Worker
                            </a>
                        <?php else: ?>
                            <button class="w-full py-4 bg-slate-400 text-white font-bold rounded-xl cursor-not-allowed opacity-60 flex items-center justify-center gap-2" disabled>
                                <span class="material-icons-round text-xl">block</span>
                                Currently Unavailable
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="border-t border-slate-100 dark:border-slate-800 p-6 lg:p-8 space-y-6">
                    <h3 class="text-[10px] lg:text-sm font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest">Contact Information</h3>
                    
                    <div class="space-y-4">
                        <!-- Email (if available) -->
                        <?php if(!empty($worker['email'])): ?>
                        <div class="flex items-center gap-4 group cursor-pointer">
                            <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-xl bg-purple-50 dark:bg-purple-900/20 text-purple-600 flex items-center justify-center group-hover:bg-purple-600 group-hover:text-white transition-colors duration-300">
                                <span class="material-icons-round text-lg">alternate_email</span>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500 font-medium">Email</p>
                                <p class="text-xs lg:text-sm font-semibold text-slate-700 dark:text-slate-200 truncate max-w-[180px]">
                                    <?php echo htmlspecialchars($worker['email']); ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Address -->
                        <div class="flex items-center gap-4 group cursor-pointer">
                            <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-xl bg-orange-50 dark:bg-orange-900/20 text-orange-600 flex items-center justify-center group-hover:bg-orange-600 group-hover:text-white transition-colors duration-300">
                                <span class="material-icons-round text-lg">place</span>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500 font-medium">Address</p>
                                <p class="text-xs lg:text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    <?php echo htmlspecialchars($worker['address'] ?: $worker['municipality']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Right Column - Details -->
        <div class="w-full lg:w-2/3 space-y-6 lg:space-y-8">
            
            <!-- Stats Row -->
            <div id="tour-stats" class="grid grid-cols-3 gap-3 md:gap-6">
                <div class="bg-white/80 dark:bg-slate-900/80 glass p-3 lg:p-6 rounded-2xl lg:rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-800/50 text-center flex flex-col items-center">
                    <div class="w-8 h-8 lg:w-12 lg:h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg lg:rounded-2xl flex items-center justify-center mb-2 lg:mb-4">
                        <span class="material-icons-round text-primary text-lg lg:text-2xl">star</span>
                    </div>
                    <h4 class="text-lg lg:text-3xl font-extrabold text-slate-900 dark:text-white">
                        <?php echo number_format($worker['average_rating'], 1); ?>
                    </h4>
                    <p class="text-[8px] lg:text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1 lg:mb-2 leading-tight">Avg. Rating</p>
                    <div class="hidden md:flex gap-0.5 text-amber-400">
                        <?php 
                        $rating = round($worker['average_rating']);
                        for($i=1; $i<=5; $i++) {
                            if($i <= $rating) {
                                echo '<span class="material-icons-round text-[16px]">star</span>';
                            } else {
                                echo '<span class="material-icons-round text-slate-300 dark:text-slate-600 text-[16px]">star</span>';
                            }
                        }
                        ?>
                    </div>
                </div>
                
                <div class="bg-white/80 dark:bg-slate-900/80 glass p-3 lg:p-6 rounded-2xl lg:rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-800/50 text-center flex flex-col items-center">
                    <div class="w-8 h-8 lg:w-12 lg:h-12 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg lg:rounded-2xl flex items-center justify-center mb-2 lg:mb-4">
                        <span class="material-icons-round text-indigo-600 text-lg lg:text-2xl">work_outline</span>
                    </div>
                    <h4 class="text-lg lg:text-3xl font-extrabold text-slate-900 dark:text-white">
                        <?php echo $worker['jobs_completed']; ?>
                    </h4>
                    <p class="text-[8px] lg:text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1 lg:mb-2 leading-tight">Jobs Done</p>
                    <span class="text-emerald-500 text-[8px] lg:text-xs font-bold flex items-center gap-0.5 lg:gap-1">
                        <span class="material-icons-round text-[10px] lg:text-[14px]">check_circle</span>
                        <span class="hidden md:inline">Successful</span>
                    </span>
                </div>
                
                <div class="bg-white/80 dark:bg-slate-900/80 glass p-3 lg:p-6 rounded-2xl lg:rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-800/50 text-center flex flex-col items-center">
                    <div class="w-8 h-8 lg:w-12 lg:h-12 bg-sky-100 dark:bg-sky-900/30 rounded-lg lg:rounded-2xl flex items-center justify-center mb-2 lg:mb-4">
                        <span class="material-icons-round text-sky-600 text-lg lg:text-2xl">history</span>
                    </div>
                    <h4 class="text-lg lg:text-3xl font-extrabold text-slate-900 dark:text-white">
                        <?php echo $worker['rating_count']; ?>
                    </h4>
                    <p class="text-[8px] lg:text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1 lg:mb-2 leading-tight">Reviews</p>
                    <span class="text-sky-500 text-[8px] lg:text-xs font-bold flex items-center gap-0.5 lg:gap-1">
                        <span class="material-icons-round text-[10px] lg:text-[14px]">chat_bubble_outline</span>
                        <span class="hidden md:inline">Feedback</span>
                    </span>
                </div>
            </div>
            
            <!-- About Section -->
            <section id="tour-about" class="bg-white dark:bg-slate-900 rounded-3xl p-6 lg:p-8 shadow-ambient border border-slate-200/50 dark:border-slate-800/50">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-blue-50 dark:bg-blue-900/20 text-primary flex items-center justify-center">
                        <span class="material-icons-round text-lg lg:text-xl">person</span>
                    </div>
                    <h2 class="text-lg lg:text-xl font-bold text-slate-900 dark:text-white">
                        About <?php echo explode(' ', $worker['full_name'])[0]; ?>
                    </h2>
                </div>
                <p class="text-sm lg:text-base text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
                    <?php echo $worker['bio'] ? nl2br(htmlspecialchars($worker['bio'])) : 'This worker has not written a bio yet.'; ?>
                </p>
            </section>
            
            <!-- Service Area Map -->
            <section class="bg-white dark:bg-slate-900 rounded-3xl overflow-hidden shadow-ambient border border-slate-200/50 dark:border-slate-800/50">
                <div class="p-6 lg:p-8 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center">
                            <span class="material-icons-round text-lg lg:text-xl">map</span>
                        </div>
                        <h2 class="text-lg lg:text-xl font-bold text-slate-900 dark:text-white">Service Area</h2>
                    </div>
                    <div class="mt-4 flex items-center gap-2 text-slate-500 dark:text-slate-400 text-xs lg:text-sm">
                        <span class="material-icons-round text-base">info</span>
                        <span>Approximate location in <?php echo $worker['municipality']; ?></span>
                    </div>
                </div>
                <div class="h-48 lg:h-64 relative bg-slate-100 dark:bg-slate-800 overflow-hidden">
                    <div id="viewMap" class="absolute inset-0"></div>
                    
                    <!-- Privacy Notice (disappears after 10 seconds) -->
                    <div id="privacyNotice" class="absolute top-4 left-1/2 -translate-x-1/2 glass px-4 py-2 rounded-full shadow-lg border border-white/40 dark:border-white/10 flex items-center gap-2 z-10">
                        <span class="material-icons-round text-primary text-sm">privacy_tip</span>
                        <span class="text-[10px] lg:text-xs font-bold text-slate-700 dark:text-slate-200">Location is approximations only. Exact location is not shared for privacy reasons.</span>
                        <button onclick="fadeOutNotice()" class="ml-2 text-slate-400 hover:text-slate-600">
                            <span class="material-icons-round text-sm">close</span>
                        </button>
                    </div>
                    
                    <!-- Municipality indicator -->
                    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 bg-white/90 dark:bg-slate-900/90 px-3 py-1.5 rounded-full shadow-md border border-white/50 dark:border-slate-700 flex items-center gap-1.5 z-10">
                        <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
                        <span class="text-[10px] lg:text-xs font-bold text-slate-700 dark:text-slate-200"><?php echo $worker['municipality']; ?> Area</span>
                    </div>
                </div>
            </section>
            
            <!-- Portfolio Section -->
            <?php if(count($portfolio) > 0): ?>
            <section class="bg-white dark:bg-slate-900 rounded-3xl p-6 lg:p-8 shadow-ambient border border-slate-200/50 dark:border-slate-800/50">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-green-50 dark:bg-green-900/20 text-green-600 flex items-center justify-center">
                        <span class="material-icons-round text-lg lg:text-xl">photo_library</span>
                    </div>
                    <h2 class="text-lg lg:text-xl font-bold text-slate-900 dark:text-white">Work Portfolio</h2>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3 lg:gap-4">
                    <?php foreach ($portfolio as $img): ?>
                        <?php
                            $imagePath = "../uploads/portfolios/" . htmlspecialchars($img['image_path']);
                            if (file_exists($imagePath)):
                        ?>
                        <a href="<?php echo $imagePath; ?>" target="_blank" class="group relative aspect-square rounded-xl overflow-hidden bg-slate-100 dark:bg-slate-800">
                            <img src="<?php echo $imagePath; ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300" alt="Portfolio">
                            <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                <span class="material-icons-round text-white text-2xl">zoom_in</span>
                            </div>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
            
            <!-- Reviews Section -->
            <section class="space-y-6 pb-12">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 lg:w-10 lg:h-10 rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-500 flex items-center justify-center">
                            <span class="material-icons-round text-lg lg:text-xl">star_outline</span>
                        </div>
                        <h2 class="text-lg lg:text-xl font-bold text-slate-900 dark:text-white">
                            Client Reviews (<?php echo count($reviews); ?>)
                        </h2>
                    </div>
                    <?php if (count($reviews) > 3): ?>
                    <button class="text-primary text-xs lg:text-sm font-bold hover:underline">View All</button>
                    <?php endif; ?>
                </div>

                <?php if(count($reviews) > 0): ?>
                    <?php foreach ($reviews as $rev): ?>
                        <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 lg:p-8 shadow-ambient border border-slate-200/50 dark:border-slate-800/50 transition-transform hover:translate-y-[-4px]">
                            <div class="flex items-start justify-between mb-6">
                                <div class="flex items-center gap-3 lg:gap-4">
                                    <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-xl lg:rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-extrabold text-base lg:text-lg shadow-lg">
                                        <?php echo getInitials($rev['reviewer_name']); ?>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-slate-900 dark:text-white text-sm lg:text-base">
                                            <?php echo htmlspecialchars($rev['reviewer_name']); ?>
                                        </h4>
                                        <p class="text-[10px] lg:text-xs font-medium text-slate-400 flex items-center gap-1">
                                            <span class="material-icons-round text-[12px] lg:text-[14px]">calendar_today</span>
                                            <?php echo date("M d, Y", strtotime($rev['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex gap-0.5 text-amber-400">
                                    <?php 
                                    for($i=0; $i<5; $i++) {
                                        if($i < $rev['rating']) {
                                            echo '<span class="material-icons-round text-sm lg:text-base">star</span>';
                                        } else {
                                            echo '<span class="material-icons-round text-sm lg:text-base text-slate-300 dark:text-slate-600">star</span>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="relative italic text-slate-600 dark:text-slate-300 pl-6 lg:pl-8 font-medium leading-relaxed text-sm lg:text-base">
                                <span class="absolute left-0 top-0 text-slate-200 dark:text-slate-700 text-3xl lg:text-5xl font-serif leading-none">"</span>
                                <?php echo htmlspecialchars($rev['comment']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bg-white dark:bg-slate-900 rounded-3xl p-12 text-center border border-slate-200/50 dark:border-slate-800/50">
                        <div class="w-16 h-16 mx-auto mb-4 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center">
                            <span class="material-icons-round text-slate-400 text-3xl">chat</span>
                        </div>
                        <h3 class="text-lg font-bold mb-2">No reviews yet</h3>
                        <p class="text-slate-500 dark:text-slate-400 text-sm">Be the first to book and leave a review!</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<!-- Mobile Sticky Booking Bar -->
<div class="js-tour-bottom-inset fixed bottom-0 left-0 right-0 p-4 lg:hidden z-50">
    <div class="glass border border-white/20 rounded-2xl p-2 shadow-2xl">
        <?php if ($worker['availability_status'] == 'Available'): ?>
            <a href="booking.php?worker_id=<?php echo $worker_id; ?>" id="tour-book-mobile" class="w-full py-4 gradient-bg text-white font-bold rounded-xl shadow-glow active:scale-95 transition-transform flex items-center justify-center gap-2">
                <span class="material-icons-round">bolt</span>
                Book This Worker
            </a>
        <?php else: ?>
            <button class="w-full py-4 bg-slate-400 text-white font-bold rounded-xl cursor-not-allowed opacity-60 flex items-center justify-center gap-2" disabled>
                <span class="material-icons-round">block</span>
                Unavailable
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Spacer for mobile -->
<div class="h-24 lg:hidden"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Initialize Map with masked coordinates
    setTimeout(function() {
        var workerLat = <?php echo $maskedLat; ?>;
        var workerLng = <?php echo $maskedLng; ?>;
        var hasLocation = <?php echo !empty($worker['latitude']) ? 'true' : 'false'; ?>;
        var municipality = "<?php echo $worker['municipality']; ?>";

        var map = L.map('viewMap', {
            zoomControl: false,
            attributionControl: false,
            scrollWheelZoom: false
        }).setView([workerLat, workerLng], 14);

        // Use a clean, modern map tile
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            subdomains: 'abcd',
            maxZoom: 19,
            minZoom: 10
        }).addTo(map);

        // Add service area circle (showing approximate range)
        L.circle([workerLat, workerLng], {
            color: '#146af5',
            fillColor: '#146af5',
            fillOpacity: 0.1,
            weight: 2,
            radius: 400
        }).addTo(map);

        // Add marker with custom icon
        var customIcon = L.divIcon({
            className: 'custom-marker',
            html: '<div class="relative"><span class="material-icons-round text-4xl text-primary filter drop-shadow-lg">location_on</span><div class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-4 h-1 bg-black/20 blur-sm rounded-full"></div></div>',
            iconSize: [32, 32],
            iconAnchor: [16, 32]
        });

        var marker = L.marker([workerLat, workerLng], {icon: customIcon}).addTo(map);
        
        if (hasLocation) {
            marker.bindPopup("<b>Service Base");
        } else {
            marker.bindPopup("Exact location not shared.<br>Showing " + municipality + " municipality.");
        }
        
        marker.openPopup();

        // Fix map display
        setTimeout(function() {
            map.invalidateSize();
        }, 200);
    }, 300);

    // Function to fade out privacy notice after 10 seconds
    function fadeOutNotice() {
        var notice = document.getElementById('privacyNotice');
        if (notice) {
            notice.classList.add('fade-out');
            setTimeout(function() {
                if (notice && notice.parentNode) {
                    notice.style.display = 'none';
                }
            }, 1000);
        }
    }

    // Auto fade out after 10 seconds
    setTimeout(function() {
        fadeOutNotice();
    }, 10000);

    // Manual close function
    function closeNotice() {
        var notice = document.getElementById('privacyNotice');
        if (notice) {
            notice.style.display = 'none';
        }
    }
</script>

<!-- Dark Mode Toggle -->
<script>
    // Check for saved dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        document.documentElement.classList.add('dark');
    }
    
    // Listen for dark mode toggle from navbar
    document.addEventListener('darkModeToggle', function() {
        const isDark = document.documentElement.classList.contains('dark');
        localStorage.setItem('darkMode', isDark);
    });
</script>

<!-- ══════════════════════════════════════════
     CLIENT FIRST-LOGIN TOUR — part 1 of 2
     Continues on booking.php once "Continue" is clicked below.
     "Skip tour" ends it here and marks it seen immediately.
══════════════════════════════════════════ -->
<?php if ($show_client_tour): ?>
<script src="../includes/tour_engine.js"></script>
<script>
AbiTour.run({
    bottomInsetSelectors: ['.js-tour-bottom-inset'],
    steps: [
        {
            target: null, position: 'center',
            icon: 'waving_hand', iconColor: '#146af5',
            title: 'Welcome to Abilisto! 👋',
            body: 'Let\'s quickly show you how to check out a worker and book the right one for your job.',
        },
        {
            target: '#tour-stats', position: 'bottom',
            icon: 'star', iconColor: '#f59e0b',
            title: 'Check Their Track Record',
            body: 'Rating, jobs completed, and reviews — everything you need to decide if they\'re a good fit.',
        },
        {
            target: '#tour-about', position: 'bottom',
            icon: 'person', iconColor: '#146af5',
            title: 'About & Skills',
            body: 'Read their bio and see their skill certifications before reaching out.',
        },
        {
            target: ['#tour-book-desktop', '#tour-book-mobile'], position: 'top',
            icon: 'event_available', iconColor: '#16a34a',
            title: 'Ready to Book?',
            body: 'When you\'re happy with what you see, this button starts your booking — let\'s walk through it together.',
            ctaLabel: 'Continue <span class="material-symbols-rounded" style="font-size:16px">arrow_forward</span>',
        },
    ],
    onSkip: function () {
        fetch('worker_details.php?id=<?php echo (int)$worker_id; ?>&mark_tour_seen=1', { method: 'POST' }).catch(() => {});
    },
    onComplete: function () {
        // Don't mark has_seen_tour yet — part 2 on booking.php does that,
        // once the client has actually seen the whole continuous tour.
        window.location.href = 'booking.php?worker_id=<?php echo (int)$worker_id; ?>&tour=1';
    }
});
</script>
<?php endif; ?>

</body>
</html>