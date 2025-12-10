<?php

declare(strict_types=1);

namespace Attendly\Support;

final class SessionAuth
{
    private const USER_KEY = '_user';
    private const PENDING_MFA_KEY = '_pending_mfa';
    private const PENDING_TOTP_KEY = '_pending_totp';
    private const RECOVERY_CODES_KEY = '_mfa_recovery_codes';
    private const AUTH_TIME_KEY = '_auth_time';

    /**
     * @param array{id:int|null,email:string|null,role?:string|null,tenant_id?:int|null} $user
     */
    public static function setUser(array $user): void
    {
        $_SESSION[self::USER_KEY] = [
            'id' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'tenant_id' => isset($user['tenant_id']) ? (int)$user['tenant_id'] : null,
        ];
        $_SESSION[self::AUTH_TIME_KEY] = time();
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
        unset($_SESSION[self::PENDING_MFA_KEY]);
        unset($_SESSION[self::PENDING_TOTP_KEY]);
        unset($_SESSION[self::RECOVERY_CODES_KEY]);
        unset($_SESSION[self::AUTH_TIME_KEY]);
    }

    /**
     * @param array{
     *   user:array{id:int,email:string,role:?string,tenant_id:?int},
     *   methods:array<int,array{id:int,type:string,target_email:string}>,
     *   created_at?:int
     * } $payload
     */
    public static function setPendingMfa(array $payload): void
    {
        if (empty($payload['user']['id']) || empty($payload['user']['email'])) {
            return;
        }
        $methods = [];
        foreach ($payload['methods'] ?? [] as $method) {
            if (!is_array($method) || empty($method['id']) || empty($method['type'])) {
                continue;
            }
            $methods[] = [
                'id' => (int)$method['id'],
                'type' => (string)$method['type'],
                'target_email' => isset($method['target_email']) ? (string)$method['target_email'] : null,
            ];
        }
        if ($methods === []) {
            return;
        }
        $_SESSION[self::PENDING_MFA_KEY] = [
            'user' => [
                'id' => (int)$payload['user']['id'],
                'email' => (string)$payload['user']['email'],
                'role' => $payload['user']['role'] ?? null,
                'tenant_id' => isset($payload['user']['tenant_id']) ? (int)$payload['user']['tenant_id'] : null,
            ],
            'methods' => $methods,
            'created_at' => isset($payload['created_at']) ? (int)$payload['created_at'] : time(),
        ];
    }

    /**
     * @return array{
     *   user:array{id:int,email:string,role:?string,tenant_id:?int},
     *   methods:array<int,array{id:int,type:string,target_email:string}>,
     *   created_at:int
     * }|null
     */
    public static function getPendingMfa(): ?array
    {
        $pending = $_SESSION[self::PENDING_MFA_KEY] ?? null;
        if (!is_array($pending) || !isset($pending['user']['id'], $pending['user']['email'], $pending['methods'])) {
            return null;
        }
        $createdAt = isset($pending['created_at']) ? (int)$pending['created_at'] : 0;
        $maxAge = (int)($_ENV['MFA_LOGIN_SESSION_TTL'] ?? 900);
        if ($maxAge > 0 && time() - $createdAt > $maxAge) {
            self::clearPendingMfa();
            return null;
        }
        $methods = [];
        foreach ($pending['methods'] as $method) {
            if (!is_array($method) || empty($method['id']) || empty($method['type'])) {
                continue;
            }
            $methods[] = [
                'id' => (int)$method['id'],
                'type' => (string)$method['type'],
                'target_email' => isset($method['target_email']) ? (string)$method['target_email'] : null,
            ];
        }
        if ($methods === []) {
            self::clearPendingMfa();
            return null;
        }
        return [
            'user' => [
                'id' => (int)$pending['user']['id'],
                'email' => (string)$pending['user']['email'],
                'role' => $pending['user']['role'] ?? null,
                'tenant_id' => isset($pending['user']['tenant_id']) ? (int)$pending['user']['tenant_id'] : null,
            ],
            'methods' => $methods,
            'created_at' => $createdAt,
        ];
    }

    public static function clearPendingMfa(): void
    {
        unset($_SESSION[self::PENDING_MFA_KEY]);
    }

    public static function setPendingTotpSecret(string $secret): void
    {
        if ($secret === '') {
            return;
        }
        $_SESSION[self::PENDING_TOTP_KEY] = [
            'secret' => $secret,
            'created_at' => time(),
        ];
    }

    public static function getPendingTotpSecret(): ?string
    {
        $pending = $_SESSION[self::PENDING_TOTP_KEY] ?? null;
        if (!is_array($pending) || empty($pending['secret'])) {
            return null;
        }
        $ttl = (int)($_ENV['MFA_TOTP_PENDING_TTL'] ?? 600);
        if ($ttl > 0 && isset($pending['created_at']) && (time() - (int)$pending['created_at']) > $ttl) {
            self::clearPendingTotpSecret();
            return null;
        }
        return (string)$pending['secret'];
    }

    public static function clearPendingTotpSecret(): void
    {
        unset($_SESSION[self::PENDING_TOTP_KEY]);
    }

    /**
     * @param string[] $codes
     */
    public static function setRecoveryCodesForDisplay(array $codes): void
    {
        $filtered = [];
        foreach ($codes as $code) {
            $codeStr = trim((string)$code);
            if ($codeStr !== '') {
                $filtered[] = $codeStr;
            }
        }
        if ($filtered === []) {
            return;
        }
        $_SESSION[self::RECOVERY_CODES_KEY] = $filtered;
    }

    /**
     * @return string[]
     */
    public static function consumeRecoveryCodesForDisplay(): array
    {
        $codes = $_SESSION[self::RECOVERY_CODES_KEY] ?? [];
        unset($_SESSION[self::RECOVERY_CODES_KEY]);
        if (!is_array($codes)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $codes), static fn(string $c): bool => trim($c) !== ''));
    }

    public static function hasRecentAuthentication(int $ttlSeconds): bool
    {
        if ($ttlSeconds <= 0) {
            return true;
        }
        $timestamp = $_SESSION[self::AUTH_TIME_KEY] ?? null;
        if (!is_int($timestamp)) {
            return false;
        }
        return (time() - $timestamp) <= $ttlSeconds;
    }
}
