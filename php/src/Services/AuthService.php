<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;

final class AuthService
{
    public function __construct(private Repository $repo = new Repository())
    {
    }

    /**
     * 認証を行い、成功時はユーザー情報を返却する。
     *
     * @return array{user: array{id:int,email:string,must_change_password:bool,status:?string}|null, error:?string}
     */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->repo->findUserByEmail($email);
        if (!$user) {
            return ['user' => null, 'error' => 'not_found'];
        }

        if ($user['status'] !== null && $user['status'] !== 'active') {
            return ['user' => null, 'error' => 'inactive'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->repo->recordLoginFailure($user['id']);
            return ['user' => null, 'error' => 'invalid_password'];
        }

        $this->repo->resetLoginFailures($user['id']);

        return [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'must_change_password' => (bool)$user['must_change_password'],
                'status' => $user['status'],
            ],
            'error' => null,
        ];
    }
}
