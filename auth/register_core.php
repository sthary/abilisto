<?php
// auth/register_core.php
session_start();
include '../db.php'; 
include '../includes/mailer.php';

if (isset($_POST['register_btn'])) {

    $role       = $_POST['role'];
    $full_name  = $_POST['full_name'];
    $email      = $_POST['email'];
    $password   = password_hash($_POST['password'], PASSWORD_DEFAULT); 
    $phone      = $_POST['phone'];
    $street     = isset($_POST['street'])   ? $_POST['street']   : '';
    $barangay   = isset($_POST['barangay']) ? $_POST['barangay'] : '';
    $municipality = $_POST['municipality'];
    $lat        = isset($_POST['latitude'])  ? $_POST['latitude']  : '';
    $lng        = isset($_POST['longitude']) ? $_POST['longitude'] : '';

    // Build address
    $address_parts = [];
    if (!empty($barangay))    $address_parts[] = $barangay;
    if (!empty($street))      $address_parts[] = $street;
    if (!empty($municipality)) $address_parts[] = $municipality . ", Surigao del Sur";
    $full_address = !empty($address_parts)
        ? implode(', ', $address_parts)
        : $municipality . ", Surigao del Sur";

    // ============================================================
    // DUPLICATE CHECK — before attempting insert
    // ============================================================
    $stmt_check = $conn->prepare(
        "SELECT 
            (SELECT COUNT(*) FROM users WHERE email = ?) AS email_count,
            (SELECT COUNT(*) FROM users WHERE phone = ?) AS phone_count"
    );
    $stmt_check->bind_param("ss", $email, $phone);
    $stmt_check->execute();
    $dup = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if ($dup['email_count'] > 0) {
        $_SESSION['signup_error'] = 'email_taken';
        header("Location: signup_form.php?role=$role");
        exit();
    }
    if ($dup['phone_count'] > 0) {
        $_SESSION['signup_error'] = 'phone_taken';
        header("Location: signup_form.php?role=$role");
        exit();
    }

    // ============================================================
    // INSERT USER
    // FIX: is_email_verified = 0 (only Google sets this to 1)
    // ============================================================
    $email_token = bin2hex(random_bytes(32));

    $stmt = $conn->prepare(
        "INSERT INTO users (
            full_name, email, password, phone, address, municipality, role,
            latitude, longitude, verification_token, is_email_verified, is_phone_verified
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)"
    );

    if ($stmt === false) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ssssssssss",
        $full_name, $email, $password, $phone,
        $full_address, $municipality, $role,
        $lat, $lng, $email_token
    );

    if ($stmt->execute() === TRUE) {
        $user_id = $stmt->insert_id;
        $stmt->close();

        // Worker profile
        if ($role == 'worker') {
            $category = isset($_POST['service_category']) ? $_POST['service_category'] : '';
            if (!empty($category)) {
                $stmt_worker = $conn->prepare(
                    "INSERT INTO worker_profiles (user_id, service_category) VALUES (?, ?)"
                );
                if ($stmt_worker) {
                    $stmt_worker->bind_param("is", $user_id, $category);
                    $stmt_worker->execute();
                    $stmt_worker->close();
                }
            }
        }

        // FIX: Do NOT send OTP here — verify_otp.php has the "Send Code" button
        // Just store email in session and redirect
        $_SESSION['email'] = $email;
        $_SESSION['flash_success'] = "✅ Account created! Please verify your phone number.";

        header("Location: verify_otp.php?email=" . urlencode($email));
        exit();

    } else {
        // Catch any other DB errors gracefully
        $db_error = $conn->error;
        if (isset($stmt)) $stmt->close();

        // Catch duplicate key errors that slipped past our check (race condition)
        if (strpos($db_error, 'email') !== false) {
            $_SESSION['signup_error'] = 'email_taken';
        } elseif (strpos($db_error, 'phone') !== false) {
            $_SESSION['signup_error'] = 'phone_taken';
        } else {
            $_SESSION['signup_error'] = 'generic';
            $_SESSION['signup_error_msg'] = $db_error;
        }
        header("Location: signup_form.php?role=$role");
        exit();
    }
}
?>