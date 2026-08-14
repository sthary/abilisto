<?php
// worker/process_topup_xendit.php
// Process top-up via GCash

include '../db.php';
include '../includes/init_lang.php';
include '../config/constants.php';

// Security Check
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

// Get worker details
$worker = $conn->query("SELECT full_name, email FROM users WHERE id = $worker_id")->fetch_assoc();

// Create top-up record (pending)
$ref = "TOPUP-" . time() . "-" . rand(1000, 9999);
$insert_sql = "INSERT INTO top_ups (worker_id, amount, payment_method, reference_number, status) 
               VALUES ($worker_id, $amount, 'gcash', '$ref', 'pending')";
$conn->query($insert_sql);
$topup_id = $conn->insert_id;

// Prepare Xendit API Data
$external_id = 'TOPUP-' . time() . '-' . $topup_id . '-' . rand(100, 999);

$secret_key = getenv('XENDIT_SECRET_KEY');

$data = [
    'external_id' => $external_id,
    'amount' => $amount,
    'description' => 'Wallet Top-Up for Worker #' . $worker_id,
    'invoice_duration' => 86400,
    'currency' => 'PHP',
    'customer' => [
        'given_names' => $worker['full_name'],
        'email' => $worker['email']
    ],
    'success_redirect_url' => 'http://abilisto.site/worker/topup_success.php?topup_id=' . $topup_id,
    'failure_redirect_url' => 'http://abilisto.site/worker/wallet.php?failed=1',
    'metadata' => [
        'topup_id' => $topup_id,
        'worker_id' => $worker_id,
        'type' => 'wallet_topup'
    ]
];

// Call Xendit API
$ch = curl_init('https://api.xendit.co/v2/invoices');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($secret_key . ':')
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code == 200 && isset($result['invoice_url'])) {
    // Update top-up with transaction ID
    $conn->query("UPDATE top_ups 
                  SET reference_number = '{$result['id']}' 
                  WHERE id = $topup_id");
    
    // Redirect to Xendit checkout
    header("Location: " . $result['invoice_url']);
    exit();
} else {
    // Log error
    error_log("Xendit Top-Up Error: " . print_r($result, true));
    
    $_SESSION['error'] = "Payment gateway error. Please try again later.";
    header("Location: topup.php");
    exit();
}
?>