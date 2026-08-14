<?php
// admin/hr_withdrawals.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}
$hr_user_id = $_SESSION['user_id'];
$hr_stmt    = $conn->prepare("SELECT full_name FROM users WHERE id=?");
$hr_stmt->execute([$hr_user_id]);
$hr_name    = $hr_stmt->fetch()['full_name'] ?? 'HR';

// ── Handle approval/rejection ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payout'])) {
    $wid    = intval($_POST['withdrawal_id']??0);
    $wkr    = intval($_POST['worker_id']??0);
    $amt    = floatval($_POST['amount']??0);
    $status = $_POST['status']??'';
    $note   = trim($_POST['rejection_note']??'');
    $allowed= ['Processing','Completed','Rejected'];
    if (!in_array($status,$allowed)) { header("Location: hr_withdrawals.php"); exit(); }

    $upd_stmt = $conn->prepare("UPDATE withdrawals SET status=?, approved_by=?, rejection_note=? WHERE id=?");
    $upd_stmt->execute([$status, $hr_user_id, $note, $wid]);

    $msg = match($status) {
        'Completed' => "✅ Your withdrawal of ₱".number_format($amt,2)." has been processed to your GCash.",
        'Rejected'  => "⚠️ Your withdrawal of ₱".number_format($amt,2)." was rejected. Reason: $note",
        default     => "🔄 Your withdrawal of ₱".number_format($amt,2)." is being processed.",
    };
    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id,message,link) VALUES (?,?,?)");
    $notif_stmt->execute([$wkr, $msg, '../worker/wallet.php']);

    header("Location: hr_withdrawals.php?msg=updated"); exit();
}

// ── Filters ─────────────────────────────────────────────────
$search   = trim($_GET['search']  ?? '');
$status_f = trim($_GET['status']  ?? '');
$date_from= trim($_GET['date_from']?? '');
$date_to  = trim($_GET['date_to']  ?? '');
$page     = max(1,intval($_GET['page']??1));
$per_page = 20; $offset = ($page-1)*$per_page;

$where = "WHERE 1=1";
$params = [];
if ($search)    { $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR w.gcash_number LIKE ?)"; $like="%$search%"; $params[]=$like; $params[]=$like; $params[]=$like; }
if ($status_f)  { $where .= " AND w.status=?"; $params[] = $status_f; }
if ($date_from) { $where .= " AND DATE(w.request_date)>=?"; $params[] = $date_from; }
if ($date_to)   { $where .= " AND DATE(w.request_date)<=?"; $params[] = $date_to; }

$stats_stmt = $conn->prepare("SELECT
    COUNT(*) as total,
    SUM(w.amount) as total_requested,
    SUM(CASE WHEN w.status IN ('Completed') THEN w.amount ELSE 0 END) as paid_out,
    SUM(CASE WHEN w.status='Pending' THEN w.amount ELSE 0 END) as pending_amt,
    COUNT(CASE WHEN w.status='Pending' THEN 1 END) as pending_cnt
FROM withdrawals w LEFT JOIN users u ON u.id=w.worker_id $where");
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch();

$count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM withdrawals w LEFT JOIN users u ON u.id=w.worker_id $where");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetch()['c'];
$total_pages = ceil($total_rows/$per_page);

$rows_stmt = $conn->prepare("SELECT w.*, u.full_name, u.email, u.profile_pic, wp.wallet_balance, wp.verification_status
    FROM withdrawals w
    LEFT JOIN users u ON u.id=w.worker_id
    LEFT JOIN worker_profiles wp ON wp.user_id=w.worker_id
    $where
    ORDER BY CASE WHEN w.status='Pending' THEN 0 ELSE 1 END, w.request_date DESC
    LIMIT ? OFFSET ?");
$rows_stmt->execute(array_merge($params, [$per_page, $offset]));
$withdrawal_rows = $rows_stmt->fetchAll();

$current_date = date('M d, Y');
function getUserAvatar($pic,$name){if(!empty($pic)&&file_exists("../uploads/profiles/".$pic))return"../uploads/profiles/".$pic;return"https://ui-avatars.com/api/?name=".urlencode($name)."&background=8B5CF6&color=fff&size=128&bold=true";}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — Worker Withdrawals</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{hr:"#8B5CF6",equity:"#10B981",danger:"#F43F5E",expense:"#F59E0B"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],body:["Plus Jakarta Sans","sans-serif"]}}}};
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
    .fin-table tbody tr.pending-row{background:rgba(245,158,11,0.04);}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-pending{background:rgba(245,158,11,.12);color:#D97706;border:1px solid rgba(245,158,11,.2);}
    .badge-processing{background:rgba(14,165,233,.12);color:#0EA5E9;border:1px solid rgba(14,165,233,.2);}
    .badge-completed{background:rgba(16,185,129,.12);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .badge-rejected{background:rgba(244,63,94,.12);color:#F43F5E;border:1px solid rgba(244,63,94,.2);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}#toast.success{background:#10B981;color:white;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>
<div id="toast"></div>

<!-- Action Modal -->
<div id="action-overlay" class="hidden fixed inset-0 bg-black/40 z-[70] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex justify-between">
            <div><h3 class="font-display font-bold text-sm" id="modal-title">Process Withdrawal</h3>
            <p class="text-[10px] text-slate-400" id="modal-sub">Worker withdrawal request</p></div>
            <button onclick="closeModal()" class="p-1.5 text-slate-400 rounded-lg"><span class="material-icons-round">close</span></button>
        </div>
        <form method="POST" class="p-6 space-y-4" onsubmit="return confirm('Process this withdrawal?')">
            <input type="hidden" name="update_payout" value="1">
            <input type="hidden" name="withdrawal_id" id="modal-wid">
            <input type="hidden" name="worker_id"     id="modal-uid">
            <input type="hidden" name="amount"        id="modal-amt">
            <div class="bg-slate-50 dark:bg-slate-800/50 rounded-xl p-4 space-y-2">
                <div class="flex justify-between"><span class="text-xs text-slate-400">Worker</span><span class="text-xs font-bold" id="modal-name"></span></div>
                <div class="flex justify-between"><span class="text-xs text-slate-400">Amount</span><span class="text-sm font-bold text-danger" id="modal-amount"></span></div>
                <div class="flex justify-between"><span class="text-xs text-slate-400">GCash</span><span class="text-xs font-mono" id="modal-gcash"></span></div>
                <div class="flex justify-between"><span class="text-xs text-slate-400">Wallet Balance</span><span class="text-xs font-bold text-equity" id="modal-balance"></span></div>
            </div>
            <div>
                <label class="hr-label">Update Status</label>
                <select name="status" class="hr-input" id="modal-status-select" onchange="toggleNote()">
                    <option value="Processing">Mark as Processing</option>
                    <option value="Completed">Mark as Completed ✓</option>
                    <option value="Rejected">Reject ✗</option>
                </select>
            </div>
            <div id="rejection-note-wrap" class="hidden">
                <label class="hr-label">Rejection Reason <span class="text-danger">*</span></label>
                <textarea name="rejection_note" class="hr-input resize-none" rows="2" placeholder="Explain why this is rejected…"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeModal()" class="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 text-slate-500 text-xs font-bold rounded-xl hover:opacity-80">Cancel</button>
                <button type="submit" class="flex-1 py-2.5 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90">Confirm</button>
            </div>
        </form>
    </div>
</div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()"><span class="material-icons-round">menu</span></button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Worker Withdrawals</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Approve · Process · Reject</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400"><span class="material-icons-round text-xl">dark_mode</span></button>
            <div class="hidden sm:block text-right ml-2"><p class="text-[10px] font-bold"><?php echo $current_date; ?></p></div>
        </div>
    </header>

    <?php if(isset($_GET['msg'])&&$_GET['msg']==='updated'): ?><script>document.addEventListener('DOMContentLoaded',()=>showToast('Withdrawal status updated.','success'));</script><?php endif; ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php $kpis=[['Total Requests',number_format($stats['total']),'receipt','text-hr'],['Total Requested','₱'.number_format($stats['total_requested'],2),'account_balance_wallet','text-slate-600 dark:text-slate-300'],['Paid Out','₱'.number_format($stats['paid_out'],2),'check_circle','text-equity'],['Pending','₱'.number_format($stats['pending_amt'],2).' ('.$stats['pending_cnt'].')','pending_actions','text-expense']];
        foreach($kpis as $i=>$k): ?>
        <div class="glass-card rounded-2xl p-4">
            <div class="flex items-center gap-2 mb-2"><span class="material-icons-round <?php echo $k[3]; ?> text-lg"><?php echo $k[2]; ?></span><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?php echo $k[0]; ?></p></div>
            <p class="font-display text-xl font-bold <?php echo $k[3]; ?>"><?php echo $k[1]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div><label class="hr-label">Search</label><input type="text" name="search" class="hr-input" placeholder="Name, email, GCash…" value="<?php echo htmlspecialchars($search); ?>"></div>
            <div><label class="hr-label">From</label><input type="date" name="date_from" class="hr-input" value="<?php echo $date_from; ?>"></div>
            <div><label class="hr-label">To</label><input type="date" name="date_to" class="hr-input" value="<?php echo $date_to; ?>"></div>
            <div><label class="hr-label">Status</label>
                <select name="status" class="hr-input">
                    <option value="">All</option>
                    <?php foreach(['Pending','Processing','Completed','Rejected'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_f===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 flex items-center justify-center gap-1"><span class="material-icons-round text-sm">filter_list</span>Filter</button>
                <a href="hr_withdrawals.php" class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-500 text-xs font-bold rounded-xl hover:opacity-80 flex items-center justify-center gap-1"><span class="material-icons-round text-sm">clear</span>Reset</a>
            </div>
            <div class="sm:col-span-2 lg:col-span-5"><span class="text-[10px] text-slate-400"><?php echo number_format($total_rows); ?> result<?php echo $total_rows!=1?'s':''; ?></span></div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden mb-6">
        <div class="overflow-x-auto slim-scroll">
            <table class="fin-table w-full">
                <thead><tr>
                    <th class="text-left">Worker</th>
                    <th class="text-right">Amount</th>
                    <th class="text-left">GCash No.</th>
                    <th class="text-right">Wallet Balance</th>
                    <th class="text-left">Status</th>
                    <th class="text-left">Requested</th>
                    <th class="text-right">Action</th>
                </tr></thead>
                <tbody>
                <?php if(count($withdrawal_rows)>0): foreach($withdrawal_rows as $r):
                    $sl=strtolower($r['status']);
                    $is_pending=$r['status']==='Pending';
                ?>
                <tr class="<?php echo $is_pending?'pending-row':''; ?>">
                    <td>
                        <div class="flex items-center gap-3">
                            <img src="<?php echo getUserAvatar($r['profile_pic'],$r['full_name']); ?>" class="w-9 h-9 rounded-full object-cover flex-shrink-0" alt="">
                            <div>
                                <p class="font-semibold text-[12px]"><?php echo htmlspecialchars($r['full_name']??'—'); ?></p>
                                <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($r['email']??'—'); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="text-right font-bold font-mono text-danger">₱<?php echo number_format($r['amount'],2); ?></td>
                    <td class="font-mono text-[12px]"><?php echo htmlspecialchars($r['gcash_number']); ?></td>
                    <td class="text-right font-mono text-[12px] <?php echo $r['wallet_balance']>0?'text-equity':'text-slate-400'; ?>">₱<?php echo number_format($r['wallet_balance']??0,2); ?></td>
                    <td><span class="badge badge-<?php echo $sl; ?>"><?php echo $r['status']; ?></span></td>
                    <td class="text-[11px] text-slate-500 whitespace-nowrap"><?php echo date('M d, Y',strtotime($r['request_date'])); ?><br><span class="text-[10px] text-slate-400"><?php echo date('h:i A',strtotime($r['request_date'])); ?></span></td>
                    <td class="text-right">
                        <?php if($r['status']==='Pending'||$r['status']==='Processing'): ?>
                        <button onclick="openModal(<?php echo $r['id']; ?>,<?php echo $r['worker_id']; ?>,<?php echo $r['amount']; ?>,'<?php echo addslashes(htmlspecialchars($r['full_name'])); ?>','<?php echo $r['gcash_number']; ?>',<?php echo $r['wallet_balance']??0; ?>)"
                                class="px-3 py-1.5 bg-hr text-white text-[10px] font-bold rounded-lg hover:opacity-90 transition-all">
                            Process
                        </button>
                        <?php else: ?>
                        <span class="text-[10px] text-slate-400">
                            <?php echo $r['status']==='Completed'?'Paid':'Closed'; ?>
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400">
                    <span class="material-icons-round text-3xl block mb-2 opacity-30">account_balance_wallet</span>
                    No withdrawal requests found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if($total_pages>1): ?>
        <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <p class="text-[10px] text-slate-400">Page <?php echo $page; ?> of <?php echo $total_pages; ?></p>
            <div class="flex gap-2">
                <?php $base="hr_withdrawals.php?search=".urlencode($search)."&date_from=$date_from&date_to=$date_to&status=$status_f";
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
function openModal(wid,uid,amt,name,gcash,balance){
    document.getElementById('modal-wid').value=wid;
    document.getElementById('modal-uid').value=uid;
    document.getElementById('modal-amt').value=amt;
    document.getElementById('modal-name').textContent=name;
    document.getElementById('modal-amount').textContent='₱'+parseFloat(amt).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('modal-gcash').textContent=gcash;
    document.getElementById('modal-balance').textContent='₱'+parseFloat(balance).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('action-overlay').classList.remove('hidden');
}
function closeModal(){document.getElementById('action-overlay').classList.add('hidden');}
function toggleNote(){
    const s=document.getElementById('modal-status-select').value;
    document.getElementById('rejection-note-wrap').classList.toggle('hidden',s!=='Rejected');
}
</script>
</body>
</html>