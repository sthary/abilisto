<?php
// waitlist.php — landing page for signed-in accounts that aren't approved
// yet. includes/functions/enforce_waitlist.php redirects here for anything
// non-active; this page re-reads the account's real status itself (never
// trusts $_SESSION) so it stays correct even if someone bookmarks this URL
// or refreshes after being approved mid-session.
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['client', 'worker'], true)) {
    header("Location: auth/login.php");
    exit();
}

$stmt = $conn->prepare("SELECT full_name, role, account_status FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: auth/logout.php");
    exit();
}

// Already approved (or approved just now, mid-session) — no reason to sit
// on this page, send them straight in.
if ($user['account_status'] === 'active') {
    header("Location: " . ($user['role'] === 'worker' ? 'worker/dashboard.php' : 'client/dashboard.php'));
    exit();
}

$is_suspended = ($user['account_status'] === 'suspended');
$first_name   = explode(' ', trim($user['full_name']))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title><?php echo $is_suspended ? 'Account Restricted' : "You're on the Waitlist"; ?> | Abilisto</title>
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
                        primary: "#146af5",
                        "background-light": "#f8faff",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
                    borderRadius: { DEFAULT: "12px", xl: "24px", "2xl": "32px" },
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
        .dark .glass-card { background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.1); }
        .radial-bg { background: radial-gradient(circle at center, #ffffff 0%, #e0eaff 100%); }
        .dark .radial-bg { background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%); }
        .material-symbols-rounded { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .inner-glow { box-shadow: inset 0 1px 1px rgba(255,255,255,0.4); }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }
        @keyframes softPulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: 0.85; } }
        .animate-softPulse { animation: softPulse 2.6s ease-in-out infinite; }
        @media (prefers-reduced-motion: reduce) { .animate-softPulse { animation: none; } }
    </style>
</head>
<body class="radial-bg min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<div class="w-full max-w-lg animate-slideUp">
    <div class="glass-card rounded-2xl shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.5)] p-8 md:p-12 border border-blue-100 dark:border-slate-700/50 text-center">

        <!-- Logo -->
        <div class="mb-8">
            <div class="flex items-center justify-center gap-1 mb-2">
                <span class="text-3xl sm:text-4xl font-extrabold tracking-tight text-primary">Abi</span>
                <span class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">listo</span>
            </div>
            <p class="text-xs font-semibold tracking-[0.2em] uppercase text-slate-400 dark:text-slate-500">Abilidad. Bilis. Listo.</p>
        </div>

        <?php if ($is_suspended): ?>

            <!-- SUSPENDED STATE -->
            <div class="flex justify-center mb-6">
                <div class="w-20 h-20 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-4xl text-red-500">block</span>
                </div>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white mb-3">Account Restricted</h1>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
                Hi <?php echo htmlspecialchars($first_name); ?>, your account currently doesn't have access to Abilisto.
                If you believe this isn't right, please reach out to our support team.
            </p>

        <?php else: ?>

            <!-- WAITLISTED STATE -->
            <div class="flex justify-center mb-6">
                <div class="w-20 h-20 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center animate-softPulse">
                    <span class="material-symbols-rounded text-4xl text-primary">hourglass_top</span>
                </div>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white mb-3">You're on the Abilisto Waitlist!</h1>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 leading-relaxed mb-2">
                Thanks for signing up, <?php echo htmlspecialchars($first_name); ?> — we're glad you're here.
            </p>
            <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
                Abilisto is currently in a controlled rollout while we prepare for launch. Your account has been
                created and is saved — you'll get full access as soon as it's approved. No further action is
                needed on your part.
            </p>

            <div class="mb-8 p-4 bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-800/20 rounded-xl flex items-start gap-3 text-left">
                <span class="material-symbols-rounded text-primary flex-shrink-0 mt-0.5">info</span>
                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400">
                    Check back anytime — this page will automatically take you into Abilisto the moment your
                    account is approved.
                </p>
            </div>

        <?php endif; ?>

        <!-- Actions -->
        <div class="space-y-3">
            <a href="waitlist.php"
               class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold text-sm sm:text-base py-3 sm:py-4 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow">
                <span class="material-symbols-rounded text-base sm:text-xl">refresh</span>
                Check Status
            </a>
            <a href="auth/logout.php"
               class="w-full inline-flex items-center justify-center gap-2 bg-white/50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 py-3 sm:py-4 text-sm sm:text-base rounded-xl transition-all font-semibold text-slate-700 dark:text-slate-200">
                <span class="material-symbols-rounded text-base sm:text-xl">logout</span>
                Log Out
            </a>
        </div>

        <!-- Trust strip -->
        <div class="mt-10 pt-8 border-t border-slate-100 dark:border-slate-800 flex flex-wrap justify-center gap-x-6 gap-y-3">
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">verified_user</span> Verified Workers
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">encrypted</span> Encrypted
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('theme') === 'dark' || localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    });
</script>
</body>
</html>
