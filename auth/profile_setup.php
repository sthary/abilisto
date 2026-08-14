<?php
// profile_setup.php  — v2 (Rule of Three + worker_skills table)
include '../db_connect.php';

// ── Auth guard ──────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'client';
$is_worker = ($role === 'worker');

// ── Fetch user ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT full_name, profile_pic, is_new FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { session_destroy(); header('Location: login.php'); exit; }

$first_name = explode(' ', trim($user['full_name']))[0];

if ((int)$user['is_new'] === 0) {
    header('Location: dashboard.php');
    exit;
}

// ── Main-category lookup (PHP-side mirror of migration SQL) ──
const MAIN_CAT_MAP = [
    'Electrical'    => 'Construction & Trades',
    'Plumbing'      => 'Construction & Trades',
    'Carpentry'     => 'Construction & Trades',
    'Masonry'       => 'Construction & Trades',
    'Welding'       => 'Construction & Trades',
    'Construction'  => 'Construction & Trades',
    'Automotive'    => 'Automotive & Mechanics',
    'Motorcycle'    => 'Automotive & Mechanics',
    'Electronics'   => 'Technology & Electronics',
    'Aircon Ref'    => 'Technology & Electronics',
    'Computer Tech' => 'Technology & Electronics',
    'Domestic Work' => 'Domestic & Personal Care',
    'Caregiving'    => 'Domestic & Personal Care',
    'Massage'       => 'Domestic & Personal Care',
    'Beauty Care'   => 'Domestic & Personal Care',
    'Cookery'       => 'Culinary Arts',
    'Baking'        => 'Culinary Arts',
    'Graphic_Design'=> 'Creative Arts',
    'Photography'   => 'Creative Arts',
    'Videography'   => 'Creative Arts',
    'Music'         => 'Creative Arts',
    'Arts Crafts'   => 'Creative Arts',
    'Others'        => 'Other',
];

// ── AJAX handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── [CHANGED] save_skills → writes to worker_skills (many-to-many) ──
    if ($action === 'save_skills') {
        $raw    = isset($_POST['skills']) ? (array)$_POST['skills'] : [];
        $skills = array_slice(
            array_unique(array_map('strip_tags', $raw)),
            0, 3   // Hard-enforce max 3 server-side
        );

        if (empty($skills)) {
            echo json_encode(['success' => false, 'error' => 'No skills provided']); exit;
        }

        try {
            $pdo->beginTransaction();

            // Clear old entries for this worker
            $pdo->prepare("DELETE FROM worker_skills WHERE worker_id = ?")->execute([$user_id]);

            // Insert fresh rows
            $ins = $pdo->prepare(
                "INSERT INTO worker_skills (worker_id, sub_category, main_category)
                 VALUES (?, ?, ?)"
            );
            foreach ($skills as $skill) {
                $main = MAIN_CAT_MAP[$skill] ?? 'Other';
                $ins->execute([$user_id, $skill, $main]);
            }

            // Backward-compat: keep service_category in worker_profiles
            $skills_str = implode(',', $skills);
            $chk = $pdo->prepare("SELECT user_id FROM worker_profiles WHERE user_id = ?");
            $chk->execute([$user_id]);
            if ($chk->fetch()) {
                $pdo->prepare(
                    "UPDATE worker_profiles SET service_category = ?, skill_count = ? WHERE user_id = ?"
                )->execute([$skills_str, count($skills), $user_id]);
            } else {
                $pdo->prepare(
                    "INSERT INTO worker_profiles (user_id, service_category, skill_count) VALUES (?,?,?)"
                )->execute([$user_id, $skills_str, count($skills)]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'count' => count($skills)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'DB error']);
        }
        exit;
    }

    if ($action === 'save_rate') {
        $rate = floatval($_POST['rate'] ?? 0);
        $pdo->prepare("UPDATE worker_profiles SET minimum_standard_rate = ? WHERE user_id = ?")->execute([$rate, $user_id]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'save_location') {
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE id = ?")->execute([$lat, $lng, $user_id]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'save_bio') {
        $bio = strip_tags(trim($_POST['bio'] ?? ''));
        $pdo->prepare("UPDATE worker_profiles SET bio = ? WHERE user_id = ?")->execute([$bio, $user_id]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'save_photo') {
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) { echo json_encode(['success'=>false,'error'=>'Invalid type']); exit; }
            if ($_FILES['photo']['size'] > 5*1024*1024) { echo json_encode(['success'=>false,'error'=>'Too large']); exit; }
            $fname = 'profile_'.$user_id.'_'.time().'.'.$ext;
            $dest  = '../uploads/profiles/'.$fname;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?")->execute([$fname, $user_id]);
                echo json_encode(['success'=>true,'file'=>$fname]);
            } else {
                echo json_encode(['success'=>false,'error'=>'Move failed']);
            }
        } else {
            echo json_encode(['success'=>false,'error'=>'No file']);
        }
        exit;
    }

    if ($action === 'finish') {
        $pdo->prepare("UPDATE users SET is_new = FALSE WHERE id = ?")->execute([$user_id]);
        $_SESSION['is_new'] = 0;
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']); exit;
}

// ── View vars ───────────────────────────────────────────────
// [CHANGED] Worker now uses blue (#3b82f6) like client
$role_color    = '#146af5'; // Blue for both worker and client
$role_gradient = $is_worker ? 'from-blue-400 to-blue-600' : 'from-blue-400 to-blue-600';
$role_icon     = $is_worker ? 'construction' : 'person';
$role_title    = ucfirst($role);
$total_steps   = $is_worker ? 5 : 1;
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Profile Setup | Abilisto</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    primary: "<?php echo $role_color; ?>",
                    "background-light": "#F8FAFC",
                    "card-border": "#E2E8F0",
                },
                fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
                borderRadius: { DEFAULT:"0.25rem", lg:"0.5rem", xl:"0.75rem", full:"9999px" },
                boxShadow: { ambient: "0 20px 50px rgba(0,0,0,0.05)" }
            }
        }
    };
    </script>

    <style type="text/tailwindcss">
        body { font-family: "Plus Jakarta Sans", sans-serif; }
        .radial-bg {
            background: radial-gradient(circle at top right, rgba(20,106,245,0.08), transparent),
                        radial-gradient(circle at bottom left, rgba(20,106,245,0.03), #F8FAFC);
        }
        .glass-card {
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid rgba(226,232,240,0.8);
        }
        .input-field {
            @apply w-full px-6 py-4 bg-white/60 dark:bg-slate-800/50 border-2 border-slate-100 dark:border-slate-700 rounded-2xl focus:ring-4 focus:border-primary outline-none transition-all text-slate-900 dark:text-white placeholder:text-slate-300;
        }

        /* ── [CHANGED] Skill bubbles with disabled state ── */
        .skill-bubble {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 8px; cursor: pointer;
            padding: 16px 10px 14px; border-radius: 24px;
            border: 2px solid #e2e8f0; background: rgba(255,255,255,0.7);
            color: #64748b; font-size: 11px; font-weight: 700;
            text-align: center; transition: all 0.2s ease;
            line-height: 1.3; letter-spacing: 0.02em;
        }
        .skill-bubble .bubble-icon { font-size: 26px; line-height: 1; transition: transform 0.2s ease; }
        .skill-bubble:hover:not(.maxed-out) { border-color: <?php echo $role_color; ?>80; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.07); }
        .skill-bubble:hover:not(.maxed-out) .bubble-icon { transform: scale(1.1); }
        .skill-bubble.on {
            border-color: <?php echo $role_color; ?>;
            background: rgba(20,106,245,0.08);
            color: <?php echo $role_color; ?>;
            box-shadow: 0 0 0 3px <?php echo $role_color; ?>20, 0 8px 20px <?php echo $role_color; ?>18;
        }
        /* Dimmed state when cap reached and this bubble is NOT selected */
        .skill-bubble.maxed-out {
            opacity: 0.35;
            cursor: not-allowed;
            transform: none !important;
        }
        /* Shake animation for over-limit tap attempt */
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%      { transform: translateX(-5px); }
            60%      { transform: translateX(5px); }
            80%      { transform: translateX(-3px); }
        }
        .skill-bubble.shake { animation: shake 0.35s ease; }

        /* ── [NEW] Skill counter pill ── */
        .skill-counter-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 18px; border-radius: 999px;
            font-size: 13px; font-weight: 800;
            border: 2px solid <?php echo $role_color; ?>33;
            background: <?php echo $role_color; ?>10;
            color: <?php echo $role_color; ?>;
            transition: all 0.25s ease;
        }
        .skill-counter-pill.full {
            background: <?php echo $role_color; ?>20;
            border-color: <?php echo $role_color; ?>;
            box-shadow: 0 0 0 3px <?php echo $role_color; ?>18;
        }
        .skill-counter-dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: <?php echo $role_color; ?>55;
            transition: background 0.2s;
        }
        .skill-counter-dot.filled { background: <?php echo $role_color; ?>; }

        .cat-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; background: #f1f5f9; border-radius: 20px;
            font-size: 10px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.12em; color: #64748b; margin-bottom: 12px;
        }
        .bio-card {
            cursor: pointer; padding: 16px; border-radius: 20px;
            border: 2px solid transparent; background: rgba(255,255,255,0.6);
            font-size: 14px; color: #64748b; transition: all 0.2s ease;
        }
        .bio-card:hover { transform: translateY(-2px); border-color: <?php echo $role_color; ?>50; }
        .bio-card.on {
            border-color: <?php echo $role_color; ?>;
            background: rgba(20,106,245,0.06);
            color: #1e40af;
        }
        .btn-primary {
            @apply w-full font-bold rounded-2xl flex items-center justify-center gap-3 text-base transition-all text-white;
            padding: 18px;
            background: linear-gradient(to right, #146af5, #0f52c2);
            box-shadow: 0 10px 25px -5px <?php echo $role_color; ?>40;
        }
        .btn-primary:hover { transform: translateY(-1px) scale(1.005); box-shadow: 0 15px 30px -5px <?php echo $role_color; ?>50; }
        .btn-primary:active { transform: scale(0.98); }
        .btn-primary:disabled { @apply opacity-60 cursor-not-allowed; transform: none !important; }

        @keyframes slideInRight { from{opacity:0;transform:translateX(40px)} to{opacity:1;transform:translateX(0)} }
        @keyframes slideOutLeft { from{opacity:1;transform:translateX(0)} to{opacity:0;transform:translateX(-40px)} }
        .slide-in  { animation: slideInRight 0.35s ease forwards; }
        .slide-out { animation: slideOutLeft  0.35s ease forwards; }
        @keyframes slideUp { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }

        #setup-map { height: 280px; width: 100%; z-index: 1; border-radius: 20px; }
        @media(min-width:768px){ #setup-map { height: 320px; } }

        input[type=range] {
            -webkit-appearance: none; width: 100%; height: 6px; border-radius: 3px;
            background: linear-gradient(to right, <?php echo $role_color; ?> 0%, <?php echo $role_color; ?> var(--pct, 18%), #e2e8f0 var(--pct, 18%), #e2e8f0 100%);
            outline: none; cursor: pointer;
        }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; width: 28px; height: 28px; border-radius: 50%;
            background: white; border: 3px solid <?php echo $role_color; ?>;
            cursor: pointer; box-shadow: 0 2px 12px <?php echo $role_color; ?>55;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        input[type=range]::-webkit-slider-thumb:hover { transform: scale(1.18); box-shadow: 0 4px 20px <?php echo $role_color; ?>66; }

        .drop-zone {
            @apply border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl flex flex-col items-center justify-center gap-3 py-10 px-6 cursor-pointer transition-all;
        }
        .drop-zone.over { border-color: <?php echo $role_color; ?>; background: <?php echo $role_color; ?>08; }
        .step-dot { @apply h-2 rounded-full transition-all duration-300; width:8px; background:#e2e8f0; }
        .step-dot.active { width:28px; background: <?php echo $role_color; ?>; }
        .step-dot.done   { background: <?php echo $role_color; ?>88; }
    </style>
</head>

<body class="min-h-screen font-display bg-background-light radial-bg text-slate-900 dark:text-white transition-colors duration-500">

<div class="w-full max-w-2xl mx-auto px-4 pt-24 pb-12 animate-slideUp">

<!-- Progress bar -->
<div class="mb-8 px-2">
    <div class="flex justify-between items-end mb-3">
        <div>
            <span class="text-xs font-extrabold uppercase tracking-widest" style="color:<?php echo $role_color; ?>">Onboarding</span>
            <h3 class="text-base font-bold text-slate-700 dark:text-slate-300">
                <?php echo $is_worker ? 'Worker Profile' : 'Client Profile'; ?>
            </h3>
        </div>
        <div class="flex items-center gap-2" id="progress-dots">
            <?php for ($i = 0; $i < $total_steps; $i++): ?>
            <div class="step-dot <?php echo $i === 0 ? 'active' : ''; ?>" id="dot-<?php echo $i; ?>"></div>
            <?php endfor; ?>
        </div>
    </div>
    <div class="h-2 w-full bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
        <div id="progress-bar" class="h-full rounded-full transition-all duration-500 shadow-sm"
             style="width:<?php echo (1/$total_steps)*100; ?>%; background:<?php echo $role_color; ?>"></div>
    </div>
</div>

<!-- Main glass card -->
<div class="glass-card rounded-[2.5rem] shadow-ambient relative overflow-hidden">
    <div class="absolute -top-24 -right-24 w-64 h-64 rounded-full blur-3xl pointer-events-none opacity-10" style="background:<?php echo $role_color; ?>"></div>
    <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-blue-400/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="px-8 md:px-12 pt-8 pb-0">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 text-white rounded-full text-xs font-bold mb-6"
             style="background:<?php echo $role_color; ?>; box-shadow: 0 4px 14px <?php echo $role_color; ?>44">
            <span class="material-icons-round text-sm"><?php echo $role_icon; ?></span>
            Setting up as <?php echo $role_title; ?>
        </div>
    </div>

    <div id="step-wrap" class="px-8 md:px-12 pb-10 md:pb-12">

<?php if ($is_worker): ?>

        <!-- ═══════════════════════════════════
             STEP 0 — SERVICE SKILLS (RULE OF 3)
        ═══════════════════════════════════ -->
        <div class="step-panel" id="panel-0">
            <div class="mb-6">
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white mb-2">
                    What are your <span style="color:<?php echo $role_color; ?>">specialties</span>?
                </h1>
                <p class="text-slate-500 dark:text-slate-400 text-base font-medium">
                    Select up to <strong>3 services</strong> you truly excel in — this keeps your profile trustworthy and makes admin NC verification faster.
                </p>
            </div>

            <!-- [NEW] Skill counter + dots -->
            <div class="flex items-center justify-between mb-6">
                <div class="skill-counter-pill" id="skill-counter-pill">
                    <div class="flex items-center gap-1.5">
                        <div class="skill-counter-dot" id="sdot-0"></div>
                        <div class="skill-counter-dot" id="sdot-1"></div>
                        <div class="skill-counter-dot" id="sdot-2"></div>
                    </div>
                    <span id="skill-counter-text">0 / 3 selected</span>
                </div>
                <span id="skills-limit-msg" class="hidden text-xs font-bold px-3 py-1.5 rounded-full" style="background:<?php echo $role_color; ?>15; color:<?php echo $role_color; ?>">
                    ✓ Maximum reached
                </span>
            </div>

            <div class="space-y-6" id="skills-wrap">
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">build</span>Construction &amp; Trades</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Electrical"><span class="bubble-icon">⚡</span>Electrical</div>
                        <div class="skill-bubble" data-value="Plumbing"><span class="bubble-icon">💧</span>Plumbing</div>
                        <div class="skill-bubble" data-value="Carpentry"><span class="bubble-icon">🔨</span>Carpentry</div>
                        <div class="skill-bubble" data-value="Masonry"><span class="bubble-icon">🧱</span>Masonry</div>
                        <div class="skill-bubble" data-value="Welding"><span class="bubble-icon">🔥</span>Welding</div>
                        <div class="skill-bubble" data-value="Construction"><span class="bubble-icon">🏗️</span>Construction</div>
                    </div>
                </div>
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">directions_car</span>Automotive &amp; Mechanics</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Automotive"><span class="bubble-icon">🚘</span>Automotive</div>
                        <div class="skill-bubble" data-value="Motorcycle"><span class="bubble-icon">🏍️</span>Motorcycle</div>
                    </div>
                </div>
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">computer</span>Technology &amp; Electronics</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Electronics"><span class="bubble-icon">📱</span>Electronics</div>
                        <div class="skill-bubble" data-value="Aircon Ref"><span class="bubble-icon">❄️</span>Aircon &amp; Ref</div>
                        <div class="skill-bubble" data-value="Computer Tech"><span class="bubble-icon">💻</span>Computer Tech</div>
                    </div>
                </div>
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">home</span>Domestic &amp; Personal Care</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Domestic Work"><span class="bubble-icon">🧹</span>Housekeeping</div>
                        <div class="skill-bubble" data-value="Caregiving"><span class="bubble-icon">🤱</span>Caregiving</div>
                        <div class="skill-bubble" data-value="Massage"><span class="bubble-icon">💆</span>Massage</div>
                        <div class="skill-bubble" data-value="Beauty Care"><span class="bubble-icon">💇</span>Beauty Care</div>
                    </div>
                </div>
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">restaurant</span>Culinary Arts</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Cookery"><span class="bubble-icon">👨‍🍳</span>Cookery</div>
                        <div class="skill-bubble" data-value="Baking"><span class="bubble-icon">🥖</span>Baking</div>
                    </div>
                </div>
                <div>
                    <div class="cat-pill"><span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">palette</span>Creative Arts</div>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        <div class="skill-bubble" data-value="Graphic_Design"><span class="bubble-icon">🎨</span>Graphic Design</div>
                        <div class="skill-bubble" data-value="Photography"><span class="bubble-icon">📸</span>Photography</div>
                        <div class="skill-bubble" data-value="Videography"><span class="bubble-icon">🎥</span>Videography</div>
                        <div class="skill-bubble" data-value="Music"><span class="bubble-icon">🎵</span>Music &amp; Audio</div>
                        <div class="skill-bubble" data-value="Arts Crafts"><span class="bubble-icon">🖌️</span>Arts &amp; Crafts</div>
                        <div class="skill-bubble" data-value="Others"><span class="bubble-icon">🔹</span>Others</div>
                    </div>
                </div>
            </div>

            <p id="skills-err" class="hidden text-red-500 text-sm mt-4 font-semibold">Please select at least one skill.</p>

            <div class="mt-8">
                <button onclick="step0Next()" class="btn-primary">
                    Continue <span class="material-icons-round text-xl">arrow_forward</span>
                </button>
            </div>
        </div>

        <!-- ═══════════════════════════════════
             STEP 1 — MINIMUM RATE
        ═══════════════════════════════════ -->
        <div class="step-panel hidden" id="panel-1">
            <div class="mb-8">
                <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-2">
                    Set your <span style="color:<?php echo $role_color; ?>">standard rate</span>
                </h1>
                <p class="text-slate-500 dark:text-slate-400 font-medium">Clients will see this on your profile. You can always negotiate higher.</p>
            </div>
            <div class="text-center mb-8">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400 mb-2">Minimum rate per job</p>
                <div class="inline-flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-slate-400">₱</span>
                    <span id="rate-display" class="text-7xl font-extrabold tabular-nums transition-all" style="color:<?php echo $role_color;?>">300</span>
                    <span class="text-slate-400 text-base font-semibold">/job</span>
                </div>
            </div>
            <div class="mb-8 px-1">
                <input type="range" id="rate-slider" min="100" max="5000" step="50" value="300" oninput="syncRate(this.value,'slider')" style="--pct:3.92%">
                <div class="flex justify-between text-xs font-semibold text-slate-400 mt-3">
                    <span>₱100</span><span>₱2,500</span><span>₱5,000</span>
                </div>
            </div>
            <div class="relative group mb-6">
                <span class="absolute left-5 top-1/2 -translate-y-1/2 font-extrabold text-xl text-slate-400">₱</span>
                <input type="number" id="rate-input" value="300" min="100" max="999999"
                       placeholder="Or type an exact amount..." oninput="syncRate(this.value,'input')"
                       class="input-field pl-14 text-xl font-bold">
            </div>
            <div>
                <p class="text-xs font-extrabold text-slate-400 uppercase tracking-widest mb-3">Common rates</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ([150,200,300,500,750,1000,1500,2000,3000,5000] as $r): ?>
                    <button type="button" onclick="setRate(<?php echo $r; ?>)"
                            class="px-4 py-2 rounded-2xl bg-white/70 dark:bg-slate-800/60 border-2 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-sm font-bold hover:border-primary hover:text-primary transition-all hover:-translate-y-0.5">
                        ₱<?php echo number_format($r); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mt-8">
                <button onclick="step1Next()" class="btn-primary">
                    Continue <span class="material-icons-round text-xl">arrow_forward</span>
                </button>
            </div>
        </div>

        <!-- ═══════════════════════════════════
             STEP 2 — LOCATION (with Use Current Location button)
        ═══════════════════════════════════ -->
        <div class="step-panel hidden" id="panel-2">
            <div class="mb-6">
                <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-2">
                    <span style="color:<?php echo $role_color; ?>">Pin</span> your location
                </h1>
                <p class="text-slate-500 dark:text-slate-400 font-medium">Helps nearby clients find you. Tap the map to drop a pin.</p>
            </div>
            <div class="relative rounded-[24px] overflow-hidden border border-slate-200/60 dark:border-slate-700/60 shadow-inner mb-2">
                <div id="setup-map"></div>
                <div class="absolute top-4 right-4 z-10 flex flex-col gap-2">
                    <button type="button" onclick="mZoomIn()" class="w-10 h-10 glass-card rounded-[14px] flex items-center justify-center hover:scale-105 transition-transform shadow">
                        <span class="material-icons-round text-slate-700 dark:text-white">add</span>
                    </button>
                    <button type="button" onclick="mZoomOut()" class="w-10 h-10 glass-card rounded-[14px] flex items-center justify-center hover:scale-105 transition-transform shadow">
                        <span class="material-icons-round text-slate-700 dark:text-white">remove</span>
                    </button>
                    <button type="button" onclick="mLocateMe()" class="w-10 h-10 rounded-[14px] flex items-center justify-center hover:scale-105 transition-transform shadow text-white" style="background:<?php echo $role_color;?>">
                        <span class="material-icons-round">my_location</span>
                    </button>
                </div>
                <!-- [CHANGED] Added Use Current Location button below the map -->
                <div class="absolute bottom-4 left-4 right-4 z-10 flex gap-2">
                    <div class="glass-card px-4 py-2 rounded-[14px] text-xs text-slate-600 dark:text-slate-300 flex items-center gap-2 flex-1">
                        <span class="material-icons-round text-sm" style="color:<?php echo $role_color;?>">info</span>
                        <span id="map-hint">Click anywhere on the map to drop a pin</span>
                    </div>
                    <button type="button" onclick="mLocateMe()" class="glass-card px-4 py-2 rounded-[14px] text-xs font-bold flex items-center gap-1 hover:scale-105 transition-transform" style="color:<?php echo $role_color;?>">
                        <span class="material-icons-round text-sm">my_location</span>
                        Use Current
                    </button>
                </div>
            </div>
            <input type="hidden" id="setup-lat">
            <input type="hidden" id="setup-lng">
            <div class="mt-6">
                <button onclick="step2Next()" class="btn-primary">
                    Continue <span class="material-icons-round text-xl">arrow_forward</span>
                </button>
            </div>
        </div>

        <!-- ═══════════════════════════════════
             STEP 3 — BIO
        ═══════════════════════════════════ -->
        <div class="step-panel hidden" id="panel-3">
            <div class="mb-6">
                <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-2">
                    Tell us about <span style="color:<?php echo $role_color; ?>">yourself.</span>
                </h1>
                <p class="text-slate-500 dark:text-slate-400 font-medium">Pick a suggestion or write your own.</p>
            </div>
            <div id="bio-suggestions" class="grid grid-cols-1 gap-3 mb-6"></div>
            <div class="space-y-2">
                <div class="flex items-center gap-2 px-4 py-2 w-fit bg-slate-100 dark:bg-slate-800 rounded-2xl">
                    <span class="material-icons-round text-base" style="color:<?php echo $role_color;?>">edit_note</span>
                    <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Your Bio</span>
                </div>
                <textarea id="bio-text" rows="4" maxlength="500"
                          placeholder="Hi! I'm a skilled professional in Surigao del Sur..."
                          oninput="document.getElementById('bio-count').textContent = this.value.length + ' / 500'"
                          class="input-field resize-none leading-relaxed"></textarea>
                <div class="flex justify-end">
                    <span id="bio-count" class="text-xs text-slate-400">0 / 500</span>
                </div>
            </div>
            <div class="mt-8">
                <button onclick="step3Next()" class="btn-primary">
                    Continue <span class="material-icons-round text-xl">arrow_forward</span>
                </button>
            </div>
        </div>

<?php endif; ?>

        <!-- ═══════════════════════════════════
             STEP LAST — PROFILE PHOTO
        ═══════════════════════════════════ -->
        <div class="step-panel <?php echo $is_worker ? 'hidden' : ''; ?>" id="panel-<?php echo $is_worker ? '4' : '0'; ?>">
            <div class="mb-8">
                <h1 class="text-3xl md:text-4xl font-bold text-slate-900 dark:text-white tracking-tight mb-2">
                    <?php echo $is_worker ? "One last thing —<br>add a profile photo" : "Hey " . htmlspecialchars($first_name) . "!<br>Add a profile photo"; ?>
                </h1>
                <p class="text-slate-500 dark:text-slate-400">
                    <?php echo $is_worker ? "Workers with photos get hired more." : "Helps workers recognize you when they arrive."; ?>
                </p>
            </div>
            <div class="flex flex-col items-center gap-6">
                <div class="relative">
                    <div class="w-28 h-28 rounded-full overflow-hidden shadow-xl"
                         style="box-shadow: 0 0 0 4px <?php echo $role_color;?>33, 0 8px 24px rgba(0,0,0,0.1)">
                        <img id="photo-preview"
                             src="<?php echo !empty($user['profile_pic'])
                                ? '../uploads/profiles/'.htmlspecialchars($user['profile_pic'])
                                : 'https://ui-avatars.com/api/?name='.urlencode($user['full_name']).'&background='.ltrim($role_color,'#').'&color=fff&size=112&bold=true'; ?>"
                             alt="Preview" class="w-full h-full object-cover">
                    </div>
                    <label for="photo-file" class="absolute -bottom-1 -right-1 w-9 h-9 rounded-full flex items-center justify-center text-white cursor-pointer hover:scale-110 transition-transform shadow-lg" style="background:<?php echo $role_color;?>">
                        <span class="material-icons-round" style="font-size:16px">photo_camera</span>
                    </label>
                </div>
                <div id="drop-zone" class="drop-zone w-full"
                     onclick="document.getElementById('photo-file').click()"
                     ondragover="event.preventDefault(); this.classList.add('over')"
                     ondragleave="this.classList.remove('over')"
                     ondrop="onDrop(event)">
                    <span class="material-icons-round text-5xl text-slate-300 dark:text-slate-600">cloud_upload</span>
                    <p class="text-slate-500 dark:text-slate-400 text-center text-sm leading-relaxed">
                        <span class="font-semibold" style="color:<?php echo $role_color;?>">Click to upload</span> or drag & drop<br>
                        <span class="text-xs opacity-70">JPG, PNG, WEBP · max 5MB</span>
                    </p>
                </div>
                <input type="file" id="photo-file" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewPhoto(this)">
                <div id="upload-progress" class="hidden w-full space-y-1">
                    <div class="h-2 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div id="upload-bar" class="h-full rounded-full transition-all duration-300" style="width:0%; background:<?php echo $role_color;?>"></div>
                    </div>
                    <p id="upload-msg" class="text-xs text-slate-500 text-center">Uploading...</p>
                </div>
            </div>
            <div class="mt-8">
                <button id="finish-btn" onclick="photoStepNext()" class="btn-primary">
                    <?php echo $is_worker ? 'Finish Setup' : 'Get Started'; ?>
                    <span class="material-icons-round text-xl"><?php echo $is_worker ? 'check_circle' : 'arrow_forward'; ?></span>
                </button>
            </div>
        </div>

        <!-- DONE SCREEN -->
        <div class="step-panel hidden" id="panel-done">
            <div class="py-8 flex flex-col items-center text-center gap-6">
                <div class="w-24 h-24 rounded-full flex items-center justify-center text-5xl" style="background:<?php echo $role_color;?>15">🎉</div>
                <div>
                    <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white mb-3 tracking-tight">You're all set!</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-base max-w-sm mx-auto leading-relaxed">
                        <?php echo $is_worker ? "Your worker profile is live. Start accepting jobs and earning today!" : "Your profile is ready. Find skilled workers near you, fast!"; ?>
                    </p>
                </div>
                <a href="../<?php echo $is_worker ? 'worker' : 'client'; ?>/dashboard.php" class="btn-primary mt-2" style="width:auto; padding: 1rem 2.5rem;">
                    <span class="material-icons-round">dashboard</span>
                    Go to Dashboard
                </a>
            </div>
        </div>

    </div>
</div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const IS_WORKER   = <?php echo $is_worker ? 'true' : 'false'; ?>;
const TOTAL_STEPS = <?php echo $total_steps; ?>;
const ROLE_COLOR  = '<?php echo $role_color; ?>';
const MAX_SKILLS  = 3;  // [NEW] Rule of Three constant

const PANELS = IS_WORKER
    ? ['panel-0','panel-1','panel-2','panel-3','panel-4','panel-done']
    : ['panel-0','panel-done'];

let current        = 0;
let selectedSkills = [];
let pinnedLat = null, pinnedLng = null;
let leafMap = null, leafMarker = null;
let photoFile = null, photoUploaded = false;

// ── Dots ───────────────────────────────────────────────────
function updateDots() {
    for (let i = 0; i < TOTAL_STEPS; i++) {
        const d = document.getElementById('dot-' + i);
        if (!d) continue;
        d.className = 'step-dot';
        if (i < current)  d.classList.add('done');
        if (i === current) d.classList.add('active');
    }
    const bar = document.getElementById('progress-bar');
    if (bar) bar.style.width = Math.min(100, ((current + 1) / TOTAL_STEPS) * 100) + '%';
}

function goTo(nextIdx) {
    const cur  = document.getElementById(PANELS[current]);
    const next = document.getElementById(PANELS[nextIdx]);
    cur.classList.add('slide-out');
    setTimeout(() => {
        cur.classList.add('hidden'); cur.classList.remove('slide-out');
        next.classList.remove('hidden'); next.classList.add('slide-in');
        setTimeout(() => next.classList.remove('slide-in'), 360);
        if (PANELS[nextIdx] === 'panel-2') initMap();
        if (PANELS[nextIdx] === 'panel-3') buildBioSuggestions();
    }, 320);
    current = nextIdx;
    updateDots();
}

async function doFinish() {
    await api('finish', {});
    goTo(PANELS.indexOf('panel-done'));
}

// ── [CHANGED] STEP 0: Skills with Rule of Three ────────────
document.querySelectorAll('.skill-bubble').forEach(chip => {
    chip.addEventListener('click', () => {
        const v    = chip.dataset.value;
        const isOn = chip.classList.contains('on');

        // Already at cap and trying to add a new one
        if (!isOn && selectedSkills.length >= MAX_SKILLS) {
            chip.classList.add('shake');
            setTimeout(() => chip.classList.remove('shake'), 400);
            return;
        }

        chip.classList.toggle('on');
        if (chip.classList.contains('on')) {
            selectedSkills.push(v);
        } else {
            selectedSkills = selectedSkills.filter(s => s !== v);
        }
        updateSkillUI();
    });
});

function updateSkillUI() {
    const count = selectedSkills.length;
    const pill  = document.getElementById('skill-counter-pill');
    const txt   = document.getElementById('skill-counter-text');
    const lim   = document.getElementById('skills-limit-msg');

    txt.textContent = count + ' / 3 selected';

    // Update dots
    for (let i = 0; i < 3; i++) {
        const dot = document.getElementById('sdot-' + i);
        if (dot) dot.classList.toggle('filled', i < count);
    }

    if (count >= MAX_SKILLS) {
        pill.classList.add('full');
        lim.classList.remove('hidden');
    } else {
        pill.classList.remove('full');
        lim.classList.add('hidden');
    }

    // Dim un-selected bubbles when cap is reached
    document.querySelectorAll('.skill-bubble').forEach(b => {
        if (!b.classList.contains('on') && count >= MAX_SKILLS) {
            b.classList.add('maxed-out');
        } else {
            b.classList.remove('maxed-out');
        }
    });
}

async function step0Next() {
    if (!selectedSkills.length) {
        document.getElementById('skills-err').classList.remove('hidden');
        return;
    }
    document.getElementById('skills-err').classList.add('hidden');
    await api('save_skills', { skills: selectedSkills });
    goTo(current + 1);
}

// ── STEP 1: Rate ───────────────────────────────────────────
function syncRate(val, from) {
    const v = Math.max(100, Math.min(999999, parseInt(val) || 100));
    document.getElementById('rate-display').textContent = v.toLocaleString();
    if (from !== 'input') document.getElementById('rate-input').value = v;
    if (from !== 'slider' && v <= 5000) document.getElementById('rate-slider').value = v;
    const pct = ((Math.min(v, 5000) - 100) / (5000 - 100) * 100).toFixed(2) + '%';
    document.getElementById('rate-slider').style.setProperty('--pct', pct);
}
function setRate(v) { syncRate(v, 'both'); }
async function step1Next() {
    const rate = parseInt(document.getElementById('rate-input').value) || 300;
    await api('save_rate', { rate });
    goTo(current + 1);
}

// ── STEP 2: Map ────────────────────────────────────────────
function initMap() {
    if (leafMap) { leafMap.invalidateSize(); return; }
    leafMap = L.map('setup-map', { zoomControl: false }).setView([9.317, 125.96], 11);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        { attribution: '© OpenStreetMap contributors' }).addTo(leafMap);
    const icon = L.divIcon({
        className: 'custom-marker',
        html: `<i class="fa-solid fa-location-dot" style="font-size:34px;color:${ROLE_COLOR};filter:drop-shadow(0 3px 8px ${ROLE_COLOR}88)"></i>`,
        iconSize:[34,34], iconAnchor:[17,34]
    });
    leafMap.on('click', e => {
        pinnedLat = e.latlng.lat; pinnedLng = e.latlng.lng;
        document.getElementById('setup-lat').value = pinnedLat.toFixed(6);
        document.getElementById('setup-lng').value = pinnedLng.toFixed(6);
        if (leafMarker) leafMarker.setLatLng(e.latlng);
        else leafMarker = L.marker(e.latlng, { icon }).addTo(leafMap);
        document.getElementById('map-hint').textContent = '📍 Pin set! Click again to move it.';
    });
}
function mZoomIn()  { leafMap?.zoomIn(); }
function mZoomOut() { leafMap?.zoomOut(); }
function mLocateMe() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(pos => {
        leafMap.setView([pos.coords.latitude, pos.coords.longitude], 15);
        leafMap.fire('click', { latlng: L.latLng(pos.coords.latitude, pos.coords.longitude) });
    });
}
async function step2Next() {
    if (pinnedLat && pinnedLng) await api('save_location', { lat: pinnedLat, lng: pinnedLng });
    goTo(current + 1);
}

// ── STEP 3: Bio ────────────────────────────────────────────
const bioDB = {
    Electrical:    ["Licensed electrician with 5+ years of experience. I handle wiring, installations, and repairs safely and up to code.", "Reliable electrical professional serving Surigao del Sur. From outlets to panels — I've got you covered."],
    Plumbing:      ["Experienced plumber specializing in pipe repairs, drainage, and fixture installation.", "Your trusted plumbing expert. I fix leaks, unclog drains, and install new systems efficiently."],
    Carpentry:     ["Skilled carpenter offering furniture making, roofing, and general woodwork.", "From built-in cabinets to full renovations — I build and repair with precision and care."],
    Masonry:       ["Masonry specialist with expertise in concrete works, stone laying, and structural repairs.", "Building strong, lasting structures. Clean finishes and solid foundations, guaranteed."],
    Welding:       ["Certified welder proficient in SMAW and metal fabrication.", "From steel gates to structural work — I weld it right the first time."],
    Construction:  ["General construction contractor experienced in residential and commercial builds.", "Your hands-on construction partner — from foundation to finishing touches."],
    Automotive:    ["Automotive technician specializing in engine diagnostics, brake systems, and general repair.", "Car troubles? I'll get you back on the road. Honest assessments, quality repairs."],
    Motorcycle:    ["Motorcycle mechanic experienced with all makes and models. Fast, affordable service.", "Keep your bike running smooth. From tune-ups to major overhauls."],
    Electronics:   ["Electronics technician skilled in diagnosing and repairing smartphones, appliances, and circuit boards.", "Your gadget repair expert. I restore electronics quickly and affordably."],
    'Aircon Ref':  ["RAC technician certified in aircon cleaning, refrigerant charging, and refrigerator repair.", "I service all major AC and refrigeration brands — residential and commercial."],
    'Computer Tech': ["Computer technician offering virus removal, hardware repair, OS installation, and networking.", "Your go-to tech support. I solve PC problems fast so you can get back to work."],
    'Domestic Work': ["Reliable housekeeper providing thorough cleaning and household management.", "Professional domestic worker offering regular or deep cleaning. Trustworthy and detail-oriented."],
    Caregiving:    ["Compassionate caregiver with experience assisting elderly individuals and patients.", "Dedicated caregiver offering personal assistance, medication reminders, and companionship."],
    Massage:       ["Licensed massage therapist offering relaxing and therapeutic sessions.", "Certified masseur specializing in Swedish, sports, and deep tissue massage."],
    'Beauty Care': ["Professional hair and nail technician. Salon-quality results at your doorstep.", "Beauty specialist offering haircut, styling, and nail services. I bring the salon to you!"],
    Cookery:       ["Private chef specializing in Filipino cuisine. Perfect for events and meal prep.", "Experienced cook offering catering and private meal prep. Delicious food, every time!"],
    Baking:        ["Passionate baker crafting custom cakes, pastries, and breads for all occasions.", "Custom baked goods for events and everyday cravings. Every bite made with love."],
    Graphic_Design:["Creative graphic designer producing logos, social media content, and print materials.", "Visual storyteller with a knack for clean, impactful design."],
    Photography:   ["Professional photographer specializing in events, portraits, and product photography.", "Capturing memories that last a lifetime."],
    Videography:   ["Videographer and editor creating cinematic content for weddings, events, and social media.", "Tell your story through video."],
    Music:         ["Music producer and audio engineer offering beat making, recording, mixing, and mastering.", "From recording to mastering — professional music production and audio services."],
    'Arts Crafts': ["Creative artisan specializing in handmade crafts, home decor, and custom artwork.", "Unique handcrafted pieces for your home or as gifts."],
    Others:        ["Versatile service professional ready to help with a variety of tasks.", "Multi-skilled worker open to different jobs. Just ask — I'm here to help!"],
};
function buildBioSuggestions() {
    const wrap = document.getElementById('bio-suggestions');
    wrap.innerHTML = '';
    const texts = [...new Set(selectedSkills.flatMap(s => bioDB[s] || []))].slice(0, 4);
    texts.forEach(text => {
        const card = document.createElement('div');
        card.className = 'bio-card';
        card.textContent = `"${text}"`;
        card.addEventListener('click', () => {
            document.querySelectorAll('.bio-card').forEach(c => c.classList.remove('on'));
            card.classList.add('on');
            const ta = document.getElementById('bio-text');
            ta.value = text;
            document.getElementById('bio-count').textContent = text.length + ' / 500';
        });
        wrap.appendChild(card);
    });
}
async function step3Next() {
    const bio = document.getElementById('bio-text').value.trim();
    if (bio) await api('save_bio', { bio });
    goTo(current + 1);
}

// ── Photo step ─────────────────────────────────────────────
function previewPhoto(input) {
    if (!input.files?.[0]) return;
    photoFile = input.files[0];
    const r = new FileReader();
    r.onload = e => { document.getElementById('photo-preview').src = e.target.result; };
    r.readAsDataURL(photoFile);
}
function onDrop(e) {
    e.preventDefault();
    document.getElementById('drop-zone').classList.remove('over');
    const f = e.dataTransfer.files[0];
    if (f?.type.startsWith('image/')) {
        photoFile = f;
        const r = new FileReader();
        r.onload = ev => { document.getElementById('photo-preview').src = ev.target.result; };
        r.readAsDataURL(f);
    }
}
async function photoStepNext() {
    const btn = document.getElementById('finish-btn');
    btn.disabled = true;
    if (photoFile && !photoUploaded) {
        const prog = document.getElementById('upload-progress');
        const bar  = document.getElementById('upload-bar');
        const msg  = document.getElementById('upload-msg');
        prog.classList.remove('hidden');
        let pct = 0;
        const t = setInterval(() => { pct = Math.min(pct + 10, 80); bar.style.width = pct + '%'; }, 70);
        const fd = new FormData();
        fd.append('action', 'save_photo'); fd.append('photo', photoFile);
        try {
            const data = await (await fetch('profile_setup.php', { method:'POST', body:fd })).json();
            clearInterval(t);
            if (data.success) {
                bar.style.width = '100%'; msg.textContent = '✓ Photo saved!'; msg.style.color = ROLE_COLOR;
                photoUploaded = true;
                setTimeout(() => doFinish(), 600);
            } else {
                msg.textContent = '⚠️ ' + (data.error || 'Upload failed'); msg.style.color = '#ef4444';
                btn.disabled = false;
            }
        } catch { clearInterval(t); setTimeout(() => doFinish(), 400); }
    } else {
        await doFinish();
    }
}

// ── API helper ─────────────────────────────────────────────
async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) {
        Array.isArray(v) ? v.forEach(x => fd.append(k+'[]',x)) : fd.append(k, v);
    }
    try { return await (await fetch('profile_setup.php',{method:'POST',body:fd})).json(); }
    catch { return { success:false }; }
}

// ── Init ───────────────────────────────────────────────────
updateDots();
</script>
</body>
</html>