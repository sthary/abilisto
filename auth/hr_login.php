<?php
// auth/hr_login.php
session_start();
include '../db_connect.php';
include 'staff_auth_check.php';

$error = '';
if (isset($_POST['login_btn'])) {
    $error = handleStaffLogin($conn, 'hr', '../admin/hr.php');
}

$staff_role_label = 'HR';
$staff_subtitle   = 'HR Department Portal';
$staff_icon       = 'badge';
$staff_accent     = '#9333ea';

include 'staff_login_template.php';
