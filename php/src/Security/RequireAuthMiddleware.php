<?php

declare(strict_types=1);

namespace Attendly\Security;

use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
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
            Flash::add('error', 'ログインしてください。');
            $response = new Response(303);
            return $response->withHeader('Location', '/login');
        }

        return $handler->handle($request);
    }
}
