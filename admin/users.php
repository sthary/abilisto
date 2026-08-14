<?php
// admin/users.php  — Support Admin edition
// Shows flagged status, violation count, enforcement actions
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

$admin_id   = $_SESSION['user_id'];
$admin_stmt = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$admin_stmt->execute([$admin_id]);
$admin_name = $admin_stmt->fetch()['full_name'] ?? 'Support';

// ── Handle toggles ──────────────────────────────────────────
if (isset($_GET['toggle_cash']) && isset($_GET['user_id'])) {
    $uid = intval($_GET['user_id']); $val = intval($_GET['toggle_cash']);
    if ($val===0||$val===1) {
        $stmt = $conn->prepare("UPDATE users SET cash_enabled=? WHERE id=? AND role='client'");
        $stmt->execute([$val, $uid]);
    }
    header("Location: users.php?".http_build_query(['search'=>$_GET['search']??'','role'=>$_GET['role']??'','msg'=>'cash_updated'])); exit();
}
if (isset($_GET['toggle_accept']) && isset($_GET['user_id'])) {
    $uid = intval($_GET['user_id']); $val = intval($_GET['toggle_accept']);
    if ($val===0||$val===1) {
        $stmt = $conn->prepare("UPDATE worker_profiles SET can_accept_bookings=? WHERE user_id=?");
        $stmt->execute([$val, $uid]);
    }
    header("Location: users.php?".http_build_query(['search'=>$_GET['search']??'','role'=>$_GET['role']??'','msg'=>'accept_updated'])); exit();
}
if (isset($_GET['toggle_flag']) && isset($_GET['user_id'])) {
    $uid     = intval($_GET['user_id']);
    $new_flag= intval($_GET['toggle_flag']);
    $stmt = $conn->prepare("UPDATE users SET is_flagged=? WHERE id=?");
    $stmt->execute([$new_flag, $uid]);
    header("Location: users.php?".http_build_query(['search'=>$_GET['search']??'','role'=>$_GET['role']??'','msg'=>'flag_updated'])); exit();
}
if (isset($_GET['delete_id'])) {
    $del = intval($_GET['delete_id']);
    if ($del !== (int)$_SESSION['user_id']) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->execute([$del]);
        header("Location: users.php?msg=deleted"); exit();
    }
}

// ── Filters ─────────────────────────────────────────────────
$search      = $_GET['search']  ?? '';
$role_filter = $_GET['role']    ?? '';
$flag_filter = $_GET['flagged'] ?? ''; // 'yes' | ''

$where  = "WHERE u.role NOT IN ('admin','finance','hr')";
$params = [];
if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.id::text LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($role_filter && in_array($role_filter,['client','worker','business'])) {
    $where .= " AND u.role=?";
    $params[] = $role_filter;
}
if ($flag_filter === 'yes') $where .= " AND u.is_flagged=TRUE";

$users_stmt = $conn->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.role, u.created_at,
    u.municipality, u.profile_pic, u.is_flagged, u.violation_count, u.has_prior_violation,
    u.cash_enabled, u.cash_restriction_at, u.appeal_eligible, u.appeal_deadline,
    COALESCE(wp.can_accept_bookings,TRUE) AS can_accept_bookings,
    wp.wallet_balance, wp.verification_status, wp.deletion_pending
    FROM users u
    LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND u.role='worker'
    $where
    ORDER BY u.is_flagged DESC, u.violation_count DESC, u.id DESC");
$users_stmt->execute($params);
$users = $users_stmt->fetchAll();
$total_users = count($users);

// Summary counts
$flagged_stmt = $conn->query("SELECT COUNT(*) as c FROM users WHERE is_flagged=TRUE AND role NOT IN ('admin','finance','hr')");
$flagged_count  = (int)$flagged_stmt->fetch()['c'];
$pending_stmt = $conn->query("SELECT (SELECT COUNT(*) FROM reports WHERE status='pending')+(SELECT COUNT(*) FROM reports_worker WHERE status='pending') as c");
$pending_reports= (int)$pending_stmt->fetch()['c'];

function getUserAvatar($user) {
    if (!empty($user['profile_pic']) && file_exists("../uploads/profiles/".$user['profile_pic'])) return "../uploads/profiles/".$user['profile_pic'];
    return "https://ui-avatars.com/api/?name=".urlencode($user['full_name'])."&background=6366F1&color=fff&size=128&bold=true";
}
function violationPill($n) {
    if ($n <= 0) return '';
    if ($n >= 3) return '<span class="ml-1 inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[9px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 border border-red-200 dark:border-red-800">💀 '.$n.' violations</span>';
    if ($n == 2) return '<span class="ml-1 inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[9px] font-bold bg-orange-100 dark:bg-orange-900/30 text-orange-600 border border-orange-200">🚫 '.$n.' violations</span>';
    return '<span class="ml-1 inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[9px] font-bold bg-amber-100 dark:bg-amber-900/30 text-amber-700 border border-amber-200">⚠️ '.$n.' violation</span>';
}
$current_date = date('M d, Y');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto Support — Users</title>
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
    .card{background:white;border:1px solid #f1f5f9;border-radius:20px;box-shadow:0 4px 24px rgba(0,0,0,0.05);}
    .dark .card{background:#0f172a;border-color:#1e293b;}
    /* Flagged row highlight */
    .flagged-row{border-left:3px solid #ef4444;}
    .dark .flagged-row{border-left-color:#f87171;}
    /* Violation level row tint */
    .vio-1 td{background:rgba(245,158,11,0.04);}
    .vio-2 td{background:rgba(249,115,22,0.06);}
    .vio-3 td{background:rgba(239,68,68,0.08);}
    /* Violation drawer */
    .vio-panel{display:none;background:#fafafa;}
    .dark .vio-panel{background:#0d1117;}
    .vio-panel.open{display:table-row;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}
    #toast.success{background:#10B981;color:white;}#toast.error{background:#F43F5E;color:white;}
</style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">
<?php include 'navbar.php'; ?>
<div id="toast"></div>

<!-- Violation history drawer template (hidden) -->
<div id="vio-modal" class="hidden fixed inset-0 bg-black/40 z-[80] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden max-h-[80vh] flex flex-col">
        <div class="flex justify-between items-center px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
            <h3 class="font-bold" id="vio-modal-title">Violation History</h3>
            <button onclick="document.getElementById('vio-modal').classList.add('hidden')" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg"><span class="material-icons-round">close</span></button>
        </div>
        <div class="overflow-y-auto p-6" id="vio-modal-body">Loading…</div>
    </div>
</div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Manage Users</h1>
            <p class="text-slate-400 text-sm mt-0.5">Platform members · trust & safety enforcement</p>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" class="glass flex items-center px-4 py-2.5 rounded-2xl border border-slate-200 dark:border-slate-700 w-72">
                <span class="material-icons-round text-slate-400 text-xl mr-2">search</span>
                <input type="text" name="search" class="bg-transparent border-none focus:ring-0 text-sm w-full placeholder:text-slate-400" placeholder="Name, email, or ID…" value="<?php echo htmlspecialchars($search); ?>"/>
                <?php if($role_filter): ?><input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>"><?php endif; ?>
                <?php if($flag_filter): ?><input type="hidden" name="flagged" value="<?php echo htmlspecialchars($flag_filter); ?>"><?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Summary strip -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="card p-4"><p class="text-xs text-slate-400 font-semibold mb-1">Total Users</p><p class="text-2xl font-bold"><?php echo $total_users; ?></p></div>
        <div class="card p-4 border-red-200 dark:border-red-900/50">
            <p class="text-xs text-red-500 font-semibold mb-1 flex items-center gap-1"><span class="material-icons-round text-sm">flag</span>Flagged</p>
            <p class="text-2xl font-bold text-red-600"><?php echo $flagged_count; ?></p>
        </div>
        <div class="card p-4 border-amber-200 dark:border-amber-900/50">
            <p class="text-xs text-amber-600 font-semibold mb-1">Pending Reports</p>
            <p class="text-2xl font-bold text-amber-700"><?php echo $pending_reports; ?></p>
        </div>
        <div class="card p-4">
            <p class="text-xs text-slate-400 font-semibold mb-1">Showing</p>
            <p class="text-2xl font-bold"><?php echo $total_users; ?></p>
        </div>
    </div>

    <!-- Role + flag filter pills -->
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <?php foreach([''=>'All Users','client'=>'Clients','worker'=>'Workers','business'=>'Business'] as $rv=>$rl):
            $ac=($role_filter===$rv)?'bg-slate-900 text-white dark:bg-white dark:text-slate-900':'bg-white dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700';
        ?>
        <a href="?role=<?php echo $rv; ?><?php echo $search?"&search=".urlencode($search):''; ?>" class="px-5 py-2 rounded-full text-xs font-bold transition-all <?php echo $ac; ?>"><?php echo $rl; ?></a>
        <?php endforeach; ?>

        <a href="?<?php echo $role_filter?"role=$role_filter&":''; ?><?php echo $search?"search=".urlencode($search)."&":''; ?>flagged=<?php echo $flag_filter==='yes'?'':'yes'; ?>"
           class="px-5 py-2 rounded-full text-xs font-bold transition-all flex items-center gap-1 <?php echo $flag_filter==='yes'?'bg-red-500 text-white':'bg-red-50 dark:bg-red-900/20 text-red-600 border border-red-200 dark:border-red-800'; ?>">
            <span class="material-icons-round text-sm">flag</span>
            <?php echo $flag_filter==='yes'?'Show All':'Flagged Only'; ?>
        </a>
    </div>

    <!-- Toast messages -->
    <?php
    $msgs=['deleted'=>['success','User deleted.'],'cash_updated'=>['success','Cash payment updated.'],'accept_updated'=>['success','Booking acceptance updated.'],'flag_updated'=>['success','Flag status updated.']];
    if(isset($_GET['msg'])&&isset($msgs[$_GET['msg']])){$m=$msgs[$_GET['msg']];echo "<script>document.addEventListener('DOMContentLoaded',()=>showToast('{$m[1]}','{$m[0]}'));<\/script>";}
    ?>

    <!-- Users table -->
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-50 dark:border-slate-800">
                        <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">User</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">Role</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest">Contact</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-4 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50 dark:divide-slate-800/50">
                <?php if(count($users) > 0): foreach($users as $row):
                    $av         = getUserAvatar($row);
                    $joined     = date("M d, Y",strtotime($row['created_at']));
                    $is_flagged = (int)$row['is_flagged'] === 1;
                    $violations = (int)$row['violation_count'];
                    $del_pend   = (int)($row['deletion_pending'] ?? 0);
                    $vio_row    = $violations >= 3 ? 'vio-3' : ($violations >= 2 ? 'vio-2' : ($violations >= 1 ? 'vio-1' : ''));
                    $rbadge     = match($row['role']){'client'=>'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400','worker'=>'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400','business'=>'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400',default=>'bg-slate-100 dark:bg-slate-800 text-slate-500'};
                ?>
                <tr class="group transition-all <?php echo $vio_row; ?> <?php echo $is_flagged?'flagged-row':''; ?>" id="user-row-<?php echo $row['id']; ?>">
                    <!-- User -->
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="relative flex-shrink-0">
                                <img src="<?php echo $av; ?>" class="w-11 h-11 rounded-full object-cover border-2 <?php echo $is_flagged?'border-red-400':'border-white dark:border-slate-800'; ?> shadow-sm" alt="">
                                <?php if($is_flagged): ?>
                                <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 rounded-full border-2 border-white dark:border-slate-900 flex items-center justify-center">
                                    <span class="material-icons-round text-white" style="font-size:9px">flag</span>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-sm font-bold flex items-center gap-1">
                                    <?php echo htmlspecialchars($row['full_name']); ?>
                                    <?php if($del_pend): ?><span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600">DELETION PENDING</span><?php endif; ?>
                                </p>
                                <p class="text-[10px] text-slate-400">#<?php echo $row['id']; ?> · <?php echo $joined; ?></p>
                                <?php if($violations > 0): echo violationPill($violations); endif; ?>
                            </div>
                        </div>
                    </td>
                    <!-- Role -->
                    <td class="px-4 py-4 text-center">
                        <span class="<?php echo $rbadge; ?> px-3 py-1 rounded-full text-[10px] font-bold inline-block"><?php echo ucfirst($row['role']); ?></span>
                        <?php if($row['role']==='client' && $row['cash_enabled']==0): ?>
                        <br><span class="mt-1 inline-block px-2 py-0.5 rounded-full text-[9px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600">CASH OFF</span>
                        <?php if(!empty($row['appeal_deadline'])): ?>
                        <br><span class="text-[8px] text-sky-500 font-semibold">Appeal until <?php echo date('M d H:i',strtotime($row['appeal_deadline'])); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if($row['role']==='worker' && $row['can_accept_bookings']==0): ?>
                        <br><span class="mt-1 inline-block px-2 py-0.5 rounded-full text-[9px] font-bold bg-orange-100 dark:bg-orange-900/30 text-orange-600">BOOKINGS BLOCKED</span>
                        <?php endif; ?>
                    </td>
                    <!-- Contact -->
                    <td class="px-4 py-4">
                        <p class="text-xs"><?php echo htmlspecialchars($row['email']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($row['phone']??'—'); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($row['municipality']??'—'); ?></p>
                    </td>
                    <!-- Status indicators -->
                    <td class="px-4 py-4 text-center">
                        <?php if($is_flagged): ?>
                        <span class="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-1 rounded-full bg-red-100 dark:bg-red-900/30 text-red-600 border border-red-200 dark:border-red-800">
                            <span class="material-icons-round" style="font-size:10px">flag</span>FLAGGED
                        </span>
                        <?php else: ?>
                        <span class="text-[9px] text-slate-400">—</span>
                        <?php endif; ?>
                        <?php if($violations > 0): ?>
                        <br>
                        <button onclick="showViolations(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['full_name']); ?>')"
                                class="mt-1 text-[9px] text-primary underline hover:no-underline">View history</button>
                        <?php endif; ?>
                    </td>
                    <!-- Actions -->
                    <td class="px-4 py-4 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">

                            <!-- Flag toggle -->
                            <a href="?toggle_flag=<?php echo $is_flagged?0:1; ?>&user_id=<?php echo $row['id']; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>"
                               onclick="return confirm('<?php echo $is_flagged?'Remove flag from':'Flag'; ?> <?php echo addslashes(htmlspecialchars($row['full_name'])); ?>?')"
                               class="p-1.5 <?php echo $is_flagged?'text-red-500 hover:text-slate-400':'text-slate-300 hover:text-red-500'; ?> transition-colors rounded-lg hover:bg-red-50 dark:hover:bg-red-900/10"
                               title="<?php echo $is_flagged?'Remove flag':'Flag user'; ?>">
                                <span class="material-icons-round text-base">flag</span>
                            </a>

                            <!-- Cash toggle (clients) -->
                            <?php if($row['role']==='client'):
                                $cOn=$row['cash_enabled']==1;
                                $cUrl="?toggle_cash=".($cOn?0:1)."&user_id={$row['id']}&search=".urlencode($search)."&role=".urlencode($role_filter);
                            ?>
                            <a href="<?php echo $cUrl; ?>"
                               onclick="return confirm('<?php echo $cOn?'Disable':'Enable'; ?> cash for <?php echo addslashes(htmlspecialchars($row['full_name'])); ?>?')"
                               class="p-1.5 <?php echo $cOn?'text-emerald-500 hover:text-red-500':'text-slate-300 hover:text-emerald-500'; ?> transition-colors rounded-lg"
                               title="<?php echo $cOn?'Disable Cash':'Enable Cash'; ?>">
                                <span class="material-icons-round text-base"><?php echo $cOn?'payments':'money_off'; ?></span>
                            </a>
                            <?php endif; ?>

                            <!-- Booking toggle (workers) -->
                            <?php if($row['role']==='worker'):
                                $bOn=$row['can_accept_bookings']==1;
                                $bUrl="?toggle_accept=".($bOn?0:1)."&user_id={$row['id']}&search=".urlencode($search)."&role=".urlencode($role_filter);
                            ?>
                            <a href="<?php echo $bUrl; ?>"
                               onclick="return confirm('<?php echo $bOn?'Block':'Unblock'; ?> bookings for <?php echo addslashes(htmlspecialchars($row['full_name'])); ?>?')"
                               class="p-1.5 <?php echo $bOn?'text-emerald-500 hover:text-orange-500':'text-orange-400 hover:text-emerald-500'; ?> transition-colors rounded-lg"
                               title="<?php echo $bOn?'Block bookings':'Allow bookings'; ?>">
                                <span class="material-icons-round text-base"><?php echo $bOn?'block':'check_circle'; ?></span>
                            </a>
                            <?php endif; ?>

                            <!-- View reports -->
                            <a href="reports.php?search=<?php echo urlencode($row['email']); ?>"
                               class="p-1.5 text-slate-300 hover:text-primary transition-colors rounded-lg"
                               title="View reports involving this user">
                                <span class="material-icons-round text-base">flag_circle</span>
                            </a>

                            <!-- Delete -->
                            <a href="?delete_id=<?php echo $row['id']; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>"
                               onclick="return confirm('⚠️ DANGER: Delete <?php echo addslashes(htmlspecialchars($row['full_name'])); ?>? This cannot be undone.')"
                               class="p-1.5 text-red-300 hover:text-red-600 transition-colors rounded-lg"
                               title="Delete user">
                                <span class="material-icons-round text-base">delete_outline</span>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5" class="px-8 py-12 text-center text-slate-400">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-slate-50 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                Showing <?php echo $total_users; ?> user<?php echo $total_users!=1?'s':''; ?>
                <?php if($flagged_count>0): ?>
                · <span class="text-red-500"><?php echo $flagged_count; ?> flagged</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
</main>

<script>
function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className=`show ${type}`;setTimeout(()=>{t.className=type;},3500);}

// ── Violation history modal ─────────────────────────────────
async function showViolations(userId, name){
    document.getElementById('vio-modal-title').textContent = name + ' — Violation History';
    document.getElementById('vio-modal-body').innerHTML = '<p class="text-slate-400 text-sm text-center py-4">Loading…</p>';
    document.getElementById('vio-modal').classList.remove('hidden');

    const res  = await fetch(`get_violations.php?user_id=${userId}`);
    const data = await res.json();

    if (!data.length) {
        document.getElementById('vio-modal-body').innerHTML = '<p class="text-slate-400 text-sm text-center py-4">No violation records found.</p>';
        return;
    }

    const actionLabels={
        'warning':'⚠️ Warning issued',
        'booking_blocked':'🚫 Booking access blocked',
        'account_deleted':'💀 Account terminated',
        'cash_restricted':'💳 Cash payment restricted',
        'cash_restriction_lifted':'✅ Cash restriction lifted',
    };
    const colors={
        'warning':'text-amber-600','booking_blocked':'text-orange-600',
        'account_deleted':'text-red-600','cash_restricted':'text-red-500',
        'cash_restriction_lifted':'text-emerald-600',
    };

    let html='<div class="space-y-3">';
    data.forEach(vl=>{
        const label=actionLabels[vl.action_taken]||vl.action_taken;
        const color=colors[vl.action_taken]||'text-slate-500';
        html+=`<div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700">
            <div class="flex justify-between items-start mb-1">
                <span class="text-sm font-bold ${color}">${label}</span>
                <span class="text-[9px] text-slate-400">${vl.created_at}</span>
            </div>
            <p class="text-[11px] text-slate-500">Report #${vl.report_id} (${vl.report_type}) · Violation #${vl.violation_num}</p>
            ${vl.notes?`<p class="text-[11px] text-slate-400 mt-1">${escHtml(vl.notes)}</p>`:''}
        </div>`;
    });
    html+='</div>';
    document.getElementById('vio-modal-body').innerHTML=html;
}

function escHtml(str){return str.replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]));}

// Auto-dismiss flash messages
setTimeout(()=>{const m=document.querySelector('.fixed.bottom-24');if(m)m.remove();},3000);
</script>
</body>
</html>