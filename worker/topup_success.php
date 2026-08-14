<?php
// worker/topup_success.php
// Handle successful top-up callback

include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/functions/wallet_manager.php';

// Security Check
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

// Verify top-up belongs to this worker and is pending
$check_sql = "SELECT * FROM top_ups
              WHERE id = ? AND worker_id = ? AND status = 'pending'";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->execute([$topup_id, $worker_id]);
$topup = $check_stmt->fetch();

if (!$topup) {
    header("Location: topup.php?error=invalid");
    exit();
}

$amount = $topup['amount'];

// Process the top-up
$result = $wallet->processTopUp($worker_id, $amount, 'gcash', $topup['reference_number']);

if ($result['success']) {
    // Send notification
    sendNotification($conn, $worker_id, 
        "💰 Wallet Top-Up Successful!\n\n₱$amount has been added to your wallet.", 
        "wallet.php"
    );
    
    // Show success page
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
    // Show error
    header("Location: topup.php?error=processing");
}
?>