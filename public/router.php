<?php

declare(strict_types=1);

$target = dirname(__DIR__) . '/php/public/router.php';
if (!is_file($target)) {
    http_response_code(500);
    echo 'Missing php/public/router.php. Run the PHP server from the php/ directory.';
    exit(1);
}

require $target;
