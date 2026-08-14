<?php
// worker/navigate.php
include '../db.php';

// --- FORCE PHILIPPINE TIME ---
date_default_timezone_set('Asia/Manila');

// Check if worker is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

if (!isset($_GET['booking_id'])) {
    die("Invalid Access");
}

$booking_id = $_GET['booking_id'];
$worker_id = $_SESSION['user_id'];

// Fetch Booking with client location from bookings table
$sql = "SELECT bookings.*, 
        client.full_name as client_name, 
        client.phone as client_phone,
        client.profile_pic as client_profile_pic,
        client.address as client_address
        FROM bookings 
        JOIN users client ON bookings.client_id = client.id 
        WHERE bookings.id = ? AND bookings.worker_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $booking_id, $worker_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Booking not found or you don't have permission to view it.");
}

$booking = $result->fetch_assoc();

// Get location from bookings table
$destination_lat = $booking['latitude'] ?? null;
$destination_lng = $booking['longitude'] ?? null;
$destination_address = $booking['location_address'] ?? $booking['client_address'] ?? 'Location not specified';

// If no location in bookings, try to use client's address as fallback
if (empty($destination_lat) || empty($destination_lng)) {
    // You might want to geocode the address here, but for now show error
    die("<h2>Location Unavailable</h2><p>The client hasn't provided their exact location for this booking.</p><a href='dashboard.php'>Back to Dashboard</a>");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Navigate to Client | Abilisto</title>
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
            filter: saturate(1.1);
        }

        /* Location source badge */
        .location-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            color: #fff;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            z-index: 1000;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .location-badge i {
            color: #146af5;
            margin-right: 5px;
        }

        /* --- FUTURISTIC FLOATING PANEL --- */
        .navigation-panel {
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
        .navigation-panel.minimized {
            padding: 12px 24px;
            width: auto;
            min-width: 200px;
            cursor: pointer;
        }

        .navigation-panel.minimized .panel-header,
        .navigation-panel.minimized .client-info,
        .navigation-panel.minimized .destination-info,
        .navigation-panel.minimized .trip-info,
        .navigation-panel.minimized .panel-actions {
            display: none;
        }

        .navigation-panel.minimized .minimized-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .navigation-panel .minimized-content {
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

        .navigation-panel.minimized .minimize-btn i {
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
            background: rgba(20, 106, 245, 0.2);
            color: var(--accent);
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
            background-color: var(--accent);
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(20, 106, 245, 0.7);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(20, 106, 245, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(20, 106, 245, 0); }
            100% { box-shadow: 0 0 0 0 rgba(20, 106, 245, 0); }
        }

        /* Client Info */
        .client-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .client-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
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

        .client-details h3 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .client-details p { margin: 2px 0 0; font-size: 0.85rem; color: var(--text-muted); }

        /* Destination Address */
        .destination-info {
            margin-top: 12px;
            padding: 10px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            font-size: 0.8rem;
        }

        .destination-info i {
            color: #ef4444;
            margin-right: 5px;
        }

        /* Trip Info */
        .trip-info {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            font-size: 0.9rem;
        }

        .trip-info-item {
            text-align: center;
            flex: 1;
        }

        .trip-info-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .trip-info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--accent);
        }

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

        .btn-navigate {
            background: var(--accent);
            color: white;
        }
        .btn-navigate:hover { background: #0f52c2; }

        /* Hide Routing Instructions */
        .leaflet-routing-container { display: none !important; }
        
        /* Custom Map Controls Position */
        .leaflet-top { top: 20px; }
        .leaflet-left { left: 20px; }
    </style>
</head>
<body>

    <!-- Location Source Indicator -->
    <div class="location-badge">
        <i class="fa-solid fa-map-pin"></i> Service Location
    </div>

    <div class="navigation-panel" id="navigationPanel">
        <!-- Minimize Button -->
        <button class="minimize-btn" id="minimizeBtn" onclick="togglePanel()">
            <i class="fa-solid fa-chevron-down"></i>
        </button>

        <!-- Minimized Content -->
        <div class="minimized-content" id="minimizedContent">
            <div class="status-badge">
                <div class="pulse-dot"></div>
                NAV
            </div>
            <span style="font-size: 0.9rem; font-weight: 600;"><?php echo substr($booking['client_name'], 0, 1); ?></span>
        </div>

        <!-- Full Content -->
        <div class="panel-header">
            <div class="status-badge">
                <div class="pulse-dot"></div>
                NAVIGATION
            </div>
            <div style="font-size: 0.8rem; color: #f87171; font-weight: bold;">
                <?php echo strtoupper($booking['urgency_level']); ?> PRIORITY
            </div>
        </div>

        <div class="client-info">
            <div class="client-avatar">
                <?php 
                $picPath = "../uploads/profiles/" . $booking['client_profile_pic']; 
                
                if (!empty($booking['client_profile_pic']) && file_exists($picPath)): ?>
                    <img src="<?php echo $picPath; ?>" 
                         alt="Profile" 
                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <?php else: ?>
                    <?php echo substr($booking['client_name'], 0, 1); ?>
                <?php endif; ?>
            </div>

            <div class="client-details">
                <h3><?php echo $booking['client_name']; ?></h3>
                <p><i class="fa-solid fa-location-dot"></i> Client location</p>
            </div>
        </div>

        <!-- Destination Address -->
        <div class="destination-info">
            <i class="fa-solid fa-location-dot" style="color: #ef4444;"></i>
            <strong>Destination:</strong> <?php echo htmlspecialchars($destination_address); ?>
        </div>

        <!-- Trip Info (will be updated by JavaScript) -->
        <div class="trip-info">
            <div class="trip-info-item">
                <div class="trip-info-label">Distance</div>
                <div class="trip-info-value" id="distance-display">--</div>
            </div>
            <div class="trip-info-item">
                <div class="trip-info-label">ETA</div>
                <div class="trip-info-value" id="eta-display">--</div>
            </div>
        </div>

        <div class="panel-actions">
            <a href="tel:<?php echo $booking['client_phone'] ?? '#'; ?>" class="btn btn-call">
                <i class="fa-solid fa-phone"></i> Call
            </a>
            <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $destination_lat; ?>,<?php echo $destination_lng; ?>" 
               target="_blank" class="btn btn-navigate">
                <i class="fa-brands fa-google"></i> Google Maps
            </a>
            <a href="dashboard.php" class="btn btn-exit" style="grid-column: span 2;">
                <i class="fa-solid fa-door-open"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <div id="map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

    <script>
        // DESTINATION LOCATION - FROM BOOKINGS TABLE
        var destinationLat = <?php echo json_encode($destination_lat); ?>;
        var destinationLng = <?php echo json_encode($destination_lng); ?>;
        
        // Initialize Map
        var map = L.map('map', {
            zoomControl: false
        }).setView([destinationLat, destinationLng], 13);

        // Add Google Streets Tiles
        L.tileLayer('http://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
        }).addTo(map);

        // Custom Icons
        var destinationIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png', // Home/Destination Icon
            iconSize: [40, 40], 
            iconAnchor: [20, 40], 
            popupAnchor: [0, -40]
        });

        var workerIcon = L.icon({
            iconUrl: 'https://cdn-icons-png.flaticon.com/512/1995/1995470.png', // Worker Icon
            iconSize: [45, 45], 
            iconAnchor: [22, 45], 
            popupAnchor: [0, -45]
        });

        // Add Destination Marker (from bookings table)
        var destinationMarker = L.marker([destinationLat, destinationLng], {icon: destinationIcon}).addTo(map)
            .bindPopup("<b>Client Location</b><br><?php echo addslashes($destination_address); ?>")
            .openPopup();

        // Variable to store routing control
        var routingControl = null;
        var workerMarker = null;

        // Try to get worker's location (starting point)
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const workerLat = position.coords.latitude;
                    const workerLng = position.coords.longitude;
                    
                    // Add worker marker
                    workerMarker = L.marker([workerLat, workerLng], {icon: workerIcon})
                        .addTo(map)
                        .bindPopup('<b>Your Location</b>');
                    
                    // Create routing control
                    routingControl = L.Routing.control({
                        waypoints: [
                            L.latLng(workerLat, workerLng),
                            L.latLng(destinationLat, destinationLng)
                        ],
                        lineOptions: {
                            styles: [{color: '#146af5', opacity: 0.8, weight: 6}]
                        },
                        createMarker: function() { return null; }, // Don't create markers (we already have them)
                        show: false, // Hide instructions
                        addWaypoints: false,
                        draggableWaypoints: false,
                        fitSelectedRoutes: true,
                        router: L.Routing.osrmv1({
                            serviceUrl: 'https://router.project-osrm.org/route/v1'
                        })
                    }).addTo(map);
                    
                    // Listen for route calculation to get distance and ETA
                    routingControl.on('routesfound', function(e) {
                        const routes = e.routes;
                        if (routes && routes.length > 0) {
                            const route = routes[0];
                            const distanceKm = (route.summary.totalDistance / 1000).toFixed(1);
                            const durationMin = Math.round(route.summary.totalTime / 60);
                            
                            // Update displays
                            document.getElementById('distance-display').innerHTML = distanceKm + ' km';
                            document.getElementById('eta-display').innerHTML = durationMin + ' min';
                            
                            // Fit bounds to show both markers and route
                            const bounds = L.latLngBounds([
                                [workerLat, workerLng],
                                [destinationLat, destinationLng]
                            ]);
                            map.fitBounds(bounds, {padding: [30, 30]});
                        }
                    });
                },
                function(error) {
                    console.log('Geolocation error:', error);
                    document.getElementById('distance-display').innerHTML = '--';
                    document.getElementById('eta-display').innerHTML = '--';
                    
                    // Still show destination location
                    map.setView([destinationLat, destinationLng], 14);
                    
                    // Show error message
                    const panel = document.querySelector('.navigation-panel');
                    const errorMsg = document.createElement('div');
                    errorMsg.style.cssText = 'background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 8px; border-radius: 8px; margin-top: 10px; font-size: 0.8rem; text-align: center;';
                    errorMsg.innerHTML = '<i class="fa-solid fa-exclamation-triangle"></i> Unable to get your location. Please enable location services.';
                    panel.insertBefore(errorMsg, panel.querySelector('.panel-actions'));
                }
            );
        } else {
            document.getElementById('distance-display').innerHTML = '--';
            document.getElementById('eta-display').innerHTML = '--';
            map.setView([destinationLat, destinationLng], 14);
        }

        // Manual refresh button (optional)
        function refreshRoute() {
            if (navigator.geolocation && routingControl) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const workerLat = position.coords.latitude;
                        const workerLng = position.coords.longitude;
                        
                        // Update worker marker
                        if (workerMarker) {
                            workerMarker.setLatLng([workerLat, workerLng]);
                        }
                        
                        // Update route
                        routingControl.setWaypoints([
                            L.latLng(workerLat, workerLng),
                            L.latLng(destinationLat, destinationLng)
                        ]);
                    }
                );
            }
        }

        // Auto-refresh every 30 seconds
        setInterval(refreshRoute, 30000);

        // Panel Toggle Function
        function togglePanel() {
            var panel = document.getElementById('navigationPanel');
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