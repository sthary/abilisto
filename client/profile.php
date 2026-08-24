<?php
// client/profile.php

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/sms_sender.php';
require_once '../greenloop/greenloop_db.php';

// 2. Security Check
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = ''; $msg_type = '';

// Full name, municipality, phone, and address are no longer editable
// inline here — see client/edit_personal_details.php.

// --- 4. FETCH DATA ---
$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

$lat = $user['latitude'] ?: 12.8797;
$lng = $user['longitude'] ?: 121.7740;

// --- My Vouchers ---
$voucher_stmt = $pdo->prepare("
    SELECT rd.*, rw.reward_name, rw.description
    FROM greenloop_redemptions rd
    JOIN greenloop_rewards rw ON rd.reward_id = rw.id
    WHERE rd.user_id = ?
    ORDER BY rd.created_at DESC
");
$voucher_stmt->execute([$user_id]);
$my_vouchers = $voucher_stmt->fetchAll();

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
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
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
            background: linear-gradient(135deg, #146af5 0%, #3B82F6 100%);
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
    
    <!-- Personal Information (read-only — edit via a dedicated page) -->
    <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp" style="animation-delay: 0.1s;">
        <div class="p-4 md:p-5 flex items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-700">
            <div class="flex items-center gap-2">
                <div class="p-2 bg-primary/10 rounded-lg">
                    <span class="material-symbols-outlined text-primary text-xl">edit_note</span>
                </div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Personal Information</h2>
            </div>
            <a href="edit_personal_details.php" class="inline-flex items-center gap-1.5 text-sm font-bold text-primary hover:underline shrink-0">
                <span class="material-symbols-outlined text-sm">edit</span> Edit
            </a>
        </div>

        <div class="p-4 md:p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Full Name</label>
                    <p class="text-sm font-medium text-slate-800 dark:text-white py-2 px-1"><?php echo htmlspecialchars($user['full_name']); ?></p>
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Municipality</label>
                    <p class="text-sm font-medium text-slate-800 dark:text-white py-2 px-1"><?php echo htmlspecialchars($user['municipality'] ?: 'Not set'); ?></p>
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Email Address</label>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400 py-2 px-1"><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Phone Number</label>
                    <p class="text-sm font-medium text-slate-800 dark:text-white py-2 px-1"><?php echo htmlspecialchars($user['phone']); ?></p>
                </div>
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest ml-1">Address</label>
                <p class="text-sm font-medium text-slate-800 dark:text-white py-2 px-1"><?php echo htmlspecialchars($user['address'] ?: 'Not set'); ?></p>
            </div>
        </div>
    </section>

    <!-- My Vouchers -->
    <section class="bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-100 dark:border-slate-700 overflow-hidden animate-slideUp" style="animation-delay: 0.15s;">
        <div class="p-4 md:p-5 flex items-center gap-2 border-b border-slate-100 dark:border-slate-700">
            <div class="p-2 bg-primary/10 rounded-lg">
                <span class="material-symbols-outlined text-primary text-xl">redeem</span>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">My Vouchers</h2>
        </div>

        <div class="p-4 md:p-6 space-y-3">
            <?php if (empty($my_vouchers)): ?>
            <div class="text-center py-6">
                <span class="material-symbols-outlined text-slate-300 dark:text-slate-600 text-4xl">redeem</span>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">No vouchers yet — redeem Green Coins for rewards in GreenLoop.</p>
                <a href="../greenloop/greenloop_wallet.php" class="inline-block mt-3 text-sm font-bold text-primary hover:underline">Go to GreenLoop Wallet</a>
            </div>
            <?php else: foreach ($my_vouchers as $v):
                $v_status = gc_voucher_status($v);
                $v_badge = [
                    'active'    => 'bg-primary/10 text-primary',
                    'used'      => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
                    'expired'   => 'bg-red-50 dark:bg-red-900/20 text-red-500',
                    'cancelled' => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
                ][$v_status] ?? 'bg-slate-100 text-slate-500';
            ?>
            <div class="flex items-center justify-between gap-3 p-3 rounded-xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm font-bold text-slate-800 dark:text-slate-100"><?php echo htmlspecialchars($v['promo_code'] ?? '—'); ?></span>
                        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full <?php echo $v_badge; ?>"><?php echo ucfirst($v_status); ?></span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 truncate"><?php echo htmlspecialchars($v['reward_name']); ?></p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        <?php echo number_format($v['green_coins_spent'], 0); ?> coins spent ·
                        <?php if ($v_status === 'used' && $v['used_at']): ?>
                            Used <?php echo date('M d, Y', strtotime($v['used_at'])); ?><?php echo $v['used_booking_id'] ? " (Booking #{$v['used_booking_id']})" : ''; ?>
                        <?php elseif ($v['expires_at']): ?>
                            Expires <?php echo date('M d, Y', strtotime($v['expires_at'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </section>

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
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                    To keep your account safe, changing your password now requires a one-time code sent to your email —
                    the same way password recovery works.
                </p>
                <a href="security.php" class="inline-flex items-center gap-2 bg-slate-900 dark:bg-slate-700 hover:bg-slate-800 dark:hover:bg-slate-600 text-white font-bold py-2.5 px-5 rounded-lg transition-all text-sm">
                    <span class="material-symbols-outlined text-sm">key</span>
                    Go to Security & Password
                </a>
            </div>
        </div>
    </section>
</main>

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

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.bg-accent-green, .bg-accent-red').forEach(alert => {
            if (alert) alert.remove();
        });
    }, 5000);
</script>

</body>
</html>