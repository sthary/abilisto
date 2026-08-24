<?php
// navbar.php - REVAMPED VERSION
// Features:
// - Desktop: "More" dropdown with Profile, Settings, Language switcher, Theme toggle, Logout
// - Mobile: Bottom nav + hamburger icon that opens a sidebar with the same options
// - Persistent theme preference (light/dark) saved in localStorage

// 1. Get Current Page for "Active" highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// 2. Define Menu Items based on Role
$menu_items = [];

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'worker') {
        $menu_items = [
            ['title' => 'Jobs',          'url' => '../worker/dashboard.php',   'icon' => 'fa-briefcase', 'page' => 'dashboard.php'],
            ['title' => 'Wallet',        'url' => '../worker/wallet.php',      'icon' => 'fa-wallet',    'page' => 'wallet.php'],
            ['title' => 'Notifications', 'url' => '../includes/notifications.php', 'icon' => 'fa-bell', 'page' => 'notifications.php'],
        ];
        $home_link = "../worker/dashboard.php";
    } else {
        // Client Menu
        require_once __DIR__ . '/functions/feature_flags.php';
        $menu_items = [
    ['title' => 'Home',          'url' => '../client/dashboard.php',        'icon' => 'fa-home',           'page' => 'dashboard.php'],
    ['title' => 'We Map',        'url' => '../client/we_map.php',           'icon' => 'fa-map-marker-alt', 'page' => 'we_map.php',           'feature' => 'feature_wemap_enabled'],
    ['title' => 'Quick Match',   'url' => '../client/quick_match.php',      'icon' => 'fa-bolt',           'page' => 'quick_match.php',      'feature' => 'feature_quickmatch_enabled'],
    ['title' => 'Bookings',      'url' => '../client/my_bookings.php',      'icon' => 'fa-calendar',       'page' => 'my_bookings.php'],
    ['title' => 'GreenLoop',     'url' => '../greenloop/greenloop_report.php', 'icon' => 'fa-leaf',        'page' => 'greenloop_report.php', 'feature' => 'feature_greenloop_enabled'],
    ['title' => 'Notifications', 'url' => '../includes/notifications.php',  'icon' => 'fa-bell',           'page' => 'notifications.php'],
];
        if (isset($conn)) {
            $menu_items = array_values(array_filter($menu_items, function ($item) use ($conn) {
                return !isset($item['feature']) || isFeatureEnabled($conn, $item['feature']);
            }));
        }
        $home_link = "../client/dashboard.php";
    }
} else {
    $home_link = "index.php";
}

// Language helper (will be used in More dropdown)
$current_lang = $_SESSION['lang'] ?? 'en';
?>

<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
<!-- Material Symbols (optional, for logo) -->
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

<style>
    /* ---------- RESET / BASE ---------- */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        padding-top: 80px; /* desktop fixed nav space */
        transition: background-color 0.2s, color 0.2s;
    }
    /* dark mode base (will be complemented by .dark class) */
    .dark body { background: #0f172a; color: #f1f5f9; }

    /* ---------- DESKTOP NAV (fixed, glass) ---------- */
    .desktop-nav {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        padding: 16px 5px;
        background: transparent;
    }
    .nav-container {
        max-width: 1280px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 60px;
        padding: 12px 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    }
    .dark .nav-container {
        background: rgba(15, 23, 42, 0.8);
        border-color: rgba(51, 65, 85, 0.5);
    }

    /* Logo */
    .nav-logo {
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .logo-icon {
        width: 32px; height: 32px;
        background: #146af5;
        border-radius: 10px;
        display: flex;
        align-items: center; justify-content: center;
        color: white;
    }
    .logo-icon span { font-size: 20px; }
    .logo-text {
        font-size: 1.25rem; font-weight: 700; letter-spacing: -0.02em;
        color: #0f172a;
    }
    .dark .logo-text { color: white; }
    .logo-text span { color: #146af5; }

    /* Main menu (for logged-in) */
    .user-menu {
        display: flex;
        align-items: center;
        gap: 32px;
    }
    .user-menu-item {
        font-size: 0.95rem; font-weight: 500;
        color: #64748b; /* Changed from #334155 to gray */
        text-decoration: none;
        transition: color 0.2s;
        position: relative;
    }
    .user-menu-item:hover { color: #146af5; }
    .user-menu-item.active {
        color: #146af5; font-weight: 600;
    }
    .user-menu-item.active::after {
        content: ''; position: absolute; bottom: -4px; left: 0; right: 0;
        height: 2px; background: #146af5; border-radius: 2px;
    }
    .dark .user-menu-item { color: #94a3b8; } /* Lighter gray for dark mode */
    .dark .user-menu-item:hover,
    .dark .user-menu-item.active { color: #146af5; }

    /* Special styling for Quick Match */
    .user-menu-item.quick-match {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 600;
    }
    .user-menu-item.quick-match:hover {
        opacity: 0.8;
    }
    .user-menu-item.quick-match.active {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .user-menu-item.quick-match.active::after {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
    }

    /* Right side actions (desktop) */
    .nav-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* --- MORE DROPDOWN (desktop) --- */
    .more-dropdown-container {
        position: relative;
    }
    .more-btn {
        background: transparent;
        border: none;
        padding: 8px 16px;
        font-size: 0.95rem;
        font-weight: 500;
        color: #64748b; /* Changed to gray */
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        border-radius: 40px;
        transition: background 0.2s;
    }
    .more-btn i { font-size: 1rem; }
    .more-btn:hover { background: rgba(20, 106, 245, 0.08); color: #146af5; }
    .dark .more-btn { color: #94a3b8; } /* Lighter gray for dark mode */
    .dark .more-btn:hover { background: rgba(255,255,255,0.1); color: #146af5; }

    .more-dropdown-menu {
        position: absolute;
        top: calc(100% + 12px);
        right: 0;
        width: 220px;
        background: white;
        border-radius: 24px;
        padding: 8px;
        box-shadow: 0 20px 35px -8px rgba(0,0,0,0.15);
        border: 1px solid rgba(0,0,0,0.05);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-8px);
        transition: all 0.2s ease;
        z-index: 1001;
    }
    .more-dropdown-container.open .more-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .dark .more-dropdown-menu {
        background: #1e293b;
        border-color: #334155;
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-radius: 16px;
        color: #334155;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        transition: background 0.15s;
        width: 100%;
        border: none;
        background: transparent;
        cursor: pointer;
    }
    .dropdown-item i { width: 20px; font-size: 1rem; color: #64748b; }
    .dropdown-item:hover { background: #f1f5f9; }
    .dark .dropdown-item { color: #e2e8f0; }
    .dark .dropdown-item i { color: #94a3b8; }
    .dark .dropdown-item:hover { background: #2d3a4f; }

    /* divider */
    .dropdown-divider {
        height: 1px;
        background: #e2e8f0;
        margin: 8px;
    }
    .dark .dropdown-divider { background: #334155; }

    /* language inside dropdown as inline buttons */
    .lang-inline {
        display: flex;
        gap: 8px;
        padding: 8px 16px;
    }
    .lang-option {
        flex: 1;
        text-align: center;
        padding: 6px 12px;
        border-radius: 30px;
        background: #f1f5f9;
        color: #0f172a;
        font-weight: 500;
        font-size: 0.85rem;
        text-decoration: none;
    }
    .lang-option.active {
        background: #146af5;
        color: white;
    }
    .dark .lang-option {
        background: #2d3a4f;
        color: #e2e8f0;
    }
    .dark .lang-option.active {
        background: #146af5;
        color: white;
    }

    /* theme toggle switch */
    .theme-toggle-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
    }
    .theme-toggle-switch {
        width: 48px;
        height: 24px;
        background: #cbd5e1;
        border-radius: 40px;
        position: relative;
        cursor: pointer;
        transition: background 0.2s;
    }
    .theme-toggle-switch::after {
        content: '🌙';
        position: absolute;
        width: 20px; height: 20px;
        background: white;
        border-radius: 50%;
        top: 2px; left: 2px;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px;
        transition: transform 0.2s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .dark .theme-toggle-switch {
        background: #146af5;
    }
    .dark .theme-toggle-switch::after {
        content: '☀️';
        transform: translateX(24px);
    }

    /* ---------- MOBILE BOTTOM NAV ---------- */
    .mobile-bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(12px);
        border-top: 1px solid rgba(0, 0, 0, 0.05);
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 8px 0 16px;
        z-index: 1000;
        padding-bottom: max(16px, env(safe-area-inset-bottom));
        box-shadow: 0 -4px 20px rgba(0,0,0,0.03);
    }
    .dark .mobile-bottom-nav {
        background: rgba(15, 23, 42, 0.9);
        border-top-color: rgba(255,255,255,0.05);
    }
    .nav-item-mobile {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: #64748b; /* Changed to gray for all items */
        text-decoration: none;
        font-size: 0.7rem;
        flex: 1;
        padding: 8px 4px;
        gap: 4px;
    }
    .nav-item-mobile i { font-size: 1.4rem; }
    .nav-item-mobile.active { color: #146af5; }
    .nav-item-mobile.active i { transform: translateY(-2px); }
    
    /* Quick Match special styling in mobile */
    .nav-item-mobile.quick-match-item {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 600;
    }
    .nav-item-mobile.quick-match-item i {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    /* Remove special home styling - now gray like others */
    .nav-item-mobile.home-item { 
        color: #64748b; /* Same gray as others */
    }
    .dark .nav-item-mobile { color: #94a3b8; }
    .dark .nav-item-mobile.active,
    .dark .nav-item-mobile.active { color: #146af5; }
    .dark .nav-item-mobile.quick-match-item,
    .dark .nav-item-mobile.quick-match-item i {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    /* Mobile hamburger icon (only appears if logged in) */
    .mobile-hamburger {
        flex: 0 0 auto;
        padding: 8px 16px;
        font-size: 1.8rem;
        color: #64748b; /* Changed to gray */
        cursor: pointer;
    }

    /* ---------- MOBILE SIDEBAR (drawer) ---------- */
    .mobile-sidebar {
        position: fixed;
        top: 0;
        right: -300px;
        width: 280px;
        height: 100vh;
        background: white;
        z-index: 2000;
        padding: 24px 20px;
        box-shadow: -10px 0 30px rgba(0,0,0,0.1);
        transition: right 0.3s ease;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .dark .mobile-sidebar {
        background: #1e293b;
        border-left: 1px solid #334155;
    }
    .mobile-sidebar.open { right: 0; }
    .sidebar-overlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.3);
        z-index: 1999;
        opacity: 0;
        visibility: hidden;
        transition: 0.2s;
    }
    .sidebar-overlay.open { opacity: 1; visibility: visible; }

    .sidebar-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 16px;
        border-radius: 20px;
        color: #334155;
        text-decoration: none;
        font-weight: 500;
    }
    .dark .sidebar-item { color: #e2e8f0; }
    .sidebar-item i { width: 24px; color: #64748b; }
    .sidebar-item:hover { background: #f1f5f9; }
    .dark .sidebar-item:hover { background: #2d3a4f; }

    /* language inline in sidebar */
    .sidebar-lang {
        display: flex;
        gap: 12px;
        padding: 8px 16px;
    }
    .sidebar-lang .lang-option { flex: 1; }

    /* theme toggle inside sidebar */
    .sidebar-theme {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px;
    }

    /* responsive */
    @media (max-width: 768px) {
        .desktop-nav { display: none !important; }
        .mobile-bottom-nav { display: flex !important; }
        body { padding-top: 0; padding-bottom: 80px; }
    }
    @media (min-width: 769px) {
        .desktop-nav { display: block !important; }
        .mobile-bottom-nav { display: none !important; }
        body { padding-top: 100px; }
    }
</style>

<!-- DESKTOP NAVBAR -->
<nav class="desktop-nav">
    <div class="nav-container">
        <!-- Logo -->
<a href="<?php echo $home_link; ?>" class="nav-logo">
    <img src="/1.png" alt="Abilisto Logo" style="width:32px;height:32px;border-radius:10px;object-fit:cover;">
    <span class="logo-text">Abi<span>listo</span></span>
</a>

        <?php if (isset($_SESSION['user_id'])): ?>
            <!-- Logged-in Menu Items (Jobs, Wallet, etc) -->
            <div class="user-menu">
                <?php foreach ($menu_items as $item): ?>
                    <?php 
                    $is_quick_match = ($item['title'] === 'Quick Match');
                    $additional_class = '';
                    if ($is_quick_match) {
                        $additional_class = 'quick-match';
                    }
                    ?>
                    <a href="<?php echo $item['url']; ?>" class="user-menu-item <?php echo $additional_class; ?> <?php echo ($current_page == $item['page']) ? 'active' : ''; ?>">
                        <?php echo $item['title']; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Right side: MORE dropdown + (optionally no separate lang/logout) -->
            <div class="nav-actions">
                <div class="more-dropdown-container" id="moreDropdownDesktop">
                    <button class="more-btn" id="moreBtnDesktop">
                        <i class="fa-solid fa-ellipsis-vertical"></i> More
                    </button>
                    <div class="more-dropdown-menu">
                        <!-- Profile -->
                        <a href="<?php echo ($_SESSION['role']=='worker')?'../worker/profile_edit.php':'../client/profile.php'; ?>" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> Profile
                        </a>
                        <!-- Settings (placeholder) -->
                        <a href="../settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                        
                        <!-- Language inline 
                        <div class="lang-inline">
                            <a href="?lang=en" class="lang-option <?php echo ($current_lang=='en')?'active':''; ?>">EN</a>
                        <a href="?lang=tl" class="lang-option <?php echo ($current_lang=='tl')?'active':''; ?>">TL</a>
                        </div> -->

                        <!-- Theme Toggle -->
                        <div class="theme-toggle-item">
                            <span><i class="fa-solid fa-circle-half-stroke"></i> Dark mode</span>
                            <div class="theme-toggle-switch" id="desktopThemeToggle"></div>
                        </div>

                        <div class="dropdown-divider"></div>

                        <!-- Logout -->
                        <a href="../auth/logout.php" class="dropdown-item">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Guest menu: unchanged -->
            <div class="nav-menu">
                <a href="#hero" class="nav-link">Services</a>
                <a href="#pricing" class="nav-link">Pricing</a>
                <a href="#social-proof" class="nav-link">Neighbors</a>
                <a href="#promise" class="nav-link">Verification</a>
            </div>
            <div class="nav-actions">
                <a href="../auth/login.php" class="btn-login">Login</a>
                <a href="../auth/signup_role.php" class="btn-signup">Signup</a>
            </div>
        <?php endif; ?>
    </div>
</nav>

<!-- MOBILE BOTTOM NAV (logged in only) -->
<?php if (isset($_SESSION['user_id'])): ?>
<div class="mobile-bottom-nav">
    <?php 
    // Show first 3 items (excluding Notifications)
    $mobile_items = array_filter($menu_items, function($item) {
        return $item['title'] !== 'Notifications';
    });
    $mobile_items = array_values($mobile_items); // Reset array keys
    
    foreach ($mobile_items as $item): 
        $is_home = ($item['title'] === 'Home');
        $is_quick_match = ($item['title'] === 'Quick Match');
        $additional_class = '';
        if ($is_quick_match) {
            $additional_class = 'quick-match-item';
        } elseif ($is_home) {
            $additional_class = 'home-item';
        }
    ?>
        <a href="<?php echo $item['url']; ?>" class="nav-item-mobile <?php echo ($current_page == $item['page']) ? 'active' : ''; ?> <?php echo $additional_class; ?>">
            <i class="fa-solid <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['title']; ?></span>
        </a>
    <?php endforeach; ?>
    <!-- More button (hamburger) -->
    <div class="nav-item-mobile" id="mobileMenuToggle">
        <i class="fa-solid fa-bars"></i>
        <span>More</span>
    </div>
</div>

<!-- Mobile Sidebar (drawer) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="mobile-sidebar" id="mobileSidebar">
    <!-- Profile -->
    <a href="<?php echo ($_SESSION['role']=='worker')?'../worker/profile_edit.php':'../client/profile.php'; ?>" class="sidebar-item">
        <i class="fa-solid fa-user"></i> Profile
    </a>
    
    <!-- Notifications (moved from bottom nav to here) -->
    <a href="../includes/notifications.php" class="sidebar-item">
        <i class="fa-solid fa-bell"></i> Notifications
    </a>
    
    <!-- Settings (placeholder) -->
    <a href="../settings.php" class="sidebar-item"><i class="fa-solid fa-gear"></i> Settings</a>
    
    <!-- Language inline 
    <div class="sidebar-lang">
        <a href="?lang=en" class="lang-option <?php echo ($current_lang=='en')?'active':''; ?>">EN</a>
        <a href="?lang=tl" class="lang-option <?php echo ($current_lang=='tl')?'active':''; ?>">TL</a>
    </div> -->

    <!-- Theme Toggle inside sidebar -->
    <div class="sidebar-theme">
        <span><i class="fa-solid fa-circle-half-stroke"></i> Dark mode</span>
        <div class="theme-toggle-switch" id="mobileThemeToggle"></div>
    </div>

    <div style="flex:1;"></div>
    <!-- Logout at bottom -->
    <a href="../auth/logout.php" class="sidebar-item">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
    </a>
</div>
<?php endif; ?>

<!-- JavaScript: dropdown toggle, sidebar, theme sync with localStorage -->
<script>
(function() {
    // ----- DESKTOP MORE DROPDOWN -----
    const desktopMore = document.getElementById('moreDropdownDesktop');
    const moreBtn = document.getElementById('moreBtnDesktop');
    if (moreBtn) {
        moreBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            desktopMore.classList.toggle('open');
        });
        // close on outside click
        document.addEventListener('click', function(e) {
            if (!desktopMore.contains(e.target)) {
                desktopMore.classList.remove('open');
            }
        });
    }

    // ----- MOBILE SIDEBAR -----
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (menuToggle && sidebar && overlay) {
        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('open');
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        }
        menuToggle.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);
    }

    // ----- THEME TOGGLE (localStorage + .dark class) -----
    const themeToggles = [
        ...(document.getElementById('desktopThemeToggle') ? [document.getElementById('desktopThemeToggle')] : []),
        ...(document.getElementById('mobileThemeToggle') ? [document.getElementById('mobileThemeToggle')] : [])
    ];

    // Initialize theme from localStorage
    const storedTheme = localStorage.getItem('theme') || 'light'; // default light
    if (storedTheme === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }

    function toggleTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        if (isDark) {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        } else {
            document.documentElement.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        }
    }

    themeToggles.forEach(toggle => {
        toggle.addEventListener('click', toggleTheme);
    });

    // Ensure both toggles reflect current state (if needed, but they are just buttons)
    // Also handle language links to preserve theme param? Not needed, they are separate.
})();
</script>

<!-- Spacer for desktop (optional) -->
<div class="d-none d-md-block" style="height: 20px;"></div>