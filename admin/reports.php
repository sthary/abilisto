<?php
// admin/reports.php  — Support Admin (Trust & Safety)
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$admin_id   = $_SESSION['user_id'];
$admin_name_stmt = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$admin_name_stmt->execute([$admin_id]);
$admin_name = $admin_name_stmt->fetch()['full_name'] ?? 'Support';

// ============================================================
// HELPERS
// ============================================================
function getUserAvatar($pic, $name) {
    if (!empty($pic) && file_exists("../uploads/profiles/".$pic)) return "../uploads/profiles/".$pic;
    return "https://ui-avatars.com/api/?name=".urlencode($name)."&background=6366F1&color=fff&size=128&bold=true";
}

function statusClass($s) {
    return match($s) {
        'pending'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800',
        'reviewed' => 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800',
        'resolved' => 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-800',
        'dismissed'=> 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700',
        default    => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
    };
}

function violationLabel($n) {
    return match($n) {
        1 => ['⚠️ 1st — Warning',            'text-amber-600'],
        2 => ['🚫 2nd — Booking Blocked',     'text-orange-600'],
        3 => ['💀 3rd — Account Deletion',    'text-red-600'],
        default => ['Violation #'.$n,          'text-slate-500'],
    };
}

// ============================================================
// HANDLE AJAX — REPLY
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reply') {
    header('Content-Type: application/json');
    $rid   = intval($_POST['report_id']   ?? 0);
    $rtype = $_POST['report_type'] ?? 'client';
    $msg   = trim($_POST['message'] ?? '');
    $target= intval($_POST['target_user_id'] ?? 0); // who receives notif

    if (!$msg || !$rid) { echo json_encode(['success'=>false]); exit(); }

    $reply_stmt = $conn->prepare("INSERT INTO report_replies (report_id,report_type,sender_id,sender_role,message)
                  VALUES (?,?,?,'support',?)");
    $reply_stmt->execute([$rid, $rtype, $admin_id, $msg]);

    // Notify both parties (reporter + reported)
    $reporter_id = intval($_POST['reporter_id'] ?? 0);
    $reported_id = intval($_POST['reported_id'] ?? 0);
    $type_label  = $rtype === 'worker' ? 'worker' : 'client';
    $notif       = "📩 Support replied to your Report #$rid: ".mb_substr(strip_tags($msg),0,80)."…";
    $notif_stmt  = $conn->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,'#')");
    foreach ([$reporter_id, $reported_id] as $uid) {
        if ($uid > 0) $notif_stmt->execute([$uid, $notif]);
    }

    echo json_encode(['success'=>true,'ts'=>date('M d, Y h:i A'),'admin_name'=>htmlspecialchars($admin_name)]);
    exit();
}

// ============================================================
// HANDLE PENALTY ACTIONS (confirm report → enforce penalty)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_report') {
    header('Content-Type: application/json');

    $rid         = intval($_POST['report_id']     ?? 0);
    $rtype       = $_POST['report_type'] ?? 'client';
    $reported_id = intval($_POST['reported_id']   ?? 0);
    $user_role   = $_POST['user_role']   ?? 'worker'; // 'worker'|'client'
    $notes_raw   = trim($_POST['admin_notes']     ?? '');
    $notes       = $notes_raw;

    // ── Get reported user's current violation count ───────
    $u_stmt = $conn->prepare("SELECT violation_count, has_prior_violation, appeal_eligible FROM users WHERE id=?");
    $u_stmt->execute([$reported_id]);
    $u = $u_stmt->fetch();
    $current_violations = (int)($u['violation_count'] ?? 0);
    $new_violations     = $current_violations + 1;
    $prior              = (int)($u['has_prior_violation'] ?? 0);

    $action_taken = 'warning';
    $penalty_msg  = '';

    if ($user_role === 'worker') {
        // ── WORKER ESCALATION ─────────────────────────────
        if ($new_violations === 1) {
            // 1st: formal warning notification only
            $action_taken = 'warning';
            $penalty_msg  = "⚠️ Formal Warning: Your account has received a confirmed report. Continued violations may result in booking suspension. This is your 1st violation.";
        } elseif ($new_violations === 2) {
            // 2nd: disable bookings
            $action_taken = 'booking_blocked';
            $stmt = $conn->prepare("UPDATE worker_profiles SET can_accept_bookings=false WHERE user_id=?");
            $stmt->execute([$reported_id]);
            $penalty_msg  = "🚫 Booking Suspended: Due to a second confirmed report, your ability to accept new bookings has been disabled. Contact support to appeal.";
        } elseif ($new_violations >= 3) {
            // 3rd: permanent deletion
            $action_taken = 'account_deleted';
            $stmt = $conn->prepare("UPDATE worker_profiles SET deletion_pending=true, is_active=false WHERE user_id=?");
            $stmt->execute([$reported_id]);
            $stmt = $conn->prepare("UPDATE users SET is_flagged=true WHERE id=?");
            $stmt->execute([$reported_id]);
            // Soft-delete: mark inactive; hard delete can be done manually
            $penalty_msg  = "❌ Account Terminated: Your account has been permanently disabled due to three confirmed violations of platform rules.";
        }

    } else {
        // ── CLIENT ESCALATION ─────────────────────────────
        // Confirmed report → restrict cash payment
        $action_taken = 'cash_restricted';
        $stmt = $conn->prepare("UPDATE users SET cash_enabled=false, cash_restriction_at=NOW() WHERE id=?");
        $stmt->execute([$reported_id]);

        if ($prior == 0) {
            // First-time: 48-hour appeal window
            $deadline = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $stmt = $conn->prepare("UPDATE users SET appeal_eligible=true, appeal_deadline=? WHERE id=?");
            $stmt->execute([$deadline, $reported_id]);
            $penalty_msg = "💳 Cash Payment Restricted: Due to a confirmed report, your cash payment option has been restricted. You have a 48-hour window to appeal this decision.";
        } else {
            // Prior violation: immediate, no appeal
            $stmt = $conn->prepare("UPDATE users SET appeal_eligible=false, appeal_deadline=NULL WHERE id=?");
            $stmt->execute([$reported_id]);
            $penalty_msg = "💳 Cash Payment Restricted (No Appeal): Due to a prior confirmed violation, your cash payment option has been permanently restricted. No appeal is available.";
        }
    }

    // ── Update user violation counters ────────────────────
    $stmt = $conn->prepare("UPDATE users SET
        is_flagged=true,
        violation_count=?,
        last_violation_at=NOW(),
        has_prior_violation=true
        WHERE id=?");
    $stmt->execute([$new_violations, $reported_id]);

    // ── Mark report resolved ──────────────────────────────
    $table = ($rtype === 'worker') ? 'reports_worker' : 'reports';
    $ts    = date('Y-m-d H:i:s');
    $log_entry = "\n[Support ".date('M d Y H:i')."] $notes";
    $stmt = $conn->prepare("UPDATE $table SET status='resolved',
        admin_notes=CONCAT(COALESCE(admin_notes,''),?),
        updated_at=?
        WHERE id=?");
    $stmt->execute([$log_entry, $ts, $rid]);

    // ── Log to violation_logs ─────────────────────────────
    $stmt = $conn->prepare("INSERT INTO violation_logs (user_id,user_role,report_id,report_type,violation_num,action_taken,notes,actioned_by)
                  VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$reported_id, $user_role, $rid, $rtype, $new_violations, $action_taken, $notes, $admin_id]);

    // ── Send notification to reported user ────────────────
    $safe_msg = $penalty_msg;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,'#')");
    $stmt->execute([$reported_id, $safe_msg]);

    echo json_encode([
        'success'           => true,
        'new_violations'    => $new_violations,
        'action_taken'      => $action_taken,
        'penalty_msg'       => $penalty_msg,
    ]);
    exit();
}

// ============================================================
// HANDLE: Dismiss report
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_report') {
    header('Content-Type: application/json');
    $rid   = intval($_POST['report_id']   ?? 0);
    $rtype = $_POST['report_type'] ?? 'client';
    $notes = trim($_POST['notes']  ?? '');
    $table = ($rtype === 'worker') ? 'reports_worker' : 'reports';
    $ts    = date('Y-m-d H:i:s');
    $log_entry = "\n[Dismissed ".date('M d Y H:i')."] $notes";
    $stmt = $conn->prepare("UPDATE $table SET status='dismissed',
        admin_notes=CONCAT(COALESCE(admin_notes,''),?),
        updated_at=? WHERE id=?");
    $stmt->execute([$log_entry, $ts, $rid]);

    // Notify reporter
    $reporter_id = intval($_POST['reporter_id'] ?? 0);
    if ($reporter_id) {
        $notif = "Your Report #$rid has been reviewed and dismissed after investigation. ".($notes?"Reason: $notes":"");
        $stmt = $conn->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,'#')");
        $stmt->execute([$reporter_id, $notif]);
    }
    echo json_encode(['success'=>true]); exit();
}

// ============================================================
// HANDLE: Appeal (lift client cash restriction)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lift_restriction') {
    header('Content-Type: application/json');
    $client_id = intval($_POST['client_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE users SET cash_enabled=true, cash_restriction_at=NULL, appeal_deadline=NULL WHERE id=?");
    $stmt->execute([$client_id]);
    $stmt = $conn->prepare("INSERT INTO violation_logs (user_id,user_role,report_id,report_type,violation_num,action_taken,notes,actioned_by)
                  VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$client_id, 'client', 0, 'client', 0, 'cash_restriction_lifted', 'Appeal granted by support', $admin_id]);
    $notif = "✅ Appeal Granted: Your cash payment option has been restored following your successful appeal.";
    $stmt = $conn->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,'#')");
    $stmt->execute([$client_id, $notif]);
    echo json_encode(['success'=>true]); exit();
}

// ============================================================
// FILTERS & DATA
// ============================================================
$type_f   = $_GET['type']   ?? 'all';
$status_f = $_GET['status'] ?? 'all';
$sort     = $_GET['sort']   ?? 'newest';
$search   = $_GET['search'] ?? '';

// ── Base queries ──────────────────────────────────────────
$client_sql = "SELECT
    r.id, r.booking_id,
    r.client_id  AS reporter_id, r.worker_id AS reported_id,
    r.report_reason, r.report_details,
    NULL          AS proof_image,
    r.status, r.admin_notes, r.created_at, r.updated_at,
    'client'      AS report_type,
    c.full_name   AS reporter_name, c.email AS reporter_email, c.profile_pic AS reporter_pic,
    c.role        AS reporter_role,
    w.full_name   AS reported_name, w.email AS reported_email, w.profile_pic AS reported_pic,
    w.role        AS reported_role,
    w.violation_count AS reported_violations, w.is_flagged AS reported_flagged,
    w.cash_enabled, w.appeal_eligible, w.appeal_deadline,
    b.booking_date, b.problem_desc, b.service_type, b.urgency_level
FROM reports r
JOIN users c ON r.client_id  = c.id
JOIN users w ON r.worker_id  = w.id
LEFT JOIN bookings b ON r.booking_id = b.id";

$worker_sql = "SELECT
    r.id, r.booking_id,
    r.worker_id        AS reporter_id, r.reported_user_id AS reported_id,
    r.report_reason, r.report_details,
    r.proof_image,
    r.status, r.admin_notes, r.created_at, r.updated_at,
    'worker'           AS report_type,
    w.full_name        AS reporter_name, w.email AS reporter_email, w.profile_pic AS reporter_pic,
    w.role             AS reporter_role,
    u.full_name        AS reported_name, u.email AS reported_email, u.profile_pic AS reported_pic,
    u.role             AS reported_role,
    u.violation_count  AS reported_violations, u.is_flagged AS reported_flagged,
    u.cash_enabled, u.appeal_eligible, u.appeal_deadline,
    b.booking_date, b.problem_desc, b.service_type, b.urgency_level
FROM reports_worker r
JOIN users w ON r.worker_id        = w.id
JOIN users u ON r.reported_user_id = u.id
LEFT JOIN bookings b ON r.booking_id = b.id";

// ── Apply filters ─────────────────────────────────────────
$params = [];

if ($type_f === 'client') {
    $sql = $client_sql;
    $conds = [];
    if ($status_f !== 'all') { $conds[] = "r.status=?"; $params[] = $status_f; }
    if ($search)  { $conds[] = "(c.full_name LIKE ? OR w.full_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
} elseif ($type_f === 'worker') {
    $sql = $worker_sql;
    $conds = [];
    if ($status_f !== 'all') { $conds[] = "r.status=?"; $params[] = $status_f; }
    if ($search)  { $conds[] = "(w.full_name LIKE ? OR u.full_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
} else {
    $combined = "SELECT * FROM (($client_sql) UNION ALL ($worker_sql)) AS all_reports";
    $conds = [];
    if ($status_f !== 'all') { $conds[] = "status=?"; $params[] = $status_f; }
    if ($search)             { $conds[] = "(reporter_name LIKE ? OR reported_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    $sql = $combined . (count($conds) ? ' WHERE '.implode(' AND ',$conds) : '');
}

$sql .= match($sort) {
    'oldest'        => ' ORDER BY created_at ASC',
    'reporter_asc'  => ' ORDER BY reporter_name ASC',
    'reporter_desc' => ' ORDER BY reporter_name DESC',
    'reported_asc'  => ' ORDER BY reported_name ASC',
    'reported_desc' => ' ORDER BY reported_name DESC',
    default         => ' ORDER BY created_at DESC',
};

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$report_rows = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────
$cs = $conn->query("SELECT COUNT(*) as t,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as p,
    SUM(CASE WHEN status='reviewed' THEN 1 ELSE 0 END) as rv,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as rs,
    SUM(CASE WHEN status='dismissed' THEN 1 ELSE 0 END) as d
    FROM reports")->fetch();
$ws = $conn->query("SELECT COUNT(*) as t,
    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as p,
    SUM(CASE WHEN status='reviewed' THEN 1 ELSE 0 END) as rv,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as rs,
    SUM(CASE WHEN status='dismissed' THEN 1 ELSE 0 END) as d
    FROM reports_worker")->fetch();
$stats = [
    'total'     => ($cs['t']??0)  + ($ws['t']??0),
    'pending'   => ($cs['p']??0)  + ($ws['p']??0),
    'reviewed'  => ($cs['rv']??0) + ($ws['rv']??0),
    'resolved'  => ($cs['rs']??0) + ($ws['rs']??0),
    'dismissed' => ($cs['d']??0)  + ($ws['d']??0),
];

// ── Flagged workers (3+ confirmed reports) ────────────────
$flagged_rows = $conn->query("SELECT u.id, u.full_name, u.profile_pic, u.violation_count
    FROM users u WHERE u.is_flagged = TRUE AND u.role='worker'
    ORDER BY u.violation_count DESC LIMIT 8")->fetchAll();

$current_date = date('M d, Y');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto Support — Reports</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#146af5","background-light":"#F8FAFC","background-dark":"#0F172A"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],sans:["Plus Jakarta Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
</script>
<style>
    body{font-family:'Plus Jakarta Sans',sans-serif;}
    .glass{background:rgba(255,255,255,0.7);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.3);}
    .dark .glass{background:rgba(30,41,59,0.7);border:1px solid rgba(255,255,255,0.1);}
    .card{background:white;border:1px solid #f1f5f9;border-radius:24px;box-shadow:0 4px 24px rgba(0,0,0,0.05);}
    .dark .card{background:#0f172a;border-color:#1e293b;}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;border-width:1px;}
    /* Flagged user accent */
    .flagged-border{border-left:4px solid #ef4444;}
    /* Reply thread */
    .reply-support{background:rgba(99,102,241,0.07);border-left:3px solid #6366F1;}
    .reply-user{background:rgba(241,245,249,0.8);border-left:3px solid #94a3b8;}
    .dark .reply-support{background:rgba(99,102,241,0.12);}
    .dark .reply-user{background:rgba(30,41,59,0.8);}
    /* Penalty level colors */
    .pen-1{background:rgba(245,158,11,.1);color:#D97706;border-color:rgba(245,158,11,.3);}
    .pen-2{background:rgba(249,115,22,.1);color:#EA580C;border-color:rgba(249,115,22,.3);}
    .pen-3{background:rgba(239,68,68,.1);color:#DC2626;border-color:rgba(239,68,68,.3);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}
    #toast.success{background:#10B981;color:white;}#toast.error{background:#F43F5E;color:white;}#toast.info{background:#6366F1;color:white;}
</style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">
<?php include 'navbar.php'; ?>
<div id="toast"></div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Header -->
    <header class="flex flex-col lg:flex-row lg:items-center justify-between mb-8 gap-4">
        <div class="flex items-center gap-4">
            <button class="lg:hidden p-2 glass rounded-lg text-slate-500" onclick="toggleMobileMenu()"><span class="material-icons-round">menu</span></button>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Reports Management</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mt-0.5">Review, reply, and enforce platform accountability</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" class="glass flex items-center px-4 py-2.5 rounded-2xl w-72">
                <span class="material-icons-round text-slate-400 text-xl">search</span>
                <input name="search" class="bg-transparent border-none focus:ring-0 text-sm w-full placeholder:text-slate-400 ml-2" placeholder="Search by name…" value="<?php echo htmlspecialchars($_GET['search']??''); ?>"/>
                <input type="hidden" name="type"   value="<?php echo htmlspecialchars($type_f); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>">
                <input type="hidden" name="sort"   value="<?php echo htmlspecialchars($sort); ?>">
            </form>
        </div>
    </header>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-8">
        <?php
        $stat_items=[
            ['Total',    $stats['total'],    'bg-white dark:bg-slate-900',    'text-slate-700 dark:text-slate-300'],
            ['Pending',  $stats['pending'],  'bg-amber-50 dark:bg-amber-900/20','text-amber-700 dark:text-amber-400'],
            ['Reviewed', $stats['reviewed'], 'bg-blue-50 dark:bg-blue-900/20',  'text-blue-700 dark:text-blue-400'],
            ['Resolved', $stats['resolved'], 'bg-emerald-50 dark:bg-emerald-900/20','text-emerald-700 dark:text-emerald-400'],
            ['Dismissed',$stats['dismissed'],'bg-slate-50 dark:bg-slate-800/50', 'text-slate-500'],
        ];
        foreach($stat_items as $si):?>
        <div class="<?php echo $si[2]; ?> p-5 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm">
            <p class="<?php echo $si[3]; ?> text-xs font-semibold mb-1"><?php echo $si[0]; ?></p>
            <h3 class="text-2xl font-bold <?php echo $si[3]; ?>"><?php echo $si[1]; ?></h3>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Flagged workers strip -->
    <?php if (count($flagged_rows) > 0): ?>
    <div class="card border-l-4 border-red-500 p-5 mb-8">
        <div class="flex items-center gap-2 mb-4">
            <span class="material-icons-round text-red-500">warning</span>
            <h3 class="font-bold text-red-700 dark:text-red-400">Flagged Accounts</h3>
            <span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-600 border border-red-200 dark:border-red-800"><?php echo count($flagged_rows); ?></span>
        </div>
        <div class="flex flex-wrap gap-3">
        <?php foreach($flagged_rows as $fw):
            $av=getUserAvatar($fw['profile_pic'],$fw['full_name']);
            $vc=(int)$fw['violation_count'];
            $pc=$vc>=3?'pen-3':($vc>=2?'pen-2':'pen-1');
        ?>
        <div class="flex items-center gap-2 bg-white dark:bg-slate-800 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700">
            <img src="<?php echo $av; ?>" class="w-7 h-7 rounded-full object-cover" alt="">
            <span class="text-sm font-semibold"><?php echo htmlspecialchars($fw['full_name']); ?></span>
            <span class="badge <?php echo $pc; ?>"><?php echo $vc; ?> violation<?php echo $vc!==1?'s':''; ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div class="glass flex p-1.5 rounded-2xl gap-1">
            <?php foreach(['all'=>'All Reports','client'=>'Client Reports','worker'=>'Worker Reports'] as $tv=>$tl):
                $active=$type_f===$tv;
                $ac=$active?($tv==='client'?'bg-blue-500 text-white shadow':($tv==='worker'?'bg-purple-500 text-white shadow':'bg-white dark:bg-slate-800 shadow text-primary')):'text-slate-500';
            ?>
            <a href="?type=<?php echo $tv; ?>&sort=<?php echo $sort; ?>&status=<?php echo $status_f; ?>&search=<?php echo urlencode($_GET['search']??''); ?>"
               class="px-5 py-2 rounded-xl text-sm font-bold transition-all <?php echo $ac; ?>"><?php echo $tl; ?></a>
            <?php endforeach; ?>
        </div>
        <select onchange="window.location.href='?type=<?php echo $type_f; ?>&sort='+this.value+'&status=<?php echo $status_f; ?>&search=<?php echo urlencode($_GET['search']??''); ?>'"
                class="glass px-4 py-2.5 rounded-2xl text-sm font-semibold text-slate-600 dark:text-slate-300 border-none focus:ring-0 cursor-pointer">
            <?php foreach(['newest'=>'Newest First','oldest'=>'Oldest First','reporter_asc'=>'Reporter A–Z','reported_asc'=>'Reported A–Z'] as $sv=>$sl): ?>
            <option value="<?php echo $sv; ?>" <?php echo $sort===$sv?'selected':''; ?>><?php echo $sl; ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Status pills -->
    <div class="flex flex-wrap items-center gap-2 mb-8">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Status:</span>
        <?php foreach(['all'=>['All','bg-primary text-white','bg-white dark:bg-slate-800 text-slate-600'],
                        'pending'=>['Pending','bg-amber-500 text-white','bg-amber-50 dark:bg-amber-900/20 text-amber-700'],
                        'reviewed'=>['Reviewed','bg-blue-500 text-white','bg-blue-50 dark:bg-blue-900/20 text-blue-700'],
                        'resolved'=>['Resolved','bg-emerald-500 text-white','bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700'],
                        'dismissed'=>['Dismissed','bg-slate-500 text-white','bg-slate-100 dark:bg-slate-800 text-slate-500'],
                       ] as $sv=>$sd):
            $active=$status_f===$sv;
        ?>
        <a href="?type=<?php echo $type_f; ?>&sort=<?php echo $sort; ?>&status=<?php echo $sv; ?>&search=<?php echo urlencode($_GET['search']??''); ?>"
           class="px-4 py-1.5 rounded-full text-xs font-bold transition-all <?php echo $active?$sd[1]:$sd[2]; ?>"><?php echo $sd[0]; ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Reports list -->
    <div class="space-y-8" id="reports-list">
    <?php if (count($report_rows) > 0):
        foreach($report_rows as $rp):
            $is_flagged    = (int)($rp['reported_violations']??0) >= 1;
            $rtype         = $rp['report_type'];
            $reporter_av   = getUserAvatar($rp['reporter_pic'],$rp['reporter_name']);
            $reported_av   = getUserAvatar($rp['reported_pic'],$rp['reported_name']);
            $violations    = (int)($rp['reported_violations']??0);
            $next_v        = $violations + 1;
            [$v_label,$v_color] = violationLabel($next_v);
            $type_color    = $rtype==='client'?'bg-blue-100 dark:bg-blue-900/30 text-blue-600':'bg-purple-100 dark:bg-purple-900/30 text-purple-600';
            $type_icon     = $rtype==='client'?'person':'handyman';
            $type_label    = $rtype==='client'?'Client Report':'Worker Report';
            $is_client_rep = ($rtype==='client');
            $has_appeal    = $is_client_rep && (int)($rp['appeal_eligible']??1)===1 && !empty($rp['appeal_deadline']);

            // Fetch replies for this report
            $replies_stmt = $conn->prepare("SELECT rr.*, u.full_name, u.profile_pic, u.role
                FROM report_replies rr JOIN users u ON u.id=rr.sender_id
                WHERE rr.report_id=? AND rr.report_type=?
                ORDER BY rr.created_at ASC");
            $replies_stmt->execute([$rp['id'], $rtype]);
            $replies_rows = $replies_stmt->fetchAll();
    ?>
    <div class="card <?php echo $is_flagged?'flagged-border':''; ?> overflow-hidden" id="report-<?php echo $rp['id']; ?>-<?php echo $rtype; ?>">

        <!-- Card header -->
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full <?php echo $type_color; ?> flex items-center justify-center">
                        <span class="material-icons-round text-lg"><?php echo $type_icon; ?></span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-bold text-lg">Report #<?php echo $rp['id']; ?></h3>
                            <span class="badge <?php echo $type_color; ?>"><?php echo $type_label; ?></span>
                            <span class="badge <?php echo statusClass($rp['status']); ?>"><?php echo ucfirst($rp['status']); ?></span>
                            <?php if ($violations > 0): $pc=$violations>=3?'pen-3':($violations>=2?'pen-2':'pen-1'); ?>
                            <span class="badge <?php echo $pc; ?>"><?php echo $violations; ?> prior violation<?php echo $violations!==1?'s':''; ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-1"><?php echo date('M d, Y \a\t h:i A',strtotime($rp['created_at'])); ?></p>
                    </div>
                </div>
                <?php if ($rp['booking_id']): ?>
                <div class="text-right text-xs">
                    <p class="font-semibold">Booking #<?php echo $rp['booking_id']; ?></p>
                    <?php if($rp['booking_date']): ?><p class="text-slate-400"><?php echo date('M d, Y',strtotime($rp['booking_date'])); ?></p><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Parties + Reason -->
        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Reporter -->
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-700">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-2"><?php echo $rtype==='worker'?'Worker (Reporter)':'Client (Reporter)'; ?></p>
                <div class="flex items-center gap-2 mb-2">
                    <img src="<?php echo $reporter_av; ?>" class="w-9 h-9 rounded-full object-cover" alt="">
                    <div><p class="font-bold text-sm"><?php echo htmlspecialchars($rp['reporter_name']); ?></p>
                    <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($rp['reporter_email']); ?></p></div>
                </div>
            </div>
            <!-- Reported -->
            <div class="bg-red-50 dark:bg-red-900/10 p-4 rounded-2xl border border-red-100 dark:border-red-900/30">
                <p class="text-[9px] font-bold text-red-400 uppercase tracking-wider mb-2"><?php echo $rtype==='client'?'Reported Worker':'Reported User'; ?></p>
                <div class="flex items-center gap-2 mb-2">
                    <img src="<?php echo $reported_av; ?>" class="w-9 h-9 rounded-full object-cover" alt="">
                    <div><p class="font-bold text-sm"><?php echo htmlspecialchars($rp['reported_name']); ?></p>
                    <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($rp['reported_email']); ?></p></div>
                </div>
                <?php if ($is_flagged): ?>
                <p class="text-[9px] font-bold text-red-500 flex items-center gap-1">
                    <span class="material-icons-round" style="font-size:11px">flag</span>
                    FLAGGED — <?php echo $violations; ?> confirmed violation<?php echo $violations!==1?'s':''; ?>
                </p>
                <?php endif; ?>
            </div>
            <!-- Reason -->
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-700">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-2">Report Reason</p>
                <p class="font-bold capitalize"><?php echo str_replace('_',' ',$rp['report_reason']); ?></p>
                <?php if(!empty($rp['report_details'])): ?>
                <p class="text-xs text-slate-500 mt-2 leading-relaxed"><?php echo nl2br(htmlspecialchars(mb_substr($rp['report_details'],0,200))); ?><?php echo strlen($rp['report_details'])>200?'…':''; ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Proof image (worker no_show reports) -->
        <?php if(!empty($rp['proof_image'])): ?>
        <div class="px-6 pb-4">
            <div class="bg-amber-50 dark:bg-amber-900/20 p-4 rounded-2xl border border-amber-200 dark:border-amber-800">
                <p class="text-[9px] font-bold text-amber-600 uppercase tracking-wider mb-3 flex items-center gap-1"><span class="material-icons-round text-sm">photo_camera</span>Proof Photo</p>
                <a href="../uploads/report_proofs/<?php echo htmlspecialchars($rp['proof_image']); ?>" target="_blank">
                    <img src="../uploads/report_proofs/<?php echo htmlspecialchars($rp['proof_image']); ?>" class="max-h-48 rounded-xl border border-amber-200 shadow-sm hover:opacity-90 transition-opacity" alt="Proof">
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Appeal window (clients) -->
        <?php if ($has_appeal && $rp['status']==='resolved'): ?>
        <div class="px-6 pb-4">
            <div class="bg-sky-50 dark:bg-sky-900/10 p-4 rounded-2xl border border-sky-200 dark:border-sky-800 flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold text-sky-700 dark:text-sky-400 flex items-center gap-1"><span class="material-icons-round text-sm">gavel</span>Appeal Window Open</p>
                    <p class="text-[10px] text-slate-500 mt-1">Client can appeal until <?php echo date('M d, Y h:i A',strtotime($rp['appeal_deadline'])); ?></p>
                </div>
                <button onclick="liftRestriction(<?php echo $rp['reported_id']; ?>)"
                        class="px-4 py-2 bg-sky-500 text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center gap-1">
                    <span class="material-icons-round text-sm">check_circle</span>Grant Appeal
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reply thread -->
        <div class="px-6 pb-2">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1">
                <span class="material-icons-round text-sm">forum</span>
                Communication Thread
            </p>
            <div class="space-y-3 max-h-48 overflow-y-auto slim-scroll mb-3" id="thread-<?php echo $rp['id']; ?>-<?php echo $rtype; ?>">
            <?php if (count($replies_rows) > 0):
                foreach($replies_rows as $reply):
                    $is_support=$reply['sender_role']==='support';
            ?>
            <div class="p-3 rounded-xl text-sm <?php echo $is_support?'reply-support':'reply-user'; ?>">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-[10px] font-bold <?php echo $is_support?'text-primary':'text-slate-500'; ?>">
                        <?php echo $is_support?'🛡️ Support Admin':('👤 '.htmlspecialchars($reply['full_name'])); ?>
                    </span>
                    <span class="text-[9px] text-slate-400 ml-auto"><?php echo date('M d h:i A',strtotime($reply['created_at'])); ?></span>
                </div>
                <p class="text-slate-700 dark:text-slate-300"><?php echo nl2br(htmlspecialchars($reply['message'])); ?></p>
            </div>
            <?php endforeach; else: ?>
            <p class="text-[11px] text-slate-400 italic text-center py-2">No messages yet.</p>
            <?php endif; ?>
            </div>

            <!-- Reply input -->
            <?php if($rp['status']!=='dismissed'): ?>
            <div class="flex gap-2">
                <textarea id="reply-input-<?php echo $rp['id']; ?>-<?php echo $rtype; ?>"
                          class="flex-1 text-sm px-4 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-primary focus:border-primary outline-none resize-none"
                          rows="2" placeholder="Send a message to both parties…"></textarea>
                <button onclick="sendReply(<?php echo $rp['id']; ?>,'<?php echo $rtype; ?>',<?php echo $rp['reporter_id']; ?>,<?php echo $rp['reported_id']; ?>)"
                        class="px-4 py-2 bg-primary text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all flex items-center gap-1 self-end">
                    <span class="material-icons-round text-sm">send</span>Send
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Admin action footer -->
        <?php if ($rp['status'] !== 'resolved' && $rp['status'] !== 'dismissed'): ?>
        <div class="p-6 bg-slate-50 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Next Enforcement Action</p>
                    <p class="text-sm font-bold <?php echo $v_color; ?>"><?php echo $v_label; ?></p>
                    <p class="text-[10px] text-slate-400 mt-0.5">
                        Reported user: <?php echo htmlspecialchars($rp['reported_name']); ?> ·
                        Role: <?php echo ucfirst($rp['reported_role']); ?>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative">
                        <textarea id="notes-<?php echo $rp['id']; ?>-<?php echo $rtype; ?>"
                                  class="text-xs px-3 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:ring-primary outline-none resize-none w-48"
                                  rows="2" placeholder="Admin notes (optional)…"></textarea>
                    </div>
                    <div class="flex flex-col gap-2">
                        <button onclick="confirmReport(<?php echo $rp['id']; ?>,'<?php echo $rtype; ?>',<?php echo $rp['reported_id']; ?>,'<?php echo $rp['reported_role']; ?>')"
                                class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-1 whitespace-nowrap">
                            <span class="material-icons-round text-sm">gavel</span>Confirm & Enforce
                        </button>
                        <button onclick="dismissReport(<?php echo $rp['id']; ?>,'<?php echo $rtype; ?>',<?php echo $rp['reporter_id']; ?>)"
                                class="px-4 py-2 bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-bold rounded-xl hover:opacity-80 transition-all flex items-center gap-1 whitespace-nowrap">
                            <span class="material-icons-round text-sm">do_not_disturb</span>Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800">
            <p class="text-[10px] text-slate-400 flex items-center gap-1">
                <span class="material-icons-round text-sm"><?php echo $rp['status']==='resolved'?'check_circle':'do_not_disturb'; ?></span>
                Report <?php echo ucfirst($rp['status']); ?>
                <?php if($rp['updated_at']): ?> · <?php echo date('M d, Y',strtotime($rp['updated_at'])); ?><?php endif; ?>
            </p>
            <?php if(!empty($rp['admin_notes'])): ?>
            <p class="text-[11px] text-slate-500 mt-1 whitespace-pre-line"><?php echo htmlspecialchars($rp['admin_notes']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- end report card -->
    <?php endforeach; else: ?>
    <div class="card p-16 text-center">
        <span class="material-icons-round text-4xl text-slate-300 dark:text-slate-600 block mb-3">inbox</span>
        <h3 class="font-bold text-lg mb-1">No Reports Found</h3>
        <p class="text-slate-500 text-sm">No reports match your current filters.</p>
        <a href="reports.php" class="inline-block mt-4 px-6 py-2.5 bg-primary text-white rounded-xl text-sm font-semibold hover:opacity-90">Clear Filters</a>
    </div>
    <?php endif; ?>
    </div>

</main>

<script>
function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className=`show ${type}`;setTimeout(()=>{t.className=type;},3500);}

// ── Send reply ──────────────────────────────────────────────
async function sendReply(rid,rtype,reporterId,reportedId){
    const key=`reply-input-${rid}-${rtype}`;
    const msg=document.getElementById(key)?.value.trim();
    if(!msg){showToast('Type a message first.','error');return;}

    const fd=new FormData();
    fd.append('action','send_reply');fd.append('report_id',rid);fd.append('report_type',rtype);
    fd.append('message',msg);fd.append('reporter_id',reporterId);fd.append('reported_id',reportedId);

    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
        const thread=document.getElementById(`thread-${rid}-${rtype}`);
        const noMsg=thread?.querySelector('.italic');
        if(noMsg)noMsg.remove();
        const div=document.createElement('div');
        div.className='p-3 rounded-xl text-sm reply-support';
        div.innerHTML=`<div class="flex items-center gap-2 mb-1"><span class="text-[10px] font-bold text-primary">🛡️ Support Admin</span><span class="text-[9px] text-slate-400 ml-auto">${data.ts}</span></div><p class="text-slate-700 dark:text-slate-300">${escHtml(msg).replace(/\n/g,'<br>')}</p>`;
        thread?.appendChild(div);
        thread?.scrollTo({top:thread.scrollHeight,behavior:'smooth'});
        document.getElementById(key).value='';
        showToast('Message sent to both parties.','success');
    }
}

// ── Confirm report → enforce penalty ───────────────────────
async function confirmReport(rid,rtype,reportedId,userRole){
    const notesKey=`notes-${rid}-${rtype}`;
    const notes=document.getElementById(notesKey)?.value.trim()||'';
    if(!confirm(`Confirm this report and enforce penalty on the ${userRole}?\n\nThis cannot be undone.`))return;

    const fd=new FormData();
    fd.append('action','confirm_report');fd.append('report_id',rid);fd.append('report_type',rtype);
    fd.append('reported_id',reportedId);fd.append('user_role',userRole);fd.append('admin_notes',notes);

    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
        showToast(`Penalty enforced: ${data.action_taken.replace('_',' ')}. Notification sent.`,'success');
        setTimeout(()=>location.reload(),1500);
    } else {
        showToast('Error processing penalty.','error');
    }
}

// ── Dismiss report ──────────────────────────────────────────
async function dismissReport(rid,rtype,reporterId){
    const notesKey=`notes-${rid}-${rtype}`;
    const notes=document.getElementById(notesKey)?.value.trim()||'';
    if(!confirm('Dismiss this report? The reporter will be notified.'))return;

    const fd=new FormData();
    fd.append('action','dismiss_report');fd.append('report_id',rid);fd.append('report_type',rtype);
    fd.append('reporter_id',reporterId);fd.append('notes',notes);

    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){showToast('Report dismissed. Reporter notified.','info');setTimeout(()=>location.reload(),1200);}
}

// ── Lift client cash restriction ────────────────────────────
async function liftRestriction(clientId){
    if(!confirm('Grant appeal and restore cash payment for this client?'))return;
    const fd=new FormData();
    fd.append('action','lift_restriction');fd.append('client_id',clientId);
    const res=await fetch(window.location.href,{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){showToast('Appeal granted. Cash payment restored.','success');setTimeout(()=>location.reload(),1200);}
}

function escHtml(str){return str.replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]));}
</script>
</body>
</html>