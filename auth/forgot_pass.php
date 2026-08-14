<?php
// auth/forgot_pass.php
session_start();
include '../db_connect.php';
include '../includes/mailer.php';

// ─────────────────────────────────────────────────────────────
// STEP STATE:  'email' → 'code' → 'password' → done
// All state is stored in $_SESSION['fp_*'] keys.
// ─────────────────────────────────────────────────────────────
$step  = $_SESSION['fp_step']  ?? 'email';
$error = '';
$info  = '';

// ── POST: Step 1 — Verify email ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_email'])) {
    $email = trim($_POST['fp_email']);

    $stmt = $conn->prepare("SELECT id, full_name, email, role, is_email_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = "No account found with that email address.";
        $step  = 'email';
    } elseif (!$user['is_email_verified']) {
        $error = "This account's email has not been verified yet. Please verify your email first.";
        $step  = 'email';
    } else {
        // Generate 6-digit OTP
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = time() + 600; // 10 minutes

        // Save to session
        $_SESSION['fp_step']    = 'code';
        $_SESSION['fp_user_id'] = $user['id'];
        $_SESSION['fp_email']   = $user['email'];
        $_SESSION['fp_name']    = explode(' ', trim($user['full_name']))[0];
        $_SESSION['fp_role']    = $user['role'];
        $_SESSION['fp_otp']     = password_hash($otp, PASSWORD_DEFAULT); // hash it
        $_SESSION['fp_expires'] = $expires;
        $_SESSION['fp_attempts'] = 0;

        // Send email
        $subject = "Your Abilisto Password Reset Code";
        $html_body = "
        <div style='font-family:\"Plus Jakarta Sans\",Arial,sans-serif; max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;'>
            <div style='background:linear-gradient(135deg,#60a5fa,#0f52c2); padding:32px; text-align:center;'>
                <span style='font-size:22px; font-weight:800; color:#fff; letter-spacing:-0.03em;'>Abi<span style='opacity:0.85'>listo</span></span>
            </div>
            <div style='padding:36px 32px;'>
                <h2 style='font-size:20px; font-weight:700; color:#0f172a; margin:0 0 8px;'>Password Reset</h2>
                <p style='color:#64748b; font-size:15px; line-height:1.6; margin:0 0 28px;'>
                    Hi <strong style='color:#0f172a;'>" . htmlspecialchars($user['full_name']) . "</strong>,<br>
                    Use the code below to reset your password. It expires in <strong>10 minutes</strong>.
                </p>
                <div style='background:#f8fafc; border:2px dashed #e2e8f0; border-radius:12px; padding:24px; text-align:center; margin-bottom:28px;'>
                    <span style='font-size:40px; font-weight:800; letter-spacing:0.18em; color:#146af5; font-family:monospace;'>" . $otp . "</span>
                </div>
                <p style='color:#94a3b8; font-size:13px; line-height:1.6; margin:0;'>
                    If you didn't request this, you can safely ignore this email. Your password won't change.
                </p>
            </div>
            <div style='background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px; text-align:center;'>
                <p style='color:#cbd5e1; font-size:12px; margin:0;'>&copy; " . date("Y") . " Abilisto. All rights reserved.</p>
            </div>
        </div>";

        $sent = sendAbilistoEmail($user['email'], $subject, $html_body);

        if (!$sent) {
            // Rollback session if mail fails
            unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_otp'],
                  $_SESSION['fp_expires'], $_SESSION['fp_email'], $_SESSION['fp_name'],
                  $_SESSION['fp_role'], $_SESSION['fp_attempts']);
            $error = "Failed to send the reset code. Please try again.";
            $step  = 'email';
        } else {
            $step = 'code';
            $info = "A 6-digit code was sent to " . htmlspecialchars($email) . ".";
        }
    }
}

// ── POST: Step 2 — Verify OTP ────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_code'])) {
    $step = 'code';
    $entered = trim($_POST['fp_code']);

    if (!isset($_SESSION['fp_otp']) || !isset($_SESSION['fp_expires'])) {
        $error = "Session expired. Please start over.";
        $step  = 'email';
        unset($_SESSION['fp_step']);
    } elseif (time() > $_SESSION['fp_expires']) {
        $error = "This code has expired. Please request a new one.";
        unset($_SESSION['fp_step'], $_SESSION['fp_otp'], $_SESSION['fp_expires']);
        $step = 'email';
    } else {
        $_SESSION['fp_attempts'] = ($_SESSION['fp_attempts'] ?? 0) + 1;

        if ($_SESSION['fp_attempts'] > 5) {
            $error = "Too many attempts. Please start over.";
            unset($_SESSION['fp_step'], $_SESSION['fp_otp'], $_SESSION['fp_expires'],
                  $_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_name'],
                  $_SESSION['fp_role'], $_SESSION['fp_attempts']);
            $step = 'email';
        } elseif (!password_verify($entered, $_SESSION['fp_otp'])) {
            $remaining = 5 - $_SESSION['fp_attempts'];
            $error = "Incorrect code. $remaining attempt" . ($remaining !== 1 ? 's' : '') . " remaining.";
        } else {
            // OTP correct — move to password step
            $_SESSION['fp_step']      = 'password';
            $_SESSION['fp_otp_valid'] = true;
            unset($_SESSION['fp_otp'], $_SESSION['fp_expires'], $_SESSION['fp_attempts']);
            $step = 'password';
        }
    }
}

// ── POST: Step 3 — Set new password ─────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_newpass'])) {
    $step = 'password';

    if (empty($_SESSION['fp_otp_valid']) || empty($_SESSION['fp_user_id'])) {
        $error = "Session invalid. Please start over.";
        $step  = 'email';
        foreach (['fp_step','fp_user_id','fp_email','fp_name','fp_role','fp_otp_valid'] as $k) unset($_SESSION[$k]);
    } else {
        $new_pass     = $_POST['fp_newpass'];
        $confirm_pass = $_POST['fp_confirm'];

        if (strlen($new_pass) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "Passwords don't match.";
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $_SESSION['fp_user_id']]);

            // Set session so they're logged in
            $uid = $_SESSION['fp_user_id'];
            $role = $_SESSION['fp_role'];

            // Determine redirect
            $redirect = match($role) {
                'worker'   => '../worker/dashboard.php',
                // TODO: no business dashboard exists yet - business accounts land on the client dashboard as an interim fallback
                default    => '../client/dashboard.php',
            };

            // Clean up fp_ session keys
            foreach (['fp_step','fp_user_id','fp_email','fp_name','fp_role','fp_otp_valid'] as $k) unset($_SESSION[$k]);

            // Log them in
            $user_stmt = $conn->prepare("SELECT id, full_name, email, phone, role, municipality, latitude, longitude, is_email_verified FROM users WHERE id = ?");
            $user_stmt->execute([$uid]);
            $logged_user = $user_stmt->fetch();

            $_SESSION['user_id']           = $logged_user['id'];
            $_SESSION['full_name']         = $logged_user['full_name'];
            $_SESSION['email']             = $logged_user['email'];
            $_SESSION['phone']             = $logged_user['phone'];
            $_SESSION['role']              = $logged_user['role'];
            $_SESSION['municipality']      = $logged_user['municipality'];
            $_SESSION['latitude']          = $logged_user['latitude'];
            $_SESSION['longitude']         = $logged_user['longitude'];
            $_SESSION['is_email_verified'] = $logged_user['is_email_verified'];

            header("Location: $redirect");
            exit();
        }
    }
}

// Sync $step from session if no POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $step = $_SESSION['fp_step'] ?? 'email';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Reset Password | Abilisto</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: { extend: { fontFamily: { display: ['Plus Jakarta Sans', 'sans-serif'] } } }
    };
    </script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }

        .glass-card {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(255,255,255,0.4);
        }
        .dark .glass-card {
            background: rgba(30,41,59,0.7);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .input-field {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            background: rgba(255,255,255,0.5);
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-family: inherit;
            font-size: 0.95rem;
            color: #0f172a;
            outline: none;
            transition: all 0.2s;
        }
        .dark .input-field {
            background: rgba(30,41,59,0.5);
            border-color: #334155;
            color: #f1f5f9;
        }
        .input-field:focus {
            border-color: #146af5;
            box-shadow: 0 0 0 3px rgba(20,106,245,0.12);
        }
        .input-field.error-field { border-color: #ef4444; }
        .input-field.error-field:focus { box-shadow: 0 0 0 3px rgba(239,68,68,0.12); }

        /* OTP input */
        .otp-input {
            width: 100%;
            padding: 1rem;
            text-align: center;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.35em;
            font-family: 'Courier New', monospace;
            background: rgba(255,255,255,0.6);
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            outline: none;
            transition: all 0.2s;
            color: #1e3a8a;
        }
        .dark .otp-input {
            background: rgba(30,41,59,0.5);
            border-color: #334155;
            color: #93c5fd;
        }
        .otp-input:focus {
            border-color: #146af5;
            box-shadow: 0 0 0 4px rgba(20,106,245,0.12);
        }
        .otp-input.error-field { border-color: #ef4444; }

        /* Password strength bar */
        .strength-bar { height: 4px; border-radius: 2px; transition: width 0.3s, background 0.3s; }

        /* Buttons */
        .btn-primary {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #60a5fa, #0f52c2);
            color: white;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 8px 20px -4px rgba(20,106,245,0.35);
            letter-spacing: -0.01em;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -4px rgba(20,106,245,0.45); }
        .btn-primary:active { transform: scale(0.98); }

        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }

        /* Stepper */
        .step-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #e2e8f0;
            transition: all 0.3s;
        }
        .step-dot.active { width: 24px; background: #146af5; border-radius: 4px; }
        .step-dot.done   { background: #146af5; opacity: 0.4; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-up { animation: slideUp 0.45s cubic-bezier(0.34, 1.2, 0.64, 1) forwards; }

        @keyframes shake {
            0%,100% { transform: translateX(0); }
            20%,60% { transform: translateX(-6px); }
            40%,80% { transform: translateX(6px); }
        }
        .shake { animation: shake 0.4s ease; }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4 transition-colors duration-300"
      style="background: radial-gradient(circle at top left, #e0f2fe, #f0f9ff, #fff);">

<div class="w-full max-w-md animate-up">
<div class="glass-card rounded-[28px] shadow-2xl p-8 md:p-10">

    <!-- Logo -->
    <div class="flex items-center justify-center gap-0.5 mb-8">
        <span class="text-xl font-extrabold text-blue-600 tracking-tight">Abi</span>
        <span class="text-xl font-extrabold text-slate-900 dark:text-white tracking-tight">listo</span>
    </div>

    <!-- Step dots -->
    <?php
    $step_index = match($step) { 'email' => 0, 'code' => 1, 'password' => 2, default => 0 };
    ?>
    <div class="flex items-center justify-center gap-2 mb-8">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="step-dot <?php
            if ($i < $step_index) echo 'done';
            elseif ($i === $step_index) echo 'active';
        ?>"></div>
        <?php endfor; ?>
    </div>

    <!-- ═══════════════════════════════
         STEP 1 — EMAIL
    ═══════════════════════════════ -->
    <?php if ($step === 'email'): ?>

    <div class="mb-7">
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-1.5">Forgot your password?</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">Enter your email address and we'll send you a reset code.</p>
    </div>

    <?php if ($error): ?>
    <div class="flex items-start gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-[14px] px-4 py-3 mb-5">
        <span class="material-symbols-rounded filled text-red-500 flex-shrink-0" style="font-size:18px; margin-top:1px">error</span>
        <p class="text-sm text-red-700 dark:text-red-400 font-medium"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="space-y-5">
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5 ml-0.5">Email Address</label>
                <div class="relative group">
                    <input type="email" name="fp_email" required
                           placeholder="juandelacruz@gmail.com"
                           value="<?php echo htmlspecialchars($_POST['fp_email'] ?? ''); ?>"
                           class="input-field <?php echo $error ? 'error-field' : ''; ?>">
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <span class="material-symbols-rounded" style="font-size:18px">send</span>
                Send Reset Code
            </button>
        </div>
    </form>

    <!-- ═══════════════════════════════
         STEP 2 — OTP CODE
    ═══════════════════════════════ -->
    <?php elseif ($step === 'code'): ?>

    <div class="mb-7">
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-1.5">Check your email</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
            We sent a 6-digit code to<br>
            <strong class="text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($_SESSION['fp_email'] ?? ''); ?></strong>
        </p>
    </div>

    <?php if ($error): ?>
    <div id="err-box" class="flex items-start gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-[14px] px-4 py-3 mb-5">
        <span class="material-symbols-rounded filled text-red-500 flex-shrink-0" style="font-size:18px; margin-top:1px">error</span>
        <p class="text-sm text-red-700 dark:text-red-400 font-medium"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($info && !$error): ?>
    <div class="flex items-start gap-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-[14px] px-4 py-3 mb-5">
        <span class="material-symbols-rounded filled text-blue-500 flex-shrink-0" style="font-size:18px; margin-top:1px">info</span>
        <p class="text-sm text-blue-700 dark:text-blue-400 font-medium"><?php echo htmlspecialchars($info); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="otp-form">
        <div class="space-y-5">
            <!-- OTP expiry countdown -->
            <div class="flex items-center justify-between text-xs font-semibold text-slate-400 mb-1">
                <span>Enter the code below</span>
                <span id="countdown" class="text-blue-500"></span>
            </div>

            <input type="text" name="fp_code" id="otp-input" required
                   maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"
                   placeholder="000000"
                   class="otp-input <?php echo $error ? 'error-field' : ''; ?>"
                   oninput="this.value = this.value.replace(/\D/g,'').slice(0,6); if(this.value.length===6) document.getElementById('otp-form').submit();">

            <button type="submit" class="btn-primary">
                <span class="material-symbols-rounded" style="font-size:18px">verified</span>
                Verify Code
            </button>
        </div>
    </form>

    <!-- Resend + back -->
    <div class="flex items-center justify-between mt-5">
        <form method="POST" action="">
            <input type="hidden" name="fp_email" value="<?php echo htmlspecialchars($_SESSION['fp_email'] ?? ''); ?>">
            <button type="submit" class="text-xs font-semibold text-blue-500 hover:text-blue-700 dark:hover:text-blue-300 transition-colors flex items-center gap-1">
                <span class="material-symbols-rounded" style="font-size:14px">refresh</span>
                Resend code
            </button>
        </form>
        <a href="forgot_pass.php?reset=1" class="text-xs font-semibold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors flex items-center gap-1">
            <span class="material-symbols-rounded" style="font-size:14px">arrow_back</span>
            Use different email
        </a>
    </div>

    <!-- ═══════════════════════════════
         STEP 3 — NEW PASSWORD
    ═══════════════════════════════ -->
    <?php elseif ($step === 'password'): ?>

    <div class="mb-7">
        <h1 class="text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-1.5">
            New password,<?php if (!empty($_SESSION['fp_name'])): ?> <?php echo htmlspecialchars($_SESSION['fp_name']); ?>!<?php else: ?>!<?php endif; ?>
        </h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">Choose a strong password you haven't used before.</p>
    </div>

    <?php if ($error): ?>
    <div class="flex items-start gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-[14px] px-4 py-3 mb-5">
        <span class="material-symbols-rounded filled text-red-500 flex-shrink-0" style="font-size:18px; margin-top:1px">error</span>
        <p class="text-sm text-red-700 dark:text-red-400 font-medium"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="pass-form">
        <div class="space-y-5">

            <!-- New password -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5 ml-0.5">New Password</label>
                <div class="relative group">
                    <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors" style="font-size:18px">lock</span>
                    <input type="password" name="fp_newpass" id="new-pass" required
                           placeholder="At least 8 characters"
                           oninput="checkStrength(this.value)"
                           class="input-field <?php echo $error ? 'error-field' : ''; ?> pr-12">
                    <button type="button" onclick="toggleVis('new-pass','eye1')"
                            class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                        <span class="material-symbols-rounded" id="eye1" style="font-size:18px">visibility</span>
                    </button>
                </div>
                <!-- Strength bar -->
                <div class="mt-2 h-1 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                    <div id="strength-bar" class="strength-bar h-full" style="width:0%"></div>
                </div>
                <p id="strength-label" class="text-xs text-slate-400 mt-1 ml-0.5 h-4"></p>
            </div>

            <!-- Confirm password -->
            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5 ml-0.5">Confirm Password</label>
                <div class="relative group">
                    <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500 transition-colors" style="font-size:18px">lock_reset</span>
                    <input type="password" name="fp_confirm" id="confirm-pass" required
                           placeholder="Re-enter password"
                           oninput="checkMatch()"
                           class="input-field <?php echo $error && str_contains($error, "match") ? 'error-field' : ''; ?> pr-12">
                    <button type="button" onclick="toggleVis('confirm-pass','eye2')"
                            class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                        <span class="material-symbols-rounded" id="eye2" style="font-size:18px">visibility</span>
                    </button>
                </div>
                <p id="match-label" class="text-xs mt-1 ml-0.5 h-4"></p>
            </div>

            <button type="submit" class="btn-primary">
                <span class="material-symbols-rounded" style="font-size:18px">check_circle</span>
                Reset Password
            </button>
        </div>
    </form>

    <?php endif; ?>

    <!-- Back to login -->
    <div class="mt-7 pt-6 border-t border-slate-100 dark:border-slate-800 text-center">
        <a href="login.php" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-400 hover:text-blue-500 dark:hover:text-blue-400 transition-colors">
            <span class="material-symbols-rounded" style="font-size:16px">arrow_back</span>
            Back to Login
        </a>
    </div>

</div>
</div>

<script>
// ── Dark mode restore ─────────────────────────────────────────
if (localStorage.getItem('dark') === 'true') document.documentElement.classList.add('dark');

// ── OTP: reset session if "Use different email" clicked ───────
const url = new URL(window.location.href);
if (url.searchParams.get('reset') === '1') {
    fetch('', { method:'POST', body: new URLSearchParams({ __reset: 1 }) });
    url.searchParams.delete('reset');
    window.history.replaceState({}, '', url);
}

// ── OTP countdown ─────────────────────────────────────────────
<?php if ($step === 'code' && isset($_SESSION['fp_expires'])): ?>
(function() {
    const expires = <?php echo $_SESSION['fp_expires']; ?>;
    const el = document.getElementById('countdown');
    function tick() {
        const left = expires - Math.floor(Date.now() / 1000);
        if (!el) return;
        if (left <= 0) {
            el.textContent = 'Expired';
            el.classList.add('text-red-500');
            el.classList.remove('text-blue-500');
            return;
        }
        const m = Math.floor(left / 60);
        const s = left % 60;
        el.textContent = m + ':' + String(s).padStart(2,'0');
        if (left <= 60) { el.classList.add('text-red-500'); el.classList.remove('text-blue-500'); }
        setTimeout(tick, 1000);
    }
    tick();
})();
<?php endif; ?>

// ── Password strength ─────────────────────────────────────────
function checkStrength(val) {
    const bar   = document.getElementById('strength-bar');
    const label = document.getElementById('strength-label');
    if (!bar) return;
    let score = 0;
    if (val.length >= 8)                    score++;
    if (/[A-Z]/.test(val))                  score++;
    if (/[0-9]/.test(val))                  score++;
    if (/[^A-Za-z0-9]/.test(val))           score++;

    const configs = [
        { w: '0%',   c: 'transparent', t: '' },
        { w: '25%',  c: '#ef4444',     t: 'Weak' },
        { w: '50%',  c: '#f59e0b',     t: 'Fair' },
        { w: '75%',  c: '#3b82f6',     t: 'Good' },
        { w: '100%', c: '#10b981',     t: 'Strong' },
    ];
    const cfg = configs[score];
    bar.style.width      = cfg.w;
    bar.style.background = cfg.c;
    label.textContent    = cfg.t;
    label.style.color    = cfg.c;
}

function checkMatch() {
    const np = document.getElementById('new-pass')?.value;
    const cp = document.getElementById('confirm-pass')?.value;
    const lbl = document.getElementById('match-label');
    if (!lbl || !cp) return;
    if (np === cp) {
        lbl.textContent  = '✓ Passwords match';
        lbl.style.color  = '#10b981';
    } else {
        lbl.textContent  = 'Passwords don\'t match';
        lbl.style.color  = '#ef4444';
    }
}

function toggleVis(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type      = 'text';
        icon.textContent = 'visibility_off';
    } else {
        input.type      = 'password';
        icon.textContent = 'visibility';
    }
}

// ── Shake error box if present ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const err = document.getElementById('err-box') || document.querySelector('[class*="red"]');
    if (err) {
        err.classList.add('shake');
        setTimeout(() => err.classList.remove('shake'), 500);
    }
});
</script>
</body>
</html>

<?php
// Handle the "Use different email" / reset trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__reset'])) {
    foreach (['fp_step','fp_user_id','fp_email','fp_name','fp_role','fp_otp','fp_expires','fp_attempts','fp_otp_valid'] as $k) {
        unset($_SESSION[$k]);
    }
    exit();
}
?>