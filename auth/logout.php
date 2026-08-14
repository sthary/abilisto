<?php
// auth/logout.php
session_start();
session_destroy();
header("Location: /auth/login.php"); // Redirect to Landing Page
exit();
?>