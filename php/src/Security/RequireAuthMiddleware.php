<?php

declare(strict_types=1);

namespace Attendly\Security;

use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\Database\Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (SessionAuth::getUser() === null) {
            if (!Flash::hasType('error')) {
                Flash::add('error', 'ログインしてください。');
            }
            $response = new Response(303);
            return $response->withHeader('Location', '/login');
        }

        $user = SessionAuth::getUser();
        if (is_array($user) && ($user['role'] ?? null) === 'platform_admin' && !empty($user['id'])) {
            $path = $this->normalizePath($request->getUri()->getPath());
            $allowAccount = $this->isAccountPath($path);
            $allowMfaSetup = $this->isMfaSetupPath($path);
            if (SessionAuth::needsPasswordChange() && !$allowAccount && !$allowMfaSetup) {
                if (!Flash::hasType('error')) {
                    Flash::add('error', 'プラットフォーム管理者のパスワード更新が必要です。');
                }
                $response = new Response(303);
                return $response->withHeader('Location', '/account');
            }

            if ($this->shouldBypassPlatformMfa()) {
                return $handler->handle($request);
            }

            if (!$allowAccount && !$allowMfaSetup) {
                try {
                    $repository = new Repository();
                    $totp = $repository->findVerifiedMfaMethodByType((int)$user['id'], 'totp');
                } catch (\Throwable) {
                    SessionAuth::clear();
                    if (!Flash::hasType('error')) {
                        Flash::add('error', '二段階認証設定の確認に失敗しました。再度ログインしてください。');
                    }
                    $response = new Response(303);
                    return $response->withHeader('Location', '/login');
                }
                if ($totp === null) {
                    if (!Flash::hasType('error')) {
                        Flash::add('error', 'プラットフォーム管理者は二段階認証の設定が必須です。');
                    }
                    $response = new Response(303);
                    return $response->withHeader('Location', '/settings/mfa');
                }
            }
        }

        return $handler->handle($request);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path !== '/' && str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }
        return $path;
    }

    private function isMfaSetupPath(string $path): bool
    {
        return $path === '/settings/mfa' || str_starts_with($path, '/settings/mfa/');
    }

    private function isAccountPath(string $path): bool
    {
        return $path === '/account' || str_starts_with($path, '/account/');
    }

    private function shouldBypassPlatformMfa(): bool
    {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
        if ($env === 'production') {
            return false;
        }
        $raw = $_ENV['PLATFORM_ADMIN_2FA_BYPASS'] ?? '';
        $enabled = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $enabled === true;
    }
}
