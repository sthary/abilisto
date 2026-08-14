<?php
// admin/hr_workers.php — Platform worker management for HR
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}
$hr_user_id = $_SESSION['user_id'];
$hr_stmt    = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$hr_stmt->execute([$hr_user_id]);
$hr_name    = $hr_stmt->fetch()['full_name'] ?? 'HR';

// ── Handle toggles (accept bookings only — HR doesn't delete) ──
if (isset($_GET['toggle_accept']) && isset($_GET['user_id'])) {
    $uid = intval($_GET['user_id']); $val = intval($_GET['toggle_accept']);
    if ($val===0||$val===1) {
        $stmt = $conn->prepare("UPDATE worker_profiles SET can_accept_bookings=? WHERE user_id=?");
        $stmt->execute([$val, $uid]);
    }
    header("Location: hr_workers.php?msg=updated&".http_build_query(['search'=>$_GET['search']??'','role'=>$_GET['role']??''])); exit();
}

// ── Filters ─────────────────────────────────────────────────
$search  = trim($_GET['search']  ?? '');
$role_f  = trim($_GET['role']    ?? '');
$page    = max(1,intval($_GET['page']??1));
$per_page= 25; $offset=($page-1)*$per_page;

$where = "WHERE u.role IN ('worker','client','business')";
$params = [];
if ($search) {
    $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.id::text LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($role_f && in_array($role_f,['worker','client','business'])) {
    $where .= " AND u.role=?";
    $params[] = $role_f;
}

$count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM users u $where");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetch()['c'];
$total_pages = ceil($total_rows/$per_page);

$users_stmt = $conn->prepare("SELECT u.id,u.full_name,u.email,u.phone,u.role,u.created_at,u.municipality,u.profile_pic,
    COALESCE(u.cash_enabled,TRUE) as cash_enabled,
    COALESCE(wp.can_accept_bookings,TRUE) as can_accept_bookings,
    wp.wallet_balance, wp.verification_status, wp.jobs_completed, wp.average_rating
    FROM users u
    LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND u.role='worker'
    $where ORDER BY u.id DESC LIMIT ? OFFSET ?");
$users_stmt->execute(array_merge($params, [$per_page, $offset]));
$user_rows = $users_stmt->fetchAll();

// Stats
$stats_stmt = $conn->query("SELECT
    SUM(CASE WHEN role='worker' THEN 1 ELSE 0 END) as workers,
    SUM(CASE WHEN role='client' THEN 1 ELSE 0 END) as clients,
    SUM(CASE WHEN role='business' THEN 1 ELSE 0 END) as business,
    COUNT(*) as total FROM users WHERE role IN ('worker','client','business')");
$stats = $stats_stmt->fetch();

function getUserAvatar($pic,$name){if(!empty($pic)&&file_exists("../uploads/profiles/".$pic))return"../uploads/profiles/".$pic;return"https://ui-avatars.com/api/?name=".urlencode($name)."&background=8B5CF6&color=fff&size=128&bold=true";}
$current_date=date('M d, Y');
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — Platform Workers</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{hr:"#8B5CF6",equity:"#10B981",danger:"#F43F5E"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],body:["Plus Jakarta Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
    function toggleMobileMenu(){document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');document.getElementById('menu-overlay').classList.toggle('hidden');}
</script>
<style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#F5F3FF;} .dark body{background:#0B0F1A;}
    h1,h2,h3,.font-display{font-family:'Plus Jakarta Sans',sans-serif;}
    .glass-card{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.6);box-shadow:0 4px 24px rgba(0,0,0,0.06);}
    .dark .glass-card{background:rgba(17,24,39,0.75);border:1px solid rgba(255,255,255,0.07);}
    .hr-input{background:rgba(255,255,255,0.8);border:1.5px solid rgba(0,0,0,0.1);border-radius:10px;padding:8px 12px;font-size:13px;width:100%;color:#1e293b;}
    .dark .hr-input{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;}
    .hr-input:focus{outline:none;border-color:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,.15);}
    .hr-label{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;display:block;margin-bottom:4px;}
    .dark .hr-label{color:#94a3b8;}
    .fin-table thead th{font-family:'Plus Jakarta Sans',sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;padding:10px 14px;border-bottom:1px solid rgba(0,0,0,0.06);white-space:nowrap;}
    .dark .fin-table thead th{border-color:rgba(255,255,255,0.06);}
    .fin-table tbody td{padding:12px 14px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.04);}
    .dark .fin-table tbody td{border-color:rgba(255,255,255,0.04);}
    .fin-table tbody tr:hover{background:rgba(139,92,246,0.04);}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-worker{background:rgba(16,185,129,.1);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .badge-client{background:rgba(14,165,233,.1);color:#0EA5E9;border:1px solid rgba(14,165,233,.2);}
    .badge-business{background:rgba(139,92,246,.1);color:#8B5CF6;border:1px solid rgba(139,92,246,.2);}
    .badge-gold{background:rgba(245,158,11,.1);color:#D97706;border:1px solid rgba(245,158,11,.2);}
    .badge-silver{background:rgba(148,163,184,.1);color:#64748b;border:1px solid rgba(148,163,184,.2);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}#toast.success{background:#10B981;color:white;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>
<div id="toast"></div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()"><span class="material-icons-round">menu</span></button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Platform Workers</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">View & manage worker access</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400"><span class="material-icons-round text-xl">dark_mode</span></button>
        </div>
    </header>

    <?php if(isset($_GET['msg'])&&$_GET['msg']==='updated'): ?><script>document.addEventListener('DOMContentLoaded',()=>showToast('Worker status updated.','success'));</script><?php endif; ?>

    <!-- Stats strip -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <?php $s=[['Workers',$stats['workers']??0,'construction','text-equity'],['Clients',$stats['clients']??0,'person','text-sky-500'],['Business',$stats['business']??0,'business','text-hr']];
        foreach($s as $k): ?>
        <div class="glass-card rounded-2xl p-4 flex items-center gap-3">
            <span class="material-icons-round <?php echo $k[3]; ?> text-2xl"><?php echo $k[2]; ?></span>
            <div><p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider"><?php echo $k[0]; ?></p><p class="font-display text-xl font-bold"><?php echo number_format($k[1]); ?></p></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters + Tab pills -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[180px]">
                <label class="hr-label">Search</label>
                <input type="text" name="search" class="hr-input" placeholder="Name, email, or ID…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="flex gap-2 items-end">
                <?php foreach([''=>'All','worker'=>'Workers','client'=>'Clients','business'=>'Business'] as $v=>$l): ?>
                <a href="?role=<?php echo $v; ?><?php echo $search?"&search=".urlencode($search):''; ?>"
                   class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?php echo $role_f===$v?'bg-hr text-white':'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:opacity-80'; ?>">
                    <?php echo $l; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 flex items-center gap-1">
                <span class="material-icons-round text-sm">search</span> Search
            </button>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden mb-6">
        <div class="overflow-x-auto slim-scroll">
            <table class="fin-table w-full">
                <thead><tr>
                    <th class="text-left">User</th>
                    <th class="text-left">Role</th>
                    <th class="text-left">Contact</th>
                    <th class="text-left">Municipality</th>
                    <th class="text-right">Wallet</th>
                    <th class="text-left">Verification</th>
                    <th class="text-left">Jobs</th>
                    <th class="text-right">Actions</th>
                </tr></thead>
                <tbody>
                <?php if(count($user_rows)>0): foreach($user_rows as $r):
                    $avatar=getUserAvatar($r['profile_pic'],$r['full_name']);
                    $rbadge=['worker'=>'badge-worker','client'=>'badge-client','business'=>'badge-business'][$r['role']]??'badge-draft';
                    $vbadge=['Gold'=>'badge-gold','Silver'=>'badge-silver'][$r['verification_status']??'']??'';
                ?>
                <tr>
                    <td>
                        <div class="flex items-center gap-3">
                            <img src="<?php echo $avatar; ?>" class="w-9 h-9 rounded-full object-cover flex-shrink-0" alt="">
                            <div>
                                <p class="font-semibold text-[12px]"><?php echo htmlspecialchars($r['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400">#<?php echo $r['id']; ?> · <?php echo date('M d, Y',strtotime($r['created_at'])); ?></p>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $rbadge; ?>"><?php echo ucfirst($r['role']); ?></span>
                        <?php if($r['role']==='worker' && $r['can_accept_bookings']==0): ?>
                        <span class="badge" style="background:rgba(244,63,94,.1);color:#F43F5E;border:1px solid rgba(244,63,94,.2)">BLOCKED</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <p class="text-[11px]"><?php echo htmlspecialchars($r['email']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($r['phone']??'—'); ?></p>
                    </td>
                    <td class="text-[12px] text-slate-500"><?php echo htmlspecialchars($r['municipality']??'—'); ?></td>
                    <td class="text-right font-mono text-[12px] <?php echo ($r['wallet_balance']??0)>0?'text-equity':'text-slate-400'; ?>">
                        <?php echo $r['role']==='worker'?'₱'.number_format($r['wallet_balance']??0,2):'—'; ?>
                    </td>
                    <td>
                        <?php if($r['verification_status'] && $vbadge): ?>
                        <span class="badge <?php echo $vbadge; ?>"><?php echo $r['verification_status']; ?></span>
                        <?php elseif($r['role']==='worker'): ?>
                        <span class="text-[10px] text-slate-400"><?php echo $r['verification_status']??'Unverified'; ?></span>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td class="text-[12px] text-slate-500"><?php echo $r['role']==='worker'?($r['jobs_completed']??0).' jobs':'—'; ?></td>
                    <td class="text-right">
                        <?php if($r['role']==='worker'): 
                            $can=$r['can_accept_bookings']==1;
                            $newv=$can?0:1;
                            $tip=$can?'Block from accepting bookings':'Allow bookings again';
                            $icon=$can?'block':'check_circle';
                            $col=$can?'text-slate-400 hover:text-danger':'text-amber-400 hover:text-equity';
                        ?>
                        <a href="?toggle_accept=<?php echo $newv; ?>&user_id=<?php echo $r['id']; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_f); ?>"
                           onclick="return confirm('<?php echo addslashes($tip.' for '.htmlspecialchars($r['full_name'])); ?>?')"
                           class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold <?php echo $col; ?> border border-current/20 hover:border-current transition-all"
                           title="<?php echo $tip; ?>">
                            <span class="material-icons-round text-sm"><?php echo $icon; ?></span>
                            <?php echo $can?'Block':'Unblock'; ?>
                        </a>
                        <?php else: echo '<span class="text-[10px] text-slate-300 dark:text-slate-600">—</span>'; endif; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center py-16 text-slate-400">
                    <span class="material-icons-round text-3xl block mb-2 opacity-30">construction</span>No users found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($total_pages>1): ?>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?> · <?php echo number_format($total_rows); ?> users</p>
            <div class="flex gap-2">
                <?php $base="hr_workers.php?search=".urlencode($search)."&role=$role_f";
                for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
                <a href="<?php echo $base; ?>&page=<?php echo $p; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold <?php echo $p===$page?'bg-hr text-white':'bg-slate-100 dark:bg-slate-800 text-slate-500'; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function showToast(msg,type){const t=document.getElementById('toast');t.textContent=msg;t.className=`show ${type}`;setTimeout(()=>{t.className=type;},3500);}
</script>
</body>
</html>