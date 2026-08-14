<?php
// worker/listen.php

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 8; // For testing
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Worker Listener</title>
    <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
</head>
<body>
    <h2>Worker Listener (ID: <?php echo $_SESSION['user_id']; ?>)</h2>
    <div id="status">Connecting...</div>
    <div id="jobs"></div>

    <script>
    const workerId = <?php echo $_SESSION['user_id']; ?>;
    const socket = io('http://localhost:3001');
    
    socket.on('connect', () => {
        document.getElementById('status').innerHTML = '✅ Connected';
        socket.emit('register_user', workerId);
    });
    
    socket.on('new_job_alert', (data) => {
        console.log('New job:', data);
        document.getElementById('jobs').innerHTML += `
            <div style="border:1px solid green; padding:10px; margin:10px">
                <h3>🔔 NEW JOB!</h3>
                <p>From: ${data.client_name}</p>
                <p>Problem: ${data.problem}</p>
                <p>Fee: ₱${data.fee}</p>
            </div>
        `;
    });
    
    socket.on('registered', (data) => {
        document.getElementById('status').innerHTML += '<br>✅ Registered';
    });
    </script>
</body>
</html>