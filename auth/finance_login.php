<?php
// auth/finance_login.php
session_start();
include '../db_connect.php';
include 'staff_auth_check.php';

$error = '';
if (isset($_POST['login_btn'])) {
    $error = handleStaffLogin($conn, 'finance', '../admin/finance.php');
}

$staff_role_label = 'Finance';
$staff_subtitle   = 'Finance Department Portal';
$staff_icon       = 'account_balance';
$staff_accent     = '#16a34a';

include 'staff_login_template.php';
