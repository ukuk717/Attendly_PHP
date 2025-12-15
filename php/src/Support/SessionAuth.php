<?php

declare(strict_types=1);

namespace Attendly\Support;

final class SessionAuth
{
    private const USER_KEY = '_user';
    private const SESSION_KEY = '_session_key';
    private const PENDING_MFA_KEY = '_pending_mfa';
    private const PENDING_TOTP_KEY = '_pending_totp';
    private const PENDING_TOTP_SHOWN_KEY = '_pending_totp_shown';
    private const RECOVERY_CODES_KEY = '_mfa_recovery_codes';
    private const PENDING_EMAIL_CHANGE_KEY = '_pending_email_change';
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

    public static function setSessionKey(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $_SESSION[self::SESSION_KEY] = $key;
    }

    public static function getSessionKey(): ?string
    {
        $value = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
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
        unset($_SESSION[self::SESSION_KEY]);
        unset($_SESSION[self::PENDING_MFA_KEY]);
        unset($_SESSION[self::PENDING_TOTP_KEY]);
        unset($_SESSION[self::RECOVERY_CODES_KEY]);
        unset($_SESSION[self::PENDING_EMAIL_CHANGE_KEY]);
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
        $maxAgeRaw = $_ENV['MFA_LOGIN_SESSION_TTL'] ?? 900;
        $maxAge = filter_var($maxAgeRaw, FILTER_VALIDATE_INT, ['options' => ['default' => 900]]);
        if ($maxAge === false) {
            $maxAge = 900;
        }
        if ($maxAge > 0) {
            $maxAge = max(60, min(3600, (int)$maxAge));
        } else {
            $maxAge = 0;
        }
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
        unset($_SESSION[self::PENDING_TOTP_SHOWN_KEY]);
    }

    public static function getPendingTotpSecret(): ?string
    {
        $pending = $_SESSION[self::PENDING_TOTP_KEY] ?? null;
        if (!is_array($pending) || empty($pending['secret'])) {
            return null;
        }
        $ttlRaw = $_ENV['MFA_TOTP_PENDING_TTL'] ?? 600;
        $ttl = filter_var($ttlRaw, FILTER_VALIDATE_INT, ['options' => ['default' => 600]]);
        if ($ttl === false) {
            $ttl = 600;
        }
        if ($ttl > 0) {
            $ttl = max(60, min(3600, (int)$ttl));
        } else {
            $ttl = 0;
        }
        if ($ttl > 0 && isset($pending['created_at']) && (time() - (int)$pending['created_at']) > $ttl) {
            self::clearPendingTotpSecret();
            return null;
        }
        return (string)$pending['secret'];
    }

    public static function hasShownPendingTotpSecret(): bool
    {
        $secret = self::getPendingTotpSecret();
        if ($secret === null) {
            unset($_SESSION[self::PENDING_TOTP_SHOWN_KEY]);
            return false;
        }
        return !empty($_SESSION[self::PENDING_TOTP_SHOWN_KEY]);
    }

    public static function markPendingTotpSecretShown(): void
    {
        $secret = self::getPendingTotpSecret();
        if ($secret === null) {
            return;
        }
        $_SESSION[self::PENDING_TOTP_SHOWN_KEY] = time();
    }

    public static function clearPendingTotpSecret(): void
    {
        unset($_SESSION[self::PENDING_TOTP_KEY]);
        unset($_SESSION[self::PENDING_TOTP_SHOWN_KEY]);
    }

    public static function resetPendingTotpSetup(): void
    {
        self::clearPendingTotpSecret();
    }

    public static function setPendingEmailChange(string $email, int $ttlSeconds): void
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $ttl = max(60, $ttlSeconds);
        $_SESSION[self::PENDING_EMAIL_CHANGE_KEY] = [
            'email' => $normalized,
            'expires_at' => time() + $ttl,
        ];
    }

    /**
     * @return array{email:string,expires_at:?int}|null
     */
    public static function getPendingEmailChange(): ?array
    {
        $pending = $_SESSION[self::PENDING_EMAIL_CHANGE_KEY] ?? null;
        if (!is_array($pending) || empty($pending['email'])) {
            return null;
        }
        $email = strtolower(trim((string)$pending['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::clearPendingEmailChange();
            return null;
        }
        $expiresAt = isset($pending['expires_at']) ? (int)$pending['expires_at'] : 0;
        if ($expiresAt > 0 && time() > $expiresAt) {
            self::clearPendingEmailChange();
            return null;
        }
        return [
            'email' => $email,
            'expires_at' => $expiresAt > 0 ? $expiresAt : null,
        ];
    }

    public static function clearPendingEmailChange(): void
    {
        unset($_SESSION[self::PENDING_EMAIL_CHANGE_KEY]);
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
