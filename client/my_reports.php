<?php
// client/my_reports.php
include '../db.php';
include '../includes/init_lang.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Handle report cancellation (if still pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_report'])) {
    $report_id = intval($_POST['report_id']);
    
    // Verify report belongs to this client and is still pending
    $check_sql = "SELECT id FROM reports WHERE id = ? AND client_id = ? AND status = 'pending'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $report_id, $client_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $update_sql = "UPDATE reports SET status = 'dismissed', admin_notes = 'Cancelled by client' WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $report_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Report cancelled successfully.";
        } else {
            $_SESSION['error'] = "Failed to cancel report.";
        }
    } else {
        $_SESSION['error'] = "Report not found or cannot be cancelled.";
    }
    
    header("Location: my_reports.php");
    exit();
}

// Fetch all reports from this client
$sql = "SELECT r.*, 
               w.full_name as worker_name,
               w.profile_pic as worker_profile,
               w.email as worker_email,
               b.booking_date,
               b.problem_desc,
               b.status as booking_status,
               DATEDIFF(NOW(), r.created_at) as days_ago
        FROM reports r
        JOIN users w ON r.worker_id = w.id
        LEFT JOIN bookings b ON r.booking_id = b.id
        WHERE r.client_id = ?
        ORDER BY 
            CASE r.status
                WHEN 'pending' THEN 1
                WHEN 'reviewed' THEN 2
                WHEN 'resolved' THEN 3
                WHEN 'dismissed' THEN 4
            END,
            r.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$reports_result = $stmt->get_result();

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed
              FROM reports 
              WHERE client_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $client_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Helper function to get status styling
function getReportStatusStyle($status) {
    $styles = [
        'pending' => [
            'bg' => 'bg-amber-100 dark:bg-amber-900/20',
            'text' => 'text-amber-700 dark:text-amber-400',
            'icon' => 'pending',
            'label' => 'Pending Review',
            'progress' => 'w-1/4'
        ],
        'reviewed' => [
            'bg' => 'bg-blue-100 dark:bg-blue-900/20',
            'text' => 'text-blue-700 dark:text-blue-400',
            'icon' => 'visibility',
            'label' => 'Under Review',
            'progress' => 'w-2/4'
        ],
        'resolved' => [
            'bg' => 'bg-green-100 dark:bg-green-900/20',
            'text' => 'text-green-700 dark:text-green-400',
            'icon' => 'check_circle',
            'label' => 'Resolved',
            'progress' => 'w-4/4'
        ],
        'dismissed' => [
            'bg' => 'bg-slate-100 dark:bg-slate-700',
            'text' => 'text-slate-500 dark:text-slate-400',
            'icon' => 'do_not_disturb',
            'label' => 'Dismissed',
            'progress' => 'w-4/4'
        ]
    ];
    return $styles[$status] ?? $styles['pending'];
}

// Helper function to get reason label
function getReasonLabel($reason) {
    $reasons = [
        'no_show' => 'Worker did not show up',
        'unprofessional' => 'Unprofessional behaviour',
        'overcharging' => 'Overcharging / incorrect fee',
        'poor_quality' => 'Poor quality of work',
        'harassment' => 'Harassment or inappropriate conduct',
        'other' => 'Other'
    ];
    return $reasons[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports | Client</title>
    
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
        .progress-bar {
            transition: width 0.5s ease;
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

<?php include '../includes/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white flex items-center gap-3">
                <span class="material-symbols-rounded text-4xl text-primary">flag</span>
                My Reports
            </h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Track and manage your submitted reports</p>
        </div>
        <div class="flex gap-3">
            <a href="my_bookings.php" class="flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                <span class="material-symbols-rounded">book_online</span>
                Back to Bookings
            </a>
        </div>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 rounded-xl flex items-center gap-3">
        <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
        <span class="text-green-700 dark:text-green-300"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-6 p-4 bg-rose-50 dark:bg-rose-900/20 border-l-4 border-rose-500 rounded-xl flex items-center gap-3">
        <span class="material-symbols-rounded text-rose-600 dark:text-rose-400">error</span>
        <span class="text-rose-700 dark:text-rose-300"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
    </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="text-sm text-slate-500 dark:text-slate-400 mb-1">Total Reports</div>
            <div class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo $stats['total'] ?? 0; ?></div>
        </div>
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-2xl p-5 shadow-sm border border-amber-200 dark:border-amber-800">
            <div class="text-sm text-amber-700 dark:text-amber-400 mb-1">Pending</div>
            <div class="text-2xl font-bold text-amber-700 dark:text-amber-400"><?php echo $stats['pending'] ?? 0; ?></div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-2xl p-5 shadow-sm border border-blue-200 dark:border-blue-800">
            <div class="text-sm text-blue-700 dark:text-blue-400 mb-1">Under Review</div>
            <div class="text-2xl font-bold text-blue-700 dark:text-blue-400"><?php echo $stats['reviewed'] ?? 0; ?></div>
        </div>
        <div class="bg-green-50 dark:bg-green-900/20 rounded-2xl p-5 shadow-sm border border-green-200 dark:border-green-800">
            <div class="text-sm text-green-700 dark:text-green-400 mb-1">Resolved</div>
            <div class="text-2xl font-bold text-green-700 dark:text-green-400"><?php echo $stats['resolved'] ?? 0; ?></div>
        </div>
        <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="text-sm text-slate-500 dark:text-slate-400 mb-1">Dismissed</div>
            <div class="text-2xl font-bold text-slate-500 dark:text-slate-400"><?php echo $stats['dismissed'] ?? 0; ?></div>
        </div>
    </div>
    
    <!-- Reports List -->
    <div class="space-y-6">
        <?php if ($reports_result && $reports_result->num_rows > 0): ?>
            <?php while($report = $reports_result->fetch_assoc()): 
                $status_style = getReportStatusStyle($report['status']);
            ?>
            <div class="report-card bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <!-- Report Header -->
                <div class="p-6 border-b border-slate-100 dark:border-slate-700">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <!-- Worker Avatar -->
                            <?php if (!empty($report['worker_profile']) && file_exists("../uploads/profiles/" . $report['worker_profile'])): ?>
                            <img src="../uploads/profiles/<?php echo $report['worker_profile']; ?>" 
                                 class="w-14 h-14 rounded-full object-cover border-2 border-slate-200 dark:border-slate-700">
                            <?php else: ?>
                            <div class="w-14 h-14 rounded-full bg-gradient-to-br from-primary to-indigo-600 flex items-center justify-center text-white font-bold text-xl">
                                <?php echo strtoupper(substr($report['worker_name'], 0, 2)); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div>
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white">
                                    Report against <?php echo htmlspecialchars($report['worker_name']); ?>
                                </h3>
                                <div class="flex items-center gap-3 mt-1 text-sm">
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">
                                        <span class="material-symbols-rounded text-[16px]">event</span>
                                        <?php echo date('M d, Y', strtotime($report['created_at'])); ?>
                                    </span>
                                    <span class="text-slate-300 dark:text-slate-600">•</span>
                                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">
                                        <span class="material-symbols-rounded text-[16px]">schedule</span>
                                        <?php echo $report['days_ago']; ?> days ago
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status Badge -->
                        <span class="px-4 py-2 rounded-full text-xs font-bold <?php echo $status_style['bg']; ?> <?php echo $status_style['text']; ?> flex items-center gap-2">
                            <span class="material-symbols-rounded text-[16px]"><?php echo $status_style['icon']; ?></span>
                            <?php echo $status_style['label']; ?>
                        </span>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="h-1 bg-slate-100 dark:bg-slate-700">
                    <div class="h-1 <?php echo $status_style['bg']; ?> progress-bar <?php echo $status_style['progress']; ?>"></div>
                </div>
                
                <!-- Report Details -->
                <div class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column - Report Info -->
                    <div class="lg:col-span-2 space-y-4">
                        <!-- Reason -->
                        <div>
                            <h4 class="text-xs uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">gavel</span>
                                Reason for Report
                            </h4>
                            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-4">
                                <p class="font-medium text-slate-900 dark:text-white">
                                    <?php echo getReasonLabel($report['report_reason']); ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Details -->
                        <?php if (!empty($report['report_details'])): ?>
                        <div>
                            <h4 class="text-xs uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">description</span>
                                Your Description
                            </h4>
                            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-4">
                                <p class="text-slate-700 dark:text-slate-300 whitespace-pre-line">
                                    <?php echo nl2br(htmlspecialchars($report['report_details'])); ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Related Booking -->
                        <?php if ($report['booking_id']): ?>
                        <div>
                            <h4 class="text-xs uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[14px]">book_online</span>
                                Related Booking
                            </h4>
                            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-4">
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-sm font-mono text-primary">#<?php echo $report['booking_id']; ?></span>
                                    <span class="text-slate-300 dark:text-slate-600">|</span>
                                    <span class="text-sm text-slate-600 dark:text-slate-400">
                                        <?php echo date('M d, Y', strtotime($report['booking_date'])); ?>
                                    </span>
                                    <span class="text-slate-300 dark:text-slate-600">|</span>
                                    <span class="text-sm text-slate-600 dark:text-slate-400">
                                        Status: <?php echo $report['booking_status']; ?>
                                    </span>
                                </div>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2 italic">
                                    "<?php echo htmlspecialchars(substr($report['problem_desc'], 0, 100)) . '...'; ?>"
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Right Column - Admin Notes & Actions -->
                    <div class="lg:col-span-1">
                        <!-- Admin Notes -->
                        <div class="bg-primary/5 dark:bg-primary/10 rounded-xl p-5 border border-primary/10 dark:border-primary/20">
                            <h4 class="text-sm font-bold text-primary dark:text-primary-400 mb-3 flex items-center gap-2">
                                <span class="material-symbols-rounded">admin_panel_settings</span>
                                Admin Response
                            </h4>
                            
                            <?php if (!empty($report['admin_notes'])): ?>
                                <div class="bg-white dark:bg-slate-800 rounded-lg p-4 text-sm">
                                    <p class="text-slate-700 dark:text-slate-300 whitespace-pre-line">
                                        <?php echo nl2br(htmlspecialchars($report['admin_notes'])); ?>
                                    </p>
                                    <?php if ($report['updated_at']): ?>
                                    <p class="text-xs text-slate-400 mt-2">
                                        Updated: <?php echo date('M d, Y h:i A', strtotime($report['updated_at'])); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-6">
                                    <span class="material-symbols-rounded text-3xl text-slate-300 dark:text-slate-600 mb-2">chat</span>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">
                                        No admin response yet
                                    </p>
                                    <p class="text-xs text-slate-400 mt-1">
                                        Admin will review your report and respond soon
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="mt-4 space-y-2">
                            <?php if ($report['status'] == 'pending'): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this report?');">
                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                <button type="submit" name="cancel_report" 
                                        class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl border border-rose-200 dark:border-rose-800 text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-all">
                                    <span class="material-symbols-rounded">close</span>
                                    Cancel Report
                                </button>
                            </form>
                            <?php endif; ?>
                            
                            <?php if ($report['status'] == 'resolved' && $report['booking_id']): ?>
                            <a href="my_bookings.php" 
                               class="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-primary hover:bg-blue-700 text-white font-medium transition-all shadow-lg shadow-primary/20">
                                <span class="material-symbols-rounded">visibility</span>
                                View Booking
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Footer with Report ID -->
                <div class="px-6 py-3 bg-slate-50 dark:bg-slate-700/30 border-t border-slate-100 dark:border-slate-700">
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Report ID: #<?php echo $report['id']; ?></span>
                        <span>Case Reference: RPT-<?php echo str_pad($report['id'], 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            
        <?php else: ?>
            <!-- Empty State -->
            <div class="bg-white dark:bg-slate-800 rounded-3xl p-16 text-center border border-slate-200 dark:border-slate-700">
                <div class="w-24 h-24 mx-auto mb-6 bg-slate-100 dark:bg-slate-700 rounded-full flex items-center justify-center">
                    <span class="material-symbols-rounded text-4xl text-slate-400">inbox</span>
                </div>
                <h3 class="text-2xl font-bold mb-3 text-slate-900 dark:text-white">No Reports Yet</h3>
                <p class="text-slate-500 dark:text-slate-400 mb-8 max-w-md mx-auto">
                    You haven't submitted any reports. If you encounter an issue with a worker, you can report them from your bookings page.
                </p>
                <a href="my_bookings.php" class="inline-flex items-center gap-2 bg-primary hover:bg-blue-700 text-white font-bold py-4 px-8 rounded-2xl transition-all shadow-lg shadow-primary/20">
                    <span class="material-symbols-rounded">book_online</span>
                    View My Bookings
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Help Section -->
    <div class="mt-12 bg-gradient-to-r from-slate-50 to-white dark:from-slate-800 dark:to-slate-800 rounded-3xl p-8 border border-slate-200 dark:border-slate-700">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="text-center">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-amber-100 dark:bg-amber-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">pending</span>
                </div>
                <h4 class="font-bold text-sm mb-1 text-slate-900 dark:text-white">Pending</h4>
                <p class="text-xs text-slate-500">Report waiting for admin review</p>
            </div>
            <div class="text-center">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">visibility</span>
                </div>
                <h4 class="font-bold text-sm mb-1 text-slate-900 dark:text-white">Under Review</h4>
                <p class="text-xs text-slate-500">Admin is investigating</p>
            </div>
            <div class="text-center">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
                </div>
                <h4 class="font-bold text-sm mb-1 text-slate-900 dark:text-white">Resolved</h4>
                <p class="text-xs text-slate-500">Issue has been addressed</p>
            </div>
            <div class="text-center">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                    <span class="material-symbols-rounded text-slate-600 dark:text-slate-400">do_not_disturb</span>
                </div>
                <h4 class="font-bold text-sm mb-1 text-slate-900 dark:text-white">Dismissed</h4>
                <p class="text-xs text-slate-500">Report was not substantiated</p>
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

</body>
</html>