<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo 'Missing vendor/autoload.php. Run "composer install" in the php/ directory.';
    exit(1);
}

require $autoloadPath;
require $projectRoot . '/src/bootstrap.php';
require $projectRoot . '/src/app.php';

attendly_load_env($projectRoot);
attendly_bootstrap_runtime();

$app = Attendly\create_app();
$app->run();
