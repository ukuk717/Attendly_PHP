<?php

declare(strict_types=1);

namespace Attendly;

use DateTimeImmutable;
use DateTimeInterface;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Attendly\Security\CsrfMiddleware;
use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Security\HostValidationMiddleware;
use Attendly\Security\SecurityHeadersMiddleware;
use Attendly\Controllers\AuthController;
use Attendly\Security\RequireAuthMiddleware;
use Attendly\Middleware\CurrentUserMiddleware;
use Attendly\Controllers\RegisterController;

function create_app(): \Slim\App
{
    $app = AppFactory::create();

    $displayErrorDetails = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    $app->addErrorMiddleware($displayErrorDetails, true, true);

    $allowedHosts = [];
    if (!empty($_ENV['ALLOWED_HOSTS'])) {
        $allowedHosts = array_filter(array_map('trim', explode(',', (string)$_ENV['ALLOWED_HOSTS'])));
    }
    $app->add(new HostValidationMiddleware($allowedHosts));

    // Security headers (CSP/SAMEORIGIN etc.)
    $app->add(new SecurityHeadersMiddleware());

    // CSRF protection for state-changing requests
    $app->add(new CsrfMiddleware());

    // Attach current user to request attributes for views
    $app->add(new CurrentUserMiddleware());

    $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write('ok');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    });

    $view = new View(dirname(__DIR__) . '/views');

    $statusHandler = function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $payload = [
            'app' => 'Attendly PHP skeleton',
            'env' => $_ENV['APP_ENV'] ?? 'local',
            'php' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'db' => \attendly_status_database(),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $app->get('/', $statusHandler);
    $app->get('/status', $statusHandler);

    $app->get('/web', function (ServerRequestInterface $request, ResponseInterface $response) use ($view): ResponseInterface {
        $html = $view->renderWithLayout('home', [
            'title' => 'Attendly PHP Skeleton',
            'env' => $_ENV['APP_ENV'] ?? 'local',
            'php' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    // sample form to validate CSRF + flash handling
    $app->get('/web/form', function (ServerRequestInterface $request, ResponseInterface $response) use ($view): ResponseInterface {
        $flashes = Flash::consume();
        $html = $view->renderWithLayout('form', [
            'title' => 'CSRF & Flash Sample',
            'csrf' => CsrfToken::getToken(),
            'flashes' => $flashes,
            'currentUser' => $request->getAttribute('currentUser'),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    $app->post('/web/form', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            Flash::add('error', '名前を入力してください。');
        } else {
            Flash::add('success', "送信しました: {$name}");
        }
        return $response
            ->withStatus(303)
            ->withHeader('Location', '/web/form');
    });

    // Auth (AuthService は遅延生成)
    $authController = new AuthController($view);
    $app->get('/login', [$authController, 'showLogin']);
    $app->post('/login', [$authController, 'login']);
    $app->post('/logout', [$authController, 'logout']);

    // Registration (placeholder: logic to be completed with DB writes + MFA)
    $registerController = new RegisterController($view);
    $app->get('/register', [$registerController, 'show']);
    $app->post('/register', [$registerController, 'register']);

    $app->get('/dashboard', function (ServerRequestInterface $request, ResponseInterface $response) use ($view): ResponseInterface {
        $flashes = Flash::consume();
        $html = $view->renderWithLayout('dashboard', [
            'title' => 'ダッシュボード',
            'csrf' => CsrfToken::getToken(),
            'flashes' => $flashes,
            'currentUser' => $request->getAttribute('currentUser'),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    })->add(new RequireAuthMiddleware());

    $app->get('/whoami', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $user = $request->getAttribute('currentUser');
        if (!$user) {
            $response = $response->withStatus(401);
        }
        $response->getBody()->write(json_encode(['user' => $user], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    return $app;
}
