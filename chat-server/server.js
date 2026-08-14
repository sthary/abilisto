// server.js (Cloud Ready Version + Proper Media Support)

const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const mysql = require('mysql');
const cors = require('cors');
require('dotenv').config(); 

const app = express();
app.use(cors());

// 1. Health Check Route
app.get('/', (req, res) => {
    res.send('✅ Abilisto Chat Server is Running!');
});

const server = http.createServer(app);

// 2. Socket Setup
const io = new Server(server, {
    cors: {
        origin: "*", 
        methods: ["GET", "POST"]
    }
});

// 3. Database Connection (With Fail-Safe)
const db = mysql.createConnection({
    host: process.env.DB_HOST || "127.0.0.1",
    user: process.env.DB_USER || "root",
    password: process.env.DB_PASSWORD || "",
    database: process.env.DB_NAME || "abilisto_db"
});

let isDbConnected = false;

db.connect(err => {
    if (err) { 
        console.warn('⚠️ WARNING: Could not connect to MySQL Database.');
        console.warn('   - Real-time chat will work, but history will NOT be saved.');
        isDbConnected = false;
    } else { 
        console.log('✅ Connected to MySQL Database'); 
        isDbConnected = true;
    }
});

// Keep connection alive
setInterval(() => {
    if (isDbConnected) {
        db.query('SELECT 1');
    }
}, 5000);

// MAIN SOCKET CONNECTION HANDLER
io.on('connection', (socket) => {
    console.log(`User Connected: ${socket.id}`);

    // === CHAT FEATURES ===
    socket.on('join_room', (booking_id) => {
        socket.join(booking_id.toString());
        console.log(`User joined room: ${booking_id}`);
    });

    socket.on('send_message', (data) => {
        const { booking_id, sender_id, sender_name, message, attachment_url, message_type } = data;
        // message_type: 'text' | 'image' | 'video'
        // attachment_url: file path returned by upload.php (null for text messages)
        // message: text content (empty string for media messages)

        const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        // Broadcast to everyone in the room instantly
        io.to(booking_id.toString()).emit('receive_message', {
            sender_id:      sender_id,
            sender_name:    sender_name,
            message:        message,
            attachment_url: attachment_url,
            message_type:   message_type || 'text',
            timestamp:      now
        });

        // Save text messages to DB here.
        // Media messages are already saved by upload.php before the socket fires.
        if (isDbConnected && message_type === 'text' && message && message.trim() !== '') {
            const sql = "INSERT INTO messages (booking_id, sender_id, message, message_type) VALUES (?, ?, ?, 'text')";
            db.query(sql, [booking_id, sender_id, message.trim()], (err) => {
                if (err) console.error("DB Save Error (text):", err);
            });
        }
    });

    // === QUICK MATCH FEATURES ===
    
    // 1. User Registration (Client or Worker)
    socket.on("register_user", (userId) => {
        const roomName = "user_" + userId;
        socket.join(roomName);
        console.log(`👤 User ${userId} joined personal room: ${roomName}`);
        socket.emit("registered", { 
            success: true, 
            userId: userId,
            room: roomName 
        });
    });

    // 2. Broadcast Trigger: Client sends job to Workers
    socket.on("send_broadcast", (data) => {
        console.log(`📡 Broadcasting Job #${data.broadcast_id} to workers:`, data.worker_ids);
        
        data.worker_ids.forEach(workerId => {
            const workerRoom = "user_" + workerId;
            io.to(workerRoom).emit("new_job_alert", {
                broadcast_id: data.broadcast_id,
                category:     data.category,
                urgency:      data.urgency,
                client_id:    data.client_id,
                client_name:  data.client_name,
                problem:      data.problem,
                fee:          data.fee,
                timestamp:    new Date().toISOString()
            });
        });

        socket.emit("broadcast_sent", {
            broadcast_id: data.broadcast_id,
            workers_sent: data.worker_ids.length
        });
    });

    // 3. Worker accepts the job
    socket.on("worker_accepted", (data) => {
        console.log(`✅ Job Accepted by ${data.worker_name} for booking #${data.booking_id}`);
        
        io.to("user_" + data.client_id).emit("job_matched", {
            worker_id:    data.worker_id,
            worker_name:  data.worker_name,
            booking_id:   data.booking_id,
            broadcast_id: data.broadcast_id,
            message:      `🎉 ${data.worker_name} accepted your job!`
        });

        if (data.broadcast_id) {
            socket.broadcast.emit("job_taken", {
                broadcast_id: data.broadcast_id,
                worker_id:    data.worker_id,
                message:      "This job has been accepted by another worker"
            });
        }
    });

    // 4. Worker declines the job
    socket.on("worker_declined", (data) => {
        console.log(`❌ Worker ${data.worker_id} declined job #${data.broadcast_id}`);
        if (data.client_id) {
            io.to("user_" + data.client_id).emit("worker_declined_notification", {
                worker_id:    data.worker_id,
                broadcast_id: data.broadcast_id
            });
        }
    });

    // 5. Job timeout
    socket.on("job_timeout", (data) => {
        console.log(`⏰ Job #${data.broadcast_id} expired`);
        if (data.client_id) {
            io.to("user_" + data.client_id).emit("job_expired", {
                broadcast_id: data.broadcast_id
            });
        }
    });

    // 6. Test connection
    socket.on("test_connection", (data) => {
        console.log("Test connection received:", data);
        socket.emit("test_response", { 
            message:    "Server is working!", 
            socket_id:  socket.id,
            timestamp:  new Date().toISOString()
        });
    });

    socket.on('disconnect', () => {
        console.log(`User Disconnected: ${socket.id}`);
    });
});

// 4. Use Cloud Port OR 3001
const PORT = process.env.PORT || 3001;
server.listen(PORT, '0.0.0.0', () => {
    console.log(`🚀 Chat Server running on port ${PORT}`);
    console.log(`📡 WebSocket ready for connections`);
    console.log(`🌐 HTTP: http://localhost:${PORT}`);
});