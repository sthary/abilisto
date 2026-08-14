<?php
// includes/profile_completion_check.php
// Checks if user profile is complete and shows modal with missing items

// Only run if user is logged in
if (!isset($_SESSION['user_id'])) {
    return;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// Get user data
$sql = "SELECT full_name, email, phone, address, municipality, 
               latitude, longitude, birthdate, profile_pic,
               is_email_verified, is_phone_verified
        FROM users 
        WHERE id = $user_id";
$result = $conn->query($sql);
$user = $result->fetch_assoc();

if (!$user) {
    return;
}

// Check for missing items
$missing_items = [];
$missing_count = 0;

// Basic info checks (for all users)
if (empty($user['full_name'])) {
    $missing_items[] = ['field' => 'full_name', 'label' => 'Full Name', 'icon' => 'badge', 'material' => true];
    $missing_count++;
}

if (empty($user['phone'])) {
    $missing_items[] = ['field' => 'phone', 'label' => 'Phone Number', 'icon' => 'phone', 'material' => true];
    $missing_count++;
} elseif (!$user['is_phone_verified']) {
    $missing_items[] = ['field' => 'phone_verify', 'label' => 'Verify Phone Number', 'icon' => 'pending_actions', 'material' => true];
    $missing_count++;
}

if (empty($user['email'])) {
    $missing_items[] = ['field' => 'email', 'label' => 'Email Address', 'icon' => 'mail', 'material' => true];
    $missing_count++;
} elseif (!$user['is_email_verified']) {
    $missing_items[] = ['field' => 'email_verify', 'label' => 'Verify Email', 'icon' => 'pending_actions', 'material' => true];
    $missing_count++;
}

// Address checks
if (empty($user['address'])) {
    $missing_items[] = ['field' => 'address', 'label' => 'Street/Barangay Address', 'icon' => 'map', 'material' => true];
    $missing_count++;
}

if (empty($user['municipality'])) {
    $missing_items[] = ['field' => 'municipality', 'label' => 'Municipality', 'icon' => 'location_city', 'material' => true];
    $missing_count++;
}

// Location coordinates (important for workers)
if ($role === 'worker') {
    if (empty($user['latitude']) || empty($user['longitude']) || $user['latitude'] == 0) {
        $missing_items[] = ['field' => 'location', 'label' => 'Pin Your Location on Map', 'icon' => 'location_on', 'material' => true];
        $missing_count++;
    }
}

// Optional but recommended
if (empty($user['birthdate'])) {
    $missing_items[] = ['field' => 'birthdate', 'label' => 'Birthday (Optional)', 'icon' => 'cake', 'material' => true, 'optional' => true];
}

if (empty($user['profile_pic'])) {
    $missing_items[] = ['field' => 'profile_pic', 'label' => 'Profile Picture (Optional)', 'icon' => 'image', 'material' => true, 'optional' => true];
}

// Don't show modal if profile is complete (no missing required items)
$required_missing = array_filter($missing_items, function($item) {
    return !isset($item['optional']);
});

if (count($required_missing) === 0) {
    return; // Profile complete, don't show modal
}
?>

<!-- Bootstrap CSS (if not already included) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Material Symbols and Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

<!-- Tailwind (if needed) -->
<script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>

<!-- Profile Completion Modal -->
<div class="modal fade" id="profileCompletionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content glass-card shadow-[0_32px_64px_-16px_rgba(0,0,0,0.1)] dark:shadow-[0_32px_64px_-16px_rgba(0,0,0,0.4)] rounded-3xl overflow-hidden border-0">
            <div class="p-8 md:p-12">
                
                <!-- Header with Icon -->
                <div class="flex items-center gap-3 mb-8">
                    <span class="material-symbols-outlined text-primary text-3xl font-light">grade</span>
                    <h1 class="font-display text-2xl md:text-3xl font-bold tracking-tight text-slate-800 dark:text-white">
                        Complete Your Profile
                    </h1>
                </div>
                
                <!-- Progress Bar -->
                <?php 
                $total_required = $missing_count - count(array_filter($missing_items, function($item) {
                    return isset($item['optional']);
                }));
                $completed = $total_required - count($required_missing);
                $progress = $total_required > 0 ? round(($completed / $total_required) * 100) : 0;
                ?>
                
                <div class="mb-10">
                    <div class="flex justify-between items-end mb-3">
                        <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Profile Completion</span>
                        <span class="text-xs font-semibold uppercase tracking-wider text-primary">
                            <?php echo count($required_missing); ?> step<?php echo count($required_missing) > 1 ? 's' : ''; ?> remaining
                        </span>
                    </div>
                    <div class="h-1.5 w-full bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full progress-gradient w-[<?php echo $progress; ?>%] rounded-full shadow-[0_0_12px_rgba(37,99,235,0.4)]"></div>
                    </div>
                </div>
                
                <p class="text-center text-slate-500 dark:text-slate-400 font-light mb-8 text-lg">
                    You're just a few steps away from <span class="font-medium text-slate-800 dark:text-slate-200">unlocking all features!</span>
                </p>
                
                <!-- Missing Items Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-10">
                    <?php foreach ($missing_items as $item): ?>
                        <div class="step-gradient border border-blue-100 dark:border-blue-900/30 rounded-2xl p-5 transition-all hover:shadow-lg <?php echo isset($item['optional']) ? 'opacity-90' : ''; ?>">
                            <div class="flex items-start gap-4">
                                <div class="bg-primary/10 text-primary p-2 rounded-lg">
                                    <span class="material-symbols-outlined block"><?php echo $item['icon']; ?></span>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center justify-between mb-1">
                                        <h3 class="font-display font-semibold text-slate-800 dark:text-slate-100">
                                            <?php echo $item['label']; ?>
                                            <?php if (isset($item['optional'])): ?>
                                                <span class="ml-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">Optional</span>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if (strpos($item['field'], 'verify') !== false): ?>
                                            <span class="material-symbols-outlined text-amber-500 text-xl">pending_actions</span>
                                        <?php else: ?>
                                            <span class="material-symbols-outlined text-slate-400 text-xl">error_outline</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (strpos($item['field'], 'verify') !== false): ?>
                                        <div class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
                                            <span class="material-symbols-outlined text-base">schedule</span>
                                            <span>Awaiting verification</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex flex-col md:flex-row items-center justify-center gap-4 mb-10">
                    <?php if ($role === 'worker'): ?>
                        <a href="../worker/profile_edit.php" class="w-full md:w-auto px-8 py-4 bg-primary hover:bg-blue-700 text-white font-semibold rounded-2xl shadow-[0_10px_20px_-5px_rgba(37,99,235,0.4)] transition-all hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2 group">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-12 transition-transform">edit_square</span>
                            Complete Profile Now
                        </a>
                    <?php else: ?>
                        <a href="../client/profile.php" class="w-full md:w-auto px-8 py-4 bg-primary hover:bg-blue-700 text-white font-semibold rounded-2xl shadow-[0_10px_20px_-5px_rgba(37,99,235,0.4)] transition-all hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2 group">
                            <span class="material-symbols-outlined text-xl group-hover:rotate-12 transition-transform">edit_square</span>
                            Complete Profile Now
                        </a>
                    <?php endif; ?>
                    
                    <button class="w-full md:w-auto px-8 py-4 bg-slate-100 dark:bg-slate-800/50 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 font-medium rounded-2xl transition-all border border-transparent hover:border-slate-300 dark:hover:border-slate-600 flex items-center justify-center gap-2" data-bs-dismiss="modal">
                        <span class="material-symbols-outlined text-xl">timer</span>
                        Maybe Later
                    </button>
                </div>
                
                <!-- Benefits Note -->
                <div class="border-t border-slate-200 dark:border-slate-700/50 pt-8">
                    <p class="text-center text-[10px] font-bold uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500 mb-6 flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">verified</span>
                        Complete profile to:
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="flex items-center justify-center md:justify-start gap-3 group">
                            <div class="p-2 rounded-full bg-slate-50 dark:bg-slate-800 group-hover:bg-primary/10 transition-colors">
                                <span class="material-symbols-outlined text-slate-400 group-hover:text-primary transition-colors text-xl">calendar_month</span>
                            </div>
                            <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Get more bookings</span>
                        </div>
                        <div class="flex items-center justify-center md:justify-center gap-3 group">
                            <div class="p-2 rounded-full bg-slate-50 dark:bg-slate-800 group-hover:bg-primary/10 transition-colors">
                                <span class="material-symbols-outlined text-slate-400 group-hover:text-primary transition-colors text-xl">shield_person</span>
                            </div>
                            <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Build trust</span>
                        </div>
                        <div class="flex items-center justify-center md:justify-end gap-3 group">
                            <div class="p-2 rounded-full bg-slate-50 dark:bg-slate-800 group-hover:bg-primary/10 transition-colors">
                                <span class="material-symbols-outlined text-slate-400 group-hover:text-primary transition-colors text-xl">auto_awesome</span>
                            </div>
                            <span class="text-xs font-medium text-slate-600 dark:text-slate-400">Access all features</span>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</div>

<!-- Styles -->
<style>
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
    .progress-gradient {
        background: linear-gradient(90deg, #146af5 0%, #0f52c2 100%);
    }
    .step-gradient {
        background: linear-gradient(135deg, rgba(219, 234, 254, 0.5) 0%, rgba(191, 219, 254, 0.3) 100%);
    }
    .dark .step-gradient {
        background: linear-gradient(135deg, rgba(30, 58, 138, 0.3) 0%, rgba(30, 64, 175, 0.2) 100%);
    }
    
    /* Modal animations */
    .modal.fade .modal-dialog {
        transform: scale(0.8);
        transition: transform 0.3s ease;
    }
    .modal.show .modal-dialog {
        transform: scale(1);
    }
    
    /* Hover effects */
    .step-gradient:hover {
        transform: translateX(5px);
    }
</style>

<!-- Bootstrap JS (required for modal functionality) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Auto-show modal on page load -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Profile completion check loaded');
    
    // Check if modal should show (check localStorage for "maybe later" choice)
    const modalShown = localStorage.getItem('profile_modal_shown_<?php echo $user_id; ?>');
    const modalClosed = sessionStorage.getItem('profile_modal_closed_<?php echo $user_id; ?>');
    
    console.log('Modal shown:', modalShown, 'Modal closed:', modalClosed);
    
    if (!modalClosed && !modalShown) {
        console.log('Attempting to show modal after 1.5 seconds');
        
        setTimeout(() => {
            const modalElement = document.getElementById('profileCompletionModal');
            if (modalElement) {
                console.log('Modal element found, initializing...');
                
                // Make sure modal is initialized
                const modal = new bootstrap.Modal(modalElement, {
                    backdrop: 'static',
                    keyboard: false
                });
                
                // Show the modal
                modal.show();
                console.log('Modal show() called');
                
                // Mark as shown for this session
                sessionStorage.setItem('profile_modal_closed_<?php echo $user_id; ?>', 'true');
                
                // Also mark in localStorage for future visits
                localStorage.setItem('profile_modal_shown_<?php echo $user_id; ?>', 'true');
            } else {
                console.error('Modal element not found!');
            }
        }, 1500); // Show after 1.5 seconds
    }
});

// Optional: Reset "maybe later" choice when they complete profile
document.querySelector('[data-bs-dismiss="modal"]')?.addEventListener('click', function() {
    console.log('Modal dismissed with "Maybe Later"');
    sessionStorage.setItem('profile_modal_closed_<?php echo $user_id; ?>', 'true');
});
</script>