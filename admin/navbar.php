<?php
// admin/navbar.php  — Support Admin edition
// Shows only: Dashboard · Bookings · System Settings · Reports · Users
// Finance/HR/Verifications/Payouts removed (handled by their own modules)

$cur = basename($_SERVER['PHP_SELF']);

// Pending reports badge
$pending_reports = 0;
if (isset($conn) && $conn) {
    $r1 = $conn->query("SELECT COUNT(*) as c FROM reports WHERE status='pending'");
    $r2 = $conn->query("SELECT COUNT(*) as c FROM reports_worker WHERE status='pending'");
    $pending_reports = ($r1?$r1->fetch_assoc()['c']:0) + ($r2?$r2->fetch_assoc()['c']:0);
}

// Flagged users badge
$flagged_users = 0;
if (isset($conn) && $conn) {
    $fu = $conn->query("SELECT COUNT(*) as c FROM users WHERE is_flagged=1");
    if ($fu) $flagged_users = (int)$fu->fetch_assoc()['c'];
}

// Support admin info
if (!isset($admin_name) && isset($_SESSION['user_id'])) {
    $admin_id  = $_SESSION['user_id'];
    $a_res     = $conn->query("SELECT full_name, profile_pic FROM users WHERE id=$admin_id");
    $a_row     = $a_res ? $a_res->fetch_assoc() : [];
    $admin_name= $a_row['full_name'] ?? 'Support';
    $admin_avatar = !empty($a_row['profile_pic']) && file_exists("../uploads/profiles/".$a_row['profile_pic'])
        ? "../uploads/profiles/".$a_row['profile_pic']
        : "https://ui-avatars.com/api/?name=".urlencode($admin_name)."&background=6366F1&color=fff&size=128&bold=true";
} else {
    $admin_avatar = "https://ui-avatars.com/api/?name=Support&background=6366F1&color=fff&size=128&bold=true";
}

function nav_item($href, $icon, $label, $cur_page, $badge=0) {
    $active = ($cur_page === basename($href));
    $cls = $active
        ? 'bg-slate-100 dark:bg-slate-800 text-primary'
        : 'text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800';
    echo '<a class="flex items-center gap-3 px-4 py-3 '.$cls.' rounded-xl font-medium transition-all" href="'.$href.'">';
    echo '<span class="material-icons-round text-xl">'.$icon.'</span>';
    echo $label;
    if ($badge > 0) echo '<span class="ml-auto min-w-[20px] h-5 px-1 bg-red-500 text-white text-[9px] rounded-full flex items-center justify-center font-bold">'.$badge.'</span>';
    echo '</a>';
}
?>

<!-- Mobile overlay -->
<div class="fixed inset-0 bg-slate-900/50 z-[60] hidden transition-opacity" id="menu-overlay" onclick="toggleMobileMenu()"></div>

<!-- Sidebar -->
<aside class="fixed top-0 left-0 w-72 h-full bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 z-[70] transition-transform duration-300 -translate-x-full lg:translate-x-0 flex flex-col" id="mobile-sidebar">

    <!-- Brand -->
    <div class="p-6 flex items-center justify-between flex-shrink-0">
        <div class="flex items-center gap-3">
            <img src="../1.png" alt="Abilisto Logo" class="w-8 h-8 rounded-lg object-cover">
            <div>
                <span class="text-base font-bold tracking-tight text-slate-800 dark:text-white">Abilisto</span>
                <p class="text-[9px] font-bold uppercase tracking-widest text-primary">Support Admin</p>
            </div>
        </div>
        <button class="lg:hidden p-2 text-slate-500" onclick="toggleMobileMenu()">
            <span class="material-icons-round">close</span>
        </button>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-4 space-y-1 overflow-y-auto">
        <p class="px-4 py-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Platform</p>
        <?php nav_item('dashboard.php','dashboard','Dashboard',$cur); ?>
        <?php nav_item('bookings.php','calendar_today','Bookings',$cur); ?>
        <?php nav_item('settings.php','settings','System Settings',$cur); ?>

        <p class="px-4 pt-4 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Trust & Safety</p>
        <?php nav_item('reports.php','flag','Reports',$cur,$pending_reports); ?>
        <?php nav_item('users.php','people_outline','Users',$cur,$flagged_users); ?>

        <p class="px-4 pt-6 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Account</p>
        <a class="flex items-center gap-3 px-4 py-3 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-all rounded-xl font-medium" href="../auth/logout.php">
            <span class="material-icons-round text-xl">logout</span>
            Logout
        </a>
    </nav>

    <!-- Admin profile chip -->
    <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex-shrink-0">
        <div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
            <img src="<?php echo $admin_avatar; ?>" class="w-8 h-8 rounded-full object-cover flex-shrink-0" alt=""/>
            <div class="min-w-0">
                <p class="text-xs font-bold truncate text-slate-800 dark:text-white"><?php echo htmlspecialchars($admin_name); ?></p>
                <p class="text-[9px] text-primary font-bold uppercase tracking-wider">Support Admin</p>
            </div>
            <button onclick="toggleDarkMode()" class="ml-auto p-1.5 text-slate-400 hover:text-primary rounded-lg transition-colors">
                <span class="material-icons-round text-base">dark_mode</span>
            </button>
        </div>
    </div>
</aside>

<!-- Mobile toggle -->
<button class="lg:hidden fixed top-4 left-4 z-50 p-2 bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 text-slate-500" onclick="toggleMobileMenu()">
    <span class="material-icons-round">menu</span>
</button>

<script>
function toggleMobileMenu(){
    document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');
    document.getElementById('menu-overlay').classList.toggle('hidden');
}
function toggleDarkMode(){
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
}
if(localStorage.getItem('darkMode') === 'true'){
    document.documentElement.classList.add('dark');
}
document.addEventListener('click',function(e){
    const menu=document.getElementById('mobile-sidebar');
    const overlay=document.getElementById('menu-overlay');
    if(menu&&!menu.classList.contains('-translate-x-full')&&!e.target.closest('[onclick="toggleMobileMenu()"]')&&!menu.contains(e.target)&&window.innerWidth<1024)toggleMobileMenu();
});
window.addEventListener('resize',function(){
    const menu=document.getElementById('mobile-sidebar');
    if(window.innerWidth>=1024)menu.classList.remove('-translate-x-full');
    else menu.classList.add('-translate-x-full');
});
</script>