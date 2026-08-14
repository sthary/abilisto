<?php
// serve_verification_image.php
// Serves verification images from private_uploads directory
// Supports: ids/, face/, nc_certificates/
// Fix: removed premature basename() call that was stripping the directory prefix,
//      causing $dir to always be empty and the allowed_dirs check to fail (403).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied');
}

$path = $_GET['path'] ?? '';
if (empty($path)) {
    http_response_code(400);
    exit('No path specified');
}

// ── Allowed values ────────────────────────────────────────────────────────────
$allowed_dirs       = ['ids', 'face', 'nc_certificates'];
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

// ── Parse and sanitize ────────────────────────────────────────────────────────
// Expected format: "dir/filename.ext"
// Do NOT call basename() on the full path — that strips the "dir/" prefix.
// Instead split first, then sanitize each part separately.
$parts    = explode('/', $path, 2);          // max 2 parts: dir + filename
$dir      = $parts[0] ?? '';
$filename = basename($parts[1] ?? '');       // basename() only on the filename

// Validate directory
if (!in_array($dir, $allowed_dirs, true)) {
    http_response_code(403);
    exit('Invalid directory');
}

// Validate filename is not empty and contains no path traversal characters
if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
    http_response_code(403);
    exit('Invalid filename');
}

// Validate extension
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions, true)) {
    http_response_code(403);
    exit('Invalid file type');
}

// ── Build and verify path ─────────────────────────────────────────────────────
$base_path = dirname(__DIR__) . '/private_uploads/';
$full_path = $base_path . $dir . '/' . $filename;

// Resolve real path and confirm it stays inside private_uploads/
$real_full = realpath($full_path);
$real_base = realpath($base_path);

if ($real_full === false || $real_base === false || strpos($real_full, $real_base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Path traversal detected');
}

if (!is_file($real_full)) {
    http_response_code(404);
    exit('File not found');
}

// ── Serve the image ───────────────────────────────────────────────────────────
$mime_types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
];
$mime = $mime_types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real_full));
header('Cache-Control: public, max-age=86400');
header('Pragma: public');
header('Content-Disposition: inline; filename="' . basename($real_full) . '"');
readfile($real_full);
exit;