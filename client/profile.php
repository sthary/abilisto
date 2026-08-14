<?php
// client/profile.php

include '../db.php';
include '../includes/init_lang.php';
include '../includes/sms_sender.php';

// 2. Security Check
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = ""; $msg_type = ""; $otp_modal_open = false;

// --- 3. HANDLE LOGIC (Kept exactly the same) ---

// A. Handle Profile Update
if (isset($_POST['update_profile'])) {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $address = $conn->real_escape_string($_POST['address']);
    $municipality = $conn->real_escape_string($_POST['municipality']);
    $new_lat = floatval($_POST['latitude']);
    $new_lng = floatval($_POST['longitude']);
    $new_phone = $conn->real_escape_string($_POST['phone']);

    $curr_check = $conn->query("SELECT phone FROM users WHERE id = '$user_id'")->fetch_assoc();
    $current_phone_db = $curr_check['phone'];

    $update_sql = "UPDATE users SET 
                   full_name='$full_name', 
                   address='$address', 
                   municipality='$municipality',
                   latitude='$new_lat', 
                   longitude='$new_lng' 
                   WHERE id='$user_id'";
    $conn->query($update_sql);

    if ($new_phone != $current_phone_db) {
        // Send OTP Logic
        $otp_response = sendOTP($new_phone);
        $_SESSION['temp_new_phone'] = $new_phone;
        
        if (isset($otp_response['data']['otp_code'])) {
            $_SESSION['temp_otp'] = $otp_response['data']['otp_code'];
        } else {
            $_SESSION['temp_otp'] = rand(100000, 999999);
        }
        $otp_modal_open = true; 
    } else {
        $msg = "Profile updated successfully!";
        $msg_type = "success";
    }
}

// B. Handle OTP Verification
if (isset($_POST['verify_otp'])) {
    if ($_POST['otp_code'] == $_SESSION['temp_otp']) {
        $new_p = $_SESSION['temp_new_phone'];
        $conn->query("UPDATE users SET phone='$new_p', is_phone_verified=1 WHERE id='$user_id'");
        unset($_SESSION['temp_new_phone']); 
        unset($_SESSION['temp_otp']);
        echo "<script>alert('✅ Phone Number Verified & Updated!'); window.location.href='profile.php';</script>";
        exit();
    } else { 
        $msg = "Invalid OTP Code"; 
        $msg_type = "error"; 
        $otp_modal_open = true; 
    }
}

// C. Handle Password Change
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    
    $pass_check = $conn->query("SELECT password FROM users WHERE id = '$user_id'")->fetch_assoc();

    if (password_verify($current_pass, $pass_check['password'])) {
        if ($new_pass === $confirm_pass) {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET password='$hashed' WHERE id='$user_id'");
            $msg = "Password changed successfully!";
            $msg_type = "success";
        } else {
            $msg = "New passwords do not match.";
            $msg_type = "error";
        }
    } else {
        $msg = "Current password is incorrect.";
        $msg_type = "error";
    }
}

// --- 4. FETCH DATA ---
$sql = "SELECT * FROM users WHERE id = '$user_id'";
$user = $conn->query($sql)->fetch_assoc();

$lat = $user['latitude'] ?: 12.8797; 
$lng = $user['longitude'] ?: 121.7740;

// Helper function to get initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

$initials = getInitials($user['full_name']);
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Profile | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet"/>
    
    <!-- Font Awesome (for backward compatibility) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#2563EB",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0F172A",
                        accent: {
                            blue: "#3B82F6",
                            green: "#10B981",
                            red: "#EF4444"
                        }
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        '2xl': '24px',
                    },
                },
            },
        };
    </script>
    
    <style type="text/tailwindcss">
        .glass {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3)
        }
        .dark .glass {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1)
        }
        .vibrant-gradient {
            background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
        }
        
        /* Animation for slideUp */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-slideUp {
            animation: slideUp 0.5s ease forwards;
        }
        
        /* Shrunk card styles */
        .shrunk-card {
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .shrunk-text {
            font-size: 0.95rem;
        }
        
        .shrunk-input {
            padding: 0.5rem 1rem !important;
            font-size: 0.95rem !important;
        }
        
        .shrunk-button {
            padding: 0.5rem 1.5rem !important;
            font-size: 0.9rem !important;
        }
        
        /* Icon styles */
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.25rem;
        }
        
        .input-with-icon {
            padding-left: 40px !important;
        }
        
        /* In your <style> section */
.mobile-bottom-spacing {
    padding-bottom: 60px !important; /* Reduced from 80px */
}

@media (max-width: 768px) {
    main {
        padding-bottom: 70px !important; /* Reduced from 100px */
    }
    
    /* Reduce spacing between sections on mobile */
    .space-y-4 > :not([hidden]) ~ :not([hidden]) {
        --tw-space-y-reverse: 0;
        margin-top: calc(1rem * calc(1 - var(--tw-space-y-reverse))) !important;
        margin-bottom: calc(1rem * var(--tw-space-y-reverse)) !important;
    }
}
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-sans transition-colors duration-300 min-h-screen pb-12">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-4xl mx-auto px-4 pt-6 space-y-6 shrunk-card">
    
    <!-- Alert Messages -->
    <?php if($msg): ?>
    <div class="bg-<?php echo $msg_type == 'success' ? 'accent-green/10 border-accent-green/20 text-accent-green' : 'accent-red/10 border-accent-red/20 text-accent-red'; ?> border rounded-xl p-3 flex items-center gap-2 animate-slideUp shrunk-text">
        <span class="material-symbols-outlined text-base"><?php echo $msg_type == 'success' ? 'check_circle' : 'error'; ?></span>
        <span class="font-medium"><?php echo $msg; ?></span>
        <button class="ml-auto" onclick="this.parentElement.remove()">
            <span class="material-symbols-outlined text-base">close</span>
        </button>
    </div>
    <?php endif; ?>
    
    <!-- Profile Card -->
    <section class="relative overflow-hidden rounded-xl bg-white dark:bg-slate-800 shadow-md border border-slate-100 dark:border-slate-700 p-4 md:p-6 animate-slideUp">
        <div class="absolute -top-20 -right-20 w-48 h-48 bg-primary/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 w-48 h-48 bg-accent-blue/10 rounded-full blur-3xl"></div>
        
        <div class="relative flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                <div class="relative">
                    <?php 
                        $avatar = !empty($user['profile_pic']) && file_exists("../uploads/profiles/".$user['profile_pic']) 
                            ? "../uploads/profiles/".$user['profile_pic'] 
                            : '';
                    ?>
                    <?php if ($avatar): ?>
                        <img src="<?php echo $avatar; ?>" class="w-16 h-16 md:w-20 md:h-20 rounded-full object-cover shadow-md border-2 border-white dark:border-slate-800" alt="Profile">
                    <?php else: ?>
                        <div class="w-16 h-16 md:w-20 md:h-20 rounded-full vibrant-gradient flex items-center justify-center text-white text-xl md:text-2xl font-extrabold shadow-md border-2 border-white dark:border-slate-800">
                            <?php echo $initials; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if($user['is_email_verified'] && $user['is_phone_verified']): ?>
                    <div class="absolute bottom-0 right-0 bg-accent-green text-white p-0.5 rounded-full border border-white dark:border-slate-800 flex items-center justify-center shadow-sm">
                        <span class="material-symbols-outlined text-xs">check</span>
                    </div>
                    <?php elseif($user['is_email_verified']): ?>
                    <div class="absolute bottom-0 right-0 bg-primary text-white p-0.5 rounded-full border border-white dark:border-slate-800 flex items-center justify-center shadow-sm">
                        <span class="material-symbols-outlined text-xs">mail</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="text-center md:text-left space-y-2">
                    <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                        <?php echo htmlspecialchars($user['full_name']); ?>
                    </h1>
                    
                    <div class="flex flex-wrap justify-center md:justify-start gap-2">
                        <div class="glass flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium text-slate-600 dark:text-slate-300">
                            <span class="material-symbols-outlined text-primary text-xs">calendar_today</span>
                            Member since <?php echo date("F Y", strtotime($user['created_at'])); ?>
                        </div>
                        <div class="glass flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium text-slate-600 dark:text-slate-300">
                            <span class="material-symbols-outlined text-primary text-xs">schedule</span>
                            Last active today
                        </div>
                    </div>
                    
                    <div class="flex flex-wrap justify-center md:justify-start gap-2">
                        <!-- Email Verification Status -->
                        <div class="<?php echo $user['is_email_verified'] ? 'bg-accent-green/10 border-accent-green/20 text-accent-green' : 'bg-accent-red/10 border-accent-red/20 text-accent-red'; ?> border flex items-center gap-1 px-3 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider">
                            <span class="material-symbols-outlined text-xs"><?php echo $user['is_email_verified'] ? 'verified_user' : 'error'; ?></span>
                            Email <?php echo $user['is_email_verified'] ? 'Verified' : 'Not Verified'; ?>
                            <?php if(!$user['is_email_verified']): ?>
                                <a href="../auth/resend_email.php" class="underline ml-1">Verify</a>
                            <?php endif; ?>
                        </div>

                        <!-- Phone Verification Status -->
                        <div class="<?php echo $user['is_phone_verified'] ? 'bg-accent-green/10 border-accent-green/20 text-accent-green' : 'bg-accent-red/10 border-accent-red/20 text-accent-red'; ?> border flex items-center gap-1 px-3 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider">
                            <span class="material-symbols-outlined text-xs"><?php echo $user['is_phone_verified'] ? 'phonelink_setup' : 'error'; ?></span>
                            Phone <?php echo $user['is_phone_verified'] ? 'Verified' : 'Not Verified'; ?>
                            <?php if(!$user['is_phone_verified']): ?>
                                <a href="../auth/verify_otp.php" class="underline ml-1">Verify</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <a href="../auth/logout.php" class="flex items-center gap-1 px-4 py-2 border border-accent-red/20 text-accent-red hover:bg-accent-red hover:text-white transition-all duration-300 font-bold rounded-lg text-sm group">
                <span class="material-symbols-outlined text-base transform group-hover:-translate-x-1 transition-transform">logout</span>
                Sign Out
            </a>
        </div>
    </section>
    
    <!-- Main Form -->
    <form method="POST" id="main-form">
        <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp" style="animation-delay: 0.1s;">
            <div class="p-4 md:p-5 flex items-center gap-2 border-b border-slate-100 dark:border-slate-700">
                <div class="p-2 bg-primary/10 rounded-lg">
                    <span class="material-symbols-outlined text-primary text-xl">edit_note</span>
                </div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Personal Information</h2>
            </div>
            
            <div class="p-4 md:p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Full Name -->
                    <div class="space-y-1">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Full Name</label>
                        <div class="relative">
                            <span class="material-symbols-outlined input-icon">person</span>
                            <input type="text" name="full_name" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                    </div>

                    <!-- Municipality (Text Input) -->
                    <div class="space-y-1">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Municipality</label>
                        <div class="relative">
                            <span class="material-symbols-outlined input-icon">location_city</span>
                            <input type="text" name="municipality" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" value="<?php echo htmlspecialchars($user['municipality']); ?>" placeholder="Enter municipality" required>
                        </div>
                    </div>

                    <!-- Email (Disabled) -->
                    <div class="space-y-1">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Email Address</label>
                        <div class="relative">
                            <span class="material-symbols-outlined input-icon">email</span>
                            <input type="email" class="w-full bg-slate-50 dark:bg-slate-900 border-none rounded-lg py-2 px-3 pl-10 text-slate-500 dark:text-slate-400 text-sm font-medium cursor-not-allowed" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        </div>
                    </div>

                    <!-- Phone -->
                    <div class="space-y-1">
                        <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Phone Number</label>
                        <div class="relative">
                            <span class="material-symbols-outlined input-icon">phone</span>
                            <input type="tel" name="phone" id="phone-input" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Changing this will require OTP verification</p>
                    </div>
                </div>

                <!-- Address -->
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Street / Barangay Address</label>
                    <div class="relative">
                        <span class="material-symbols-outlined input-icon">home</span>
                        <input type="text" name="address" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" value="<?php echo htmlspecialchars($user['address']); ?>">
                    </div>
                </div>

                <!-- Hidden lat/lng fields (keeping for backward compatibility) -->
                <input type="hidden" name="latitude" id="lat" value="<?php echo $lat; ?>">
                <input type="hidden" name="longitude" id="lng" value="<?php echo $lng; ?>">

                <!-- Save Button (inside card for both mobile and desktop) -->
                <div class="flex justify-end pt-3">
                    <button type="button" onclick="checkPhoneChange()" class="w-full md:w-auto vibrant-gradient text-white px-6 py-2 rounded-lg font-bold flex items-center justify-center gap-2 hover:scale-[1.02] active:scale-95 transition-all shadow-sm shadow-primary/25">
                        <span class="material-symbols-outlined text-sm">save</span>
                        Save Changes
                    </button>
                </div>
            </div>
        </section>
        <input type="hidden" name="update_profile" value="1">
    </form>
    
    <!-- Security Section -->
    <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp" style="animation-delay: 0.2s;">
        <div class="cursor-pointer" onclick="toggleSecurity()">
            <div class="p-4 md:p-5 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="p-2 bg-accent-green/10 rounded-lg">
                        <span class="material-symbols-outlined text-accent-green text-xl">shield</span>
                    </div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Security & Password</h2>
                </div>
                <span class="material-symbols-outlined text-slate-400 transition-transform" id="securityChevron">expand_more</span>
            </div>
        </div>
        
        <div id="securityContent" class="hidden">
            <div class="p-4 md:p-6 pt-0 border-t border-slate-100 dark:border-slate-700">
                <form method="POST">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Current Password</label>
                            <div class="relative">
                                <span class="material-symbols-outlined input-icon">lock</span>
                                <input type="password" name="current_password" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" required>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">New Password</label>
                            <div class="relative">
                                <span class="material-symbols-outlined input-icon">lock_open</span>
                                <input type="password" name="new_password" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" required>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Confirm Password</label>
                            <div class="relative">
                                <span class="material-symbols-outlined input-icon">lock_clock</span>
                                <input type="password" name="confirm_password" class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-lg py-2 px-3 pl-10 text-slate-800 dark:text-white text-sm font-medium transition-all" required>
                            </div>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" name="change_password" class="w-full bg-slate-900 dark:bg-slate-700 hover:bg-slate-800 dark:hover:bg-slate-600 text-white font-bold py-2 px-4 rounded-lg transition-all flex items-center justify-center gap-1 text-sm">
                                <span class="material-symbols-outlined text-sm">key</span>
                                Update Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

<!-- OTP Modal -->
<?php if($otp_modal_open): ?>
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-md w-full p-6 animate-slideUp">
        <div class="text-center">
            <div class="w-16 h-16 mx-auto mb-3 bg-primary/10 rounded-full flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl text-primary">sms</span>
            </div>
            <h3 class="text-xl font-bold mb-2 text-slate-900 dark:text-white">Verify Your Number</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-2 text-sm">We sent a code to</p>
            <p class="text-base font-bold mb-4"><?php echo $_SESSION['temp_new_phone'] ?? '...'; ?></p>
            
            <?php if(isset($_SESSION['temp_otp'])): ?>
            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-2 mb-4">
                <p class="text-amber-600 dark:text-amber-400 text-xs">
                    <span class="material-symbols-outlined text-xs align-middle">code</span>
                    Dev Code: <strong class="text-base"><?php echo $_SESSION['temp_otp']; ?></strong>
                </p>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="relative mb-4">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">pin</span>
                    <input type="text" name="otp_code" class="w-full text-center text-xl tracking-[0.5em] bg-slate-50 dark:bg-slate-900 border-2 border-primary/20 focus:border-primary rounded-lg py-3 px-3 pl-10" placeholder="000000" maxlength="6" autofocus required>
                </div>
                
                <div class="space-y-2">
                    <button type="submit" name="verify_otp" class="w-full vibrant-gradient text-white py-3 rounded-lg font-bold transition-all shadow-sm shadow-primary/25 text-sm">
                        Verify & Continue
                    </button>
                    <a href="profile.php" class="block w-full text-center text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 py-2 text-sm">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // Security section toggle
    function toggleSecurity() {
        const content = document.getElementById('securityContent');
        const chevron = document.getElementById('securityChevron');
        
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            chevron.style.transform = 'rotate(180deg)';
        } else {
            content.classList.add('hidden');
            chevron.style.transform = 'rotate(0deg)';
        }
    }

    // Form submission
    function checkPhoneChange() {
        document.getElementById('main-form').submit();
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.bg-accent-green, .bg-accent-red').forEach(alert => {
            if (alert) alert.remove();
        });
    }, 5000);
</script>

</body>
</html>