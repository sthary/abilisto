<?php
// auth/manage_phone.php
include '../db_connect.php';

$error = '';
$success = '';
$email = '';

// Get email from session or URL
if (isset($_SESSION['email']) && !empty($_SESSION['email'])) {
    $email = $_SESSION['email'];
} elseif (isset($_GET['email']) && !empty($_GET['email'])) {
    $email = $_GET['email'];
    $_SESSION['email'] = $email;
}

if (empty($email)) {
    die("Error: Session expired or Email missing. <a href='login.php'>Login again</a>");
}

// Get user's current phone number if exists
$sql = "SELECT phone, is_phone_verified FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$email]);
$user = $stmt->fetch();
$current_phone = $user['phone'] ?? '';
$is_verified = $user['is_phone_verified'] ?? 0;

// Format phone for display (add leading 0 if stored without it)
function formatPhoneForDisplay($phone) {
    // If phone starts with 63 (international format), convert to 09 format
    if (substr($phone, 0, 2) == '63') {
        return '0' . substr($phone, 2);
    }
    return $phone;
}

$display_phone = formatPhoneForDisplay($current_phone);

// Handle form submission
if (isset($_POST['continue_btn'])) {
    $phone = trim($_POST['phone']);
    
    // Remove any spaces or special characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Validate Philippine mobile number format (11 digits starting with 09)
    if (empty($phone)) {
        $error = "Phone number is required.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = "Please enter a valid 11-digit Philippine mobile number (e.g., 09123456789).";
    } else {
        // Convert to international format for storage (remove leading 0, add 63)
        $clean_phone = '63' . substr($phone, 1);
        
        // Update phone in database
        $update_sql = "UPDATE users SET phone = ?, is_phone_verified = FALSE WHERE email = ?";
        $update_stmt = $conn->prepare($update_sql);

        if ($update_stmt->execute([$clean_phone, $email])) {
            // Send OTP
            include '../includes/sms_sender.php';
            $otp_result = sendOTP($clean_phone);
            
            if (isset($otp_result['status']) && $otp_result['status'] == 'success') {
                // Store phone in session and redirect to OTP verification
                $_SESSION['phone'] = $clean_phone;
                header("Location: verify_otp.php?email=" . urlencode($email));
                exit();
            } else {
                $error = "Failed to send OTP. Please try again.";
            }
        } else {
            $error = "Failed to update phone number. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Manage Phone | Abilisto</title>

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

        /* Phone input styling */
        .phone-input {
            width: 100%;
            font-size: 1.25rem;
            font-weight: 500;
            text-align: center;
            letter-spacing: 0.1em;
            padding: 1rem 0;
            border: 2px solid #e2e8f0;
            border-radius: 1rem;
            outline: none;
            font-family: monospace;
            background: rgba(255,255,255,0.5);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .phone-input:focus {
            border-color: #146af5;
            box-shadow: 0 0 0 3px rgba(20,106,245,0.1);
        }
        .dark .phone-input {
            background: rgba(30,41,59,0.5);
            border-color: #334155;
            color: white;
        }
        .phone-input::placeholder {
            font-family: 'Plus Jakarta Sans', sans-serif;
            letter-spacing: normal;
            font-weight: 400;
            font-size: 0.9rem;
        }

        /* Current phone pill */
        .phone-pill {
            background: rgba(20, 106, 245, 0.1);
            border-radius: 9999px;
            padding: 0.5rem 1.25rem;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }
        .dark .phone-pill {
            background: rgba(20, 106, 245, 0.2);
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
                <span class="material-symbols-rounded text-5xl text-primary">phone_iphone</span>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Phone Verification</h1>
            <p class="text-slate-500 dark:text-slate-400">Please verify your Philippine mobile number to continue</p>
        </div>

        <!-- Error alert -->
        <?php if($error): ?>
        <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-red-500 flex-shrink-0">error</span>
            <span class="text-sm font-medium text-red-700 dark:text-red-400"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Success alert (if any) -->
        <?php if($success): ?>
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl flex items-center gap-3">
            <span class="material-symbols-rounded text-green-500 flex-shrink-0">check_circle</span>
            <span class="text-sm font-medium text-green-700 dark:text-green-400"><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <?php if($current_phone && $is_verified): ?>
            <!-- Case 1: User has verified phone number -->
            <div class="mb-8 text-center">
                <div class="phone-pill mx-auto w-fit">
                    <span class="material-symbols-rounded text-primary text-xl">check_circle</span>
                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">
                        <?php echo htmlspecialchars($display_phone); ?>
                    </span>
                    <span class="text-xs bg-green-500 text-white px-2 py-0.5 rounded-full">Verified</span>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-3">Your current verified phone number</p>
            </div>

            <form method="POST" id="phoneForm">
                <div class="mb-6" style="display: none;" id="editField">
                    <input type="tel" 
                           name="phone" 
                           id="phone"
                           class="phone-input" 
                           placeholder="09123456789"
                           value="<?php echo htmlspecialchars($display_phone); ?>"
                           maxlength="11"
                           pattern="[0-9]{11}"
                           inputmode="numeric">
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">Enter 11-digit mobile number (e.g., 09123456789)</p>
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500" id="charCount"><?php echo strlen($display_phone); ?>/11 digits</p>
                </div>
                
                <button type="button" class="w-full inline-flex items-center justify-center gap-2 bg-white/50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 py-4 rounded-xl transition-all font-semibold text-slate-700 dark:text-slate-200 mb-4" id="editBtn" onclick="toggleEdit()">
                    <span class="material-symbols-rounded">edit</span>
                    Edit Phone Number
                </button>
                <button type="submit" name="continue_btn" class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow" id="continueBtn">
                    <span class="material-symbols-rounded">check_circle</span>
                    Continue with this number
                </button>
            </form>

        <?php elseif($current_phone && !$is_verified): ?>
            <!-- Case 2: User has phone but not verified -->
            <div class="mb-6 text-center">
                <div class="phone-pill mx-auto w-fit bg-amber-50 dark:bg-amber-900/20">
                    <span class="material-symbols-rounded text-amber-500 text-xl">priority_high</span>
                    <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">
                        <?php echo htmlspecialchars($display_phone); ?>
                    </span>
                </div>
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-3">⚠️ Not verified yet</p>
            </div>

            <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl flex items-start gap-3">
                <span class="material-symbols-rounded text-amber-500 flex-shrink-0">info</span>
                <p class="text-sm text-amber-700 dark:text-amber-400">This number hasn't been verified yet. Please verify or update it.</p>
            </div>

            <form method="POST">
                <div class="mb-6">
                    <input type="tel" 
                           name="phone" 
                           id="phone"
                           class="phone-input" 
                           placeholder="09123456789"
                           value="<?php echo htmlspecialchars($display_phone); ?>"
                           maxlength="11"
                           pattern="[0-9]{11}"
                           inputmode="numeric"
                           required>
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">Enter 11-digit mobile number starting with 09</p>
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500" id="charCount"><?php echo strlen($display_phone); ?>/11 digits</p>
                </div>
                
                <button type="submit" name="continue_btn" class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow">
                    <span class="material-symbols-rounded">send_to_mobile</span>
                    Send Verification Code
                </button>
            </form>

        <?php else: ?>
            <!-- Case 3: No phone number (e.g., Google login users) -->
            <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl flex items-start gap-3">
                <span class="material-symbols-rounded text-primary flex-shrink-0">info</span>
                <p class="text-sm text-slate-600 dark:text-slate-400">To continue, please provide your Philippine mobile number. We'll send a 6-digit verification code.</p>
            </div>

            <form method="POST">
                <div class="mb-6">
                    <input type="tel" 
                           name="phone" 
                           id="phone"
                           class="phone-input" 
                           placeholder="09123456789"
                           maxlength="11"
                           pattern="[0-9]{11}"
                           inputmode="numeric"
                           required
                           autofocus>
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500 mt-2">Enter 11-digit mobile number starting with 09 (e.g., 09123456789)</p>
                    <p class="text-center text-xs text-slate-400 dark:text-slate-500" id="charCount">0/11 digits</p>
                </div>
                
                <button type="submit" name="continue_btn" class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 inner-glow">
                    <span class="material-symbols-rounded">send_to_mobile</span>
                    Send Verification Code
                </button>
            </form>
        <?php endif; ?>

        <!-- Cancel / Back link -->
        <div class="mt-8 text-center">
            <a href="logout.php" class="inline-flex items-center gap-2 text-sm font-medium text-slate-400 dark:text-slate-500 hover:text-primary dark:hover:text-primary transition-colors">
                <span class="material-symbols-rounded text-lg">cancel</span>
                Cancel and go back to login
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
    // Dark mode detection
    document.addEventListener('DOMContentLoaded', function () {
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
        
        // Character counter for phone input
        const phoneInput = document.getElementById('phone');
        const charCount = document.getElementById('charCount');
        
        if (phoneInput && charCount) {
            function updateCharCount() {
                let length = phoneInput.value.length;
                // Allow only numbers
                phoneInput.value = phoneInput.value.replace(/[^0-9]/g, '');
                length = phoneInput.value.length;
                charCount.textContent = length + '/11 digits';
                
                if (length === 11 && phoneInput.value.match(/^09/)) {
                    charCount.classList.add('text-green-500');
                    charCount.classList.remove('text-red-500');
                } else {
                    charCount.classList.add('text-red-500');
                    charCount.classList.remove('text-green-500');
                }
            }
            
            phoneInput.addEventListener('input', updateCharCount);
            updateCharCount();
        }
    });

    // Toggle edit mode for verified users
    function toggleEdit() {
        const editField = document.getElementById('editField');
        const editBtn = document.getElementById('editBtn');
        const continueBtn = document.getElementById('continueBtn');
        
        if (editField.style.display === 'none') {
            editField.style.display = 'block';
            editBtn.innerHTML = '<span class="material-symbols-rounded">close</span> Cancel Edit';
            continueBtn.innerHTML = '<span class="material-symbols-rounded">save</span> Save and Continue';
        } else {
            editField.style.display = 'none';
            editBtn.innerHTML = '<span class="material-symbols-rounded">edit</span> Edit Phone Number';
            continueBtn.innerHTML = '<span class="material-symbols-rounded">check_circle</span> Continue with this number';
        }
    }

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>
</body>
</html>