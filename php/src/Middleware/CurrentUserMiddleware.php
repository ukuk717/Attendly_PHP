<?php

declare(strict_types=1);

namespace Attendly\Middleware;

use Attendly\Database\Repository;
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
    private Repository $repository;

    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = SessionAuth::getUser();
        if (is_array($user) && !empty($user['id'])) {
            try {
                $profile = $this->repository->findUserProfile((int)$user['id']);
            } catch (\Throwable $e) {
                // Log the error for operational visibility
                error_log('Failed to fetch user profile: ' . $e->getMessage());
                $profile = null;
            }
            if (is_array($profile)) {
                $fullName = trim(
                    (($profile['last_name'] ?? '') !== null ? (string)$profile['last_name'] : '') . ' ' .
                    (($profile['first_name'] ?? '') !== null ? (string)$profile['first_name'] : '')
                );
                $user = array_merge($user, [
                    'email' => $profile['email'] ?? ($user['email'] ?? null),
                    'tenant_id' => $profile['tenant_id'] ?? ($user['tenant_id'] ?? null),
                    'first_name' => $profile['first_name'] ?? null,
                    'last_name' => $profile['last_name'] ?? null,
                    'name' => $fullName !== '' ? $fullName : null,
                ]);
            }
        }
        $request = $request->withAttribute('currentUser', $user);
        return $handler->handle($request);
    }
}
