<?php
// auth/staff_login_template.php
// Shared page chrome for the admin/finance/hr staff login pages. The
// including file must set: $staff_role_label, $staff_subtitle, $staff_icon,
// $staff_accent, $error, before including this.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title><?php echo htmlspecialchars($staff_role_label); ?> Login | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "<?php echo $staff_accent; ?>",
                        "background-light": "#f8faff",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
                    borderRadius: { DEFAULT: "12px", 'xl': '24px', '2xl': '32px' },
                },
            },
        };
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .dark .glass-card {
            background: rgba(30,41,59,0.7);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .radial-bg { background: radial-gradient(circle at center, #ffffff 0%, #e0eaff 100%); }
        .dark .radial-bg { background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%); }
        .material-symbols-rounded { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .inner-glow { box-shadow: inset 0 1px 1px rgba(255,255,255,0.4); }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }

        /* Purely decorative — see auth/login.php for the identical treatment. */
        .auth-brand-panel { cursor: default; }
        .auth-blob {
            transition: transform 0.6s cubic-bezier(.22,1,.36,1);
            animation: authBlobMorph 9s ease-in-out infinite;
            will-change: transform, border-radius;
        }
        .auth-blob#authBlob2 { animation-delay: -4.5s; }
        @keyframes authBlobMorph {
            0%, 100% { border-radius: 50%; }
            25%      { border-radius: 46% 54% 60% 40% / 55% 45% 55% 45%; }
            50%      { border-radius: 60% 40% 45% 55% / 40% 60% 40% 60%; }
            75%      { border-radius: 40% 60% 55% 45% / 60% 40% 60% 40%; }
        }
        /* Faint monochrome "map" texture — see auth/login.php for the
           identical treatment. */
        .auth-map {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.5;
            background-image:
                repeating-linear-gradient(0deg,  rgba(255,255,255,0.07) 0px, rgba(255,255,255,0.07) 1px, transparent 1px, transparent 64px),
                repeating-linear-gradient(90deg, rgba(255,255,255,0.07) 0px, rgba(255,255,255,0.07) 1px, transparent 1px, transparent 64px),
                repeating-linear-gradient(35deg, rgba(255,255,255,0.04) 0px, rgba(255,255,255,0.04) 1px, transparent 1px, transparent 96px);
        }
        .auth-house {
            position: absolute;
            width: 18px;
            height: 18px;
            color: #fff;
            opacity: 0.22;
            transform: translate(-50%, -50%) scale(1);
            transition: transform 0.4s cubic-bezier(.34,1.56,.64,1), opacity 0.4s ease;
            pointer-events: none;
        }
        .auth-house.zoomed { transform: translate(-50%, -50%) scale(1.8); opacity: 0.6; }
        .auth-house svg { width: 100%; height: 100%; display: block; }

        .auth-magnifier {
            position: absolute;
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: 2.5px solid rgba(255,255,255,0.6);
            background: rgba(255,255,255,0.05);
            box-shadow: 0 6px 18px rgba(0,0,0,0.18), inset 0 0 14px rgba(255,255,255,0.1);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.25s ease;
            transform: translate(-50%, -50%);
            z-index: 5;
        }
        .auth-magnifier::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 3px;
            background: rgba(255,255,255,0.6);
            border-radius: 2px;
            bottom: -3px;
            right: -13px;
            transform: rotate(45deg);
            transform-origin: left center;
        }
        .auth-brand-panel:hover .auth-magnifier { opacity: 1; }
        @media (prefers-reduced-motion: reduce) {
            .auth-blob { animation: none; transition: none; }
            .auth-house, .auth-magnifier { transition: none; }
        }
    </style>
</head>
<body class="radial-bg min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<div class="w-full max-w-lg lg:max-w-5xl lg:flex lg:items-stretch animate-slideUp">

    <!-- Desktop-only branding panel, same treatment as auth/login.php -->
    <div class="hidden lg:flex lg:w-1/2 lg:flex-col lg:justify-between rounded-l-2xl p-12 relative overflow-hidden text-white auth-brand-panel" id="authBrandPanel"
         style="background: linear-gradient(135deg, <?php echo $staff_accent; ?> 0%, <?php echo $staff_accent; ?>cc 100%);">
        <div class="absolute -top-24 -right-24 w-72 h-72 bg-white/10 rounded-full blur-3xl auth-blob" id="authBlob1"></div>
        <div class="absolute -bottom-32 -left-16 w-72 h-72 bg-white/10 rounded-full blur-3xl auth-blob" id="authBlob2"></div>
        <div class="auth-map"></div>
        <?php
        $auth_house_svg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg>';
        $auth_house_positions = [
            [14,16], [76,12], [42,24], [88,42], [22,52],
            [58,60], [8,74], [33,86], [70,80],
        ];
        foreach ($auth_house_positions as $hp):
        ?>
        <div class="auth-house" data-house style="left:<?php echo $hp[0]; ?>%; top:<?php echo $hp[1]; ?>%;"><?php echo $auth_house_svg; ?></div>
        <?php endforeach; ?>
        <div class="auth-magnifier" id="authMagnifier"></div>
        <div class="relative z-10">
            <div class="flex items-center gap-1 mb-1">
                <span class="text-3xl font-extrabold tracking-tight">Abi</span>
                <span class="text-3xl font-extrabold tracking-tight text-white/70">listo</span>
            </div>
            <p class="text-xs font-semibold tracking-[0.2em] text-white/70">Abilidad. Bilis. Listo.</p>
        </div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-1.5 mb-4 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest bg-white/15">
                <span class="material-symbols-rounded text-xs"><?php echo htmlspecialchars($staff_icon); ?></span>
                <?php echo htmlspecialchars($staff_subtitle); ?>
            </div>
            <h2 class="text-3xl font-bold leading-snug">Staff access only.</h2>
        </div>
        <div class="relative z-10 text-[11px] uppercase tracking-widest font-bold text-white/70">
            <div class="flex items-center gap-1.5"><span class="material-symbols-rounded text-sm">lock</span> Internal Portal</div>
        </div>
    </div>

    <div class="glass-card rounded-2xl lg:rounded-l-none lg:rounded-r-2xl lg:w-1/2 shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.5)] p-8 md:p-12 border border-blue-100 dark:border-slate-700/50">

        <!-- Logo + role badge (mobile/tablet only) -->
        <div class="text-center mb-10 lg:hidden">
            <div class="flex items-center justify-center gap-1 mb-2">
                <span class="text-4xl font-extrabold tracking-tight" style="color: <?php echo $staff_accent; ?>">Abi</span>
                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">listo</span>
            </div>
            <div class="inline-flex items-center gap-1.5 mt-2 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest"
                 style="background: <?php echo $staff_accent; ?>1a; color: <?php echo $staff_accent; ?>">
                <span class="material-symbols-rounded text-xs"><?php echo htmlspecialchars($staff_icon); ?></span>
                <?php echo htmlspecialchars($staff_subtitle); ?>
            </div>
        </div>

        <!-- Role switcher — hop between the 3 staff portals -->
        <?php
        $staff_portals = [
            'Admin'   => ['href' => 'admin_login.php',   'icon' => 'admin_panel_settings', 'accent' => '#146af5'],
            'Finance' => ['href' => 'finance_login.php',  'icon' => 'account_balance',      'accent' => '#16a34a'],
            'HR'      => ['href' => 'hr_login.php',       'icon' => 'badge',                'accent' => '#9333ea'],
        ];
        ?>
        <div class="flex items-center justify-center gap-2 mb-8">
            <?php foreach ($staff_portals as $portal_label => $portal): $is_active = ($portal_label === $staff_role_label); ?>
            <?php if ($is_active): ?>
            <span class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-xs font-bold"
                  style="background: <?php echo $portal['accent']; ?>; color: white;">
                <span class="material-symbols-rounded text-sm"><?php echo $portal['icon']; ?></span>
                <?php echo $portal_label; ?>
            </span>
            <?php else: ?>
            <a href="<?php echo $portal['href']; ?>"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <span class="material-symbols-rounded text-sm"><?php echo $portal['icon']; ?></span>
                <?php echo $portal_label; ?>
            </a>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2"><?php echo htmlspecialchars($staff_role_label); ?> Sign In</h1>
            <p class="text-slate-500 dark:text-slate-400">Staff access only</p>
        </div>

        <!-- Error Message -->
        <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-red-500">error</span>
            <span class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="" class="space-y-6">
            <!-- Email or Phone -->
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 ml-1">Email or Phone Number</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                        <span class="material-symbols-rounded text-slate-400 group-focus-within:text-primary transition-colors">badge</span>
                    </div>
                    <input type="text"
                           name="identifier"
                           class="block w-full pl-12 pr-4 py-4 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all outline-none dark:text-white placeholder:text-slate-300 dark:placeholder:text-slate-600"
                           placeholder="you@abilisto.com or 09123456789"
                           value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : (isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''); ?>"
                           required>
                </div>
            </div>

            <!-- Password -->
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 ml-1">Password</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                        <span class="material-symbols-rounded text-slate-400 group-focus-within:text-primary transition-colors">lock</span>
                    </div>
                    <input type="password"
                           id="staffPassword"
                           name="password"
                           class="block w-full pl-12 pr-12 py-4 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all outline-none dark:text-white placeholder:text-slate-300 dark:placeholder:text-slate-600"
                           placeholder="••••••••"
                           required>
                    <button type="button" onclick="toggleVis('staffPassword','staffEyeIcon')"
                            class="absolute inset-y-0 right-0 pr-5 flex items-center text-slate-400 hover:text-primary transition-colors">
                        <span class="material-symbols-rounded" id="staffEyeIcon">visibility</span>
                    </button>
                </div>
                <div class="flex justify-end">
                    <a class="text-sm font-medium text-primary hover:underline underline-offset-4" href="forgot_pass.php">Forgot password?</a>
                </div>
            </div>

            <!-- Login Button -->
            <button type="submit" name="login_btn"
                    class="w-full bg-gradient-to-r text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98] shadow-lg flex items-center justify-center gap-2 group inner-glow"
                    style="background: linear-gradient(to right, <?php echo $staff_accent; ?>, <?php echo $staff_accent; ?>cc);">
                <span class="material-symbols-rounded">login</span>
                Sign In
            </button>
        </form>

        <div class="mt-10 text-center">
            <a class="inline-flex items-center gap-2 text-sm font-medium text-slate-400 dark:text-slate-500 hover:text-primary dark:hover:text-primary transition-colors" href="../index.php">
                <span class="material-symbols-rounded text-lg">arrow_back</span>
                Back to Home
            </a>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    });

    function toggleVis(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (!input || !icon) return;
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility';
        }
    }

    // Decorative cursor interaction on the branding panel — see
    // auth/login.php for the identical treatment.
    (function () {
        const panel = document.getElementById('authBrandPanel');
        const blob1 = document.getElementById('authBlob1');
        const blob2 = document.getElementById('authBlob2');
        const magnifier = document.getElementById('authMagnifier');
        const houses = Array.prototype.slice.call(document.querySelectorAll('.auth-house'));
        if (!panel || !blob1 || !blob2 || !magnifier) return;

        const ZOOM_RADIUS = 42;

        panel.addEventListener('mousemove', function (e) {
            const rect = panel.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            magnifier.style.left = x + 'px';
            magnifier.style.top  = y + 'px';

            houses.forEach(function (house) {
                const hx = (parseFloat(house.style.left) / 100) * rect.width;
                const hy = (parseFloat(house.style.top)  / 100) * rect.height;
                const dist = Math.hypot(x - hx, y - hy);
                house.classList.toggle('zoomed', dist < ZOOM_RADIUS);
            });

            const dx = (x - rect.width / 2) / rect.width;
            const dy = (y - rect.height / 2) / rect.height;
            blob1.style.transform = 'translate(' + (dx * 34) + 'px,' + (dy * 34) + 'px) scale(1.12)';
            blob2.style.transform = 'translate(' + (dx * -28) + 'px,' + (dy * -28) + 'px) scale(1.12)';
        });
        panel.addEventListener('mouseleave', function () {
            blob1.style.transform = '';
            blob2.style.transform = '';
            houses.forEach(function (house) { house.classList.remove('zoomed'); });
        });
    })();
</script>
</body>
</html>
