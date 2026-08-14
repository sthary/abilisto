<?php
// auth/resend_otp.php
session_start();
include '../db.php';
include '../includes/sms_sender.php';

$email = isset($_GET['email']) ? $_GET['email'] : '';

if ($email) {
    // 1. Check if user exists (prepared statement)
    $sql = "SELECT phone, full_name FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stmt->close();

        if (empty($user['phone'])) {
            // If no phone number, redirect to verify_otp.php to prompt for phone
            echo "<script>
                    alert('Please provide your phone number first.');
                    window.location.href='verify_otp.php?email=" . urlencode($email) . "';
                  </script>";
            exit();
        }

        // 2. Send OTP via API (same as register_core.php)
        $otp_response = sendOTP($user['phone']);

        // 3. Dev Hint: Capture OTP code from response to show in alert
        $dev_otp_display = "";
        if (isset($otp_response['data']['otp_code'])) {
            $dev_otp_display = $otp_response['data']['otp_code'];
        }

        echo "<script>
                alert('✅ New code sent to " . $user['phone'] . ".\\n(Dev Hint: " . $dev_otp_display . ")');
                window.location.href='verify_otp.php?email=" . urlencode($email) . "';
              </script>";
    } else {
        $stmt->close();
        echo "User not found.";
    }
} else {
    header("Location: login.php");
}
?>