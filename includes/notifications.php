<?php
// notifications.php

// 1. Safe Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../db.php'; 

// 2. Security Check
if(!isset($_SESSION['user_id'])) { 
    header("Location: ../auth/login.php"); 
    exit(); 
}

$uid = $_SESSION['user_id'];

// 3. FETCH FIRST (So we can show "Unread" state before clearing it)
// We fetch only the latest 50 to keep it fast.
$sql = "SELECT * FROM notifications WHERE user_id = '$uid' ORDER BY created_at DESC LIMIT 50";
$notifs = $conn->query($sql);

// 4. MARK AS READ (Runs in background so next refresh they are clear)
$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = '$uid' AND is_read = 0");

// Get unread count for display
$unread_count_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = '$uid' AND is_read = 0";
$unread_count_res = $conn->query($unread_count_sql);
$unread_count = $unread_count_res->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Notifications | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        "card-light": "#FFFFFF",
                        "card-dark": "#1E293B",
                    },
                    fontFamily: {
                        sans: ["Plus Jakarta Sans"],
                        display: ["Plus Jakarta Sans"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        "2xl": "24px",
                    },
                },
            },
        };
        
        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
            localStorage.setItem('darkMode', document.documentElement.classList.contains('dark'));
        }
        
        // Load dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    
    <style>
        .glass-badge {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .unread-indicator {
            position: relative;
        }
        .unread-indicator::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(to bottom, #146af5, #60A5FA);
            border-radius: 0 4px 4px 0;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 24;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark min-h-screen transition-colors duration-300 font-sans antialiased">

<!-- Include Navbar -->
<?php include '../includes/navbar.php'; ?>
<?php include '../includes/chatbot_widget.php'; ?>

<main class="max-w-4xl mx-auto px-4 py-12 md:py-20">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <h1 class="text-3xl md:text-4xl font-extrabold font-display tracking-tight text-slate-900 dark:text-white">
                    Notifications
                </h1>
                <span class="glass-badge bg-slate-200/50 dark:bg-slate-700/50 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-widest text-slate-600 dark:text-slate-400 border border-slate-300/30 dark:border-slate-600/30">
                    <?php echo $unread_count > 0 ? $unread_count . ' new' : 'Recent'; ?>
                </span>
            </div>
            <p class="text-slate-500 dark:text-slate-400 text-sm">Stay updated with your latest booking activities</p>
        </div>
        
        <!-- Mark all as read button (only shows if there are unread) -->
        <?php if ($unread_count > 0): ?>
        <a href="mark_all_read.php" class="flex items-center gap-2 text-sm font-semibold text-primary hover:opacity-80 transition-opacity">
            <span class="material-symbols-outlined text-[20px]">done_all</span>
            Mark all as read
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Notifications List -->
    <div class="bg-card-light dark:bg-card-dark rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl shadow-slate-200/50 dark:shadow-none overflow-hidden">
        
        <?php if($notifs && $notifs->num_rows > 0): ?>
            <?php while($n = $notifs->fetch_assoc()): ?>
                
                <?php 
                    // --- LOGIC: Icon & Color based on message content ---
                    $icon = 'notifications';
                    $iconBgClass = 'bg-slate-100 dark:bg-slate-700/50 text-slate-600 dark:text-slate-400';
                    $title = 'Notification';
                    
                    $msg = strtolower($n['message']);
                    
                    if (strpos($msg, 'booking') !== false || strpos($msg, 'accepted') !== false) {
                        $icon = 'calendar_today';
                        $iconBgClass = 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400';
                        $title = 'Booking Update';
                    } elseif (strpos($msg, 'completed') !== false || strpos($msg, 'success') !== false) {
                        $icon = 'check_circle';
                        $iconBgClass = 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400';
                        $title = 'Success';
                    } elseif (strpos($msg, 'rate') !== false || strpos($msg, 'review') !== false) {
                        $icon = 'star';
                        $iconBgClass = 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400';
                        $title = 'Review';
                    } elseif (strpos($msg, 'alert') !== false || strpos($msg, 'cancel') !== false || strpos($msg, 'declined') !== false) {
                        $icon = 'warning';
                        $iconBgClass = 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400';
                        $title = 'Alert';
                    } elseif (strpos($msg, 'wallet') !== false || strpos($msg, 'payment') !== false || strpos($msg, 'paid') !== false) {
                        $icon = 'account_balance_wallet';
                        $iconBgClass = 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400';
                        $title = 'Payment';
                    } elseif (strpos($msg, 'verified') !== false || strpos($msg, 'verification') !== false) {
                        $icon = 'verified';
                        $iconBgClass = 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400';
                        $title = 'Verification';
                    } elseif (strpos($msg, 'reminder') !== false || strpos($msg, 'upcoming') !== false) {
                        $icon = 'event_note';
                        $iconBgClass = 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400';
                        $title = 'Reminder';
                    }

                    // Unread class
                    $unreadClass = ($n['is_read'] == 0) ? 'unread-indicator bg-blue-50/30 dark:bg-blue-900/10 hover:bg-blue-100/40 dark:hover:bg-blue-900/20' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40';
                    
                    // Get timezone from session or default to Asia/Manila
                    $timezone = $_SESSION['timezone'] ?? 'Asia/Manila';
                    
                    // Format time ago
                    $time_ago = time_elapsed_string($n['created_at']);
                    
                    // Format full date
                    $date = new DateTime($n['created_at'], new DateTimeZone('UTC'));
                    $date->setTimezone(new DateTimeZone($timezone));
                    $full_date = $date->format('M d, Y • h:i A');
                ?>

                <a href="<?php echo htmlspecialchars($n['link']); ?>" 
                   class="relative group cursor-pointer border-b border-slate-100 dark:border-slate-800/50 <?php echo $unreadClass; ?> transition-all duration-200 block no-underline"
                   data-created="<?php echo $n['created_at']; ?>">
                    
                    <div class="p-5 md:p-6 flex items-start gap-4">
                        <!-- Icon Circle -->
                        <div class="flex-shrink-0 w-12 h-12 rounded-full <?php echo $iconBgClass; ?> flex items-center justify-center">
                            <span class="material-symbols-outlined"><?php echo $icon; ?></span>
                        </div>
                        
                        <!-- Content -->
                        <div class="flex-grow min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <h3 class="font-bold text-slate-900 dark:text-white truncate">
                                    <?php echo $title; ?>
                                    <?php if ($n['is_read'] == 0): ?>
                                        <span class="inline-block w-2 h-2 rounded-full bg-primary ml-2"></span>
                                    <?php endif; ?>
                                </h3>
                                <span class="text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap ml-4 time-ago" data-timestamp="<?php echo $n['created_at']; ?>">
                                    <?php echo $time_ago; ?>
                                </span>
                            </div>
                            
                            <p class="text-slate-600 dark:text-slate-400 text-[15px] leading-relaxed">
                                <?php echo htmlspecialchars($n['message']); ?>
                            </p>
                            
                            <p class="text-xs text-slate-400 mt-2 font-medium">
                                <?php echo $full_date; ?>
                            </p>
                        </div>
                    </div>
                </a>

            <?php endwhile; ?>
        <?php else: ?>
            <!-- Empty State -->
            <div class="text-center py-16 px-4">
                <div class="w-24 h-24 mx-auto mb-6 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center">
                    <span class="material-symbols-outlined text-4xl text-slate-400">notifications_off</span>
                </div>
                <h3 class="text-xl font-bold mb-2 text-slate-900 dark:text-white">No notifications yet</h3>
                <p class="text-slate-500 dark:text-slate-400">We'll let you know when something important happens.</p>
            </div>
        <?php endif; ?>
        
    </div>
    
    <!-- View Older Button (only shows if there are notifications) -->
    <?php if($notifs && $notifs->num_rows > 0): ?>
    <div class="mt-8 text-center">
        <button class="px-8 py-3 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-semibold text-sm hover:border-primary hover:text-primary transition-all shadow-sm">
            View Older Notifications
        </button>
    </div>
    <?php endif; ?>
    
</main>

<script>
// Real-time timer updates
function updateTimers() {
    document.querySelectorAll('[data-timestamp]').forEach(function(element) {
        const timestamp = element.getAttribute('data-timestamp');
        const timeAgo = timeElapsedString(timestamp);
        element.textContent = timeAgo;
    });
}

function timeElapsedString(datetime) {
    const now = new Date();
    const past = new Date(datetime + ' UTC'); // Treat as UTC
    const diff = Math.floor((now - past) / 1000); // seconds
    
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hour' + (Math.floor(diff / 3600) > 1 ? 's' : '') + ' ago';
    if (diff < 604800) return Math.floor(diff / 86400) + ' day' + (Math.floor(diff / 86400) > 1 ? 's' : '') + ' ago';
    
    return past.toLocaleDateString();
}

// Update timers every minute
updateTimers();
setInterval(updateTimers, 60000);
</script>

</body>
</html>

<?php
/**
 * Enhanced time elapsed function with timezone support
 */
/**
 * Enhanced time elapsed function with PHP 8.2+ Compatibility
 */
function time_elapsed_string($datetime, $full = false) {
    $timezone = new DateTimeZone('Asia/Manila');
    
    $now = new DateTime('now', $timezone);
    $ago = new DateTime($datetime, new DateTimeZone('UTC')); 
    $ago->setTimezone($timezone);
    $diff = $now->diff($ago);

    // Calculate weeks and remaining days as local variables
    $weeks = floor($diff->d / 7);
    $days = $diff->d - ($weeks * 7);

    $string = array(
        'y' => ['val' => $diff->y, 'label' => 'year'],
        'm' => ['val' => $diff->m, 'label' => 'month'],
        'w' => ['val' => (int)$weeks, 'label' => 'week'],
        'd' => ['val' => (int)$days,  'label' => 'day'],
        'h' => ['val' => $diff->h, 'label' => 'hour'],
        'i' => ['val' => $diff->i, 'label' => 'minute'],
        's' => ['val' => $diff->s, 'label' => 'second'],
    );
    
    $result = [];
    foreach ($string as $k => $v) {
        if ($v['val'] > 0) {
            $result[] = $v['val'] . ' ' . $v['label'] . ($v['val'] > 1 ? 's' : '');
        }
    }

    if (!$full) {
        $result = array_slice($result, 0, 1);
    }
    
    return $result ? implode(', ', $result) . ' ago' : 'just now';
}

// Optional: Set user's timezone in session (add this to login process)
// $_SESSION['timezone'] = 'Asia/Manila';
?>