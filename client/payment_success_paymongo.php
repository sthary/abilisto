<?php
// client/payment_success_paymongo.php
// Landing page after a client is redirected back from PayMongo's hosted
// checkout for a MOBILIZATION fee payment.
//
// SECURITY: this URL proves nothing on its own — anyone logged in as a
// client can navigate here for any of their own booking ids without ever
// paying. Every credit below is gated on paymongoRetrieveLink() confirming
// the Link is actually paid, and fails CLOSED (credits nothing) if that
// verification can't be confirmed. holdEscrowPayment() is additionally
// idempotent, so this is safe to run whether or not the webhook already
// processed the same payment.

error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';
include '../includes/functions/paymongo_client.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../auth/login.php");
    exit();
}

$client_id = $_SESSION['user_id'];
$wallet = new WalletManager($conn);

$booking_id = intval($_GET['booking_id'] ?? 0);
if (!$booking_id) {
    header("Location: dashboard.php?error=invalid_booking");
    exit();
}

$check_sql = "SELECT b.*, u.full_name as client_name, u.email
              FROM bookings b
              JOIN users u ON b.client_id = u.id
              WHERE b.id = ? AND b.client_id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->execute([$booking_id, $client_id]);
$booking = $check_stmt->fetch();

if (!$booking) {
    header("Location: dashboard.php?error=not_found");
    exit();
}

$worker_id = $booking['worker_id'];
$amount    = floatval($booking['calculated_fee']);
$error     = null;

if ($amount <= 0) {
    $error = "Invalid booking amount.";
} elseif (!empty($booking['is_escrow'])) {
    // Already processed (very likely by the webhook, possibly by an earlier
    // hit of this same page) — nothing to verify or credit again.
} else {
    // ── Verify with PayMongo that the Link is actually PAID ───────────────
    $link_id = $booking['transaction_id'] ?? null;

    if (!$link_id) {
        $error = "Payment reference missing. Please contact support.";
    } else {
        $verify = paymongoRetrieveLink($link_id);

        if (!$verify['success'] || !$verify['paid']) {
            // Fail CLOSED — do not credit anything on a failed/uncertain check.
            $error = "Payment not yet confirmed by the payment gateway. Please wait a moment and refresh, or check My Bookings.";
            error_log("payment_success_paymongo: verification failed for booking #$booking_id, link $link_id: " . ($verify['message'] ?? ''));
        } else {
            $conn->beginTransaction();
            try {
                $escrow_result = $wallet->holdEscrowPayment($booking_id, $worker_id, $amount);
                if (!$escrow_result['success']) {
                    throw new Exception("Escrow failed: " . ($escrow_result['message'] ?? 'Unknown error'));
                }

                $notif_msg = "💰 New PAID Booking Request!\n\n" .
                             $_SESSION['full_name'] . " has paid ₱$amount via PayMongo.\n" .
                             "Payment is held in escrow. Accept to release payment.";
                sendNotification($conn, $worker_id, $notif_msg, "../worker/dashboard.php");

                $client_notif = "✅ Payment Successful! Your ₱$amount payment is now in escrow.\n" .
                                "The worker will be notified and funds will be released when they accept.";
                sendNotification($conn, $client_id, $client_notif, "../client/my_bookings.php");

                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Processing error: " . $e->getMessage();
                error_log("❌ payment_success_paymongo failed for booking #$booking_id: " . $e->getMessage());
            }
        }
    }
}

$worker_stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE id = ?");
$worker_stmt->execute([$worker_id]);
$worker = $worker_stmt->fetch();
$worker_name = $worker['full_name'] ?? 'Worker';
$worker_pic = !empty($worker['profile_pic']) && file_exists("../uploads/profiles/".$worker['profile_pic'])
    ? "../uploads/profiles/".$worker['profile_pic']
    : "https://ui-avatars.com/api/?name=" . urlencode($worker_name) . "&background=0d59f2&color=fff&size=64&bold=true";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title><?php echo $error ? 'Payment Error' : 'Payment Successful'; ?> | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script id="tailwind-config">
        tailwind.config = { theme: { extend: {
            colors: { "primary": "#146af5", "off-white": "#F8FAFC", "escrow-blue": "#E3F2FD", "dark-text": "#1A1A1A" },
            fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
        } } }
    </script>
    <style type="text/tailwindcss">
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(203, 213, 225, 0.5); }
        .radial-bg { background: radial-gradient(circle at top center, #f0f7ff 0%, #F8FAFC 100%); }
        .ambient-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08); }
    </style>
</head>
<body class="bg-off-white font-display antialiased text-dark-text">
<?php if (!$error) include '../includes/navbar.php'; ?>
<div class="relative min-h-screen w-full overflow-hidden radial-bg flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-[520px] glass-card rounded-3xl p-8 md:p-12 relative z-10 ambient-shadow">
        <div class="flex flex-col items-center text-center">
            <?php if ($error): ?>
                <div class="mb-8"><div class="size-24 rounded-full bg-red-100 flex items-center justify-center text-red-500">
                    <span class="material-symbols-outlined !text-5xl font-bold">error</span>
                </div></div>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2 text-dark-text">Payment Not Confirmed</h1>
                <p class="text-slate-500 text-sm md:text-base mb-8"><?php echo htmlspecialchars($error); ?></p>
            <?php else: ?>
                <div class="mb-8"><div class="size-24 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-500">
                    <span class="material-symbols-outlined !text-5xl font-bold">check_circle</span>
                </div></div>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2 text-dark-text">Payment Successful!</h1>
                <p class="text-slate-500 text-sm md:text-base mb-8">Your transaction has been processed securely.</p>
                <div class="w-full mb-10">
                    <div class="text-5xl font-black text-dark-text mb-6">₱<?php echo number_format($amount, 2); ?></div>
                    <div class="w-full h-px bg-gradient-to-r from-transparent via-slate-200 to-transparent"></div>
                </div>
                <div class="w-full bg-escrow-blue border border-blue-100 rounded-2xl p-6 mb-8 text-left">
                    <div class="flex items-start gap-4">
                        <div class="bg-blue-200 p-2 rounded-lg text-blue-700 shrink-0"><span class="material-symbols-outlined">shield_lock</span></div>
                        <div>
                            <h3 class="font-bold text-blue-900 mb-1">Payment Held in Escrow</h3>
                            <p class="text-blue-800/80 text-sm leading-relaxed">Funds are securely held and will only be released once the worker accepts your booking.</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 bg-slate-100 px-4 py-2 rounded-full border border-slate-200 mb-10">
                    <div class="size-6 rounded-full overflow-hidden bg-slate-200"><img src="<?php echo $worker_pic; ?>" class="w-full h-full object-cover" alt=""></div>
                    <span class="text-xs font-medium text-slate-600">Notifying <?php echo htmlspecialchars($worker_name); ?>...</span>
                </div>
            <?php endif; ?>
            <a href="my_bookings.php" class="w-full py-4 bg-primary hover:bg-blue-600 text-white font-bold rounded-2xl transition-all shadow-[0_8px_30px_rgb(13,89,242,0.2)] flex items-center justify-center gap-2 group">
                <span>View My Bookings</span>
                <span class="material-symbols-outlined transition-transform group-hover:translate-x-1">arrow_forward</span>
            </a>
        </div>
    </div>
</div>
<?php if (!$error): ?>
<script>
    let seconds = 5;
    const t = setInterval(function () {
        seconds--;
        if (seconds <= 0) { clearInterval(t); window.location.href = 'my_bookings.php'; }
    }, 1000);
</script>
<?php endif; ?>
</body>
</html>
