<?php
// auth/admin_login.php
session_start();
include '../db_connect.php';
include 'staff_auth_check.php';

$error = '';
if (isset($_POST['login_btn'])) {
    $error = handleStaffLogin($conn, 'admin', '../admin/dashboard.php');
}

$staff_role_label = 'Admin';
$staff_subtitle   = 'Support Admin Portal';
$staff_icon       = 'admin_panel_settings';
$staff_accent     = '#146af5';

include 'staff_login_template.php';
