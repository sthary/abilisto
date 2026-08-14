<?php
// includes/init_lang.php

// 2. Set Default Language if missing
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en'; // Default to English
}

// 3. Handle Language Switch via URL (e.g., ?lang=tl)
if (isset($_GET['lang'])) {
    $lang_code = $_GET['lang'];
    $allowed_langs = ['en', 'tl', 'bis'];
    
    if (in_array($lang_code, $allowed_langs)) {
        $_SESSION['lang'] = $lang_code;
    }
}

// 4. Load Language File
$lang_file = __DIR__ . "/../lang/" . $_SESSION['lang'] . ".php";
if (file_exists($lang_file)) {
    include $lang_file;
} else {
    // Fallback if file is missing
    include __DIR__ . "/../lang/en.php";
}
?>