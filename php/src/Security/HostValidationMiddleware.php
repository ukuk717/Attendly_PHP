<?php

declare(strict_types=1);

namespace Attendly\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class HostValidationMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $allowedHosts;

    /**
     * @param string[] $allowedHosts
     */
    public function __construct(array $allowedHosts)
    {
        $this->allowedHosts = array_values(array_filter(array_map('trim', $allowedHosts)));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->allowedHosts)) {
            // No restriction configured; allow all.
            return $handler->handle($request);
        }

        $host = $request->getUri()->getHost();
        if ($host === '' || !in_array($host, $this->allowedHosts, true)) {
            return $this->deny($host);
        }

        return $handler->handle($request);
    }

    private function deny(string $host): ResponseInterface
    {
        $response = new Response();
        $payload = [
            'error' => 'invalid_host',
            'host' => $host,
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
