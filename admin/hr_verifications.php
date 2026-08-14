<?php
// admin/hr_verifications.php
include '../db.php';
include '../includes/init_lang.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}

$hr_user_id = $_SESSION['user_id'];
$hr_name    = $conn->query("SELECT full_name FROM users WHERE id=$hr_user_id")->fetch_assoc()['full_name'] ?? 'HR';

// ── Decryption (same key as admin verifications) ─────────────
define('ADMIN_ENC_KEY', hex2bin(
    getenv('VERIFICATION_ENC_KEY') ?: 'a3f5c8d1e2b4f6a8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6'
));
function decrypt_field(?string $packed): string {
    if ($packed === null || $packed === '') return '-';
    $raw = base64_decode($packed, true);
    if ($raw === false || strlen($raw) < 28) return '[decrypt error]';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', ADMIN_ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return ($pt === false) ? '[decrypt error]' : $pt;
}

const BADGE_RANK = ['Unverified'=>0,'Community'=>1,'Bronze'=>2,'Silver'=>3,'Gold'=>4];

// ── Handle approval / rejection (same logic as admin) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_verification'])) {
    $verification_id = (int)$_POST['verification_id'];
    $worker_id       = (int)$_POST['worker_id'];
    $action          = $_POST['action'];
    $admin_notes     = $conn->real_escape_string($_POST['admin_notes'] ?? '');
    $community_only  = !empty($_POST['community_only']);
    $skill_actions   = $_POST['skill_action'] ?? [];
    $skill_badges    = $_POST['skill_badge']  ?? [];
    $skill_notes     = $_POST['skill_notes']  ?? [];
    $highest_badge   = 'Unverified';

    if ($action === 'Approved') {
        if ($community_only) {
            $conn->query("UPDATE verification SET verification_status='verified', verified_at=NOW(),
                          id_verification_notes='$admin_notes', id_verified=1 WHERE id=$verification_id");
            $conn->query("UPDATE worker_profiles SET verification_status='Community', is_verified=1 WHERE user_id=$worker_id");
            $highest_badge = 'Community';
            $msg = "🎉 Your profile has been verified as Community by HR.";
        } else {
            foreach ($skill_actions as $nc_id => $decision) {
                $nc_id = (int)$nc_id;
                $badge = $conn->real_escape_string($skill_badges[$nc_id] ?? 'Unverified');
                $snote = $conn->real_escape_string($skill_notes[$nc_id] ?? '');
                if ($decision === 'approve') {
                    $conn->query("UPDATE verification_nc_skills SET is_verified=1, verified_at=NOW(),
                                  badge_level='$badge', verification_notes='$snote'
                                  WHERE id=$nc_id AND verification_id=$verification_id");
                    $conn->query("UPDATE worker_skills ws JOIN verification_nc_skills vns ON vns.id=$nc_id
                                  SET ws.badge_level='$badge', ws.is_verified=1, ws.verified_at=NOW(), ws.verification_notes='$snote'
                                  WHERE ws.worker_id=$worker_id AND ws.sub_category=vns.sub_category");
                    if ((BADGE_RANK[$badge]??0) > (BADGE_RANK[$highest_badge]??0)) $highest_badge = $badge;
                } else {
                    $conn->query("UPDATE verification_nc_skills SET is_verified=0, badge_level='Unverified', verification_notes='$snote'
                                  WHERE id=$nc_id AND verification_id=$verification_id");
                }
            }
            $conn->query("UPDATE verification SET verification_status='verified', verified_at=NOW(),
                          id_verification_notes='$admin_notes', id_verified=1 WHERE id=$verification_id");
            $conn->query("UPDATE worker_profiles SET verification_status='$highest_badge', is_verified=1 WHERE user_id=$worker_id");
            $label = ($highest_badge !== 'Unverified') ? "as $highest_badge" : "(no NC approved)";
            $msg   = "🎉 Your profile has been verified $label by HR.";
        }
    } else {
        $conn->query("UPDATE verification SET verification_status='rejected',
                      rejection_reason='$admin_notes', id_verification_notes='$admin_notes'
                      WHERE id=$verification_id");
        $msg = "⚠️ Your verification was rejected by HR. Reason: $admin_notes";
    }

    $safe_msg = $conn->real_escape_string($msg);
    $conn->query("INSERT INTO notifications (user_id,message,link) VALUES ('$worker_id','$safe_msg','../worker/profile_edit.php')");
    header("Location: hr_verifications.php?status=success"); exit();
}

// ── Fetch pending verifications ───────────────────────────────
$pending_count = $conn->query("SELECT COUNT(*) as c FROM verification WHERE verification_status='pending'")->fetch_assoc()['c'] ?? 0;

$reqs = $conn->query("SELECT v.*, u.id AS worker_id, u.full_name, u.email, u.profile_pic,
    u.created_at, wp.service_category, wp.verification_status AS current_badge,
    wp.is_verified, wp.jobs_completed
    FROM verification v
    JOIN users u ON v.user_id = u.id
    LEFT JOIN worker_profiles wp ON u.id = wp.user_id
    WHERE v.verification_status = 'pending'
    ORDER BY v.submitted_at DESC");

// Pre-fetch NC skills for pending verifications
$pending_nc = [];
$nc_res = $conn->query("SELECT vns.* FROM verification_nc_skills vns
    JOIN verification v ON v.id = vns.verification_id
    WHERE v.verification_status = 'pending'
    ORDER BY vns.verification_id, vns.sub_category");
while ($nr = $nc_res->fetch_assoc()) $pending_nc[$nr['verification_id']][] = $nr;

// History
$history = $conn->query("SELECT v.*, u.full_name, u.profile_pic, wp.verification_status AS assigned_badge
    FROM verification v JOIN users u ON v.user_id=u.id
    LEFT JOIN worker_profiles wp ON u.id=wp.user_id
    WHERE v.verification_status IN ('verified','rejected')
    ORDER BY v.updated_at DESC LIMIT 10");

$current_date = date('M d, Y');

function getUserAvatar($pic,$name){
    if(!empty($pic)&&file_exists("../uploads/profiles/".$pic))return"../uploads/profiles/".$pic;
    return"https://ui-avatars.com/api/?name=".urlencode($name)."&background=8B5CF6&color=fff&size=128&bold=true";
}
function formatIdType($type){
    return ['National ID'=>'National ID','ePhilID'=>'ePhilID','Drivers License'=>"Driver's License",
        'UMID'=>'UMID','PRC'=>'PRC License','SSS ID'=>'SSS ID','Student ID'=>'Student ID'][$type]??$type;
}
function formatDate($date){if(empty($date))return'-';return date('M d, Y',strtotime($date));}
function getStatusClass($s){
    return match($s){
        'verified'  =>'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800',
        'rejected'  =>'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border-red-200 dark:border-red-800',
        default     =>'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800',
    };
}
$SUB_EMOJI=['Electrical'=>'⚡','Plumbing'=>'💧','Carpentry'=>'🔨','Masonry'=>'🧱','Welding'=>'🔥',
    'Construction'=>'🏗️','Automotive'=>'🚘','Motorcycle'=>'🏍️','Electronics'=>'📱',
    'Aircon Ref'=>'❄️','Computer Tech'=>'💻','Domestic Work'=>'🧹','Caregiving'=>'🤱',
    'Massage'=>'💆','Beauty Care'=>'💇','Cookery'=>'👨‍🍳','Baking'=>'🥖',
    'Graphic_Design'=>'🎨','Photography'=>'📸','Videography'=>'🎥','Music'=>'🎵',
    'Arts Crafts'=>'🖌️','Others'=>'🔹'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto HR — Worker Verifications</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{hr:"#8B5CF6",equity:"#10B981",danger:"#F43F5E"},fontFamily:{display:["Syne","sans-serif"],body:["DM Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
    function toggleMobileMenu(){document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');document.getElementById('menu-overlay').classList.toggle('hidden');}
</script>
<style>
    body{font-family:'DM Sans',sans-serif;background:#F5F3FF;} .dark body{background:#0B0F1A;}
    h1,h2,h3,.font-display{font-family:'Syne',sans-serif;}
    .glass-card{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.6);box-shadow:0 4px 24px rgba(0,0,0,0.06);}
    .dark .glass-card{background:rgba(17,24,39,0.75);border:1px solid rgba(255,255,255,0.07);}
    .stat-card-shadow{box-shadow:0 10px 25px -5px rgba(0,0,0,.04),0 8px 10px -6px rgba(0,0,0,.04);}
    /* Side panel */
    .glass-panel{background:rgba(255,255,255,0.98);backdrop-filter:blur(20px);border-left:1px solid rgba(255,255,255,0.4);}
    .dark .glass-panel{background:rgba(10,14,26,0.98);border-left:1px solid rgba(255,255,255,0.08);}
    /* NC skill cards */
    .nc-skill-card{transition:border-color .2s,box-shadow .2s,opacity .2s;}
    .nc-skill-card.state-gold  {border-color:#F59E0B!important;box-shadow:0 0 0 3px rgba(245,158,11,.15);}
    .nc-skill-card.state-silver{border-color:#94A3B8!important;box-shadow:0 0 0 3px rgba(148,163,184,.15);}
    .nc-skill-card.state-bronze{border-color:#B45309!important;box-shadow:0 0 0 3px rgba(180,83,9,.15);}
    .nc-skill-card.state-reject{border-color:#EF4444!important;box-shadow:0 0 0 3px rgba(239,68,68,.08);opacity:.5;}
    .badge-btn{display:flex;flex:1;align-items:center;justify-content:center;gap:6px;padding:8px;border-radius:12px;font-size:11px;font-weight:700;transition:all .15s;border:1.5px solid;}
    .badge-btn.inactive{background:white;color:#64748b;border-color:#e2e8f0;}
    .dark .badge-btn.inactive{background:#1e293b;border-color:#334155;color:#94a3b8;}
    .badge-btn.active-gold  {background:#FEF3C7;color:#B45309;border-color:#F59E0B;box-shadow:0 0 0 2px rgba(245,158,11,.2);}
    .badge-btn.active-silver{background:#F1F5F9;color:#475569;border-color:#94A3B8;box-shadow:0 0 0 2px rgba(148,163,184,.2);}
    .badge-btn.active-bronze{background:#FEF3C7;color:#92400E;border-color:#D97706;box-shadow:0 0 0 2px rgba(217,119,6,.2);}
    .badge-btn.active-reject{background:#FEF2F2;color:#DC2626;border-color:#FCA5A5;box-shadow:0 0 0 2px rgba(239,68,68,.15);}
    .fin-table thead th{font-family:'Syne',sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;padding:10px 16px;border-bottom:1px solid rgba(0,0,0,0.06);}
    .dark .fin-table thead th{border-color:rgba(255,255,255,0.06);}
    .fin-table tbody td{padding:12px 16px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.04);}
    .dark .fin-table tbody td{border-color:rgba(255,255,255,0.04);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    @keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    .anim-in{animation:fadeUp .4s ease both;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-4 md:p-8 relative">

    <!-- Header -->
    <header class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
        <div class="flex items-center gap-4">
            <button class="lg:hidden p-2 hover:bg-white rounded-lg text-slate-500 shadow-sm border border-slate-200" onclick="toggleMobileMenu()"><span class="material-icons-round">menu</span></button>
            <div class="flex items-center gap-3">
                <h1 class="font-display text-2xl font-bold">Worker Verifications</h1>
                <span class="px-2.5 py-0.5 bg-hr/10 text-hr text-xs font-bold rounded-full border border-hr/20"><?php echo $pending_count; ?> Pending</span>
            </div>
        </div>
        <div class="flex items-center gap-3 self-end sm:self-auto">
            <button class="p-2.5 glass-card border border-slate-200 dark:border-slate-700 rounded-xl text-slate-500 hover:shadow-md transition-all" onclick="toggleDarkMode()">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
            <div class="hidden sm:block text-right">
                <p class="text-xs font-bold text-slate-800 dark:text-white"><?php echo $current_date; ?></p>
                <p class="text-[10px] text-slate-400 uppercase tracking-wider">HR · Verification Queue</p>
            </div>
        </div>
    </header>

    <!-- ── PENDING REVIEWS ──────────────────────────────────── -->
    <?php if ($reqs && $reqs->num_rows > 0): ?>
    <h2 class="font-display text-lg font-bold mb-4 flex items-center gap-2">
        <span class="material-icons-round text-hr">pending_actions</span>Pending Reviews
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-12">
    <?php while ($row = $reqs->fetch_assoc()):
        $avatar         = getUserAvatar($row['profile_pic'], $row['full_name']);
        $worker_code    = "#W-".str_pad($row['worker_id'], 4, '0', STR_PAD_LEFT);
        $submitted_date = date("M d, Y", strtotime($row['submitted_at']));
        $id_display     = formatIdType($row['id_type']);
        $decrypted_id   = decrypt_field($row['id_number'] ?? null);
        $vid            = $row['id'];
        $nc_rows        = $pending_nc[$vid] ?? [];
        $nc_count       = count($nc_rows);
        $has_face       = !empty($row['face_photo']);

        $nc_payload = array_map(fn($nr) => [
            'id'       => (int)$nr['id'],
            'sub'      => $nr['sub_category'],
            'main'     => $nr['main_category'] ?? '',
            'nc_level' => $nr['nc_level'],
            'badge'    => $nr['badge_level'],
            'cert_num' => decrypt_field($nr['nc_certificate_number'] ?? null),
            'cert_img' => $nr['nc_certificate_image'] ?? '',
        ], $nc_rows);
    ?>
    <div class="glass-card p-6 rounded-2xl stat-card-shadow border border-slate-100 dark:border-slate-800 hover:border-hr/30 transition-all group anim-in"
         data-req-id="<?php echo $vid; ?>"
         data-worker-id="<?php echo $row['worker_id']; ?>"
         data-worker-name="<?php echo htmlspecialchars($row['full_name']); ?>"
         data-first-name="<?php echo htmlspecialchars($row['first_name']??''); ?>"
         data-middle-name="<?php echo htmlspecialchars($row['middle_name']??''); ?>"
         data-last-name="<?php echo htmlspecialchars($row['last_name']??''); ?>"
         data-age="<?php echo htmlspecialchars($row['age']??''); ?>"
         data-nationality="<?php echo htmlspecialchars($row['nationality']??''); ?>"
         data-civil-status="<?php echo htmlspecialchars($row['civil_status']??''); ?>"
         data-sex="<?php echo htmlspecialchars($row['sex']??''); ?>"
         data-dob="<?php echo $row['date_of_birth']?date('M d, Y',strtotime($row['date_of_birth'])):''; ?>"
         data-pob="<?php echo htmlspecialchars($row['place_of_birth']??''); ?>"
         data-perm-municipality="<?php echo htmlspecialchars($row['permanent_municipality']??''); ?>"
         data-perm-barangay="<?php echo htmlspecialchars($row['permanent_barangay']??''); ?>"
         data-perm-street="<?php echo htmlspecialchars($row['permanent_street']??''); ?>"
         data-perm-house="<?php echo htmlspecialchars($row['permanent_house_details']??''); ?>"
         data-current-municipality="<?php echo htmlspecialchars($row['current_municipality']??''); ?>"
         data-current-barangay="<?php echo htmlspecialchars($row['current_barangay']??''); ?>"
         data-current-street="<?php echo htmlspecialchars($row['current_street']??''); ?>"
         data-current-house="<?php echo htmlspecialchars($row['current_house_details']??''); ?>"
         data-zip-code="<?php echo htmlspecialchars($row['zip_code']??''); ?>"
         data-id-type="<?php echo htmlspecialchars($id_display); ?>"
         data-id-number="<?php echo htmlspecialchars($decrypted_id); ?>"
         data-id-photo-front="<?php echo htmlspecialchars($row['id_photo_front']??''); ?>"
         data-id-photo-back="<?php echo htmlspecialchars($row['id_photo_back']??''); ?>"
         data-face-photo="<?php echo htmlspecialchars($row['face_photo']??''); ?>"
         data-has-face-photo="<?php echo $has_face?'1':'0'; ?>"
         data-nc-skills='<?php echo htmlspecialchars(json_encode($nc_payload),ENT_QUOTES); ?>'>

        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <img src="<?php echo $avatar; ?>" class="w-14 h-14 rounded-xl object-cover" alt=""/>
                <div>
                    <h3 class="font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($row['full_name']); ?></h3>
                    <p class="text-xs text-slate-400">ID: <?php echo $worker_code; ?></p>
                    <?php if(!empty($row['service_category'])): ?>
                    <p class="text-[10px] text-hr mt-0.5"><?php echo htmlspecialchars($row['service_category']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <span class="px-2 py-1 bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 text-[9px] font-bold rounded-lg border border-amber-100 dark:border-amber-800 uppercase">Awaiting</span>
        </div>

        <div class="space-y-2.5 mb-5">
            <div class="flex justify-between py-2 border-b border-slate-50 dark:border-slate-800">
                <span class="text-xs text-slate-500 flex items-center gap-1.5"><span class="material-icons-round text-sm opacity-60">badge</span>ID Type</span>
                <span class="text-xs font-bold"><?php echo $id_display; ?></span>
            </div>
            <div class="flex justify-between py-2 border-b border-slate-50 dark:border-slate-800">
                <span class="text-xs text-slate-500 flex items-center gap-1.5"><span class="material-icons-round text-sm opacity-60">event</span>Submitted</span>
                <span class="text-xs font-bold"><?php echo $submitted_date; ?></span>
            </div>
            <?php if($has_face): ?>
            <div class="flex justify-between py-2 border-b border-slate-50 dark:border-slate-800">
                <span class="text-xs text-slate-500 flex items-center gap-1.5"><span class="material-icons-round text-sm opacity-60">face</span>Face Photo</span>
                <span class="text-xs font-bold text-equity">✓ Submitted</span>
            </div>
            <?php endif; ?>

            <?php if($nc_count > 0): ?>
            <div class="py-2">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">NC Certificates (<?php echo $nc_count; ?>)</p>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach($nc_rows as $nr):
                        $emoji=$SUB_EMOJI[$nr['sub_category']]??'🔹';
                        $bc=match($nr['badge_level']){'Gold'=>'bg-amber-50 dark:bg-amber-900/20 text-amber-600 border-amber-200','Silver'=>'bg-slate-100 dark:bg-slate-800 text-slate-600 border-slate-300','Bronze'=>'bg-orange-50 dark:bg-orange-900/20 text-orange-600 border-orange-200',default=>'bg-slate-50 dark:bg-slate-800 text-slate-400 border-slate-200'};
                    ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $bc; ?>">
                        <?php echo $emoji; ?> <?php echo htmlspecialchars($nr['sub_category']); ?> · <?php echo $nr['nc_level']; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="py-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold bg-purple-50 dark:bg-purple-900/20 text-purple-600 border border-purple-200">
                    🟣 ID Only — Community Badge
                </span>
            </div>
            <?php endif; ?>
        </div>

        <button onclick="toggleSidePanel(<?php echo $vid; ?>)"
                class="w-full flex items-center justify-center gap-2 py-2.5 bg-hr/10 hover:bg-hr/20 text-hr rounded-xl text-xs font-bold transition-all border border-hr/20">
            <span class="material-icons-round text-base">preview</span>
            Review &amp; Decide
        </button>
    </div>
    <?php endwhile; ?>
    </div>

    <?php else: ?>
    <div class="flex flex-col items-center justify-center min-h-[40vh] text-center mb-12">
        <div class="w-24 h-24 bg-hr/10 rounded-3xl flex items-center justify-center mb-6 border border-hr/20">
            <span class="material-icons-round text-5xl text-hr/40">assignment_turned_in</span>
        </div>
        <h2 class="font-display text-xl font-bold mb-2">No pending verifications</h2>
        <p class="text-sm text-slate-500">All worker documents have been processed.</p>
    </div>
    <?php endif; ?>

    <!-- ── HISTORY ──────────────────────────────────────────── -->
    <h2 class="font-display text-lg font-bold mb-4 flex items-center gap-2">
        <span class="material-icons-round text-hr">history</span>Recent History
    </h2>
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto slim-scroll">
            <table class="fin-table w-full">
                <thead>
                    <tr>
                        <?php foreach(['Worker','ID Type','Submitted','Processed','Status','Badge'] as $h): ?>
                        <th class="text-left px-6 py-4 text-xs font-bold text-slate-400 uppercase tracking-wider"><?php echo $h; ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800/50">
                <?php if($history && $history->num_rows > 0):
                    while($row=$history->fetch_assoc()):
                        $av=getUserAvatar($row['profile_pic'],$row['full_name']);
                        $sc=getStatusClass($row['verification_status']);
                        $bc=match($row['assigned_badge']??''){
                            'Gold'      =>'bg-amber-100 dark:bg-amber-900/30 text-amber-600',
                            'Silver'    =>'bg-slate-100 dark:bg-slate-800 text-slate-600',
                            'Bronze'    =>'bg-orange-100 dark:bg-orange-900/30 text-orange-600',
                            'Community' =>'bg-purple-100 dark:bg-purple-900/30 text-purple-600',
                            default     =>'bg-blue-100 dark:bg-blue-900/30 text-blue-600',
                        };
                ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-all">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="<?php echo $av; ?>" class="w-8 h-8 rounded-full object-cover flex-shrink-0" alt=""/>
                            <p class="text-sm font-semibold"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm"><?php echo formatIdType($row['id_type']); ?></td>
                    <td class="px-6 py-4 text-sm"><?php echo formatDate($row['submitted_at']); ?></td>
                    <td class="px-6 py-4 text-sm"><?php echo formatDate($row['verified_at']??$row['updated_at']); ?></td>
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase border <?php echo $sc; ?>"><?php echo $row['verification_status']; ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <?php if(!empty($row['assigned_badge'])&&$row['assigned_badge']!=='Unverified'): ?>
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?php echo $bc; ?>"><?php echo $row['assigned_badge']; ?></span>
                        <?php else: ?><span class="text-slate-400 text-xs">-</span><?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">No verification history yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ── OVERLAY + SIDE PANEL (identical to admin, HR-themed) ── -->
<div class="fixed inset-0 bg-black/30 z-[79] hidden" id="panel-overlay" onclick="toggleSidePanel()"></div>

<div class="fixed top-0 right-0 w-full md:w-[700px] h-full z-[80] glass-panel transition-transform duration-500 translate-x-full shadow-2xl flex flex-col overflow-hidden"
     id="quick-preview-panel">

    <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between flex-shrink-0">
        <div>
            <h2 class="font-display text-lg font-bold" id="preview-worker-name">Select a worker</h2>
            <p class="text-xs text-slate-400" id="preview-panel-subtitle">Review verification documents</p>
        </div>
        <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-full text-slate-500" onclick="toggleSidePanel()"><span class="material-icons-round">close</span></button>
    </div>

    <div class="flex-1 overflow-y-auto p-6 space-y-8 slim-scroll">
        <!-- Face Photo -->
        <div id="face-photo-section" class="hidden">
            <h4 class="text-sm font-bold mb-4 flex items-center gap-2"><span class="material-icons-round text-hr">face</span>Face Verification Selfie</h4>
            <div class="bg-slate-50 dark:bg-slate-800/50 rounded-2xl p-4 border border-slate-200 dark:border-slate-700">
                <div class="flex justify-center">
                    <img id="preview-face-photo" alt="Face Selfie" class="rounded-2xl max-h-72 object-contain border-2 border-hr/30 shadow-lg" src=""/>
                </div>
                <p class="text-center text-[10px] text-slate-400 mt-3">Submitted face photo for identity verification</p>
            </div>
        </div>

        <!-- ID Documents -->
        <div>
            <h4 class="text-sm font-bold mb-4 flex items-center gap-2"><span class="material-icons-round text-hr">badge</span>Identification Documents</h4>
            <div id="preview-id-front-container" class="mb-4 hidden">
                <div class="flex items-center justify-between mb-2"><span class="text-xs font-bold text-slate-400 uppercase tracking-wider">ID Front</span><span class="px-2 py-0.5 bg-violet-50 dark:bg-violet-900/30 text-violet-500 text-[10px] font-bold rounded">FRONT</span></div>
                <div class="aspect-[16/10] bg-slate-100 dark:bg-slate-800 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 overflow-hidden">
                    <img id="preview-id-front" alt="ID Front" class="w-full h-full object-contain" src=""/>
                </div>
            </div>
            <div id="preview-id-back-container" class="mb-4 hidden">
                <div class="flex items-center justify-between mb-2"><span class="text-xs font-bold text-slate-400 uppercase tracking-wider">ID Back</span><span class="px-2 py-0.5 bg-violet-50 dark:bg-violet-900/30 text-violet-500 text-[10px] font-bold rounded">BACK</span></div>
                <div class="aspect-[16/10] bg-slate-100 dark:bg-slate-800 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-700 overflow-hidden">
                    <img id="preview-id-back" alt="ID Back" class="w-full h-full object-contain" src=""/>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 mt-4 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">ID Type</label><p class="text-sm font-medium mt-1" id="preview-id-type">-</p></div>
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">ID Number</label><p class="text-sm font-medium mt-1 font-mono" id="preview-id-number">-</p></div>
            </div>
        </div>

        <!-- NC Certificates -->
        <div id="nc-skills-section" class="hidden">
            <h4 class="text-sm font-bold mb-1 flex items-center gap-2"><span class="material-icons-round text-hr">verified</span>NC Certificates</h4>
            <p class="text-[10px] text-slate-400 mb-4">Click <strong>Gold / Silver / Bronze</strong> to approve at that level, or <strong>Reject</strong> to deny.</p>
            <div id="nc-cards-container" class="space-y-4"></div>
        </div>

        <!-- Community notice -->
        <div id="community-notice" class="hidden p-5 bg-purple-50 dark:bg-purple-900/10 rounded-2xl border border-purple-200 dark:border-purple-800">
            <div class="flex items-start gap-3">
                <span class="material-icons-round text-purple-500 text-2xl mt-0.5">stars</span>
                <div>
                    <p class="font-bold text-purple-700 dark:text-purple-300 text-sm">ID-Only Submission</p>
                    <p class="text-xs text-purple-500 mt-1">No NC certificates submitted. Approving grants a <strong>Community</strong> badge — confirms identity but does not validate TESDA NC skills.</p>
                </div>
            </div>
        </div>

        <!-- Personal Info -->
        <div class="bg-slate-50 dark:bg-slate-800/50 p-5 rounded-2xl border border-slate-200 dark:border-slate-700">
            <h4 class="text-sm font-bold mb-4 flex items-center gap-2"><span class="material-icons-round text-hr">person</span>Personal Information</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach([['preview-first-name','First Name'],['preview-middle-name','Middle Name'],['preview-last-name','Last Name','md:col-span-2'],['preview-age','Age'],['preview-nationality','Nationality'],['preview-civil-status','Civil Status'],['preview-sex','Sex'],['preview-dob','Date of Birth'],['preview-pob','Place of Birth']] as $f): ?>
                <div class="<?php echo $f[2]??''; ?>">
                    <label class="text-[10px] font-bold text-slate-400 uppercase"><?php echo $f[1]; ?></label>
                    <p class="text-sm font-medium mt-1" id="<?php echo $f[0]; ?>">-</p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Address -->
        <div class="bg-slate-50 dark:bg-slate-800/50 p-5 rounded-2xl border border-slate-200 dark:border-slate-700">
            <h4 class="text-sm font-bold mb-4 flex items-center gap-2"><span class="material-icons-round text-hr">home</span>Permanent Address</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">Province</label><p class="text-sm font-medium mt-1">Surigao del Sur</p></div>
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">Municipality</label><p class="text-sm font-medium mt-1" id="preview-perm-municipality">-</p></div>
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">Barangay</label><p class="text-sm font-medium mt-1" id="preview-perm-barangay">-</p></div>
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">Street</label><p class="text-sm font-medium mt-1" id="preview-perm-street">-</p></div>
                <div><label class="text-[10px] font-bold text-slate-400 uppercase">House/Unit</label><p class="text-sm font-medium mt-1" id="preview-perm-house">-</p></div>
            </div>
        </div>
    </div>

    <!-- Panel Footer — Action Form -->
    <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex-shrink-0">
        <form method="POST" id="preview-action-form" class="space-y-4">
            <input type="hidden" name="process_verification" value="1">
            <input type="hidden" name="verification_id" id="preview-verification-id" value="">
            <input type="hidden" name="worker_id"       id="preview-worker-id"       value="">
            <div id="skill-hidden-inputs"></div>

            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">KYC Notes</label>
                <textarea name="admin_notes" rows="2"
                          class="w-full px-4 py-2 text-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-hr focus:border-hr outline-none resize-none"
                          placeholder="Notes about the ID / KYC review…"></textarea>
            </div>

            <!-- Community path -->
            <div id="action-community" class="hidden flex gap-3">
                <button type="submit" name="action" value="Approved"
                        class="flex-1 flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-purple-500 to-hr text-white rounded-xl text-xs font-bold hover:scale-105 transition-all shadow-md">
                    <span class="material-icons-round text-lg">stars</span>Approve as Community
                </button>
                <button type="submit" name="action" value="Rejected"
                        class="flex-1 flex items-center justify-center gap-2 py-3 bg-red-50 dark:bg-red-900/10 text-red-500 hover:bg-red-100 rounded-xl text-xs font-bold transition-all border border-red-200 dark:border-red-800">
                    <span class="material-icons-round text-lg">block</span>Reject
                </button>
            </div>

            <!-- NC certs path -->
            <div id="action-nc" class="hidden">
                <p class="text-[10px] text-slate-400 mb-3" id="nc-decision-summary">Set badge per skill above, then approve.</p>
                <div class="flex gap-3">
                    <button type="submit" name="action" value="Approved"
                            class="flex-1 flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-equity to-emerald-600 text-white rounded-xl text-xs font-bold hover:scale-105 transition-all shadow-md">
                        <span class="material-icons-round text-lg">check_circle</span>Approve Selected
                    </button>
                    <button type="submit" name="action" value="Rejected"
                            class="flex-1 flex items-center justify-center gap-2 py-3 bg-red-50 dark:bg-red-900/10 text-red-500 hover:bg-red-100 rounded-xl text-xs font-bold transition-all border border-red-200 dark:border-red-800">
                        <span class="material-icons-round text-lg">block</span>Reject All
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if(isset($_GET['status'])&&$_GET['status']==='success'): ?>
<div class="fixed bottom-24 right-6 bg-emerald-100 dark:bg-emerald-900/30 border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 px-6 py-3 rounded-xl shadow-lg flex items-center gap-3 z-50" id="success-toast">
    <span class="material-icons-round text-emerald-600">check_circle</span>
    <span class="font-medium">Verification processed successfully.</span>
</div>
<script>setTimeout(()=>document.getElementById('success-toast')?.remove(),3000);</script>
<?php endif; ?>

<script>
const SUB_EMOJI = <?php echo json_encode($SUB_EMOJI); ?>;
let skillState={}, currentSkills=[], isCommunityOnly=false;

function toggleSidePanel(reqId=null){
    const panel=document.getElementById('quick-preview-panel');
    const overlay=document.getElementById('panel-overlay');
    const isOpen=!panel.classList.contains('translate-x-full');
    if(reqId&&isOpen&&document.getElementById('preview-verification-id').value==reqId){
        panel.classList.add('translate-x-full');overlay.classList.add('hidden');return;
    }
    if(reqId){loadWorkerData(reqId);panel.classList.remove('translate-x-full');overlay.classList.remove('hidden');}
    else{panel.classList.add('translate-x-full');overlay.classList.add('hidden');}
}

function loadWorkerData(reqId){
    const card=document.querySelector(`[data-req-id="${reqId}"]`);if(!card)return;
    const d=card.dataset;
    document.getElementById('preview-verification-id').value=reqId;
    document.getElementById('preview-worker-id').value=d.workerId;
    document.getElementById('preview-worker-name').textContent=d.workerName;

    const fieldMap={'preview-first-name':d.firstName,'preview-middle-name':d.middleName,'preview-last-name':d.lastName,
        'preview-age':d.age,'preview-nationality':d.nationality,'preview-civil-status':d.civilStatus,
        'preview-sex':d.sex,'preview-dob':d.dob,'preview-pob':d.pob,
        'preview-perm-municipality':d.permMunicipality,'preview-perm-barangay':d.permBarangay,
        'preview-perm-street':d.permStreet,'preview-perm-house':d.permHouse,
        'preview-id-type':d.idType,'preview-id-number':d.idNumber};
    Object.entries(fieldMap).forEach(([id,val])=>{const el=document.getElementById(id);if(el)el.textContent=val||'-';});

    const imgBase='../admin/serve_verification_image.php?path=';
    ['front','back'].forEach(side=>{
        const key=side==='front'?'idPhotoFront':'idPhotoBack';
        const wrap=document.getElementById(`preview-id-${side}-container`);
        const img=document.getElementById(`preview-id-${side}`);
        if(d[key]){img.src=imgBase+encodeURIComponent(d[key]);wrap.classList.remove('hidden');}
        else{wrap.classList.add('hidden');}
    });

    const faceSection=document.getElementById('face-photo-section');
    const faceImg=document.getElementById('preview-face-photo');
    if(d.hasFacePhoto==='1'&&d.facePhoto){faceImg.src=imgBase+encodeURIComponent(d.facePhoto);faceSection.classList.remove('hidden');}
    else{faceSection.classList.add('hidden');}

    currentSkills=JSON.parse(d.ncSkills||'[]');
    skillState={};
    isCommunityOnly=currentSkills.length===0;
    renderNcSection();
}

function renderNcSection(){
    const ncSection=document.getElementById('nc-skills-section');
    const commNotice=document.getElementById('community-notice');
    const actComm=document.getElementById('action-community');
    const actNc=document.getElementById('action-nc');
    const subtitle=document.getElementById('preview-panel-subtitle');
    const hiddenArea=document.getElementById('skill-hidden-inputs');
    hiddenArea.innerHTML='';

    if(isCommunityOnly){
        ncSection.classList.add('hidden');commNotice.classList.remove('hidden');
        actComm.classList.remove('hidden');actNc.classList.add('hidden');
        subtitle.textContent='ID only — Community badge on approval';
        hiddenArea.innerHTML='<input type="hidden" name="community_only" value="1">';
    } else {
        ncSection.classList.remove('hidden');commNotice.classList.add('hidden');
        actComm.classList.add('hidden');actNc.classList.remove('hidden');
        subtitle.textContent=`Review ${currentSkills.length} NC certificate${currentSkills.length>1?'s':''}`;
        renderNcCards();
    }
}

function renderNcCards(){
    const container=document.getElementById('nc-cards-container');
    container.innerHTML='';
    currentSkills.forEach(sk=>{
        const defaultBadge={'NC I':'Bronze','NC II':'Silver','NC III':'Gold'}[sk.nc_level]||'Gold';
        skillState[sk.id]={decision:'approve',badge:defaultBadge,notes:''};
        const emoji=SUB_EMOJI[sk.sub]||'🔹';
        const card=document.createElement('div');
        card.id=`nc-card-${sk.id}`;
        card.className=`nc-skill-card state-${defaultBadge.toLowerCase()} border-2 rounded-2xl overflow-hidden`;
        card.innerHTML=`
        <div class="flex items-center gap-3 px-4 py-3 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-100 dark:border-slate-700">
            <span class="text-xl">${emoji}</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold truncate">${escHtml(sk.sub)}</p>
                <p class="text-[10px] text-slate-400">${escHtml(sk.main||'')} · <span class="font-semibold text-hr">${sk.nc_level}</span></p>
            </div>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all duration-200" id="nc-pill-${sk.id}"></span>
        </div>
        <div class="p-4 border-b border-slate-100 dark:border-slate-700">
            ${sk.cert_img
                ?`<div class="aspect-[16/9] bg-slate-100 dark:bg-slate-800 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700"><img src="../admin/serve_verification_image.php?path=${encodeURIComponent(sk.cert_img)}" class="w-full h-full object-contain cursor-pointer" onclick="window.open(this.src,'_blank')" alt="NC Certificate"/></div><p class="text-[9px] text-slate-400 mt-1 text-center">Click to open full size</p>`
                :`<div class="aspect-[16/9] bg-slate-100 dark:bg-slate-800 rounded-xl flex items-center justify-center border border-dashed border-slate-300 dark:border-slate-600"><p class="text-xs text-slate-400">No certificate image uploaded</p></div>`}
        </div>
        <div class="px-4 pt-3 pb-2 grid grid-cols-2 gap-3">
            <div><p class="text-[9px] font-bold text-slate-400 uppercase">Certificate No.</p><p class="text-xs font-mono mt-0.5 break-all">${sk.cert_num!=='-'?escHtml(sk.cert_num):'<span class="text-slate-400 italic">Not provided</span>'}</p></div>
            <div><p class="text-[9px] font-bold text-slate-400 uppercase">NC Level</p><p class="text-xs font-semibold mt-0.5">${sk.nc_level}</p></div>
        </div>
        <div class="px-4 pb-4 space-y-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase mb-2">Grant badge level</p>
            <div class="flex gap-2">
                <button type="button" id="btn-gold-${sk.id}"   onclick="setSkillBadge(${sk.id},'Gold')"   class="badge-btn">🟡 Gold</button>
                <button type="button" id="btn-silver-${sk.id}" onclick="setSkillBadge(${sk.id},'Silver')" class="badge-btn">⚪ Silver</button>
                <button type="button" id="btn-bronze-${sk.id}" onclick="setSkillBadge(${sk.id},'Bronze')" class="badge-btn">🟤 Bronze</button>
                <button type="button" id="btn-reject-${sk.id}" onclick="setSkillBadge(${sk.id},'reject')" class="badge-btn">✕ Reject</button>
            </div>
            <input type="text" id="note-${sk.id}" placeholder="Optional note…"
                   class="w-full mt-2 text-xs px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:ring-1 focus:ring-hr"
                   oninput="updateNotes(${sk.id},this.value)"/>
        </div>`;
        container.appendChild(card);
        applyBadgeStyle(sk.id,defaultBadge);
    });
    syncHiddenInputs();
    updateDecisionSummary();
}

function escHtml(str){if(!str)return'';return str.replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]));}

function setSkillBadge(skillId,badge){
    if(badge==='reject'){skillState[skillId].decision='reject';skillState[skillId].badge='Unverified';}
    else{skillState[skillId].decision='approve';skillState[skillId].badge=badge;}
    applyBadgeStyle(skillId,badge);syncHiddenInputs();updateDecisionSummary();
}

function applyBadgeStyle(skillId,badge){
    const card=document.getElementById(`nc-card-${skillId}`);
    const pill=document.getElementById(`nc-pill-${skillId}`);
    card.classList.remove('state-gold','state-silver','state-bronze','state-reject','state-pending');
    const configs={
        'Gold'  :{state:'gold',  pill:'🟡 Gold',   pillCls:'bg-amber-50 dark:bg-amber-900/20 text-amber-700 border-amber-300'},
        'Silver':{state:'silver',pill:'⚪ Silver', pillCls:'bg-slate-100 dark:bg-slate-800 text-slate-600 border-slate-300'},
        'Bronze':{state:'bronze',pill:'🟤 Bronze', pillCls:'bg-orange-50 dark:bg-orange-900/20 text-orange-600 border-orange-300'},
        'reject':{state:'reject',pill:'✕ Rejected',pillCls:'bg-red-50 dark:bg-red-900/20 text-red-500 border-red-300'},
    };
    const cfg=configs[badge]||configs['Bronze'];
    card.classList.add(`state-${cfg.state}`);
    if(pill){pill.textContent=cfg.pill;pill.className=`px-2.5 py-1 rounded-full text-[10px] font-bold border transition-all duration-200 ${cfg.pillCls}`;}
    ['Gold','Silver','Bronze','reject'].forEach(b=>{
        const btn=document.getElementById(`btn-${b.toLowerCase()}-${skillId}`);
        if(!btn)return;
        btn.classList.remove('inactive','active-gold','active-silver','active-bronze','active-reject');
        btn.classList.add(b===badge?`active-${b.toLowerCase()}`:'inactive');
    });
}

function updateNotes(skillId,val){if(skillState[skillId])skillState[skillId].notes=val;syncHiddenInputs();}

function syncHiddenInputs(){
    const area=document.getElementById('skill-hidden-inputs');if(!area)return;
    const comOnly=area.querySelector('[name="community_only"]')?.outerHTML||'';
    area.innerHTML=comOnly;
    Object.entries(skillState).forEach(([id,st])=>{
        area.insertAdjacentHTML('beforeend',
            `<input type="hidden" name="skill_action[${id}]" value="${st.decision}">
             <input type="hidden" name="skill_badge[${id}]"  value="${st.badge}">
             <input type="hidden" name="skill_notes[${id}]"  value="${st.notes.replace(/"/g,'&quot;')}">`);
    });
}

function updateDecisionSummary(){
    const summary=document.getElementById('nc-decision-summary');if(!summary)return;
    const approved=Object.values(skillState).filter(s=>s.decision==='approve').length;
    const rejected=Object.values(skillState).filter(s=>s.decision==='reject').length;
    const total=currentSkills.length;
    summary.textContent=`${approved}/${total} skill${total!==1?'s':''} approved${rejected>0?`, ${rejected} rejected`:''}. Click Approve Selected to confirm.`;
}
</script>
</body>
</html>