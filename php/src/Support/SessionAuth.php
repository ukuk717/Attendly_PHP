<?php

declare(strict_types=1);

namespace Attendly\Support;

final class SessionAuth
{
    private const USER_KEY = '_user';

    /**
     * @param array{id:int|null,email:string|null,role?:string|null,tenant_id?:int|null} $user
     */
    public static function setUser(array $user): void
    {
        $_SESSION[self::USER_KEY] = [
            'id' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'tenant_id' => $user['tenant_id'] ?? null,
        ];
    }

    /**
     * @return array{id:int|null,email:string|null,role:?string,tenant_id:?int}|null
     */
    public static function getUser(): ?array
    {
        $user = $_SESSION[self::USER_KEY] ?? null;
        if (!is_array($user)) {
            return null;
        }
        return [
            'id' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'tenant_id' => isset($user['tenant_id']) ? (int)$user['tenant_id'] : null,
        ];
    }

    public static function clear(): void
    {
        unset($_SESSION[self::USER_KEY]);
    }
}
