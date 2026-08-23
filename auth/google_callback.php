<?php
// auth/google_callback.php
session_start();
include '../db_connect.php';
include 'google_config.php';

if (isset($_GET['code'])) {

    // Google's authorization codes are single-use. If this callback gets
    // invoked twice for the same code (browser retry, back-button, a
    // duplicate navigation on the extra redirect hop first-time consent
    // goes through) the second attempt always fails — either Google
    // rejects the reused code with invalid_grant, or if both requests
    // race past the token exchange, the second one hits the UNIQUE
    // constraint on users.email trying to insert the same new signup
    // twice (uncaught, since nothing here catches PDO exceptions — a
    // real HTTP 500). Short-circuit any repeat of the same code by
    // replaying the first request's successful redirect instead of
    // redoing any of this work.
    $replay_key = 'google_oauth_redirect_' . md5($_GET['code']);
    if (isset($_SESSION[$replay_key])) {
        header("Location: " . $_SESSION[$replay_key]);
        exit();
    }

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
    $response   = curl_exec($ch);
    $token_data = json_decode($response, true);
    curl_close($ch);

    if (!isset($token_data['access_token'])) {
        // Log Google's actual reason instead of guessing — the generic
        // message on screen doesn't say why, which made this undiagnosable.
        error_log("Google OAuth token exchange failed. HTTP response: " . $response);
        $g_error  = $token_data['error'] ?? 'unknown_error';
        $g_detail = $token_data['error_description'] ?? 'No details returned by Google.';
        die("Error fetching token from Google ($g_error): $g_detail");
    }

    // --- PART B: Get User Profile ---
    $user_info_url = 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $token_data['access_token'];

    $ch        = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_info_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
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
    $stmt_check->execute([$g_email]);
    $existing = $stmt_check->fetch();

    if ($existing) {
        // --- EXISTING USER: Link Google account if not already linked ---
        // FIX ISSUE 1: Mark only email as verified; leave is_phone_verified untouched
        if (empty($existing['google_id'])) {
            $stmt_link = $conn->prepare(
                "UPDATE users SET google_id = ?, avatar = ?, is_email_verified = TRUE WHERE id = ?"
            );
            $stmt_link->execute([$g_id, $g_picture, $existing['id']]);
        }

        // FIX ISSUE 3: Do NOT create a login session here.
        // Store a temporary token so login.php can auto-fill / confirm identity.
        $temp_token = bin2hex(random_bytes(32));
        $stmt_token = $conn->prepare(
            "UPDATE users SET google_temp_token = ?, google_temp_token_expires = NOW() + INTERVAL '10 minutes' WHERE id = ?"
        );
        $stmt_token->execute([$temp_token, $existing['id']]);

        // Redirect to login with the token — login.php will verify and create the real session
        $_SESSION['flash_info'] = "✅ Google account verified! Please confirm your login below.";
        $redirect_url = "login.php?google_token=" . urlencode($temp_token);
        $_SESSION[$replay_key] = $redirect_url;
        header("Location: " . $redirect_url);
        exit();

    } else {
        // --- NEW USER: Insert with correct role and email verified, phone NOT verified ---
        // FIX ISSUE 1: is_email_verified=1, is_phone_verified=0 (default)
        // FIX ISSUE 2: use $role from state param, not hardcoded 'client'
        // phone, address, and municipality are all NOT NULL with no default
        // in the schema, and Google's 'email profile' scope never returns
        // any of them — every brand new Google signup was failing here
        // with a not-null violation (uncaught, a real HTTP 500) before this
        // even reached the replay guard or invalid_grant could become
        // visible. '' (not NULL) is the value the rest of the app already
        // expects for "not filled in yet": manage_phone.php has a dedicated
        // "Case 3: No phone number (e.g. Google login users)" branch,
        // municipality's CHECK constraint explicitly allows '', and
        // worker/profile_edit.php is where address/municipality get filled
        // in later.
        $stmt_insert = $conn->prepare(
            "INSERT INTO users (full_name, email, google_id, avatar, role, password, phone, address, municipality, is_email_verified, is_phone_verified)
             VALUES (?, ?, ?, ?, ?, NULL, '', '', '', TRUE, FALSE)"
        );

        try {
            $stmt_insert->execute([$g_name, $g_email, $g_id, $g_picture, $role]);
            $new_id = $conn->lastInsertId('users_id_seq');
        } catch (PDOException $e) {
            // The replay guard above should make this unreachable in
            // practice, but if two requests for the same new signup ever
            // do race past it (e.g. no session cookie), don't crash with
            // an uncaught exception on the email UNIQUE constraint —
            // whoever won the race already created the account, so just
            // use it instead of erroring.
            error_log("google_callback: insert race on email $g_email — " . $e->getMessage());
            $stmt_check2 = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_check2->execute([$g_email]);
            $race_winner = $stmt_check2->fetch();
            if (!$race_winner) {
                die("Error creating account. Please try again.");
            }
            $new_id = $race_winner['id'];
        }

        // FIX ISSUE 3: Do NOT log them in yet. Store a temp token for login.php.
        $temp_token = bin2hex(random_bytes(32));
        $stmt_token = $conn->prepare(
            "UPDATE users SET google_temp_token = ?, google_temp_token_expires = NOW() + INTERVAL '10 minutes' WHERE id = ?"
        );
        $stmt_token->execute([$temp_token, $new_id]);

        $_SESSION['flash_success'] = "🎉 Account created with Google! Please log in to continue.";
        $redirect_url = "login.php?google_token=" . urlencode($temp_token);
        $_SESSION[$replay_key] = $redirect_url;
        header("Location: " . $redirect_url);
        exit();
    }
}
?>