<?php
// client/security.php
// Dedicated Security & Password page — reuses auth/forgot_pass.php's exact
// OTP pattern (6-digit code, hashed into session, 10-minute expiry, 5-attempt
// cap, delivered via includes/mailer.php's sendAbilistoEmail), adapted for an
// already-logged-in user changing their own password: no email-entry step
// needed since we already know who they are, and no new login session is
// created at the end since they're already in one.
session_start();
include '../db_connect.php';
include '../includes/mailer.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['client', 'worker'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$back_link = $_SESSION['role'] === 'worker' ? '../worker/profile_edit.php' : 'profile.php';

// ── STEP STATE: 'start' -> 'code' -> 'password' -> done ─────────────────
$step  = $_SESSION['sec_step'] ?? 'start';
$error = '';
$info  = '';

// ── POST: send the code ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sec_send'])) {
    $stmt = $conn->prepare("SELECT id, full_name, email, is_email_verified FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user['is_email_verified']) {
        $error = "Your account's email isn't verified yet, so we can't send a code. Please verify your email first.";
        $step  = 'start';
    } else {
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = time() + 600; // 10 minutes

        $_SESSION['sec_step']     = 'code';
        $_SESSION['sec_otp']      = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['sec_expires']  = $expires;
        $_SESSION['sec_attempts'] = 0;

        $subject   = "Your Abilisto Security Code";
        $html_body = "
        <div style='font-family:\"Plus Jakarta Sans\",Arial,sans-serif; max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;'>
            <div style='background:linear-gradient(135deg,#60a5fa,#0f52c2); padding:32px; text-align:center;'>
                <span style='font-size:22px; font-weight:800; color:#fff; letter-spacing:-0.03em;'>Abi<span style='opacity:0.85'>listo</span></span>
            </div>
            <div style='padding:36px 32px;'>
                <h2 style='font-size:20px; font-weight:700; color:#0f172a; margin:0 0 8px;'>Confirm Your Password Change</h2>
                <p style='color:#64748b; font-size:15px; line-height:1.6; margin:0 0 28px;'>
                    Hi <strong style='color:#0f172a;'>" . htmlspecialchars($user['full_name']) . "</strong>,<br>
                    Use the code below to confirm you want to change your Abilisto password. It expires in <strong>10 minutes</strong>.
                </p>
                <div style='background:#f8fafc; border:2px dashed #e2e8f0; border-radius:12px; padding:24px; text-align:center; margin-bottom:28px;'>
                    <span style='font-size:40px; font-weight:800; letter-spacing:0.18em; color:#146af5; font-family:monospace;'>" . $otp . "</span>
                </div>
                <p style='color:#94a3b8; font-size:13px; line-height:1.6; margin:0;'>
                    If you didn't request this, your password is still safe — just ignore this email.
                </p>
            </div>
            <div style='background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px; text-align:center;'>
                <p style='color:#cbd5e1; font-size:12px; margin:0;'>&copy; " . date("Y") . " Abilisto. All rights reserved.</p>
            </div>
        </div>";

        $sent = sendAbilistoEmail($user['email'], $subject, $html_body);

        if (!$sent) {
            unset($_SESSION['sec_step'], $_SESSION['sec_otp'], $_SESSION['sec_expires'], $_SESSION['sec_attempts']);
            $error = "Failed to send the code. Please try again.";
            $step  = 'start';
        } else {
            $step = 'code';
            $info = "A 6-digit code was sent to " . htmlspecialchars(substr($user['email'], 0, 2)) . str_repeat('•', max(0, strpos($user['email'], '@') - 2)) . substr($user['email'], strpos($user['email'], '@'));
        }
    }
}

// ── POST: verify the code ────────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sec_code'])) {
    $step = 'code';
    $entered = trim($_POST['sec_code']);

    if (!isset($_SESSION['sec_otp']) || !isset($_SESSION['sec_expires'])) {
        $error = "Session expired. Please start over.";
        $step  = 'start';
        unset($_SESSION['sec_step']);
    } elseif (time() > $_SESSION['sec_expires']) {
        $error = "This code has expired. Please request a new one.";
        unset($_SESSION['sec_step'], $_SESSION['sec_otp'], $_SESSION['sec_expires']);
        $step = 'start';
    } else {
        $_SESSION['sec_attempts'] = ($_SESSION['sec_attempts'] ?? 0) + 1;

        if ($_SESSION['sec_attempts'] > 5) {
            $error = "Too many attempts. Please start over.";
            unset($_SESSION['sec_step'], $_SESSION['sec_otp'], $_SESSION['sec_expires'], $_SESSION['sec_attempts']);
            $step = 'start';
        } elseif (!password_verify($entered, $_SESSION['sec_otp'])) {
            $remaining = 5 - $_SESSION['sec_attempts'];
            $error = "Incorrect code. $remaining attempt" . ($remaining !== 1 ? 's' : '') . " remaining.";
        } else {
            $_SESSION['sec_step']      = 'password';
            $_SESSION['sec_otp_valid'] = true;
            unset($_SESSION['sec_otp'], $_SESSION['sec_expires'], $_SESSION['sec_attempts']);
            $step = 'password';
        }
    }
}

// ── POST: set the new password ───────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sec_newpass'])) {
    $step = 'password';

    if (empty($_SESSION['sec_otp_valid'])) {
        $error = "Session invalid. Please start over.";
        $step  = 'start';
        unset($_SESSION['sec_step'], $_SESSION['sec_otp_valid']);
    } else {
        $new_pass     = $_POST['sec_newpass'];
        $confirm_pass = $_POST['sec_confirm'];

        if (strlen($new_pass) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "Passwords don't match.";
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $conn->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);

            unset($_SESSION['sec_step'], $_SESSION['sec_otp_valid']);
            $step = 'done';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $step = $_SESSION['sec_step'] ?? 'start';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Security & Password | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: {
                colors: { primary: "#146af5", "background-light": "#f8faff", "background-dark": "#0f172a" },
                fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
            } },
        };
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.3); }
        .dark .glass-card { background: rgba(30,41,59,0.7); border: 1px solid rgba(255,255,255,0.1); }
        .radial-bg { background: radial-gradient(circle at center, #ffffff 0%, #e0eaff 100%); }
        .dark .radial-bg { background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%); }
        .otp-box { width: 3rem; height: 3.5rem; text-align: center; font-size: 1.5rem; font-weight: 800; }
        .step-dot { width: 8px; height: 8px; border-radius: 999px; background: #cbd5e1; transition: all 0.2s; }
        .step-dot.active { width: 24px; background: #146af5; }
        .step-dot.done { background: #146af5; }
    </style>
</head>
<body class="radial-bg min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<div class="w-full max-w-md">
    <div class="glass-card rounded-2xl shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.5)] p-8 md:p-10 border border-blue-100 dark:border-slate-700/50">

        <div class="text-center mb-8">
            <div class="size-14 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-rounded text-primary text-2xl">shield</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-1">Security & Password</h1>
            <p class="text-slate-500 dark:text-slate-400 text-sm">Changing your password requires an email confirmation code, same as password recovery.</p>
        </div>

        <!-- Step indicator -->
        <?php if ($step !== 'done'): ?>
        <div class="flex items-center justify-center gap-2 mb-8">
            <div class="step-dot <?php echo $step === 'start' ? 'active' : 'done'; ?>"></div>
            <div class="step-dot <?php echo $step === 'code' ? 'active' : ($step === 'password' ? 'done' : ''); ?>"></div>
            <div class="step-dot <?php echo $step === 'password' ? 'active' : ''; ?>"></div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-red-500">error</span>
            <span class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($info): ?>
        <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-blue-500">mail</span>
            <span class="text-sm font-medium text-blue-700 dark:text-blue-400"><?php echo $info; ?></span>
        </div>
        <?php endif; ?>

        <?php if ($step === 'start'): ?>
        <form method="POST">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">We'll email a 6-digit code to your registered email address to confirm it's really you before changing your password.</p>
            <button type="submit" name="sec_send" class="w-full bg-gradient-to-r from-primary to-blue-600 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-2">
                <span class="material-symbols-rounded">mail</span>
                Send Verification Code
            </button>
        </form>

        <?php elseif ($step === 'code'): ?>
        <form method="POST" id="codeForm">
            <div class="flex justify-center gap-2 mb-6" id="otpBoxes">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" inputmode="numeric" maxlength="1" class="otp-box rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none dark:text-white">
                <?php endfor; ?>
            </div>
            <input type="hidden" name="sec_code" id="sec_code_hidden">
            <button type="submit" class="w-full bg-gradient-to-r from-primary to-blue-600 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-primary/20">
                Verify Code
            </button>
            <div class="text-center mt-4">
                <form method="POST" class="inline">
                    <button type="submit" name="sec_send" class="text-sm font-medium text-primary hover:underline">Resend code</button>
                </form>
            </div>
        </form>
        <script>
            const boxes = document.querySelectorAll('#otpBoxes input');
            const hidden = document.getElementById('sec_code_hidden');
            boxes.forEach((box, i) => {
                box.addEventListener('input', () => {
                    box.value = box.value.replace(/[^0-9]/g, '');
                    if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
                    hidden.value = Array.from(boxes).map(b => b.value).join('');
                    if (hidden.value.length === 6) document.getElementById('codeForm').submit();
                });
                box.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
                });
            });
            boxes[0]?.focus();
        </script>

        <?php elseif ($step === 'password'): ?>
        <form method="POST" class="space-y-5">
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 ml-1">New Password</label>
                <div class="relative">
                    <input type="password" id="secNewPass" name="sec_newpass" minlength="8" required
                           class="block w-full pl-4 pr-12 py-3.5 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none dark:text-white">
                    <button type="button" onclick="toggleVis('secNewPass','secEye1')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-primary">
                        <span class="material-symbols-rounded" id="secEye1">visibility</span>
                    </button>
                </div>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 ml-1">Confirm New Password</label>
                <div class="relative">
                    <input type="password" id="secConfirmPass" name="sec_confirm" minlength="8" required
                           class="block w-full pl-4 pr-12 py-3.5 rounded-xl border-slate-200 dark:border-slate-700 bg-white/50 dark:bg-slate-900/50 focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none dark:text-white">
                    <button type="button" onclick="toggleVis('secConfirmPass','secEye2')" class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-primary">
                        <span class="material-symbols-rounded" id="secEye2">visibility</span>
                    </button>
                </div>
            </div>
            <button type="submit" name="sec_newpass_submit" onclick="this.form.querySelector('[name=sec_newpass]').required=true"
                    class="w-full bg-gradient-to-r from-primary to-blue-600 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-primary/20">
                Update Password
            </button>
        </form>

        <?php elseif ($step === 'done'): ?>
        <div class="text-center">
            <div class="size-16 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-rounded text-emerald-500 text-3xl">check_circle</span>
            </div>
            <h2 class="text-xl font-bold text-slate-900 dark:text-white mb-2">Password Updated!</h2>
            <p class="text-slate-500 dark:text-slate-400 text-sm mb-6">Your password has been changed successfully.</p>
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="block w-full bg-gradient-to-r from-primary to-blue-600 text-white font-bold py-4 rounded-xl text-center">Back to Profile</a>
        </div>
        <?php endif; ?>

        <?php if ($step !== 'done'): ?>
        <div class="mt-8 text-center">
            <a href="<?php echo htmlspecialchars($back_link); ?>" class="text-sm font-medium text-slate-400 hover:text-primary">Cancel and go back</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    });
    function toggleVis(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (!input || !icon) return;
        if (input.type === 'password') { input.type = 'text'; icon.textContent = 'visibility_off'; }
        else { input.type = 'password'; icon.textContent = 'visibility'; }
    }
</script>
</body>
</html>
