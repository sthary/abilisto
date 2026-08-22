<?php
// worker/process_topup_paymongo.php
// Creates a pending top_ups row and redirects the worker to a PayMongo
// Payment Link for it.

include '../db_connect.php';
include '../includes/init_lang.php';
include '../config/constants.php';
require_once '../includes/functions/paymongo_client.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$amount = floatval($_GET['amount'] ?? 0);

if ($amount < MIN_TOPUP || $amount > MAX_TOPUP) {
    $_SESSION['error'] = "Invalid amount";
    header("Location: topup.php");
    exit();
}

$worker_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
$worker_stmt->execute([$worker_id]);
$worker = $worker_stmt->fetch();

$ref = "TOPUP-" . time() . "-" . rand(1000, 9999);
$insert_sql = "INSERT INTO top_ups (worker_id, amount, payment_method, reference_number, status)
               VALUES (?, ?, 'gcash', ?, 'pending')
               RETURNING id";
$insert_stmt = $conn->prepare($insert_sql);
$insert_stmt->execute([$worker_id, $amount, $ref]);
$topup_id = $insert_stmt->fetchColumn();

$reference_number = 'TOPUP-' . time() . '-' . $topup_id . '-' . rand(100, 999);

$link = paymongoCreateLink(
    $amount,
    'Wallet Top-Up for Worker #' . $worker_id,
    $reference_number,
    ['topup_id' => $topup_id, 'worker_id' => $worker_id, 'type' => 'wallet_topup']
);

if ($link['success']) {
    $conn->prepare("UPDATE top_ups SET reference_number = ? WHERE id = ?")
         ->execute([$link['id'], $topup_id]);

    header("Location: " . $link['checkout_url']);
    exit();
} else {
    error_log("PayMongo Top-Up Error: " . $link['message']);
    $_SESSION['error'] = "Payment gateway error. Please try again later.";
    header("Location: topup.php");
    exit();
}
?>