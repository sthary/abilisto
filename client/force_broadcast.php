<?php
// client/force_broadcast.php
session_start();
$client_id = $_SESSION['user_id'] ?? 1;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Force Broadcast</title>
    <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
    <style>
        body { font-family: Arial; padding: 20px; }
        button { padding: 10px; margin: 5px; font-size: 16px; }
        .log { background: #f0f0f0; padding: 10px; height: 200px; overflow-y: scroll; margin-top: 20px; }
    </style>
</head>
<body>
    <h2>Force Broadcast Test</h2>
    
    <div>
        <button onclick="broadcastTo8()">Broadcast to Worker 8</button>
        <button onclick="broadcastToMultiple()">Broadcast to Multiple</button>
        <button onclick="checkDatabase()">Check Database</button>
    </div>
    
    <div id="status"></div>
    <div id="log" class="log"></div>

    <script>
    let socket;
    const logDiv = document.getElementById('log');
    
    function addLog(message) {
        const timestamp = new Date().toLocaleTimeString();
        logDiv.innerHTML += `[${timestamp}] ${message}<br>`;
        logDiv.scrollTop = logDiv.scrollHeight;
    }
    
    socket = io('http://localhost:3001', {
        transports: ['websocket', 'polling']
    });
    
    socket.on('connect', () => {
        addLog('✅ Connected to server');
        socket.emit('register_user', <?php echo $client_id; ?>);
    });
    
    socket.on('registered', (data) => {
        addLog('✅ Registered: ' + JSON.stringify(data));
    });
    
    socket.on('broadcast_sent', (data) => {
        addLog('✅ Broadcast confirmed: ' + JSON.stringify(data));
    });
    
    function broadcastTo8() {
        const broadcastId = Math.floor(Math.random() * 10000);
        addLog(`📡 Broadcasting to worker 8 (ID: ${broadcastId})...`);
        
        socket.emit('send_broadcast', {
            broadcast_id: broadcastId,
            worker_ids: [8],
            category: 'Electrical',
            urgency: 'Emergency',
            client_id: <?php echo $client_id; ?>,
            client_name: 'Test Client',
            problem: 'Emergency test broadcast',
            fee: 75
        });
    }
    
    function broadcastToMultiple() {
        const broadcastId = Math.floor(Math.random() * 10000);
        addLog(`📡 Broadcasting to workers 8, 68, 73 (ID: ${broadcastId})...`);
        
        socket.emit('send_broadcast', {
            broadcast_id: broadcastId,
            worker_ids: [8, 68, 73],
            category: 'Plumbing',
            urgency: 'High',
            client_id: <?php echo $client_id; ?>,
            client_name: 'Test Client',
            problem: 'Multiple workers test',
            fee: 60
        });
    }
    
    function checkDatabase() {
        window.open('check_broadcast.php', '_blank');
    }
    </script>
</body>
</html>