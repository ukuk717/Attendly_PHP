<?php

declare(strict_types=1);

namespace Attendly\Security;

use Attendly\Support\Flash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * プラットフォーム管理者（role=platform_admin かつ tenant_id が未設定）を許可するミドルウェア。
 */
final class RequirePlatformMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        $role = is_array($user) ? ($user['role'] ?? null) : null;
        $isPlatform = is_array($user)
            && in_array($role, ['platform_admin', 'admin'], true)
            && !empty($user['id'])
            && (!array_key_exists('tenant_id', $user) || $user['tenant_id'] === null);

        if ($isPlatform) {
            return $handler->handle($request);
        }

        $rawUserId = is_array($user) ? ($user['id'] ?? null) : null;
        $hashedUserId = $rawUserId !== null ? hash('sha256', (string)$rawUserId) : 'unknown';
        error_log(sprintf(
            'Unauthorized platform access attempt: user_ref=%s, role=%s, path=%s',
            $hashedUserId,
            is_array($user) ? ($user['role'] ?? 'none') : 'none',
            $request->getUri()->getPath()
        ));

        $accept = strtolower($request->getHeaderLine('Accept'));
        $expectsJson = str_contains($accept, 'application/json')
            || str_contains($accept, 'text/json')
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json');

        if ($expectsJson) {
            $response = new Response(403);
            $json = json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = '{"error":"forbidden"}';
            }
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        Flash::add('error', 'プラットフォーム管理者権限が必要です。');
        $response = new Response(303);
        return $response->withHeader('Location', '/login');
    }
}
