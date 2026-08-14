<?php
// client/submit_report.php
// Handles report submissions from clients with beautiful UI

session_start();
include '../db.php';
include '../includes/init_lang.php';

// Security Check - Only clients can submit reports
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header('Location: ../auth/login.php');
    exit();
}

$client_id = $_SESSION['user_id'];
$success = false;
$error_message = '';
$report_data = null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $report_reason = isset($_POST['report_reason']) ? trim($_POST['report_reason']) : '';
    $report_details = isset($_POST['report_details']) ? trim($_POST['report_details']) : '';
    
    // Validate input
    $errors = [];
    
    if ($booking_id <= 0) {
        $errors[] = 'Invalid booking ID';
    }
    
    if (empty($report_reason)) {
        $errors[] = 'Please select a reason for reporting';
    }
    
    // Valid report reasons
    $valid_reasons = [
        'no_show',
        'unprofessional',
        'overcharging',
        'poor_quality',
        'harassment',
        'other'
    ];
    
    if (!in_array($report_reason, $valid_reasons)) {
        $errors[] = 'Invalid report reason';
    }
    
    // If no errors, process the report
    if (empty($errors)) {
        // Verify that the booking belongs to this client and exists
        $check_sql = "SELECT b.id, b.client_id, b.worker_id, b.status, u.full_name as worker_name, u.profile_pic
                      FROM bookings b
                      JOIN users u ON b.worker_id = u.id
                      WHERE b.id = ? AND b.client_id = ?";
        
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("ii", $booking_id, $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $error_message = 'Booking not found or does not belong to you';
        } else {
            $booking = $result->fetch_assoc();
            $worker_id = $booking['worker_id'];
            $worker_name = $booking['worker_name'];
            
            // Check if a report already exists for this booking BY THIS CLIENT
            // FIX: Checking the `reports` table instead of `reports_worker`
            $check_report_sql = "SELECT id FROM reports 
                                 WHERE booking_id = ? AND client_id = ?";
            $stmt_check = $conn->prepare($check_report_sql);
            $stmt_check->bind_param("ii", $booking_id, $client_id);
            $stmt_check->execute();
            $report_result = $stmt_check->get_result();
            
            if ($report_result->num_rows > 0) {
                $error_message = 'You have already reported this booking. Our team will review it shortly.';
            } else {
                // Map report reasons to display text
                $reason_labels = [
                    'no_show' => 'Worker did not show up',
                    'unprofessional' => 'Unprofessional behaviour',
                    'overcharging' => 'Overcharging / incorrect fee',
                    'poor_quality' => 'Poor quality of work',
                    'harassment' => 'Harassment or inappropriate conduct',
                    'other' => 'Other'
                ];
                
                $report_reason_display = isset($reason_labels[$report_reason]) ? $reason_labels[$report_reason] : $report_reason;
                
                // Insert report into reports table
                // FIX: Using the correct Client Reports table
                $insert_sql = "INSERT INTO reports 
                               (booking_id, client_id, worker_id, report_reason, report_details, status, created_at)
                               VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
                
                $stmt_insert = $conn->prepare($insert_sql);
                // Bind: iiiss (int, int, int, string, string)
                $stmt_insert->bind_param("iiiss", $booking_id, $client_id, $worker_id, 
                                         $report_reason_display, $report_details);
                
                if ($stmt_insert->execute()) {
                    $report_id = $stmt_insert->insert_id;
                    
                    // Log the report in system_logs
                    $log_sql = "INSERT INTO system_logs (user_id, action, details, ip_address, created_at)
                                VALUES (?, 'submit_report', ?, ?, NOW())";
                    
                    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $log_details = "Client ID: $client_id reported Worker ID: $worker_id for Booking ID: $booking_id. Reason: $report_reason_display";
                    
                    $stmt_log = $conn->prepare($log_sql);
                    $stmt_log->bind_param("iss", $client_id, $log_details, $client_ip);
                    $stmt_log->execute();
                    
                    $success = true;
                    $report_data = [
                        'report_id' => $report_id,
                        'worker_name' => $worker_name,
                        'reason' => $report_reason_display,
                        'booking_id' => $booking_id
                    ];
                } else {
                    $error_message = 'Failed to submit report. Please try again later.';
                }
                $stmt_insert->close();
                if (isset($stmt_log)) $stmt_log->close();
            }
            $stmt_check->close();
        }
        $stmt->close();
    } else {
        $error_message = implode(', ', $errors);
    }
}

// If it's a GET request, redirect to my_bookings
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_bookings.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Report | Abilisto</title>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#1d4ed8",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans: ["Plus Jakarta Sans", "sans-serif"],
                    },
                    animation: {
                        'spin-slow': 'spin 1.5s linear infinite',
                        'pulse-fast': 'pulse 1s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'slide-up': 'slideUp 0.3s ease forwards',
                        'fade-in': 'fadeIn 0.2s ease forwards',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(10px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        }
                    }
                },
            },
        };
    </script>
    
    <style>
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 18px;
        }
        
        .success-checkmark {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            animation: scaleIn 0.3s ease forwards;
        }
        
        @keyframes scaleIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        @media (max-width: 640px) {
            .material-symbols-rounded {
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-sans transition-colors duration-300 min-h-screen">
    
    <?php include '../includes/navbar.php'; ?>
    
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 md:py-12">
        
        <?php if ($success): ?>
            <div class="animate-slide-up">
                <div class="bg-white dark:bg-slate-800/90 rounded-2xl shadow-lg border border-slate-200/60 dark:border-slate-700/50 overflow-hidden">
                    <div class="p-6 md:p-8">
                        <div class="flex items-center gap-4 mb-6">
                            <div class="success-checkmark bg-gradient-to-br from-emerald-500 to-green-600 shadow-md">
                                <span class="material-symbols-rounded text-white text-3xl">check_circle</span>
                            </div>
                            <div>
                                <h1 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-white">Report Submitted</h1>
                                <p class="text-slate-500 dark:text-slate-400 text-sm mt-0.5">Thank you for helping keep our community safe</p>
                            </div>
                        </div>
                        
                        <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl p-4 mb-6 border border-emerald-200 dark:border-emerald-800">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-rounded text-emerald-600 dark:text-emerald-400 text-lg shrink-0 mt-0.5">info</span>
                                <div class="text-sm">
                                    <p class="text-emerald-800 dark:text-emerald-300 font-medium mb-1">Report #<?php echo $report_data['report_id']; ?> received</p>
                                    <p class="text-emerald-700 dark:text-emerald-400 leading-relaxed">
                                        Our team will review within 24-48 hours and take appropriate action.
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-slate-50 dark:bg-slate-700/30 rounded-xl p-4 mb-6">
                            <h3 class="font-semibold text-slate-800 dark:text-slate-200 text-sm mb-3 flex items-center gap-2">
                                <span class="material-symbols-rounded text-primary text-base">receipt</span>
                                Report Summary
                            </h3>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="flex justify-between items-center py-2 border-b border-slate-200 dark:border-slate-600">
                                    <span class="text-xs text-slate-500 dark:text-slate-400">Worker:</span>
                                    <span class="text-xs font-medium text-slate-800 dark:text-slate-200">
                                        <?php echo htmlspecialchars($report_data['worker_name']); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 border-b border-slate-200 dark:border-slate-600">
                                    <span class="text-xs text-slate-500 dark:text-slate-400">Reason:</span>
                                    <span class="text-xs font-medium text-rose-600 dark:text-rose-400">
                                        <?php echo htmlspecialchars($report_data['reason']); ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center py-2 sm:border-b border-slate-200 dark:border-slate-600 sm:pb-2">
                                    <span class="text-xs text-slate-500 dark:text-slate-400">Booking ID:</span>
                                    <span class="text-xs font-mono text-slate-700 dark:text-slate-300">
                                        #<?php echo $report_data['booking_id']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 mb-6 border border-blue-200 dark:border-blue-800">
                            <h3 class="font-semibold text-blue-800 dark:text-blue-300 text-sm mb-2 flex items-center gap-2">
                                <span class="material-symbols-rounded text-base">timeline</span>
                                Next Steps
                            </h3>
                            <ul class="space-y-1.5 text-xs text-blue-700 dark:text-blue-400">
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-blue-500 text-xs mt-0.5">check_circle</span>
                                    <span>Review within 24-48 hours</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-blue-500 text-xs mt-0.5">check_circle</span>
                                    <span>May contact for additional info</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-blue-500 text-xs mt-0.5">check_circle</span>
                                    <span>Updates via notifications</span>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row gap-3">
                            <a href="my_bookings.php" 
                               class="flex-1 flex items-center justify-center gap-2 bg-primary hover:bg-blue-700 text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-md shadow-blue-500/20 text-sm group">
                                <span class="material-symbols-rounded text-base transition-transform group-hover:-translate-x-0.5">arrow_back</span>
                                Back to Bookings
                            </a>
                            <a href="dashboard.php" 
                               class="flex-1 flex items-center justify-center gap-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold py-2.5 px-4 rounded-xl transition-all text-sm">
                                <span class="material-symbols-rounded text-base">search</span>
                                Find Worker
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            <div class="animate-slide-up">
                <div class="bg-white dark:bg-slate-800/90 rounded-2xl shadow-lg border border-slate-200/60 dark:border-slate-700/50 overflow-hidden">
                    <div class="p-6 md:p-8">
                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-14 h-14 bg-gradient-to-br from-rose-500 to-red-600 rounded-full flex items-center justify-center shadow-md">
                                <span class="material-symbols-rounded text-white text-3xl">error</span>
                            </div>
                            <div>
                                <h1 class="text-xl md:text-2xl font-bold text-slate-900 dark:text-white">Submission Failed</h1>
                                <p class="text-slate-500 dark:text-slate-400 text-sm mt-0.5">Please check the error below</p>
                            </div>
                        </div>
                        
                        <div class="bg-rose-50 dark:bg-rose-900/20 rounded-xl p-4 mb-6 border border-rose-200 dark:border-rose-800">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-rounded text-rose-600 dark:text-rose-400 text-lg shrink-0 mt-0.5">error</span>
                                <div class="text-sm">
                                    <p class="text-rose-800 dark:text-rose-300 font-medium mb-1">Unable to submit report</p>
                                    <p class="text-rose-700 dark:text-rose-400 leading-relaxed">
                                        <?php echo htmlspecialchars($error_message); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl p-4 mb-6 border border-amber-200 dark:border-amber-800">
                            <h3 class="font-semibold text-amber-800 dark:text-amber-300 text-sm mb-2 flex items-center gap-2">
                                <span class="material-symbols-rounded text-base">lightbulb</span>
                                Suggestions
                            </h3>
                            <ul class="space-y-1.5 text-xs text-amber-700 dark:text-amber-400">
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-amber-500 text-xs mt-0.5">arrow_right</span>
                                    <span>Select a valid reason for the report</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-amber-500 text-xs mt-0.5">arrow_right</span>
                                    <span>Check if already reported this booking</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="material-symbols-rounded text-amber-500 text-xs mt-0.5">arrow_right</span>
                                    <span>Contact support@abilisto.com if issue persists</span>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row gap-3">
                            <a href="javascript:history.back()" 
                               class="flex-1 flex items-center justify-center gap-2 bg-primary hover:bg-blue-700 text-white font-semibold py-2.5 px-4 rounded-xl transition-all shadow-md shadow-blue-500/20 text-sm group">
                                <span class="material-symbols-rounded text-base transition-transform group-hover:-translate-x-0.5">arrow_back</span>
                                Try Again
                            </a>
                            <a href="my_bookings.php" 
                               class="flex-1 flex items-center justify-center gap-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold py-2.5 px-4 rounded-xl transition-all text-sm">
                                <span class="material-symbols-rounded text-base">home</span>
                                My Bookings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>