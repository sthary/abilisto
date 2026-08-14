<?php
// auth/verify_otp.php
include '../db.php';
include '../includes/sms_sender.php';

function formatPhoneForDisplay($phone) {
    if (substr($phone, 0, 2) == '63') {
        return '0' . substr($phone, 2);
    }
    return $phone;
}

if (isset($_SESSION['email']) && !empty($_SESSION['email'])) {
    $email = $_SESSION['email'];
} elseif (isset($_GET['email']) && !empty($_GET['email'])) {
    $email = $_GET['email'];
    $_SESSION['email'] = $email;
}

if (empty($email)) {
    die("Error: Session expired or Email missing. <a href='login.php'>Login again</a>");
}

$sql = "SELECT phone, is_phone_verified FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (empty($user['phone'])) {
    header("Location: manage_phone.php?email=" . urlencode($email));
    exit();
}

$phone = $user['phone'];
$error = '';
$success = '';
$dev_otp = '';
$otp_sent = false;

// Send Code button
if (isset($_POST['send_otp_btn'])) {
    $otp_result = sendOTP($phone);
    if (isset($otp_result['status']) && $otp_result['status'] == 'success') {
        $success = "Verification code sent to your phone!";
        $otp_sent = true;
        $_SESSION['otp_sent'] = true;
        if (isset($otp_result['dev_otp']))            $dev_otp = $otp_result['dev_otp'];
        if (isset($otp_result['data']['otp_code']))   $dev_otp = $otp_result['data']['otp_code'];
    } else {
        $error = "Failed to send code. Please try again.";
    }
}

// Resend button
if (isset($_POST['resend_btn'])) {
    $otp_result = sendOTP($phone);
    if (isset($otp_result['status']) && $otp_result['status'] == 'success') {
        $success = "New verification code sent!";
        $otp_sent = true;
        $_SESSION['otp_sent'] = true;
        if (isset($otp_result['dev_otp']))            $dev_otp = $otp_result['dev_otp'];
        if (isset($otp_result['data']['otp_code']))   $dev_otp = $otp_result['data']['otp_code'];
    } else {
        $error = "Failed to resend code. Please try again.";
    }
}

// Persist otp_sent across page load
if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent']) {
    $otp_sent = true;
}

// Verify OTP
if (isset($_POST['verify_btn'])) {
    $otp_input  = $_POST['otp'];
    $api_result = verifyOTP($phone, $otp_input);

    if (isset($api_result['status']) && $api_result['status'] == 'success') {
        $conn->query("UPDATE users SET is_phone_verified = 1 WHERE email = '" . $conn->real_escape_string($email) . "'");
        unset($_SESSION['otp_sent']);
        unset($_SESSION['pending_verify_email']);
        unset($_SESSION['pending_verify_role']);
        $_SESSION['flash_success'] = "✅ Phone verified! Please log in to continue.";

        echo "<script>
                alert('✅ Phone verified! Please log in to your account.');
                window.location.href='login.php';
              </script>";
        exit();
    } else {
        $msg   = isset($api_result['message']) ? $api_result['message'] : "Invalid Code";
        $error = $msg;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Verify Phone | Abilisto</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>

    <!-- Fonts -->
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
                        primary: "#2563eb",
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
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .radial-bg {
            background: radial-gradient(circle at center, #ffffff 0%, #e0eaff 100%);
        }
        .dark .radial-bg {
            background: radial-gradient(circle at center, #1e293b 0%, #0f172a 100%);
        }
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .inner-glow { box-shadow: inset 0 1px 1px rgba(255,255,255,0.4); }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }

        /* OTP input */
        .otp-input {
            width: 100%;
            font-size: 2.25rem;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.6em;
            padding: 1rem 0;
            border: 2px solid #e2e8f0;
            border-radius: 1rem;
            outline: none;
            font-family: monospace;
            background: rgba(255,255,255,0.5);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .otp-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .dark .otp-input {
            background: rgba(30,41,59,0.5);
            border-color: #334155;
            color: white;
        }

        /* Dev OTP panel */
        .dev-panel {
            background: #1e1e2f;
            border: 2px dashed #4f46e5;
            border-radius: 1rem;
            padding: 1.25rem;
        }
        .dev-otp-code {
            font-family: monospace;
            font-size: 2.75rem;
            font-weight: 800;
            letter-spacing: 0.5em;
            color: #818cf8;
            background: #2d2d3f;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            display: inline-block;
        }
    </style>
</head>
<body class="radial-bg min-h-screen flex items-center justify-center p-4 transition-colors duration-300">

<div class="w-full max-w-lg animate-slideUp">
    <div class="glass-card rounded-2xl shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.5)] p-8 md:p-12 border border-blue-100 dark:border-slate-700/50">

        <!-- Logo -->
        <div class="text-center mb-10">
            <div class="flex items-center justify-center gap-1 mb-2">
                <span class="text-4xl font-extrabold tracking-tight text-primary">Abi</span>
                <span class="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white">listo</span>
            </div>
            <p class="text-xs font-semibold tracking-[0.2em] uppercase text-slate-400 dark:text-slate-500">Abilidad. Bilis. Listo.</p>
        </div>

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <span class="material-symbols-rounded text-5xl text-primary">
                    <?php echo $otp_sent ? 'sms' : 'smartphone'; ?>
                </span>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">
                <?php echo $otp_sent ? 'Enter Verification Code' : 'Verify Your Phone'; ?>
            </h1>
            <p class="text-slate-500 dark:text-slate-400">
                <?php echo $otp_sent
                    ? 'Enter the 6-digit code sent to your phone'
                    : 'We\'ll send a verification code via SMS'; ?>
            </p>
        </div>

        <!-- Phone info pill -->
        <div class="flex justify-center mb-8">
            <div class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/30 rounded-full">
                <span class="material-symbols-rounded text-primary text-sm">phone_iphone</span>
                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">
                    <?php echo htmlspecialchars(formatPhoneForDisplay($phone)); ?>
                </span>
            </div>
        </div>

        <!-- Dev OTP panel -->
        <?php if ($dev_otp): ?>
        <div class="dev-panel mb-8 text-center">
            <span class="inline-block bg-indigo-600 text-white text-xs font-bold px-3 py-1 rounded-full mb-3 tracking-wider uppercase">
                🔧 Development Mode
            </span>
            <p class="text-indigo-300 text-xs uppercase tracking-widest font-semibold mb-2">OTP Code (testing only)</p>
            <div class="dev-otp-code" id="otpDisplay"><?php echo htmlspecialchars($dev_otp); ?></div>
            <button onclick="copyOTP()"
                    class="mt-3 text-xs border border-indigo-500 text-indigo-400 px-4 py-1.5 rounded-full hover:bg-indigo-500 hover:text-white transition-all">
                Copy Code
            </button>
        </div>
        <?php endif; ?>

        <!-- Error alert -->
        <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-red-500 flex-shrink-0">error</span>
            <span class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Success alert -->
        <?php if ($success): ?>
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-green-500 flex-shrink-0">check_circle</span>
            <span class="text-sm font-medium text-green-700 dark:text-green-400"><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!$otp_sent): ?>
        <!-- STEP 1: Send Code -->
        <div class="mb-8 bg-blue-50 dark:bg-blue-900/10 rounded-xl p-5 border border-blue-100 dark:border-blue-800/20">
            <div class="flex items-start gap-3">
                <span class="material-symbols-rounded text-primary flex-shrink-0 mt-0.5">info</span>
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    Tap the button below and we'll send a 6-digit SMS verification code to your registered phone number.
                </p>
            </div>
        </div>

        <form method="POST">
            <button type="submit" name="send_otp_btn"
                    class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow">
                <span class="material-symbols-rounded">send_to_mobile</span>
                Send Verification Code
            </button>
        </form>

        <?php else: ?>
        <!-- STEP 2: Enter OTP -->
        <form method="POST" id="otpForm">
            <input type="text"
                   name="otp"
                   class="otp-input mb-2"
                   placeholder="000000"
                   maxlength="6"
                   pattern="\d{6}"
                   inputmode="numeric"
                   required
                   autocomplete="one-time-code"
                   id="otpInput"
                   <?php if ($dev_otp): ?>value="<?php echo htmlspecialchars($dev_otp); ?>"<?php endif; ?>>

            <!-- Timer -->
            <p class="text-center text-sm text-slate-400 dark:text-slate-500 mb-6" id="timerText"></p>

            <button type="submit" name="verify_btn"
                    class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow mb-4">
                <span class="material-symbols-rounded">verified_user</span>
                Verify Identity
            </button>
        </form>

        <!-- Resend -->
        <form method="POST">
            <button type="submit" name="resend_btn" id="resendBtn" disabled
                    class="w-full inline-flex items-center justify-center gap-2 bg-white/50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 py-4 rounded-xl transition-all font-semibold text-slate-700 dark:text-slate-200 disabled:opacity-40 disabled:cursor-not-allowed">
                <span class="material-symbols-rounded">refresh</span>
                Resend Code
            </button>
        </form>
        <?php endif; ?>

        <!-- Change phone link -->
        <div class="mt-6 text-center">
            <a href="manage_phone.php?email=<?php echo urlencode($email); ?>"
               class="inline-flex items-center gap-2 text-sm font-medium text-slate-400 dark:text-slate-500 hover:text-primary dark:hover:text-primary transition-colors">
                <span class="material-symbols-rounded text-lg">arrow_back</span>
                Change phone number
            </a>
        </div>

        <!-- Trust strip -->
        <div class="mt-10 pt-8 border-t border-slate-100 dark:border-slate-800 flex flex-wrap justify-center gap-x-8 gap-y-4">
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">verified_user</span>
                Secure SMS
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">encrypted</span>
                Encrypted
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">schedule</span>
                Code expires in 5 min
            </div>
        </div>

    </div>
</div>

<script>
    // Dark mode
    document.addEventListener('DOMContentLoaded', function () {
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }

        <?php if ($otp_sent): ?>
        // Auto-submit on 6 digits
        document.getElementById('otpInput').addEventListener('input', function () {
            if (this.value.length === 6) {
                document.querySelector('[name="verify_btn"]').click();
            }
        });

        // Resend cooldown timer
        let timeLeft = 60;
        const timerEl  = document.getElementById('timerText');
        const resendBtn = document.getElementById('resendBtn');

        function updateTimer() {
            if (timeLeft > 0) {
                timerEl.textContent = `You can resend the code in ${timeLeft}s`;
                resendBtn.disabled = true;
                timeLeft--;
            } else {
                timerEl.textContent = '';
                resendBtn.disabled = false;
            }
        }
        updateTimer();
        setInterval(updateTimer, 1000);
        <?php endif; ?>
    });

    function copyOTP() {
        const otp = document.getElementById('otpDisplay').innerText;
        navigator.clipboard.writeText(otp).then(() => alert('Copied: ' + otp));
    }
</script>
</body>
</html>