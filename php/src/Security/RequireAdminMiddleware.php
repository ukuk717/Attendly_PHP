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
 * 管理者ロール（admin、tenant_admin）を許可するミドルウェア。
 * currentUser に role=admin と tenant_id がセットされていない場合は 403/リダイレクトで拒否する。
 */
final class RequireAdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        $isAdmin = is_array($user)
            && in_array(($user['role'] ?? null), ['admin', 'tenant_admin'], true)
            && !empty($user['id'])
            && isset($user['tenant_id']);

        if ($isAdmin) {
            return $handler->handle($request);
        }

        $rawUserId = is_array($user) ? ($user['id'] ?? null) : null;
        $hashedUserId = $rawUserId !== null ? hash('sha256', (string)$rawUserId) : 'unknown';
        error_log(sprintf(
            'Unauthorized admin access attempt: user_ref=%s, role=%s, path=%s',
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

        Flash::add('error', '管理者権限が必要です。');
        $response = new Response(303);
        return $response->withHeader('Location', '/dashboard');
    }
}
