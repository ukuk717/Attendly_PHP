<?php

declare(strict_types=1);

/**
 * Remove PHPMailer's OAuth helper script to avoid exposing a credential helper
 * on shared hosting environments such as Lolipop.
 */

$helperPath = __DIR__ . '/../vendor/phpmailer/phpmailer/get_oauth_token.php';
$helperDir = __DIR__ . '/../vendor/phpmailer/phpmailer';

$helperDirReal = realpath($helperDir);
if ($helperDirReal === false) {
    // PHPMailer not installed yet; nothing to clean.
    return;
}

$helperReal = realpath($helperPath);
if ($helperReal === false) {
    // Helper already removed.
    return;
}

if (!str_starts_with($helperReal, $helperDirReal . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "安全のため、想定外のパスの削除を拒否しました: {$helperReal}" . PHP_EOL);
    exit(1);
}

if (!is_file($helperReal)) {
    return;
}

if (!is_writable($helperReal)) {
    fwrite(STDERR, "ファイルに書き込みできないため削除できませんでした: {$helperReal}" . PHP_EOL);
    exit(1);
}

if (!unlink($helperReal)) {
    fwrite(STDERR, "get_oauth_token.php の削除に失敗しました: {$helperReal}" . PHP_EOL);
    exit(1);
}

echo "Removed PHPMailer OAuth helper for security hardening." . PHP_EOL;
