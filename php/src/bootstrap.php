<?php

declare(strict_types=1);

use Attendly\Database;
use Attendly\Support\AppTime;
use Dotenv\Dotenv;

const APP_DEFAULT_SESSION_TTL = 60 * 60 * 6; // 6 hours

/**
 * Load environment variables from .env (if present).
 */
function attendly_load_env(string $projectRoot): void
{
    if (!class_exists(Dotenv::class)) {
        http_response_code(500);
        echo 'Missing dependency: vlucas/phpdotenv. Run "composer install" in php/.';
        exit(1);
    }

    $dotEnv = Dotenv::createImmutable($projectRoot, '.env');
    $dotEnv->safeLoad();
}

/**
 * Configure timezone and session cookie params for CGI環境。
 */
function attendly_bootstrap_runtime(): void
{
    date_default_timezone_set(AppTime::timezone()->getName());

    $lifetime = (int)($_ENV['SESSION_TTL_SECONDS'] ?? APP_DEFAULT_SESSION_TTL);
    $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
    $secureOverride = $_ENV['APP_COOKIE_SECURE'] ?? null;
    if ($secureOverride !== null) {
        $cookieSecure = filter_var($secureOverride, FILTER_VALIDATE_BOOL);
    } else {
        $cookieSecure = $env === 'production';
    }

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Minimum router to unblock further feature ports.
 */
function attendly_handle_request(): void
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($uri === '/health') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        return;
    }

    if ($uri === '/' || $uri === '/status') {
        if ($uri === '/status') {
            $enableStatus = filter_var($_ENV['STATUS_ENDPOINT_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
            if (!$enableStatus) {
                http_response_code(404);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'not_found']);
                return;
            }
        }
        $dbStatus = attendly_status_database();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'app' => 'Attendly PHP skeleton',
            'env' => $_ENV['APP_ENV'] ?? 'local',
            'timezone' => date_default_timezone_get(),
            'timestamp' => AppTime::now()->format(DateTimeInterface::ATOM),
            'db' => $dbStatus,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found']);
}

/**
 * Return database connectivity status for /status.
 */
function attendly_status_database(): array
{
    try {
        $pdo = Database::connect();
        $alive = Database::ping($pdo);
        return [
            'status' => $alive ? 'ok' : 'fail',
        ];
    } catch (Throwable $e) {
        $payload = [
            'status' => 'fail',
        ];
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        if ($debug) {
            $payload['error'] = $e->getMessage();
        }
        return $payload;
    }
}
