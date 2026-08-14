<?php
// worker/quick_match_jobs.php
session_start();
include '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];

// DATABASE-FIRST APPROACH: Load ALL active jobs from database
$sql = "SELECT 
            b.id as broadcast_id,
            b.category,
            b.problem_tags as problem,
            b.urgency,
            b.expires_at,
            b.client_id,
            u.full_name as client_name,
            u.address,
            bk.id as booking_id,
            COALESCE(bk.calculated_fee, 
                CASE 
                    WHEN b.urgency = 'Emergency' THEN 75
                    WHEN b.urgency = 'High' THEN 60
                    ELSE 50
                END
            ) as fee,
            TIMESTAMPDIFF(SECOND, NOW(), b.expires_at) as seconds_remaining
        FROM job_broadcasts b
        JOIN users u ON b.client_id = u.id
        LEFT JOIN bookings bk ON bk.client_id = b.client_id AND bk.worker_id = '$worker_id'
        WHERE b.status = 'searching' 
            AND b.expires_at > NOW()
            AND JSON_CONTAINS(b.candidate_workers, '$worker_id')
            AND (b.declined_workers IS NULL OR NOT JSON_CONTAINS(b.declined_workers, '$worker_id'))
        ORDER BY 
            CASE b.urgency 
                WHEN 'Emergency' THEN 1 
                WHEN 'High' THEN 2 
                ELSE 3 
            END,
            b.created_at DESC";

$result = $conn->query($sql);
$existing_jobs = [];
while ($row = $result->fetch_assoc()) {
    // Calculate expiry timestamp in milliseconds for JavaScript
    $row['expiry_timestamp'] = strtotime($row['expires_at']) * 1000;
    $existing_jobs[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Match Jobs | Abilisto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
    <style>
        body { 
            background: #f0f2f5; 
            font-family: 'Segoe UI', sans-serif;
            padding: 20px;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
        }
        .header {
            background: white;
            border-radius: 15px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .job-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 5px solid #3b82f6;
            animation: slideIn 0.3s ease;
            position: relative;
            transition: all 0.3s;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .job-card.emergency { border-left-color: #dc3545; background: #fff5f5; }
        .job-card.high { border-left-color: #f59e0b; background: #fff9e6; }
        .job-card.normal { border-left-color: #3b82f6; }
        
        .urgency-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        .emergency .urgency-badge { background: #dc3545; color: white; }
        .high .urgency-badge { background: #f59e0b; color: white; }
        .normal .urgency-badge { background: #6c757d; color: white; }
        
        .timer {
            font-size: 1.5rem;
            font-weight: bold;
            color: #dc3545;
            font-family: monospace;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 8px;
            display: inline-block;
        }
        .timer.warning { 
            color: #dc3545;
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .accept-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            width: 100%;
            transition: all 0.2s;
            font-size: 1.1rem;
        }
        .accept-btn:hover { background: #2563eb; transform: translateY(-2px); }
        .accept-btn:disabled { background: #ccc; cursor: not-allowed; transform: none; }
        .accept-btn.emergency { background: #dc3545; }
        .accept-btn.emergency:hover { background: #c82333; }
        
        .no-jobs {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            color: #6c757d;
        }
        .no-jobs i { font-size: 4rem; margin-bottom: 15px; color: #adb5bd; }
        
        .connection-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        .connected { background: #28a745; box-shadow: 0 0 5px #28a745; animation: blink 2s infinite; }
        .disconnected { background: #dc3545; }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .badge-info {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-left: 5px;
        }
        
        .location-icon {
            color: #6c757d;
            margin-right: 5px;
        }
        
        .distance-badge {
            background: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h4 class="fw-bold m-0">
                <i class="fa-solid fa-bolt text-primary me-2"></i>Quick Match
                <span class="badge-info" id="jobCount">0 active</span>
            </h4>
            <div>
                <span class="connection-status connected" id="statusLed"></span>
                <span id="statusText">Connected</span>
            </div>
        </div>
        
        <!-- Jobs Container - INITIALLY POPULATED FROM DATABASE -->
        <div id="jobs-container">
            <?php if (empty($existing_jobs)): ?>
                <div class="no-jobs" id="noJobsMessage">
                    <i class="fa-regular fa-clock"></i>
                    <h5>No Active Quick Match Jobs</h5>
                    <p class="text-muted">You'll see emergency and high urgency jobs here in real-time</p>
                    <small class="text-muted">Normal bookings appear in your regular dashboard</small>
                </div>
            <?php else: ?>
                <?php foreach ($existing_jobs as $job): 
                    $urgency_class = strtolower($job['urgency']);
                    $expiry_time = $job['expiry_timestamp'];
                    $seconds = $job['seconds_remaining'];
                    $minutes = floor($seconds / 60);
                    $seconds_remain = $seconds % 60;
                    $initial_time = sprintf("%d:%02d", $minutes, $seconds_remain);
                ?>
                <div class="job-card <?php echo $urgency_class; ?>" id="job-<?php echo $job['broadcast_id']; ?>" data-broadcast-id="<?php echo $job['broadcast_id']; ?>">
                    <span class="urgency-badge"><?php echo $job['urgency']; ?></span>
                    
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-light rounded-circle p-3 me-3">
                            <i class="fa-solid fa-user text-primary fa-2x"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($job['client_name']); ?></h5>
                            <div class="d-flex align-items-center">
                                <i class="fa-solid fa-location-dot location-icon"></i>
                                <small class="text-muted me-2">Nearby</small>
                                <span class="distance-badge">
                                    <i class="fa-regular fa-clock"></i> Just now
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <span class="badge bg-light text-dark p-2 me-2">
                            <i class="fa-solid fa-wrench text-primary"></i> <?php echo $job['category']; ?>
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fa-solid fa-tag text-success"></i> ₱<?php echo number_format($job['fee'], 2); ?>
                        </span>
                    </div>
                    
                    <p class="mb-3">
                        <strong>Problem:</strong> <?php echo htmlspecialchars($job['problem']); ?>
                    </p>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="timer" data-expiry="<?php echo $expiry_time; ?>" id="timer-<?php echo $job['broadcast_id']; ?>">
                            <?php echo $initial_time; ?>
                        </div>
                        <small class="text-muted">Time left to accept</small>
                    </div>
                    
                    <button class="accept-btn <?php echo $urgency_class; ?>" onclick="acceptJob(<?php echo $job['broadcast_id']; ?>, <?php echo $job['booking_id'] ?? 'null'; ?>)" id="btn-<?php echo $job['broadcast_id']; ?>">
                        <i class="fa-solid fa-hand"></i> Accept Job
                    </button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    const workerId = <?php echo $worker_id; ?>;
    const jobsContainer = document.getElementById('jobs-container');
    let activeJobs = new Set();
    let timers = {};
    
    // Initialize active jobs from existing DOM (loaded from database)
    document.querySelectorAll('.job-card').forEach(card => {
        const broadcastId = parseInt(card.dataset.broadcastId);
        activeJobs.add(broadcastId);
        
        // Start timer for this job
        const timerEl = document.getElementById(`timer-${broadcastId}`);
        if (timerEl) {
            const expiry = parseInt(timerEl.dataset.expiry);
            startTimer(broadcastId, expiry);
        }
    });
    
    function updateJobCount() {
        const count = document.querySelectorAll('.job-card').length;
        document.getElementById('jobCount').textContent = count + ' active';
    }
    updateJobCount();
    
    function startTimer(broadcastId, expiryTime) {
        if (timers[broadcastId]) clearInterval(timers[broadcastId]);
        
        timers[broadcastId] = setInterval(() => {
            const timerEl = document.getElementById(`timer-${broadcastId}`);
            if (!timerEl) {
                clearInterval(timers[broadcastId]);
                return;
            }
            
            const now = new Date().getTime();
            const diff = expiryTime - now;
            
            if (diff <= 0) {
                timerEl.textContent = 'Expired';
                timerEl.style.color = '#6c757d';
                const jobCard = document.getElementById(`job-${broadcastId}`);
                if (jobCard) {
                    jobCard.style.opacity = '0.5';
                    const btn = document.getElementById(`btn-${broadcastId}`);
                    if (btn) btn.disabled = true;
                    
                    // Mark as expired in database? (optional)
                    // You could call an API here to update status
                    
                    // Remove after 5 seconds
                    setTimeout(() => {
                        jobCard.remove();
                        activeJobs.delete(broadcastId);
                        updateJobCount();
                        
                        if (document.querySelectorAll('.job-card').length === 0) {
                            jobsContainer.innerHTML = `
                                <div class="no-jobs" id="noJobsMessage">
                                    <i class="fa-regular fa-clock"></i>
                                    <h5>No Active Quick Match Jobs</h5>
                                    <p class="text-muted">Waiting for quick match requests...</p>
                                </div>
                            `;
                        }
                    }, 5000);
                }
                clearInterval(timers[broadcastId]);
            } else {
                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                timerEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                if (diff < 60000) {
                    timerEl.style.color = '#dc3545';
                    timerEl.classList.add('warning');
                }
            }
        }, 1000);
    }
    
    // Socket Connection - ONLY FOR NEW JOBS, NOT FOR LOADING EXISTING ONES
    const socket = io('http://localhost:3001', {
        transports: ['websocket', 'polling'],
        reconnection: true,
        reconnectionAttempts: 10
    });
    
    socket.on('connect', () => {
        console.log('✅ Connected to server - ready for new jobs');
        document.getElementById('statusLed').className = 'connection-status connected';
        document.getElementById('statusText').textContent = 'Connected';
        socket.emit('register_user', workerId);
    });
    
    socket.on('registered', (data) => {
        console.log('✅ Registered as worker:', data);
    });
    
    socket.on('connect_error', (error) => {
        console.error('❌ Connection error:', error);
        document.getElementById('statusLed').className = 'connection-status disconnected';
        document.getElementById('statusText').textContent = 'Disconnected';
    });
    
    // Receive NEW job alert (only for jobs broadcast AFTER page load)
    socket.on('new_job_alert', (data) => {
        console.log('🔔 NEW JOB RECEIVED:', data);
        
        // Don't add if already exists (prevents duplicates from page refresh)
        if (activeJobs.has(data.broadcast_id)) {
            console.log('Job already exists, skipping');
            return;
        }
        activeJobs.add(data.broadcast_id);
        
        // Remove "no jobs" message if it exists
        const noJobs = document.getElementById('noJobsMessage');
        if (noJobs) noJobs.remove();
        
        // Create job card
        addJobCard(data);
        
        // Play notification sound (only for emergency/high)
        if (data.urgency === 'Emergency' || data.urgency === 'High') {
            try {
                const audio = new Audio('https://www.soundjay.com/misc/sounds/bell-ringing-05.mp3');
                audio.play();
            } catch (e) {}
            
            // Show browser notification
            if (Notification.permission === "granted") {
                new Notification("🚨 " + data.urgency + " Quick Match Job!", {
                    body: `${data.client_name} needs ${data.category} help - ₱${data.fee}`,
                    icon: "/favicon.ico",
                    requireInteraction: true
                });
            }
        }
    });
    
    function addJobCard(data) {
        // Check AGAIN if job already exists (double-check)
        if (document.getElementById(`job-${data.broadcast_id}`)) {
            console.log('Job card already exists in DOM');
            return;
        }
        
        const jobCard = document.createElement('div');
        jobCard.className = `job-card ${(data.urgency || 'normal').toLowerCase()}`;
        jobCard.id = `job-${data.broadcast_id}`;
        jobCard.dataset.broadcastId = data.broadcast_id;
        
        // Set expiry time (5 minutes from now)
        const expiryTime = new Date().getTime() + (5 * 60 * 1000);
        
        jobCard.innerHTML = `
            <span class="urgency-badge">${data.urgency || 'Normal'}</span>
            
            <div class="d-flex align-items-center mb-3">
                <div class="bg-light rounded-circle p-3 me-3">
                    <i class="fa-solid fa-user text-primary fa-2x"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-1">${data.client_name || 'Client'}</h5>
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-location-dot location-icon"></i>
                        <small class="text-muted me-2">Nearby</small>
                        <span class="distance-badge">
                            <i class="fa-regular fa-clock"></i> Just now
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <span class="badge bg-light text-dark p-2 me-2">
                    <i class="fa-solid fa-wrench text-primary"></i> ${data.category || 'Service'}
                </span>
                <span class="badge bg-light text-dark p-2">
                    <i class="fa-solid fa-tag text-success"></i> ₱${data.fee || 50}
                </span>
            </div>
            
            <p class="mb-3">
                <strong>Problem:</strong> ${data.problem || 'Not specified'}
            </p>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="timer" data-expiry="${expiryTime}" id="timer-${data.broadcast_id}">
                    5:00
                </div>
                <small class="text-muted">Time left to accept</small>
            </div>
            
            <button class="accept-btn ${(data.urgency || 'normal').toLowerCase()}" onclick="acceptJob(${data.broadcast_id}, null)" id="btn-${data.broadcast_id}">
                <i class="fa-solid fa-hand"></i> Accept Job
            </button>
        `;
        
        // Add to container at the top
        jobsContainer.insertBefore(jobCard, jobsContainer.firstChild);
        startTimer(data.broadcast_id, expiryTime);
        updateJobCount();
    }
    
    // Job taken by another worker
    socket.on('job_taken', (data) => {
        console.log('⚠️ Job taken by another worker:', data);
        const jobElement = document.getElementById(`job-${data.broadcast_id}`);
        if (jobElement) {
            jobElement.style.opacity = '0.5';
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-warning mt-2 mb-0 py-2';
            alertDiv.innerHTML = '<i class="fa-solid fa-info-circle"></i> Already accepted by another worker';
            jobElement.appendChild(alertDiv);
            
            const btn = document.getElementById(`btn-${data.broadcast_id}`);
            if (btn) btn.disabled = true;
            
            // Remove from active jobs after 3 seconds
            setTimeout(() => {
                jobElement.remove();
                activeJobs.delete(data.broadcast_id);
                updateJobCount();
            }, 3000);
        }
    });
    
    // Accept job function
    function acceptJob(broadcastId, bookingId = null) {
        const jobCard = document.getElementById(`job-${broadcastId}`);
        if (!jobCard) return;
        
        const urgency = jobCard.classList.contains('emergency') ? 'Emergency' : 
                       (jobCard.classList.contains('high') ? 'High' : 'Normal');
        
        const confirmMsg = urgency === 'Emergency' 
            ? '🚨 This is an EMERGENCY job! You must arrive within 30 minutes. Accept?' 
            : 'Accept this job? You must complete it once accepted.';
        
        if (!confirm(confirmMsg)) return;
        
        const btn = document.getElementById(`btn-${broadcastId}`);
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Accepting...';
        
        // Call your API to accept the job
        fetch('api/accept_quick_match.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                broadcast_id: broadcastId,
                worker_id: workerId,
                booking_id: bookingId
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Notify server via socket
                socket.emit('worker_accepted', {
                    worker_id: workerId,
                    worker_name: data.worker_name,
                    client_id: data.client_id,
                    booking_id: data.booking_id,
                    broadcast_id: broadcastId
                });
                
                // Redirect to chat
                window.location.href = `../chat.php?booking_id=${data.booking_id}`;
            } else {
                alert('Error: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-hand"></i> Accept Job';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error accepting job');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-hand"></i> Accept Job';
        });
    }
    
    // Request notification permission
    if (Notification.permission !== "granted") {
        Notification.requestPermission();
    }
    
    // Optional: Periodically check for expired jobs from database
    setInterval(() => {
        // This ensures that if a job expires while the page is open,
        // it will be properly removed even if the timer fails
        const now = new Date().getTime();
        document.querySelectorAll('.job-card').forEach(card => {
            const broadcastId = parseInt(card.dataset.broadcastId);
            const timerEl = document.getElementById(`timer-${broadcastId}`);
            if (timerEl) {
                const expiry = parseInt(timerEl.dataset.expiry);
                if (expiry - now <= 0) {
                    // Force expiration
                    if (timers[broadcastId]) clearInterval(timers[broadcastId]);
                    card.style.opacity = '0.5';
                    timerEl.textContent = 'Expired';
                    timerEl.style.color = '#6c757d';
                    const btn = document.getElementById(`btn-${broadcastId}`);
                    if (btn) btn.disabled = true;
                }
            }
        });
    }, 10000); // Check every 10 seconds
    </script>

    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>

<script>
const firebaseConfig = {
  apiKey: "<?= htmlspecialchars(getenv('FIREBASE_API_KEY'), ENT_QUOTES) ?>",
  authDomain: "<?= htmlspecialchars(getenv('FIREBASE_AUTH_DOMAIN'), ENT_QUOTES) ?>",
  projectId: "<?= htmlspecialchars(getenv('FIREBASE_PROJECT_ID'), ENT_QUOTES) ?>",
  storageBucket: "<?= htmlspecialchars(getenv('FIREBASE_STORAGE_BUCKET'), ENT_QUOTES) ?>",
  messagingSenderId: "<?= htmlspecialchars(getenv('FIREBASE_MESSAGING_SENDER_ID'), ENT_QUOTES) ?>",
  appId: "<?= htmlspecialchars(getenv('FIREBASE_APP_ID'), ENT_QUOTES) ?>",
  measurementId: "<?= htmlspecialchars(getenv('FIREBASE_MEASUREMENT_ID'), ENT_QUOTES) ?>"
};
firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

function requestPermission() {
    console.log('Requesting permission...');
    Notification.requestPermission().then((permission) => {
        if (permission === 'granted') {
            console.log('Notification permission granted.');
            
            // Get the Device Token
            messaging.getToken({ vapidKey: 'YOUR_PUBLIC_VAPID_KEY_HERE' })
            .then((currentToken) => {
                if (currentToken) {
                    console.log("Token generated:", currentToken);
                    saveTokenToDatabase(currentToken);
                }
            }).catch((err) => {
                console.log('An error occurred while retrieving token. ', err);
            });
        } else {
            console.log('Unable to get permission to notify.');
        }
    });
}

function saveTokenToDatabase(token) {
    // Send the token to your server using AJAX
    fetch('update_fcm_token.php', {
        method: 'POST',
        headers: { 'Content-Type: application/x-www-form-urlencoded' },
        body: 'fcm_token=' + token
    })
    .then(res => res.json())
    .then(data => console.log("Token saved:", data))
    .catch(err => console.error("Error saving token:", err));
}

// Automatically ask or trigger via a button
window.onload = requestPermission;
</script>

</body>
</html>