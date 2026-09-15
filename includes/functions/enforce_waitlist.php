<?php
// includes/functions/enforce_waitlist.php
// Waitlist / early-access gate. Required once from db_connect.php — which
// every page and every api/*.php endpoint already includes as its first
// substantive line — so this runs on every request without needing to be
// added to each protected file individually (there's no router/middleware
// layer in this app; db_connect.php is the closest thing to one).
//
// Mirrors the established auth/enforce_phone.php pattern (session check +
// a short allowlist of pages by basename, redirect otherwise), just
// centralized here instead of per-file, since this also has to cover
// api/ JSON endpoints, not only client/worker pages that happen to
// include enforce_phone.php themselves.
//
// The account's real state is always re-read from the database on every
// call — never trusted from $_SESSION — so an admin approval (or a
// suspension) takes effect on the very next request, no re-login required.

require_once __DIR__ . '/feature_flags.php';

function abilisto_waitlist_guard($conn) {
    // No session, or a role this gate doesn't apply to (staff accounts are
    // never created through the public signup flow and are never
    // waitlisted) — nothing to check.
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) return;
    if (!in_array($_SESSION['role'], ['client', 'worker'], true)) return;

    // Master switch — lets the whole feature be turned off from
    // admin/settings.php without touching any auth code.
    if (!isFeatureEnabled($conn, 'feature_waitlist_enabled')) return;

    $stmt = $conn->prepare("SELECT account_status FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $status = $stmt->fetchColumn();

    // Account no longer exists, or is fully active — normal access.
    if ($status === false || $status === 'active') return;

    // From here the account is 'waitlisted', 'suspended', or any future
    // non-active state — restrict to the small set of pages a signed-in,
    // not-yet-approved user still needs: the waitlist page itself, and
    // core auth/session plumbing (so logging out, or an in-flight
    // Google/OTP/password-reset flow, can never dead-end in a redirect loop).
    $current_page = basename($_SERVER['PHP_SELF'] ?? '');
    $allowed_pages = [
        'waitlist.php',
        'login.php',
        'logout.php',
        'verify_otp.php',
        'google_callback.php',
        'forgot_pass.php',
        'resend_otp.php',
        'resend_email.php',
    ];
    if (in_array($current_page, $allowed_pages, true)) return;

    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $is_api = (strpos($script, '/api/') !== false);

    if ($is_api) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'error'   => 'account_not_active',
            'message' => 'Your account does not currently have access to this feature.',
        ]);
        exit();
    }

    header('Location: /waitlist.php');
    exit();
}

abilisto_waitlist_guard($conn);
