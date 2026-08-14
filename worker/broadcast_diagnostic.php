<?php
// worker/broadcast_diagnostic.php
// Diagnostic tool to view all broadcasts for worker ID=8
// Access: /abilisto/worker/broadcast_diagnostic.php

session_start();
include '../db.php';

// Security - only allow access if logged in as worker or admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'worker' && $_SESSION['role'] !== 'admin')) {
    die("Access denied. Worker or admin access required.");
}

// Hardcoded worker ID for testing (as requested)
$target_worker_id = 8;

// Get worker info
$worker_info = $conn->query("SELECT full_name, email, phone FROM users WHERE id = $target_worker_id")->fetch_assoc();

// ============================================
// QUERY 1: All broadcasts for this worker (via job_candidates)
// ============================================
$broadcasts_sql = "
    SELECT 
        jb.*,
        u.full_name as client_name,
        u.phone as client_phone,
        u.email as client_email,
        jc.score as candidate_score,
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at) as seconds_remaining
    FROM job_broadcasts jb
    JOIN job_candidates jc ON jb.id = jc.broadcast_id
    JOIN users u ON jb.client_id = u.id
    WHERE jc.worker_id = $target_worker_id
    ORDER BY jb.created_at DESC";

$broadcasts = $conn->query($broadcasts_sql);

// ============================================
// QUERY 2: All bookings for this worker
// ============================================
$bookings_sql = "
    SELECT 
        b.*,
        u.full_name as client_name,
        u.phone as client_phone,
        u.email as client_email,
        u.latitude as client_lat,
        u.longitude as client_lng,
        jb.id as broadcast_id,
        jb.status as broadcast_status,
        jb.expires_at as broadcast_expires_at,
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at) as seconds_remaining
    FROM bookings b
    JOIN users u ON b.client_id = u.id
    LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
    WHERE b.worker_id = $target_worker_id
    ORDER BY 
        CASE b.status
            WHEN 'Pending' THEN 1
            WHEN 'Accepted' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Cancelled' THEN 4
            ELSE 5
        END,
        b.created_at DESC";

$bookings = $conn->query($bookings_sql);

// ============================================
// QUERY 3: Active broadcasts (searching, not expired)
// ============================================
$active_broadcasts_sql = "
    SELECT 
        jb.*,
        u.full_name as client_name,
        u.phone as client_phone,
        jc.score as candidate_score,
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at) as seconds_remaining,
        CASE 
            WHEN jb.expires_at <= NOW() THEN 'EXPIRED'
            WHEN jb.status = 'searching' THEN 'ACTIVE'
            ELSE jb.status
        END as status_label
    FROM job_broadcasts jb
    JOIN job_candidates jc ON jb.id = jc.broadcast_id
    JOIN users u ON jb.client_id = u.id
    WHERE jc.worker_id = $target_worker_id
        AND jb.status = 'searching'
        AND jb.expires_at > NOW()
    ORDER BY jb.expires_at ASC";

$active_broadcasts = $conn->query($active_broadcasts_sql);

// ============================================
// QUERY 4: What the dashboard SHOULD show
// Using the simplified query from poll_pending.php
// ============================================
// ============================================
// QUERY 4: What the dashboard SHOULD show
// Using the simplified query from poll_pending.php
// ============================================
$dashboard_should_show_sql = "
    SELECT 
        b.id as booking_id,
        b.service_type,
        b.problem_desc,
        b.calculated_fee,
        b.urgency_level,
        b.status as booking_status,
        b.created_at as booking_created,
        u.full_name as client_name,
        u.phone as client_phone,
        jb.id as broadcast_id,
        jb.status as broadcast_status,
        jb.expires_at,
        TIMESTAMPDIFF(SECOND, NOW(), jb.expires_at) as seconds_remaining,
        CASE 
            WHEN jb.id IS NOT NULL AND jb.expires_at > NOW() AND jb.status = 'searching' THEN '✅ SHOULD SHOW (Active Quick Match)'
            WHEN jb.id IS NOT NULL AND jb.expires_at <= NOW() THEN '⏰ EXPIRED - Should be hidden'
            WHEN jb.id IS NOT NULL AND jb.status != 'searching' THEN '❌ INACTIVE - Should be hidden'
            ELSE '📝 Regular Booking'
        END as dashboard_status
    FROM bookings b
    JOIN users u ON b.client_id = u.id
    LEFT JOIN job_broadcasts jb ON b.broadcast_id = jb.id
    WHERE b.worker_id = $target_worker_id
        AND b.status = 'Pending'
    ORDER BY 
        CASE 
            WHEN jb.id IS NOT NULL AND jb.expires_at > NOW() AND jb.status = 'searching' THEN 0
            ELSE 1
        END,
        CASE 
            WHEN jb.expires_at IS NULL THEN 1
            ELSE 0
        END,
        jb.expires_at ASC,
        b.created_at DESC";

$dashboard_should_show = $conn->query($dashboard_should_show_sql);

// ============================================
// QUERY 5: Count statistics
// ============================================
$stats_sql = "
    SELECT
        (SELECT COUNT(*) FROM job_candidates WHERE worker_id = $target_worker_id) as total_broadcasts,
        (SELECT COUNT(*) FROM job_broadcasts jb 
         JOIN job_candidates jc ON jb.id = jc.broadcast_id 
         WHERE jc.worker_id = $target_worker_id AND jb.status = 'searching' AND jb.expires_at > NOW()) as active_broadcasts,
        (SELECT COUNT(*) FROM bookings WHERE worker_id = $target_worker_id) as total_bookings,
        (SELECT COUNT(*) FROM bookings WHERE worker_id = $target_worker_id AND status = 'Pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings WHERE worker_id = $target_worker_id AND status = 'Pending' AND broadcast_id IS NOT NULL) as pending_quick_match_bookings";

$stats = $conn->query($stats_sql)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broadcast Diagnostic Tool - Worker ID=8</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; }
        .diagnostic-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 24px; overflow: hidden; }
        .diagnostic-header { padding: 16px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .diagnostic-content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 12px 8px; background: #f3f4f6; font-weight: 600; }
        td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr:hover { background: #f9fafb; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fed7aa; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-gray { background: #f3f4f6; color: #374151; }
        .status-box { padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 12px; }
        .status-searching { background: #dbeafe; color: #1e40af; }
        .status-accepted { background: #d1fae5; color: #065f46; }
        .status-expired { background: #f3f4f6; color: #6b7280; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .timestamp { font-family: monospace; font-size: 12px; color: #6b7280; }
        .json-preview { background: #1f2937; color: #e5e7eb; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto; }
        .copy-btn { padding: 4px 8px; background: #e5e7eb; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .copy-btn:hover { background: #d1d5db; }
        .live-timer { font-family: monospace; font-weight: 600; }
        .timer-urgent { color: #dc2626; animation: pulse 1s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
    </style>
</head>
<body class="p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="mb-6 flex justify-between items-start">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🔍 Broadcast Diagnostic Tool</h1>
                <p class="text-gray-600 mt-1">Analyzing all broadcasts and bookings for <strong>Worker ID: 8</strong> - <?php echo htmlspecialchars($worker_info['full_name'] ?? 'Unknown'); ?></p>
            </div>
            <div class="flex gap-2">
                <a href="../worker/dashboard.php" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition">← Back to Dashboard</a>
                <button onclick="location.reload()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">⟳ Refresh Data</button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Total Broadcasts</div>
                <div class="text-2xl font-bold"><?php echo $stats['total_broadcasts'] ?? 0; ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
                <div class="text-sm text-gray-500">Active Broadcasts</div>
                <div class="text-2xl font-bold text-green-600"><?php echo $stats['active_broadcasts'] ?? 0; ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Total Bookings</div>
                <div class="text-2xl font-bold"><?php echo $stats['total_bookings'] ?? 0; ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500">
                <div class="text-sm text-gray-500">Pending Bookings</div>
                <div class="text-2xl font-bold text-yellow-600"><?php echo $stats['pending_bookings'] ?? 0; ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
                <div class="text-sm text-gray-500">Pending Quick Match</div>
                <div class="text-2xl font-bold text-blue-600"><?php echo $stats['pending_quick_match_bookings'] ?? 0; ?></div>
            </div>
        </div>

        <!-- ACTIVE BROADCASTS (Should be visible in dashboard) -->
        <div class="diagnostic-card">
            <div class="diagnostic-header">
                <span>🚀 ACTIVE QUICK MATCH BROADCASTS (Should appear in dashboard)</span>
                <span class="badge badge-success"><?php echo $active_broadcasts->num_rows; ?> active</span>
            </div>
            <div class="diagnostic-content">
                <?php if ($active_broadcasts->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Broadcast ID</th>
                                <th>Client</th>
                                <th>Category</th>
                                <th>Urgency</th>
                                <th>Status</th>
                                <th>Expires In</th>
                                <th>Created</th>
                                <th>Score</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($b = $active_broadcasts->fetch_assoc()): 
                                $seconds = (int)$b['seconds_remaining'];
                                $minutes = floor($seconds / 60);
                                $secs = $seconds % 60;
                                $is_urgent = $minutes < 5;
                            ?>
                            <tr>
                                <td><strong>#<?php echo $b['id']; ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($b['client_name']); ?><br>
                                    <span class="text-xs text-gray-500"><?php echo $b['client_phone']; ?></span>
                                </td>
                                <td><?php echo $b['category']; ?></td>
                                <td>
                                    <span class="badge <?php echo $b['urgency'] == 'Emergency' ? 'badge-danger' : ($b['urgency'] == 'High' ? 'badge-warning' : 'badge-info'); ?>">
                                        <?php echo $b['urgency']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-box status-searching">SEARCHING</span>
                                </td>
                                <td>
                                    <span class="live-timer <?php echo $is_urgent ? 'timer-urgent' : ''; ?>" 
                                          data-expires="<?php echo strtotime($b['expires_at']); ?>">
                                        <?php echo sprintf("%d:%02d", $minutes, $secs); ?>
                                    </span>
                                </td>
                                <td class="timestamp"><?php echo date('M d, H:i', strtotime($b['created_at'])); ?></td>
                                <td><?php echo $b['candidate_score']; ?></td>
                                <td>
                                    <button onclick="testBroadcast(<?php echo $b['id']; ?>)" 
                                            class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200">
                                        Test View
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        No active broadcasts found. This explains why nothing appears in the dashboard!
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- WHAT DASHBOARD SHOULD SHOW -->
        <div class="diagnostic-card">
            <div class="diagnostic-header">
                <span>📋 WHAT THE DASHBOARD *SHOULD* SHOW (Based on correct query)</span>
                <span class="badge badge-info"><?php echo $dashboard_should_show->num_rows; ?> pending bookings</span>
            </div>
            <div class="diagnostic-content">
                <?php if ($dashboard_should_show->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Dashboard Status</th>
                                <th>Broadcast</th>
                                <th>Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($b = $dashboard_should_show->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?php echo $b['booking_id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($b['client_name']); ?></td>
                                <td><?php echo $b['service_type'] ?: 'N/A'; ?></td>
                                <td>
                                    <span class="badge badge-warning"><?php echo $b['booking_status']; ?></span>
                                </td>
                                <td>
                                    <span class="text-sm <?php echo strpos($b['dashboard_status'], '✅') !== false ? 'text-green-600 font-bold' : 'text-gray-500'; ?>">
                                        <?php echo $b['dashboard_status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($b['broadcast_id']): ?>
                                        #<?php echo $b['broadcast_id']; ?> 
                                        <span class="status-box status-<?php echo $b['broadcast_status']; ?>">
                                            <?php echo $b['broadcast_status']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="timestamp">
                                    <?php if ($b['expires_at']): ?>
                                        <?php echo date('H:i:s', strtotime($b['expires_at'])); ?>
                                        (<?php echo $b['seconds_remaining']; ?>s)
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        No pending bookings found for worker #8.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ALL BROADCASTS (Complete history) -->
        <div class="diagnostic-card">
            <div class="diagnostic-header">
                <span>📊 ALL BROADCASTS FOR WORKER #8 (via job_candidates)</span>
                <span class="badge badge-gray"><?php echo $broadcasts->num_rows; ?> total</span>
            </div>
            <div class="diagnostic-content">
                <?php if ($broadcasts->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Category</th>
                                <th>Urgency</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th>Created</th>
                                <th>Score</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($b = $broadcasts->fetch_assoc()): 
                                $status_class = 'status-' . $b['status'];
                            ?>
                            <tr>
                                <td><strong>#<?php echo $b['id']; ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($b['client_name']); ?><br>
                                    <span class="text-xs text-gray-500">ID: <?php echo $b['client_id']; ?></span>
                                </td>
                                <td><?php echo $b['category']; ?></td>
                                <td>
                                    <span class="badge <?php echo $b['urgency'] == 'Emergency' ? 'badge-danger' : ($b['urgency'] == 'High' ? 'badge-warning' : 'badge-info'); ?>">
                                        <?php echo $b['urgency']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-box <?php echo $status_class; ?>">
                                        <?php echo strtoupper($b['status']); ?>
                                    </span>
                                </td>
                                <td class="timestamp">
                                    <?php echo date('M d, H:i', strtotime($b['expires_at'])); ?><br>
                                    <span class="text-xs"><?php echo $b['seconds_remaining']; ?>s left</span>
                                </td>
                                <td class="timestamp"><?php echo date('M d, H:i', strtotime($b['created_at'])); ?></td>
                                <td><?php echo $b['candidate_score']; ?></td>
                                <td class="text-xs">
                                    <?php if ($b['latitude']): ?>
                                        <span title="<?php echo $b['location_address']; ?>">
                                            <?php echo substr($b['latitude'], 0, 8); ?>°, <?php echo substr($b['longitude'], 0, 8); ?>°
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">No location</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        No broadcast records found for worker #8 in job_candidates table.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ALL BOOKINGS (Complete history) -->
        <div class="diagnostic-card">
            <div class="diagnostic-header">
                <span>📋 ALL BOOKINGS FOR WORKER #8</span>
                <span class="badge badge-gray"><?php echo $bookings->num_rows; ?> total</span>
            </div>
            <div class="diagnostic-content">
                <?php if ($bookings->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Fee</th>
                                <th>Status</th>
                                <th>Broadcast</th>
                                <th>Created</th>
                                <th>Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($b = $bookings->fetch_assoc()): 
                                $status_class = 'badge-' . ($b['status'] == 'Pending' ? 'warning' : ($b['status'] == 'Accepted' ? 'success' : ($b['status'] == 'Completed' ? 'info' : 'gray')));
                            ?>
                            <tr>
                                <td><strong>#<?php echo $b['id']; ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($b['client_name']); ?><br>
                                    <span class="text-xs text-gray-500"><?php echo $b['client_phone']; ?></span>
                                </td>
                                <td><?php echo $b['service_type'] ?: 'N/A'; ?></td>
                                <td>₱<?php echo number_format($b['calculated_fee'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <?php echo $b['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($b['broadcast_id']): ?>
                                        #<?php echo $b['broadcast_id']; ?> 
                                        <span class="status-box status-<?php echo $b['broadcast_status']; ?>">
                                            <?php echo $b['broadcast_status']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">Regular booking</span>
                                    <?php endif; ?>
                                </td>
                                <td class="timestamp"><?php echo date('M d, H:i', strtotime($b['created_at'])); ?></td>
                                <td>
                                    <?php echo $b['payment_method']; ?><br>
                                    <span class="text-xs"><?php echo $b['payment_status']; ?></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        No bookings found for worker #8.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RAW DATA DEBUG -->
        <div class="diagnostic-card">
            <div class="diagnostic-header">
                <span>🔧 RAW DATA DEBUG - Broadcast #84 (Most Recent)</span>
                <button onclick="copyDebugInfo()" class="copy-btn">📋 Copy Debug Info</button>
            </div>
            <div class="diagnostic-content">
                <?php
                // Get raw broadcast #84 data
                $debug_broadcast = $conn->query("SELECT * FROM job_broadcasts WHERE id = 84")->fetch_assoc();
                $debug_bookings = $conn->query("SELECT * FROM bookings WHERE broadcast_id = 84");
                ?>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="font-bold mb-2">Broadcast #84 Data:</h3>
                        <div class="json-preview" id="broadcast-debug">
                            <?php 
                            echo json_encode($debug_broadcast, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            ?>
                        </div>
                    </div>
                    <div>
                        <h3 class="font-bold mb-2">Associated Bookings:</h3>
                        <div class="json-preview" id="bookings-debug">
                            <?php 
                            $bookings_array = [];
                            while($b = $debug_bookings->fetch_assoc()) {
                                $bookings_array[] = $b;
                            }
                            echo json_encode($bookings_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-4 bg-yellow-50 rounded-lg">
                    <h4 class="font-bold text-yellow-800">🔍 Diagnostic Conclusion:</h4>
                    <?php
                    $broadcast = $debug_broadcast;
                    $booking_exists = !empty($bookings_array);
                    $booking_for_worker8 = false;
                    
                    foreach($bookings_array as $booking) {
                        if($booking['worker_id'] == 8) {
                            $booking_for_worker8 = true;
                            break;
                        }
                    }
                    
                    $issues = [];
                    if (!$broadcast) $issues[] = "Broadcast #84 doesn't exist";
                    if ($broadcast && $broadcast['status'] != 'searching') $issues[] = "Broadcast status is '{$broadcast['status']}' not 'searching'";
                    if ($broadcast && strtotime($broadcast['expires_at']) <= time()) $issues[] = "Broadcast has expired";
                    if (!$booking_exists) $issues[] = "No bookings linked to this broadcast";
                    if ($booking_exists && !$booking_for_worker8) $issues[] = "No booking for worker #8";
                    
                    if (empty($issues)):
                    ?>
                        <p class="text-green-700">✅ All data looks correct! Broadcast #84 should appear in dashboard.</p>
                        <p class="text-green-600 mt-2">This confirms the issue is in the dashboard query logic, not the data.</p>
                    <?php else: ?>
                        <p class="text-red-700">❌ Issues detected:</p>
                        <ul class="list-disc list-inside text-red-600 mt-1">
                            <?php foreach($issues as $issue): ?>
                                <li><?php echo $issue; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Live timer updates
        function updateTimers() {
            document.querySelectorAll('.live-timer').forEach(el => {
                const expires = parseInt(el.dataset.expires) * 1000;
                const now = new Date().getTime();
                const diff = Math.floor((expires - now) / 1000);
                
                if (diff <= 0) {
                    el.textContent = 'EXPIRED';
                    el.classList.add('text-red-600', 'font-bold');
                    return;
                }
                
                const minutes = Math.floor(diff / 60);
                const seconds = diff % 60;
                el.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (minutes < 5) {
                    el.classList.add('timer-urgent');
                } else {
                    el.classList.remove('timer-urgent');
                }
            });
        }
        
        setInterval(updateTimers, 1000);
        
        function testBroadcast(broadcastId) {
            fetch('test_broadcast_view.php?broadcast_id=' + broadcastId)
                .then(r => r.json())
                .then(data => {
                    alert('Broadcast #' + broadcastId + ' test result:\n' + 
                          'Exists: ' + data.exists + '\n' +
                          'Status: ' + data.status + '\n' +
                          'Should show: ' + data.should_show);
                });
        }
        
        function copyDebugInfo() {
            const broadcast = document.getElementById('broadcast-debug').textContent;
            const bookings = document.getElementById('bookings-debug').textContent;
            const debugText = 'BROADCAST #84:\n' + broadcast + '\n\nBOOKINGS:\n' + bookings;
            
            navigator.clipboard.writeText(debugText).then(() => {
                alert('Debug info copied to clipboard!');
            });
        }
    </script>
</body>
</html>