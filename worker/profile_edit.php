<?php
// worker/profile_edit.php

include '../includes/init_lang.php';
include '../db.php';
include '../includes/sms_sender.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$msg = ""; $msg_type = ""; $otp_modal_open = false;

// A. Handle Profile Picture Upload
if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
    $target_dir = "../uploads/profiles/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    $fname = "pfp_" . $worker_id . time() . ".jpg";
    if(move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target_dir.$fname)) {
        $conn->query("UPDATE users SET profile_pic='$fname' WHERE id='$worker_id'");
        header("Location: profile_edit.php"); exit();
    }
}

// B. Handle Portfolio Upload
if (isset($_FILES['portfolio_imgs'])) {
    $target_dir = "../uploads/portfolios/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    $count = count($_FILES['portfolio_imgs']['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['portfolio_imgs']['error'][$i] == 0) {
            $ext = pathinfo($_FILES['portfolio_imgs']['name'][$i], PATHINFO_EXTENSION);
            $new_name = time() . "_port_" . $worker_id . "_$i." . $ext;
            if (move_uploaded_file($_FILES['portfolio_imgs']['tmp_name'][$i], $target_dir . $new_name)) {
                $conn->query("INSERT INTO portfolio_images (user_id, image_path) VALUES ('$worker_id', '$new_name')");
            }
        }
    }
    header("Location: profile_edit.php?msg=portfolio_updated"); exit();
}

// C. Handle Portfolio Deletion
if (isset($_GET['delete_portfolio'])) {
    $img_id = intval($_GET['delete_portfolio']);
    $check = $conn->query("SELECT image_path FROM portfolio_images WHERE id='$img_id' AND user_id='$worker_id'");
    if ($check->num_rows > 0) {
        $img = $check->fetch_assoc();
        if (file_exists("../uploads/portfolios/" . $img['image_path'])) unlink("../uploads/portfolios/" . $img['image_path']);
        $conn->query("DELETE FROM portfolio_images WHERE id='$img_id'");
    }
    header("Location: profile_edit.php"); exit();
}

// D. Handle Main Profile Update
if (isset($_POST['update_profile'])) {
    $full_name   = $conn->real_escape_string($_POST['full_name']);
    $address     = $conn->real_escape_string($_POST['address']);
    $municipality= $conn->real_escape_string($_POST['municipality']);
    $new_lat     = $_POST['latitude'];
    $new_lng     = $_POST['longitude'];
    $new_phone   = $conn->real_escape_string($_POST['phone']);
    $birthdate   = $_POST['birthdate'];
    $category    = $_POST['service_category'];
    $bio         = $conn->real_escape_string($_POST['bio']);
    $rate        = floatval($_POST['minimum_standard_rate'] ?? 0);

    $curr_check        = $conn->query("SELECT phone FROM users WHERE id = '$worker_id'")->fetch_assoc();
    $current_phone_db  = $curr_check['phone'];

    $conn->query("UPDATE users SET full_name='$full_name', address='$address', municipality='$municipality', latitude='$new_lat', longitude='$new_lng', birthdate='$birthdate' WHERE id='$worker_id'");
    $conn->query("UPDATE worker_profiles SET service_category='$category', bio='$bio', minimum_standard_rate='$rate' WHERE user_id='$worker_id'");

    if ($new_phone != $current_phone_db) {
        $otp_response        = sendOTP($new_phone);
        $_SESSION['temp_new_phone'] = $new_phone;
        $_SESSION['temp_otp']       = isset($otp_response['data']['otp_code']) ? $otp_response['data']['otp_code'] : rand(100000, 999999);
        $otp_modal_open = true;
    } else {
        $msg = "✅ Profile updated successfully!"; $msg_type = "success";
    }
}

// E. Handle OTP Verification
if (isset($_POST['verify_otp'])) {
    if ($_POST['otp_code'] == $_SESSION['temp_otp']) {
        $new_p = $_SESSION['temp_new_phone'];
        $conn->query("UPDATE users SET phone='$new_p', is_phone_verified=1 WHERE id='$worker_id'");
        unset($_SESSION['temp_new_phone']); unset($_SESSION['temp_otp']);
        echo "<script>alert('✅ Phone Number Verified & Updated!'); window.location.href='profile_edit.php';</script>";
        exit();
    } else {
        $msg = "❌ Invalid OTP Code"; $msg_type = "error"; $otp_modal_open = true;
    }
}

// ── FETCH MAIN DATA ──────────────────────────────────────────────────────────
$sql = "SELECT u.full_name, u.phone, u.email, u.is_email_verified, u.birthdate, u.profile_pic,
               u.address, u.municipality, u.latitude, u.longitude, u.created_at, u.is_phone_verified,
               w.service_category, w.bio, w.verification_status, w.is_verified, w.jobs_completed,
               w.average_rating, w.minimum_standard_rate
        FROM users u
        JOIN worker_profiles w ON u.id = w.user_id
        WHERE u.id = '$worker_id'";
$data = $conn->query($sql)->fetch_assoc();

$lat = $data['latitude'] ?: 12.8797;
$lng = $data['longitude'] ?: 121.7740;
$current_phone = $data['phone'];

// ── FETCH NC / SKILL DATA ────────────────────────────────────────────────────
// All registered worker skills with their verification badge
$worker_skills_res = $conn->query(
    "SELECT ws.id, ws.sub_category, ws.main_category, ws.nc_level,
            ws.badge_level, ws.is_verified, ws.verified_at, ws.verification_notes
     FROM worker_skills ws
     WHERE ws.worker_id = '$worker_id'
     ORDER BY ws.is_verified DESC, ws.badge_level DESC, ws.sub_category ASC"
);
$worker_skills = [];
while ($row = $worker_skills_res->fetch_assoc()) {
    $worker_skills[] = $row;
}

// Build quick lookup: sub_category → skill data
$skill_map = [];
foreach ($worker_skills as $ws) {
    $skill_map[$ws['sub_category']] = $ws;
}

// The service_category field (comma-separated) is the source of truth for what's selected
$selected_cats_raw = array_map('trim', explode(',', $data['service_category'] ?? ''));
$selected_cats     = array_filter($selected_cats_raw);

$has_any_verified_skill = false;
foreach ($worker_skills as $ws) {
    if ($ws['is_verified'] && $ws['badge_level'] !== 'Unverified') {
        $has_any_verified_skill = true;
        break;
    }
}

$reviews_featured = $conn->query("SELECT r.*, u.full_name FROM reviews r JOIN users u ON r.client_id = u.id WHERE r.worker_id = '$worker_id' ORDER BY r.created_at DESC LIMIT 3");
$reviews_all      = $conn->query("SELECT r.*, u.full_name FROM reviews r JOIN users u ON r.client_id = u.id WHERE r.worker_id = '$worker_id' ORDER BY r.created_at DESC");
$portfolio        = $conn->query("SELECT * FROM portfolio_images WHERE user_id = '$worker_id' ORDER BY uploaded_at DESC");

function getInitials($name) {
    $words = explode(' ', $name); $initials = '';
    foreach ($words as $word) $initials .= strtoupper(substr($word, 0, 1));
    return substr($initials, 0, 2);
}
$initials = getInitials($data['full_name']);

// ── BADGE HELPERS ────────────────────────────────────────────────────────────
function badgeConfig($badge) {
    return match($badge) {
        'Gold'      => ['icon'=>'workspace_premium','label'=>'Gold NC',    'cls'=>'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',  'dot'=>'bg-yellow-400'],
        'Silver'    => ['icon'=>'military_tech',    'label'=>'Silver NC',  'cls'=>'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-600',             'dot'=>'bg-slate-400'],
        'Bronze'    => ['icon'=>'verified',         'label'=>'Bronze NC',  'cls'=>'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',          'dot'=>'bg-amber-500'],
        'Community' => ['icon'=>'groups',           'label'=>'Community',  'cls'=>'bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-800',                      'dot'=>'bg-sky-400'],
        default     => ['icon'=>'pending',          'label'=>'Pending',    'cls'=>'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-700',             'dot'=>'bg-slate-300'],
    };
}

$SUB_EMOJI = [
    'Electrical'=>'⚡','Plumbing'=>'💧','Carpentry'=>'🔨','Masonry'=>'🧱','Welding'=>'🔥',
    'Construction'=>'🏗️','Automotive'=>'🚘','Motorcycle'=>'🏍️','Electronics'=>'📱',
    'Aircon_Ref'=>'❄️','Aircon Ref'=>'❄️','Computer_Tech'=>'💻','Computer Tech'=>'💻',
    'Domestic_Work'=>'🧹','Domestic Work'=>'🧹','Caregiving'=>'🤱','Massage'=>'💆',
    'Beauty_Care'=>'💇','Beauty Care'=>'💇','Cookery'=>'👨‍🍳','Baking'=>'🥖',
    'Graphic_Design'=>'🎨','Photography'=>'📸','Videography'=>'🎥','Music'=>'🎵',
    'Arts_Crafts'=>'🖌️','Arts Crafts'=>'🖌️','Others'=>'🔹',
];

// ── HELPER: should a skill card show "Verify Now"? ───────────────────────────
// Show for: Pending, Unverified, Community — NOT for Gold/Silver/Bronze
function shouldShowVerifyNow($badge) {
    return !in_array($badge, ['Gold', 'Silver', 'Bronze']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Worker Profile | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet"/>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: {
                colors: { primary: "#0ea5e9", "background-light": "#F8FAFC", "background-dark": "#0F172A",
                          accent: { blue: "#3B82F6", green: "#10B981", red: "#EF4444" } },
                fontFamily: { display: ["Manrope","sans-serif"], sans: ["Manrope","sans-serif"] },
                borderRadius: { DEFAULT: "12px", '2xl': '24px' },
            }},
        };
    </script>
    <style type="text/tailwindcss">
        .glass { background: rgba(255,255,255,0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.3) }
        .dark .glass { background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.1) }
        .vibrant-gradient { background: linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%); }
        #map { height: 300px; width: 100%; z-index: 1; border-radius: 16px; }
        @media (min-width: 768px) { #map { height: 400px; } }

        /* ── CHANGE 2: Tab buttons — equal distribution, fixed on mobile ── */
        .tab-btn {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            flex: 1; min-width: 0;
            padding: 9px 6px; border-radius: 10px; font-size: 12px; font-weight: 700;
            color: #64748b; transition: all 0.18s ease; white-space: nowrap; cursor: pointer;
            border: none; background: transparent;
        }
        .dark .tab-btn { color: #94a3b8; }
        .tab-btn:hover { background: #f1f5f9; color: #0ea5e9; }
        .dark .tab-btn:hover { background: rgba(14,165,233,0.08); color: #0ea5e9; }
        .tab-btn.active { background: #0ea5e9; color: white; box-shadow: 0 4px 12px rgba(14,165,233,0.3); }
        /* Tab container: no overflow scroll, full width flex */
        .tab-container { display: flex; gap: 4px; width: 100%; }

        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .skill-bubble {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 5px; cursor: pointer; padding: 10px 6px; border-radius: 16px; border: 2px solid #e2e8f0;
            background: rgba(255,255,255,0.7); color: #64748b; font-size: 10px; font-weight: 700;
            text-align: center; transition: all 0.18s ease; line-height: 1.3; letter-spacing: 0.02em; user-select: none;
        }
        .dark .skill-bubble { background: rgba(30,41,59,0.5); border-color: #334155; color: #94a3b8; }
        .skill-bubble .bubble-icon { font-size: 20px; line-height: 1; transition: transform 0.18s ease; }
        .skill-bubble:hover { border-color: #0ea5e980; transform: translateY(-1px); }
        .skill-bubble:hover .bubble-icon { transform: scale(1.1); }
        .skill-bubble.on { border-color: #0ea5e9; background: rgba(14,165,233,0.08); color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.15); }
        .dark .skill-bubble.on { background: rgba(14,165,233,0.15); }
        input[type=range].rate-slider {
            -webkit-appearance: none; width: 100%; height: 6px; border-radius: 3px; outline: none; cursor: pointer;
            background: linear-gradient(to right, #0ea5e9 0%, #0ea5e9 var(--pct, 4%), #e2e8f0 var(--pct, 4%), #e2e8f0 100%);
        }
        .dark input[type=range].rate-slider {
            background: linear-gradient(to right, #0ea5e9 0%, #0ea5e9 var(--pct, 4%), #334155 var(--pct, 4%), #334155 100%);
        }
        input[type=range].rate-slider::-webkit-slider-thumb {
            -webkit-appearance: none; width: 22px; height: 22px; border-radius: 50%;
            background: white; border: 3px solid #0ea5e9; cursor: pointer;
            box-shadow: 0 2px 8px rgba(14,165,233,0.4); transition: transform 0.15s;
        }
        input[type=range].rate-slider::-webkit-slider-thumb:hover { transform: scale(1.15); }
        @keyframes slideUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }
        @keyframes slideDown { from { transform:translate(-50%,-20px); opacity:0; } to { transform:translate(-50%,0); opacity:1; } }
        @keyframes fadeOut { from { opacity:1; } to { opacity:0; } }
        @keyframes pulse { 0% { transform:scale(1); opacity:0.3; } 50% { transform:scale(1.8); opacity:0.1; } 100% { transform:scale(1); opacity:0.3; } }

        /* ── Skill card (verified view) ─────────────────────────────────── */
        .skill-card {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border-radius: 16px; border: 2px solid #e2e8f0;
            background: rgba(255,255,255,0.7); transition: all 0.2s;
        }
        .dark .skill-card { background: rgba(30,41,59,0.5); border-color: #334155; }
        .skill-card.verified-gold   { border-color: #fbbf24; background: rgba(254,243,199,0.5); }
        .skill-card.verified-silver { border-color: #94a3b8; background: rgba(241,245,249,0.8); }
        .skill-card.verified-bronze { border-color: #d97706; background: rgba(255,237,213,0.5); }
        .skill-card.verified-community { border-color: #38bdf8; background: rgba(224,242,254,0.5); }
        .dark .skill-card.verified-gold   { border-color: #fbbf24; background: rgba(120,53,15,0.2); }
        .dark .skill-card.verified-silver { border-color: #94a3b8; background: rgba(51,65,85,0.5); }
        .dark .skill-card.verified-bronze { border-color: #d97706; background: rgba(120,53,15,0.15); }
        .dark .skill-card.verified-community { border-color: #38bdf8; background: rgba(7,89,133,0.2); }
        .skill-card.pending { border-color: #e2e8f0; opacity: 0.75; }
        .dark .skill-card.pending { border-color: #334155; }
        .badge-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 99px; font-size: 10px; font-weight: 800;
            letter-spacing: 0.04em; text-transform: uppercase; border-width: 1px; border-style: solid;
        }
        /* Edit-skills toggle */
        .edit-skills-toggle { 
            display: flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 10px; font-size: 11px; font-weight: 700;
            color: #0ea5e9; border: 1.5px solid #0ea5e930; background: rgba(14,165,233,0.05);
            cursor: pointer; transition: all 0.18s; user-select: none; width: fit-content;
        }
        .edit-skills-toggle:hover { background: rgba(14,165,233,0.12); border-color: #0ea5e960; }
        .collapsible-skills { overflow: hidden; transition: max-height 0.35s cubic-bezier(.22,1,.36,1), opacity 0.25s; max-height: 0; opacity: 0; }
        .collapsible-skills.open { max-height: 1200px; opacity: 1; }

        /* NC level badge inside skill card */
        .nc-level-tag {
            font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 99px;
            letter-spacing: 0.06em; text-transform: uppercase;
            background: rgba(14,165,233,0.1); color: #0284c7; border: 1px solid rgba(14,165,233,0.2);
        }

        /* ── CHANGE 1: Verify Now button on skill cards ─────────────────── */
        .verify-now-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 5px 11px; border-radius: 8px; font-size: 10px; font-weight: 800;
            letter-spacing: 0.03em; text-decoration: none; transition: all 0.18s;
            background: rgba(14,165,233,0.1); color: #0284c7;
            border: 1.5px solid rgba(14,165,233,0.3);
            white-space: nowrap; flex-shrink: 0;
        }
        .verify-now-btn:hover { background: #0ea5e9; color: white; border-color: #0ea5e9; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(14,165,233,0.3); }
        .skill-card.verified-community .verify-now-btn { background: rgba(56,189,248,0.1); color: #0284c7; border-color: rgba(56,189,248,0.35); }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-sans transition-colors duration-300 min-h-screen pb-20 md:pb-10">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-3xl mx-auto px-3 md:px-4 pt-6 space-y-5">

    <!-- Alert -->
    <?php if($msg): ?>
    <div class="bg-<?php echo $msg_type=='success' ? 'accent-green/10 border-accent-green/20 text-accent-green' : 'accent-red/10 border-accent-red/20 text-accent-red'; ?> border rounded-2xl p-4 flex items-center gap-3 animate-slideUp">
        <span class="material-symbols-outlined"><?php echo $msg_type=='success' ? 'check_circle' : 'error'; ?></span>
        <span class="font-medium"><?php echo $msg; ?></span>
        <button class="ml-auto" onclick="this.parentElement.remove()"><span class="material-symbols-outlined">close</span></button>
    </div>
    <?php endif; ?>

    <!-- ══ PROFILE HEADER ══════════════════════════════════════════════════ -->
    <section class="relative overflow-hidden rounded-2xl bg-white dark:bg-slate-800 shadow-lg border border-slate-100 dark:border-slate-700 p-4 md:p-7 animate-slideUp">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-primary/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-sky-200/20 rounded-full blur-3xl"></div>

        <div class="relative flex flex-col md:flex-row items-center justify-between gap-5">
            <div class="flex flex-col md:flex-row items-center gap-5 w-full">

                <!-- Avatar -->
                <div class="relative flex-shrink-0">
                    <?php $hasImage = !empty($data['profile_pic']) && file_exists("../uploads/profiles/".$data['profile_pic']); ?>
                    <?php if ($hasImage): ?>
                        <img src="../uploads/profiles/<?php echo $data['profile_pic']; ?>" class="w-24 h-24 md:w-28 md:h-28 rounded-full object-cover shadow-xl border-4 border-white dark:border-slate-800" alt="<?php echo $data['full_name']; ?>">
                    <?php else: ?>
                        <div class="w-24 h-24 md:w-28 md:h-28 rounded-full vibrant-gradient flex items-center justify-center text-white text-4xl md:text-5xl font-extrabold shadow-xl border-4 border-white dark:border-slate-800"><?php echo $initials; ?></div>
                    <?php endif; ?>
                    <div onclick="document.getElementById('pfp-input').click()" class="absolute bottom-0 right-0 bg-white dark:bg-slate-800 text-primary p-1.5 rounded-full border-4 border-white dark:border-slate-800 flex items-center justify-center shadow-lg cursor-pointer hover:bg-primary hover:text-white transition-colors">
                        <span class="material-symbols-outlined text-base">photo_camera</span>
                    </div>
                    <?php if($data['is_verified']): ?>
                    <div class="absolute bottom-0 left-0 bg-accent-green text-white p-1.5 rounded-full border-4 border-white dark:border-slate-800 flex items-center justify-center shadow-lg">
                        <span class="material-symbols-outlined text-base font-bold">check</span>
                    </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" id="pfp-form">
                        <input type="file" name="profile_pic" id="pfp-input" style="display:none;" onchange="document.getElementById('pfp-form').submit()" accept="image/*">
                    </form>
                </div>

                <!-- Name + chips + skill badges -->
                <div class="text-center md:text-left space-y-3 w-full">
                    <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                        <?php echo htmlspecialchars($data['full_name']); ?>
                    </h1>

                    <!-- Account-level badges row -->
                    <div class="flex flex-wrap justify-center md:justify-start gap-2">
                        <div class="glass flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium text-slate-600 dark:text-slate-300">
                            <span class="material-symbols-outlined text-primary text-sm">calendar_today</span>
                            Member since <?php echo date("F Y", strtotime($data['created_at'])); ?>
                        </div>
                        <?php
                        $status = $data['verification_status'];
                        if ($status && $status !== 'None' && $status !== 'Unverified'):
                            $bc = badgeConfig($status);
                        ?>
                        <div class="glass flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border <?php echo $bc['cls']; ?>">
                            <span class="material-symbols-outlined text-sm"><?php echo $bc['icon']; ?></span>
                            <?php echo $bc['label']; ?> Verified
                        </div>
                        <?php else: ?>
                        <a href="verification.php" class="glass flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium text-primary hover:bg-primary hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-sm">shield</span>
                            Get Verified
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Email / Phone verified row -->
                    <div class="flex flex-wrap justify-center md:justify-start gap-2">
                        <div class="<?php echo $data['is_email_verified'] ? 'bg-accent-green/10 border-accent-green/20 text-accent-green' : 'bg-accent-red/10 border-accent-red/20 text-accent-red'; ?> border flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
                            <span class="material-symbols-outlined text-xs"><?php echo $data['is_email_verified'] ? 'verified_user' : 'error'; ?></span>
                            Email <?php echo $data['is_email_verified'] ? 'Verified' : 'Not Verified'; ?>
                            <?php if(!$data['is_email_verified']): ?><a href="../auth/resend_email.php" class="underline ml-1">Verify</a><?php endif; ?>
                        </div>
                        <div class="<?php echo $data['is_phone_verified'] ? 'bg-accent-green/10 border-accent-green/20 text-accent-green' : 'bg-accent-red/10 border-accent-red/20 text-accent-red'; ?> border flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
                            <span class="material-symbols-outlined text-xs"><?php echo $data['is_phone_verified'] ? 'phonelink_setup' : 'error'; ?></span>
                            Phone <?php echo $data['is_phone_verified'] ? 'Verified' : 'Not Verified'; ?>
                            <?php if(!$data['is_phone_verified']): ?><a href="../auth/add_phone_google.php" class="underline ml-1">Verify</a><?php endif; ?>
                        </div>
                    </div>

                    <!-- ── PER-SKILL NC BADGE STRIP ────────────────────────────────── -->
                    <?php if (!empty($worker_skills)): ?>
                    <div>
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-2 text-center md:text-left">Skills & Certifications</p>
                        <div class="flex flex-wrap justify-center md:justify-start gap-2">
                            <?php foreach ($worker_skills as $ws):
                                $emoji   = $SUB_EMOJI[$ws['sub_category']] ?? '🔹';
                                $badge   = $ws['is_verified'] ? ($ws['badge_level'] ?? 'Unverified') : 'Pending';
                                $bc_sk   = badgeConfig($badge);
                                $ncLabel = $ws['nc_level'] ? ' · ' . $ws['nc_level'] : '';
                            ?>
                            <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-[10px] font-bold border <?php echo $bc_sk['cls']; ?>">
                                <span><?php echo $emoji; ?></span>
                                <span><?php echo htmlspecialchars($ws['sub_category']); ?><?php echo $ncLabel; ?></span>
                                <span class="material-symbols-outlined text-[12px]"><?php echo $bc_sk['icon']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- ── END SKILL STRIP ────────────────────────────────────────── -->
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Cards -->
    <div class="grid grid-cols-3 gap-3 animate-slideUp" style="animation-delay:0.1s;">
        <?php
        $stats = [
            ['star',     number_format($data['average_rating'],1), 'Avg Rating', 'amber-400'],
            ['work',     $data['jobs_completed'],                  'Jobs Done',  'emerald-500'],
            ['schedule', floor((time()-strtotime($data['created_at']))/(86400)).'d', 'Active Days','blue-500'],
        ];
        foreach ($stats as [$icon,$val,$label,$col]):
        ?>
        <div class="bg-white dark:bg-slate-800 rounded-xl p-4 shadow-sm border border-slate-200 dark:border-slate-700 text-center">
            <div class="w-9 h-9 bg-primary/10 rounded-lg flex items-center justify-center mx-auto mb-2">
                <span class="material-symbols-outlined text-primary text-lg"><?php echo $icon; ?></span>
            </div>
            <h3 class="text-xl font-bold text-slate-900 dark:text-white"><?php echo $val; ?></h3>
            <p class="text-slate-500 dark:text-slate-400 text-xs mt-0.5"><?php echo $label; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tab Navigation — CHANGE 2: equal distribution, fixed on mobile -->
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-100 dark:border-slate-700 p-1.5 animate-slideUp" style="animation-delay:0.15s;">
        <div class="tab-container">
            <?php foreach([['personal','edit_note','Personal'],['professional','work','Professional'],['portfolio','photo_library','Portfolio'],['reviews','star_rate','Reviews']] as [$t,$icon,$lbl]): ?>
            <button class="tab-btn <?php echo $t==='personal'?'active':''; ?>" onclick="switchTab('<?php echo $t; ?>')" id="tab-<?php echo $t; ?>">
                <span class="material-symbols-outlined text-sm"><?php echo $icon; ?></span><?php echo $lbl; ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Form -->
    <form method="POST" id="main-form">
        <input type="hidden" name="update_profile" value="1">

        <!-- ══ TAB: Personal ══════════════════════════════════════════════ -->
        <div class="tab-panel active" id="panel-personal">
            <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp">
                <div class="p-4 md:p-6 space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Full Name</label>
                            <input type="text" name="full_name" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-medium transition-all" value="<?php echo htmlspecialchars($data['full_name']); ?>" required>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Birthday</label>
                            <input type="date" name="birthdate" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-medium transition-all" value="<?php echo $data['birthdate']; ?>">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Municipality</label>
                            <div class="relative">
                                <select name="municipality" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-medium appearance-none transition-all" required>
                                    <option value="">Select Municipality</option>
                                    <?php foreach(['Tandag City','Bislig City','Barobo','Bayabas','Cagwait','Cantilan','Carmen','Carrascal','Cortes','Hinatuan','Lanuza','Lianga','Lingig','Madrid','Marihatag','San Agustin','San Miguel','Tagbina','Tago'] as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($data['municipality']==$m)?'selected':''; ?>><?php echo $m; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400 text-lg">expand_more</span>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Phone Number</label>
                            <input type="tel" name="phone" id="phone-input" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-medium transition-all" value="<?php echo htmlspecialchars($data['phone']); ?>" required>
                            <p class="text-xs text-slate-400 mt-1">Changing this requires OTP verification</p>
                        </div>
                        <div class="space-y-1.5 md:col-span-2">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Email Address</label>
                            <input type="email" class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-xl py-3 px-4 text-slate-400 dark:text-slate-500 text-base font-medium cursor-not-allowed" value="<?php echo htmlspecialchars($data['email']); ?>" disabled>
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Street / Barangay Address</label>
                        <input type="text" name="address" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-medium transition-all" value="<?php echo htmlspecialchars($data['address']); ?>" placeholder="e.g. Purok 1, Poblacion">
                    </div>
                    <!-- Map -->
                    <div class="space-y-3 pt-2">
                        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                            <span class="material-symbols-outlined text-accent-red text-sm">location_on</span>
                            <span class="text-xs font-semibold">Drag the marker to set your exact location</span>
                        </div>
                        <div class="relative h-[250px] md:h-[320px] w-full rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-inner">
                            <div id="map" class="absolute inset-0"></div>
                            <div class="absolute top-3 left-3 flex flex-col gap-1.5 z-10">
                                <button type="button" class="w-8 h-8 bg-white dark:bg-slate-800 text-slate-600 rounded-lg shadow-lg flex items-center justify-center hover:bg-slate-50 transition-colors" onclick="zoomIn()"><span class="material-symbols-outlined text-sm">add</span></button>
                                <button type="button" class="w-8 h-8 bg-white dark:bg-slate-800 text-slate-600 rounded-lg shadow-lg flex items-center justify-center hover:bg-slate-50 transition-colors" onclick="zoomOut()"><span class="material-symbols-outlined text-sm">remove</span></button>
                            </div>
                            <div class="absolute top-3 right-3 z-10">
                                <div class="glass px-3 py-1.5 rounded-full flex items-center gap-2 shadow-lg">
                                    <div class="w-2 h-2 bg-accent-red rounded-full animate-pulse"></div>
                                    <span class="text-xs font-semibold text-slate-700 dark:text-slate-200">Drag pin to adjust</span>
                                </div>
                            </div>
                            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 px-3 py-1.5 glass rounded-lg text-xs font-bold text-slate-800 dark:text-white pointer-events-none whitespace-nowrap">
                                <?php echo htmlspecialchars($data['municipality'] ?: 'Cantilan'); ?>, Surigao del Sur
                            </div>
                        </div>
                        <div class="flex justify-center">
                            <button type="button" id="resetLocation" class="px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/10 rounded-lg transition-colors flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-sm">undo</span>Reset to Saved Location
                            </button>
                        </div>
                        <input type="hidden" name="latitude"  id="lat" value="<?php echo $lat; ?>">
                        <input type="hidden" name="longitude" id="lng" value="<?php echo $lng; ?>">
                    </div>
                </div>
            </section>
            <div class="flex justify-end pt-3">
                <button type="button" onclick="checkPhoneChange()" class="vibrant-gradient text-white px-8 py-3 rounded-xl font-bold flex items-center gap-2 hover:scale-[1.02] active:scale-95 transition-all shadow-lg shadow-primary/25 text-sm w-full md:w-auto justify-center">
                    <span class="material-symbols-outlined text-lg">save</span>Save Changes
                </button>
            </div>
        </div>

        <!-- ══ TAB: Professional ══════════════════════════════════════════ -->
        <div class="tab-panel" id="panel-professional">
            <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp">
                <div class="p-4 md:p-6 space-y-7">

                    <!-- SERVICE CATEGORIES ─────────────────────────────── -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1 mb-1">Service Categories</label>
                            <p class="text-xs text-slate-400 ml-1">Your registered skills and verification status</p>
                        </div>

                        <?php if (!empty($worker_skills)): ?>
                        <!-- ── VERIFIED / REGISTERED SKILLS DISPLAY ─────────────── -->
                        <div class="space-y-3" id="skills-display">
                            <?php foreach ($worker_skills as $ws):
                                $emoji  = $SUB_EMOJI[$ws['sub_category']] ?? '🔹';
                                $badge  = $ws['is_verified'] ? ($ws['badge_level'] ?? 'Unverified') : ($ws['badge_level'] !== 'Unverified' ? 'Pending' : 'Unverified');
                                // If admin hasn't touched it yet but it was submitted, call it Pending
                                if (!$ws['is_verified'] && $ws['verification_notes'] === 'Pending admin review') $badge = 'Pending';
                                $bc     = badgeConfig($badge);
                                $cardCls= match($badge) {
                                    'Gold'      => 'verified-gold',
                                    'Silver'    => 'verified-silver',
                                    'Bronze'    => 'verified-bronze',
                                    'Community' => 'verified-community',
                                    default     => 'pending',
                                };
                                $showVerifyBtn = shouldShowVerifyNow($badge);
                            ?>
                            <div class="skill-card <?php echo $cardCls; ?>">
                                <!-- Emoji -->
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-xl flex-shrink-0 bg-white/60 dark:bg-slate-800/60 shadow-sm">
                                    <?php echo $emoji; ?>
                                </div>
                                <!-- Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($ws['sub_category']); ?></span>
                                        <?php if ($ws['nc_level']): ?>
                                        <span class="nc-level-tag"><?php echo htmlspecialchars($ws['nc_level']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[10px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($ws['main_category']); ?></p>
                                    <?php if ($ws['is_verified'] && $ws['verified_at']): ?>
                                    <p class="text-[9px] text-slate-400 mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[11px]">event_available</span>
                                        Verified <?php echo date('M d, Y', strtotime($ws['verified_at'])); ?>
                                    </p>
                                    <?php elseif ($badge === 'Pending'): ?>
                                    <p class="text-[9px] text-amber-500 mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[11px]">pending</span>
                                        Under admin review
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <!-- Right side: badge pill + optional Verify Now button -->
                                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                                    <span class="badge-pill <?php echo $bc['cls']; ?>">
                                        <span class="material-symbols-outlined" style="font-size:11px;"><?php echo $bc['icon']; ?></span>
                                        <?php echo $bc['label']; ?>
                                    </span>
                                    <?php if ($showVerifyBtn): ?>
                                    <a href="verification.php" class="verify-now-btn">
                                        <span class="material-symbols-outlined" style="font-size:11px;">shield</span>
                                        Verify Now
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Edit skills toggle -->
                        <button type="button" class="edit-skills-toggle" onclick="toggleEditSkills(this)">
                            <span class="material-symbols-outlined text-sm">tune</span>
                            <span id="edit-skills-lbl">Edit / Add Skills</span>
                            <span class="material-symbols-outlined text-sm" id="edit-skills-chevron">expand_more</span>
                        </button>

                        <!-- Collapsible full bubble picker -->
                        <div class="collapsible-skills" id="collapsible-skills">
                            <div class="pt-3 pb-1 space-y-4 border-t border-slate-100 dark:border-slate-700">
                                <p class="text-xs text-slate-400 ml-1">Toggle skills you offer. Adding new skills will require re-verification.</p>

                        <?php else: ?>
                        <!-- No registered skills yet — show full picker right away -->
                        <p class="text-xs text-slate-400 ml-1">Select all services you offer</p>
                        <div id="collapsible-skills" class="collapsible-skills open">
                        <div class="space-y-4">
                        <?php endif; ?>

                        <!-- ── THE BUBBLE PICKER (shared by both paths) ─────────── -->
                        <input type="hidden" name="service_category" id="service_category_input" value="<?php echo htmlspecialchars($data['service_category']); ?>">

                        <?php
                        $cat_groups = [
                            ['Construction & Trades', 'build', [['Electrical','⚡'],['Plumbing','💧'],['Carpentry','🔨'],['Masonry','🧱'],['Welding','🔥'],['Construction','🏗️']]],
                            ['Automotive', 'directions_car', [['Automotive','🚘'],['Motorcycle','🏍️']]],
                            ['Technology & Electronics', 'computer', [['Electronics','📱'],['Aircon_Ref','❄️'],['Computer_Tech','💻']]],
                            ['Domestic & Personal', 'home', [['Domestic_Work','🧹'],['Caregiving','🤱'],['Massage','💆'],['Beauty_Care','💇']]],
                            ['Culinary & Creative', 'palette', [['Cookery','👨‍🍳'],['Baking','🥖'],['Graphic_Design','🎨'],['Photography','📸'],['Videography','🎥'],['Music','🎵'],['Arts_Crafts','🖌️'],['Others','🔹']]],
                        ];
                        foreach ($cat_groups as [$grp_name, $grp_icon, $items]):
                        ?>
                        <div>
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 dark:bg-slate-700 rounded-full mb-2">
                                <span class="material-symbols-outlined text-primary text-xs"><?php echo $grp_icon; ?></span>
                                <span class="text-xs font-extrabold uppercase tracking-wider text-slate-500 dark:text-slate-400"><?php echo $grp_name; ?></span>
                            </div>
                            <div class="grid grid-cols-4 sm:grid-cols-6 gap-2">
                                <?php foreach ($items as [$v, $icon]):
                                    // Match against selected_cats (handles spaces vs underscores)
                                    $vNorm  = str_replace('_', ' ', $v);
                                    $isOn   = in_array($v, $selected_cats) || in_array($vNorm, $selected_cats);
                                    $isVerif= isset($skill_map[$v]) || isset($skill_map[$vNorm]);
                                    $onCls  = $isOn ? ' on' : '';
                                ?>
                                <div class="skill-bubble<?php echo $onCls; ?> relative" data-value="<?php echo $v; ?>">
                                    <span class="bubble-icon"><?php echo $icon; ?></span>
                                    <?php echo $v; ?>
                                    <?php if ($isVerif): 
                                        $sk_chk = $skill_map[$v] ?? $skill_map[$vNorm];
                                        $bv = $sk_chk['is_verified'] ? $sk_chk['badge_level'] : 'Pending';
                                        $dot_col = match($bv) { 'Gold'=>'bg-yellow-400','Silver'=>'bg-slate-400','Bronze'=>'bg-amber-500','Community'=>'bg-sky-400',default=>'bg-slate-300' };
                                    ?>
                                    <div class="absolute -top-1 -right-1 w-3 h-3 <?php echo $dot_col; ?> rounded-full border-2 border-white dark:border-slate-800 shadow-sm" title="<?php echo $bv; ?>"></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if (!empty($worker_skills)): ?>
                            </div><!-- end pt-3 inner -->
                        </div><!-- end collapsible-skills -->
                        <?php else: ?>
                        </div><!-- end space-y-4 inner -->
                        </div><!-- end collapsible-skills open -->
                        <?php endif; ?>

                    </div><!-- end service categories wrapper -->

                    <!-- MINIMUM RATE ────────────────────────────────────── -->
                    <div class="space-y-3">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Minimum Standard Rate</label>
                        <div class="text-center mb-3">
                            <div class="inline-flex items-baseline gap-1">
                                <span class="text-xl font-bold text-slate-400">₱</span>
                                <span id="rate-display" class="text-5xl font-extrabold tabular-nums text-primary transition-all"><?php echo number_format($data['minimum_standard_rate'] ?: 300); ?></span>
                                <span class="text-slate-400 text-sm font-semibold">/job</span>
                            </div>
                        </div>
                        <input type="range" name="minimum_standard_rate_slider" id="rate-slider" class="rate-slider w-full"
                               min="100" max="5000" step="50" value="<?php echo min($data['minimum_standard_rate'] ?: 300, 5000); ?>"
                               oninput="syncRate(this.value,'slider')"
                               style="--pct:<?php echo (((min($data['minimum_standard_rate'] ?: 300, 5000)-100)/(5000-100))*100); ?>%">
                        <div class="flex justify-between text-xs font-semibold text-slate-400"><span>₱100</span><span>₱2,500</span><span>₱5,000</span></div>
                        <input type="number" name="minimum_standard_rate" id="rate-input"
                               class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-base font-bold transition-all mt-2"
                               value="<?php echo $data['minimum_standard_rate'] ?: 300; ?>" min="100" max="999999"
                               oninput="syncRate(this.value,'input')" placeholder="Or type exact amount">
                        <div class="flex flex-wrap gap-2 mt-1">
                            <?php foreach([150,200,300,500,750,1000,1500,2000,3000,5000] as $r): ?>
                            <button type="button" onclick="syncRate(<?php echo $r; ?>,'preset')"
                                    class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-900 border-2 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold hover:border-primary hover:text-primary transition-all">
                                ₱<?php echo number_format($r); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- BIO ─────────────────────────────────────────────── -->
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Bio / Description</label>
                        <textarea name="bio" rows="4" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium transition-all resize-none"><?php echo htmlspecialchars($data['bio']); ?></textarea>
                    </div>
                </div>
            </section>
            <div class="flex justify-end pt-3">
                <button type="button" onclick="checkPhoneChange()" class="vibrant-gradient text-white px-8 py-3 rounded-xl font-bold flex items-center gap-2 hover:scale-[1.02] active:scale-95 transition-all shadow-lg shadow-primary/25 text-sm w-full md:w-auto justify-center">
                    <span class="material-symbols-outlined text-lg">save</span>Save Changes
                </button>
            </div>
        </div>

    </form>

    <!-- ══ TAB: Portfolio ════════════════════════════════════════════════ -->
    <div class="tab-panel" id="panel-portfolio">
        <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp">
            <div class="p-4 md:p-6">
                <p class="text-slate-500 dark:text-slate-400 text-sm mb-4">Upload photos of your past work to attract more clients.</p>
                <div class="grid grid-cols-3 md:grid-cols-5 gap-3">
                    <div onclick="document.getElementById('port-input').click()" class="aspect-square rounded-xl border-2 border-dashed border-primary/30 hover:border-primary hover:bg-primary/5 transition-all cursor-pointer flex flex-col items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-2xl text-primary/70">add_photo_alternate</span>
                        <span class="text-xs font-medium text-center text-slate-500">Add Photo</span>
                    </div>
                    <?php while($img = $portfolio->fetch_assoc()): ?>
                    <div class="relative aspect-square rounded-xl overflow-hidden group border border-slate-200 dark:border-slate-700">
                        <img src="../uploads/portfolios/<?php echo $img['image_path']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300" alt="Portfolio">
                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <a href="profile_edit.php?delete_portfolio=<?php echo $img['id']; ?>" onclick="return confirm('Delete this photo?')" class="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center text-white hover:bg-red-600 transition-colors">
                                <span class="material-symbols-outlined text-base">delete</span>
                            </a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- ══ TAB: Reviews ══════════════════════════════════════════════════ -->
    <div class="tab-panel" id="panel-reviews">
        <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp">
            <div class="p-4 md:p-6">
                <?php if($reviews_featured->num_rows > 0): ?>
                <div class="space-y-3">
                    <?php while($rev = $reviews_featured->fetch_assoc()): ?>
                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-xl p-4 border border-slate-200 dark:border-slate-800">
                        <div class="flex items-start justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary text-xs font-bold"><?php echo getInitials($rev['full_name']); ?></div>
                                <h4 class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($rev['full_name']); ?></h4>
                            </div>
                            <div class="flex gap-0.5 text-amber-400">
                                <?php for($i=0; $i<5; $i++): ?><span class="material-symbols-outlined text-xs <?php echo $i<$rev['rating']?'':'text-slate-300 dark:text-slate-600'; ?>">star</span><?php endfor; ?>
                            </div>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 italic text-sm">"<?php echo htmlspecialchars($rev['comment']); ?>"</p>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php if($reviews_all->num_rows > 3): ?>
                <div class="pt-4 text-center">
                    <button class="text-primary text-sm font-bold flex items-center gap-1 mx-auto hover:underline" onclick="document.getElementById('reviews-modal').style.display='flex'">
                        See All Reviews <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </button>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-center py-8">
                    <div class="w-14 h-14 mx-auto mb-3 bg-primary/10 rounded-full flex items-center justify-center"><span class="material-symbols-outlined text-3xl text-primary">star_rate</span></div>
                    <h3 class="text-base font-bold mb-1 text-slate-900 dark:text-white">No reviews yet</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm">Complete jobs to earn reviews from clients</p>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <form method="POST" enctype="multipart/form-data" id="port-form">
        <input type="file" name="portfolio_imgs[]" id="port-input" multiple style="display:none;" onchange="document.getElementById('port-form').submit()" accept="image/*">
    </form>
</main>

<!-- Mobile Save Bar -->
<div id="mobile-save-bar" class="fixed bottom-0 left-0 right-0 p-3 bg-white/90 dark:bg-slate-900/90 backdrop-blur-xl border-t border-slate-200 dark:border-slate-700 md:hidden z-40">
    <button type="button" onclick="checkPhoneChange()" class="w-full vibrant-gradient text-white py-3.5 rounded-xl font-bold flex items-center justify-center gap-2 shadow-lg shadow-primary/20 active:scale-95 transition-all text-sm">
        <span class="material-symbols-outlined text-lg">save</span>Save Changes
    </button>
</div>

<!-- OTP Modal -->
<?php if($otp_modal_open): ?>
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8 animate-slideUp">
        <div class="text-center">
            <div class="w-20 h-20 mx-auto mb-4 bg-primary/10 rounded-full flex items-center justify-center">
                <span class="material-symbols-outlined text-4xl text-primary">sms</span>
            </div>
            <h3 class="text-2xl font-bold mb-2 text-slate-900 dark:text-white">Verify Your Number</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-2">We sent a code to</p>
            <p class="text-lg font-bold mb-6"><?php echo $_SESSION['temp_new_phone'] ?? '...'; ?></p>
            <?php if(isset($_SESSION['temp_otp'])): ?>
            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 mb-6">
                <p class="text-amber-600 dark:text-amber-400 text-sm"><span class="material-symbols-outlined text-sm align-middle">code</span> Dev Code: <strong class="text-lg"><?php echo $_SESSION['temp_otp']; ?></strong></p>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="text" name="otp_code" class="w-full text-center text-2xl tracking-[0.5em] bg-slate-50 dark:bg-slate-900 border-2 border-primary/20 focus:border-primary rounded-xl py-4 px-5 mb-6" placeholder="000000" maxlength="6" autofocus required>
                <div class="space-y-3">
                    <button type="submit" name="verify_otp" class="w-full vibrant-gradient text-white py-4 rounded-xl font-bold transition-all shadow-lg shadow-primary/25">Verify & Continue</button>
                    <a href="profile_edit.php" class="block w-full text-center text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 py-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- All Reviews Modal -->
<div id="reviews-modal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden p-4">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-2xl w-full p-8 max-h-[80vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-slate-900 dark:text-white">All Client Reviews</h3>
            <button onclick="document.getElementById('reviews-modal').classList.add('hidden')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full transition-colors"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="space-y-4">
            <?php $reviews_all->data_seek(0); while($rev_all = $reviews_all->fetch_assoc()): ?>
            <div class="bg-slate-50 dark:bg-slate-900/50 rounded-2xl p-6 border border-slate-200 dark:border-slate-800">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold"><?php echo getInitials($rev_all['full_name']); ?></div>
                        <h4 class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($rev_all['full_name']); ?></h4>
                    </div>
                    <div class="flex gap-0.5 text-amber-400">
                        <?php for($i=0; $i<5; $i++): ?><span class="material-symbols-outlined text-sm <?php echo $i<$rev_all['rating']?'':'text-slate-300 dark:text-slate-600'; ?>">star</span><?php endfor; ?>
                    </div>
                </div>
                <p class="text-slate-600 dark:text-slate-400 italic">"<?php echo htmlspecialchars($rev_all['comment']); ?>"</p>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── MAP ───────────────────────────────────────────────────────────────────────
let map, marker;
function initMap() {
    const lat = <?php echo $lat; ?>, lng = <?php echo $lng; ?>;
    map = L.map('map', { zoomControl: false, attributionControl: false }).setView([lat, lng], 15);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { subdomains: 'abcd', maxZoom: 19, minZoom: 10 }).addTo(map);

    // CHANGE 3: Red marker icon, slightly larger (56px vs original 48px)
    const pulsingIcon = L.divIcon({
        className: 'custom-marker',
        html: '<div style="position:relative;"><span class="material-symbols-outlined" style="font-size:64px;color:#ef4444;filter:drop-shadow(0 3px 6px rgba(239,68,68,0.55));line-height:1;">location_on</span><div style="position:absolute;top:8px;left:8px;width:36px;height:36px;background:rgba(239,68,68,0.2);border-radius:50%;animation:pulse 2s infinite;"></div></div>',
        iconSize: [56, 56], iconAnchor: [28, 56]
    });

    marker = L.marker([lat, lng], { draggable: true, icon: pulsingIcon }).addTo(map);
    marker.on('dragend', function() {
        const p = marker.getLatLng();
        document.getElementById('lat').value = p.lat.toFixed(6);
        document.getElementById('lng').value = p.lng.toFixed(6);
        if (navigator.vibrate) navigator.vibrate(10);
    });
    setTimeout(() => map.invalidateSize(), 100);
}
function zoomIn()  { if (map) map.zoomIn(); }
function zoomOut() { if (map) map.zoomOut(); }
document.getElementById('resetLocation')?.addEventListener('click', function() {
    const oLat = <?php echo $lat; ?>, oLng = <?php echo $lng; ?>;
    marker.setLatLng([oLat, oLng]); map.setView([oLat, oLng], 15);
    document.getElementById('lat').value = oLat.toFixed(6);
    document.getElementById('lng').value = oLng.toFixed(6);
});

// ── TABS ──────────────────────────────────────────────────────────────────────
function switchTab(name) {
    ['personal','professional','portfolio','reviews'].forEach(t => {
        document.getElementById('tab-'+t).classList.toggle('active', t===name);
        document.getElementById('panel-'+t).classList.toggle('active', t===name);
    });
    const saveBar = document.getElementById('mobile-save-bar');
    if (saveBar) saveBar.style.display = (name==='personal'||name==='professional') ? 'block' : 'none';
    if (name==='personal' && map) setTimeout(() => map.invalidateSize(), 100);
}

// ── EDIT SKILLS TOGGLE ────────────────────────────────────────────────────────
function toggleEditSkills(btn) {
    const col = document.getElementById('collapsible-skills');
    const lbl = document.getElementById('edit-skills-lbl');
    const chv = document.getElementById('edit-skills-chevron');
    const open = col.classList.toggle('open');
    lbl.textContent = open ? 'Collapse' : 'Edit / Add Skills';
    chv.textContent = open ? 'expand_less' : 'expand_more';
    btn.style.borderColor = open ? 'rgba(14,165,233,0.5)' : '';
    btn.style.background  = open ? 'rgba(14,165,233,0.1)' : '';
}

// ── SKILL BUBBLES ─────────────────────────────────────────────────────────────
let selectedSkills = [];
const catInput = document.getElementById('service_category_input');
if (catInput && catInput.value) selectedSkills = catInput.value.split(',').map(s=>s.trim()).filter(Boolean);
document.querySelectorAll('.skill-bubble').forEach(bubble => {
    bubble.addEventListener('click', () => {
        const v = bubble.dataset.value;
        bubble.classList.toggle('on');
        if (bubble.classList.contains('on')) {
            if (!selectedSkills.includes(v)) selectedSkills.push(v);
        } else {
            selectedSkills = selectedSkills.filter(s => s !== v);
        }
        if (catInput) catInput.value = selectedSkills.join(',');
    });
});

// ── RATE SLIDER ───────────────────────────────────────────────────────────────
function syncRate(val, from) {
    const v = Math.max(100, Math.min(999999, parseInt(val)||100));
    const disp = document.getElementById('rate-display');
    const slider = document.getElementById('rate-slider');
    const input  = document.getElementById('rate-input');
    if (disp)   disp.textContent = v.toLocaleString();
    if (from!=='input'  && input)  input.value = v;
    if (from!=='slider' && slider && v<=5000) slider.value = v;
    if (slider) {
        const pct = ((Math.min(v,5000)-100)/(5000-100)*100).toFixed(2)+'%';
        slider.style.setProperty('--pct', pct);
    }
}
const rateSlider = document.getElementById('rate-slider');
if (rateSlider) syncRate(rateSlider.value, 'init');

function checkPhoneChange() { document.getElementById('main-form').submit(); }

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(initMap, 300);
    setTimeout(() => {
        document.querySelectorAll('.bg-accent-green, .bg-accent-red').forEach(el => el?.remove());
    }, 5000);
});
</script>
</body>
</html>