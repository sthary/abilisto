<?php
// auth/resend_otp.php
session_start();
include '../db_connect.php';
include '../includes/sms_sender.php';

$email = isset($_GET['email']) ? $_GET['email'] : '';

if ($email) {
    // 1. Check if user exists (prepared statement)
    $sql = "SELECT phone, full_name FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$email]);

    if ($user = $stmt->fetch()) {
        if (empty($user['phone'])) {
            // If no phone number, redirect to verify_otp.php to prompt for phone
            include '../includes/abilisto_page_shell.php';
            abilistoAlertPageOpen();
            include '../includes/abilisto_alert.php';
            echo "<script>
                    abilistoAlert('Please provide your phone number first.').then(function(){
                        window.location.href='verify_otp.php?email=" . urlencode($email) . "';
                    });
                  </script></body></html>";
            exit();
        }

        // 2. Send OTP via API (same as register_core.php)
        $otp_response = sendOTP($user['phone']);

        // 3. Dev Hint: Capture OTP code from response to show in alert
        $dev_otp_display = "";
        if (isset($otp_response['data']['otp_code'])) {
            $dev_otp_display = $otp_response['data']['otp_code'];
        }

        include '../includes/abilisto_page_shell.php';
        abilistoAlertPageOpen();
        include '../includes/abilisto_alert.php';
        echo "<script>
                abilistoAlert('✅ New code sent to " . $user['phone'] . ".\\n(Dev Hint: " . $dev_otp_display . ")').then(function(){
                    window.location.href='verify_otp.php?email=" . urlencode($email) . "';
                });
              </script></body></html>";
    } else {
        echo "User not found.";
    }
} else {
    header("Location: login.php");
}
?>