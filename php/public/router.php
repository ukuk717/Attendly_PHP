<?php

declare(strict_types=1);

// PHP built-in server router for local development.
// Usage: php -S localhost:8000 -t public public/router.php

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = is_string($uriPath) ? $uriPath : '/';

// Prevent path traversal attempts from being treated as static.
if (str_contains($uriPath, '..')) {
    require __DIR__ . '/index.php';
    return;
}

// Serve existing files directly (css/js/images, etc.)
$candidate = __DIR__ . $uriPath;
if ($uriPath !== '/' && is_file($candidate)) {
    return false;
}

require __DIR__ . '/index.php';

