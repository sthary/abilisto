<?php
// client/quick_match.php
session_start();
include '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../includes/functions/feature_flags.php';
requireFeatureEnabled($conn, 'feature_quickmatch_enabled', 'dashboard.php');

$client_id = $_SESSION['user_id'];
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

// Get client location
$client_stmt = $conn->prepare("SELECT latitude, longitude, municipality FROM users WHERE id = ?");
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch();
$clientLat = floatval($client['latitude']);
$clientLng = floatval($client['longitude']);
$clientMunicipality = $client['municipality'];

// --- HELPER: Distance Calculation ---
function getDistance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) return 999;
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// --- SCORING ALGORITHM (Updated with skill_badge) ---
function scoreWorker($worker, $clientLat, $clientLng, $clientMunicipality, $urgency, $dist) {
    $score = 0;
    // 1. Availability — highest priority
    if (($worker['availability_status'] ?? '') === 'Available') $score += 50;
    
    // 2. Skill badge (from worker_skills) - [CHANGED]
    switch ($worker['skill_badge'] ?? 'Unverified') {
        case 'Gold':      $score += 30; break;
        case 'Silver':    $score += 20; break;
        case 'Bronze':    $score += 10; break;
        case 'Community': $score += 15; break;
    }
    if (!empty($worker['skill_verified'])) $score += 10;
    
    // 3. Rating
    $score += floatval($worker['average_rating'] ?? 0) * 4;
    
    // 4. Vouch
    $score += min(10, intval($worker['vouch_count'] ?? 0) * 2);
    
    // 5. Distance
    if ($dist <= 2)      $score += 20;
    elseif ($dist <= 5)  $score += 14;
    elseif ($dist <= 7)  $score += 8;
    elseif ($dist <= 10) $score += 3;
    
    // 6. Emergency proximity bonus
    if ($urgency === 'Emergency' && $dist < 3) $score += 10;
    return $score;
}

// --- Fetch Admin-Enabled Service Keys ---
// Admin saves keys like: service_electrical, service_aircon_ref, service_arts_crafts
// We match by normalizing sub_category: lowercase + spaces→underscores
$enabled_settings_res = $conn->query(
    "SELECT setting_key FROM settings
     WHERE setting_key LIKE 'service_%' AND setting_value = '1'"
);
$enabled_keys = [];
while ($s_row = $enabled_settings_res->fetch()) {
    // Strip 'service_' prefix → e.g. 'aircon_ref', 'arts_crafts'
    $enabled_keys[] = str_replace('service_', '', $s_row['setting_key']);
}

if (empty($enabled_keys)) {
    $enabled_keys = ['NONE_ENABLED'];
}

// --- Build sub-category list (filtered by admin-enabled keys) ---
// Normalize sub_category to match: LOWER(REPLACE(sub_category, ' ', '_'))
$in_placeholders = implode(',', array_fill(0, count($enabled_keys), '?'));

$sub_sql = "
    SELECT
        ws.sub_category,
        ws.main_category,
        COUNT(DISTINCT ws.worker_id) AS worker_count,
        (ARRAY_AGG(ws.badge_level ORDER BY
                CASE ws.badge_level
                    WHEN 'Gold'      THEN 5
                    WHEN 'Silver'    THEN 4
                    WHEN 'Bronze'    THEN 3
                    WHEN 'Community' THEN 2
                    ELSE 1
                END DESC
            ))[1] AS top_badge
    FROM worker_skills ws
    JOIN users u ON u.id = ws.worker_id AND u.role = 'worker'
    JOIN worker_profiles wp ON wp.user_id = ws.worker_id AND wp.is_active = TRUE
    WHERE LOWER(REPLACE(ws.sub_category,   ' ', '_')) IN ($in_placeholders)
       OR LOWER(REPLACE(ws.main_category,  ' ', '_')) IN ($in_placeholders)
    GROUP BY ws.sub_category, ws.main_category
    ORDER BY ws.main_category, ws.sub_category
";

$sub_stmt = $conn->prepare($sub_sql);
$sub_stmt->execute(array_merge($enabled_keys, $enabled_keys));

// --- Collect results into arrays ONCE ---
$subs_by_main = [];
$enabled_categories = [];

while ($row = $sub_stmt->fetch()) {
    // Group by main category
    if (!isset($subs_by_main[$row['main_category']])) {
        $subs_by_main[$row['main_category']] = [];
    }
    $subs_by_main[$row['main_category']][] = [
        'sub'       => $row['sub_category'],
        'top_badge' => $row['top_badge'] ?? 'Unverified',
        'count'     => (int) $row['worker_count'],
    ];

    // Populate enabled_categories for UI
    $enabled_categories[$row['sub_category']] = [
        'name'  => $row['sub_category'],
        'key'   => $row['sub_category'],
        'main'  => $row['main_category'],
        'count' => (int) $row['worker_count'],
    ];
}

$no_categories = empty($enabled_categories);

// Problems DB (unchanged)
$problemsDB = [
    'Electrical' => ['Power Outage', 'Short Circuit', 'Outlet Installation', 'Wiring Issue', 'Fuse Blown', 'Light Not Working'],
    'Plumbing'   => ['Leaky Pipe', 'Clogged Toilet', 'No Water', 'Faucet Repair', 'Water Heater', 'Drain Clogged'],
    'Carpentry'  => ['Broken Door', 'Roof Leak', 'Furniture Repair', 'Cabinet Install', 'Shelf Installation', 'Window Repair'],
    'Automotive' => ['Flat Tire', 'Battery Dead', 'Engine Overheat', 'Car Wash', 'Oil Change', 'Brake Problem'],
    'Electronics' => ['TV Repair', 'Refrigerator', 'Washing Machine', 'Aircon Not Cooling', 'Microwave', 'Sound System'],
    'Aircon_Ref' => ['AC Not Cooling', 'Water Leaking', 'Refrigerator Noise', 'Freezer Not Freezing', 'AC Cleaning', 'Gas Refill'],
    'Masonry' => ['Cracked Wall','Floor Tiling','Concrete Repair','Fence Installation','Pavement Work','Foundation Issue'],
    'Welding' => ['Gate Fabrication','Steel Repair','Metal Cutting','Railing Install','Custom Metalwork','Structural Welding'],
    'Construction' => ['General Renovation','New Room Build','Ceiling Work','Floor Leveling','Roofing','Waterproofing'],
    'Motorcycle' => ["Won't Start",'Chain Repair','Brake Adjustment','Oil Change','Tire Replacement','Electrical Fault'],
    'Aircon Ref' => ['AC Not Cooling','Water Leaking','Refrigerator Noise','Freezer Not Freezing','AC Cleaning','Gas Refill'],
    'Computer Tech' => ['Virus Removal','Slow Performance','Hardware Repair','OS Installation','Network Setup','Data Recovery'],
    'Domestic Work' => ['General Cleaning','Deep Cleaning','Laundry','Ironing','Organizing','Dishwashing'],
    'Caregiving' => ['Elderly Assistance','Post-Op Care','Medication Reminder','Companionship','Mobility Help','Wound Dressing'],
    'Massage' => ['Full Body Massage','Back Pain','Foot Massage','Sports Massage','Prenatal Massage','Deep Tissue'],
    'Beauty Care' => ['Haircut','Hair Color','Manicure','Pedicure','Facial','Eyebrow Threading'],
    'Cookery' => ['Event Catering','Private Meal Prep','Filipino Cuisine','Special Diet','Party Food','Daily Meals'],
    'Baking' => ['Custom Cake','Pastries','Bread','Cookies','Event Desserts','Wedding Cake'],
    'Graphic_Design'=> ['Logo Design','Social Media Post','Flyer/Poster','Business Card','Brand Identity','Infographic'],
    'Photography' => ['Event Coverage','Portrait Session','Product Photography','Real Estate Photos','Graduation','Headshots'],
    'Videography' => ['Wedding Video','Event Coverage','Social Media Reel','Interview Video','Product Demo','Short Film'],
    'Music' => ['Beat Making','Recording Session','Mixing & Mastering','Live Performance','Jingle Production','Sound Design'],
    'Arts Crafts' => ['Custom Painting','Wall Mural','Handmade Gifts','Home Decor','Scrapbooking','Floral Arrangement'],
    'Others' => ['General Assistance','Errand Running','Moving Help','Delivery','Assembly','Other Task'],
];

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) $initials .= strtoupper(substr($word, 0, 1));
    return substr($initials, 0, 2);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step1'])) {
        // Save real geolocation if provided
        if (!empty($_POST['geo_lat']) && !empty($_POST['geo_lng'])) {
            $gLat = floatval($_POST['geo_lat']);
            $gLng = floatval($_POST['geo_lng']);
            $geo_stmt = $conn->prepare("UPDATE users SET latitude=?, longitude=? WHERE id=?");
            $geo_stmt->execute([$gLat, $gLng, $client_id]);
            $clientLat = $gLat;
            $clientLng = $gLng;
        }
        // [CHANGED] Store sub_category instead of category
        $_SESSION['quick_match']['sub_category'] = $_POST['category'];
        $_SESSION['quick_match']['main_category'] = $_POST['main_category'] ?? '';
        header("Location: quick_match.php?step=2");
        exit();
    }
    if (isset($_POST['step2'])) {
        $_SESSION['quick_match']['problems'] = $_POST['problems'] ?? [];
        header("Location: quick_match.php?step=3");
        exit();
    }
    if (isset($_POST['step3'])) {
        $_SESSION['quick_match']['urgency'] = $_POST['urgency'];
        if (!isset($_POST['terms'])) {
            $error = "You must agree to the terms";
        } else {
            header("Location: quick_match.php?step=4");
            exit();
        }
    }
    if (isset($_POST['confirm_match'])) {
        // [CHANGED] Use sub_category for matching
        $sub_category = $_SESSION['quick_match']['sub_category'];
        $problems_arr = is_array($_SESSION['quick_match']['problems'])
            ? $_SESSION['quick_match']['problems']
            : [$_SESSION['quick_match']['problems']];
        $problems = implode(", ", $problems_arr);
        $urgency  = $_SESSION['quick_match']['urgency'];

        // Urgency surcharge
        $urgency_surcharge = 0;
        if ($urgency === 'Urgent')        $urgency_surcharge = 20;
        elseif ($urgency === 'Emergency') $urgency_surcharge = 30;
        $fee = $urgency_surcharge;

        // [CHANGED] New query that joins worker_skills on sub_category
        $sql = "
            SELECT
                u.id,
                u.full_name,
                u.latitude,
                u.longitude,
                u.municipality,
                u.profile_pic,
                wp.vouch_count,
                wp.average_rating,
                wp.jobs_completed,
                wp.availability_status,
                ws.sub_category AS matched_skill,
                ws.badge_level AS skill_badge,
                ws.nc_level AS skill_nc_level,
                ws.is_verified AS skill_verified
            FROM users u
            JOIN worker_profiles wp ON wp.user_id = u.id AND wp.is_active = TRUE
            JOIN worker_skills ws ON ws.worker_id = u.id
                AND ws.sub_category = ?
            WHERE u.role = 'worker'
                AND u.latitude IS NOT NULL
                AND u.latitude != 0
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$sub_category]);
        $available_workers   = [];
        $unavailable_workers = [];

        while ($worker = $stmt->fetch()) {
            $dist = getDistance($clientLat, $clientLng, $worker['latitude'], $worker['longitude']);
            if ($dist > 10) continue;

            $score = scoreWorker($worker, $clientLat, $clientLng, $clientMunicipality, $urgency, $dist);

            $entry = [
                'id'                  => $worker['id'],
                'name'                => $worker['full_name'],
                'profile_pic'         => $worker['profile_pic'] ?? null,
                'score'               => $score,
                'distance'            => round($dist, 1),
                'rating'              => floatval($worker['average_rating'] ?? 0),
                'verified'            => (bool)($worker['skill_verified'] ?? false),
                'verification_status' => $worker['skill_badge'] ?? 'None', // [CHANGED] Use skill_badge
                'jobs'                => intval($worker['jobs_completed'] ?? 0),
                'available'           => ($worker['availability_status'] ?? '') === 'Available',
                'latitude'            => floatval($worker['latitude']),
                'longitude'           => floatval($worker['longitude']),
                'matched_skill'       => $worker['matched_skill'],
            ];

            if ($entry['available']) {
                $available_workers[] = $entry;
            } else {
                $unavailable_workers[] = $entry;
            }
        }

        // Sort each group by score desc
        usort($available_workers,   fn($a,$b) => $b['score'] <=> $a['score']);
        usort($unavailable_workers, fn($a,$b) => $b['score'] <=> $a['score']);

        // Take up to 5
        $top_workers = array_merge(
            array_slice($available_workers,   0, 5),
            array_slice($unavailable_workers, 0, max(0, 5 - count($available_workers)))
        );
        $top_workers = array_slice($top_workers, 0, 5);

        // Calculate per-worker total
        foreach ($top_workers as &$w) {
            $dist_km = $w['distance'];
            $w['total_price'] = 15 + (ceil($dist_km) * 5) + $urgency_surcharge;
        }
        unset($w);

        $_SESSION['quick_match']['workers']            = $top_workers;
        $_SESSION['quick_match']['fee']                = $fee;
        $_SESSION['quick_match']['urgency_surcharge']  = $urgency_surcharge;

        header("Location: quick_match.php?step=5");
        exit();
    }
}

// [CHANGED] For UI compatibility, convert $subs_by_main to something step-1 can use
// We need to modify step-1 slightly to show sub-categories instead of categories
// But keep the same button structure
?>
<!DOCTYPE html>
<html class="scroll-smooth" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Quick Match | Abilisto</title>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <?php if ($step == 4 || $step == 5): ?>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <?php endif; ?>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark":  "#0F172A",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans:    ["Plus Jakarta Sans", "Inter", "system-ui", "sans-serif"],
                    },
                    borderRadius: { DEFAULT: "1rem" },
                },
            },
        };
    </script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .step-container  { perspective: 1500px; }
        .step-content    { backface-visibility: hidden; -webkit-backface-visibility: hidden; }
        .step-hidden     { display: none; }
        .category-hover:hover {
            background: linear-gradient(135deg, rgba(59,130,246,.1) 0%, rgba(37,99,235,.05) 100%);
            transform: translateY(-2px);
        }
        .category-hover.category-selected {
            background: linear-gradient(135deg, rgba(59,130,246,.15) 0%, rgba(99,102,241,.1) 100%) !important;
            border-color: #146af5 !important;
            box-shadow: 0 0 0 2px rgba(59,130,246,.25), 0 0 20px rgba(59,130,246,.2);
            transform: translateY(-2px) scale(1.03);
        }
        .category-hover.category-selected .material-icons-round {
            filter: drop-shadow(0 0 8px rgba(59,130,246,.7));
        }
        .chip-selected {
            background: #146af5 !important;
            color: white !important;
            box-shadow: 0 0 15px rgba(59,130,246,.5);
        }
        @keyframes flipIn {
            from { transform: rotateY(10deg) scale(0.98); opacity: 0.5; }
            to   { transform: rotateY(0deg)  scale(1);    opacity: 1; }
        }
        .flip-animation { animation: flipIn 0.3s ease; }

        /* City scene styles... */
        .city-scene { position: fixed; inset: 0; perspective: 900px; overflow: hidden; z-index: 0; }
        .city-plane { position: absolute; width: 200%; height: 200%; left: -50%; top: 10%; transform: rotateX(55deg) rotateZ(-45deg); transform-style: preserve-3d; }
        .city-plane::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px); background-size: 60px 60px; }
        .building { position: absolute; border-radius: 4px 4px 0 0; transform-style: preserve-3d; transition: box-shadow .4s; }
        .building::before { content: ''; position: absolute; inset: 2px; background: repeating-linear-gradient(180deg, rgba(255,255,255,.12) 0px, rgba(255,255,255,.12) 6px, transparent 6px, transparent 14px); border-radius: 2px; }
        .building.lit { box-shadow: 0 0 30px rgba(250,204,21,.35), 0 0 60px rgba(250,204,21,.15); }
        .building.lit::after { content: ''; position: absolute; width: 8px; height: 8px; background: #facc15; border-radius: 50%; top: 8px; left: 50%; transform: translateX(-50%); box-shadow: 0 0 12px #facc15; animation: pulse-dot 1.6s ease-in-out infinite; }
        @keyframes pulse-dot { 0%, 100% { transform: translateX(-50%) scale(1); opacity: 1; } 50% { transform: translateX(-50%) scale(1.6); opacity: .5; } }
        .magnifier { position: fixed; width: 100px; height: 100px; pointer-events: none; z-index: 500; filter: drop-shadow(0 8px 24px rgba(0,0,0,.35)); transition: opacity .3s; }
        .mag-lens { width: 64px; height: 64px; border: 5px solid rgba(255,255,255,.92); border-radius: 50%; position: absolute; top: 0; left: 0; background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.22), rgba(99,102,241,.15)); backdrop-filter: blur(2px); box-shadow: inset 0 0 20px rgba(255,255,255,.1), 0 0 30px rgba(99,102,241,.3); }
        .mag-lens::before { content: ''; position: absolute; width: 18px; height: 10px; background: rgba(255,255,255,.35); border-radius: 50%; top: 10px; left: 12px; transform: rotate(-20deg); }
        .mag-handle { width: 6px; height: 36px; background: linear-gradient(180deg, rgba(255,255,255,.9), rgba(255,255,255,.6)); border-radius: 3px; position: absolute; top: 54px; left: 58px; transform: rotate(-45deg); transform-origin: top center; }
        .scan-ring { position: absolute; width: 80px; height: 80px; border: 2px solid rgba(250,204,21,.5); border-radius: 50%; top: -8px; left: -8px; animation: scan-expand 2s ease-out infinite; }
        @keyframes scan-expand { 0% { transform: scale(.6); opacity: .8; } 100% { transform: scale(1.8); opacity: 0; } }
        #leafletMap { position: fixed; inset: 0; z-index: 1; transition: opacity .8s ease; }
        #leafletMap.map-hidden { opacity: 0; pointer-events: none; }
        body.step4-active { overflow: hidden; position: fixed; width: 100%; height: 100%; }
        .leaflet-control-attribution { font-size: 9px !important; opacity: 0.5 !important; background: rgba(0,0,0,.4) !important; color: rgba(255,255,255,.6) !important; border-radius: 4px !important; }
        .leaflet-container { background: #1a1a2e !important; }
        @keyframes radiusPulse { 0%, 100% { stroke-opacity: 0.35; } 50% { stroke-opacity: 0.7; } }
        .radius-circle { animation: radiusPulse 2.5s ease-in-out infinite; }
        .worker-pin { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 50%; font-weight: 800; font-size: 13px; color: white; border: 2.5px solid rgba(255,255,255,.9); box-shadow: 0 4px 16px rgba(0,0,0,.4); cursor: pointer; transition: transform .2s; }
        .worker-pin.available { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .worker-pin.unavailable { background: linear-gradient(135deg, #6366f1, #4f46e5); opacity: .75; }
        .worker-pin:hover { transform: scale(1.18); }
        .client-pin { width: 20px; height: 20px; background: #f59e0b; border: 3px solid white; border-radius: 50%; box-shadow: 0 0 0 6px rgba(245,158,11,.3), 0 4px 12px rgba(0,0,0,.4); }
        #searchOverlay { position: fixed; inset: 0; z-index: 200; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; padding-bottom: 3rem; pointer-events: none; transition: opacity .6s ease; }
        #searchOverlay.fading { opacity: 0; }
        .search-glass { background: rgba(15,10,40,.65); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,.12); border-radius: 1.5rem; padding: 1.5rem 2rem; text-align: center; max-width: 480px; width: 90vw; }
        .result-card { opacity: 0; transform: translateY(30px) scale(.94); transition: opacity .5s cubic-bezier(.22,1,.36,1), transform .5s cubic-bezier(.22,1,.36,1); }
        .result-card.show { opacity: 1; transform: translateY(0) scale(1); }
        #cardsMobile .result-card.show { opacity: 0.55; transform: scale(0.88); }
        #cardsMobile .result-card.show.is-center { opacity: 1; transform: scale(1.06); }
        .panel { transition: opacity .5s, transform .5s cubic-bezier(.22,1,.36,1); z-index: 40; position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; overflow-y: auto; padding: 2rem 1rem; }
        .panel.hidden-panel { opacity: 0; pointer-events: none; transform: translateY(30px); }
        .panel.visible-panel { opacity: 1; pointer-events: auto; transform: translateY(0); }
        .particle { position: absolute; border-radius: 50%; pointer-events: none; animation: float-up linear forwards; }
        @keyframes float-up { 0% { opacity: 1; transform: translateY(0) scale(1); } 100% { opacity: 0; transform: translateY(-120px) scale(0); } }
        .shimmer { background: linear-gradient(90deg, rgba(255,255,255,.7), #fff, rgba(255,255,255,.7)); background-size: 200% 100%; -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 2s linear infinite; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        @keyframes sway { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
        .btn-glow { position: relative; overflow: hidden; }
        .btn-glow::after { content: ''; position: absolute; inset: -2px; background: linear-gradient(135deg, rgba(99,102,241,.4), rgba(168,85,247,.4)); border-radius: inherit; z-index: -1; opacity: 0; transition: opacity .3s; }
        .btn-glow:hover::after { opacity: 1; }
        .road-h, .road-v { position: absolute; background: rgba(255,255,255,.04); }
        .road-h { height: 20px; width: 100%; }
        .road-v { width: 20px; height: 100%; }
        .road-h::after, .road-v::after { content: ''; position: absolute; background: repeating-linear-gradient(90deg, rgba(250,204,21,.25) 0px, rgba(250,204,21,.25) 12px, transparent 12px, transparent 24px); }
        .road-h::after { height: 2px; width: 100%; top: 50%; }
        .road-v::after { width: 2px; height: 100%; left: 50%; background: repeating-linear-gradient(180deg, rgba(250,204,21,.25) 0px, rgba(250,204,21,.25) 12px, transparent 12px, transparent 24px); }
        .badge-pulse { animation: badge-pop .5s cubic-bezier(.22,1,.36,1); }
        @keyframes badge-pop { 0% { transform: scale(0); opacity: 0; } 60% { transform: scale(1.15); } 100% { transform: scale(1); opacity: 1; } }
        .star { color: #facc15; }
        .worker-card { flex-shrink: 0; background: rgba(255,255,255,.10); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid rgba(255,255,255,.16); border-radius: 1.25rem; padding: 1rem 0.75rem 0.9rem; display: flex; flex-direction: column; align-items: center; text-align: center; transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s ease, opacity .35s ease; position: relative; cursor: default; }
        .worker-card:hover { transform: translateY(-4px) scale(1.04); box-shadow: 0 12px 40px rgba(99,102,241,.35); }
        #cardsDesktop { display: flex; justify-content: center; gap: 12px; margin-bottom: 1.5rem; }
        #cardsDesktop .worker-card { width: 148px; }
        #cardsMobileWrap { display: none; position: relative; margin: 0 -16px 1.5rem; overflow: hidden; }
        #cardsMobile { display: flex; gap: 14px; overflow-x: scroll; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scrollbar-width: none; padding: 24px calc(50vw - 65px); scroll-padding: calc(50vw - 65px); }
        #cardsMobile::-webkit-scrollbar { display: none; }
        #cardsMobile .worker-card { width: 130px; flex-shrink: 0; scroll-snap-align: center; opacity: 0.55; transform: scale(0.88); transition: transform .35s cubic-bezier(.22,1,.36,1), opacity .35s ease, box-shadow .35s ease; }
        #cardsMobile .worker-card.is-center { opacity: 1 !important; transform: scale(1.06) !important; box-shadow: 0 10px 36px rgba(99,102,241,.45); border-color: rgba(250,204,21,.35); }
        .worker-avatar-img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2.5px solid rgba(250,204,21,.55); }
        .worker-avatar-placeholder { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 800; color: white; background: linear-gradient(135deg, #6366f1, #a855f7); border: 2.5px solid rgba(250,204,21,.55); }
        @media (max-width: 767px) { #cardsDesktop { display: none !important; } #cardsMobileWrap { display: block; } }
        @media (min-width: 768px) { #cardsDesktop { display: flex; } #cardsMobileWrap { display: none !important; } }
        .leaflet-popup-content-wrapper { background: rgba(15,10,40,.9) !important; backdrop-filter: blur(16px) !important; border: 1px solid rgba(255,255,255,.15) !important; border-radius: 14px !important; color: white !important; box-shadow: 0 12px 40px rgba(0,0,0,.6) !important; }
        .leaflet-popup-tip { background: rgba(15,10,40,.9) !important; }
        .leaflet-popup-close-button { color: rgba(255,255,255,.6) !important; }
        .popup-worker-name { font-weight: 700; font-size: 14px; margin-bottom: 4px; }
        .popup-worker-detail { font-size: 11px; color: rgba(255,255,255,.65); }
        .popup-avail-badge { display: inline-block; margin-top: 6px; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
        .popup-avail-badge.avail { background: rgba(34,197,94,.2); color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
        .popup-avail-badge.unavail { background: rgba(99,102,241,.2); color: #a5b4fc; border: 1px solid rgba(99,102,241,.3); }
    </style>
</head>
<body class="relative min-h-screen<?php echo $step == 4 ? ' step4-active' : ''; ?>">

    <!-- STEP 4: Leaflet Map Search Scene (unchanged) -->
    <?php if ($step == 4): ?>
    <div id="leafletMap"></div>
    <div class="magnifier" id="magnifier">
        <div class="scan-ring"></div>
        <div class="mag-lens"></div>
        <div class="mag-handle"></div>
    </div>
    <div id="particles" style="position:fixed;inset:0;pointer-events:none;z-index:300;"></div>
    <div id="searchOverlay">
        <div class="search-glass">
            <h1 class="text-white text-2xl md:text-3xl font-extrabold tracking-tight mb-1 drop-shadow-lg">
                Finding Workers Near You
            </h1>
            <p id="statusText" class="shimmer text-base font-medium mb-4">Scanning your area...</p>
            <div class="w-full h-2 rounded-full overflow-hidden mb-2" style="background: rgba(255,255,255,.12);">
                <div id="progressBar" class="h-full rounded-full transition-all duration-300" style="width: 0%; background: linear-gradient(90deg, #facc15, #f59e0b);"></div>
            </div>
            <p class="text-white/50 text-xs font-medium tracking-wide" id="areaCount">Initialising map...</p>
        </div>
    </div>
    <form method="POST" action="quick_match.php" id="autoSubmit" style="display:none;">
        <input type="hidden" name="confirm_match" value="1">
    </form>
    <?php endif; ?>

    <!-- STEP 5: City scene background + results -->
    <?php if ($step == 5): ?>
    <div class="city-scene" id="cityScene">
        <div class="city-plane" id="cityPlane"></div>
        <div id="particles"></div>
    </div>

    <?php
    $workers     = $_SESSION['quick_match']['workers'] ?? [];
    $fee         = $_SESSION['quick_match']['fee'] ?? 50;
    $worker_count = count($workers);
    $available_count   = count(array_filter($workers, fn($w) => $w['available'] ?? true));
    $unavailable_count = $worker_count - $available_count;
    $matched_skill = $_SESSION['quick_match']['sub_category'] ?? '';
    ?>

    <div id="resultsOverlay" class="panel visible-panel">
        <div class="w-full px-4" style="max-width: 860px;">
            <div class="text-center mb-6">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-sm font-semibold mb-3 badge-pulse"
                     style="background: rgba(250,204,21,.15); color: #facc15; border: 1px solid rgba(250,204,21,.25);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Search Complete
                </div>
                <h2 class="text-white text-2xl md:text-4xl font-extrabold tracking-tight drop-shadow-lg"
                    style="text-shadow: 0 4px 24px rgba(0,0,0,.2);">
                    We found <span style="color: #facc15;"><?php echo $worker_count; ?> match<?php echo $worker_count != 1 ? 'es' : ''; ?></span> for you
                </h2>
                <p class="text-white/60 mt-1 text-sm md:text-base font-medium">
                    <strong style="color:#facc15;"><?php echo htmlspecialchars($matched_skill); ?></strong> specialists within 10 km
                    <?php if ($unavailable_count > 0): ?>
                    · <span style="color:#facc15;"><?php echo $unavailable_count; ?> on standby</span>
                    <?php endif; ?>
                </p>
            </div>

            <?php
            $rank_colors = ['#facc15','#e2e8f0','#f97316','#a5f3fc','#c4b5fd'];

            // Helper to render a single card (updated to show skill badge)
            function renderWorkerCard(array $worker, int $index): string {
                global $rank_colors;
                $initials     = getInitials($worker['name']);
                $rating_stars = round($worker['rating']);
                $is_available = $worker['available'] ?? true;
                $avail_color  = $is_available ? '#4ade80' : '#94a3b8';
                
                // [CHANGED] Show the skill badge instead of verification status
                $verify_label = match($worker['verification_status']) {
                    'Gold' => 'Gold', 'Silver' => 'Silver', 'Bronze' => 'Bronze', 
                    'Community' => 'Community', default => 'Verified'
                };
                
                $has_pic    = !empty($worker['profile_pic']);
                $rank_color = $rank_colors[$index] ?? '#facc15';
                $raw_pic    = $worker['profile_pic'] ?? '';
                if ($has_pic && strpos($raw_pic, '/') === false) {
                    $raw_pic = '../uploads/profile_pics/' . $raw_pic;
                }
                $pic_src    = htmlspecialchars($raw_pic);
                $name_safe  = htmlspecialchars($worker['name']);
                $rating_fmt = number_format($worker['rating'], 1);
                $dist       = $worker['distance'];
                $total_price = $worker['total_price'] ?? (15 + ceil($dist) * 5);

                $stars = '';
                for ($i = 1; $i <= 5; $i++) {
                    $col = $i <= $rating_stars ? '#facc15' : 'rgba(250,204,21,.25)';
                    $stars .= "<span style='font-size:0.58rem;color:{$col};'>★</span>";
                }

                $avatar = $has_pic
                    ? "<img src='{$pic_src}' alt='{$name_safe}' class='worker-avatar-img'
                           onerror=\"this.style.display='none';this.nextElementSibling.style.display='flex';\">
                       <div class='worker-avatar-placeholder' style='display:none;'>{$initials}</div>"
                    : "<div class='worker-avatar-placeholder'>{$initials}</div>";

                $badge_check = ($worker['verified'] || $worker['verification_status'] !== 'None')
                    ? "<span style='position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;border-radius:50%;
                                    background:#22c55e;border:2px solid rgba(99,102,241,.8);
                                    display:flex;align-items:center;justify-content:center;'>
                           <svg width='9' height='9' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.5'
                                stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>
                       </span>"
                    : '';

                $avail_bg  = $is_available ? 'rgba(34,197,94,.15)' : 'rgba(100,116,139,.12)';
                $avail_br  = $is_available ? 'rgba(34,197,94,.25)' : 'rgba(100,116,139,.2)';
                $avail_txt = $is_available ? '● Available' : '◌ Standby';

                return "
                <div class='worker-card result-card' data-index='{$index}' data-worker-id='{$worker['id']}' id='wcard-{$worker['id']}'>
                    <button onclick='excludeWorker({$worker['id']})' title='Exclude this worker'
                            style='position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:50%;
                                   background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
                                   color:rgba(255,255,255,.7);font-size:0.7rem;font-weight:900;
                                   display:flex;align-items:center;justify-content:center;cursor:pointer;
                                   line-height:1;padding:0;transition:background .2s,color .2s;z-index:10;'
                            onmouseover=\"this.style.background='rgba(239,68,68,.5)';this.style.color='white';\"
                            onmouseout=\"this.style.background='rgba(255,255,255,.15)';this.style.color='rgba(255,255,255,.7)';\">
                        ×
                    </button>
                    <div style='position:absolute;top:-10px;left:50%;transform:translateX(-50%);
                                background:{$rank_color};color:#1a1a2e;font-size:0.65rem;font-weight:900;
                                padding:2px 9px;border-radius:20px;box-shadow:0 2px 10px rgba(0,0,0,.3);
                                white-space:nowrap;letter-spacing:0.03em;'>
                        #" . ($index + 1) . "
                    </div>
                    <div class='relative mt-2 mb-2.5'>
                        {$avatar}
                        {$badge_check}
                    </div>
                    <h3 style='color:white;font-weight:700;font-size:0.72rem;line-height:1.3;margin-bottom:3px;
                                display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;'>
                        {$name_safe}
                    </h3>
                    <p style='color:#c4b5fd;font-size:0.6rem;font-weight:600;margin-bottom:6px;'>
                        {$verify_label} ✓
                    </p>
                    <div style='display:flex;align-items:center;justify-content:center;gap:1px;margin-bottom:5px;'>
                        {$stars}
                        <span style='color:rgba(255,255,255,.45);font-size:0.58rem;margin-left:3px;'>{$rating_fmt}</span>
                    </div>
                    <div style='display:flex;align-items:center;justify-content:center;gap:3px;margin-bottom:5px;'>
                        <svg width='8' height='8' viewBox='0 0 24 24' fill='none' stroke='rgba(255,255,255,.4)' stroke-width='2' stroke-linecap='round'>
                            <path d='M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'/><circle cx='12' cy='10' r='3'/>
                        </svg>
                        <span style='color:rgba(255,255,255,.4);font-size:0.6rem;font-weight:600;'>{$dist} km</span>
                    </div>
                    <div style='color:#facc15;font-size:0.7rem;font-weight:800;margin-bottom:6px;letter-spacing:0.01em;'>
                        ₱{$total_price}
                    </div>
                    <span style='font-size:0.58rem;font-weight:700;padding:3px 10px;border-radius:20px;
                                 background:{$avail_bg};color:{$avail_color};border:1px solid {$avail_br};'>
                        {$avail_txt}
                    </span>
                </div>";
            }

            // Desktop order
            $desktopHTML = '';
            foreach ($workers as $idx => $w) {
                $desktopHTML .= renderWorkerCard($w, $idx);
            }

            // Mobile order
            $total = count($workers);
            if ($total >= 5) {
                $mobileOrder = [4, 1, 0, 2, 3];
            } elseif ($total === 4) {
                $mobileOrder = [3, 0, 1, 2];
            } elseif ($total === 3) {
                $mobileOrder = [2, 0, 1];
            } else {
                $mobileOrder = array_keys($workers);
            }

            $mobileHTML = '';
            foreach ($mobileOrder as $workerIdx) {
                if (isset($workers[$workerIdx])) {
                    $mobileHTML .= renderWorkerCard($workers[$workerIdx], $workerIdx);
                }
            }
            ?>

            <div id="cardsDesktop"><?php echo $desktopHTML; ?></div>
            <div id="cardsMobileWrap">
                <div id="cardsMobile"><?php echo $mobileHTML; ?></div>
            </div>

            <?php if ($unavailable_count > 0 && $available_count < 5): ?>
            <div class="bg-amber-500/20 text-amber-300 p-4 rounded-2xl mb-6 flex items-center gap-3 text-sm max-w-md mx-auto"
                 style="backdrop-filter: blur(8px);">
                <span class="material-icons-round">info</span>
                <span>Only <?php echo $available_count; ?> worker<?php echo $available_count != 1 ? 's' : ''; ?> available right now.
                <?php echo $unavailable_count; ?> on standby will also receive your request.</span>
            </div>
            <?php endif; ?>

            <div class="flex items-center justify-center gap-4">
                <form method="POST" action="confirm_quick_match.php" id="confirmForm" style="display:inline;">
                    <div id="selectedWorkersInputs">
                        <?php foreach ($workers as $w): ?>
                        <input type="hidden" name="selected_workers[]" value="<?php echo $w['id']; ?>" data-worker-id="<?php echo $w['id']; ?>" class="worker-hidden-input">
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" id="btnConfirm"
                       class="btn-glow px-8 py-3 rounded-xl text-base font-bold text-white cursor-pointer transition-all duration-200 hover:scale-105 active:scale-95"
                       style="background: linear-gradient(135deg, #6366f1, #8b5cf6); border: 1px solid rgba(255,255,255,.2); box-shadow: 0 8px 32px rgba(99,102,241,.4);">
                        Confirm &amp; Broadcast
                    </button>
                </form>
                <button onclick="nextStep(1)" id="btnSearchAgain"
                        class="btn-glow px-8 py-3 rounded-xl text-base font-bold text-white cursor-pointer transition-all duration-200 hover:scale-105 active:scale-95"
                        style="background: rgba(255,255,255,.1); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,.2);">
                    Search Again
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Confirmed Overlay -->
    <div id="confirmedOverlay" class="panel hidden-panel">
        <div class="text-center">
            <div class="w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-6"
                 style="background: rgba(34,197,94,.15); border: 2px solid rgba(34,197,94,.3);">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#4ade80"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h2 class="text-white text-3xl md:text-4xl font-extrabold mb-3 drop-shadow-lg">Confirmed!</h2>
            <p class="text-white/60 text-lg font-medium mb-8">Your workers have been notified and are on their way.</p>
            <a href="quick_match.php?step=1" id="btnBackToSearch"
               class="btn-glow px-8 py-3 rounded-xl text-base font-bold text-white cursor-pointer transition-all duration-200 hover:scale-105 active:scale-95"
               style="background: linear-gradient(135deg, #6366f1, #8b5cf6); border: 1px solid rgba(255,255,255,.2); box-shadow: 0 8px 32px rgba(99,102,241,.4);">
                Start New Search
            </a>
        </div>
    </div>

    <!-- STEPS 1–3 Wizard Card -->
    <?php if ($step <= 3): ?>
    <div class="w-full max-w-lg step-container absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 z-10 px-4 sm:px-6">
        <div class="glass-card shadow-2xl rounded-3xl overflow-hidden transition-all duration-500" id="wizard-card">

            <!-- Step Indicator -->
            <div class="px-8 pt-8 flex justify-between items-center">
                <div class="flex gap-2">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="<?php
                        if ($i === $step)      echo 'w-8 h-2 rounded-full bg-primary transition-all duration-300';
                        elseif ($i < $step)    echo 'w-2 h-2 rounded-full bg-emerald-400 transition-all duration-300';
                        else                   echo 'w-2 h-2 rounded-full bg-slate-200 dark:bg-slate-700 transition-all duration-300';
                    ?>" id="dot-<?php echo $i; ?>"></div>
                    <?php endfor; ?>
                </div>
                <span class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                    Step <?php echo $step; ?> of 5
                </span>
            </div>

            <!-- STEP 1: Category/Sub-category Selection (UI unchanged but now shows sub-categories) -->
            <div class="step-content p-8 <?php echo $step !== 1 ? 'step-hidden' : ''; ?>" id="step-1">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">What do you need?</h1>
                <p class="text-slate-500 dark:text-slate-400 mb-8">Select a service category to begin</p>

                <?php if ($no_categories): ?>
                <div class="bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 p-6 rounded-2xl mb-8 text-center">
                    <span class="material-icons-round text-4xl mb-2">info</span>
                    <p class="font-bold">No services available at the moment</p>
                    <p class="text-sm mt-2">Please check back later or contact support.</p>
                </div>
                <div class="flex gap-4">
                    <a href="dashboard.php" class="w-full py-4 px-6 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all text-center">Back to Dashboard</a>
                </div>
                <?php else: ?>
                <form method="POST" id="step1Form">
                    <div class="grid grid-cols-2 gap-4 mb-8">
                        <?php
                        // [CHANGED] Use $enabled_categories which now contains sub-categories
                        $icon_map = [
                            'Electrical' => 'electrical_services',
                            'Plumbing'   => 'plumbing',
                            'Carpentry'  => 'handyman',
                            'Automotive' => 'directions_car',
                            'Electronics' => 'memory',
                            'Aircon_Ref' => 'ac_unit',
                            'Masonry' => 'domain',
                            'Welding' => 'whatshot',
                            'Construction' => 'build',
                            'Motorcycle' => 'two_wheeler',
                            'Aircon Ref' => 'ac_unit',
                            'Computer Tech' => 'computer',
                            'Domestic Work' => 'cleaning_services',
                            'Caregiving' => 'elderly',
                            'Massage' => 'spa',
                            'Beauty Care' => 'content_cut',
                            'Cookery' => 'restaurant',
                            'Baking' => 'bakery_dining',
                            'Graphic_Design'=> 'palette',
                            'Photography' => 'camera_alt',
                            'Videography' => 'videocam',
                            'Music' => 'music_note',
                            'Arts Crafts' => 'brush',
                            'Others' => 'more_horiz',
                        ];
                        foreach ($enabled_categories as $key => $cat):
                            $icon = $icon_map[$key] ?? 'build';
                        ?>
                        <button type="button"
                                class="category-hover flex flex-col items-center justify-center p-6 bg-white dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 rounded-2xl transition-all"
                                onclick="selectCategory('<?php echo $key; ?>', this)">
                            <span class="material-icons-round text-primary text-4xl mb-3"><?php echo $icon; ?></span>
                            <span class="font-semibold text-slate-700 dark:text-slate-200"><?php echo $cat['name']; ?></span>
                            <span class="text-xs text-slate-400 mt-1"><?php echo $cat['count'] ?? ''; ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- [CHANGED] Add hidden main_category field -->
                    <input type="hidden" name="main_category" id="selectedMain" value="">
                    <input type="hidden" name="category" id="selectedCategory" required>
                    <input type="hidden" name="geo_lat" id="geoLat" value="">
                    <input type="hidden" name="geo_lng" id="geoLng" value="">
                    <input type="hidden" name="step1" value="1">
                    <div class="flex gap-4">
                        <a href="dashboard.php" class="flex-1 py-4 px-6 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all text-center">Cancel</a>
                        <button type="submit" class="flex-[2] py-4 px-6 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 transition-all disabled:opacity-50 disabled:cursor-not-allowed" id="step1NextBtn" disabled>Next Step</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- STEP 2: Problem Selection -->
            <?php
            $sub_category = $_SESSION['quick_match']['sub_category'] ?? '';
            $problems = $problemsDB[$sub_category] ?? ['General Repair', 'Maintenance', 'Installation'];
            ?>
            <div class="step-content p-8 <?php echo $step !== 2 ? 'step-hidden' : ''; ?>" id="step-2">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">What's the problem?</h1>
                <p class="text-slate-500 dark:text-slate-400 mb-8">Select all issues that apply</p>
                <form method="POST" id="step2Form">
                    <div class="flex flex-wrap gap-3 mb-12" id="problemsContainer">
                        <?php foreach ($problems as $problem): ?>
                        <button type="button"
                                class="px-5 py-3 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-sm font-medium border border-transparent hover:border-primary/30 transition-all"
                                onclick="toggleChip(this, '<?php echo $problem; ?>')"><?php echo $problem; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div id="hiddenInputsContainer"></div>
                    <input type="hidden" name="step2" value="1">
                    <div class="flex gap-4">
                        <button type="button" class="flex-1 py-4 px-6 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all" onclick="nextStep(1)">Back</button>
                        <button type="submit" class="flex-[2] py-4 px-6 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 transition-all disabled:opacity-50 disabled:cursor-not-allowed" id="step2NextBtn" disabled>Next Step</button>
                    </div>
                </form>
            </div>

            <!-- STEP 3: Urgency Selection -->
            <div class="step-content p-8 <?php echo $step !== 3 ? 'step-hidden' : ''; ?>" id="step-3">
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">How urgent is it?</h1>
                <p class="text-slate-500 dark:text-slate-400 mb-8">Choose your response time</p>
                <form method="POST" id="step3Form">
                    <div class="space-y-4 mb-6">
                        <label class="relative flex items-center p-4 cursor-pointer rounded-2xl border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800/40 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-all group">
                            <input type="radio" name="urgency" value="Urgent" class="hidden peer" onchange="checkUrgency()">
                            <div class="w-12 h-12 rounded-xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                <span class="material-icons-round text-amber-500">bolt</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-bold text-slate-800 dark:text-white">Urgent (30m – 1h)</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Priority matching (+₱20)</p>
                            </div>
                            <div class="w-6 h-6 border-2 border-slate-200 dark:border-slate-600 rounded-full peer-checked:border-primary peer-checked:bg-primary transition-all relative">
                                <div class="absolute inset-1 bg-white rounded-full"></div>
                            </div>
                        </label>
                        <label class="relative flex items-center p-4 cursor-pointer rounded-2xl border border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800/40 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-all group">
                            <input type="radio" name="urgency" value="Emergency" class="hidden peer" onchange="checkUrgency()">
                            <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-900/30 flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                <span class="material-icons-round text-rose-500">emergency</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-bold text-rose-600 dark:text-rose-400">EMERGENCY (Now!)</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Within 30 mins (+₱30)</p>
                            </div>
                            <div class="w-6 h-6 border-2 border-slate-200 dark:border-slate-600 rounded-full peer-checked:border-primary peer-checked:bg-primary transition-all relative">
                                <div class="absolute inset-1 bg-white rounded-full"></div>
                            </div>
                        </label>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-800/30 p-4 rounded-2xl mb-6 border border-slate-100 dark:border-slate-700">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="terms" name="terms" class="mt-1 rounded text-primary focus:ring-primary/20" onchange="checkUrgency()">
                            <div>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1">Terms Agreement:</p>
                                <ul class="text-xs text-slate-500 dark:text-slate-400 list-disc pl-4">
                                    <li>Payment will be <strong>CASH ON COMPLETION</strong></li>
                                    <li>You cannot cancel after a worker accepts</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="step3" value="1">
                    <div class="flex gap-4">
                        <button type="button" class="flex-1 py-4 px-6 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-200 dark:hover:bg-slate-700 transition-all" onclick="nextStep(2)">Back</button>
                        <button type="submit" class="flex-[2] py-4 px-6 rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 text-white font-bold shadow-lg shadow-blue-500/30 hover:shadow-blue-500/50 transition-all disabled:opacity-50 disabled:cursor-not-allowed" id="step3NextBtn" disabled>Find Workers</button>
                    </div>
                </form>
            </div>

        </div><!-- wizard-card -->
    </div>
    <?php endif; /* steps 1–3 */ ?>

    <!-- Leaflet JS — step 4 only (unchanged) -->
    <?php if ($step == 4): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    const CLIENT_LAT  = <?php echo json_encode($clientLat); ?>;
    const CLIENT_LNG  = <?php echo json_encode($clientLng); ?>;
    const RADIUS_KM   = 5;
    const SEARCH_MS   = 8000;
    const FADE_PAUSE  = 2000;
    const AREAS = [
        'Scanning your neighbourhood…',
        'Checking nearby districts…',
        'Searching 5 km radius…',
        'Locating verified workers…',
        'Ranking by availability…',
        'Finalising your matches…',
    ];

    const progressBar = document.getElementById('progressBar');
    const statusText  = document.getElementById('statusText');
    const areaCount   = document.getElementById('areaCount');
    const magnifier   = document.getElementById('magnifier');
    const particlesEl = document.getElementById('particles');

    const map = L.map('leafletMap', {
        center:          [CLIENT_LAT, CLIENT_LNG],
        zoom:            14,
        zoomControl:     false,
        attributionControl: true,
        dragging:        false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        touchZoom:       false,
        keyboard:        false,
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://carto.com/">CARTO</a>',
        subdomains:  'abcd',
        maxZoom:     19,
    }).addTo(map);

    const clientIcon = L.divIcon({
        className: '',
        html: '<div class="client-pin"></div>',
        iconSize:   [20, 20],
        iconAnchor: [10, 10],
    });
    L.marker([CLIENT_LAT, CLIENT_LNG], { icon: clientIcon })
     .addTo(map)
     .bindPopup('<div style="color:white;font-size:12px;font-weight:700;">📍 Your Location</div>');

    const radiusCircle = L.circle([CLIENT_LAT, CLIENT_LNG], {
        radius:      RADIUS_KM * 1000,
        color:       '#a855f7',
        fillColor:   '#6366f1',
        fillOpacity: 0.06,
        weight:      2,
        dashArray:   '8 6',
        className:   'radius-circle',
    }).addTo(map);

    map.fitBounds(radiusCircle.getBounds(), { padding: [30, 30] });

    let magTargets = [];
    let animFrame  = null;
    let startTime  = null;
    let searchDone = false;
    const workerMarkers = [];

    function generateTargets() {
        const rect = document.getElementById('leafletMap').getBoundingClientRect();
        const w = rect.width;
        const h = rect.height;
        magTargets = [];
        const count = 8;
        for (let i = 0; i < count; i++) {
            magTargets.push({
                x: 60 + Math.random() * (w - 120),
                y: 60 + Math.random() * (h - 200),
            });
        }
    }

    function spawnParticles(x, y, n) {
        for (let i = 0; i < n; i++) {
            const p = document.createElement('div');
            p.className = 'particle';
            const sz = 3 + Math.random() * 5;
            p.style.cssText = `
                width:${sz}px; height:${sz}px;
                left:${x + (Math.random()-.5)*60}px;
                top:${y  + (Math.random()-.5)*60}px;
                background:${Math.random()>.5?'#facc15':'#a78bfa'};
                animation-duration:${.6+Math.random()*.8}s;
            `;
            particlesEl.appendChild(p);
            setTimeout(() => p.remove(), 1400);
        }
    }

    function tick(ts) {
        if (searchDone) return;
        if (!startTime) startTime = ts;
        const elapsed  = ts - startTime;
        const progress = Math.min(elapsed / SEARCH_MS, 1);

        progressBar.style.width = (progress * 100) + '%';

        const aIdx = Math.min(Math.floor(progress * AREAS.length), AREAS.length - 1);
        statusText.textContent = AREAS[aIdx];
        areaCount.textContent  = (aIdx + 1) + ' of ' + AREAS.length + ' areas scanned';

        const segDur  = SEARCH_MS / magTargets.length;
        const segIdx  = Math.min(Math.floor(elapsed / segDur), magTargets.length - 1);
        const prevIdx = Math.max(0, segIdx - 1);
        const from    = magTargets[prevIdx];
        const to      = magTargets[segIdx];
        const t       = (elapsed % segDur) / segDur;
        const ease    = t < .5 ? 2*t*t : 1 - Math.pow(-2*t+2,2)/2;
        const cx      = from.x + (to.x - from.x) * ease;
        const cy      = from.y + (to.y - from.y) * ease;

        magnifier.style.left = cx + 'px';
        magnifier.style.top  = cy + 'px';

        if (Math.random() < 0.04) spawnParticles(cx + 32, cy + 32, 5);

        if (progress < 1) {
            animFrame = requestAnimationFrame(tick);
        } else {
            finishSearch();
        }
    }

    function finishSearch() {
        searchDone = true;
        if (magnifier) magnifier.style.opacity = '0';
        spawnParticles(
            parseFloat(magnifier.style.left || 0) + 32,
            parseFloat(magnifier.style.top  || 0) + 32,
            20
        );

        setTimeout(() => {
            const overlay = document.getElementById('searchOverlay');
            if (overlay) overlay.classList.add('fading');

            const mapEl = document.getElementById('leafletMap');
            if (mapEl) mapEl.classList.add('map-hidden');

            setTimeout(() => {
                document.getElementById('autoSubmit').submit();
            }, 800);
        }, FADE_PAUSE);
    }

    window.addEventListener('load', function () {
        generateTargets();
        animFrame = requestAnimationFrame(tick);
    });
    </script>
    <?php endif; ?>

    <!-- Step 5 JS (unchanged) -->
    <?php if ($step == 5): ?>
    <script>
    const WORKER_DATA = <?php echo json_encode(array_map(fn($w) => [
        'latitude'            => $w['latitude'] ?? null,
        'longitude'           => $w['longitude'] ?? null,
        'name'                => $w['name'],
        'available'           => $w['available'],
        'distance'            => $w['distance'],
        'rating'              => $w['rating'],
        'verification_status' => $w['verification_status'],
    ], $workers)); ?>;

    const BUILDING_COLORS = [
        'rgba(99,102,241,.45)', 'rgba(129,140,248,.35)', 'rgba(168,85,247,.4)',
        'rgba(139,92,246,.35)', 'rgba(79,70,229,.5)',   'rgba(109,40,217,.35)',
        'rgba(67,56,202,.4)',   'rgba(147,51,234,.35)',
    ];
    const cityPlane  = document.getElementById('cityPlane');
    const particlesEl = document.getElementById('particles');
    let buildings = [];

    function buildCity() {
        if (!cityPlane) return;
        cityPlane.innerHTML = '';
        const roadPositions = [25, 45, 65, 85];
        roadPositions.forEach(p => {
            const rh = document.createElement('div');
            rh.className = 'road-h'; rh.style.top = p + '%'; cityPlane.appendChild(rh);
            const rv = document.createElement('div');
            rv.className = 'road-v'; rv.style.left = p + '%'; cityPlane.appendChild(rv);
        });
        buildings = [];
        for (let r = 0; r < 6; r++) {
            for (let c = 0; c < 8; c++) {
                if (Math.random() < 0.25) continue;
                const b = document.createElement('div');
                b.className = 'building';
                const w = 30 + Math.random()*30, h = 30 + Math.random()*60;
                const left = 10 + c*12 + Math.random()*4;
                const top  = 10 + r*14 + Math.random()*4;
                b.style.cssText = `
                    width:${w}px; height:${h}px; left:${left}%; top:${top}%;
                    background:${BUILDING_COLORS[Math.floor(Math.random()*BUILDING_COLORS.length)]};
                    animation:sway ${3+Math.random()*2}s ease-in-out ${Math.random()*2}s infinite;
                    z-index:${Math.floor(h)};
                `;
                cityPlane.appendChild(b);
                buildings.push({ el: b, cx: left, cy: top });
            }
        }
        buildings.filter(() => Math.random() < 0.15).forEach(b => b.el.classList.add('lit'));
    }

    document.addEventListener('DOMContentLoaded', function () {
        buildCity();

        const allCards = document.querySelectorAll('.result-card');
        allCards.forEach((card, i) => {
            const logicalIndex = parseInt(card.getAttribute('data-index') ?? i);
            setTimeout(() => card.classList.add('show'), 120 + logicalIndex * 140);
        });

        const carousel = document.getElementById('cardsMobile');
        if (!carousel) return;

        const totalWorkers = <?php echo $worker_count; ?>;

        function updateCenterCard() {
            const carouselRect = carousel.getBoundingClientRect();
            const centerX      = carouselRect.left + carouselRect.width / 2;
            const mobileCards  = carousel.querySelectorAll('.worker-card');
            let closestCard = null;
            let closestDist = Infinity;

            mobileCards.forEach(card => {
                const rect   = card.getBoundingClientRect();
                const cardCX = rect.left + rect.width / 2;
                const dist   = Math.abs(cardCX - centerX);
                if (dist < closestDist) { closestDist = dist; closestCard = card; }
            });

            mobileCards.forEach(card => card.classList.remove('is-center'));
            if (closestCard) closestCard.classList.add('is-center');
        }

        setTimeout(() => {
            const mobileCards = Array.from(carousel.querySelectorAll('.worker-card'));
            const centerSlot = Math.floor(mobileCards.length / 2);
            const midCard    = mobileCards[centerSlot];
            if (midCard) {
                const cardCenter = midCard.offsetLeft + midCard.offsetWidth / 2;
                carousel.scrollLeft = cardCenter - carousel.offsetWidth / 2;
            }
            updateCenterCard();
        }, 200);

        carousel.addEventListener('scroll', updateCenterCard, { passive: true });
        window.addEventListener('resize', updateCenterCard);
    });

    window.addEventListener('resize', buildCity);

    function excludeWorker(workerId) {
        document.querySelectorAll('[data-worker-id="' + workerId + '"]').forEach(card => {
            card.style.transition = 'opacity .3s, transform .3s';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.8)';
            setTimeout(() => { card.style.display = 'none'; }, 300);
        });
        document.querySelectorAll('.worker-hidden-input[data-worker-id="' + workerId + '"]').forEach(inp => inp.remove());
    }
    </script>
    <?php endif; ?>

    <script>
    function nextStep(step) {
        const card = document.getElementById('wizard-card');
        if (card) {
            card.style.transform = 'rotateY(10deg) scale(0.98)';
            card.style.opacity   = '0.5';
            setTimeout(() => { window.location.href = 'quick_match.php?step=' + step; }, 300);
        } else {
            window.location.href = 'quick_match.php?step=' + step;
        }
    }

    function selectCategory(category, element) {
        document.querySelectorAll('.category-hover').forEach(el => el.classList.remove('category-selected'));
        element.classList.add('category-selected');
        document.getElementById('selectedCategory').value = category;
        // [NEW] Try to get main category from data attribute if available
        const mainCat = element.getAttribute('data-main') || '';
        document.getElementById('selectedMain').value = mainCat;
        document.getElementById('step1NextBtn').disabled  = false;
    }

    (function tryGeolocate() {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function(pos) {
            const latEl = document.getElementById('geoLat');
            const lngEl = document.getElementById('geoLng');
            if (latEl) latEl.value = pos.coords.latitude;
            if (lngEl) lngEl.value = pos.coords.longitude;
        }, function() { /* silently fail */ }, {
            enableHighAccuracy: true, timeout: 8000, maximumAge: 0
        });
    })();

    let selectedProblems = [];
    function toggleChip(element, problem) {
        element.classList.toggle('chip-selected');
        if (element.classList.contains('chip-selected')) selectedProblems.push(problem);
        else selectedProblems = selectedProblems.filter(p => p !== problem);
        updateProblemInputs();
        document.getElementById('step2NextBtn').disabled = selectedProblems.length === 0;
    }
    function updateProblemInputs() {
        const container = document.getElementById('hiddenInputsContainer');
        container.innerHTML = '';
        selectedProblems.forEach((p, i) => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = `problems[${i}]`; inp.value = p;
            container.appendChild(inp);
        });
    }

    function checkUrgency() {
        const urgencyOk = document.querySelector('input[name="urgency"]:checked') !== null;
        const termsOk   = document.getElementById('terms')?.checked;
        document.getElementById('step3NextBtn').disabled = !urgencyOk || !termsOk;
    }

    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark');
    }

    (function animateStepDots() {
        const currentStep = <?php echo $step; ?>;
        const activeDot = document.getElementById('dot-' + currentStep);
        if (!activeDot) return;
        activeDot.classList.remove('w-8', 'bg-primary');
        activeDot.classList.add('w-2', 'bg-slate-200');
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                activeDot.classList.remove('w-2', 'bg-slate-200');
                activeDot.classList.add('w-8', 'bg-primary');
            });
        });
    })();
    </script>

</body>
</html>