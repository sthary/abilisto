<?php
// auth/staff_auth_check.php
// Shared login-processing for the admin/finance/hr staff portals. Deliberately
// plain email+password — no phone-verification gate (staff accounts are
// created directly in the database, not through the client/worker
// signup+OTP flow, so there's no reason to require a phone number here) and
// no Google OAuth (not needed for staff).

require_once __DIR__ . '/../includes/functions/identify_user.php';

function handleStaffLogin($conn, $expectedRole, $dashboardUrl) {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    $user = findUserByIdentifier($conn, $identifier);

    if (!$user) {
        return "User not found!";
    }

    if (empty($user['password']) || !password_verify($password, $user['password'])) {
        return "Incorrect Password!";
    }

    if ($user['role'] !== $expectedRole) {
        $portal_names = ['admin' => 'Admin', 'hr' => 'HR', 'finance' => 'Finance'];
        $actual   = $portal_names[$user['role']]   ?? ucfirst($user['role']);
        $expected = $portal_names[$expectedRole]   ?? ucfirst($expectedRole);
        return "This account is registered as $actual, not $expected. Please use the $actual login page instead.";
    }

    $_SESSION['user_id']           = $user['id'];
    $_SESSION['full_name']         = $user['full_name'];
    $_SESSION['email']             = $user['email'];
    $_SESSION['phone']             = $user['phone'];
    $_SESSION['role']              = $user['role'];
    $_SESSION['municipality']      = $user['municipality'];
    $_SESSION['latitude']          = $user['latitude'];
    $_SESSION['longitude']         = $user['longitude'];
    $_SESSION['is_email_verified'] = $user['is_email_verified'];
    $_SESSION['is_phone_verified'] = $user['is_phone_verified'];

    header("Location: $dashboardUrl");
    exit();
}
?>
