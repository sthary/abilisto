<?php
// admin/settings.php
include '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../auth/login.php"); 
    exit(); 
}

// Set current user ID and IP for logging
$current_user_id = $_SESSION['user_id'];
$current_ip = $_SERVER['REMOTE_ADDR'];
$conn->query("SET @current_user_id = $current_user_id");
$conn->query("SET @current_ip = '$current_ip'");

// Get admin info for navbar
$admin_id = $_SESSION['user_id'];
$admin_sql = "SELECT full_name, profile_pic FROM users WHERE id = $admin_id";
$admin_res = $conn->query($admin_sql);
$admin = $admin_res->fetch_assoc();
$admin_name = $admin['full_name'] ?? 'Admin';
$admin_avatar = !empty($admin['profile_pic']) && file_exists("../uploads/profiles/".$admin['profile_pic']) 
    ? "../uploads/profiles/".$admin['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($admin_name) . "&background=6366F1&color=fff&size=128&bold=true";

// Handle form submissions
$message = '';
$message_type = '';

// Save Service Availability
if (isset($_POST['save_services'])) {
    $services = $_POST['services'] ?? [];
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE settings SET setting_value = '0' WHERE setting_key LIKE 'service_%'");

        foreach ($services as $raw_key => $enabled) {
            $safe_key = $conn->real_escape_string(
                str_replace(' ', '_', strtolower(trim($raw_key)))
            );
            $service_key = 'service_' . $safe_key;
            $conn->query("INSERT INTO settings (setting_key, setting_value) 
                         VALUES ('$service_key', '1') 
                         ON DUPLICATE KEY UPDATE setting_value = '1'");
        }
        $conn->commit();

        $enabled_list = implode(', ', array_keys($services));
        $details = $conn->real_escape_string("Updated service availability. Enabled: " . ($enabled_list ?: 'none'));
        $conn->query("INSERT INTO system_logs (user_id, action, details, ip_address)
                      VALUES ($current_user_id, 'update_services', '$details', '$current_ip')");

        $message = "Service availability updated!";
        $message_type = "success";
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Save Fee Settings
if (isset($_POST['save_fees'])) {
    $admin_fee = floatval($_POST['admin_fee']);
    $min_payout = floatval($_POST['min_payout']);
    
    $conn->query("UPDATE settings SET setting_value = '$admin_fee' WHERE setting_key = 'admin_fee_percentage'");
    $conn->query("UPDATE settings SET setting_value = '$min_payout' WHERE setting_key = 'min_payout_amount'");
    
    $action = "update_fees";
    $details = "Updated fee settings: admin_fee=$admin_fee%, min_payout=₱$min_payout";
    $conn->query("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES ($current_user_id, '$action', '$details', '$current_ip')");
    
    $message = "Fee settings updated successfully!";
    $message_type = "success";
}

// Clear system logs
if (isset($_POST['clear_logs'])) {
    $days = intval($_POST['clear_days']);
    
    $action = "clear_logs";
    $details = "Cleared system logs older than $days days";
    $conn->query("INSERT INTO system_logs (user_id, action, details, ip_address) VALUES ($current_user_id, '$action', '$details', '$current_ip')");
    
    $conn->query("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
    
    $message = "System logs older than $days days have been cleared!";
    $message_type = "success";
}

// Fetch current settings
$settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$default_settings = [
    'admin_fee_percentage' => '2.00',
    'min_payout_amount'    => '100'
];

foreach ($default_settings as $key => $default) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default;
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$default')");
    }
}

// Fetch unique service categories
$service_categories = [];
$skill_worker_counts = [];
$service_result = $conn->query(
    "SELECT sub_category, COUNT(DISTINCT worker_id) AS worker_count
     FROM worker_skills
     WHERE sub_category IS NOT NULL AND sub_category != ''
     GROUP BY sub_category
     ORDER BY sub_category ASC"
);
while ($row = $service_result->fetch_assoc()) {
    $service_categories[] = $row['sub_category'];
    $skill_worker_counts[$row['sub_category']] = (int)$row['worker_count'];
}

if (empty($service_categories)) {
    $service_categories = [
        'Electrical','Plumbing','Carpentry','Masonry','Welding','Construction',
        'Automotive','Motorcycle','Electronics','Aircon_Ref','Computer_Tech',
        'Domestic_Work','Caregiving','Massage','Beauty_Care',
        'Cookery','Baking','Graphic_Design','Photography','Videography','Music','Arts_Crafts','Others'
    ];
    foreach ($service_categories as $category) {
        $key = 'service_' . strtolower($category);
        if (!isset($settings[$key])) {
            $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '1')");
            $settings[$key] = '1';
        }
    }
}

$service_availability = [];
foreach ($service_categories as $category) {
    $key = 'service_' . strtolower($category);
    if (!isset($settings[$key])) {
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '1') ON DUPLICATE KEY UPDATE setting_value = setting_value");
        $settings[$key] = '1';
    }
    $service_availability[$category] = $settings[$key] ?? '1';
}

$log_query = "SELECT l.*, u.full_name FROM system_logs l 
              LEFT JOIN users u ON l.user_id = u.id 
              ORDER BY l.created_at DESC LIMIT 100";
$logs = $conn->query($log_query);

// Only two tabs now: services and fees/logs
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'services';
// Redirect old tabs
if (in_array($active_tab, ['general', 'security'])) {
    header("Location: settings.php?tab=services");
    exit();
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Abilisto Admin - Configuration</title>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        accent: "#8B5CF6",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        'xl': '16px',
                        '2xl': '24px',
                        '3xl': '32px',
                    },
                },
            },
        };
        
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
        }
        
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-sidebar');
            const overlay = document.getElementById('menu-overlay');
            menu.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
        
        function switchTab(tab) {
            window.location.href = 'settings.php?tab=' + tab;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            initToggles();
        });
        
        function initToggles() {
            const toggles = document.querySelectorAll('input[type="checkbox"].sr-only.peer');
            toggles.forEach(function(checkbox) {
                const toggleDiv = checkbox.closest('label')?.querySelector('.toggle-switch');
                if (toggleDiv) {
                    updateToggleState(checkbox, toggleDiv);
                    checkbox.addEventListener('change', function() {
                        updateToggleState(this, toggleDiv);
                    });
                }
            });
        }
        
        function updateToggleState(checkbox, toggleDiv) {
            const dot = toggleDiv.querySelector('.toggle-dot');
            if (checkbox.checked) {
                toggleDiv.classList.add('toggle-active');
                toggleDiv.classList.remove('toggle-inactive');
                if (dot) { dot.classList.add('translate-x-6'); dot.classList.remove('translate-x-1'); }
            } else {
                toggleDiv.classList.add('toggle-inactive');
                toggleDiv.classList.remove('toggle-active');
                if (dot) { dot.classList.add('translate-x-1'); dot.classList.remove('translate-x-6'); }
            }
        }
        
        document.addEventListener('click', function(e) {
            const toggleDiv = e.target.closest('.toggle-switch');
            if (toggleDiv) {
                e.preventDefault();
                const checkbox = toggleDiv.closest('label')?.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    const event = new Event('change', { bubbles: true });
                    checkbox.dispatchEvent(event);
                }
            }
        });
        
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    
    <style type="text/tailwindcss">
        @layer base {
            body { font-family: 'Plus Jakarta Sans', sans-serif; }
        }
        .glass {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.05);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .toggle-switch {
            @apply relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none cursor-pointer;
        }
        .toggle-active {
            @apply bg-primary shadow-[0_0_15px_rgba(20,106,245,0.5)];
        }
        .toggle-inactive {
            @apply bg-slate-200 dark:bg-slate-700;
        }
        .toggle-dot {
            @apply inline-block h-4 w-4 transform rounded-full bg-white transition-transform;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-[#F8FAFC] dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen">

<div class="fixed inset-0 bg-slate-900/50 z-[60] hidden transition-opacity" id="menu-overlay" onclick="toggleMobileMenu()"></div>

<?php include 'navbar.php'; ?>

<main class="lg:ml-72 min-h-screen p-6 md:p-12 relative pb-32">
    
    <!-- Header -->
    <header class="flex flex-col lg:flex-row lg:items-center justify-between mb-8 gap-6">
        <div>
            <div class="flex items-center gap-4 mb-1">
                <button class="lg:hidden p-2 glass rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                    <span class="material-icons-round">menu</span>
                </button>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Configuration</h1>
            </div>
            <p class="text-slate-500 dark:text-slate-400 font-medium tracking-wide">Manage system settings and global variables</p>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <div class="glass relative flex items-center px-4 py-2.5 rounded-2xl w-full sm:w-80 group focus-within:border-primary/50 transition-all">
                <span class="material-icons-round text-slate-400 text-xl">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-sm w-full placeholder:text-slate-400 font-medium ml-2" placeholder="Search settings..." type="text"/>
            </div>
            <button class="glass p-2.5 rounded-2xl text-slate-500 hover:text-primary transition-all" onclick="toggleDarkMode()">
                <span class="material-icons-round">dark_mode</span>
            </button>
        </div>
    </header>
    
    <!-- Tab Navigation -->
    <div class="glass flex p-1.5 rounded-2xl mb-12 overflow-x-auto whitespace-nowrap scrollbar-hide">
        <button onclick="switchTab('services')" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all <?php echo $active_tab == 'services' ? 'bg-white dark:bg-slate-800 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">Services</button>
        <button onclick="switchTab('fees')" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all <?php echo $active_tab == 'fees' ? 'bg-white dark:bg-slate-800 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">Fees &amp; Pricing</button>
        <button onclick="switchTab('logs')" class="px-6 py-2.5 rounded-xl text-sm font-bold transition-all <?php echo $active_tab == 'logs' ? 'bg-white dark:bg-slate-800 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">System Logs</button>
    </div>
    
    <!-- Message -->
    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-xl <?php echo $message_type == 'success' ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 border border-green-200 dark:border-green-800' : 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 border border-red-200 dark:border-red-800'; ?> flex items-center justify-between">
        <span><?php echo $message; ?></span>
        <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-slate-700">
            <span class="material-icons-round text-sm">close</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- ===================== SERVICES TAB ===================== -->
    <?php if ($active_tab == 'services'): ?>
    <div class="space-y-8">

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="glass-card p-5 rounded-2xl flex flex-col gap-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Users</span>
                <span class="text-2xl font-bold"><?php
                    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'");
                    echo $result->fetch_assoc()['count'];
                ?></span>
            </div>
            <div class="glass-card p-5 rounded-2xl flex flex-col gap-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Active Workers</span>
                <span class="text-2xl font-bold"><?php
                    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'worker'");
                    echo $result->fetch_assoc()['count'];
                ?></span>
            </div>
            <div class="glass-card p-5 rounded-2xl flex flex-col gap-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Total Bookings</span>
                <span class="text-2xl font-bold"><?php
                    $result = $conn->query("SELECT COUNT(*) as count FROM bookings");
                    echo $result->fetch_assoc()['count'];
                ?></span>
            </div>
            <div class="glass-card p-5 rounded-2xl flex flex-col gap-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Active Categories</span>
                <span class="text-2xl font-bold"><?php
                    $active_count = 0;
                    foreach ($service_availability as $active) { if ($active == '1') $active_count++; }
                    echo $active_count . '/' . count($service_categories);
                ?></span>
            </div>
        </div>

        <!-- Service Availability — full width, generous grid -->
        <form method="POST" class="glass-card p-8 rounded-3xl">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
                <h2 class="text-xl font-bold flex items-center gap-2">
                    <span class="material-icons-round text-primary">category</span>
                    Service Availability
                </h2>
                <span class="px-3 py-1 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 text-[10px] font-bold uppercase tracking-widest rounded-full">Quick Match Controls</span>
            </div>

            <!-- Grid: 2 cols on mobile, 3 on sm, 4 on lg, 5 on xl, 6 on 2xl -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4">
                <?php
                $icon_map = [
                    'Electrical'    => 'bolt',
                    'Plumbing'      => 'plumbing',
                    'Carpentry'     => 'carpenter',
                    'Masonry'       => 'foundation',
                    'Welding'       => 'whatshot',
                    'Construction'  => 'domain',
                    'Automotive'    => 'directions_car',
                    'Motorcycle'    => 'two_wheeler',
                    'Electronics'   => 'devices',
                    'Aircon_Ref'    => 'ac_unit',
                    'Computer_Tech' => 'computer',
                    'Domestic_Work' => 'cleaning_services',
                    'Caregiving'    => 'favorite',
                    'Massage'       => 'self_improvement',
                    'Beauty_Care'   => 'face',
                    'Cookery'       => 'restaurant',
                    'Baking'        => 'bakery_dining',
                    'Graphic_Design'=> 'palette',
                    'Photography'   => 'photo_camera',
                    'Videography'   => 'videocam',
                    'Music'         => 'music_note',
                    'Arts_Crafts'   => 'brush',
                    'Others'        => 'build',
                    'Cleaning'      => 'cleaning_services',
                    'Painting'      => 'format_paint',
                    'HVAC Repair'   => 'ac_unit',
                    'General'       => 'build',
                ];

                $label_map = [
                    'Aircon_Ref'    => 'Aircon & Ref',
                    'Computer_Tech' => 'Computer Tech',
                    'Domestic_Work' => 'Domestic Work',
                    'Beauty_Care'   => 'Beauty Care',
                    'Graphic_Design'=> 'Graphic Design',
                    'Arts_Crafts'   => 'Arts & Crafts',
                ];

                foreach ($service_categories as $category):
                    $service_key = strtolower($category);
                    $is_active   = $service_availability[$category] ?? '1';
                    $icon        = $icon_map[$category] ?? 'build';
                    $label       = $label_map[$category] ?? str_replace('_', ' ', $category);
                    $wcount      = $skill_worker_counts[$category] ?? 0;
                ?>
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800
                            flex flex-col gap-3 transition-all hover:border-primary/30
                            <?php echo $is_active == '1' ? 'hover:shadow-md' : 'opacity-70'; ?>">
                    <!-- Icon + toggle row -->
                    <div class="flex items-center justify-between">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0
                            <?php echo $is_active == '1' ? 'bg-primary/10 text-primary' : 'bg-slate-200 dark:bg-slate-700 text-slate-400'; ?>">
                            <span class="material-icons-round text-lg"><?php echo $icon; ?></span>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox"
                                   name="services[<?php echo htmlspecialchars($service_key); ?>]"
                                   class="sr-only peer"
                                   value="1"
                                   <?php echo $is_active == '1' ? 'checked' : ''; ?>>
                            <div class="toggle-switch <?php echo $is_active == '1' ? 'toggle-active' : 'toggle-inactive'; ?>">
                                <span class="toggle-dot <?php echo $is_active == '1' ? 'translate-x-6' : 'translate-x-1'; ?>"></span>
                            </div>
                        </label>
                    </div>
                    <!-- Label + worker count -->
                    <div>
                        <span class="text-sm font-bold block leading-tight"><?php echo htmlspecialchars($label); ?></span>
                        <?php if ($wcount > 0): ?>
                        <span class="text-[10px] text-slate-400"><?php echo $wcount; ?> worker<?php echo $wcount !== 1 ? 's' : ''; ?></span>
                        <?php else: ?>
                        <span class="text-[10px] text-slate-300 dark:text-slate-600">No workers yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-8 flex justify-end">
                <button type="submit" name="save_services"
                        class="bg-gradient-to-r from-primary to-blue-600 text-white px-8 py-3 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 hover:scale-[1.02] transition-all flex items-center gap-2">
                    <span class="material-icons-round">save</span>
                    Save Service Settings
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- ===================== FEES TAB ===================== -->
    <?php if ($active_tab == 'fees'): ?>
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
        <div class="xl:col-span-8 space-y-8">
            <form method="POST" class="glass-card p-8 rounded-3xl">
                <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                    <span class="material-icons-round text-primary">payments</span>
                    Fee Structure
                </h2>
                <div class="space-y-6">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3">Admin Fee %</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 material-icons-round text-slate-400 text-lg">percent</span>
                            <input class="w-full pl-12 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-primary focus:border-primary outline-none transition-all font-bold" type="number" step="0.01" min="0" max="100" name="admin_fee" value="<?php echo $settings['admin_fee_percentage']; ?>"/>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3">Min Payout Amount</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">₱</span>
                            <input class="w-full pl-10 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl focus:ring-primary focus:border-primary outline-none transition-all font-bold" type="number" step="0.01" min="0" name="min_payout" value="<?php echo $settings['min_payout_amount']; ?>"/>
                        </div>
                    </div>
                </div>
                <div class="mt-8 flex justify-end">
                    <button type="submit" name="save_fees" class="bg-gradient-to-r from-primary to-blue-600 text-white px-8 py-3 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 hover:scale-[1.02] transition-all flex items-center gap-2">
                        <span class="material-icons-round">save</span>
                        Save Fee Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ===================== LOGS TAB ===================== -->
    <?php if ($active_tab == 'logs'): ?>
    <div class="grid grid-cols-1 gap-8">
        <div class="glass-card p-8 rounded-3xl">
            <div class="flex items-center justify-between mb-8">
                <h2 class="text-xl font-bold flex items-center gap-2">
                    <span class="material-icons-round text-primary">history</span>
                    System Logs
                </h2>
                <form method="POST" class="flex items-center gap-4" onsubmit="return confirm('Clear old logs? This action cannot be undone.');">
                    <select name="clear_days" class="text-sm px-3 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl">
                        <option value="30">Last 30 days</option>
                        <option value="60">Last 60 days</option>
                        <option value="90">Last 90 days</option>
                    </select>
                    <button type="submit" name="clear_logs" class="px-4 py-2 bg-red-500 text-white rounded-xl text-sm font-bold hover:bg-red-600 transition-all">
                        Clear Old Logs
                    </button>
                </form>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800">
                            <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">Timestamp</th>
                            <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">User</th>
                            <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">Action</th>
                            <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">Details</th>
                            <th class="px-4 py-3 text-[10px] font-bold text-slate-400 uppercase">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php if ($logs && $logs->num_rows > 0): ?>
                            <?php while($log = $logs->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                <td class="px-4 py-3 text-xs"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                <td class="px-4 py-3 text-xs font-medium"><?php echo htmlspecialchars($log['full_name'] ?? 'System'); ?></td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 rounded-full text-[9px] font-bold 
                                        <?php
                                        if (strpos($log['action'], 'login') !== false) echo 'bg-blue-100 dark:bg-blue-900/30 text-blue-600';
                                        elseif (strpos($log['action'], 'update') !== false) echo 'bg-amber-100 dark:bg-amber-900/30 text-amber-600';
                                        elseif (strpos($log['action'], 'delete') !== false) echo 'bg-red-100 dark:bg-red-900/30 text-red-600';
                                        elseif (strpos($log['action'], 'add') !== false) echo 'bg-green-100 dark:bg-green-900/30 text-green-600';
                                        else echo 'bg-slate-100 dark:bg-slate-800 text-slate-600';
                                        ?>
                                    ">
                                        <?php echo $log['action']; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500"><?php echo htmlspecialchars($log['details']); ?></td>
                                <td class="px-4 py-3 text-xs font-mono"><?php echo $log['ip_address']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-slate-400">No logs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
</main>

<button class="fixed bottom-32 right-8 w-14 h-14 bg-primary text-white rounded-full shadow-2xl flex items-center justify-center hover:scale-110 active:scale-95 transition-all z-50 lg:hidden">
    <span class="material-icons-round text-2xl">settings</span>
</button>

</body>
</html>