<?php

declare(strict_types=1);

namespace Attendly;

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
use Attendly\Controllers\MfaLoginController;
use Attendly\Security\RequireAdminMiddleware;
use Attendly\Security\RequireAuthMiddleware;
use Attendly\Middleware\CurrentUserMiddleware;
use Attendly\Controllers\RegisterController;
use Attendly\Controllers\PasswordResetController;
use Attendly\Controllers\PasswordResetUpdateController;
use Attendly\Controllers\RegisterVerifyController;
use Attendly\Controllers\RoleCodeController;
use Attendly\Controllers\TimesheetExportController;
use Attendly\Controllers\PayslipController;
use Attendly\Controllers\DashboardController;
use Attendly\Controllers\PayrollViewerController;
use Attendly\Controllers\WorkSessionController;
use Attendly\Controllers\MfaSettingsController;
use Attendly\Controllers\AccountController;
use Attendly\Controllers\AdminEmployeesController;
use Attendly\Controllers\AdminSessionsController;
use Attendly\Controllers\AdminTenantSettingsController;
use Attendly\Support\AppTime;

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
            'timezone' => AppTime::timezone()->getName(),
            'timestamp' => AppTime::now()->format(DateTimeInterface::ATOM),
            'db' => \attendly_status_database(),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $user = $request->getAttribute('currentUser');
        $target = !empty($user) ? '/dashboard' : '/login';
        return $response->withStatus(303)->withHeader('Location', $target);
    });
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

    // MFA (メールOTP)
    $mfaLoginController = new MfaLoginController($view);
    $app->get('/login/mfa', [$mfaLoginController, 'show']);
    $app->post('/login/mfa/email/send', [$mfaLoginController, 'sendEmail']);
    $app->post('/login/mfa', [$mfaLoginController, 'verify']);
    $app->get('/login/mfa/cancel', [$mfaLoginController, 'cancel']);

    // Registration (placeholder: logic to be completed with DB writes + MFA)
    $registerController = new RegisterController($view);
    $app->get('/register', [$registerController, 'show']);
    $app->post('/register', [$registerController, 'register']);

    // Password reset (request only; full flow TBD)
    $passwordReset = new PasswordResetController($view);
    $app->get('/password/reset', [$passwordReset, 'showRequest']);
    $app->post('/password/reset', [$passwordReset, 'request']);

    $passwordResetUpdate = new PasswordResetUpdateController($view);
    $app->get('/password/reset/{token}', [$passwordResetUpdate, 'show']);
    $app->post('/password/reset/{token}', [$passwordResetUpdate, 'update']);

    $registerVerify = new RegisterVerifyController($view);
    $app->get('/register/verify', [$registerVerify, 'show']);
    $app->post('/register/verify', [$registerVerify, 'verify']);
    $app->post('/register/verify/resend', [$registerVerify, 'resend']);
    $app->post('/register/verify/cancel', [$registerVerify, 'cancel']);
    $dashboard = new DashboardController($view);
    $workSessions = new WorkSessionController();
    $payrollViewer = new PayrollViewerController($view);

    // Role code management (admin)
    $roleCodeController = new RoleCodeController($view);
    $app->get('/role-codes', [$roleCodeController, 'list'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/role-codes', [$roleCodeController, 'create'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/role-codes/{id}/disable', [$roleCodeController, 'disable'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/role-codes', [$roleCodeController, 'showPage'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/role-codes', [$roleCodeController, 'createFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/role-codes/{id}/disable', [$roleCodeController, 'disableFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    // Timesheet export (admin)
    $timesheetExport = new TimesheetExportController($view);
    $app->post('/timesheets/export', [$timesheetExport, 'export'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/timesheets/export', [$timesheetExport, 'showForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/timesheets/export', [$timesheetExport, 'exportFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    // Payslip send (admin)
    $payslipController = new PayslipController($view);
    $app->post('/payslips/send', [$payslipController, 'send'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/payslips/send', [$payslipController, 'showForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/payslips/send', [$payslipController, 'sendFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    // Admin employee management / sessions / tenant settings
    $adminEmployees = new AdminEmployeesController();
    $app->post('/admin/employees/{userId}/status', [$adminEmployees, 'updateStatus'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/mfa/reset', [$adminEmployees, 'resetMfa'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $adminSessions = new AdminSessionsController($view);
    $app->get('/admin/employees/{userId}/sessions', [$adminSessions, 'show'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions', [$adminSessions, 'add'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/update', [$adminSessions, 'update'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/employees/{userId}/sessions/{sessionId}/delete/confirm', [$adminSessions, 'confirmDelete'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/delete', [$adminSessions, 'delete'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $adminTenantSettings = new AdminTenantSettingsController();
    $app->post('/admin/settings/email-verification', [$adminTenantSettings, 'updateEmailVerification'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $app->post('/admin/export', [$timesheetExport, 'exportMonthlyFromDashboard'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $app->get('/dashboard', [$dashboard, 'show'])->add(new RequireAuthMiddleware());
    $app->post('/work-sessions/punch', [$workSessions, 'toggle'])->add(new RequireAuthMiddleware());
    $app->get('/payrolls', [$payrollViewer, 'index'])->add(new RequireAuthMiddleware());
    $app->get('/payrolls/{id}/download', [$payrollViewer, 'download'])->add(new RequireAuthMiddleware());

    $account = new AccountController($view);
    $app->get('/account', [$account, 'show'])->add(new RequireAuthMiddleware());
    $app->get('/account/password', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response->withStatus(303)->withHeader('Location', '/account');
    })->add(new RequireAuthMiddleware());
    $app->post('/account/profile', [$account, 'updateProfile'])->add(new RequireAuthMiddleware());
    $app->post('/account/password', [$account, 'changePassword'])->add(new RequireAuthMiddleware());
    $app->post('/account/email/request', [$account, 'requestEmailChange'])->add(new RequireAuthMiddleware());
    $app->post('/account/email/verify', [$account, 'verifyEmailChange'])->add(new RequireAuthMiddleware());

    $mfaSettings = new MfaSettingsController($view);
    $app->get('/settings/mfa', [$mfaSettings, 'show'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/totp/verify', [$mfaSettings, 'verifyTotp'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/recovery-codes/regenerate', [$mfaSettings, 'regenerateRecoveryCodes'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/trusted-devices/revoke', [$mfaSettings, 'revokeTrustedDevices'])->add(new RequireAuthMiddleware());

    $app->get('/whoami', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $user = $request->getAttribute('currentUser');
        if (!$user) {
            $response = $response->withStatus(401);
        }
        $response->getBody()->write(json_encode(['user' => $user], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    // Static: styles.css (for built-in server/slim routing)
    $app->get('/styles.css', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $candidates = [
            dirname(__DIR__) . '/public/styles.css',         // php/public
            dirname(__DIR__, 2) . '/public/styles.css',      // repo root public
        ];
        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $response->getBody()->write((string)file_get_contents($path));
        return $response
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    });

    // Static assets fallback for common types (css/js/png/jpg/svg/ico)
    $app->get('/{path:.*\\.(?:css|js|png|jpg|jpeg|gif|svg|ico)}', function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
        $relative = ltrim($args['path'] ?? '', '/');
        if (str_contains($relative, '..')) {
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $candidates = [
            dirname(__DIR__) . '/public/' . $relative,        // php/public
            dirname(__DIR__, 2) . '/public/' . $relative,     // repo root public
        ];
        $path = null;
        foreach ($candidates as $candidate) {
            $realPath = realpath($candidate);
            $allowedBases = [
                realpath(dirname(__DIR__) . '/public'),
                realpath(dirname(__DIR__, 2) . '/public'),
            ];
            $isAllowed = false;
            foreach ($allowedBases as $base) {
                if ($base !== false && $realPath !== false && str_starts_with($realPath, $base . DIRECTORY_SEPARATOR)) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed && $realPath !== false && is_file($realPath)) {
                $path = $realPath;
                break;
            }
        }
        if ($path === null) {
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $mime = 'text/plain';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        if (isset($mimeMap[$ext])) {
            $mime = $mimeMap[$ext];
        }
        $response->getBody()->write((string)file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=300');
    });

    return $app;
}
