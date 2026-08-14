<?php
// auth/verify.php
include '../db.php';

$redirect_url = "login.php";
$btn_text = "Go to Login";

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $redirect_url = ($_SESSION['role'] === 'worker') ? '../worker/dashboard.php' : '../client/dashboard.php';
    $btn_text = "Go to Dashboard";
}

$state = 'invalid'; // default
$user_name = '';

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT id, full_name, role FROM users WHERE verification_token = ? AND is_email_verified = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_name = explode(' ', trim($user['full_name']))[0];

        if (!isset($_SESSION['user_id'])) {
            $redirect_url = ($user['role'] === 'worker') ? '../worker/dashboard.php' : '../client/dashboard.php';
            $btn_text = "Continue to Dashboard";
        }

        $update = $conn->prepare("UPDATE users SET is_email_verified = 1, verification_token = NULL WHERE id = ?");
        $update->bind_param("i", $user['id']);

        if ($update->execute()) {
            $state = 'success';
        }
    } else {
        $state = 'invalid';
    }
} else {
    header("Location: " . $redirect_url);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?php echo $state === 'success' ? 'Email Verified' : 'Invalid Link'; ?> | Abilisto</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --success: #10b981;
            --success-light: #d1fae5;
            --error: #ef4444;
            --error-light: #fee2e2;
            --blue: #146af5;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --white: #ffffff;
            --radius: 24px;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            letter-spacing: -0.01em;
        }

        /* Subtle background pattern */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 20%, rgba(20,106,245,0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(16,185,129,0.06) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* Card */
        .card {
            position: relative;
            z-index: 1;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 3rem 2.5rem;
            width: 100%;
            max-width: 420px;
            text-align: center;
            box-shadow:
                0 1px 2px rgba(0,0,0,0.04),
                0 8px 32px rgba(0,0,0,0.06),
                0 32px 64px rgba(0,0,0,0.04);
            animation: cardUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        @keyframes cardUp {
            from { opacity: 0; transform: translateY(24px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Logo */
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            margin-bottom: 2.5rem;
        }
        .logo-abi { font-size: 1.25rem; font-weight: 800; color: var(--blue); letter-spacing: -0.04em; }
        .logo-listo { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); letter-spacing: -0.04em; }

        /* Icon circle */
        .icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            animation: iconPop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
        }
        @keyframes iconPop {
            from { opacity: 0; transform: scale(0.5); }
            to   { opacity: 1; transform: scale(1); }
        }

        .icon-wrap.success { background: var(--success-light); }
        .icon-wrap.error   { background: var(--error-light); }

        .icon-wrap .material-symbols-rounded {
            font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 40;
            font-size: 36px;
        }
        .icon-wrap.success .material-symbols-rounded { color: var(--success); }
        .icon-wrap.error   .material-symbols-rounded { color: var(--error); }

        /* Text */
        h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.03em;
            margin-bottom: 0.625rem;
            animation: fadeUp 0.4s ease 0.3s both;
        }
        p {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.65;
            margin-bottom: 2rem;
            animation: fadeUp 0.4s ease 0.35s both;
        }
        p strong { color: var(--text-primary); font-weight: 600; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Button */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 0.9rem 1.5rem;
            border-radius: 16px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            animation: fadeUp 0.4s ease 0.4s both;
        }

        .btn-success {
            background: linear-gradient(135deg, #34d399, #059669);
            color: white;
            box-shadow: 0 8px 20px -4px rgba(16,185,129,0.35);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -4px rgba(16,185,129,0.45);
        }
        .btn-success:active { transform: scale(0.98); }

        .btn-error {
            background: linear-gradient(135deg, #60a5fa, #0f52c2);
            color: white;
            box-shadow: 0 8px 20px -4px rgba(20,106,245,0.35);
        }
        .btn-error:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -4px rgba(20,106,245,0.45);
        }
        .btn-error:active { transform: scale(0.98); }

        .btn .material-symbols-rounded {
            font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
            font-size: 18px;
        }

        /* Divider */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 1.75rem 0;
            animation: fadeUp 0.4s ease 0.45s both;
        }

        /* Footer link */
        .footer-link {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
            animation: fadeUp 0.4s ease 0.5s both;
        }
        .footer-link:hover { color: var(--blue); }
        .footer-link .material-symbols-rounded {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 20;
            font-size: 15px;
        }
    </style>
</head>
<body>

<div class="card">

    <!-- Logo -->
    <div class="logo">
        <span class="logo-abi">Abi</span><span class="logo-listo">listo</span>
    </div>

    <?php if ($state === 'success'): ?>

        <!-- Success state -->
        <div class="icon-wrap success">
            <span class="material-symbols-rounded">mark_email_read</span>
        </div>

        <h1>Email Verified!</h1>
        <p>
            Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong>!<br>
            Your account is now active and ready to go.
        </p>

        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn btn-success">
            <span class="material-symbols-rounded">arrow_forward</span>
            <?php echo $btn_text; ?>
        </a>

    <?php else: ?>

        <!-- Error / invalid state -->
        <div class="icon-wrap error">
            <span class="material-symbols-rounded">link_off</span>
        </div>

        <h1>Link Invalid</h1>
        <p>
            This verification link has expired or your account has already been verified.
            Try logging in directly.
        </p>

        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn btn-error">
            <span class="material-symbols-rounded">login</span>
            <?php echo $btn_text; ?>
        </a>

    <?php endif; ?>

    <div class="divider"></div>

    <a href="../index.php" class="footer-link">
        <span class="material-symbols-rounded">arrow_back</span>
        Back to Home
    </a>

</div>

</body>
</html>