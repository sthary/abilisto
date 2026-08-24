<?php
// auth/enforce_phone.php

// 1. Check if Phone is Verified
if (isset($_SESSION['is_phone_verified']) && $_SESSION['is_phone_verified'] == 0) {
    
    // 2. Allow them to access the OTP page, but block everything else
    $current_page = basename($_SERVER['PHP_SELF']);
    
    if ($current_page != 'verify_otp.php' && $current_page != 'add_phone_google.php') {
        include __DIR__ . '/../includes/abilisto_page_shell.php';
        abilistoAlertPageOpen();
        include __DIR__ . '/../includes/abilisto_alert.php';
        echo "<script>
                abilistoAlert('⚠️ Security Alert: You must verify your phone number to use Abilisto.').then(function(){
                    window.location.href = '../auth/verify_otp.php';
                });
              </script></body></html>";
        exit();
    }
}
?>