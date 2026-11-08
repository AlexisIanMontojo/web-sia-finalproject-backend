<?php
// Let the built-in server serve existing static files (images, css, js) directly.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('/\.(?:png|jpe?g|gif|webp|css|js|ico)$/i', $path)
    && file_exists(__DIR__ . $path)) {
    return false;
}

require __DIR__ . '/index.php';