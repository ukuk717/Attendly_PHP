<?php

declare(strict_types=1);

namespace Attendly\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    public static function getToken(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }
}

/**
 * Simple session-based CSRF middleware for Slim.
 * Checks X-CSRF-Token header or form field "csrf_token" on state-changing methods.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $methodsToProtect = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, $this->methodsToProtect, true)) {
            return $handler->handle($request);
        }

        $expected = CsrfToken::getToken();
        $body = $request->getParsedBody();
        $provided = $request->getHeaderLine('X-CSRF-Token');
        if (!$provided && is_array($body)) {
            $provided = $body['csrf_token'] ?? '';
        }

        if (!hash_equals($expected, (string)$provided)) {
            return $this->deny();
        }

        return $handler->handle($request);
    }

    private function deny(): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => 'invalid_csrf_token'], JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
