<?php
// verification_success.php
require_once '../db.php';

// Check if user just completed verification
if (!isset($_SESSION['verification_completed'])) {
    header('Location: verification.php');
    exit;
}

// Clear verification session
unset($_SESSION['verification']);
unset($_SESSION['verification_step']);
unset($_SESSION['verification_completed']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Submitted - GCash Style</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .success-container {
            max-width: 600px;
            margin: 50px auto;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .checkmark {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #28a745;
            color: white;
            font-size: 50px;
            line-height: 100px;
            margin: 0 auto 30px;
        }
        .btn-gcash {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="checkmark">✓</div>
        <h2>Verification Submitted Successfully!</h2>
        <p class="lead">Your verification request is now being processed.</p>
        <p>We will notify you once your identity has been verified. This usually takes 1-2 business days.</p>
        <a href="dashboard.php" class="btn-gcash">Go to Dashboard</a>
    </div>
</body>
</html>