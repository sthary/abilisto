<?php
// client/track_worker.php
include '../db_connect.php';

// --- FIX 1: FORCE PHILIPPINE TIME ---
date_default_timezone_set('Asia/Manila'); 

if (!isset($_GET['booking_id'])) {
    die("Invalid Access");
}

$booking_id = $_GET['booking_id'];
$client_id = $_SESSION['user_id'];

// 1. Fetch Booking (Updated to include profile_pic)
$sql = "SELECT bookings.*,
        users.t_latitude as worker_lat,
        users.t_longitude as worker_lng,
        users.full_name as worker_name,
        users.phone as worker_phone,
        users.profile_pic
        FROM bookings
        JOIN users ON bookings.worker_id = users.id
        WHERE bookings.id = ? AND bookings.client_id = ?";

// --- THIS WAS MISSING ---
$stmt = $conn->prepare($sql);
$stmt->execute([$booking_id, $client_id]);
// ------------------------

$booking = $stmt->fetch();
if (!$booking) {
    die("Booking not found.");
}

// --- PRIVACY GATES (Uncomment these for production) ---

/*
// Gate A: Urgency
if ($booking['urgency_level'] == 'Normal') {
    die("<h2>Tracking Unavailable</h2><p>Real-time tracking is only available for High Priority/Emergency bookings.</p><a href='my_bookings.php'>Back</a>");
}

// Gate B: Status
if ($booking['status'] == 'Pending' || $booking['status'] == 'Completed' || $booking['status'] == 'Cancelled') {
    die("<h2>Tracking Inactive</h2><p>The worker has not started this job yet.</p><a href='my_bookings.php'>Back</a>");
}

// Gate C: Time Window
$booking_time = strtotime($booking['booking_date']);
$now = time();
$start_window = $booking_time - (24 * 3600); 
$end_window = $booking_time + (24 * 3600); 

if ($now < $start_window || $now > $end_window) {
    die("<h2>Tracking Expired</h2><a href='my_bookings.php'>Back</a>");
}
*/
?>

<!DOCTYPE html>
<html>
<head>
    <title>Live Tracking | Abilisto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />

    <style>
        :root {
            --glass-bg: rgba(28, 28, 30, 0.85);
            --glass-border: rgba(255, 255, 255, 0.1);
            --accent: #146af5;
            --success: #22c55e;
            --danger: #ef4444;
            --text: #ffffff;
            --text-muted: #9ca3af;
        }

        body { margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; background: #000; }
        
        #map { 
            position: absolute; 
            top: 0; 
            bottom: 0; 
            width: 100%; 
            z-index: 1; 
            filter: saturate(1.1); /* Make map colors pop slightly */
        }

        /* --- FUTURISTIC FLOATING PANEL --- */
        .tracking-panel {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 400px;
            background: var(--glass-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            color: var(--text);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        /* Minimized state */
        .tracking-panel.minimized {
            padding: 12px 24px;
            width: auto;
            min-width: 200px;
            cursor: pointer;
        }

        .tracking-panel.minimized .panel-header,
        .tracking-panel.minimized .worker-info,
        .tracking-panel.minimized .panel-actions {
            display: none;
        }

        .tracking-panel.minimized .minimized-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .tracking-panel .minimized-content {
            display: none;
        }

        .minimized-content .status-badge {
            margin: 0;
            padding: 4px 8px;
            font-size: 0.7rem;
        }

        .minimize-btn {
            position: absolute;
            top: 10px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
            z-index: 10;
        }

        .minimize-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        .tracking-panel.minimized .minimize-btn i {
            transform: rotate(180deg);
        }

        /* Header Section */
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 15px;
        }

        .status-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(34, 197, 94, 0.2);
            color: var(--success);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        /* Worker Info */
        .worker-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .worker-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .worker-details h3 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .worker-details p { margin: 2px 0 0; font-size: 0.85rem; color: var(--text-muted); }

        /* Actions */
        .panel-actions {
            margin-top: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: transform 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn:active { transform: scale(0.96); }

        .btn-call {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .btn-call:hover { background: rgba(255,255,255,0.2); }

        .btn-exit {
            background: var(--text);
            color: black;
        }
        .btn-exit:hover { background: #e2e8f0; }

        /* Hide Routing Instructions */
        .leaflet-routing-container { display: none !important; }
        
        /* Custom Map Controls Position */
        .leaflet-top { top: 20px; }
        .leaflet-left { left: 20px; }
    </style>
</head>
<body>

    <div class="tracking-panel" id="trackingPanel">
        <!-- Minimize Button -->
        <button class="minimize-btn" id="minimizeBtn" onclick="togglePanel()">
            <i class="fa-solid fa-chevron-down"></i>
        </button>

        <!-- Minimized Content -->
        <div class="minimized-content" id="minimizedContent">
            <div class="status-badge">
                <div class="pulse-dot"></div>
                LIVE
            </div>
            <span style="font-size: 0.9rem; font-weight: 600;"><?php echo substr($booking['worker_name'], 0, 1); ?></span>
        </div>

        <!-- Full Content -->
        <div class="panel-header">
            <div class="status-badge">
                <div class="pulse-dot"></div>
                LIVE TRACKING
            </div>
            <div style="font-size: 0.8rem; color: #f87171; font-weight: bold;">
                <?php echo strtoupper($booking['urgency_level']); ?> PRIORITY
            </div>
        </div>

        <div class="worker-info">
            <div class="worker-avatar">
                <?php 
                // Define the path to your uploads folder (Adjust if yours is different)
                $picPath = "../uploads/profiles/" . $booking['profile_pic']; 
                
                // Check if profile_pic is not empty in DB
                if (!empty($booking['profile_pic'])): ?>
                    <img src="<?php echo $picPath; ?>" 
                         alt="Profile" 
                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <?php else: ?>
                    <?php echo substr($booking['worker_name'], 0, 1); ?>
                <?php endif; ?>
            </div>

            <div class="worker-details">
                <h3><?php echo $booking['worker_name']; ?></h3>
                <p>Arriving to your location</p>
                <p style="font-size: 0.75rem; color: #4ade80; margin-top: 4px;">
                    <i class="fa-solid fa-location-arrow"></i> Position updating
                </p>
            </div>
        </div>

        <div class="panel-actions">
            <a href="tel:<?php echo $booking['worker_phone'] ?? '#'; ?>" class="btn btn-call">
                <i class="fa-solid fa-phone"></i> Call
            </a>
            <a href="my_bookings.php" class="btn btn-exit">
                <i class="fa-solid fa-door-open"></i> Exit
            </a>
        </div>
    </div>

    <div id="map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

    <script>
        var clientLat = <?php echo json_encode($_SESSION['t_latitude'] ?? null); ?> || 9.3359;
        var clientLng = <?php echo json_encode($_SESSION['t_longitude'] ?? null); ?> || 125.971;
        var workerLat = <?php echo json_encode($booking['worker_lat'] ?? null); ?> || 9.3359;
        var workerLng = <?php echo json_encode($booking['worker_lng'] ?? null); ?> || 125.971;

        // Initialize Map
        var map = L.map('map', {
            zoomControl: false // We will add it back in a custom position if needed, or rely on pinch
        }).setView([workerLat, workerLng], 15);

        // Add Google Hybrid/Streets Tiles (Clean Look)
        L.tileLayer('http://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',{
            maxZoom: 20,
            subdomains:['mt0','mt1','mt2','mt3']
        }).addTo(map);

        // Custom Icons
        var clientIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png', // Home Icon
            iconSize: [40, 40], iconAnchor: [20, 40], popupAnchor: [0, -40]
        });

        var workerIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/1995/1995470.png', // Scooter/Worker Icon
            iconSize: [45, 45], iconAnchor: [22, 45], popupAnchor: [0, -45]
        });

        // Add Markers
        var clientMarker = L.marker([clientLat, clientLng], {icon: clientIcon}).addTo(map)
            .bindPopup("<b>Your Location</b>");

        var workerMarker = L.marker([workerLat, workerLng], {icon: workerIcon}).addTo(map)
            .bindPopup("<b>Worker</b>");

        // Routing - REMOVED THE AUTO-ZOOM
        var routingControl = L.Routing.control({
            waypoints: [
                L.latLng(workerLat, workerLng),
                L.latLng(clientLat, clientLng)
            ],
            lineOptions: {
                styles: [{color: '#146af5', opacity: 0.8, weight: 6}] // Modern Blue Path
            },
            createMarker: function() { return null; },
            show: false,
            addWaypoints: false,
            draggableWaypoints: false,
            fitSelectedRoutes: false // CHANGED FROM true TO false TO PREVENT AUTO-ZOOM
        }).addTo(map);

        // Real-time Update
        function updateWorkerPosition() {
            fetch('get_worker_coords.php?worker_id=<?php echo $booking['worker_id']; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.lat && data.lng) {
                        var newLatLng = new L.LatLng(data.lat, data.lng);
                        workerMarker.setLatLng(newLatLng);
                        routingControl.setWaypoints([
                            newLatLng,
                            L.latLng(clientLat, clientLng)
                        ]);
                    }
                })
                .catch(err => console.error("Tracking Error:", err));
        }

        setInterval(updateWorkerPosition, 5000);

        // Panel Toggle Function
        function togglePanel() {
            var panel = document.getElementById('trackingPanel');
            var btnIcon = document.querySelector('#minimizeBtn i');
            
            panel.classList.toggle('minimized');
            
            if (panel.classList.contains('minimized')) {
                btnIcon.className = 'fa-solid fa-chevron-up';
            } else {
                btnIcon.className = 'fa-solid fa-chevron-down';
            }
        }
    </script>
</body>
</html>