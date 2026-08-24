<?php
// auth/login.php
session_start();
include '../includes/init_lang.php';
include '../db_connect.php';
include 'google_config.php';
require_once '../includes/functions/identify_user.php';

$error = '';
$flash_success = '';
$flash_info = '';

// ============================================================
// GOOGLE TOKEN HANDLER (from google_callback.php redirect)
// This is the ONLY place Google logins create a real session.
// ============================================================
if (isset($_GET['google_token']) && !empty($_GET['google_token'])) {
    $token = $_GET['google_token'];

    $stmt = $conn->prepare(
        "SELECT * FROM users
         WHERE google_temp_token = ?
           AND google_temp_token_expires > NOW()"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        // Consume the token immediately so it can't be reused
        $stmt_clear = $conn->prepare(
            "UPDATE users SET google_temp_token = NULL, google_temp_token_expires = NULL WHERE id = ?"
        );
        $stmt_clear->execute([$user['id']]);

        // Create the real login session
        $_SESSION['user_id']           = $user['id'];
        $_SESSION['full_name']         = $user['full_name'];
        $_SESSION['email']             = $user['email'];
        $_SESSION['phone']             = $user['phone'];
        $_SESSION['role']              = $user['role'];
        $_SESSION['municipality']      = $user['municipality'];
        $_SESSION['latitude']          = $user['latitude'];
        $_SESSION['longitude']         = $user['longitude'];
        $_SESSION['is_email_verified'] = 1; // Google always verifies email
        $_SESSION['is_phone_verified'] = $user['is_phone_verified'];

        // New Google users go to profile setup; returning users go to dashboard
        if ($user['is_new'] == 1) {
            header("Location: profile_setup.php");
            exit();
        }

        // Redirect based on role
        if ($user['role'] === 'worker') {
            header("Location: ../worker/dashboard.php");
        } elseif ($user['role'] === 'admin') {
            header("Location: ../admin/dashboard.php");
        } elseif ($user['role'] === 'business') {
            header("Location: ../client/dashboard.php"); // TODO: no business dashboard exists yet - business accounts land on the client dashboard as an interim fallback
        } elseif ($user['role'] === 'finance') {
            header("Location: ../admin/finance.php");
        } elseif ($user['role'] === 'hr') {
            header("Location: ../admin/hr.php");
        } else {
            header("Location: ../client/dashboard.php");
        }
        exit();

    } else {
        // Token expired or tampered — show error, fall through to login form
        $error = "⚠️ Google login link expired or already used. Please log in manually or try Google again.";
    }
}

// ============================================================
// FLASH MESSAGES (set by google_callback.php or verify_otp.php)
// ============================================================
if (isset($_SESSION['flash_success'])) {
    $flash_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_info'])) {
    $flash_info = $_SESSION['flash_info'];
    unset($_SESSION['flash_info']);
}
if (isset($_SESSION['flash_error']) && empty($error)) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ============================================================
// NORMAL EMAIL/PASSWORD LOGIN
// ============================================================
if (isset($_POST['login_btn'])) {
    $identifier = $_POST['identifier'];
    $password   = $_POST['password'];

    $user = findUserByIdentifier($conn, $identifier);

    if ($user) {

        if (!empty($user['password']) && password_verify($password, $user['password'])) {

            // Staff roles have their own dedicated login pages (no phone
            // gate, no Google OAuth) — send them there instead of logging
            // in here, checked before the phone-verification gate below so
            // it can never block a staff account the way it used to.
            $staff_pages = ['admin' => 'admin_login.php', 'hr' => 'hr_login.php', 'finance' => 'finance_login.php'];
            if (isset($staff_pages[$user['role']])) {
                header("Location: " . $staff_pages[$user['role']] . "?email=" . urlencode($user['email']));
                exit();
            }

            // Must verify phone before accessing dashboard
            if ($user['is_phone_verified'] == 0) {
                echo "<script>
                        alert('⚠️ Please verify your phone number first.');
                        window.location.href='verify_otp.php?email=" . urlencode($user['email']) . "';
                      </script>";
                exit();
            }

            // Create session
            $_SESSION['user_id']           = $user['id'];
            $_SESSION['full_name']         = $user['full_name'];
            $_SESSION['email']             = $user['email'];
            $_SESSION['phone']             = $user['phone'];
            $_SESSION['role']              = $user['role'];
            $_SESSION['municipality']      = $user['municipality'];
            $_SESSION['latitude']          = $user['latitude'];
            $_SESSION['longitude']         = $user['longitude'];
            $_SESSION['is_email_verified'] = $user['is_email_verified'];
            $_SESSION['is_phone_verified'] = $user['is_phone_verified'];

            // New users go to profile setup first
            if ($user['is_new'] == 1) {
                header("Location: profile_setup.php");
                exit();
            }

            // Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: ../admin/dashboard.php");
            } elseif ($user['role'] === 'worker') {
                header("Location: ../worker/dashboard.php");
            } elseif ($user['role'] === 'business') {
                header("Location: ../client/dashboard.php"); // TODO: no business dashboard exists yet - business accounts land on the client dashboard as an interim fallback
            } elseif ($user['role'] === 'finance') {
                header("Location: ../admin/finance.php");
            } elseif ($user['role'] === 'hr') {
                header("Location: ../admin/hr.php");
            } else {
                header("Location: ../client/dashboard.php");
            }
            exit();

        } else {
            $error = "Incorrect Password!";
        }
    } else {
        $error = "User not found!";
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Welcome Back | Abilisto Login</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
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

    <!-- Desktop-only branding panel — hidden below lg, this + the form card
         below together fill the wide layout instead of a lone narrow card
         floating in the middle of the screen. -->
    <div class="hidden lg:flex lg:w-1/2 lg:flex-col lg:justify-between rounded-l-2xl p-12 relative overflow-hidden text-white"
         style="background: linear-gradient(135deg, #146af5 0%, #0d4fc2 100%);">
        <div class="absolute -top-24 -right-24 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-32 -left-16 w-72 h-72 bg-white/10 rounded-full blur-3xl"></div>
        <div class="relative z-10">
            <div class="flex items-center gap-1 mb-1">
                <span class="text-3xl font-extrabold tracking-tight">Abi</span>
                <span class="text-3xl font-extrabold tracking-tight text-blue-200">listo</span>
            </div>
            <p class="text-xs font-semibold tracking-[0.2em] text-blue-200/80">Abilidad. Bilis. Listo.</p>
        </div>
        <div class="relative z-10">
            <h2 class="text-3xl font-bold leading-snug mb-4">Skilled help, verified and nearby.</h2>
            <p class="text-blue-100/90 text-sm leading-relaxed">Book trusted, TESDA-certified workers for home repairs — or find work near you, on your own schedule.</p>
        </div>
        <div class="relative z-10 flex items-center gap-6 text-[11px] uppercase tracking-widest font-bold text-blue-200/80">
            <div class="flex items-center gap-1.5"><span class="material-symbols-rounded text-sm">verified_user</span> Verified Workers</div>
            <div class="flex items-center gap-1.5"><span class="material-symbols-rounded text-sm">bolt</span> Fast Booking</div>
        </div>
    </div>

    <div class="glass-card rounded-2xl lg:rounded-l-none lg:rounded-r-2xl lg:w-1/2 shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.5)] p-8 md:p-12 border border-blue-100 dark:border-slate-700/50">

        <!-- Logo (mobile/tablet only — desktop shows it in the branding panel) -->
        <div class="text-center mb-10 lg:hidden">
            <div class="flex items-center justify-center gap-1 mb-2">
                <span class="text-4xl font-extrabold tracking-tight text-primary">Abi</span>
                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">listo</span>
            </div>
            <p class="text-xs font-semibold tracking-[0.2em] text-slate-400 dark:text-slate-500">Abilidad. Bilis. Listo.</p>
        </div>

        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Welcome Back!</h1>
            <p class="text-slate-500 dark:text-slate-400">Sign in to continue your journey</p>
        </div>

        <!-- Flash: Success (e.g. after phone verification or new Google signup) -->
        <?php if ($flash_success): ?>
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-green-500">check_circle</span>
            <span class="text-sm font-medium text-green-700 dark:text-green-400"><?php echo htmlspecialchars($flash_success); ?></span>
        </div>
        <?php endif; ?>

        <!-- Flash: Info (e.g. existing user linked Google) -->
        <?php if ($flash_info): ?>
        <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-blue-500">info</span>
            <span class="text-sm font-medium text-blue-700 dark:text-blue-400"><?php echo htmlspecialchars($flash_info); ?></span>
        </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if ($error): ?>
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
                           placeholder="juandelacruz@gmail.com or 09123456789"
                           value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>"
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
                           id="loginPassword"
                           name="password"
                           class="block w-full pl-12 pr-12 py-4 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all outline-none dark:text-white placeholder:text-slate-300 dark:placeholder:text-slate-600"
                           placeholder="••••••••"
                           required>
                    <button type="button" onclick="toggleVis('loginPassword','loginEyeIcon')"
                            class="absolute inset-y-0 right-0 pr-5 flex items-center text-slate-400 hover:text-primary transition-colors">
                        <span class="material-symbols-rounded" id="loginEyeIcon">visibility</span>
                    </button>
                </div>
                <div class="flex justify-end">
                    <a class="text-sm font-medium text-primary hover:underline underline-offset-4" href="forgot_pass.php">Forgot password?</a>
                </div>
            </div>

            <!-- Login Button -->
            <button type="submit" name="login_btn" class="w-full bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-4 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 flex items-center justify-center gap-2 group inner-glow">
                <span class="material-symbols-rounded">login</span>
                Sign In
            </button>

            <!-- Divider -->
            <div class="relative py-4">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-slate-200 dark:border-slate-700"></div>
                </div>
                <div class="relative flex justify-center text-xs uppercase">
                    <span class="bg-background-light dark:bg-slate-800 px-4 text-slate-400 dark:text-slate-500 font-semibold tracking-wider">or continue with</span>
                </div>
            </div>

            <!-- Google Login -->
            <a href="<?php echo $google_login_url; ?>"
               class="w-full flex items-center justify-center gap-3 bg-white/50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 py-4 rounded-xl transition-all font-semibold text-slate-700 dark:text-slate-200">
                <img alt="Google Logo" class="w-5 h-5" src="https://lh3.googleusercontent.com/aida-public/AB6AXuA5c9Dhl8fxgeTa5OeZOc5UG5d_tm9abWpRhGmofKFXfzuaMTCxjCSfHgvHHsewBGkfoyWFVrZhHOoE3lWQTmCW2XEIc_c9t4qGyR8REpR5z5LPpIezEDvoHAW52FtpHgvhfvZnNH8RW1tPrfN2ogD1BlQqBtteTxuVgSqdPju_Qt1RR8WtK8DSwC2aTBDNc1jIce6lkzGM7EKVnbRs_Re6nNrhHGnnTXPw2vWJheGjvMpIDs-hEi2-8dxU_XKP08EmNpah2X0oGTsG"/>
                Google Account
            </a>

            <p class="text-xs text-center text-slate-400 mt-4">
                By continuing, you agree to Abilisto's
                <a href="../privacy.html" class="underline">Privacy Policy</a> and
                <a href="../terms.html" class="underline">Terms of Service</a>.
            </p>
        </form>

        <!-- Sign Up -->
        <div class="mt-10 text-center space-y-6">
            <div class="space-y-4">
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">New to Abilisto?</p>
                <a href="signup_role.php" class="w-full inline-block py-4 px-6 bg-blue-50 dark:bg-blue-900/20 text-primary font-bold rounded-xl border border-blue-100 dark:border-blue-800/30 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-rounded">send</span>
                    Create an Account
                </a>
            </div>
            <a class="inline-flex items-center gap-2 text-sm font-medium text-slate-400 dark:text-slate-500 hover:text-primary dark:hover:text-primary transition-colors" href="../index.php">
                <span class="material-symbols-rounded text-lg">arrow_back</span>
                Back to Home
            </a>
        </div>

        <!-- Trust Features -->
        <div class="mt-12 pt-8 border-t border-slate-100 dark:border-slate-800 flex flex-wrap justify-center gap-x-8 gap-y-4">
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">verified_user</span> Secure Login
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">encrypted</span> Encrypted
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">schedule</span> 24/7 Access
            </div>
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