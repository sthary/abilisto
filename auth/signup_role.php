<?php
// auth/signup_role.php
include '../includes/init_lang.php';
?>
<!DOCTYPE html>
<html class="light" lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Join Abilisto - Choose Your Path | Abilisto</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    
    <!-- Font Awesome (for backward compatibility) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#3b82f6",
                        "background-light": "#f8fafc",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: {
                        display: ["'Plus Jakarta Sans'", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        "3xl": "28px",
                        "2xl": "16px",
                    },
                },
            },
        };
    </script>
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.05);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }
        .bg-radial-gradient {
            background: radial-gradient(circle at top center, #dbeafe 0%, #f8fafc 100%);
        }
        .dark .bg-radial-gradient {
            background: radial-gradient(circle at top center, #1e293b 0%, #0f172a 100%);
        }
        .inner-glow-blue {
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.4);
        }
        .inner-glow-green {
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.4);
        }
        
        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.7s ease forwards;
        }
    </style>
</head>
<body class="bg-radial-gradient min-h-screen flex flex-col items-center justify-center p-4 md:p-8 dark:text-slate-200">

<!-- Header (moderate size) -->
<header class="text-center mb-10 animate-fade-in">
    <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-full mb-5 text-primary">
        <span class="material-symbols-outlined text-3xl" style="font-variation-settings: 'FILL' 1">rocket_launch</span>
    </div>
    <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-3">Join Abilisto</h1>
    <p class="text-slate-600 dark:text-slate-400 max-w-lg mx-auto text-base leading-relaxed">
        Choose your path and start your journey with thousands of verified users
    </p>
</header>

<!-- Role Selection Cards (moderate size) -->
<main class="grid grid-cols-1 md:grid-cols-2 gap-7 w-full max-w-5xl mb-14">
    
    <!-- Client Card -->
    <div class="glass-card rounded-2xl p-7 md:p-9 flex flex-col relative overflow-hidden group hover:scale-[1.02] transition-all duration-300">
        <div class="absolute top-5 right-5">
        </div>
        
        <div class="w-14 h-14 bg-blue-50 dark:bg-blue-500/10 rounded-xl flex items-center justify-center mb-6">
            <span class="material-symbols-outlined text-primary text-2xl">touch_app</span>
        </div>
        
        <h2 class="text-2xl font-bold text-primary mb-3">I need a Service</h2>
        <p class="text-slate-600 dark:text-slate-400 mb-6 text-base leading-relaxed">
            Find trusted, verified workers for your home repairs, maintenance, and emergencies.
        </p>
        
        <ul class="space-y-3 mb-8 flex-grow">
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm"><strong>Verified</strong> skilled workers available</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm"><strong>30-minute</strong> response time</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Verified & background-checked</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Transparent pricing</span>
            </li>
        </ul>
        
        <a href="signup_form.php?role=client" class="w-full bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-4 px-5 rounded-xl flex items-center justify-center gap-2 group transition-all inner-glow-blue shadow-lg shadow-blue-500/20 text-base">
            Sign Up as Client
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-lg">send</span>
        </a>
        
        <p class="text-center text-slate-400 dark:text-slate-500 text-xs mt-4 flex items-center justify-center gap-1.5">
            <span class="material-symbols-outlined text-sm">schedule</span>
            Takes less than 2 minutes
        </p>
    </div>
    
    <!-- Worker Card -->
    <div class="glass-card rounded-2xl p-7 md:p-9 flex flex-col relative overflow-hidden group hover:scale-[1.02] transition-all duration-300">
        <div class="absolute top-5 right-5">
        </div>
        
        <div class="w-14 h-14 bg-emerald-50 dark:bg-emerald-500/10 rounded-xl flex items-center justify-center mb-6">
            <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-2xl">construction</span>
        </div>
        
        <h2 class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mb-3">I am a Skilled Worker</h2>
        <p class="text-slate-600 dark:text-slate-400 mb-6 text-base leading-relaxed">
            Offer your services, find customers, grow your business with our professional platform.
        </p>
        
        <ul class="space-y-3 mb-8 flex-grow">
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Get Free <span class="font-bold">₱100</span> booking credit</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Get matched with jobs</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Free profile & portfolio</span>
            </li>
            <li class="flex items-center gap-2">
                <span class="material-symbols-outlined text-emerald-500 text-lg">check_circle</span>
                <span class="text-slate-700 dark:text-slate-300 text-sm">Instant payment processing</span>
            </li>
        </ul>
        
        <a href="signup_form.php?role=worker" class="w-full bg-gradient-to-r from-emerald-600 to-emerald-500 hover:from-emerald-700 hover:to-emerald-600 text-white font-bold py-4 px-5 rounded-xl flex items-center justify-center gap-2 group transition-all inner-glow-green shadow-lg shadow-emerald-500/20 text-base">
            Sign Up as Worker
            <span class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-lg">send</span>
        </a>
        
        <p class="text-center text-slate-400 dark:text-slate-500 text-xs mt-4 flex items-center justify-center gap-1.5">
            <span class="material-symbols-outlined text-sm">monetization_on</span>
            Free to join, start earning today
        </p>
    </div>
</main>

<!-- Trust Indicators & Footer (moderate size) -->
<footer class="w-full max-w-4xl px-4 flex flex-col items-center">
    <div class="flex flex-wrap justify-center gap-5 md:gap-10 mb-9 border-b border-slate-200 dark:border-slate-800 pb-9 w-full">
        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined font-light text-xl">verified_user</span>
            <span class="text-xs font-medium">TESDA Verified</span>
        </div>
        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined font-light text-xl">lock</span>
            <span class="text-xs font-medium">Secure Platform</span>
        </div>
        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined font-light text-xl">support_agent</span>
            <span class="text-xs font-medium">24/7 Support</span>
        </div>
        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span class="material-symbols-outlined font-light text-xl">payments</span>
            <span class="text-xs font-medium">Secure Payments</span>
        </div>
    </div>
    
    <p class="text-slate-600 dark:text-slate-400 text-sm font-medium mb-5">
        Already have an account? <a class="text-primary hover:underline underline-offset-4" href="login.php">Log in here</a>
    </p>
    
    <div class="flex items-center gap-1.5 text-xs text-slate-400 dark:text-slate-500 bg-slate-100 dark:bg-slate-800/50 px-4 py-2 rounded-full">
        <span class="material-symbols-outlined text-sm">shield</span>
        By signing up, you agree to our Terms of Service and Privacy Policy
    </div>
</footer>

<!-- Script for smooth interactions (UNCHANGED) -->
<script>
    // Add hover effect simulation (just visual feedback)
    document.querySelectorAll('.glass-card').forEach(card => {
        card.addEventListener('mouseenter', () => {
            card.style.transition = 'all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)';
        });
    });
    
    // Check for saved dark mode preference
    if (localStorage.getItem('darkMode') === 'true') {
        document.documentElement.classList.add('dark');
    }
    
    // Save dark mode preference when toggled
    document.querySelector('[onclick="document.documentElement.classList.toggle(\'dark\')"]')?.addEventListener('click', function() {
        const isDark = document.documentElement.classList.contains('dark');
        localStorage.setItem('darkMode', isDark);
    });
</script>

</body>
</html>