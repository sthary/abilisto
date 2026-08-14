<?php
// client/my_bookings.php
include '../db.php';
include '../includes/init_lang.php'; 

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

// Fetch ALL Bookings with additional details
// FIX: Use prepared statement to prevent SQL injection
$sql = "SELECT bookings.*, 
               users.full_name AS worker_name, 
               users.profile_pic,
               users.phone as worker_phone,
               reviews.id as review_id, 
               reviews.rating as my_rating,
               reviews.comment as my_review_comment
        FROM bookings 
        JOIN users ON bookings.worker_id = users.id 
        LEFT JOIN reviews ON bookings.id = reviews.booking_id
        WHERE bookings.client_id = ?
        ORDER BY bookings.booking_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();

// UX SEGMENTATION: Separate Active vs Past bookings
// FIX: Pre-sort newest to oldest (already done by ORDER BY DESC above)
$active_bookings = [];
$past_bookings = [];

while ($row = $result->fetch_assoc()) {
    if (in_array($row['status'], ['Pending', 'Accepted', 'In Progress', 'On The Way', 'Pending Confirmation'])) {
        $active_bookings[] = $row;
    } else {
        $past_bookings[] = $row; // Completed, Cancelled, Rejected
    }
}

// Helper function to get status styling
function getStatusStyle($status) {
    $styles = [
        'Pending'              => ['bg' => 'bg-amber-100/50 dark:bg-amber-900/20',  'color' => 'text-amber-700 dark:text-amber-400',   'icon' => 'schedule'],
        'Accepted'             => ['bg' => 'bg-emerald-100/50 dark:bg-emerald-900/20','color' => 'text-emerald-700 dark:text-emerald-400','icon' => 'check_circle'],
        'In Progress'          => ['bg' => 'bg-blue-100/50 dark:bg-blue-900/20',    'color' => 'text-blue-700 dark:text-blue-400',     'icon' => 'sync'],
        'On The Way'           => ['bg' => 'bg-blue-100/50 dark:bg-blue-900/20',    'color' => 'text-blue-700 dark:text-blue-400',     'icon' => 'local_shipping'],
        'Pending Confirmation' => ['bg' => 'bg-purple-100/50 dark:bg-purple-900/20','color' => 'text-purple-700 dark:text-purple-400', 'icon' => 'pending_actions'],
        'Completed'            => ['bg' => 'bg-green-100/50 dark:bg-green-900/20',  'color' => 'text-green-700 dark:text-green-400',   'icon' => 'check_circle'],
        'Cancelled'            => ['bg' => 'bg-red-100/50 dark:bg-red-900/20',      'color' => 'text-red-700 dark:text-red-400',       'icon' => 'cancel'],
        'Rejected'             => ['bg' => 'bg-red-100/50 dark:bg-red-900/20',      'color' => 'text-red-700 dark:text-red-400',       'icon' => 'block']
    ];
    return $styles[$status] ?? ['bg' => 'bg-slate-100/50 dark:bg-slate-700/50', 'color' => 'text-slate-700 dark:text-slate-400', 'icon' => 'circle'];
}

// Helper function to get urgency styling
function getUrgencyStyle($urgency) {
    $styles = [
        'Emergency' => ['bg' => 'bg-red-100/50 dark:bg-red-900/20',       'color' => 'text-red-700 dark:text-red-400',       'icon' => 'priority_high', 'label' => 'Emergency'],
        'High'      => ['bg' => 'bg-orange-100/50 dark:bg-orange-900/20', 'color' => 'text-orange-700 dark:text-orange-400', 'icon' => 'priority_high', 'label' => 'High Priority'],
        'Normal'    => ['bg' => 'bg-blue-100/50 dark:bg-blue-900/20',     'color' => 'text-blue-700 dark:text-blue-400',     'icon' => 'schedule',      'label' => 'Normal']
    ];
    return $styles[$urgency] ?? $styles['Normal'];
}

// Helper function to get initials
function getInitials($name) {
    $words    = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// FIX: Move renderBookingCard() BEFORE it is called in the HTML output
function renderBookingCard($row, $lang, $type, $index = 0) {
    // Avatar
    $hasImage = !empty($row['profile_pic']) && file_exists("../uploads/profiles/" . $row['profile_pic']);
    $avatar   = $hasImage ? "../uploads/profiles/" . $row['profile_pic'] : '';
    $initials = getInitials($row['worker_name']);

    // Status & urgency styling
    $statusStyle  = getStatusStyle($row['status']);
    $urgencyStyle = getUrgencyStyle($row['urgency_level']);

    // Format date nicely
    $bookingDate = date('M d, Y', strtotime($row['booking_date']));
    $bookingTime = date('h:i A',  strtotime($row['booking_date']));

    // Animation delay
    $delay = $index * 0.1;

    // Card accent colour
    $cardAccent = $row['urgency_level'] == 'Emergency' ? 'bg-red-500/5'
                : ($row['urgency_level'] == 'High'      ? 'bg-orange-500/5'
                :  'bg-blue-500/5');

    // Sortable timestamp attribute (ISO) used by JS sort
    $isoDate = $row['booking_date'];

    echo '
    <div class="booking-card group relative bg-white dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/50 rounded-2xl p-6 shadow-soft hover:shadow-xl transition-all duration-300 overflow-hidden"
         data-date="' . $isoDate . '"
         style="animation-delay: ' . $delay . 's;">
        <div class="absolute -right-20 -top-20 w-40 h-40 ' . $cardAccent . ' rounded-full blur-3xl group-hover:opacity-75 transition-opacity"></div>

        <!-- FIX: flex layout corrected so buttons stack vertically on all screen sizes -->
        <div class="flex flex-col lg:flex-row lg:items-start gap-6 relative z-10">

            <!-- Worker Info -->
            <div class="flex items-center gap-5 lg:min-w-[260px]">
                <div class="relative shrink-0">
                    ';
                    if ($hasImage) {
                        echo '<img src="' . $avatar . '" class="w-20 h-20 rounded-full object-cover border-4 border-slate-50 dark:border-slate-700 shadow-md" alt="' . htmlspecialchars($row['worker_name']) . '">';
                    } else {
                        echo '<div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-2xl border-4 border-slate-50 dark:border-slate-700 shadow-md">' . $initials . '</div>';
                    }

                    if (in_array($row['status'], ['Accepted', 'In Progress', 'On The Way', 'Pending Confirmation'])) {
                        echo '<div class="absolute bottom-0 right-0 w-6 h-6 bg-green-500 border-4 border-white dark:border-slate-800 rounded-full"></div>';
                    } elseif ($row['status'] == 'Pending') {
                        echo '<div class="absolute bottom-0 right-0 w-6 h-6 bg-amber-500 border-4 border-white dark:border-slate-800 rounded-full animate-pulse"></div>';
                    }
                    echo '
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white">' . htmlspecialchars($row['worker_name']) . '</h3>
                    <div class="mt-2 space-y-1">
                        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400 text-sm">
                            <span class="material-symbols-rounded text-[18px]">calendar_month</span>
                            <span>' . $bookingDate . '</span>
                        </div>
                        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400 text-sm">
                            <span class="material-symbols-rounded text-[18px]">schedule</span>
                            <span>' . $bookingTime . '</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Booking Details -->
            <div class="flex-1 space-y-4 min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Status Badge -->
                    <span class="glass-badge ' . $statusStyle['bg'] . ' ' . $statusStyle['color'] . ' px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px]">' . $statusStyle['icon'] . '</span>
                        ' . $row['status'] . '
                    </span>';

    // Urgency badge (if not Normal)
    if ($row['urgency_level'] && $row['urgency_level'] != 'Normal') {
        echo '<span class="glass-badge ' . $urgencyStyle['bg'] . ' ' . $urgencyStyle['color'] . ' px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-rounded text-[16px]">' . $urgencyStyle['icon'] . '</span>
                ' . $urgencyStyle['label'] . '
              </span>';
    }

    // Payment badge
    echo '<span class="glass-badge bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 px-4 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider flex items-center gap-2">
            <span class="material-symbols-rounded text-[16px]">' . ($row['payment_method'] == 'Xendit' ? 'smartphone' : 'payments') . '</span>
            ' . htmlspecialchars($row['payment_method']) . ' • ' . htmlspecialchars($row['payment_status']) . '
          </span>';

    // Price tag
    if (!empty($row['calculated_fee'])) {
        echo '<span class="bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 px-4 py-1.5 rounded-full text-sm font-bold">
                ₱ ' . number_format($row['calculated_fee'], 2) . '
              </span>';
    }

    echo '</div>';

    // Problem description
    echo '<div class="flex gap-3 text-slate-600 dark:text-slate-300 italic">
            <span class="material-symbols-rounded text-blue-400 shrink-0">format_quote</span>
            <p class="leading-relaxed">' . htmlspecialchars($row['problem_desc']) . '</p>
          </div>';

    echo '</div>';

    // FIX: Action Buttons — always stacked vertically (flex-col), proper min-width
    echo '<div class="flex flex-col gap-3 lg:min-w-[180px] w-full lg:w-auto shrink-0">';

    if ($type == 'active') {
        // Chat button for all active
        echo '<a href="../chat.php?booking_id=' . $row['id'] . '" class="flex items-center justify-center gap-2 bg-primary hover:bg-blue-700 text-white font-bold py-3 px-5 rounded-xl transition-all shadow-lg shadow-blue-500/20 whitespace-nowrap">
                <span class="material-symbols-rounded">chat</span>
                Chat
              </a>';

        // Track button for Accepted, In Progress, and On The Way
        if (in_array($row['status'], ['Accepted', 'In Progress', 'On The Way'])) {
            echo '<a href="track_worker.php?booking_id=' . $row['id'] . '" class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-5 rounded-xl transition-all shadow-lg shadow-emerald-500/20 whitespace-nowrap">
                    <span class="material-symbols-rounded">location_on</span>
                    Track Live
                  </a>';
        }

        // ── CANCEL + RESCHEDULE (Pending only) ──────────────────────────
        // Split into two small icon-only buttons side by side
        if ($row['status'] == 'Pending') {
            // Pass current booking_date so flatpickr can pre-fill it
            $currentDateISO = date('Y-m-d H:i:s', strtotime($row['booking_date']));
            echo '<div class="flex gap-2">
                    <!-- Cancel: icon only, red -->
                    <a href="cancel_booking.php?id=' . $row['id'] . '"
                       onclick="return confirm(\'Cancel this booking?\')"
                       title="Cancel Booking"
                       class="flex-1 flex items-center justify-center bg-rose-50 dark:bg-rose-900/20 hover:bg-rose-100 dark:hover:bg-rose-900/40 text-rose-600 dark:text-rose-400 font-bold py-3 rounded-xl transition-all"
                    >
                        <span class="material-symbols-rounded text-[20px]">close</span>
                    </a>
                    <!-- Reschedule: icon only, amber -->
                    <button
                       onclick="openRescheduleModal(' . $row['id'] . ', \'' . addslashes($row['worker_name']) . '\', \'' . $currentDateISO . '\', ' . $row['worker_id'] . ')"
                       title="Reschedule Booking"
                       class="flex-1 flex items-center justify-center bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/40 text-amber-600 dark:text-amber-400 font-bold py-3 rounded-xl transition-all"
                    >
                        <span class="material-symbols-rounded text-[20px]">calendar_month</span>
                    </button>
                  </div>';
        }

        // Confirm Completion button:
        // - 'Pending Confirmation' = worker confirmed cash received, waiting for client
        // - 'Accepted' + Xendit   = GCash paid, client confirms job done
        if ($row['status'] == 'Pending Confirmation' || 
            ($row['status'] == 'Accepted' && $row['payment_method'] == 'Xendit' && $row['final_payment_status'] == 'paid')) {

            $btn_label = $row['status'] == 'Pending Confirmation'
                ? 'Confirm Cash Received'
                : 'Confirm Job Done';

            echo '<button onclick="confirmCompletion(' . $row['id'] . ')" class="flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-5 rounded-xl transition-all shadow-lg shadow-emerald-500/20 whitespace-nowrap animate-pulse">
                    <span class="material-symbols-rounded">check_circle</span>
                    ' . $btn_label . '
                  </button>';
        }

    } else {
        // History actions
        if ($row['status'] == 'Completed') {
            if ($row['review_id']) {
                // Already rated
                echo '<div class="flex items-center justify-center gap-2 p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                        <div class="flex gap-0.5 text-amber-400">';
                for ($i = 0; $i < $row['my_rating']; $i++)           echo '<span class="material-symbols-rounded text-[18px]">star</span>';
                for ($i = $row['my_rating']; $i < 5; $i++)            echo '<span class="material-symbols-rounded text-[18px] text-slate-300 dark:text-slate-600">star</span>';
                echo '      </div>
                        <span class="text-xs text-slate-500 whitespace-nowrap">You rated</span>
                      </div>';

                // Re-book button
                echo '<a href="booking.php?worker_id=' . $row['worker_id'] . '" class="flex items-center justify-center gap-2 bg-primary hover:bg-blue-700 text-white font-bold py-3 px-5 rounded-xl transition-all shadow-lg shadow-blue-500/20 whitespace-nowrap">
                        <span class="material-symbols-rounded">calendar_add_on</span>
                        Book Again
                      </a>';
            } else {
                // Rate button
                echo '<button onclick="openRateModal(' . $row['id'] . ', ' . $row['worker_id'] . ', \'' . addslashes($row['worker_name']) . '\')" class="flex items-center justify-center gap-2 bg-amber-600 hover:bg-amber-700 text-white font-bold py-3 px-5 rounded-xl transition-all shadow-lg shadow-amber-500/20 whitespace-nowrap">
                        <span class="material-symbols-rounded">star</span>
                        Rate Worker
                      </button>';
            }
        } else {
            // Cancelled/Rejected status
            echo '<div class="flex items-center justify-center gap-2 p-3 bg-slate-50 dark:bg-slate-800 rounded-xl">
                    <span class="material-symbols-rounded ' . $statusStyle['color'] . '">' . $statusStyle['icon'] . '</span>
                    <span class="text-sm font-medium">' . $row['status'] . '</span>
                  </div>';
        }

        // FIX: Report button for ALL history bookings
        echo '<button onclick="openReportModal(' . $row['id'] . ', \'' . addslashes($row['worker_name']) . '\')" class="flex items-center justify-center gap-2 bg-slate-100 dark:bg-slate-700/60 hover:bg-rose-50 dark:hover:bg-rose-900/20 text-slate-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 font-bold py-3 px-5 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-rose-300 dark:hover:border-rose-700 transition-all whitespace-nowrap">
                <span class="material-symbols-rounded text-[18px]">flag</span>
                Report
              </button>';
    }

    echo '  </div>
        </div>
    </div>';
}
?>

<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo $lang['my_bookings_title']; ?> | Abilisto</title>

    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography"></script>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>

    <!-- Font Awesome (for backward compatibility) -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <!-- Flatpickr (for reschedule calendar) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#146af5",
                        "background-light": "#F8FAFC",
                        "background-dark": "#0f172a",
                        surface: {
                            light: "#FFFFFF",
                            dark: "#1e293b"
                        }
                    },
                    fontFamily: {
                        display: ["Plus Jakarta Sans", "sans-serif"],
                        sans:    ["Plus Jakarta Sans", "sans-serif"],
                    },
                    borderRadius: {
                        DEFAULT: "12px",
                        'xl':    '16px',
                        '2xl':   '24px',
                    },
                    boxShadow: {
                        'soft':    '0 10px 40px -10px rgba(0, 0, 0, 0.05)',
                        'primary': '0 10px 25px -5px rgba(29, 78, 216, 0.3)',
                    }
                },
            },
        };
    </script>

    <style>
        .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
            font-size: 20px;
        }
        .glass-badge {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .booking-card { animation: slideUp 0.4s ease forwards; }

        /* Sort dropdown */
        .sort-dropdown { display: none; }
        .sort-dropdown.open { display: block; }

        /* Flatpickr overrides inside modal */
        #rescheduleModal .flatpickr-calendar {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0;
        }
        .dark #rescheduleModal .flatpickr-calendar {
            background: #1e293b;
            border-color: #334155;
            color: white;
        }
        .flatpickr-day.selected {
            background: #146af5 !important;
            border-color: #146af5 !important;
        }
        .flatpickr-day.today {
            border-color: #146af5 !important;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-sans transition-colors duration-300 min-h-screen">

<?php include '../includes/navbar.php'; ?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

    <!-- Header -->
    <header class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12">
        <div>
            <h1 class="text-4xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-2">
                <?php echo $lang['my_bookings_header']; ?>
            </h1>
            <p class="text-slate-500 dark:text-slate-400 text-lg">
                <?php echo $lang['my_bookings_sub']; ?>
            </p>
        </div>
        <a href="dashboard.php" class="flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold py-4 px-8 rounded-2xl shadow-primary transition-all active:scale-95 group">
            <span class="material-symbols-rounded transition-transform group-hover:rotate-90">add</span>
            New Booking
        </a>
    </header>

    <!-- Tabs + Sort -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 mb-8">
        <div class="flex p-1.5 bg-slate-200/50 dark:bg-slate-800/50 backdrop-blur-md rounded-2xl w-fit">
            <button class="tab-btn flex items-center gap-2 px-6 py-2.5 rounded-xl bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold transition-all" id="active-tab" onclick="switchTab('active')">
                <span class="material-symbols-rounded">schedule</span>
                <span>Active Jobs</span>
                <?php if (count($active_bookings) > 0): ?>
                <span class="bg-primary text-white text-[11px] font-bold px-2 py-0.5 rounded-full"><?php echo count($active_bookings); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn flex items-center gap-2 px-6 py-2.5 rounded-xl text-slate-500 dark:text-slate-400 font-semibold hover:text-slate-700 dark:hover:text-slate-200 transition-all" id="history-tab" onclick="switchTab('history')">
                <span class="material-symbols-rounded">history</span>
                <span>History</span>
            </button>
        </div>

        <!-- FIX: Functional Sort by Date dropdown -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative" id="sortDropdownWrapper">
                <button id="sortBtn"
                        onclick="toggleSortDropdown()"
                        class="flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-600 dark:text-slate-300 hover:border-primary transition-colors">
                    <span class="material-symbols-rounded text-slate-400">calendar_today</span>
                    <span class="text-sm font-medium" id="sortLabel">Newest First</span>
                    <span class="material-symbols-rounded text-slate-400" id="sortChevron">expand_more</span>
                </button>
                <div id="sortDropdown" class="sort-dropdown absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl z-40 overflow-hidden">
                    <button onclick="applySort('desc')" class="w-full flex items-center gap-3 px-4 py-3 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium" id="sortDesc">
                        <span class="material-symbols-rounded text-[18px] text-primary">arrow_downward</span>
                        Newest First
                        <span class="material-symbols-rounded text-primary text-[16px] ml-auto">check</span>
                    </button>
                    <button onclick="applySort('asc')" class="w-full flex items-center gap-3 px-4 py-3 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors font-medium" id="sortAsc">
                        <span class="material-symbols-rounded text-[18px] text-slate-400">arrow_upward</span>
                        Oldest First
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Content: Active -->
    <div id="active-content" class="tab-content space-y-6">
        <?php if (count($active_bookings) > 0): ?>
            <?php foreach ($active_bookings as $index => $row): ?>
                <?php renderBookingCard($row, $lang, 'active', $index); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white dark:bg-slate-800/50 rounded-3xl p-16 text-center border border-slate-200/60 dark:border-slate-700/50">
                <div class="w-24 h-24 mx-auto mb-6 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center">
                    <span class="material-symbols-rounded text-4xl text-primary">calendar_month</span>
                </div>
                <h3 class="text-2xl font-bold mb-3 text-slate-900 dark:text-white">No Active Bookings</h3>
                <p class="text-slate-500 dark:text-slate-400 mb-8 max-w-md mx-auto">Ready to get help with something? Find a worker now!</p>
                <a href="dashboard.php" class="inline-flex items-center gap-2 bg-primary hover:bg-blue-700 text-white font-bold py-4 px-8 rounded-2xl transition-all shadow-lg shadow-blue-500/20">
                    <span class="material-symbols-rounded">search</span>
                    Find a Worker
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Content: History -->
    <div id="history-content" class="tab-content space-y-6 hidden">
        <?php if (count($past_bookings) > 0): ?>
            <?php foreach ($past_bookings as $index => $row): ?>
                <?php renderBookingCard($row, $lang, 'history', $index); ?>
            <?php endforeach; ?>

            <?php if (count($past_bookings) > 5): ?>
            <div class="mt-12 flex justify-center">
                <button class="flex items-center gap-2 px-8 py-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold rounded-2xl hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                    Load More History
                    <span class="material-symbols-rounded">keyboard_arrow_down</span>
                </button>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="bg-white dark:bg-slate-800/50 rounded-3xl p-16 text-center border border-slate-200/60 dark:border-slate-700/50">
                <div class="w-24 h-24 mx-auto mb-6 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center">
                    <span class="material-symbols-rounded text-4xl text-primary">folder_open</span>
                </div>
                <h3 class="text-2xl font-bold mb-3 text-slate-900 dark:text-white">No Booking History</h3>
                <p class="text-slate-500 dark:text-slate-400 max-w-md mx-auto">Your completed and past bookings will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Rate Modal ─────────────────────────────────────────────────── -->
<div id="rateModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8 animate-slideUp">
        <div class="text-center">
            <div class="w-20 h-20 mx-auto mb-4 bg-amber-100 dark:bg-amber-900/20 rounded-full flex items-center justify-center">
                <span class="material-symbols-rounded text-4xl text-amber-500">star</span>
            </div>
            <h3 class="text-2xl font-bold mb-2 text-slate-900 dark:text-white">Rate Your Experience</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-6" id="modalWorkerName"></p>

            <form action="submit_rating.php" method="POST" class="space-y-4">
                <input type="hidden" name="booking_id" id="modalBookingId">
                <input type="hidden" name="worker_id" id="modalWorkerId">
                <input type="hidden" name="submit_rating" value="1">
                
                <select name="rating" class="w-full p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" required>
                    <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                    <option value="4">⭐⭐⭐⭐ Good</option>
                    <option value="3">⭐⭐⭐ Average</option>
                    <option value="2">⭐⭐ Poor</option>
                    <option value="1">⭐ Terrible</option>
                </select>

                <textarea name="comment" rows="3" class="w-full p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800" placeholder="Share your experience… (optional)"></textarea>

                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="closeRateModal()" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 py-3 px-4 rounded-xl bg-primary hover:bg-blue-700 text-white font-bold transition-all shadow-lg shadow-blue-500/20">
                        Submit Review
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Report Modal ───────────────────────────────────────────────── -->
<div id="reportModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-slate-800 rounded-3xl max-w-md w-full p-8">
        <div class="text-center mb-6">
            <div class="w-20 h-20 mx-auto mb-4 bg-rose-100 dark:bg-rose-900/20 rounded-full flex items-center justify-center">
                <span class="material-symbols-rounded text-4xl text-rose-500">flag</span>
            </div>
            <h3 class="text-2xl font-bold mb-2 text-slate-900 dark:text-white">Report a Problem</h3>
            <p class="text-slate-500 dark:text-slate-400 text-sm" id="reportWorkerName"></p>
        </div>

        <form action="submit_report.php" method="POST" class="space-y-4">
            <input type="hidden" name="booking_id" id="reportBookingId">

            <select name="report_reason" class="w-full p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200" required>
                <option value="" disabled selected>Select a reason…</option>
                <option value="no_show">Worker did not show up</option>
                <option value="unprofessional">Unprofessional behaviour</option>
                <option value="overcharging">Overcharging / incorrect fee</option>
                <option value="poor_quality">Poor quality of work</option>
                <option value="harassment">Harassment or inappropriate conduct</option>
                <option value="other">Other</option>
            </select>

            <textarea name="report_details" rows="4"
                      class="w-full p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200"
                      placeholder="Please describe what happened… (optional)"></textarea>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeReportModal()" class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                    Cancel
                </button>
                <button type="submit" class="flex-1 py-3 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold transition-all shadow-lg shadow-rose-500/20">
                    Submit Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reschedule Modal ───────────────────────────────────────────── -->
<div id="rescheduleModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 hidden p-4">
    <!-- max-w-2xl for wide layout, max-h with scroll so it never overflows viewport -->
    <div class="bg-white dark:bg-slate-800 rounded-3xl w-full max-w-2xl shadow-2xl max-h-[90vh] flex flex-col">

        <!-- Scrollable body -->
        <div class="overflow-y-auto flex-1 p-6 md:p-8">

            <!-- Header row: icon + title side by side on md+ -->
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 shrink-0 bg-amber-100 dark:bg-amber-900/20 rounded-2xl flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl text-amber-500">edit_calendar</span>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white leading-tight">Reschedule Booking</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm" id="rescheduleWorkerName"></p>
                </div>
            </div>

            <!-- Two-column grid on md+, single column on mobile -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">

                <!-- Current date info -->
                <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-700/40 border border-slate-200 dark:border-slate-700">
                    <span class="material-symbols-rounded text-slate-400 text-[18px] shrink-0">event</span>
                    <div class="min-w-0">
                        <p class="text-xs text-slate-400 font-medium">Current Schedule</p>
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-200 break-words" id="rescheduleCurrentDate"></p>
                    </div>
                </div>

                <!-- Smart note -->
                <div class="flex items-start gap-3 p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                    <span class="material-symbols-rounded text-blue-500 text-[18px] shrink-0 mt-0.5">info</span>
                    <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
                        Only <strong>Pending</strong> bookings can be rescheduled. Once accepted, use Chat to coordinate with the worker.
                    </p>
                </div>
            </div>

            <!-- New date picker + Reason: side by side on md+ -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Date picker -->
                <div class="space-y-2">
                    <label class="text-sm font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary text-[18px]">calendar_month</span>
                        New Date &amp; Time
                    </label>
                    <input type="text"
                           id="rescheduleDateInput"
                           class="w-full h-12 pl-4 pr-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-white text-sm font-medium focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                           placeholder="Select new date and time"
                           readonly>
                </div>

                <!-- Reason -->
                <div class="space-y-2">
                    <label class="text-sm font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary text-[18px]">chat_bubble</span>
                        Reason
                        <span class="text-slate-400 font-normal text-xs">(optional)</span>
                    </label>
                    <select id="rescheduleReason" class="w-full h-12 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-200">
                        <option value="">Select a reason…</option>
                        <option value="conflict">Schedule conflict</option>
                        <option value="emergency">Personal emergency</option>
                        <option value="mistake">Booked wrong time</option>
                        <option value="weather">Bad weather</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <!-- Validation message -->
            <p id="rescheduleError" class="hidden text-xs text-rose-600 dark:text-rose-400 flex items-center gap-1 mt-3">
                <span class="material-symbols-rounded text-[14px]">error</span>
                <span></span>
            </p>
        </div>

        <!-- Hidden fields -->
        <input type="hidden" id="rescheduleBookingId">
        <input type="hidden" id="rescheduleWorkerId">
        <input type="hidden" id="rescheduleNewDate">

        <!-- Footer buttons — sticky at bottom, never pushed off screen -->
        <div class="shrink-0 flex gap-3 p-6 md:px-8 md:pb-8 pt-0 border-t border-slate-100 dark:border-slate-700 mt-0">
            <button type="button"
                    onclick="closeRescheduleModal()"
                    class="flex-1 py-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-700 transition-all">
                Cancel
            </button>
            <button type="button"
                    id="rescheduleSubmitBtn"
                    onclick="submitReschedule()"
                    class="flex-1 py-3 px-4 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold transition-all shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2">
                <span class="material-symbols-rounded text-[18px]">check</span>
                Confirm Reschedule
            </button>
        </div>
    </div>
</div>

<script>
    // ── Tab switching ──────────────────────────────────────────────
    function switchTab(tab) {
        const activeContent  = document.getElementById('active-content');
        const historyContent = document.getElementById('history-content');
        const activeTab      = document.getElementById('active-tab');
        const historyTab     = document.getElementById('history-tab');

        if (tab === 'active') {
            activeContent.classList.remove('hidden');
            historyContent.classList.add('hidden');
            activeTab.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            activeTab.classList.remove('text-slate-500', 'dark:text-slate-400');
            historyTab.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            historyTab.classList.add('text-slate-500', 'dark:text-slate-400');
        } else {
            activeContent.classList.add('hidden');
            historyContent.classList.remove('hidden');
            historyTab.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            historyTab.classList.remove('text-slate-500', 'dark:text-slate-400');
            activeTab.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
            activeTab.classList.add('text-slate-500', 'dark:text-slate-400');
        }
    }

    // ── Sort dropdown ──────────────────────────────────────────────
    let currentSort = 'desc'; // FIX: pre-sorted newest first by default

    function toggleSortDropdown() {
        const dd = document.getElementById('sortDropdown');
        dd.classList.toggle('open');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('sortDropdownWrapper');
        if (!wrapper.contains(e.target)) {
            document.getElementById('sortDropdown').classList.remove('open');
        }
    });

    function applySort(direction) {
        currentSort = direction;

        // Update label & checkmark
        const label = direction === 'desc' ? 'Newest First' : 'Oldest First';
        document.getElementById('sortLabel').textContent = label;

        const descBtn = document.getElementById('sortDesc');
        const ascBtn  = document.getElementById('sortAsc');
        const checkIcon = '<span class="material-symbols-rounded text-primary text-[16px] ml-auto">check</span>';

        descBtn.innerHTML = '<span class="material-symbols-rounded text-[18px] text-primary">arrow_downward</span> Newest First' + (direction === 'desc' ? checkIcon : '');
        ascBtn.innerHTML  = '<span class="material-symbols-rounded text-[18px] text-slate-400">arrow_upward</span> Oldest First' + (direction === 'asc'  ? checkIcon : '');

        // Sort both containers
        ['active-content', 'history-content'].forEach(containerId => {
            const container = document.getElementById(containerId);
            const cards = Array.from(container.querySelectorAll('.booking-card'));
            cards.sort((a, b) => {
                const dateA = new Date(a.dataset.date);
                const dateB = new Date(b.dataset.date);
                return direction === 'desc' ? dateB - dateA : dateA - dateB;
            });
            cards.forEach(card => container.appendChild(card));
        });

        document.getElementById('sortDropdown').classList.remove('open');
    }

    // ── Rate Modal ─────────────────────────────────────────────────
    function openRateModal(bookingId, workerId, workerName) {
        document.getElementById('modalBookingId').value    = bookingId;
        document.getElementById('modalWorkerId').value     = workerId;
        document.getElementById('modalWorkerName').innerText = workerName;
        document.getElementById('rateModal').classList.remove('hidden');
    }

    function closeRateModal() {
        document.getElementById('rateModal').classList.add('hidden');
    }

    document.getElementById('rateModal').addEventListener('click', function(e) {
        if (e.target === this) closeRateModal();
    });

    // ── Report Modal ───────────────────────────────────────────────
    function openReportModal(bookingId, workerName) {
        document.getElementById('reportBookingId').value      = bookingId;
        document.getElementById('reportWorkerName').innerText = 'Booking with ' + workerName;
        document.getElementById('reportModal').classList.remove('hidden');
    }

    function closeReportModal() {
        document.getElementById('reportModal').classList.add('hidden');
    }

    document.getElementById('reportModal').addEventListener('click', function(e) {
        if (e.target === this) closeReportModal();
    });

    // ── Reschedule Modal ───────────────────────────────────────────
    var rescheduleFP = null; // flatpickr instance

    function openRescheduleModal(bookingId, workerName, currentDate, workerId) {
        document.getElementById('rescheduleBookingId').value  = bookingId;
        document.getElementById('rescheduleWorkerId').value   = workerId;
        document.getElementById('rescheduleWorkerName').innerText = 'with ' + workerName;
        document.getElementById('rescheduleNewDate').value    = '';
        document.getElementById('rescheduleReason').value     = '';
        document.getElementById('rescheduleError').classList.add('hidden');

        // Format current date nicely for display
        var d = new Date(currentDate);
        document.getElementById('rescheduleCurrentDate').innerText = d.toLocaleString('en-PH', {
            weekday: 'short', year: 'numeric', month: 'short',
            day: 'numeric', hour: '2-digit', minute: '2-digit'
        });

        // Show modal first so flatpickr can calculate dimensions
        document.getElementById('rescheduleModal').classList.remove('hidden');

        // Destroy old instance if any
        if (rescheduleFP) { rescheduleFP.destroy(); rescheduleFP = null; }

        // Init flatpickr — minimum 1 hour from now, pre-filled with current booking date
        var minTime = new Date(Date.now() + 60 * 60 * 1000); // at least 1h from now
        rescheduleFP = flatpickr('#rescheduleDateInput', {
            enableTime:      true,
            dateFormat:      'Y-m-d H:i:s',
            altInput:        true,
            altFormat:       'F j, Y at h:i K',
            minDate:         minTime,
            defaultDate:     currentDate,
            minuteIncrement: 15,
            time_24hr:       false,
            onChange: function(selectedDates) {
                if (selectedDates.length > 0) {
                    document.getElementById('rescheduleNewDate').value = rescheduleFP.formatDate(selectedDates[0], 'Y-m-d H:i:S'); // formatDate ensures correct Y-m-d H:i:s — input.value reads the altInput display text
                    // Clear any validation error once user picks a date
                    document.getElementById('rescheduleError').classList.add('hidden');
                }
            }
        });
    }

    function closeRescheduleModal() {
        document.getElementById('rescheduleModal').classList.add('hidden');
        if (rescheduleFP) { rescheduleFP.destroy(); rescheduleFP = null; }
    }

    document.getElementById('rescheduleModal').addEventListener('click', function(e) {
        if (e.target === this) closeRescheduleModal();
    });

    function submitReschedule() {
        var bookingId  = document.getElementById('rescheduleBookingId').value;
        var newDate    = document.getElementById('rescheduleNewDate').value;
        var reason     = document.getElementById('rescheduleReason').value;
        var errorEl    = document.getElementById('rescheduleError');
        var errorSpan  = errorEl.querySelector('span:last-child');
        var submitBtn  = document.getElementById('rescheduleSubmitBtn');

        // Validate
        if (!newDate) {
            errorSpan.textContent = 'Please select a new date and time.';
            errorEl.classList.remove('hidden');
            return;
        }

        // Make sure new date is different from current
        var current = document.getElementById('rescheduleCurrentDate').innerText;
        var picked  = new Date(newDate);
        var minDate = new Date(Date.now() + 60 * 60 * 1000);
        if (picked < minDate) {
            errorSpan.textContent = 'Please choose a time at least 1 hour from now.';
            errorEl.classList.remove('hidden');
            return;
        }

        // Loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="material-symbols-rounded animate-spin text-[18px]">progress_activity</span> Saving…';

        fetch('reschedule_booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'booking_id=' + encodeURIComponent(bookingId)
                + '&new_date='  + encodeURIComponent(newDate)
                + '&reason='    + encodeURIComponent(reason)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                closeRescheduleModal();
                alert('✅ Booking rescheduled! The worker has been notified.');
                location.reload();
            } else {
                errorSpan.textContent = data.message || 'Something went wrong. Please try again.';
                errorEl.classList.remove('hidden');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="material-symbols-rounded text-[18px]">check</span> Confirm Reschedule';
            }
        })
        .catch(function() {
            errorSpan.textContent = 'Network error. Please try again.';
            errorEl.classList.remove('hidden');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span class="material-symbols-rounded text-[18px]">check</span> Confirm Reschedule';
        });
    }

    // ── Confirm completion ─────────────────────────────────────────
    function confirmCompletion(bookingId) {
        if (!confirm('Have you confirmed that the job is complete? Payment will be released to the worker.')) return;

        fetch('../api/client_booking_actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=confirm_completion&booking_id=' + bookingId
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Job confirmed! Payment released to worker.');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            alert('Error confirming completion');
            console.error(err);
        });
    }
</script>

</body>
</html>