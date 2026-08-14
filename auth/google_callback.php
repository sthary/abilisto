<?php
// auth/google_callback.php
session_start();
include '../db.php';
include 'google_config.php';

if (isset($_GET['code'])) {

    // --- PART A: Exchange Code for Token ---
    $token_url = 'https://oauth2.googleapis.com/token';
    $data = [
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URL,
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response   = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);

    if (!isset($token_data['access_token'])) {
        die("Error fetching token from Google. Check your keys.");
    }

    // --- PART B: Get User Profile ---
    $user_info_url = 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $token_data['access_token'];

    $ch        = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_info_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $user_info = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $g_id      = $user_info['id'];
    $g_email   = $user_info['email'];
    $g_name    = $user_info['name'];
    $g_picture = $user_info['picture'];

    // --- PART C: FIX ISSUE 2 — Read role from state param, fallback to session ---
    // In google_config.php, your $google_login_url must append &state=<role>
    // e.g. $google_login_url = "https://accounts.google.com/o/oauth2/auth?...&state=" . urlencode($role);
    $role = 'client'; // safe default
    if (isset($_GET['state']) && in_array($_GET['state'], ['client', 'worker'])) {
        $role = $_GET['state'];
    } elseif (isset($_SESSION['signup_role']) && in_array($_SESSION['signup_role'], ['client', 'worker'])) {
        // Fallback: role stored in session before Google redirect
        $role = $_SESSION['signup_role'];
    }

    // --- PART D: Check if email already exists ---
    $stmt_check = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt_check->bind_param("s", $g_email);
    $stmt_check->execute();
    $existing = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();

    if ($existing) {
        // --- EXISTING USER: Link Google account if not already linked ---
        // FIX ISSUE 1: Mark only email as verified; leave is_phone_verified untouched
        if (empty($existing['google_id'])) {
            $stmt_link = $conn->prepare(
                "UPDATE users SET google_id = ?, avatar = ?, is_email_verified = 1 WHERE id = ?"
            );
            $stmt_link->bind_param("ssi", $g_id, $g_picture, $existing['id']);
            $stmt_link->execute();
            $stmt_link->close();
        }

        // FIX ISSUE 3: Do NOT create a login session here.
        // Store a temporary token so login.php can auto-fill / confirm identity.
        $temp_token = bin2hex(random_bytes(32));
        $stmt_token = $conn->prepare(
            "UPDATE users SET google_temp_token = ?, google_temp_token_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?"
        );
        $stmt_token->bind_param("si", $temp_token, $existing['id']);
        $stmt_token->execute();
        $stmt_token->close();

        // Redirect to login with the token — login.php will verify and create the real session
        $_SESSION['flash_info'] = "✅ Google account verified! Please confirm your login below.";
        header("Location: login.php?google_token=" . urlencode($temp_token));
        exit();

    } else {
        // --- NEW USER: Insert with correct role and email verified, phone NOT verified ---
        // FIX ISSUE 1: is_email_verified=1, is_phone_verified=0 (default)
        // FIX ISSUE 2: use $role from state param, not hardcoded 'client'
        $stmt_insert = $conn->prepare(
            "INSERT INTO users (full_name, email, google_id, avatar, role, password, is_email_verified, is_phone_verified)
             VALUES (?, ?, ?, ?, ?, NULL, 1, 0)"
        );
        $stmt_insert->bind_param("sssss", $g_name, $g_email, $g_id, $g_picture, $role);

        if ($stmt_insert->execute()) {
            $new_id = $stmt_insert->insert_id;
            $stmt_insert->close();

            // FIX ISSUE 3: Do NOT log them in yet. Store a temp token for login.php.
            $temp_token = bin2hex(random_bytes(32));
            $stmt_token = $conn->prepare(
                "UPDATE users SET google_temp_token = ?, google_temp_token_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?"
            );
            $stmt_token->bind_param("si", $temp_token, $new_id);
            $stmt_token->execute();
            $stmt_token->close();

            $_SESSION['flash_success'] = "🎉 Account created with Google! Please log in to continue.";
            header("Location: login.php?google_token=" . urlencode($temp_token));
            exit();

        } else {
            die("Database Error: " . $conn->error);
        }
    }
}
?>