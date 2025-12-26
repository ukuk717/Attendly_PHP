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
use Attendly\Controllers\PasskeyController;
use Attendly\Security\RequireAdminMiddleware;
use Attendly\Security\RequireAuthMiddleware;
use Attendly\Middleware\CurrentUserMiddleware;
use Attendly\Security\SessionConcurrencyMiddleware;
use Attendly\Controllers\RegisterController;
use Attendly\Controllers\PasswordResetController;
use Attendly\Controllers\PasswordResetUpdateController;
use Attendly\Controllers\RegisterVerifyController;
use Attendly\Controllers\RoleCodeController;
use Attendly\Controllers\TimesheetExportController;
use Attendly\Controllers\PayslipController;
use Attendly\Controllers\AdminPayslipsController;
use Attendly\Controllers\DashboardController;
use Attendly\Controllers\PayrollViewerController;
use Attendly\Controllers\WorkSessionController;
use Attendly\Controllers\WorkSessionBreakController;
use Attendly\Controllers\MfaSettingsController;
use Attendly\Controllers\AccountController;
use Attendly\Controllers\AdminEmployeesController;
use Attendly\Controllers\AdminSessionsController;
use Attendly\Controllers\AdminSessionBreaksController;
use Attendly\Controllers\AdminTenantSettingsController;
use Attendly\Controllers\PlatformTenantsController;
use Attendly\Controllers\SignedDownloadController;
use Attendly\Support\AppTime;
use Attendly\Security\RequirePlatformMiddleware;
use Slim\Exception\HttpNotFoundException;

function create_app(): \Slim\App
{
    $app = AppFactory::create();

    $displayErrorDetails = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
    $errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app): ResponseInterface {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        $quietPaths = [
            '/favicon.ico',
            '/apple-touch-icon.png',
            '/apple-touch-icon-precomposed.png',
            '/site.webmanifest',
            '/manifest.json',
            '/robots.txt',
        ];
        if (in_array($path, $quietPaths, true)) {
            return $app->getResponseFactory()->createResponse(204);
        }

        error_log(sprintf('[http] 404 %s %s', $method, $path));
        $response = $app->getResponseFactory()->createResponse(404);
        $accept = strtolower($request->getHeaderLine('Accept'));
        $expectsJson = str_contains($accept, 'application/json') || str_contains($accept, 'text/json');
        if ($expectsJson) {
            $response->getBody()->write('{"error":"not_found"}');
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $response->getBody()->write('Not Found');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    });

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

    // 同時ログイン制御（単一セッション）。SlimのミドルウェアはLIFOのため、CurrentUserより後に追加して先に実行する。
    $app->add(new SessionConcurrencyMiddleware());

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
    $enableStatus = filter_var($_ENV['STATUS_ENDPOINT_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    if ($enableStatus) {
        $app->get('/status', $statusHandler);
    }

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

    // Passkeys (WebAuthn)
    $passkeyController = new PasskeyController();
    $app->post('/passkeys/login/options', [$passkeyController, 'loginOptions']);
    $app->post('/passkeys/login/verify', [$passkeyController, 'loginVerify']);
    $app->post('/passkeys/registration/options', [$passkeyController, 'registrationOptions'])->add(new RequireAuthMiddleware());
    $app->post('/passkeys/registration/verify', [$passkeyController, 'registrationVerify'])->add(new RequireAuthMiddleware());
    $app->post('/passkeys/{id}/delete', [$passkeyController, 'delete'])->add(new RequireAuthMiddleware());

    // Signed downloads
    $signedDownloads = new SignedDownloadController();
    $app->get('/downloads/{token}', [$signedDownloads, 'download']);

    // MFA (メールOTP)
    $mfaLoginController = new MfaLoginController($view);
    $app->get('/login/mfa', [$mfaLoginController, 'show']);
    $app->post('/login/mfa/email/send', [$mfaLoginController, 'sendEmail']);
    $app->post('/login/mfa', [$mfaLoginController, 'verify']);
    $app->post('/login/mfa/cancel', [$mfaLoginController, 'cancel']);

    // Registration (placeholder: logic to be completed with DB writes + MFA)
    $registerController = new RegisterController($view);
    $app->get('/register', [$registerController, 'show']);
    $app->post('/register', [$registerController, 'register']);

    // Password reset
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
    $workSessionBreaks = new WorkSessionBreakController();
    $payrollViewer = new PayrollViewerController($view);

    // Role code management (admin)
    $roleCodeController = new RoleCodeController($view);
    $app->get('/role-codes', [$roleCodeController, 'list'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/role-codes', [$roleCodeController, 'create'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/role-codes/{id}/disable', [$roleCodeController, 'disable'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/role-codes', [$roleCodeController, 'showPage'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/role-codes', [$roleCodeController, 'createFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/role-codes/{id}/disable', [$roleCodeController, 'disableFromForm'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/role-codes/{id}/qr', [$roleCodeController, 'downloadQr'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

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

    // Payslip management (admin)
    $adminPayslips = new AdminPayslipsController($view);
    $app->get('/admin/payslips', [$adminPayslips, 'show'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/payslips/{id}/download', [$adminPayslips, 'download'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/payslips/{id}/resend', [$adminPayslips, 'resend'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    // Admin employee management / sessions / tenant settings
    $adminEmployees = new AdminEmployeesController();
    $app->post('/admin/employees/{userId}/status', [$adminEmployees, 'updateStatus'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->map(['GET', 'HEAD'], '/admin/employees/{userId}/mfa/reset', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    })->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/mfa/reset', [$adminEmployees, 'resetMfa'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/employment-type', [$adminEmployees, 'updateEmploymentType'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $adminSessions = new AdminSessionsController($view);
    $app->get('/admin/employees/{userId}/sessions', [$adminSessions, 'show'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions', [$adminSessions, 'add'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/update', [$adminSessions, 'update'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->get('/admin/employees/{userId}/sessions/{sessionId}/delete/confirm', [$adminSessions, 'confirmDelete'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/delete', [$adminSessions, 'delete'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $adminSessionBreaks = new AdminSessionBreaksController($view);
    $app->get('/admin/employees/{userId}/sessions/{sessionId}/breaks', [$adminSessionBreaks, 'show'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/breaks/add', [$adminSessionBreaks, 'add'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/breaks/{breakId}/update', [$adminSessionBreaks, 'update'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/admin/employees/{userId}/sessions/{sessionId}/breaks/{breakId}/delete', [$adminSessionBreaks, 'delete'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    $adminTenantSettings = new AdminTenantSettingsController();
    $app->post('/admin/settings/email-verification', [$adminTenantSettings, 'updateEmailVerification'])->add(new RequireAdminMiddleware())->add(new RequireAuthMiddleware());

    // Platform (tenant admin MFA reset/rollback)
    $platformTenants = new PlatformTenantsController($view);
    $app->get('/platform/tenants', [$platformTenants, 'show'])->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/platform/tenants/create', [$platformTenants, 'createTenant'])->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/platform/tenants/{tenantId}/status', [$platformTenants, 'updateTenantStatus'])->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->map(['GET', 'HEAD'], '/platform/tenant-admins/{userId}/mfa/reset', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
    })->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/platform/tenant-admins/{userId}/mfa/reset', [$platformTenants, 'resetTenantAdminMfa'])->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->map(['GET', 'HEAD'], '/platform/tenant-admins/{userId}/mfa/rollback', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response->withStatus(303)->withHeader('Location', '/platform/tenants');
    })->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());
    $app->post('/platform/tenant-admins/{userId}/mfa/rollback', [$platformTenants, 'rollbackTenantAdminMfa'])->add(new RequirePlatformMiddleware())->add(new RequireAuthMiddleware());

    $app->get('/dashboard', [$dashboard, 'show'])->add(new RequireAuthMiddleware());
    $app->post('/work-sessions/punch', [$workSessions, 'toggle'])->add(new RequireAuthMiddleware());
    $app->post('/work-sessions/break/start', [$workSessionBreaks, 'start'])->add(new RequireAuthMiddleware());
    $app->post('/work-sessions/break/end', [$workSessionBreaks, 'end'])->add(new RequireAuthMiddleware());
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
    $app->post('/account/sessions/{id}/revoke', [$account, 'revokeSession'])->add(new RequireAuthMiddleware());

    $mfaSettings = new MfaSettingsController($view);
    $app->get('/settings/mfa', [$mfaSettings, 'show'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/totp/setup/reset', [$mfaSettings, 'resetTotpSetup'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/totp/verify', [$mfaSettings, 'verifyTotp'])->add(new RequireAuthMiddleware());
    $app->post('/settings/mfa/totp/disable', [$mfaSettings, 'disableTotp'])->add(new RequireAuthMiddleware());
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
