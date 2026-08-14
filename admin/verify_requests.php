<?php
// admin/verify_requests.php - COMPLETE FIXED VERSION

// ============================================
// 1. INITIALIZATION - SINGLE CONNECTION
// ============================================
include '../db.php';  // ✅ ONE CONNECTION - THIS IS ALL WE NEED

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 2. ADMIN SECURITY CHECK
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ============================================
// 3. HANDLE APPROVE / REJECT ACTIONS
// ============================================
if (isset($_GET['action']) && isset($_GET['req_id'])) {
    // Sanitize inputs
    $req_id = intval($_GET['req_id']);
    $worker_id = isset($_GET['worker_id']) ? intval($_GET['worker_id']) : 0;
    $type = isset($_GET['type']) ? $conn->real_escape_string($_GET['type']) : 'Document';
    $action = $conn->real_escape_string($_GET['action']);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // 1. Update verification request status
        $update_req = $conn->query("UPDATE verification_requests SET status='$action', reviewed_at=NOW(), reviewed_by='{$_SESSION['user_id']}' WHERE id=$req_id");
        if (!$update_req) throw new Exception("Failed to update verification request: " . $conn->error);
        
        // 2. If Approved, update worker's badge and verification status
        // admin/verify_requests.php - UPDATED ACTION HANDLER

if (isset($_POST['approve_request'])) {
    $req_id = intval($_POST['req_id']);
    $worker_id = intval($_POST['worker_id']);
    $badge_choice = $conn->real_escape_string($_POST['badge_type']); // New selection

    $conn->begin_transaction();
    try {
        // 1. Update request status
        $conn->query("UPDATE verification_requests SET status='Approved', reviewed_at=NOW() WHERE id=$req_id");

        // 2. Update worker profile with the SPECIFIC badge
        $is_tesda = ($badge_choice == 'Gold') ? 1 : 0;
        $conn->query("UPDATE worker_profiles SET 
                      verification_status='$badge_choice', 
                      is_tesda_verified=$is_tesda, 
                      is_verified=1 
                      WHERE user_id=$worker_id");

        $conn->commit();
        echo "<script>alert('Worker verified as $badge_choice!'); window.location.href='verify_requests.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        die("Error: " . $e->getMessage());
    }
}
        
        // ============================================
        // 4. ✅ FIXED: SEND NOTIFICATION TO WORKER
        // ============================================
        $notif_msg = "";
        $notif_link = "../worker/verification_status.php"; // Better link
        
        if ($action == 'Approved') {
            $notif_msg = "🎉 Congratulations! Your $type Verification has been APPROVED. You now have a verified badge!";
        } else {
            $notif_msg = "⚠️ Your $type Verification request was REJECTED. Please resubmit with valid documents.";
            $notif_link = "../worker/verify.php"; // Send them to resubmit
        }
        
        // ✅ DIRECT INSERT - USING THE SAME $conn CONNECTION
        $safe_msg = $conn->real_escape_string($notif_msg);
        $safe_link = $conn->real_escape_string($notif_link);
        $timestamp = date('Y-m-d H:i:s');
        
        $notif_sql = "INSERT INTO notifications (user_id, message, link, created_at, is_read) 
                      VALUES ('$worker_id', '$safe_msg', '$safe_link', '$timestamp', 0)";
        
        $notif_result = $conn->query($notif_sql);
        
        if (!$notif_result) {
            error_log("❌ Admin Notification Failed: " . $conn->error);
            throw new Exception("Failed to send notification: " . $conn->error);
        } else {
            error_log("✅ Admin Notification Sent to Worker #$worker_id: $action");
        }
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "✅ Verification request " . strtolower($action) . " successfully! Notification sent to worker.";
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "❌ Error: " . $e->getMessage();
        error_log("❌ Verification processing failed: " . $e->getMessage());
    }
    
    // Redirect with message
    echo "<script>
            alert('" . addslashes($success_message ?? $error_message ?? 'Request processed!') . "');
            window.location.href = 'verify_requests.php';
          </script>";
    exit();
}

// ============================================
// 5. FETCH PENDING REQUESTS WITH PROPER JOINS
// ============================================
$sql = "SELECT 
            vr.*, 
            u.full_name, 
            u.email,
            u.profile_pic,
            wp.service_category,
            wp.average_rating,
            wp.jobs_completed,
            DATEDIFF(NOW(), vr.uploaded_at) as days_ago
        FROM verification_requests vr
        JOIN users u ON vr.worker_id = u.id
        LEFT JOIN worker_profiles wp ON vr.worker_id = wp.user_id
        WHERE vr.status = 'Pending'
        ORDER BY vr.uploaded_at ASC"; // Oldest first

$result = $conn->query($sql);

// Get counts for dashboard
$stats_sql = "SELECT 
                (SELECT COUNT(*) FROM verification_requests WHERE status = 'Pending') as pending_count,
                (SELECT COUNT(*) FROM verification_requests WHERE status = 'Approved' AND uploaded_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) as approved_30d,
                (SELECT COUNT(*) FROM verification_requests WHERE status = 'Rejected' AND uploaded_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) as rejected_30d,
                (SELECT AVG(DATEDIFF(NOW(), uploaded_at)) FROM verification_requests WHERE status = 'Pending') as avg_wait_time";
$stats = $conn->query($stats_sql)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Verification Queue | Abilisto</title>
    
    <!-- Font Awesome 7 (local) -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">

    <!-- Google Fonts - Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ===== FUTURISTIC COLOR SCHEME ===== */
        :root {
            --primary: #146af5;
            --primary-dark: #0f52c2;
            --primary-light: #6ea6f9;
            --primary-gradient: linear-gradient(135deg, #146af5 0%, #0f52c2 100%);
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --dark: #0f172a;
            --dark-2: #1e293b;
            --dark-3: #334155;
            --light: #f8fafc;
            --light-2: #f1f5f9;
            --glass: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --shadow-inner: inset 0 2px 4px 0 rgba(0, 0, 0, 0.06);
            --border-radius: 16px;
            --border-radius-sm: 8px;
            --border-radius-lg: 24px;
            --border-radius-xl: 32px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
        }

        /* ===== MODERN SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* ===== GLASS MORPHISM ===== */
        .glass-card {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-xl);
        }

        /* ===== ADMIN CONTAINER ===== */
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        /* ===== HEADER SECTION ===== */
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .admin-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .admin-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            letter-spacing: -0.5px;
            margin: 0;
        }

        .admin-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-md);
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 12px 24px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.5);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            color: var(--dark);
        }

        .stat-info p {
            margin: 5px 0 0;
            color: var(--dark-3);
            font-weight: 500;
        }

        /* ===== VERIFICATION CARDS GRID ===== */
        .requests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .request-card {
            background: white;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255,255,255,0.1);
            position: relative;
        }

        .request-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 40px -15px rgba(0,0,0,0.3);
        }

        .request-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--primary-gradient);
        }

        /* ===== DOCUMENT PREVIEW ===== */
        .document-preview {
            width: 100%;
            height: 200px;
            background: var(--dark);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-bottom: 3px solid var(--primary);
        }

        .document-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #1a1a1a;
            transition: transform 0.5s ease;
        }

        .document-img:hover {
            transform: scale(1.05);
        }

        .document-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .document-placeholder i {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .document-overlay {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255,255,255,0.3);
            z-index: 2;
        }

        .document-zoom {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s ease;
            z-index: 2;
        }

        .document-zoom:hover {
            background: var(--primary);
            transform: scale(1.1);
        }

        /* ===== WORKER INFO ===== */
        .worker-info {
            padding: 20px;
        }

        .worker-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .worker-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
        }

        .worker-details h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .worker-details p {
            margin: 5px 0 0;
            color: var(--dark-3);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--light-2);
            color: var(--dark-2);
        }

        .badge-tesda {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #000;
        }

        .badge-community {
            background: linear-gradient(135deg, var(--info), var(--primary-dark));
            color: white;
        }

        .meta-info {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid var(--light-2);
            border-bottom: 1px solid var(--light-2);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: var(--dark-3);
        }

        /* ===== ACTION BUTTONS ===== */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: var(--border-radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-approve {
            background: var(--success);
            color: white;
        }

        .btn-approve:hover {
            background: #0ea271;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
        }

        .btn-reject {
            background: var(--danger);
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);
        }

        .btn-view {
            background: var(--light-2);
            color: var(--dark);
            border: 1px solid var(--dark-3);
        }

        .btn-view:hover {
            background: var(--dark-3);
            color: white;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--primary-light);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--dark);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--dark-3);
            max-width: 400px;
            margin: 0 auto;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .request-card {
            animation: fadeInUp 0.6s ease forwards;
        }

        .request-card:nth-child(1) { animation-delay: 0.1s; }
        .request-card:nth-child(2) { animation-delay: 0.2s; }
        .request-card:nth-child(3) { animation-delay: 0.3s; }
        .request-card:nth-child(4) { animation-delay: 0.4s; }
        .request-card:nth-child(5) { animation-delay: 0.5s; }
        .request-card:nth-child(6) { animation-delay: 0.6s; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .requests-grid {
                grid-template-columns: 1fr;
            }
            
            .document-preview {
                height: 180px;
            }
        }

        /* ===== LOADING SPINNER ===== */
        .loading {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ===== IMAGE MODAL ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            padding-top: 50px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0,0,0,0.95);
            backdrop-filter: blur(10px);
        }

        .modal-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
            box-shadow: var(--shadow-xl);
        }

        .close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }

        .close:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="admin-container">
        
        <!-- Header Section -->
        <div class="admin-header">
            <div class="admin-title">
                <div style="background: rgba(255,255,255,0.2); padding: 15px; border-radius: 50%; backdrop-filter: blur(10px);">
                    <i class="fas fa-shield-alt" style="font-size: 2rem; color: white;"></i>
                </div>
                <div>
                    <h1>Verification Queue</h1>
                    <span class="admin-badge">
                        <i class="fas fa-bolt"></i> Admin Access • <?php echo date('F d, Y'); ?>
                    </span>
                </div>
            </div>
            
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
                <i class="fas fa-chart-line"></i>
            </a>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--info-light); color: var(--info);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_count'] ?? 0; ?></h3>
                    <p>Pending Requests</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--success-light); color: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['approved_30d'] ?? 0; ?></h3>
                    <p>Approved (30d)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--danger-light); color: var(--danger);">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['rejected_30d'] ?? 0; ?></h3>
                    <p>Rejected (30d)</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--warning-light); color: var(--warning);">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo round($stats['avg_wait_time'] ?? 0); ?>d</h3>
                    <p>Avg. Wait Time</p>
                </div>
            </div>
        </div>
        
        <!-- Verification Requests Grid -->
        <?php if ($result && $result->num_rows > 0): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: white; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-id-card"></i> 
                    Worker Verification Requests
                    <span style="background: var(--danger); color: white; padding: 5px 12px; border-radius: 30px; font-size: 0.9rem;">
                        <?php echo $result->num_rows; ?> pending
                    </span>
                </h2>
                
                <div style="display: flex; gap: 10px;">
                    <select id="filterType" style="padding: 10px 20px; border-radius: 30px; border: none; background: rgba(255,255,255,0.9); backdrop-filter: blur(10px); font-family: 'Plus Jakarta Sans', sans-serif;">
                        <option value="all">📄 All Documents</option>
                        <option value="TESDA">🎓 TESDA Certificate</option>
                        <option value="Valid ID">🪪 Valid ID</option>
                        <option value="NC I">⚙️ NC I</option>
                        <option value="NC II">🔧 NC II</option>
                        <option value="NC III">🛠️ NC III</option>
                    </select>
                </div>
            </div>
            
            <div class="requests-grid" id="requestsGrid">
                <?php while($row = $result->fetch_assoc()): 
                    $file_path = "../uploads/docs/" . $row['file_path'];
                    $file_ext = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                ?>
                    <div class="request-card" data-type="<?php echo $row['document_type']; ?>">
                        <!-- Document Preview Section -->
                        <div class="document-preview">
                            <?php if ($is_image && file_exists($file_path)): ?>
                                <img src="<?php echo $file_path; ?>" alt="Document" class="document-img" onclick="openModal(this.src)">
                                <span class="document-overlay">
                                    <i class="fas fa-image"></i> <?php echo strtoupper($file_ext); ?>
                                </span>
                                <a href="#" onclick="openModal('<?php echo $file_path; ?>'); return false;" class="document-zoom">
                                    <i class="fas fa-search-plus"></i>
                                </a>
                            <?php else: ?>
                                <div class="document-placeholder">
                                    <i class="fas fa-file-pdf"></i>
                                    <span style="margin-top: 10px; font-size: 0.9rem;">PDF Document</span>
                                </div>
                                <span class="document-overlay">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </span>
                                <a href="<?php echo $file_path; ?>" target="_blank" class="document-zoom">
                                    <i class="fas fa-download"></i>
                                </a>
                            <?php endif; ?>
                            
                            <!-- Document Type Badge -->
                            <div style="position: absolute; top: 10px; left: 10px; z-index: 2;">
                                <span class="badge <?php echo $row['document_type'] == 'TESDA' ? 'badge-tesda' : 'badge-community'; ?>">
                                    <?php if ($row['document_type'] == 'TESDA'): ?>
                                        <i class="fas fa-medal"></i>
                                    <?php elseif ($row['document_type'] == 'Valid ID'): ?>
                                        <i class="fas fa-id-card"></i>
                                    <?php else: ?>
                                        <i class="fas fa-certificate"></i>
                                    <?php endif; ?>
                                    <?php echo $row['document_type']; ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Worker Info Section -->
                        <div class="worker-info">
                            <div class="worker-header">
                                <?php if (!empty($row['profile_pic']) && file_exists("../uploads/profile/" . $row['profile_pic'])): ?>
                                    <img src="../uploads/profile/<?php echo $row['profile_pic']; ?>" class="worker-avatar">
                                <?php else: ?>
                                    <div class="worker-avatar" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); display: flex; align-items: center; justify-content: center; color: white;">
                                        <?php echo strtoupper(substr($row['full_name'], 0, 2)); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="worker-details">
                                    <h3><?php echo htmlspecialchars($row['full_name']); ?></h3>
                                    <p>
                                        <i class="fas fa-envelope"></i> 
                                        <?php echo htmlspecialchars($row['email']); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="meta-info">
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt"></i> 
                                    Uploaded: <?php echo date('M d, Y', strtotime($row['uploaded_at'])); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-hourglass-half"></i> 
                                    <?php echo $row['days_ago']; ?> days ago
                                </div>
                                <?php if (!empty($row['service_category'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-briefcase"></i> 
                                    <?php echo $row['service_category']; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($row['jobs_completed'] > 0): ?>
                                <div class="meta-item">
                                    <i class="fas fa-check-circle"></i> 
                                    <?php echo $row['jobs_completed']; ?> jobs
                                </div>
                                <?php endif; ?>
                                <?php if ($row['average_rating'] > 0): ?>
                                <div class="meta-item">
                                    <i class="fas fa-star" style="color: #FFD700;"></i> 
                                    <?php echo number_format($row['average_rating'], 1); ?> ★
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <form method="POST" style="display:inline;">
    <input type="hidden" name="req_id" value="<?php echo $row['id']; ?>">
    <input type="hidden" name="worker_id" value="<?php echo $row['worker_id']; ?>">
    
    <select name="badge_type" required>
        <option value="Bronze">Bronze (Basic)</option>
        <option value="Silver">Silver (Intermediate)</option>
        <option value="Gold">Gold (NC III/Master)</option>
        <option value="Community">Community Verified</option>
    </select>
    
    <button type="submit" name="approve_request" class="btn-approve">Verify</button>
</form>
                            
                            <div style="margin-top: 15px; text-align: center;">
                                <a href="mailto:<?php echo $row['email']; ?>" class="btn-view" style="display: inline-flex; padding: 8px 20px;">
                                    <i class="fas fa-envelope"></i> Contact Worker
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="fas fa-check-shield"></i>
                <h3>All Clear! 🎉</h3>
                <p>No pending verification requests at the moment. New worker submissions will appear here.</p>
                <div style="margin-top: 30px;">
                    <a href="dashboard.php" style="display: inline-flex; align-items: center; gap: 10px; padding: 12px 30px; background: var(--primary-gradient); color: white; text-decoration: none; border-radius: 30px; font-weight: 600;">
                        <i class="fas fa-tachometer-alt"></i> Return to Dashboard
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Image Modal -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <span class="close" onclick="closeModal()">&times;</span>
        <img class="modal-content" id="modalImage">
    </div>
    
    <script>
        // ===== IMAGE MODAL FUNCTION =====
        function openModal(src) {
            document.getElementById('imageModal').style.display = 'block';
            document.getElementById('modalImage').src = src;
        }
        
        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // ===== FILTER REQUESTS BY DOCUMENT TYPE =====
        document.getElementById('filterType').addEventListener('change', function() {
            const type = this.value;
            const cards = document.querySelectorAll('.request-card');
            
            cards.forEach(card => {
                if (type === 'all' || card.dataset.type === type) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // ===== AUTO-REFRESH NOTIFICATION (Optional) =====
        // Check for new requests every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000); // 30 seconds
    </script>
</body>
</html>