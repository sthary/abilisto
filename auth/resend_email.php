<?php
// auth/resend_email.php
session_start();
include '../db.php';
include '../includes/mailer.php'; // Includes your new sendAbilistoEmail() function

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$status = '';
$status_type = '';
$user_email = '';
$user_name = '';

// 1. Get User Details (Upgraded to Prepared Statement for Security)
$stmt = $conn->prepare("SELECT full_name, email, verification_token FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user) {
    $user_email = $user['email'];
    $user_name = $user['full_name'];
    
    // 2. Reuse existing token or generate new one if missing
    $token = $user['verification_token'];
    if (empty($token)) {
        $token = bin2hex(random_bytes(32));
        $update_stmt = $conn->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
        $update_stmt->bind_param("si", $token, $user_id);
        $update_stmt->execute();
    }

    // 3. Build the Email Subject and HTML Body
    $subject = "Verify Your Abilisto Account";
    
    // ⚠️ IMPORTANT: Change this URL when you move from localhost to your live server!
    $verify_link = "https://abilisto.site/auth/verify.php?token=" . $token; 
    
    $html_body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
        <h2 style='color: #146af5; text-align: center;'>Welcome to Abilisto!</h2>
        <p>Hi " . htmlspecialchars($user['full_name']) . ",</p>
        <p>Thank you for signing up. To complete your registration and unlock all features, please verify your email address by clicking the button below:</p>

        <div style='text-align: center; margin: 30px 0;'>
            <a href='" . $verify_link . "' style='background-color: #146af5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;'>Verify My Account</a>
        </div>
        
        <p style='color: #666; font-size: 14px;'>If the button doesn't work, copy and paste this link into your browser:</p>
        <p style='color: #666; font-size: 12px; word-break: break-all;'>" . $verify_link . "</p>
        
        <hr style='border: none; border-top: 1px solid #eee; margin-top: 30px;'>
        <p style='color: #999; font-size: 12px; text-align: center;'>&copy; " . date("Y") . " Abilisto. All rights reserved.</p>
    </div>";

    // 4. Send Email using the new Resend function
    $email_sent = sendAbilistoEmail($user['email'], $subject, $html_body);

    // 5. Set status message based on result
    if ($email_sent) {
        $status = "✅ Verification link sent successfully! Check your email inbox.";
        $status_type = 'success';
    } else {
        $status = "❌ Failed to send email. Please check your XAMPP error logs.";
        $status_type = 'error';
    }
} else {
    $status = "❌ User not found in our system.";
    $status_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Resend Verification | Abilisto</title>
    
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
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        'xl': '24px',
                        '2xl': '32px',
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
        .inner-glow {
            box-shadow: inset 0 1px 1px rgba(255,255,255,0.4);
        }
        
        /* Animation */
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
        
        /* Loading spinner */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .animate-spin {
            animation: spin 1s linear infinite;
        }
        
        /* Email preview styling */
        .email-preview {
            font-family: Arial, sans-serif;
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
                <span class="material-symbols-rounded text-5xl text-primary">mark_email_read</span>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2">Email Verification</h1>
            <p class="text-slate-500 dark:text-slate-400">Resending verification link to your email</p>
        </div>
        
        <!-- Status Message -->
        <?php if($status): ?>
        <div class="mb-8 p-5 <?php echo $status_type == 'success' ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'; ?> border rounded-xl flex items-start gap-3">
            <span class="material-symbols-rounded <?php echo $status_type == 'success' ? 'text-green-500' : 'text-red-500'; ?> flex-shrink-0 mt-0.5">
                <?php echo $status_type == 'success' ? 'mark_email_read' : 'error'; ?>
            </span>
            <div>
                <span class="text-sm font-medium <?php echo $status_type == 'success' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'; ?>">
                    <?php echo $status; ?>
                </span>
                <?php if($status_type == 'success' && $user_email): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-xs">mail</span>
                    Sent to: <span class="font-mono"><?php echo htmlspecialchars($user_email); ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- User Info Card (shown on success) -->
        <?php if($status_type == 'success' && $user_name): ?>
        <div class="mb-8 bg-blue-50 dark:bg-blue-900/10 rounded-xl p-5 border border-blue-100 dark:border-blue-800/20">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-rounded text-primary">person</span>
                </div>
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Hello,</p>
                    <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($user_name); ?></p>
                </div>
            </div>
            <div class="mt-3 text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2">
                <span class="material-symbols-rounded text-xs">lightbulb</span>
                <span>Check your spam folder if you don't see the email in your inbox</span>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Email Preview Toggle (only show on success) -->
        <?php if($status_type == 'success'): ?>
        <details class="mb-8 group">
            <summary class="flex items-center gap-2 text-sm font-semibold text-slate-500 dark:text-slate-400 cursor-pointer list-none p-3 bg-slate-50 dark:bg-slate-800/30 rounded-xl">
                <span class="material-symbols-rounded text-lg group-open:rotate-90 transition-transform">chevron_right</span>
                <span>Preview email that was sent</span>
            </summary>
            <div class="mt-4 p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 email-preview text-sm">
                <div class="flex items-center gap-2 pb-3 border-b border-slate-100 dark:border-slate-700 mb-3">
                    <span class="material-symbols-rounded text-primary">mark_as_unread</span>
                    <span class="font-semibold text-slate-700 dark:text-slate-300">To: <?php echo htmlspecialchars($user_email); ?></span>
                </div>
                <div class="space-y-3 text-slate-600 dark:text-slate-400">
                    <p><span class="font-semibold">Subject:</span> Verify Your Abilisto Account</p>
                    <p>Hi <?php echo htmlspecialchars($user_name); ?>,</p>
                    <p>Thank you for signing up. To complete your registration and unlock all features, please verify your email address by clicking the button below:</p>
                    <div class="text-center my-4">
                        <span class="inline-block bg-primary text-white px-6 py-3 rounded-lg font-bold text-sm">Verify My Account</span>
                    </div>
                    <p class="text-xs text-slate-400">If the button doesn't work, copy and paste the verification link from your email.</p>
                </div>
            </div>
        </details>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="space-y-4">
            <?php if($status_type == 'success'): ?>
                <!-- Resend again option -->
                <a href="resend_email.php" 
                   class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 group inner-glow">
                    <span class="material-symbols-rounded group-hover:rotate-12 transition-transform">refresh</span>
                    Resend Again
                </a>
            <?php else: ?>
                <!-- Retry option for errors -->
                <a href="resend_email.php" 
                   class="w-full inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-700 hover:to-blue-600 text-white font-bold py-5 rounded-xl transition-all transform active:scale-[0.98] shadow-lg shadow-blue-500/25 group inner-glow">
                    <span class="material-symbols-rounded">refresh</span>
                    Try Again
                </a>
            <?php endif; ?>
            
            <!-- Back to Dashboard -->
            <a href="javascript:window.history.back()" 
               class="w-full inline-flex items-center justify-center gap-2 bg-white/50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700 hover:bg-white dark:hover:bg-slate-800 py-5 rounded-xl transition-all font-semibold text-slate-700 dark:text-slate-200">
                <span class="material-symbols-rounded">arrow_back</span>
                Back to Dashboard
            </a>
            
            <!-- Alternative options -->
            <div class="flex flex-col items-center gap-3 pt-4">
                <a href="../index.php" 
                   class="inline-flex items-center gap-2 text-sm font-medium text-slate-400 dark:text-slate-500 hover:text-primary dark:hover:text-primary transition-colors">
                    <span class="material-symbols-rounded text-lg">home</span>
                    Go to Homepage
                </a>
            </div>
        </div>
        
        <!-- Help Section -->
        <div class="mt-10 pt-6 border-t border-slate-100 dark:border-slate-800">
            <details class="group">
                <summary class="flex items-center gap-2 text-sm font-semibold text-slate-500 dark:text-slate-400 cursor-pointer list-none">
                    <span class="material-symbols-rounded text-lg group-open:rotate-90 transition-transform">chevron_right</span>
                    <span>Email delivery tips</span>
                </summary>
                <div class="mt-4 ml-7 space-y-3 text-sm text-slate-500 dark:text-slate-400">
                    <p class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-xs mt-0.5">check_small</span>
                        <span>Check your <strong>spam/junk folder</strong> - sometimes emails end up there</span>
                    </p>
                    <p class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-xs mt-0.5">check_small</span>
                        <span>Add <strong>noreply@abilisto.site</strong> to your contacts</span>
                    </p>
                    <p class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-xs mt-0.5">check_small</span>
                        <span>Wait 1-2 minutes for the email to arrive</span>
                    </p>
                    <p class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-xs mt-0.5">check_small</span>
                        <span>Make sure your email address is spelled correctly</span>
                    </p>
                    <a href="#" class="inline-flex items-center gap-1 text-primary text-xs font-medium hover:underline mt-2">
                        <span class="material-symbols-rounded text-sm">contact_support</span>
                        Need more help?
                    </a>
                </div>
            </details>
        </div>
        
        <!-- Trust Features -->
        <div class="mt-10 pt-8 border-t border-slate-100 dark:border-slate-800 flex flex-wrap justify-center gap-x-8 gap-y-4">
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">verified_user</span>
                Secure Email
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">encrypted</span>
                Encrypted Link
            </div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-widest font-bold text-slate-400 dark:text-slate-600">
                <span class="material-symbols-rounded text-xs">schedule</span>
                Link Expires in 24h
            </div>
        </div>
    </div>
</div>

<!-- Dark Mode Script -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check for saved dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
        
        // Save dark mode preference when toggled
        document.querySelector('[onclick="document.documentElement.classList.toggle(\'dark\')"]')?.addEventListener('click', function() {
            const isDark = document.documentElement.classList.contains('dark');
            localStorage.setItem('darkMode', isDark);
        });
        
        // Auto-hide success message after 10 seconds? (optional)
        <?php if($status_type == 'success'): ?>
        // You could add auto-redirect or auto-hide logic here if desired
        <?php endif; ?>
    });
</script>

</body>
</html>