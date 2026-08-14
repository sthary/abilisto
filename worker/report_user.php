<?php
// worker/report_user.php
include '../db_connect.php';
include '../includes/init_lang.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reported_user_id = intval($_POST['reported_user_id'] ?? 0);
    $report_reason = $_POST['report_reason'] ?? '';
    $report_details = $_POST['report_details'] ?? '';
    $booking_id = !empty($_POST['booking_id']) ? intval($_POST['booking_id']) : null;
    $reported_user_role = $_POST['reported_user_role'] ?? 'client';
    
    // Validate required fields
    $errors = [];
    if (!$reported_user_id) $errors[] = "Please select a user to report";
    if (!$report_reason) $errors[] = "Please select a reason for the report";
    
    // Handle proof image upload for no_show reason
    $proof_image_filename = null;
    if ($report_reason === 'no_show' && isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $file = $_FILES['proof_image'];
        
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Proof image must be JPG, PNG, or WebP format.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "Proof image must be under 5MB.";
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $proof_image_filename = 'proof_' . $worker_id . '_' . time() . '.' . strtolower($ext);
            $upload_dir = '../uploads/report_proofs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            if (!move_uploaded_file($file['tmp_name'], $upload_dir . $proof_image_filename)) {
                $errors[] = "Failed to upload proof image. Please try again.";
                $proof_image_filename = null;
            }
        }
    }
    
    if (empty($errors)) {
        // Get reported user details
        $user_sql = "SELECT full_name, email, role FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->execute([$reported_user_id]);
        $reported_user = $user_stmt->fetch();

        if ($reported_user) {
            // Check if already reported this user recently (within last 30 days)
            $dup_check = $conn->prepare("SELECT id FROM reports_worker
                                         WHERE worker_id = ? AND reported_user_id = ?
                                         AND created_at > (NOW() - INTERVAL '30 days')");
            $dup_check->execute([$worker_id, $reported_user_id]);
            $dup_row = $dup_check->fetch();

            if ($dup_row) {
                $error_message = "You have already reported this user within the last 30 days.";
            } else {
                // Insert the report
                $insert_sql = "INSERT INTO reports_worker
                              (worker_id, reported_user_id, reported_user_role, booking_id, report_reason, report_details, proof_image, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);

                if ($insert_stmt->execute([$worker_id, $reported_user_id, $reported_user_role, $booking_id, $report_reason, $report_details, $proof_image_filename])) {
                    // Check if this user has been reported 3 or more times
                    $report_count_sql = "SELECT COUNT(*) as report_count FROM reports_worker
                                        WHERE reported_user_id = ? AND status != 'dismissed'";
                    $count_stmt = $conn->prepare($report_count_sql);
                    $count_stmt->execute([$reported_user_id]);
                    $report_count = $count_stmt->fetch()['report_count'];

                    // Create notification for admin
                    $notification_msg = "⚠️ New report from worker against " . $reported_user['full_name'] . " (" . $reported_user['role'] . ")";
                    if ($report_count >= 3) {
                        $notification_msg .= " - URGENT: " . $report_count . " total reports";
                    }

                    $admin_notif = "INSERT INTO notifications (user_id, message, link, is_read, created_at)
                                    SELECT id, ?, 'admin/worker_reports.php', 0, NOW() FROM users WHERE role = 'admin'";
                    $notif_stmt = $conn->prepare($admin_notif);
                    $notif_stmt->execute([$notification_msg]);

                    $success_message = "Report submitted successfully. An administrator will review it shortly.";
                } else {
                    $error_message = "Failed to submit report. Please try again.";
                }
            }
        } else {
            $error_message = "Selected user not found.";
        }
    } else {
        $error_message = implode(", ", $errors);
    }
}

// Get list of clients the worker has interacted with
$clients_sql = "SELECT DISTINCT 
                    u.id, 
                    u.full_name, 
                    u.email,
                    u.profile_pic,
                    u.role,
                    MAX(b.booking_date) as last_booking,
                    COUNT(b.id) as total_bookings
                FROM bookings b
                JOIN users u ON b.client_id = u.id
                WHERE b.worker_id = ? AND b.status IN ('Completed', 'Accepted', 'In Progress')
                GROUP BY u.id, u.full_name, u.email, u.profile_pic, u.role
                ORDER BY last_booking DESC";

$clients_stmt = $conn->prepare($clients_sql);
$clients_stmt->execute([$worker_id]);
$clients_result = $clients_stmt->fetchAll();

// Get recent reports from this worker
$reports_sql = "SELECT r.*,
                       u.full_name as reported_user_name,
                       u.role as reported_user_role,
                       u.email as reported_user_email,
                       b.booking_date,
                       (NOW()::date - r.created_at::date) as days_ago
                FROM reports_worker r
                JOIN users u ON r.reported_user_id = u.id
                LEFT JOIN bookings b ON r.booking_id = b.id
                WHERE r.worker_id = ?
                ORDER BY r.created_at DESC
                LIMIT 10";

$reports_stmt = $conn->prepare($reports_sql);
$reports_stmt->execute([$worker_id]);
$reports_result = $reports_stmt->fetchAll();

// Get report counts for stats
$stats_sql = "SELECT
                COUNT(*) as total_reports,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_reports
              FROM reports_worker
              WHERE worker_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute([$worker_id]);
$stats = $stats_stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report a User | Worker</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                    },
                    fontFamily: {
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    }
                }
            }
        }
    </script>
    
    <style>
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .report-card {
            transition: all 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 font-sans transition-colors duration-300">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white flex items-center gap-3">
                <span class="material-symbols-rounded text-4xl text-primary">flag</span>
                Report a User
            </h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Submit a report about a client or user you've interacted with</p>
        </div>
        <div class="flex gap-3">
            <a href="dashboard.php" class="flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                <span class="material-symbols-rounded">dashboard</span>
                Dashboard
            </a>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-rounded text-primary">flag</span>
                </div>
                <div>
                    <div class="text-sm text-slate-500 dark:text-slate-400 mb-1">Total Reports</div>
                    <div class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo $stats['total_reports'] ?? 0; ?></div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-amber-100 dark:bg-amber-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">pending</span>
                </div>
                <div>
                    <div class="text-sm text-slate-500 dark:text-slate-400 mb-1">Pending Review</div>
                    <div class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo $stats['pending_reports'] ?? 0; ?></div>
                </div>
            </div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
                </div>
                <div>
                    <div class="text-sm text-slate-500 dark:text-slate-400 mb-1">Resolved</div>
                    <div class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo $stats['resolved_reports'] ?? 0; ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if ($success_message): ?>
    <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 rounded-xl flex items-center gap-3">
        <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
        <span class="text-green-700 dark:text-green-300"><?php echo $success_message; ?></span>
    </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
    <div class="mb-6 p-4 bg-rose-50 dark:bg-rose-900/20 border-l-4 border-rose-500 rounded-xl flex items-center gap-3">
        <span class="material-symbols-rounded text-rose-600 dark:text-rose-400">error</span>
        <span class="text-rose-700 dark:text-rose-300"><?php echo $error_message; ?></span>
    </div>
    <?php endif; ?>
    
    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Report Form Column -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-700">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary">add_alert</span>
                        Submit New Report
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Please provide accurate information about the issue</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                    <!-- Select User to Report -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Select User to Report <span class="text-rose-500">*</span>
                        </label>
                        <select name="reported_user_id" id="reported_user_id" required
                                class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all">
                            <option value="">-- Choose a user --</option>
                            <optgroup label="Recent Clients">
                                <?php if ($clients_result && count($clients_result) > 0): ?>
                                    <?php foreach ($clients_result as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" data-role="client">
                                        <?php echo htmlspecialchars($client['full_name']); ?>
                                        (<?php echo $client['total_bookings']; ?> booking(s))
                                        - Last: <?php echo date('M d, Y', strtotime($client['last_booking'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </optgroup>
                            <optgroup label="Other Users">
                                <?php
                                // Get other workers (limited to avoid too many options)
                                $other_sql = "SELECT id, full_name, email, role 
                                             FROM users 
                                             WHERE role IN ('client', 'business') 
                                             AND id NOT IN (
                                                SELECT DISTINCT client_id FROM bookings WHERE worker_id = ?
                                             )
                                             LIMIT 20";
                                $other_stmt = $conn->prepare($other_sql);
                                $other_stmt->execute([$worker_id]);
                                $other_result = $other_stmt->fetchAll();

                                foreach ($other_result as $other):
                                ?>
                                <option value="<?php echo $other['id']; ?>" data-role="<?php echo $other['role']; ?>">
                                    <?php echo htmlspecialchars($other['full_name']); ?> (<?php echo $other['role']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <input type="hidden" name="reported_user_role" id="reported_user_role" value="client">
                    </div>
                    
                    <!-- Related Booking (Optional) -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Related Booking (Optional)
                        </label>
                        <select name="booking_id" id="booking_id" 
                                class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all">
                            <option value="">-- Select a booking (if applicable) --</option>
                            <?php
                            // Get recent bookings for context
                            $booking_sql = "SELECT b.id, b.booking_date, u.full_name as client_name
                                           FROM bookings b
                                           JOIN users u ON b.client_id = u.id
                                           WHERE b.worker_id = ? AND b.status IN ('Completed', 'Accepted', 'In Progress')
                                           ORDER BY b.booking_date DESC
                                           LIMIT 20";
                            $booking_stmt = $conn->prepare($booking_sql);
                            $booking_stmt->execute([$worker_id]);
                            $booking_result = $booking_stmt->fetchAll();

                            foreach ($booking_result as $booking):
                            ?>
                            <option value="<?php echo $booking['id']; ?>">
                                #<?php echo $booking['id']; ?> - <?php echo $booking['client_name']; ?>
                                (<?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Report Reason -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Reason for Report <span class="text-rose-500">*</span>
                        </label>
                        <select name="report_reason" id="report_reason" required
                                class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all">
                            <option value="">-- Select a reason --</option>
                            <option value="no_show">Client did not show up</option>
                            <option value="cancelled_last_minute">Client cancelled at last minute</option>
                            <option value="unprofessional">Unprofessional behaviour / harassment</option>
                            <option value="non_payment">Client refused to pay</option>
                            <option value="false_accusation">False accusation against me</option>
                            <option value="abusive_language">Abusive language / threats</option>
                            <option value="unsafe_environment">Unsafe working environment</option>
                            <option value="misleading_info">Client provided misleading information</option>
                            <option value="scope_changed">Job scope changed significantly after agreement</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <!-- Proof of Showing Up (only for no_show) -->
                    <div id="proof_image_section" class="hidden">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            <span class="flex items-center gap-2">
                                <span class="material-symbols-rounded text-amber-500">photo_camera</span>
                                Proof of Showing Up
                                <span class="text-xs font-normal text-slate-500">(optional but recommended)</span>
                            </span>
                        </label>
                        <div id="proof_drop_zone"
                             class="relative border-2 border-dashed border-amber-300 dark:border-amber-700 rounded-xl p-6 text-center transition-all cursor-pointer hover:border-amber-400 dark:hover:border-amber-500 hover:bg-amber-50/50 dark:hover:bg-amber-900/10 bg-amber-50/30 dark:bg-amber-900/5">
                            <input type="file" name="proof_image" id="proof_image" accept="image/jpeg,image/jpg,image/png,image/webp"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">
                            <div id="proof_placeholder">
                                <span class="material-symbols-rounded text-4xl text-amber-400 dark:text-amber-500 mb-2 block">upload_file</span>
                                <p class="text-sm font-medium text-amber-700 dark:text-amber-400">Click or drag a photo here</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">JPG, PNG, WebP — max 5MB</p>
                                <p class="text-xs text-slate-400 dark:text-slate-500 mt-2">e.g. a screenshot of your arrival location, booking confirmation, or any photo showing you were present</p>
                            </div>
                            <div id="proof_preview" class="hidden">
                                <img id="proof_preview_img" src="" alt="Proof preview" class="max-h-48 mx-auto rounded-lg object-contain shadow mb-2">
                                <p id="proof_preview_name" class="text-xs text-slate-500"></p>
                                <button type="button" id="proof_remove_btn"
                                        class="mt-2 text-xs text-rose-500 hover:text-rose-700 font-medium flex items-center gap-1 mx-auto z-20 relative">
                                    <span class="material-symbols-rounded text-[16px]">close</span> Remove photo
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-2 flex items-center gap-1">
                            <span class="material-symbols-rounded text-[14px]">info</span>
                            Uploading a proof photo helps the admin verify your claim faster.
                        </p>
                    </div>
                    
                    <!-- Report Details -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Additional Details
                        </label>
                        <textarea name="report_details" rows="5"
                                  class="w-full p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                  placeholder="Please describe what happened in detail... Include dates, times, and any relevant information."></textarea>
                        <p class="text-xs text-slate-500 mt-1">Be as specific as possible to help us investigate</p>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="flex gap-4 pt-4">
                        <button type="submit" 
                                class="flex-1 flex items-center justify-center gap-2 bg-primary hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-xl transition-all shadow-lg shadow-primary/20">
                            <span class="material-symbols-rounded">send</span>
                            Submit Report
                        </button>
                        <button type="reset" 
                                class="px-6 py-3 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                            Clear
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Reporting Guidelines -->
            <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 rounded-2xl p-6 border border-blue-200 dark:border-blue-800">
                <h3 class="font-bold text-blue-800 dark:text-blue-300 flex items-center gap-2 mb-3">
                    <span class="material-symbols-rounded">info</span>
                    Reporting Guidelines
                </h3>
                <ul class="space-y-2 text-sm text-blue-700 dark:text-blue-300">
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-[18px] shrink-0">check_circle</span>
                        <span>Only report genuine issues - false reports may affect your account</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-[18px] shrink-0">check_circle</span>
                        <span>Provide as much detail as possible including dates and specific incidents</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-[18px] shrink-0">check_circle</span>
                        <span>If you're in immediate danger, contact local authorities first</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-rounded text-[18px] shrink-0">check_circle</span>
                        <span>Reports are confidential and will be reviewed by our admin team</span>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Recent Reports Column -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden sticky top-24">
                <div class="p-5 border-b border-slate-200 dark:border-slate-700">
                    <h2 class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary">history</span>
                        Your Recent Reports
                    </h2>
                </div>
                
                <div class="divide-y divide-slate-100 dark:divide-slate-700 max-h-[500px] overflow-y-auto">
                    <?php if ($reports_result && count($reports_result) > 0): ?>
                        <?php foreach ($reports_result as $report):
                            $status_colors = [
                                'pending' => ['bg' => 'bg-amber-100 dark:bg-amber-900/20', 'text' => 'text-amber-700 dark:text-amber-400', 'icon' => 'pending'],
                                'reviewed' => ['bg' => 'bg-blue-100 dark:bg-blue-900/20', 'text' => 'text-blue-700 dark:text-blue-400', 'icon' => 'visibility'],
                                'resolved' => ['bg' => 'bg-green-100 dark:bg-green-900/20', 'text' => 'text-green-700 dark:text-green-400', 'icon' => 'check_circle'],
                                'dismissed' => ['bg' => 'bg-slate-100 dark:bg-slate-700', 'text' => 'text-slate-500 dark:text-slate-400', 'icon' => 'do_not_disturb']
                            ];
                            $style = $status_colors[$report['status']] ?? $status_colors['pending'];
                        ?>
                        <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-all">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="font-medium text-slate-900 dark:text-white text-sm truncate">
                                    <?php echo htmlspecialchars($report['reported_user_name']); ?>
                                </span>
                                <span class="text-xs text-slate-500 whitespace-nowrap">
                                    <?php echo $report['days_ago']; ?>d ago
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $style['bg']; ?> <?php echo $style['text']; ?> flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[12px]"><?php echo $style['icon']; ?></span>
                                    <?php echo ucfirst($report['status']); ?>
                                </span>
                                <span class="text-xs capitalize text-slate-500">
                                    <?php echo str_replace('_', ' ', $report['report_reason']); ?>
                                </span>
                            </div>
                            <?php if ($report['booking_id']): ?>
                            <div class="text-xs text-slate-400 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">event</span>
                                Booking #<?php echo $report['booking_id']; ?>
                                <?php if ($report['booking_date']): ?>
                                - <?php echo date('M d, Y', strtotime($report['booking_date'])); ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-8 text-center">
                            <div class="w-16 h-16 mx-auto mb-3 bg-slate-100 dark:bg-slate-700 rounded-full flex items-center justify-center">
                                <span class="material-symbols-rounded text-3xl text-slate-400">inbox</span>
                            </div>
                            <p class="text-sm text-slate-500 dark:text-slate-400">No reports yet</p>
                            <p class="text-xs text-slate-400 mt-1">Your submitted reports will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($reports_result && count($reports_result) > 0): ?>
                <div class="p-4 border-t border-slate-200 dark:border-slate-700">
                    <a href="#" class="text-sm text-primary hover:text-blue-700 font-medium flex items-center justify-center gap-1">
                        View All Reports
                        <span class="material-symbols-rounded text-[18px]">arrow_forward</span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Help Section -->
    <div class="mt-12 bg-gradient-to-r from-slate-50 to-white dark:from-slate-800 dark:to-slate-800 rounded-3xl p-8 border border-slate-200 dark:border-slate-700">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">help</span>
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white mb-1">Need help?</h4>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Contact our support team for assistance</p>
                    <a href="#" class="text-sm text-primary hover:text-blue-700 font-medium mt-2 inline-block">support@abilisto.com</a>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">schedule</span>
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white mb-1">Response Time</h4>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Reports are typically reviewed within 24-48 hours</p>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">security</span>
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white mb-1">Confidential</h4>
                    <p class="text-sm text-slate-600 dark:text-slate-400">All reports are kept strictly confidential</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dark Mode Toggle -->
<div class="fixed bottom-6 right-6 z-50">
    <button class="w-12 h-12 flex items-center justify-center rounded-full bg-white dark:bg-slate-800 shadow-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:scale-110 transition-all"
            onclick="document.documentElement.classList.toggle('dark')">
        <span class="material-symbols-rounded dark:hidden">dark_mode</span>
        <span class="material-symbols-rounded hidden dark:block">light_mode</span>
    </button>
</div>

<script>
    // Update hidden role field when user is selected
    document.getElementById('reported_user_id').addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected) {
            const role = selected.getAttribute('data-role') || 'client';
            document.getElementById('reported_user_role').value = role;
        }
    });
    
    // Show/hide proof image section based on report reason
    const reportReasonSelect = document.getElementById('report_reason');
    const proofSection = document.getElementById('proof_image_section');
    
    reportReasonSelect.addEventListener('change', function() {
        if (this.value === 'no_show') {
            proofSection.classList.remove('hidden');
            proofSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            proofSection.classList.add('hidden');
            // Clear file input when hidden
            document.getElementById('proof_image').value = '';
            resetProofPreview();
        }
    });
    
    // Proof image preview
    document.getElementById('proof_image').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('proof_placeholder').classList.add('hidden');
                document.getElementById('proof_preview').classList.remove('hidden');
                document.getElementById('proof_preview_img').src = e.target.result;
                document.getElementById('proof_preview_name').textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Remove proof image
    document.getElementById('proof_remove_btn').addEventListener('click', function(e) {
        e.stopPropagation();
        document.getElementById('proof_image').value = '';
        resetProofPreview();
    });
    
    function resetProofPreview() {
        document.getElementById('proof_placeholder').classList.remove('hidden');
        document.getElementById('proof_preview').classList.add('hidden');
        document.getElementById('proof_preview_img').src = '';
        document.getElementById('proof_preview_name').textContent = '';
    }
    
    // Auto-hide success/error messages after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.bg-green-50, .bg-rose-50').forEach(el => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        });
    }, 5000);
</script>

</body>
</html>