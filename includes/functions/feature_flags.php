<?php
// includes/functions/feature_flags.php
// App-wide feature kill-switches, backed by the existing `settings`
// key-value table (same one admin/settings.php already uses for Quick
// Match per-category availability and fee settings).

/**
 * @param  PDO    $conn
 * @param  string $key  One of: feature_quickmatch_enabled,
 *                       feature_greenloop_enabled, feature_wemap_enabled
 * @return bool          True if enabled (or the key doesn't exist yet —
 *                        fail open to "on" so a missing row never looks
 *                        like an intentional shutdown).
 */
function isFeatureEnabled($conn, $key) {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $cache[$key] = ($row === false) || ($row['setting_value'] === '1');
    return $cache[$key];
}

/**
 * Call at the top of any page gated by a feature flag. Redirects away with
 * a flash message when the feature is off; does nothing when it's on.
 *
 * @param PDO    $conn
 * @param string $key
 * @param string $redirectTo  Where to send the user if the feature is off.
 */
function requireFeatureEnabled($conn, $key, $redirectTo) {
    if (!isFeatureEnabled($conn, $key)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash_error'] = "This feature is temporarily unavailable. Please check back later.";
        }
        header("Location: $redirectTo");
        exit();
    }
}
?>
