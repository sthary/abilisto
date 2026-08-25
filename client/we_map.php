<?php
// client/we_map.php
include '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../includes/functions/feature_flags.php';
requireFeatureEnabled($conn, 'feature_wemap_enabled', 'dashboard.php');

$client_id = intval($_SESSION['user_id']);

// ── Get client lat/lng ──────────────────────────────────────────────────────
$client_sql = "SELECT latitude, longitude, full_name FROM users WHERE id = ?";
$client_stmt = $conn->prepare($client_sql);
$client_stmt->execute([$client_id]);
$client_data = $client_stmt->fetch() ?: [];
$clientLat  = floatval($client_data['latitude']  ?? 9.33796201);
$clientLng  = floatval($client_data['longitude'] ?? 125.97473145);
$clientName = $client_data['full_name'] ?? 'there';

// ── Function to offset coordinates by random distance (300-600 meters) ─────
function offsetCoordinates($lat, $lng, $minMeters = 300, $maxMeters = 600) {
    if ($lat === null || $lng === null || $lat == 0 || $lng == 0) {
        return ['lat' => $lat, 'lng' => $lng];
    }
    
    // Earth's radius in meters
    $earthRadius = 6371000;
    
    // Generate random distance between min and max meters
    $distance = mt_rand($minMeters, $maxMeters);
    
    // Generate random angle in radians (0 to 2π)
    $angle = mt_rand() / mt_getrandmax() * 2 * M_PI;
    
    // Calculate offset in radians
    $latOffset = $distance / $earthRadius * 180 / M_PI;
    $lngOffset = $distance / $earthRadius * 180 / M_PI / cos(deg2rad($lat));
    
    // Apply offset in random direction
    $newLat = $lat + $latOffset * sin($angle);
    $newLng = $lng + $lngOffset * cos($angle);
    
    return [
        'lat' => round($newLat, 8),
        'lng' => round($newLng, 8),
        'original_lat' => $lat,
        'original_lng' => $lng,
        'offset_distance' => round($distance)
    ];
}

// ── Fetch all active workers with their primary skill from worker_skills ───
// IMPORTANT: Only fetch workers who have location_sharing_enabled = 1
$workers_sql = "
    SELECT 
        u.id,
        u.full_name,
        u.profile_pic,
        u.address,
        u.municipality,
        u.latitude,
        u.longitude,
        u.location_sharing_enabled,
        COALESCE(ws.sub_category, wp.service_category) as service_category,
        wp.average_rating,
        wp.rating_count,
        wp.jobs_completed,
        wp.availability_status,
        wp.verification_status,
        wp.is_verified,
        wp.is_tesda_verified,
        wp.bio,
        ws.badge_level,
        ws.nc_level
    FROM users u
    INNER JOIN worker_profiles wp ON u.id = wp.user_id
    LEFT JOIN (
        SELECT worker_id, sub_category, badge_level, nc_level
        FROM worker_skills ws1
        WHERE is_verified = TRUE
        AND NOT EXISTS (
            SELECT 1 FROM worker_skills ws2
            WHERE ws2.worker_id = ws1.worker_id
            AND ws2.is_verified = TRUE
            AND (CASE ws2.badge_level WHEN 'Gold' THEN 1 WHEN 'Silver' THEN 2 WHEN 'Bronze' THEN 3 WHEN 'Community' THEN 4 ELSE 0 END)
                < (CASE ws1.badge_level WHEN 'Gold' THEN 1 WHEN 'Silver' THEN 2 WHEN 'Bronze' THEN 3 WHEN 'Community' THEN 4 ELSE 0 END)
        )
    ) ws ON u.id = ws.worker_id
    WHERE u.role = 'worker'
      AND wp.is_active = TRUE
      AND u.latitude IS NOT NULL
      AND u.longitude IS NOT NULL
      AND u.latitude != 0
      AND u.longitude != 0
      AND u.location_sharing_enabled = TRUE  -- Only workers who have consented
      AND (6371 * acos(LEAST(1, GREATEST(-1,
              cos(radians(?)) * cos(radians(u.latitude)) *
              cos(radians(u.longitude) - radians(?)) +
              sin(radians(?)) * sin(radians(u.latitude))
          )))) <= 30  -- 30km geofence
    ORDER BY wp.average_rating DESC
";
$workers_stmt = $conn->prepare($workers_sql);
$workers_stmt->execute([$clientLat, $clientLng, $clientLat]);
$workers = [];
$workers_with_offset = [];

while ($row = $workers_stmt->fetch()) {
    $workers[] = $row;
    
    // Apply offset to each worker's coordinates
    $offset = offsetCoordinates(
        floatval($row['latitude']), 
        floatval($row['longitude']),
        300, // minimum 300 meters
        600  // maximum 600 meters
    );
    
    $workers_with_offset[] = [
        'id' => $row['id'],
        'full_name' => $row['full_name'],
        'profile_pic' => $row['profile_pic'],
        'address' => $row['address'],
        'municipality' => $row['municipality'],
        'service_category' => $row['service_category'] ?? 'General Services',
        'badge_level' => $row['badge_level'] ?? null,
        'nc_level' => $row['nc_level'] ?? null,
        'average_rating' => $row['average_rating'],
        'rating_count' => $row['rating_count'],
        'jobs_completed' => $row['jobs_completed'],
        'availability_status' => $row['availability_status'],
        'verification_status' => $row['verification_status'] ?? ($row['badge_level'] ?? 'Unverified'),
        'is_verified' => $row['is_verified'] || !empty($row['badge_level']),
        'is_tesda_verified' => $row['is_tesda_verified'],
        'bio' => $row['bio'],
        // Use offset coordinates for display
        'display_lat' => $offset['lat'],
        'display_lng' => $offset['lng'],
        'offset_distance' => $offset['offset_distance']
    ];
}

// ── Collect unique categories from worker_skills ───────────────────────────
$categories_sql = "
    SELECT DISTINCT sub_category
    FROM worker_skills
    WHERE is_verified = TRUE
    ORDER BY sub_category
";
$categories_stmt = $conn->prepare($categories_sql);
$categories_stmt->execute();
$categories = [];
while ($cat_row = $categories_stmt->fetch()) {
    $categories[] = $cat_row['sub_category'];
}

// ── Category icons map (Material Symbols) ─────────────────────────────────
$cat_icons = [
    'Electrical'  => 'bolt',
    'Electronics' => 'settings_suggest',
    'Plumbing'    => 'plumbing',
    'Carpentry'   => 'home_repair_service',
    'Painting'    => 'format_paint',
    'Welding'     => 'construction',
    'Masonry'     => 'handyman',
    'HVAC'        => 'ac_unit',
    'Cleaning'    => 'cleaning_services',
    'Gardening'   => 'grass',
    'Motorcycle'  => 'two_wheeler',
    'Caregiving'  => 'elderly',
    'Domestic Work' => 'cleaning_services',
    'Graphic_Design' => 'palette',
    'Photography' => 'photo_camera',
    'Arts Crafts' => 'brush',
];

// ── Get initials from name ─────────────────────────────────────────────────
function getInitials($name) {
    $parts = explode(' ', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

// ── Avatar URL or initials color ───────────────────────────────────────────
function getAvatarData($worker) {
    if (!empty($worker['profile_pic'])) {
        return ['type' => 'image', 'url' => "../uploads/profiles/" . $worker['profile_pic']];
    }
    $colors = ['bg-blue-500', 'bg-primary', 'bg-indigo-500', 'bg-purple-500', 'bg-emerald-500', 'bg-amber-500'];
    $color = $colors[$worker['id'] % count($colors)];
    return ['type' => 'initials', 'initials' => getInitials($worker['full_name']), 'color' => $color];
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>We Map · Abilisto</title>
    
    <!-- Tailwind & Fonts -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet"/>
    
    <!-- Leaflet with CartoDB -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style type="text/tailwindcss">
        :root {
            --primary-color: #146af5;
            --background-light: #F8FAFC;
            --background-dark: #0F172A;
        }
        @layer components {
            .glass {
                @apply bg-white/70 dark:bg-slate-900/70 backdrop-blur-xl border border-white/20 dark:border-white/10;
            }
            .profile-gradient {
                background: linear-gradient(135deg, #146af5 0%, #8b5cf6 100%);
            }
            .no-scrollbar::-webkit-scrollbar {
                display: none;
            }
            .no-scrollbar {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            .leaflet-container {
                background: #f8fafc !important;
            }
            .leaflet-control-attribution {
                font-size: 9px !important;
                background: rgba(255,255,255,0.7) !important;
                padding: 2px 5px !important;
                border-radius: 20px !important;
                backdrop-filter: blur(5px) !important;
            }
            .leaflet-control-zoom {
                display: none !important;
            }
            /* Force circular containers for all avatars */
            .avatar-circle {
                border-radius: 9999px !important;
                overflow: hidden !important;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .avatar-circle img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                border-radius: 9999px !important;
            }
            /* Line clamp for long addresses */
            .line-clamp-2 {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            /* Bottom sheet animation */
            .bottom-sheet-enter {
                transform: translateY(100%);
            }
            .bottom-sheet-enter-active {
                transform: translateY(0);
                transition: transform 0.3s ease-out;
            }
            .bottom-sheet-exit {
                transform: translateY(0);
            }
            .bottom-sheet-exit-active {
                transform: translateY(100%);
                transition: transform 0.3s ease-in;
            }
            /* Privacy toast animation */
            .privacy-toast {
                animation: slideDown 0.5s ease-out, fadeOut 0.5s ease-in 9.5s forwards;
            }
            @keyframes slideDown {
                from { transform: translateY(-100%); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; }
                to { opacity: 0; }
            }
        }
    </style>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                    },
                },
            },
        };
    </script>
</head>
<body class="font-display bg-[var(--background-light)] dark:bg-[var(--background-dark)] text-slate-900 dark:text-slate-100 overflow-hidden h-screen w-screen transition-colors duration-300">

<!-- Privacy Caution Banner (disappears after 10 seconds) -->
<div id="privacy-banner" class="fixed top-20 left-1/2 -translate-x-1/2 z-50 privacy-toast">
    <div class="glass px-6 py-3 rounded-full shadow-2xl flex items-center gap-3 text-sm">
        <span class="material-symbols-outlined text-primary">privacy_tip</span>
        <span class="font-medium">Locations are approximations only</span>
        <button onclick="dismissPrivacyBanner()" class="ml-2 p-1 hover:bg-white/20 rounded-full transition-colors">
            <span class="material-symbols-outlined text-sm">close</span>
        </button>
    </div>
</div>

<!-- Map Background Layer -->
<div class="absolute inset-0 z-0" id="map-canvas"></div>

<!-- Overlay Blur for Cards -->
<div class="absolute inset-0 z-10 bg-black/10 backdrop-blur-[2px] opacity-0 pointer-events-none transition-opacity duration-500" id="map-overlay-blur" onclick="closeAllSheets()"></div>

<!-- Desktop Filter Bar (hidden on mobile) -->
<div class="fixed top-4 left-0 right-0 z-30 px-4 transition-opacity duration-300 hidden md:block" id="filter-bar-desktop">
    <div class="max-w-max mx-auto glass flex items-center p-1.5 rounded-full shadow-2xl overflow-x-auto no-scrollbar whitespace-nowrap scroll-smooth">
        <div class="flex items-center space-x-1" id="filter-container-desktop">
            <button class="filter-btn px-5 py-2 rounded-full bg-primary text-white font-medium text-sm transition-all shadow-lg shadow-primary/20" data-cat="all" onclick="filterWorkers('all', this)">All</button>
            <?php foreach ($categories as $cat): 
                $icon = $cat_icons[$cat] ?? 'build';
            ?>
            <button class="filter-btn px-5 py-2 rounded-full hover:bg-white/40 dark:hover:bg-white/10 text-sm font-medium transition-colors flex items-center gap-2" data-cat="<?php echo htmlspecialchars($cat); ?>" onclick="filterWorkers('<?php echo htmlspecialchars($cat); ?>', this)">
                <span class="material-symbols-outlined text-lg"><?php echo $icon; ?></span>
                <?php echo htmlspecialchars($cat); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Mobile Filter Button (visible only on mobile) -->
<div class="fixed bottom-24 left-1/2 -translate-x-1/2 z-30 md:hidden" id="mobile-filter-button">
    <button class="glass px-6 py-3 rounded-full shadow-2xl flex items-center gap-2 text-sm font-semibold" onclick="openFilterSheet()">
        <span class="material-symbols-outlined text-primary">filter_list</span>
        Filter Categories
        <span class="bg-primary text-white text-xs px-2 py-0.5 rounded-full" id="active-filter-count">All</span>
    </button>
</div>

<!-- Mobile Filter Bottom Sheet -->
<div class="fixed inset-x-0 bottom-0 z-50 transform translate-y-full transition-transform duration-300 ease-out md:hidden" id="filter-bottom-sheet">
    <div class="glass rounded-t-3xl max-h-[70vh] overflow-hidden flex flex-col">
        <!-- Sheet handle -->
        <div class="flex justify-center pt-4 pb-2">
            <div class="w-12 h-1 bg-slate-300 dark:bg-slate-600 rounded-full"></div>
        </div>
        
        <!-- Sheet header -->
        <div class="px-6 pb-4 flex items-center justify-between">
            <h3 class="font-bold text-lg">Filter by Category</h3>
            <button class="p-2 hover:bg-white/20 rounded-full transition-colors" onclick="closeFilterSheet()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <!-- Filter options -->
        <div class="px-4 pb-6 overflow-y-auto no-scrollbar">
            <div class="flex flex-col gap-2" id="filter-container-mobile">
                <!-- All button -->
                <button class="filter-btn-mobile w-full px-4 py-3 rounded-xl bg-primary text-white font-medium transition-all flex items-center gap-3" data-cat="all" onclick="filterWorkersMobile('all', this)">
                    <span class="material-symbols-outlined">workspaces</span>
                    <span>All Workers</span>
                    <span class="ml-auto bg-white/20 px-2 py-0.5 rounded-full text-xs"><?php echo count($workers_with_offset); ?></span>
                </button>
                
                <?php foreach ($categories as $cat): 
                    $icon = $cat_icons[$cat] ?? 'build';
                    $count = count(array_filter($workers_with_offset, fn($w) => $w['service_category'] === $cat));
                ?>
                <button class="filter-btn-mobile w-full px-4 py-3 rounded-xl hover:bg-white/10 dark:hover:bg-white/5 font-medium transition-all flex items-center gap-3" data-cat="<?php echo htmlspecialchars($cat); ?>" onclick="filterWorkersMobile('<?php echo htmlspecialchars($cat); ?>', this)">
                    <span class="material-symbols-outlined text-primary"><?php echo $icon; ?></span>
                    <span><?php echo htmlspecialchars($cat); ?></span>
                    <span class="ml-auto text-xs text-slate-500 dark:text-slate-400"><?php echo $count; ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Apply button -->
        <div class="p-4 border-t border-white/10">
            <button class="w-full bg-primary hover:bg-blue-600 text-white font-bold py-3 rounded-xl transition-all" onclick="closeFilterSheet()">
                Apply Filters
            </button>
        </div>
    </div>
</div>

<!-- Top Right Controls -->
<div class="fixed top-4 right-4 z-30 flex flex-col gap-2">
    <!-- Theme Toggle -->
    <button class="glass w-10 h-10 md:w-12 md:h-12 flex items-center justify-center rounded-full shadow-xl hover:scale-105 transition-transform" onclick="toggleTheme()">
        <span class="material-symbols-outlined dark:hidden text-amber-500">light_mode</span>
        <span class="material-symbols-outlined hidden dark:block text-blue-400">dark_mode</span>
    </button>
    
    <!-- Fullscreen/Immersive Mode Toggle -->
    <button class="glass w-10 h-10 md:w-12 md:h-12 flex items-center justify-center rounded-full shadow-xl hover:scale-105 transition-transform" onclick="toggleImmersiveMode()" id="immersive-toggle">
        <span class="material-symbols-outlined text-slate-600 dark:text-slate-300" id="immersive-icon">visibility</span>
    </button>
</div>

<!-- Back to Dashboard -->
<div class="fixed top-4 left-4 z-30">
    <a href="dashboard.php" class="glass w-10 h-10 md:w-12 md:h-12 flex items-center justify-center rounded-full shadow-xl hover:scale-105 transition-transform">
        <span class="material-symbols-outlined text-slate-600 dark:text-slate-300">arrow_back</span>
    </a>
</div>

<!-- Zoom Controls -->
<div class="fixed bottom-32 right-4 z-30 flex flex-col gap-2 transition-opacity duration-300" id="zoom-controls">
    <button class="glass w-12 h-12 flex items-center justify-center rounded-2xl shadow-xl active:scale-95 transition-all" onclick="zoomIn()">
        <span class="material-symbols-outlined">add</span>
    </button>
    <button class="glass w-12 h-12 flex items-center justify-center rounded-2xl shadow-xl active:scale-95 transition-all" onclick="zoomOut()">
        <span class="material-symbols-outlined">remove</span>
    </button>
    <button class="glass w-12 h-12 flex items-center justify-center rounded-2xl shadow-xl active:scale-95 transition-all mt-2" onclick="locateMe()">
        <span class="material-symbols-outlined text-primary">my_location</span>
    </button>
</div>

<!-- Worker Profile Card (Slide-up) -->
<div class="fixed inset-x-0 bottom-0 z-50 p-4 md:p-6 opacity-0 translate-y-full pointer-events-none transition-all duration-500 ease-out flex justify-center" id="worker-profile-card">
    <div class="glass w-full max-w-lg rounded-[24px] md:rounded-[32px] overflow-hidden shadow-2xl ring-1 ring-black/5">
        <div class="profile-gradient p-4 md:p-6 relative">
            <div class="w-12 h-1 bg-white/30 rounded-full mx-auto mb-4 md:hidden"></div>
            <button class="absolute top-4 right-4 bg-white/20 hover:bg-white/30 p-1.5 rounded-full transition-colors" onclick="closeProfile()">
                <span class="material-symbols-outlined text-white text-sm">close</span>
            </button>
            <div class="flex items-center gap-3 md:gap-4">
                <!-- Fixed: Proper circular container for avatar -->
                <div class="w-12 h-12 md:w-16 md:h-16 avatar-circle bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center" id="profile-avatar">
                    <span class="text-white font-bold text-lg md:text-2xl" id="profile-initials">SD</span>
                </div>
                <div class="flex-1">
                    <h3 class="text-white font-bold text-lg md:text-xl flex items-center gap-1.5 leading-tight">
                        <span id="profile-name">Uy! I'm Sthary.</span>
                        <span class="material-symbols-outlined text-blue-300 text-sm verified-badge" style="font-variation-settings: 'FILL' 1; display: none;">verified</span>
                    </h3>
                    <p class="text-white/80 text-[10px] md:text-xs font-medium uppercase tracking-widest mt-0.5" id="profile-category">Electrical Expert</p>
                </div>
            </div>
        </div>
        <div class="px-4 py-4 md:p-6 bg-white/40 dark:bg-slate-900/40 backdrop-blur-xl">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-1.5 max-w-[70%]">
                    <span class="material-symbols-outlined text-primary text-lg flex-shrink-0">location_on</span>
                    <span class="text-xs md:text-sm font-medium line-clamp-2" id="profile-location">Loading address...</span>
                </div>
                <div class="flex items-center gap-1 bg-amber-100 dark:bg-amber-900/30 px-2 py-1 rounded-lg flex-shrink-0">
                    <span class="material-symbols-outlined text-amber-500 text-xs" style="font-variation-settings: 'FILL' 1">star</span>
                    <span class="text-xs font-bold text-amber-600 dark:text-amber-500" id="profile-rating">5.0</span>
                </div>
            </div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-1">
                <span class="material-symbols-outlined text-xs">privacy_tip</span>
                <span>Location is approximate for privacy</span>
            </div>
            <a href="#" id="profile-cta" class="w-full bg-primary hover:bg-blue-600 text-white font-bold py-3 md:py-3.5 rounded-xl md:rounded-2xl shadow-lg shadow-primary/20 transition-all active:scale-95 text-sm text-center block">
                View Full Profile
            </a>
        </div>
    </div>
</div>

<!-- Bottom Stats Bar -->
<div class="fixed bottom-6 inset-x-0 z-40 px-4 pointer-events-none transition-opacity duration-300" id="bottom-bar">
    <div class="max-w-screen-md mx-auto pointer-events-auto">
        <div class="glass rounded-full px-4 md:px-8 py-2 md:py-3 flex items-center justify-between md:justify-center gap-2 md:gap-8 shadow-2xl ring-1 ring-black/5">
            <div class="flex items-center gap-2 px-2">
                <span class="material-symbols-outlined text-primary/80 text-xl md:text-2xl">groups</span>
                <span class="hidden sm:block text-xs md:text-sm font-medium"><strong id="total-workers"><?php echo count($workers_with_offset); ?></strong> Total</span>
                <span class="sm:hidden text-xs font-bold" id="total-workers-mobile"><?php echo count($workers_with_offset); ?></span>
            </div>
            <div class="w-px h-6 bg-slate-300 dark:bg-slate-700"></div>
            <div class="flex items-center gap-2 px-2">
                <span class="material-symbols-outlined text-emerald-500/80 text-xl md:text-2xl">check_circle</span>
                <span class="hidden sm:block text-xs md:text-sm font-medium"><strong id="available-workers"><?php echo count(array_filter($workers_with_offset, fn($w) => $w['availability_status'] === 'Available')); ?></strong> Available</span>
                <span class="sm:hidden text-xs font-bold" id="available-workers-mobile"><?php echo count(array_filter($workers_with_offset, fn($w) => $w['availability_status'] === 'Available')); ?></span>
            </div>
            <div class="w-px h-6 bg-slate-300 dark:bg-slate-700"></div>
            <div class="flex items-center gap-2 px-2">
                <span class="material-symbols-outlined text-blue-500/80 text-xl md:text-2xl">verified</span>
                <span class="hidden sm:block text-xs md:text-sm font-medium"><strong id="verified-workers"><?php echo count(array_filter($workers_with_offset, fn($w) => $w['is_verified'] == 1)); ?></strong> Verified</span>
                <span class="sm:hidden text-xs font-bold" id="verified-workers-mobile"><?php echo count(array_filter($workers_with_offset, fn($w) => $w['is_verified'] == 1)); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="fixed top-20 right-4 z-50 glass px-4 py-3 rounded-xl shadow-2xl transform translate-x-[120%] transition-transform duration-300 flex items-center gap-3 max-w-[280px]">
    <span class="material-symbols-outlined text-primary">info</span>
    <span id="toast-msg" class="text-sm font-medium">Loading...</span>
</div>

<!-- Empty State -->
<div id="empty-state" class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-40 text-center hidden pointer-events-none">
    <div class="text-5xl mb-3 opacity-40">📡</div>
    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No workers in this category</p>
</div>

<script>
// ────────────────────────────────────────────────────────────────────
// DATA FROM PHP - USING OFFSET COORDINATES ONLY
// ────────────────────────────────────────────────────────────────────
const CLIENT_LAT = <?php echo $clientLat; ?>;
const CLIENT_LNG = <?php echo $clientLng; ?>;
const CLIENT_NAME = <?php echo json_encode(explode(' ', $clientName)[0]); ?>;

// IMPORTANT: Only offset coordinates are passed to JavaScript
// Real coordinates NEVER leave the server
const WORKERS = <?php
    $workers_js = array_map(function($w) {
        $avatarData = getAvatarData($w);
        return [
            'id'           => $w['id'],
            'name'         => $w['full_name'],
            'initials'     => getInitials($w['full_name']),
            'avatar_type'  => $avatarData['type'],
            'avatar_url'   => $avatarData['url'] ?? null,
            'avatar_color' => $avatarData['color'] ?? 'bg-primary',
            'address'      => $w['address'] ?: ($w['municipality'] . ', Surigao del Sur'),
            'municipality' => $w['municipality'],
            // Using OFFSET coordinates for display
            'lat'          => floatval($w['display_lat']),
            'lng'          => floatval($w['display_lng']),
            'category'     => $w['service_category'],
            'badge_level'  => $w['badge_level'],
            'nc_level'     => $w['nc_level'],
            'rating'       => floatval($w['average_rating']),
            'rating_count' => intval($w['rating_count']),
            'jobs'         => intval($w['jobs_completed']),
            'status'       => $w['availability_status'],
            'verif_status' => $w['verification_status'],
            'is_verified'  => (bool)$w['is_verified'],
            'is_tesda'     => (bool)$w['is_tesda_verified'],
            'bio'          => $w['bio'] ?? '',
            'offset_distance' => $w['offset_distance'] // For informational purposes
        ];
    }, $workers_with_offset);
    echo json_encode($workers_js, JSON_HEX_TAG | JSON_HEX_APOS);
?>;

const GREETINGS = ['Uy!', 'Hi!', 'Kamusta!', 'Hello!', 'Hoy!', 'Hey!'];
const CAT_ICONS = <?php echo json_encode($cat_icons); ?>;

// ────────────────────────────────────────────────────────────────────
// STATE
// ────────────────────────────────────────────────────────────────────
let map;
let allMarkers = [];
let currentFilter = 'all';
let activeMarker = null;
let toastTimer;
let immersiveMode = false;

// Maximum zoom level - restricts to ~1500ft height (zoom level 15 is about 1500ft)
const MAX_ZOOM = 15;

// CartoDB tile layers - Light and Dark variants
const cartoDBLayers = {
    light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
};

let currentTileLayer;

// ────────────────────────────────────────────────────────────────────
// PRIVACY BANNER
// ────────────────────────────────────────────────────────────────────
function dismissPrivacyBanner() {
    document.getElementById('privacy-banner').style.display = 'none';
}

// Auto dismiss after 10 seconds
setTimeout(() => {
    const banner = document.getElementById('privacy-banner');
    if (banner) {
        banner.style.opacity = '0';
        setTimeout(() => {
            banner.style.display = 'none';
        }, 500);
    }
}, 10000);

// ────────────────────────────────────────────────────────────────────
// MAP INITIALIZATION with zoom restrictions
// ────────────────────────────────────────────────────────────────────
function initMap() {
    map = L.map('map-canvas', {
        center: [CLIENT_LAT, CLIENT_LNG],
        zoom: 13,
        zoomControl: false,
        scrollWheelZoom: true,
        doubleClickZoom: true,
        maxZoom: MAX_ZOOM, // Enforce maximum zoom level
        minZoom: 8,
        maxNativeZoom: MAX_ZOOM,
        maxZoomVals: {
            maxZoom: MAX_ZOOM
        }
    });

    // Prevent zooming beyond MAX_ZOOM
    map.on('zoomend', function() {
        if (map.getZoom() > MAX_ZOOM) {
            map.setZoom(MAX_ZOOM);
        }
    });

    // Check current theme and set appropriate tile layer
    const isDark = document.documentElement.classList.contains('dark');
    const tileUrl = isDark ? cartoDBLayers.dark : cartoDBLayers.light;
    
    currentTileLayer = L.tileLayer(tileUrl, {
        maxZoom: MAX_ZOOM,
        maxNativeZoom: MAX_ZOOM,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>, &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        crossOrigin: true
    }).addTo(map);

    // Add client marker
    addClientMarker();

    // Add worker markers with slight delay for animation
    WORKERS.forEach((worker, index) => {
        setTimeout(() => addWorkerMarker(worker), index * 50);
    });

    // Map events
    map.on('click', () => closeAllSheets());
    map.on('zoomend', updateMarkerSizes);
    map.on('moveend', updateVisibleCount);
    
    setTimeout(updateVisibleCount, 1000);
}

// ────────────────────────────────────────────────────────────────────
// UPDATE MAP THEME (called when toggling dark/light mode)
// ────────────────────────────────────────────────────────────────────
function updateMapTheme() {
    const isDark = document.documentElement.classList.contains('dark');
    const newTileUrl = isDark ? cartoDBLayers.dark : cartoDBLayers.light;
    
    if (currentTileLayer) {
        map.removeLayer(currentTileLayer);
    }
    
    currentTileLayer = L.tileLayer(newTileUrl, {
        maxZoom: MAX_ZOOM,
        maxNativeZoom: MAX_ZOOM,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>, &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        crossOrigin: true
    }).addTo(map);
}

// ────────────────────────────────────────────────────────────────────
// CLIENT MARKER
// ────────────────────────────────────────────────────────────────────
function addClientMarker() {
    const el = document.createElement('div');
    el.className = 'relative flex flex-col items-center';
    el.innerHTML = `
        <div class="w-12 h-12 md:w-16 md:h-16 avatar-circle border-4 border-white dark:border-slate-800 bg-primary flex items-center justify-center shadow-2xl">
            <span class="material-symbols-outlined text-white text-xl md:text-2xl">person</span>
        </div>
        <div class="mt-1 glass px-2 py-0.5 md:px-3 md:py-1 rounded-full text-[10px] md:text-xs font-semibold shadow-lg flex items-center gap-1">
            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
            You
        </div>
    `;
    
    const icon = L.divIcon({ 
        html: el, 
        className: '', 
        iconAnchor: [30, 70],
        popupAnchor: [0, -70]
    });
    
    L.marker([CLIENT_LAT, CLIENT_LNG], { icon, zIndexOffset: 1000 }).addTo(map);
}

// ────────────────────────────────────────────────────────────────────
// WORKER MARKER - Using offset coordinates only
// ────────────────────────────────────────────────────────────────────
function addWorkerMarker(worker) {
    const firstName = worker.name.split(' ')[0];
    const isAvail = worker.status === 'Available';
    
    const el = document.createElement('div');
    el.className = 'relative flex flex-col items-center cursor-pointer group marker-el';
    el.dataset.workerId = worker.id;
    el.dataset.category = worker.category;
    
    let avatarHtml = '';
    if (worker.avatar_type === 'image') {
        avatarHtml = `
            <div class="w-10 h-10 md:w-14 md:h-14 avatar-circle border-2 md:border-4 border-white dark:border-slate-800 shadow-2xl transition-transform group-hover:scale-110">
                <img src="${worker.avatar_url}" alt="${worker.name}" class="w-full h-full object-cover">
            </div>
        `;
    } else {
        avatarHtml = `<div class="w-10 h-10 md:w-14 md:h-14 avatar-circle border-2 md:border-4 border-white dark:border-slate-800 ${worker.avatar_color} flex items-center justify-center shadow-2xl transition-transform group-hover:scale-110">
            <span class="text-white font-bold text-xs md:text-lg tracking-tighter">${worker.initials}</span>
        </div>`;
    }
    
    el.innerHTML = `
        ${worker.is_verified ? '<div class="absolute -top-2 md:-top-3 left-1/2 -translate-x-1/2 text-lg md:text-2xl z-10">👑</div>' : ''}
        ${avatarHtml}
        <div class="absolute bottom-0 right-0 w-2.5 h-2.5 md:w-4 md:h-4 ${isAvail ? 'bg-emerald-500' : 'bg-rose-500'} border-2 border-white dark:border-slate-800 rounded-full"></div>
        <div class="mt-1 glass px-2 py-0.5 md:px-3 md:py-1 rounded-full text-[10px] md:text-xs font-semibold shadow-lg">${firstName}</div>
    `;
    
    el.addEventListener('click', (e) => {
        e.stopPropagation();
        openProfile(worker, el);
    });
    
    const icon = L.divIcon({
        html: el,
        className: '',
        iconSize: [60, 80],
        iconAnchor: [30, 80],
        popupAnchor: [0, -80]
    });
    
    const marker = L.marker([worker.lat, worker.lng], { icon }).addTo(map);
    allMarkers.push({ worker, marker, el });
}

// ────────────────────────────────────────────────────────────────────
// ZOOM CONTROLS with max zoom enforcement
// ────────────────────────────────────────────────────────────────────
function zoomIn() { 
    const currentZoom = map.getZoom();
    if (currentZoom < MAX_ZOOM) {
        map.setZoom(Math.min(currentZoom + 1, MAX_ZOOM));
    } else {
        showToast('Maximum zoom level reached for privacy');
    }
}

function zoomOut() { 
    map.zoomOut(); 
}

// ────────────────────────────────────────────────────────────────────
// REST OF THE FUNCTIONS REMAIN THE SAME
// (openProfile, closeProfile, filterWorkers, etc.)
// ────────────────────────────────────────────────────────────────────

function openProfile(worker, markerEl) {
    // Close filter sheet if open
    closeFilterSheet();
    
    // Fly to worker - but respect max zoom
    const currentZoom = map.getZoom();
    const targetLat = worker.lat - (0.002 / Math.pow(2, currentZoom - 13));
    map.flyTo([targetLat, worker.lng], Math.min(currentZoom, MAX_ZOOM - 1), {
        duration: 0.6,
        easeLinearity: 0.3,
    });
    
    // Highlight active marker
    if (activeMarker) {
        activeMarker.classList.remove('ring-4', 'ring-primary', 'ring-offset-2', 'dark:ring-offset-slate-900');
    }
    activeMarker = markerEl;
    markerEl.classList.add('ring-4', 'ring-primary', 'ring-offset-2', 'dark:ring-offset-slate-900');
    
    // Populate card
    const firstName = worker.name.split(' ')[0];
    const greeting = GREETINGS[Math.floor(Math.random() * GREETINGS.length)];
    
    const avatarEl = document.getElementById('profile-avatar');
    if (worker.avatar_type === 'image') {
        avatarEl.innerHTML = `<img src="${worker.avatar_url}" alt="${worker.name}" class="w-full h-full object-cover">`;
    } else {
        avatarEl.innerHTML = `<span class="text-white font-bold text-lg md:text-2xl">${worker.initials}</span>`;
    }
    avatarEl.className = `w-12 h-12 md:w-16 md:h-16 avatar-circle bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center`;
    
    document.getElementById('profile-name').innerHTML = `${greeting} I'm ${firstName}.`;
    const verifiedBadge = document.querySelector('.verified-badge');
    if (worker.is_verified) {
        verifiedBadge.style.display = 'inline';
    } else {
        verifiedBadge.style.display = 'none';
    }
    
    document.getElementById('profile-category').textContent = worker.category + ' Expert';
    document.getElementById('profile-location').textContent = worker.address;
    document.getElementById('profile-rating').textContent = worker.rating.toFixed(1);
    document.getElementById('profile-cta').href = `booking.php?worker_id=${worker.id}`;
    
    const card = document.getElementById('worker-profile-card');
    const overlay = document.getElementById('map-overlay-blur');
    card.classList.remove('translate-y-full', 'opacity-0', 'pointer-events-none');
    card.classList.add('translate-y-0', 'opacity-100');
    overlay.classList.remove('opacity-0', 'pointer-events-none');
}

function closeProfile() {
    const card = document.getElementById('worker-profile-card');
    const overlay = document.getElementById('map-overlay-blur');
    card.classList.add('translate-y-full', 'opacity-0', 'pointer-events-none');
    card.classList.remove('translate-y-0', 'opacity-100');
    overlay.classList.add('opacity-0', 'pointer-events-none');
    
    if (activeMarker) {
        activeMarker.classList.remove('ring-4', 'ring-primary', 'ring-offset-2', 'dark:ring-offset-slate-900');
        activeMarker = null;
    }
}

function openFilterSheet() {
    const sheet = document.getElementById('filter-bottom-sheet');
    const overlay = document.getElementById('map-overlay-blur');
    sheet.style.transform = 'translateY(0)';
    overlay.classList.remove('opacity-0', 'pointer-events-none');
}

function closeFilterSheet() {
    const sheet = document.getElementById('filter-bottom-sheet');
    const overlay = document.getElementById('map-overlay-blur');
    sheet.style.transform = 'translateY(100%)';
    if (!document.getElementById('worker-profile-card').classList.contains('translate-y-0')) {
        overlay.classList.add('opacity-0', 'pointer-events-none');
    }
}

function filterWorkers(category, btn) {
    currentFilter = category;
    
    document.querySelectorAll('#filter-container-desktop .filter-btn').forEach(b => {
        b.classList.remove('bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
        b.classList.add('hover:bg-white/40', 'dark:hover:bg-white/10');
    });
    btn.classList.add('bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
    btn.classList.remove('hover:bg-white/40', 'dark:hover:bg-white/10');
    
    document.getElementById('active-filter-count').textContent = category === 'all' ? 'All' : '1';
    applyFilter(category);
}

function filterWorkersMobile(category, btn) {
    currentFilter = category;
    
    document.querySelectorAll('#filter-container-mobile .filter-btn-mobile').forEach(b => {
        b.classList.remove('bg-primary', 'text-white');
        b.classList.add('hover:bg-white/10', 'dark:hover:bg-white/5');
    });
    btn.classList.add('bg-primary', 'text-white');
    btn.classList.remove('hover:bg-white/10', 'dark:hover:bg-white/5');
    
    document.getElementById('active-filter-count').textContent = category === 'all' ? 'All' : '1';
    
    document.querySelectorAll('#filter-container-desktop .filter-btn').forEach(desktopBtn => {
        if (desktopBtn.dataset.cat === category) {
            desktopBtn.classList.add('bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
            desktopBtn.classList.remove('hover:bg-white/40', 'dark:hover:bg-white/10');
        } else {
            desktopBtn.classList.remove('bg-primary', 'text-white', 'shadow-lg', 'shadow-primary/20');
            desktopBtn.classList.add('hover:bg-white/40', 'dark:hover:bg-white/10');
        }
    });
    
    applyFilter(category);
}

function applyFilter(category) {
    let visible = 0;
    allMarkers.forEach(({ worker, marker, el }) => {
        const show = (category === 'all' || worker.category === category);
        if (show) {
            marker.addTo(map);
            el.style.opacity = '1';
            el.style.pointerEvents = 'auto';
            visible++;
        } else {
            map.removeLayer(marker);
        }
    });
    
    document.querySelectorAll('#total-workers, #total-workers-mobile').forEach(el => {
        el.textContent = visible;
    });
    
    document.getElementById('empty-state').style.display = visible === 0 ? 'block' : 'none';
    
    closeProfile();
    showToast(`Showing ${visible} ${category === 'all' ? 'workers' : category + ' workers'}`);
}

function closeAllSheets() {
    closeProfile();
    closeFilterSheet();
}

function updateMarkerSizes() {
    const zoom = map.getZoom();
    const scale = getScaleForZoom(zoom);
    
    allMarkers.forEach(({ el }) => {
        if (el && el.style) {
            el.style.transform = `scale(${scale})`;
            el.style.transition = 'transform 0.3s ease';
        }
    });
}

function getScaleForZoom(zoom) {
    if (zoom >= MAX_ZOOM) return 1.2;
    if (zoom >= 14) return 1.0;
    if (zoom >= 13) return 0.9;
    if (zoom >= 12) return 0.8;
    return 0.7;
}

function updateVisibleCount() {
    const bounds = map.getBounds();
    let count = 0;
    allMarkers.forEach(({ worker, marker }) => {
        if (bounds.contains([worker.lat, worker.lng])) {
            count++;
        }
    });
    document.querySelectorAll('#visible-count, #visible-count-mobile').forEach(el => {
        if (el) el.textContent = count;
    });
}

function locateMe() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                map.flyTo([pos.coords.latitude, pos.coords.longitude], 13, { duration: 1.2 });
                showToast('Centered to your location');
            },
            () => {
                map.flyTo([CLIENT_LAT, CLIENT_LNG], 13, { duration: 1 });
                showToast('Using saved location');
            }
        );
    } else {
        map.flyTo([CLIENT_LAT, CLIENT_LNG], 13);
    }
}

function toggleTheme() {
    document.documentElement.classList.toggle('dark');
    updateMapTheme();
}

function toggleImmersiveMode() {
    immersiveMode = !immersiveMode;
    
    const elements = [
        document.getElementById('filter-bar-desktop'),
        document.getElementById('zoom-controls'),
        document.getElementById('bottom-bar'),
        document.getElementById('mobile-filter-button'),
        document.querySelector('.fixed.top-4.left-4'),
        document.querySelector('.fixed.top-4.right-4')
    ];
    
    const icon = document.getElementById('immersive-icon');
    
    if (immersiveMode) {
        elements.forEach(el => {
            if (el) el.style.opacity = '0';
        });
        icon.textContent = 'visibility_off';
        showToast('Full map view - tap again to show controls');
    } else {
        elements.forEach(el => {
            if (el) el.style.opacity = '1';
        });
        icon.textContent = 'visibility';
        showToast('Controls visible');
    }
}

function showToast(msg) {
    const toast = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    toast.classList.remove('translate-x-[120%]');
    toast.classList.add('translate-x-0');
    
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.add('translate-x-[120%]');
        toast.classList.remove('translate-x-0');
    }, 2800);
}

// ────────────────────────────────────────────────────────────────────
// INITIALIZE
// ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    setTimeout(() => {
        showToast(`Welcome, ${CLIENT_NAME}! ${WORKERS.length} workers nearby`);
    }, 1200);
});
</script>

<style>
    /* Custom styles for active marker */
    .marker-el.ring-4 {
        z-index: 1000 !important;
    }
    .marker-el.ring-4 .glass {
        background: rgba(20, 106, 245, 0.3) !important;
        border-color: #146af5 !important;
    }
    
    /* Force circular containers for all avatars */
    .avatar-circle,
    .avatar-circle *,
    [class*="avatar"] img,
    #profile-avatar img {
        border-radius: 9999px !important;
    }
    
    .avatar-circle {
        overflow: hidden !important;
    }
    
    /* Smooth transitions for immersive mode */
    #filter-bar-desktop,
    #zoom-controls,
    #bottom-bar,
    #mobile-filter-button,
    .fixed.top-4.left-4,
    .fixed.top-4.right-4 {
        transition: opacity 0.3s ease;
    }
    
    /* Mobile filter button animation */
    #mobile-filter-button {
        transition: opacity 0.3s ease, transform 0.2s ease;
    }
    
    #mobile-filter-button:active {
        transform: translateX(-50%) scale(0.95);
    }
    
    /* Bottom sheet styling */
    #filter-bottom-sheet {
        transition: transform 0.3s ease-out;
        will-change: transform;
        max-height: 80vh;
    }
    
    /* Desktop filter bar hidden on mobile */
    @media (max-width: 768px) {
        #filter-bar-desktop {
            display: none;
        }
    }
</style>

</body>
</html>