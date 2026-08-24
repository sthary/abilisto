<?php
// includes/abilisto_page_shell.php
// Some POST handlers reject the request and respond with nothing but a
// bare <script>abilistoAlert(...)</script> before exit() — no
// <!DOCTYPE>/<head>/viewport meta tag at all, since no normal page markup
// is ever reached. Mobile browsers then fall back to a ~980px desktop
// viewport for that response, rendering the (otherwise correctly-sized)
// modal tiny and zoomed out.
//
// Usage at those call sites — before including abilisto_alert.php, so the
// viewport meta lands ahead of the modal's own markup in the response:
//   include '../includes/abilisto_page_shell.php'; abilistoAlertPageOpen();
//   include '../includes/abilisto_alert.php';
//   echo "<script>abilistoAlert('...')</script></body></html>";
//   exit();
if (!function_exists('abilistoAlertPageOpen')) {
    function abilistoAlertPageOpen(): void {
        $dark = "if(localStorage.getItem('theme')==='dark'){document.documentElement.classList.add('dark');}";
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script>' . $dark . '</script></head><body>';
    }
}
