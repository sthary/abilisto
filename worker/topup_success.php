<?php
// worker/topup_success.php
// Landing page after a worker is redirected back from PayMongo's hosted
// checkout for a wallet top-up.
//
// SECURITY: this URL proves nothing on its own — anyone logged in as this
// worker can navigate here with their own topup_id without ever paying.
// The credit below is gated on paymongoRetrieveCheckoutSession() confirming
// the session is actually paid, and fails CLOSED if that can't be confirmed.
// WalletManager::processTopUp() is additionally idempotent (atomically
// claims the 'pending' row), so this is safe to run whether or not the
// webhook already processed the same payment, and safe against reload/back-
// button replay.

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';
include '../includes/functions/paymongo_client.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$wallet = new WalletManager($conn);

$topup_id = intval($_GET['topup_id'] ?? 0);

if (!$topup_id) {
    header("Location: topup.php");
    exit();
}

$check_sql = "SELECT * FROM top_ups WHERE id = ? AND worker_id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->execute([$topup_id, $worker_id]);
$topup = $check_stmt->fetch();

if (!$topup) {
    header("Location: topup.php?error=invalid");
    exit();
}

if ($topup['status'] === 'completed') {
    // Already processed — just show the success screen below.
    $result = ['success' => true];
} else {
    // ── Verify with PayMongo that the Checkout Session is actually PAID ───
    $link_id = $topup['reference_number'] ?? null; // holds the PayMongo checkout session id after creation
    $verify  = $link_id ? paymongoRetrieveCheckoutSession($link_id) : ['success' => false, 'paid' => false, 'message' => 'Missing gateway reference'];

    if (!$verify['success'] || !$verify['paid']) {
        // Fail CLOSED — do not credit anything on a failed/uncertain check.
        error_log("topup_success: verification failed for top_up #$topup_id, link $link_id: " . ($verify['message'] ?? ''));
        header("Location: topup.php?error=not_confirmed");
        exit();
    }

    $result = $wallet->processTopUp($worker_id, $topup['amount'], $topup_id, $link_id);
}

$amount = $topup['amount'];

if ($result['success']) {
    sendNotification($conn, $worker_id,
        "💰 Wallet Top-Up Successful!\n\n₱$amount has been added to your wallet.",
        "wallet.php"
    );
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Top-Up Successful | Abilisto</title>
        <link rel="stylesheet" href="../css/style.css">
        <style>
            .success-container { max-width: 500px; margin: 100px auto; text-align: center; padding: 40px; background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .success-icon { font-size: 5rem; color: #28a745; margin-bottom: 20px; }
            .amount-highlight { font-size: 2.5rem; font-weight: bold; color: #28a745; margin: 20px 0; }
            .btn { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 8px; margin: 10px; }
            .btn-secondary { background: #6c757d; }
        </style>
    </head>
    <body>
        <?php include '../includes/navbar.php'; ?>

        <div class="success-container">
            <div class="success-icon">✅</div>
            <h1>Top-Up Successful!</h1>
            <p>The following amount has been added to your wallet:</p>
            <div class="amount-highlight">₱<?php echo number_format($amount, 2); ?></div>

            <div style="margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                <p><strong>New Balance:</strong> ₱<?php
                    $new_wallet = $wallet->getWorkerWallet($worker_id);
                    echo number_format($new_wallet['wallet_balance'], 2);
                ?></p>
                <p><strong>Free Credits:</strong> ₱<?php echo number_format($new_wallet['free_credits'], 2); ?></p>
            </div>

            <a href="dashboard.php" class="btn">Go to Dashboard</a>
            <a href="wallet.php" class="btn btn-secondary">View Wallet</a>
        </div>
    </body>
    </html>
    <?php
} else {
    header("Location: topup.php?error=processing");
}
?>