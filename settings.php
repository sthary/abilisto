<?php
// settings.php
include 'db_connect.php';
include 'includes/init_lang.php';

// Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user data
$user_sql = "SELECT * FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// Location sharing state (workers only)
$location_sharing_enabled = ($user_role === 'worker') ? intval($user['location_sharing_enabled'] ?? 0) : 0;

// Handle location sharing AJAX toggle (workers only)
if (isset($_POST['action']) && $_POST['action'] === 'toggle_location_sharing' && $user_role === 'worker') {
    header('Content-Type: application/json');
    $enabled = intval($_POST['enabled']) === 1 ? 1 : 0;
    $upd = $conn->prepare("UPDATE users SET location_sharing_enabled = ? WHERE id = ? AND role = 'worker'");
    echo $upd->execute([$enabled, $user_id])
        ? json_encode(['success' => true,  'enabled' => $enabled])
        : json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}

// Get unread notifications count
$notif_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_sql);
$notif_stmt->execute([$user_id]);
$unread_notifications = $notif_stmt->fetch()['count'];

// Get reports count based on role
if ($user_role === 'client') {
    $reports_sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM reports 
                    WHERE client_id = ?";
} else {
    $reports_sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM reports_worker 
                    WHERE worker_id = ?";
}
$reports_stmt = $conn->prepare($reports_sql);
$reports_stmt->execute([$user_id]);
$reports_stats = $reports_stmt->fetch();

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] ?? 'light') === 'light' ? 'dark' : 'light';
    header("Location: settings.php");
    exit();
}

$current_theme = $_SESSION['theme'] ?? 'light';

// Dashboard redirect based on role
$dashboard_link = ($user_role === 'worker') ? 'worker/dashboard.php' : 'client/dashboard.php';

// Profile edit based on role
$profile_edit_link = ($user_role === 'worker') ? 'worker/profile_edit.php' : 'client/profile.php';

// Reports based on role
$reports_link = ($user_role === 'worker') ? 'worker/my_reports.php' : 'client/my_reports.php';

// Notifications is the same for both
$notifications_link = 'includes/notifications.php';

// Report a user (workers only)
$report_user_link = ($user_role === 'worker') ? 'worker/report_user.php' : '#';

// Wallet (workers only)
$wallet_link = ($user_role === 'worker') ? 'worker/wallet.php' : '#';

// Client specific links
$my_bookings_link = ($user_role === 'client') ? 'client/my_bookings.php' : '#';
$saved_workers_link = ($user_role === 'client') ? 'client/saved_workers.php' : '#';
?>

<!DOCTYPE html>
<html class="<?php echo $current_theme; ?>" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Settings & Support | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    
    <script>
        // Apply the site-wide dark-mode preference before paint — every
        // other page does this (see includes/navbar.php's theme toggle,
        // which is the only place `theme` gets written to localStorage);
        // this page just never read it back, so it always rendered light
        // regardless of what the user picked elsewhere.
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#146af5",
                        "background-light": "#f8fafc",
                        "background-dark": "#101622",
                    },
                    fontFamily: {
                        "display": ["Plus Jakarta Sans", "sans-serif"]
                    },
                    borderRadius: {
                        "DEFAULT": "1rem",
                        "lg": "1.5rem",
                        "xl": "3rem",
                        "full": "9999px"
                    },
                },
            },
        }
    </script>
    
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
        }
        .glass-card:hover {
            box-shadow: 0 20px 40px -15px rgba(20, 106, 245, 0.15);
            transform: translateY(-4px);
            border-color: rgba(20, 106, 245, 0.4);
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-slate-100 antialiased min-h-screen">

<div class="relative min-h-screen flex flex-col">

    <!-- Main Workspace -->
    <main class="flex-1 w-full max-w-[1100px] mx-auto px-6 pb-20">
        
        <!-- Breadcrumb / Back Button -->
        <div class="mb-12 mt-10">
            <a href="<?php echo $dashboard_link; ?>" class="inline-flex items-center gap-2 group text-primary font-medium tracking-wide hover:opacity-80 transition-all">
                <span class="material-symbols-outlined transition-transform group-hover:-translate-x-1">arrow_back</span>
                <span class="text-sm uppercase tracking-[0.2em] font-bold">Back to Dashboard</span>
            </a>
        </div>
        
        <!-- Page Header with User Info -->
        <div class="mb-16 flex flex-col md:flex-row md:items-end justify-between gap-6">
            <div>
                <h1 class="text-5xl md:text-6xl font-black tracking-[-0.04em] mb-4 text-slate-900 dark:text-white">
                    Settings <span class="text-primary">&amp;</span> Support
                </h1>
                <p class="text-slate-500 dark:text-slate-400 max-w-2xl text-lg leading-relaxed font-light tracking-wide">
                    Welcome back, <span class="font-semibold text-primary"><?php echo htmlspecialchars($user['full_name']); ?></span>! 
                    Configure your preferences, monitor activity, and access our premium support ecosystem.
                </p>
            </div>
            
            <!-- Quick Actions -->
            <div class="flex items-center gap-3">
                <?php if ($user_role === 'worker'): ?>
                <!-- Location Sharing Toggle (workers only) -->
                <div class="relative group/loc">
                    <button id="loc-toggle-btn"
                            onclick="handleLocToggle()"
                            title="<?php echo $location_sharing_enabled ? 'Visible on We Map' : 'Hidden from We Map'; ?>"
                            class="p-3 rounded-full border shadow-sm hover:shadow-md transition-all <?php echo $location_sharing_enabled
                                ? 'bg-primary/10 border-primary/30 text-primary'
                                : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-400 dark:text-slate-500'; ?>">
                        <span class="material-symbols-outlined" id="loc-icon" style="font-variation-settings:'FILL' <?php echo $location_sharing_enabled ? '1' : '0'; ?>">
                            <?php echo $location_sharing_enabled ? 'explore' : 'explore_off'; ?>
                        </span>
                    </button>
                    <!-- Tooltip -->
                    <div class="absolute right-0 top-full mt-2 w-max max-w-[160px] px-3 py-1.5 bg-slate-900 dark:bg-slate-700 text-white text-xs rounded-lg opacity-0 group-hover/loc:opacity-100 transition-opacity pointer-events-none z-10 text-center leading-snug">
                        <?php echo $location_sharing_enabled ? 'Visible on We Map' : 'Hidden from We Map'; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Notifications (same for both) -->
                <a href="<?php echo $notifications_link; ?>" class="relative p-3 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm hover:shadow-md transition-all">
                    <span class="material-symbols-outlined text-slate-600 dark:text-slate-300">notifications</span>
                    <?php if ($unread_notifications > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-rose-500 text-white text-xs font-bold rounded-full flex items-center justify-center animate-pulse">
                        <?php echo min($unread_notifications, 9); ?>
                    </span>
                    <?php endif; ?>
                </a>
                
                <!-- Profile (different paths for worker/client) -->
                <a href="<?php echo $profile_edit_link; ?>" class="h-12 w-12 rounded-full bg-primary/10 border border-primary/20 overflow-hidden hover:ring-4 hover:ring-primary/20 transition-all">
                    <?php if (!empty($user['profile_pic']) && file_exists("uploads/profiles/" . $user['profile_pic'])): ?>
                    <img class="w-full h-full object-cover" src="uploads/profiles/<?php echo $user['profile_pic']; ?>" alt="<?php echo htmlspecialchars($user['full_name']); ?>">
                    <?php else: ?>
                    <div class="w-full h-full bg-primary text-white flex items-center justify-center font-bold text-lg">
                        <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                    </div>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        
        <!-- Information Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            
            <!-- REPORTS CARD - Same for both roles but links to different pages -->
            <a href="<?php echo $reports_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">outlined_flag</span>
                </div>
                
                <?php if ($reports_stats['pending'] > 0): ?>
                <span class="absolute top-4 right-4 px-2 py-1 bg-amber-500 text-white text-xs font-bold rounded-full animate-pulse">
                    <?php echo $reports_stats['pending']; ?> pending
                </span>
                <?php endif; ?>
                
                <h3 class="text-xl font-bold tracking-tight mb-2">My Reports</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    <?php if ($user_role === 'client'): ?>
                        Track and manage your reports against workers. View status and admin responses.
                    <?php else: ?>
                        Monitor reports filed against you and track investigation progress.
                    <?php endif; ?>
                </p>
                
                <div class="mt-auto flex items-center justify-between">
                    <span class="text-sm text-slate-400">
                        <?php echo $reports_stats['total'] ?? 0; ?> total reports
                    </span>
                    <span class="inline-flex items-center gap-1 text-primary font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        View Reports <span class="material-symbols-outlined text-sm">chevron_right</span>
                    </span>
                </div>
            </a>
            
            <!-- REPORT A USER CARD - For workers only (to report clients) -->
            <?php if ($user_role === 'worker'): ?>
            <a href="<?php echo $report_user_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-amber-500/5 flex items-center justify-center text-amber-600">
                    <span class="material-symbols-outlined text-3xl">add_alert</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">Report a Client</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    Submit a new report against a client. Our team will investigate and respond within 24-48 hours.
                </p>
                <div class="mt-auto">
                    <span class="inline-flex items-center gap-2 text-amber-600 font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        File Report <span class="material-symbols-outlined text-sm">add</span>
                    </span>
                </div>
            </a>
            <?php endif; ?>
            
            <!-- PROFILE SETTINGS CARD - Same for both but different paths -->
            <a href="<?php echo $profile_edit_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">account_circle</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">Profile Settings</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    Update your personal information, profile picture, contact details, and account preferences.
                </p>
                <div class="mt-auto">
                    <span class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        Edit Profile <span class="material-symbols-outlined text-sm">edit</span>
                    </span>
                </div>
            </a>
            
            <!-- NOTIFICATIONS CARD - Same for both (includes/notifications.php) -->
            <a href="<?php echo $notifications_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">notifications</span>
                </div>
                <?php if ($unread_notifications > 0): ?>
                <span class="absolute top-4 right-4 px-2 py-1 bg-rose-500 text-white text-xs font-bold rounded-full">
                    <?php echo $unread_notifications; ?> new
                </span>
                <?php endif; ?>
                <h3 class="text-xl font-bold tracking-tight mb-2">Notifications</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    View your recent alerts, updates, and system notifications all in one place.
                </p>
                <div class="mt-auto">
                    <span class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        View All <span class="material-symbols-outlined text-sm">notifications_active</span>
                    </span>
                </div>
            </a>
            
            <!-- WALLET (For workers only) -->
            <?php if ($user_role === 'worker'): ?>
            <a href="<?php echo $wallet_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-green-500/5 flex items-center justify-center text-green-600">
                    <span class="material-symbols-outlined text-3xl">account_balance_wallet</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">Wallet</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    Check your balance, withdraw earnings, and view transaction history.
                </p>
                <div class="mt-auto">
                    <span class="inline-flex items-center gap-2 text-green-600 font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        View Wallet <span class="material-symbols-outlined text-sm">trending_up</span>
                    </span>
                </div>
            </a>
            <?php endif; ?>
            
            <!-- CLIENTS: My Bookings Card -->
            <?php if ($user_role === 'client'): ?>
            <a href="<?php echo $my_bookings_link; ?>" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">book_online</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">My Bookings</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    View your booking history, track ongoing services, and manage past transactions.
                </p>
                <div class="mt-auto">
                    <span class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all">
                        View Bookings <span class="material-symbols-outlined text-sm">chevron_right</span>
                    </span>
                </div>
            </a>
            <?php endif; ?>

            <!-- ABOUT CARD -->
            <div class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">info</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">About Abilisto</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    Learn about our mission to redefine the service marketplace through hyper-modern interfaces and trusted connections.
                </p>
                <div class="mt-auto">
                    <button onclick="openAboutModal()" class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] hover:gap-3 transition-all cursor-pointer">
                        Platform Info <span class="material-symbols-outlined text-sm">open_in_new</span>
                    </button>
                </div>
            </div>
            
            <!-- HELP/SUPPORT CARD -->
            <!-- HELP/SUPPORT CARD -->
<a href="support.php" class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden group" style="font-family: 'Plus Jakarta Sans', sans-serif;">
    <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
        <span class="material-symbols-outlined text-3xl">contact_support</span>
    </div>
    <h3 class="text-xl font-bold tracking-tight mb-2" style="font-family: 'Plus Jakarta Sans', sans-serif;">Help & Support</h3>
    <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide" style="font-family: 'Plus Jakarta Sans', sans-serif;">
        Direct connection to our concierge support team and extensive knowledge base.
    </p>
    <div class="mt-auto flex flex-wrap gap-4">
        <span class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] group-hover:gap-3 transition-all" style="font-family: 'Plus Jakarta Sans', sans-serif;">
            Contact Support <span class="material-symbols-outlined text-sm">support_agent</span>
        </span>
    </div>
</a>
            
            <!-- TERMS OF SERVICE CARD -->
            <div class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">description</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">Terms of Service</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    The legal framework governing your interactions and transactions on our platform.
                </p>
                <div class="mt-auto">
                    <button onclick="openTermsModal()" class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] hover:gap-3 transition-all">
                        Read Terms <span class="material-symbols-outlined text-sm">open_in_new</span>
                    </button>
                </div>
            </div>
            
            <!-- PRIVACY POLICY CARD -->
            <div class="glass-card p-8 rounded-lg border border-primary/10 flex flex-col h-full relative overflow-hidden lg:col-span-1">
                <div class="mb-8 w-14 h-14 rounded-2xl bg-primary/5 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-3xl">lock</span>
                </div>
                <h3 class="text-xl font-bold tracking-tight mb-2">Privacy Policy</h3>
                <p class="text-slate-500 text-sm leading-relaxed mb-8 tracking-wide">
                    Our commitment to data integrity and transparency. We prioritize the protection of your personal and professional information.
                </p>
                <div class="mt-auto">
                    <button onclick="openPrivacyModal()" class="inline-flex items-center gap-2 text-primary font-bold text-xs uppercase tracking-[0.15em] hover:gap-3 transition-all">
                        Review Privacy <span class="material-symbols-outlined text-sm">open_in_new</span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Footer Meta -->
        <div class="mt-24 pt-8 border-t border-slate-200 dark:border-slate-800 flex flex-col md:flex-row justify-between items-center gap-6">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold">
                © 2026 Abilisto. All rights reserved.
            </p>
            <div class="flex items-center gap-6">
                <a href="about.php" class="text-xs uppercase tracking-[0.2em] text-slate-400 font-bold hover:text-primary transition-colors">
                    About the Developer
                </a>
                <div class="flex items-center gap-2 px-3 py-1 bg-green-500/10 rounded-full">
                    <div class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></div>
                    <span class="text-[10px] uppercase font-black text-green-600 tracking-tighter">Secured</span>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- About Modal -->
<div id="aboutModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold">About Abilisto</h2>
                <button onclick="closeModal('aboutModal')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="space-y-4 text-slate-600 dark:text-slate-300">
                <p class="text-lg font-semibold text-primary">Revolutionizing Service Connections</p>
                <p>Abilisto is a hyper-modern marketplace connecting skilled workers with clients in need of quality services. Our platform leverages cutting-edge technology to ensure secure, transparent, and efficient transactions.</p>
                
                <p class="font-semibold mt-4">Our Mission</p>
                <p>To empower local communities by providing a trusted platform where skills meet opportunities, and where every transaction is protected by our comprehensive escrow system.</p>
                
                <p class="font-semibold mt-4">Key Features</p>
                <ul class="list-disc pl-5 space-y-2">
                    <li>Secure escrow payments</li>
                    <li>Real-time worker tracking</li>
                    <li>Verified worker profiles</li>
                    <li>24/7 support system</li>
                    <li>Comprehensive reporting tools</li>
                </ul>
                
                <p class="mt-6 text-sm text-slate-400">Version 2.0.0 | Released 2024</p>
            </div>
        </div>
    </div>
</div>

<!-- Support Modal -->
<div id="supportModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-2xl w-full mx-4">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold">Contact Support</h2>
                <button onclick="closeModal('supportModal')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <form action="submit_support.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Subject</label>
                    <select name="subject" class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" required>
                        <option value="">Select a topic</option>
                        <option value="technical">Technical Issue</option>
                        <option value="billing">Billing Question</option>
                        <option value="account">Account Help</option>
                        <option value="report">Report a Problem</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Message</label>
                    <textarea name="message" rows="5" class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" placeholder="Describe your issue in detail..." required></textarea>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeModal('supportModal')" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 py-3 px-4 rounded-xl bg-primary hover:bg-blue-700 text-white font-bold transition-all">
                        Send Message
                    </button>
                </div>
            </form>
            
            <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                <p class="text-sm text-slate-500 text-center">
                    Emergency? Email: <span class="font-bold text-primary">support@abilisto.com</span>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Terms Modal -->
<div id="termsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-3xl w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6 sticky top-0 bg-white dark:bg-slate-800 pb-4 border-b">
                <h2 class="text-2xl font-bold">Terms of Service</h2>
                <button onclick="closeModal('termsModal')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="space-y-4 text-sm text-slate-600 dark:text-slate-300">
                <p>Last Updated: March 2026</p>

<p class="font-semibold">1. Platform Nature</p>
<p>Abilisto is a digital marketplace that connects independent service professionals ("Workers") with individuals seeking services ("Clients"). Workers are independent contractors and are not employees, agents, or representatives of Abilisto. Abilisto does not control, supervise, or guarantee the quality, legality, or completion of services provided by Workers.</p>

<p class="font-semibold">2. Account Responsibility</p>
<p>You are responsible for maintaining the confidentiality of your account credentials and for all activities conducted under your account. You agree to provide accurate, complete, and updated information. Abilisto shall not be liable for any loss or damage resulting from unauthorized access due to your failure to safeguard your account.</p>

<p class="font-semibold">3. Payments and Fees</p>
<p>All digital payments are processed through authorized third-party payment providers. Workers agree to applicable platform service fees. Clients agree to pay the agreed service amount. Use of the optional escrow system is encouraged for transaction protection. Abilisto does not directly hold user funds except as facilitated by payment partners.</p>

<p class="font-semibold">4. Disputes and Cooperation</p>
<p>Users agree to attempt resolution through Abilisto’s internal dispute process prior to initiating legal action. Abilisto may review transaction logs, communications, and relevant records to assist in dispute handling. Where required by law, Abilisto will cooperate with competent authorities within the Republic of the Philippines.</p>

<p class="font-semibold">5. Limitation of Liability</p>
<p>To the fullest extent permitted under Philippine law, Abilisto shall not be liable for indirect, incidental, special, consequential, or punitive damages arising from the use of the platform. Abilisto’s role is limited to providing digital matchmaking services.</p>
            </div>
        </div>
    </div>
</div>

<!-- Privacy Modal -->
<div id="privacyModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-3xl w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="p-8">
            <div class="flex items-center justify-between mb-6 sticky top-0 bg-white dark:bg-slate-800 pb-4 border-b">
                <h2 class="text-2xl font-bold">Privacy Policy</h2>
                <button onclick="closeModal('privacyModal')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-full">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="space-y-4 text-sm text-slate-600 dark:text-slate-300">
                <p>Last Updated: March 2026</p>

<p>Your privacy is important to us. Abilisto processes personal data in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173) and its Implementing Rules and Regulations.</p>

<p class="font-semibold">1. Information We Collect</p>
<p>We collect personal information that you voluntarily provide when creating an account, completing your profile, submitting credentials, booking or accepting services, making payments, or communicating with us. This may include contact details, identification documents, professional certifications, transaction records, and location data necessary for service facilitation.</p>

<p class="font-semibold">2. Purpose of Processing</p>
<p>Your information is processed to verify identities, facilitate bookings, process payments, improve platform functionality, prevent fraud, enforce our Terms of Service, and comply with legal obligations. We only collect data that is necessary and proportionate for these legitimate purposes.</p>

<p class="font-semibold">3. Data Sharing</p>
<p>We do not sell, rent, or trade your personal data. Information may be shared with trusted third-party service providers (such as payment processors and hosting providers) strictly for operational purposes and subject to confidentiality and security safeguards. Data may also be disclosed when required by law or lawful order.</p>

<p class="font-semibold">4. Data Security</p>
<p>We implement reasonable and appropriate technical, organizational, and physical security measures to protect personal information against unauthorized access, disclosure, alteration, or destruction.</p>

<p class="font-semibold">5. Your Rights as a Data Subject</p>
<p>In accordance with applicable law, you have the right to access, correct, update, withdraw consent, object to processing, or request deletion of your personal data, subject to legal and contractual limitations. Requests may be submitted through our official support channels.</p>

<p class="font-semibold">6. Data Retention</p>
<p>Personal data is retained only for as long as necessary to fulfill the purposes for which it was collected, resolve disputes, enforce agreements, or comply with legal requirements.</p>
            </div>
        </div>
    </div>
</div>

<!-- Location Sharing Modals (workers only) -->
<?php if ($user_role === 'worker'): ?>
<div id="locEnableModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-sm w-full mx-4 p-8 shadow-2xl">
        <div class="flex justify-center mb-5">
            <div class="w-16 h-16 rounded-2xl bg-primary/10 flex items-center justify-center">
                <span class="material-symbols-outlined text-4xl text-primary" style="font-variation-settings:'FILL' 1">location_on</span>
            </div>
        </div>
        <h2 class="text-xl font-bold text-center mb-2">Share Your Approximate Location?</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 text-center leading-relaxed mb-4">
            Clients browsing <strong class="text-slate-700 dark:text-slate-200">We Map</strong> will see a pin near your area. Your exact address is <em>never</em> revealed.
        </p>
        <ul class="space-y-2 mb-6 text-xs text-slate-500 dark:text-slate-400">
            <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-500 text-sm">check_circle</span> Location is offset 300–600 m randomly each load</li>
            <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-500 text-sm">check_circle</span> Clients cannot zoom into street level</li>
            <li class="flex items-center gap-2"><span class="material-symbols-outlined text-green-500 text-sm">check_circle</span> You can turn this off any time</li>
        </ul>
        <div class="flex gap-3">
            <button onclick="closeModal('locEnableModal')" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-semibold hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                Cancel
            </button>
            <button onclick="confirmLocEnable()" class="flex-1 py-3 px-4 rounded-xl bg-primary hover:bg-primary/90 text-white text-sm font-bold transition-all shadow-lg shadow-primary/20">
                Yes, Share
            </button>
        </div>
    </div>
</div>

<div id="locDisableModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-sm w-full mx-4 p-8 shadow-2xl">
        <div class="flex justify-center mb-5">
            <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                <span class="material-symbols-outlined text-4xl text-slate-400">location_off</span>
            </div>
        </div>
        <h2 class="text-xl font-bold text-center mb-2">Stop Sharing Location?</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 text-center leading-relaxed mb-6">
            You'll be removed from <strong class="text-slate-700 dark:text-slate-200">We Map</strong> immediately.
        </p>
        <div class="flex gap-3">
            <button onclick="closeModal('locDisableModal')" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-semibold hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                Keep Sharing
            </button>
            <button onclick="confirmLocDisable()" class="flex-1 py-3 px-4 rounded-xl bg-rose-500 hover:bg-rose-600 text-white text-sm font-bold transition-all shadow-lg shadow-rose-500/20">
                Stop Sharing
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // Modal functions
    function openAboutModal() {
        document.getElementById('aboutModal').classList.remove('hidden');
    }
    
    function openSupportModal() {
        document.getElementById('supportModal').classList.remove('hidden');
    }
    
    function openTermsModal() {
        document.getElementById('termsModal').classList.remove('hidden');
    }
    
    function openPrivacyModal() {
        document.getElementById('privacyModal').classList.remove('hidden');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('bg-black/50')) {
            event.target.classList.add('hidden');
        }
    }
    
    // Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.fixed.inset-0.bg-black\\/50').forEach(modal => {
                modal.classList.add('hidden');
            });
        }
    });

    <?php if ($user_role === 'worker'): ?>
    // Location Sharing Toggle (workers only)
    let locEnabled = <?php echo $location_sharing_enabled ? 'true' : 'false'; ?>;

    function handleLocToggle() {
        if (!locEnabled) {
            document.getElementById('locEnableModal').classList.remove('hidden');
        } else {
            document.getElementById('locDisableModal').classList.remove('hidden');
        }
    }

    function confirmLocEnable() {
        closeModal('locEnableModal');
        sendLocToggle(1);
    }

    function confirmLocDisable() {
        closeModal('locDisableModal');
        sendLocToggle(0);
    }

    async function sendLocToggle(enable) {
        const btn  = document.getElementById('loc-toggle-btn');
        const icon = document.getElementById('loc-icon');
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');

        try {
            const form = new FormData();
            form.append('action',  'toggle_location_sharing');
            form.append('enabled', enable);
            const res  = await fetch(window.location.href, { method: 'POST', body: form });
            const data = await res.json();

            if (data.success) {
                locEnabled = enable === 1;

                if (locEnabled) {
                    btn.classList.remove('bg-white', 'dark:bg-slate-800', 'border-slate-200', 'dark:border-slate-700', 'text-slate-400', 'dark:text-slate-500');
                    btn.classList.add('bg-primary/10', 'border-primary/30', 'text-primary');
                    icon.textContent = 'explore';
                    icon.style.fontVariationSettings = "'FILL' 1";
                    btn.title = 'Visible on We Map';
                } else {
                    btn.classList.remove('bg-primary/10', 'border-primary/30', 'text-primary');
                    btn.classList.add('bg-white', 'dark:bg-slate-800', 'border-slate-200', 'dark:border-slate-700', 'text-slate-400', 'dark:text-slate-500');
                    icon.textContent = 'explore_off';
                    icon.style.fontVariationSettings = "'FILL' 0";
                    btn.title = 'Hidden from We Map';
                }

                // Update tooltip text
                const tooltip = btn.closest('.relative')?.querySelector('div');
                if (tooltip) tooltip.textContent = locEnabled ? 'Visible on We Map' : 'Hidden from We Map';
            } else {
                alert(data.message || 'Something went wrong.');
            }
        } catch (err) {
            alert('Network error. Please try again.');
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
    <?php endif; ?>
</script>

</body>
</html>