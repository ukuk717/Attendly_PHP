<?php

declare(strict_types=1);

namespace Attendly\Middleware;

use Attendly\Support\SessionAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Injects current user (if any) into request attributes.
 */
final class CurrentUserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = SessionAuth::getUser();
        $request = $request->withAttribute('currentUser', $user);
        return $handler->handle($request);
    }
}
