<?php
// includes/email_sender.php

require_once __DIR__ . '/../config/dotenv.php';

function sendAbilistoEmail($to_email, $subject, $html_body) {
    $api_key = getenv('RESEND_API_KEY');
    
    // 2. Set your sender email (MUST match your verified domain)
    $from_email = 'Abilisto Support <no-reply@abilisto.site>';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    
    // Build the email payload
    $payload = [
        'from' => $from_email,
        'to' => [$to_email],
        'subject' => $subject,
        'html' => $html_body
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    // Set the headers
    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log('Resend Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);

    // Resend returns a 200 OK status if successful
    if ($http_code == 200) {
        return true;
    } else {
        error_log('Resend API Error: ' . $response);
        return false;
    }
}
?>