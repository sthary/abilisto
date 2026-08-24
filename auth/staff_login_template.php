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
    </style>
</head>
<body class="radial-bg min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<div class="w-full max-w-lg lg:max-w-5xl lg:flex lg:items-stretch animate-slideUp">

    <!-- Desktop-only branding panel, same treatment as auth/login.php -->
    <div class="hidden lg:flex lg:w-1/2 lg:flex-col lg:justify-between rounded-l-2xl p-12 relative overflow-hidden text-white"
         style="background: linear-gradient(135deg, <?php echo $staff_accent; ?> 0%, <?php echo $staff_accent; ?>cc 100%);">
        <div class="absolute -top-24 -right-24 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-32 -left-16 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
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
            <!-- Email -->
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 ml-1">Email Address</label>
                <div class="relative group">
                    <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                        <span class="material-symbols-rounded text-slate-400 group-focus-within:text-primary transition-colors">mail</span>
                    </div>
                    <input type="email"
                           name="email"
                           class="block w-full pl-12 pr-4 py-4 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all outline-none dark:text-white placeholder:text-slate-300 dark:placeholder:text-slate-600"
                           placeholder="you@abilisto.com"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''); ?>"
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
        if (localStorage.getItem('darkMode') === 'true') {
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
</script>
</body>
</html>
