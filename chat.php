<?php
// chat.php
include 'db_connect.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || !isset($_GET['booking_id'])) {
    header("Location: auth/login.php");
    exit();
}

$booking_id = (int) $_GET['booking_id'];
$user_id    = (int) $_SESSION['user_id'];
$user_name  = $_SESSION['full_name'] ?? "User";
$role       = $_SESSION['role'] ?? 'client';

$exit_link = ($role === 'worker') ? 'worker/dashboard.php' : 'client/my_bookings.php';

// Verify Access
$check = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND (client_id = ? OR worker_id = ?)");
$check->execute([$booking_id, $user_id, $user_id]);
if (!$check->fetch()) die("Access Denied.");

// ✅ FIXED: Fetch other user's name + profile_pic (not avatar)
$nameQuery = $conn->prepare("
    SELECT u.full_name, u.profile_pic
    FROM bookings b
    JOIN users u ON (b.client_id = u.id OR b.worker_id = u.id)
    WHERE b.id = ? AND u.id != ?
    LIMIT 1
");
$nameQuery->execute([$booking_id, $user_id]);
$otherUser   = $nameQuery->fetch();
$otherName   = $otherUser ? $otherUser['full_name'] : 'User';

// ✅ Build correct avatar URL from uploads/profiles/
$otherAvatar = '';
if ($otherUser && !empty($otherUser['profile_pic'])) {
    $otherAvatar = 'uploads/profiles/' . htmlspecialchars($otherUser['profile_pic']);
} else {
    $otherAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($otherName) . '&background=random&size=96';
}

// Fetch message history
$history = $conn->prepare(
    "SELECT messages.*, users.full_name, users.profile_pic
     FROM messages
     JOIN users ON messages.sender_id = users.id
     WHERE booking_id = ?
     ORDER BY messages.\"timestamp\" ASC"
);
$history->execute([$booking_id]);
$history_rows = $history->fetchAll();

$quick_replies = ($role == 'client')
    ? ["Hi, are you available?", "Where are you located?", "Can you send a photo?", "Thank you!", "I'm here."]
    : ["Hello, I'm on my way.", "I have arrived.", "Can I call you?", "Please send your location.", "Job completed. Thanks!"];
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Chat · Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#146af5",
                        "background-light": "#f6f7f8",
                        "background-dark": "#101922",
                    },
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    borderRadius: {
                        "DEFAULT": "1rem", "lg": "2rem",
                        "xl": "3rem", "full": "9999px"
                    },
                },
            },
        }
    </script>

    <style>
        .glass {
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .chat-gradient { background: linear-gradient(135deg, #146af5 0%, #8b5cf6 100%); }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .chat-image, .chat-video { max-width: 100%; border-radius: 0.75rem; cursor: pointer; }
        .chat-video { max-height: 250px; }
        .message-bubble-text { word-break: break-word; }
        .attachment-bubble {
            background: transparent !important; padding: 0.25rem !important;
            border: none !important; box-shadow: none !important;
        }
        #loader-overlay {
            position: absolute; inset: 0;
            background: rgba(255,255,255,0.9);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            z-index: 1000; backdrop-filter: blur(5px);
        }
        .dark #loader-overlay { background: rgba(0,0,0,0.8); }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-slate-100 min-h-screen">

<div class="flex items-center justify-center min-h-screen p-0 md:p-6 lg:p-12">
    <div class="w-full max-w-4xl h-[100vh] md:h-[85vh] flex flex-col bg-white/50 dark:bg-slate-900/50 backdrop-blur-xl md:rounded-xl shadow-2xl overflow-hidden border border-white/20 relative">

        <!-- Header -->
        <header class="chat-gradient px-6 py-4 flex items-center justify-between text-white shadow-lg z-10">
            <div class="flex items-center gap-4">
                <a href="<?php echo $exit_link; ?>" class="hover:bg-white/20 p-2 rounded-full transition-colors">
                    <span class="material-symbols-outlined text-2xl">arrow_back</span>
                </a>
                <div class="relative">
                    <!-- ✅ FIXED: uses correct $otherAvatar path -->
                    <div class="size-12 rounded-full border-2 border-white/30 bg-cover bg-center"
                         style="background-image: url('<?php echo $otherAvatar; ?>')">
                    </div>
                    <span class="absolute bottom-0 right-0 size-3.5 bg-green-400 border-2 border-white rounded-full"></span>
                </div>
                <div class="flex flex-col">
                    <h1 class="text-lg font-bold tracking-tight"><?php echo htmlspecialchars($otherName); ?></h1>
                    <span class="text-xs font-medium opacity-90 uppercase tracking-widest flex items-center gap-1">
                        <span id="connection-status" class="flex items-center gap-1">
                            <span class="size-2 bg-green-300 rounded-full"></span> Online
                        </span>
                    </span>
                </div>
            </div>
            <div class="w-10"></div>
        </header>

        <!-- Loader -->
        <div id="loader-overlay">
            <div class="w-12 h-12 border-4 border-t-blue-500 border-gray-200 rounded-full animate-spin mb-3"></div>
            <div id="loader-text" class="text-slate-600 dark:text-slate-300 font-bold">Connecting...</div>
        </div>

        <!-- Chat Box -->
        <main class="flex-1 overflow-y-auto p-4 md:p-8 space-y-6 hide-scrollbar bg-slate-50/30 dark:bg-slate-900/30" id="chatBox">

            <?php if (count($history_rows) == 0): ?>
                <div class="flex flex-col items-center justify-center h-full text-center text-slate-400 dark:text-slate-500" id="emptyState">
                    <span class="material-symbols-outlined text-6xl mb-3">chat</span>
                    <p class="text-lg font-medium">Start the conversation!</p>
                    <small class="text-sm opacity-70">Send a quick message below.</small>
                </div>
            <?php else: ?>
                <?php
                $lastDate = '';
                foreach ($history_rows as $msg):
                    $is_me    = ($msg['sender_id'] == $user_id);
                    $msg_type = $msg['message_type'] ?? 'text';
                    $attach   = $msg['attachment_path'] ?? '';
                    $time     = date('h:i A', strtotime($msg['timestamp']));
                    $msgDate  = date('Y-m-d', strtotime($msg['timestamp']));

                    // ✅ Build per-message sender avatar
                    $senderAvatar = !empty($msg['profile_pic'])
                        ? 'uploads/profiles/' . htmlspecialchars($msg['profile_pic'])
                        : 'https://ui-avatars.com/api/?name=' . urlencode($msg['full_name']) . '&background=random&size=32';

                    if ($lastDate !== $msgDate):
                        $lastDate    = $msgDate;
                        $displayDate = (date('Y-m-d') === $msgDate) ? 'Today' : date('F j, Y', strtotime($msgDate));
                        echo '<div class="flex justify-center"><span class="px-4 py-1 rounded-full bg-slate-200/50 dark:bg-slate-800/50 text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">' . $displayDate . '</span></div>';
                    endif;
                ?>
                    <div class="flex <?php echo $is_me ? 'justify-end' : 'items-end gap-3'; ?> max-w-[85%] md:max-w-[70%] <?php echo $is_me ? 'ml-auto' : ''; ?>">

                        <?php if (!$is_me): ?>
                            <!-- ✅ FIXED: uses profile_pic from messages query -->
                            <div class="size-8 rounded-full bg-cover bg-center shrink-0 shadow-sm"
                                 style="background-image: url('<?php echo $senderAvatar; ?>')">
                            </div>
                        <?php endif; ?>

                        <div class="flex flex-col gap-1 <?php echo $is_me ? 'items-end' : ''; ?> w-full">
                            <?php if ($msg_type === 'image' && $attach): ?>
                                <div class="glass p-2 rounded-2xl <?php echo $is_me ? 'rounded-br-none' : 'rounded-bl-none'; ?> shadow-sm attachment-bubble">
                                    <img src="<?php echo htmlspecialchars($attach); ?>"
                                         class="chat-image rounded-xl"
                                         onclick="window.open('<?php echo htmlspecialchars($attach); ?>','_blank')"
                                         alt="Image">
                                </div>
                            <?php elseif ($msg_type === 'video' && $attach): ?>
                                <div class="glass p-2 rounded-2xl <?php echo $is_me ? 'rounded-br-none' : 'rounded-bl-none'; ?> shadow-sm attachment-bubble">
                                    <video class="chat-video rounded-xl" controls>
                                        <source src="<?php echo htmlspecialchars($attach); ?>">
                                    </video>
                                </div>
                            <?php else: ?>
                                <div class="glass p-4 rounded-2xl <?php echo $is_me ? 'rounded-br-none chat-gradient text-white' : 'rounded-bl-none text-slate-800 dark:text-slate-200'; ?> shadow-sm leading-relaxed message-bubble-text">
                                    <?php if (!$is_me): ?>
                                        <strong class="block mb-1 text-primary text-xs"><?php echo htmlspecialchars($msg['full_name']); ?></strong>
                                    <?php endif; ?>
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                            <?php endif; ?>

                            <span class="text-[10px] text-slate-400 font-medium px-1 uppercase tracking-wider flex items-center gap-1 <?php echo $is_me ? 'justify-end' : ''; ?>">
                                <?php echo $time; ?>
                                <?php if ($is_me): ?>
                                    <span class="material-symbols-outlined text-primary text-sm">done_all</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </main>

        <!-- Footer -->
        <footer class="p-4 md:p-6 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-t border-slate-200/50 dark:border-slate-800/50">
            <div class="flex gap-2 overflow-x-auto hide-scrollbar mb-4 pb-2">
                <?php foreach ($quick_replies as $reply): ?>
                    <button class="whitespace-nowrap px-5 py-2 rounded-full glass hover:bg-primary/10 hover:border-primary/30 transition-all text-sm font-medium text-slate-700 dark:text-slate-300 border border-slate-200/50"
                            onclick="sendQuickReply('<?php echo addslashes(htmlspecialchars($reply)); ?>')">
                        <?php echo htmlspecialchars($reply); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="flex items-center gap-3 glass p-2 rounded-2xl shadow-inner bg-slate-50/50 dark:bg-slate-800/50">
                <input type="file" id="mediaInput" accept="image/*,video/mp4,video/quicktime" style="display:none;" onchange="handleMediaUpload()">
                <button class="size-10 flex items-center justify-center rounded-xl hover:bg-slate-200/50 dark:hover:bg-slate-700/50 transition-colors text-slate-500"
                        onclick="document.getElementById('mediaInput').click()" title="Send photo or video">
                    <span class="material-symbols-outlined">add_circle</span>
                </button>
                <input type="text" id="msgInput"
                       class="flex-1 bg-transparent border-none focus:ring-0 text-slate-700 dark:text-slate-200 placeholder:text-slate-400 font-medium py-2"
                       placeholder="Type a message..." autocomplete="off"/>
                <div class="flex items-center gap-1">
                    <button class="size-10 hidden sm:flex items-center justify-center rounded-xl hover:bg-slate-200/50 dark:hover:bg-slate-700/50 transition-colors text-slate-500">
                        <span class="material-symbols-outlined">mood</span>
                    </button>
                    <button class="size-10 flex items-center justify-center rounded-xl chat-gradient text-white shadow-lg hover:scale-105 transition-transform active:scale-95"
                            onclick="sendMessage()" id="sendBtn">
                        <span class="material-symbols-outlined">send</span>
                    </button>
                </div>
            </div>
        </footer>
    </div>
</div>

<script>
    var socketUrl = (window.location.hostname === "localhost" || window.location.hostname === "127.0.0.1")
        ? "http://localhost:3001"
        : "https://abilisto-chat.onrender.com";

    const socket = io(socketUrl, {
        transports: ['websocket'], secure: true, reconnection: true
    });

    const bookingId    = "<?php echo $booking_id; ?>";
    const userId       = "<?php echo $user_id; ?>";
    const userName     = "<?php echo addslashes(htmlspecialchars($user_name)); ?>";
    const otherNameJs  = "<?php echo addslashes(htmlspecialchars($otherName)); ?>";
    // ✅ Pass correct avatar URL to JS for dynamic bubbles
    const otherAvatarJs = "<?php echo addslashes($otherAvatar); ?>";

    socket.on("connect", () => {
        socket.emit("join_room", bookingId);
        document.getElementById("loader-overlay").style.display = "none";
        document.getElementById("connection-status").innerHTML =
            '<span class="size-2 bg-green-400 rounded-full"></span> Online';
    });

    socket.on("disconnect", () => {
        document.getElementById("connection-status").innerHTML =
            '<span class="size-2 bg-yellow-400 rounded-full"></span> Offline';
    });

    // ✅ FIXED: no chat_push.php, saves via save_message.php
    function sendMessage() {
        const input = document.getElementById("msgInput");
        const msg = input.value.trim();
        if (!msg) return;

        socket.emit("send_message", {
            booking_id:     bookingId,
            sender_id:      userId,
            sender_name:    userName,
            message:        msg,
            attachment_url: null,
            message_type:   'text'
        });

        input.value = "";
        hideEmptyState();

        fetch('api/save_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                booking_id:   bookingId,
                sender_id:    userId,
                message:      msg,
                message_type: 'text'
            })
        });
    }
    window.sendMessage = sendMessage;

    // ✅ FIXED: no chat_push.php, saves via save_message.php
    function sendQuickReply(text) {
        socket.emit("send_message", {
            booking_id:     bookingId,
            sender_id:      userId,
            sender_name:    userName,
            message:        text,
            attachment_url: null,
            message_type:   'text'
        });
        hideEmptyState();

        fetch('api/save_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                booking_id:   bookingId,
                sender_id:    userId,
                message:      text,
                message_type: 'text'
            })
        });
    }
    window.sendQuickReply = sendQuickReply;

    function handleMediaUpload() {
        const file = document.getElementById("mediaInput").files[0];
        if (!file) return;

        if (file.size > 20 * 1024 * 1024) {
            alert("File too large. Max 20MB.");
            return;
        }

        const tempId = 'temp-' + Date.now();
        appendBubble({ is_me: true, type: 'uploading', temp_id: tempId });

        const formData = new FormData();
        formData.append('file', file);
        formData.append('booking_id', bookingId);

        fetch('upload.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                document.getElementById(tempId)?.remove();
                if (!data.success) { alert('Upload failed: ' + data.error); return; }
                socket.emit("send_message", {
                    booking_id:     bookingId,
                    sender_id:      userId,
                    sender_name:    userName,
                    message:        '',
                    attachment_url: data.url,
                    message_type:   data.type
                });
                hideEmptyState();
            })
            .catch(() => {
                document.getElementById(tempId)?.remove();
                alert('Upload failed. Please check your connection.');
            });

        document.getElementById("mediaInput").value = '';
    }
    window.handleMediaUpload = handleMediaUpload;

    socket.on("receive_message", (data) => {
        const isMe = (String(data.sender_id) === String(userId));
        appendBubble({
            is_me:          isMe,
            sender_name:    data.sender_name,
            message:        data.message,
            attachment_url: data.attachment_url,
            type:           data.message_type || 'text',
            time:           data.timestamp
                ? new Date(data.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                : 'Just now'
        });
    });

    function appendBubble(opts) {
        const chatBox = document.getElementById("chatBox");
        const isMe = opts.is_me;

        if (opts.type === 'uploading') {
            chatBox.insertAdjacentHTML('beforeend', `
                <div class="flex justify-end max-w-[85%] md:max-w-[70%] ml-auto" id="${opts.temp_id}">
                    <div class="glass p-4 rounded-2xl rounded-br-none chat-gradient text-white shadow-sm opacity-70">
                        <span class="material-symbols-outlined animate-spin text-sm">progress_activity</span> Uploading...
                    </div>
                </div>`);
            scrollToBottom();
            return;
        }

        hideEmptyState();
        const time = opts.time || 'Just now';
        const senderDisplay = !isMe
            ? `<strong class="block mb-1 text-primary text-xs">${escapeHtml(opts.sender_name || otherNameJs)}</strong>`
            : '';

        let bodyHtml = '';
        if (opts.type === 'image' && opts.attachment_url) {
            bodyHtml = `<div class="glass p-2 rounded-2xl ${isMe ? 'rounded-br-none' : 'rounded-bl-none'} shadow-sm attachment-bubble">
                            <img src="${escapeHtml(opts.attachment_url)}" class="chat-image rounded-xl"
                                 onclick="window.open('${escapeHtml(opts.attachment_url)}','_blank')" alt="Image">
                        </div>`;
        } else if (opts.type === 'video' && opts.attachment_url) {
            bodyHtml = `<div class="glass p-2 rounded-2xl ${isMe ? 'rounded-br-none' : 'rounded-bl-none'} shadow-sm attachment-bubble">
                            <video class="chat-video rounded-xl" controls><source src="${escapeHtml(opts.attachment_url)}"></video>
                        </div>`;
        } else {
            bodyHtml = `<div class="glass p-4 rounded-2xl ${isMe ? 'rounded-br-none chat-gradient text-white' : 'rounded-bl-none text-slate-800 dark:text-slate-200'} shadow-sm leading-relaxed message-bubble-text">
                            ${senderDisplay}
                            ${escapeHtml(opts.message || '')}
                        </div>`;
        }

        const readReceipt = isMe
            ? '<span class="material-symbols-outlined text-primary text-sm">done_all</span>'
            : '';

        // ✅ FIXED: uses otherAvatarJs instead of hardcoded PHP string
        const avatarHtml = !isMe
            ? `<div class="size-8 rounded-full bg-cover bg-center shrink-0 shadow-sm"
                    style="background-image: url('${otherAvatarJs}')"></div>`
            : '';

        chatBox.insertAdjacentHTML('beforeend', `
            <div class="flex ${isMe ? 'justify-end' : 'items-end gap-3'} max-w-[85%] md:max-w-[70%] ${isMe ? 'ml-auto' : ''}">
                ${avatarHtml}
                <div class="flex flex-col gap-1 ${isMe ? 'items-end' : ''} w-full">
                    ${bodyHtml}
                    <span class="text-[10px] text-slate-400 font-medium px-1 uppercase tracking-wider flex items-center gap-1 ${isMe ? 'justify-end' : ''}">
                        ${time} ${readReceipt}
                    </span>
                </div>
            </div>`);
        scrollToBottom();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"]/g, m =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
    }

    function hideEmptyState() {
        const el = document.getElementById("emptyState");
        if (el) el.style.display = "none";
    }

    function scrollToBottom() {
        const box = document.getElementById("chatBox");
        box.scrollTop = box.scrollHeight;
    }

    window.addEventListener('load', scrollToBottom);

    document.getElementById("msgInput").addEventListener("keypress", (e) => {
        if (e.key === "Enter") { e.preventDefault(); sendMessage(); }
    });
</script>
</body>
</html>