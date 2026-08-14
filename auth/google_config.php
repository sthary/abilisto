<?php
// auth/google_config.php

require_once __DIR__ . '/../config/env.php';

define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URL',  getenv('GOOGLE_REDIRECT_URL'));
define('PRIVACY_POLICY_URL',   'https://abilisto.site/privacy.html');
define('TERMS_OF_SERVICE_URL', 'https://abilisto.site/terms.html');

// Read role from wherever it's available — GET param (signup page) takes priority,
// then session fallback, then default to 'client'
$_google_role = 'client';
if (isset($_GET['role']) && in_array($_GET['role'], ['client', 'worker'])) {
    $_google_role = $_GET['role'];
} elseif (isset($_SESSION['signup_role']) && in_array($_SESSION['signup_role'], ['client', 'worker'])) {
    $_google_role = $_SESSION['signup_role'];
}

// Store in session so it survives the Google redirect round-trip as a fallback
$_SESSION['signup_role'] = $_google_role;

$google_login_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URL,
    'response_type' => 'code',
    'scope'         => 'email profile',
    'access_type'   => 'online',
    'state'         => $_google_role   // <-- carries role through OAuth round-trip
]);
?>