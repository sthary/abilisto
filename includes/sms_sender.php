<?php
// includes/sms_sender.php

require_once __DIR__ . '/../config/env.php';

define('IPROG_API_TOKEN', getenv('IPROG_API_TOKEN'));

// --- FUNCTION 1: SEND OTP ---
function sendOTP($phone_number) {
    $url = "https://www.iprogsms.com/api/v1/otp/send_otp";
    
    // 1. Prepare Data (JSON)
    $data = [
        "api_token" => IPROG_API_TOKEN,
        "phone_number" => $phone_number,
        // Optional: Custom message. Leave empty to use default.
        // "message" => "Abilisto Code: :otp" 
    ];

    // 2. Send Request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // Send as JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)

    $response = curl_exec($ch);
    curl_close($ch);

    // 3. Log for Debugging
    file_put_contents("../logs/sms_logs.txt", "[SEND] $phone_number | $response" . PHP_EOL, FILE_APPEND);

    return json_decode($response, true); // Return result as Array
}

// --- FUNCTION 2: VERIFY OTP ---
function verifyOTP($phone_number, $otp_input) {
    $url = "https://www.iprogsms.com/api/v1/otp/verify_otp";
    
    $data = [
        "api_token" => IPROG_API_TOKEN,
        "phone_number" => $phone_number,
        "otp" => $otp_input
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)

    $response = curl_exec($ch);
    curl_close($ch);

    file_put_contents("../logs/sms_logs.txt", "[VERIFY] $phone_number | $response" . PHP_EOL, FILE_APPEND);

    return json_decode($response, true);
}
?>