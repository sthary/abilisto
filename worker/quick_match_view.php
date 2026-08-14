<?php
// worker/quick_match_view.php
require_once '../db.php';

// Check if user is logged in as worker
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php?error=Please login as worker");
    exit();
}

$worker_id = intval($_SESSION['user_id']);
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

if ($session_id === 0) {
    header("Location: dashboard.php?error=Invalid session ID");
    exit();
}

// First, let's check if the broadcast exists
$checkQuery = "
    SELECT 
        qmb.*, 
        qms.*, 
        u.full_name as client_name, 
        u.phone, 
        u.address, 
        u.municipality,
        TIMESTAMPDIFF(MINUTE, NOW(), qms.expires_at) as minutes_remaining
    FROM quick_match_broadcasts qmb
    JOIN quick_match_sessions qms ON qmb.session_id = qms.id
    JOIN users u ON qms.client_id = u.id
    WHERE qmb.session_id = ? 
    AND qmb.worker_id = ?
";

$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("ii", $session_id, $worker_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Debug: Let's see what broadcasts exist for this worker
    $debugQuery = "SELECT * FROM quick_match_broadcasts WHERE worker_id = ? ORDER BY id DESC LIMIT 5";
    $debugStmt = $conn->prepare($debugQuery);
    $debugStmt->bind_param("i", $worker_id);
    $debugStmt->execute();
    $debugResult = $debugStmt->get_result();
    
    $debugBroadcasts = [];
    while ($row = $debugResult->fetch_assoc()) {
        $debugBroadcasts[] = $row;
    }
    
    echo "<pre>";
    echo "No broadcast found for session_id: $session_id and worker_id: $worker_id\n\n";
    echo "Recent broadcasts for this worker:\n";
    print_r($debugBroadcasts);
    echo "</pre>";
    exit();
}

$broadcast = $result->fetch_assoc();

// Check if expired
if (strtotime($broadcast['expires_at']) < time()) {
    header("Location: dashboard.php?error=This request has expired");
    exit();
}

// Mark as viewed
$updateStmt = $conn->prepare("UPDATE quick_match_broadcasts SET viewed_at = NOW() WHERE id = ? AND viewed_at IS NULL");
$updateStmt->bind_param("i", $broadcast['id']);
$updateStmt->execute();

// Get urgency level class and icon
$urgency_class = strtolower($broadcast['urgency_level']);
$urgency_icon = '';
$urgency_text = '';

switch($broadcast['urgency_level']) {
    case 'Emergency':
        $urgency_icon = '🚨';
        $urgency_text = 'EMERGENCY';
        break;
    case 'Urgent':
        $urgency_icon = '⚡';
        $urgency_text = 'URGENT';
        break;
    default:
        $urgency_icon = '📋';
        $urgency_text = 'New Request';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Match Request | Abilisto</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #1e293b;
        }

        .main-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        .request-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .request-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .urgency-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .urgency-badge.emergency {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .urgency-badge.urgent {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .urgency-badge.normal {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1.5rem;
        }

        .details {
            background: #f9fafb;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-item i {
            width: 20px;
            color: #667eea;
            font-size: 1.1rem;
            margin-top: 0.2rem;
        }

        .detail-item span {
            flex: 1;
            color: #4b5563;
            line-height: 1.5;
        }

        .detail-item strong {
            color: #1f2937;
            font-weight: 600;
        }

        .timer {
            background: #f3f4f6;
            padding: 0.75rem;
            border-radius: 12px;
            margin-top: 0.5rem;
        }

        .timer i {
            color: #f59e0b;
        }

        .terms-notice {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .terms-notice i {
            color: #92400e;
            font-size: 1.25rem;
        }

        .terms-notice p {
            color: #92400e;
            font-size: 0.9rem;
            line-height: 1.5;
            flex: 1;
        }

        .actions {
            display: flex;
            gap: 1rem;
        }

        .btn {
            flex: 1;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-accept {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-accept:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(16,185,129,0.3);
        }

        .btn-decline {
            background: white;
            color: #ef4444;
            border: 2px solid #ef4444;
        }

        .btn-decline:hover {
            background: #fef2f2;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .expired-message {
            text-align: center;
            padding: 2rem;
        }

        .expired-message i {
            font-size: 4rem;
            color: #9ca3af;
            margin-bottom: 1rem;
        }

        .expired-message h2 {
            color: #4b5563;
            margin-bottom: 0.5rem;
        }

        .expired-message p {
            color: #6b7280;
            margin-bottom: 1.5rem;
        }

        .btn-back {
            background: #667eea;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 12px;
            text-decoration: none;
            display: inline-block;
        }

        @media (max-width: 640px) {
            .actions {
                flex-direction: column;
            }
            
            .request-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <div class="container">
            
            <div class="request-card">
                
                <div class="urgency-badge <?php echo $urgency_class; ?>">
                    <i class="fas <?php 
                        echo $urgency_class === 'emergency' ? 'fa-exclamation-triangle' : 
                            ($urgency_class === 'urgent' ? 'fa-clock' : 'fa-bell'); 
                    ?>"></i>
                    <?php echo $urgency_icon . ' ' . $urgency_text; ?>
                </div>
                
                <h1>Quick Match Request</h1>
                
                <div class="details">
                    <div class="detail-item">
                        <i class="fas fa-wrench"></i>
                        <span>
                            <strong>Service Needed:</strong><br>
                            <?php echo htmlspecialchars($broadcast['service_category']); ?>
                        </span>
                    </div>
                    
                    <div class="detail-item">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>
                            <strong>Problem:</strong><br>
                            <?php echo htmlspecialchars($broadcast['problem_desc']); ?>
                        </span>
                    </div>
                    
                    <div class="detail-item">
                        <i class="fas fa-user"></i>
                        <span>
                            <strong>Client:</strong><br>
                            <?php echo htmlspecialchars($broadcast['client_name']); ?>
                        </span>
                    </div>
                    
                    <div class="detail-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>
                            <strong>Location:</strong><br>
                            <?php 
                                $location = trim($broadcast['address'] . ', ' . $broadcast['municipality'], ', ');
                                echo htmlspecialchars($location ?: 'Location not specified'); 
                            ?>
                        </span>
                    </div>
                    
                    <div class="detail-item">
                        <i class="fas fa-phone"></i>
                        <span>
                            <strong>Contact Number:</strong><br>
                            <?php echo htmlspecialchars($broadcast['phone'] ?: 'Not provided'); ?>
                        </span>
                    </div>
                    
                    <div class="detail-item timer">
                        <i class="far fa-clock"></i>
                        <span>
                            <strong>Time Remaining:</strong><br>
                            <span id="timer"><?php echo max(0, $broadcast['minutes_remaining']); ?> minutes</span>
                        </span>
                    </div>
                </div>
                
                <div class="terms-notice">
                    <i class="fas fa-info-circle"></i>
                    <p>
                        <strong>Important:</strong> By accepting this job, you commit to helping the client immediately. 
                        This is a first-come-first-serve basis. If you accept, you'll be connected directly with the client 
                        and cannot cancel.
                    </p>
                </div>
                
                <div class="actions">
                    <button class="btn btn-decline" onclick="respond('decline')" id="declineBtn">
                        <i class="fas fa-times"></i>
                        Decline
                    </button>
                    <button class="btn btn-accept" onclick="respond('accept')" id="acceptBtn">
                        <i class="fas fa-check"></i>
                        Accept Job
                    </button>
                </div>
                
            </div>

        </div>
    </div>

    <script>
        const sessionId = <?php echo $session_id; ?>;
        const broadcastId = <?php echo $broadcast['id']; ?>;
        let responseSubmitted = false;
        
        function respond(action) {
            // Prevent double submission
            if (responseSubmitted) {
                alert('Response already submitted. Please wait...');
                return;
            }
            
            if (action === 'decline') {
                if (!confirm('Are you sure you want to decline this request? The client will be notified.')) {
                    return;
                }
            } else {
                if (!confirm('Accept this job? You are committing to help immediately. This action cannot be undone.')) {
                    return;
                }
            }
            
            // Disable buttons
            document.getElementById('acceptBtn').disabled = true;
            document.getElementById('declineBtn').disabled = true;
            responseSubmitted = true;
            
            // Show loading state
            const acceptBtn = document.getElementById('acceptBtn');
            const declineBtn = document.getElementById('declineBtn');
            
            if (action === 'accept') {
                acceptBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            } else {
                declineBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            
            // Make the request
            fetch('quick_match_respond.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    broadcast_id: broadcastId,
                    action: action
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw err; });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (action === 'accept') {
                        acceptBtn.innerHTML = '<i class="fas fa-check"></i> Accepted! Redirecting...';
                        setTimeout(() => {
                            window.location.href = `quick_match_accepted.php?session_id=${sessionId}&booking_id=${data.booking_id}`;
                        }, 1000);
                    } else {
                        declineBtn.innerHTML = '<i class="fas fa-check"></i> Declined! Redirecting...';
                        setTimeout(() => {
                            window.location.href = 'dashboard.php?success=declined';
                        }, 1000);
                    }
                } else {
                    alert('Error: ' + (data.message || 'Unknown error occurred'));
                    // Re-enable buttons
                    document.getElementById('acceptBtn').disabled = false;
                    document.getElementById('declineBtn').disabled = false;
                    responseSubmitted = false;
                    
                    if (action === 'accept') {
                        acceptBtn.innerHTML = '<i class="fas fa-check"></i> Accept Job';
                    } else {
                        declineBtn.innerHTML = '<i class="fas fa-times"></i> Decline';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please check console for details.');
                
                // Re-enable buttons
                document.getElementById('acceptBtn').disabled = false;
                document.getElementById('declineBtn').disabled = false;
                responseSubmitted = false;
                
                if (action === 'accept') {
                    acceptBtn.innerHTML = '<i class="fas fa-check"></i> Accept Job';
                } else {
                    declineBtn.innerHTML = '<i class="fas fa-times"></i> Decline';
                }
            });
        }

        // Timer countdown
        function updateTimer() {
            const timerElement = document.getElementById('timer');
            if (!timerElement) return;
            
            const timeText = timerElement.textContent;
            let minutes = parseInt(timeText);
            
            if (minutes <= 1) {
                timerElement.innerHTML = '<span style="color: #dc2626;">Expiring soon...</span>';
                
                if (minutes <= 0) {
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                }
            } else {
                minutes--;
                timerElement.textContent = minutes + ' minutes';
            }
        }

        setInterval(updateTimer, 60000);
    </script>

</body>
</html>