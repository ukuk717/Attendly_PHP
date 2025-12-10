<?php

declare(strict_types=1);

/**
 * Remove PHPMailer's OAuth helper script shipped as an example.
 *
 * The helper is not needed in production and could expose an interactive
 * credential flow if the vendor directory is publicly reachable on shared
 * hosting. This cleanup keeps the deployment footprint minimal until we can
 * rely on an upstream option to exclude the file.
 */
$projectRoot = dirname(__DIR__);
$vendorDir = realpath($projectRoot . '/vendor');

if ($vendorDir === false) {
    // Dependencies not installed yet; nothing to clean up.
    return;
}

$targetPath = implode(DIRECTORY_SEPARATOR, [$vendorDir, 'phpmailer', 'phpmailer', 'get_oauth_token.php']);
$targetRealPath = realpath($targetPath) ?: $targetPath;

// Ensure we never delete outside the vendor directory even if symlinks are present.
if (!str_starts_with($targetRealPath, $vendorDir . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Unsafe target path resolved for PHPMailer OAuth helper.');
}

if (is_file($targetRealPath)) {
    if (!@unlink($targetRealPath)) {
        throw new RuntimeException('Failed to remove PHPMailer OAuth helper: ' . $targetRealPath);
    }
    echo "Removed PHPMailer OAuth helper at {$targetRealPath}" . PHP_EOL;
} else {
    echo "No PHPMailer OAuth helper found; nothing to remove." . PHP_EOL;
}
