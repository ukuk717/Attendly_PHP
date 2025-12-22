<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\PasswordHasher;

final class AuthService
{
    private PasswordHasher $hasher;
    private int $maxFailures;
    private int $lockSeconds;

    public function __construct(private Repository $repo = new Repository(), ?PasswordHasher $hasher = null)
    {
        $this->hasher = $hasher ?? new PasswordHasher();
        $this->maxFailures = $this->sanitizeInt($_ENV['LOGIN_MAX_FAILURES'] ?? 10, 10, 1, 50);
        $this->lockSeconds = $this->sanitizeInt($_ENV['LOGIN_LOCK_SECONDS'] ?? 600, 600, 0, 3600);
    }

    /**
     * 認証を行い、成功時はユーザー情報を返却する。
     *
     * @return array{user: array{id:int,email:string,role:string,tenant_id:?int,must_change_password:bool,status:?string}|null, error:?string}
     */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->repo->findUserByEmail($email);
        if (!$user) {
            return ['user' => null, 'error' => 'not_found'];
        }

        if (!array_key_exists('role', $user) || !array_key_exists('tenant_id', $user)) {
            return ['user' => null, 'error' => 'incomplete_user_data'];
        }

        if ($user['status'] !== null && $user['status'] !== 'active') {
            return ['user' => null, 'error' => 'inactive'];
        }

        $now = AppTime::now();
        if (!empty($user['locked_until']) && $user['locked_until'] instanceof \DateTimeImmutable && $user['locked_until'] > $now) {
            return ['user' => null, 'error' => 'locked'];
        }

        $verify = $this->hasher->verify($password, $user['password_hash']);
        if (!$verify['ok']) {
            try {
                $status = $this->repo->registerLoginFailureAndMaybeLock((int)$user['id'], $this->maxFailures, $this->lockSeconds);
                if ($status['locked_until'] instanceof \DateTimeImmutable) {
                    return ['user' => null, 'error' => 'locked'];
                }
            } catch (\Throwable) {
                // fallback: do not leak details; still deny
            }
            return ['user' => null, 'error' => 'invalid_password'];
        }

        $this->repo->resetLoginFailures($user['id']);

        if ($verify['usedLegacy'] || $this->hasher->shouldRehash($user['password_hash'])) {
            try {
                $newHash = $this->hasher->hash($password);
                $this->repo->updateUserPasswordHash((int)$user['id'], $newHash);
            } catch (\Throwable) {
                // ignore rehash failures
            }
        }

        return [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'tenant_id' => $user['tenant_id'],
                'must_change_password' => (bool)$user['must_change_password'],
                'status' => $user['status'],
            ],
            'error' => null,
        ];
    }

    private function sanitizeInt(int|string $value, int $default, int $min, int $max): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return $default;
        }
        return max($min, min($max, (int)$intVal));
    }
}
