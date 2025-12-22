<?php

declare(strict_types=1);

namespace Attendly\Database;

use Attendly\Database;
use Attendly\Support\AppTime;
use Attendly\Security\TenantDataCipher;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class Repository
{
    private PDO $pdo;
    private ?bool $payrollDownloadedAtAvailable = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array{id:int, tenant_id:int|null, username:string, email:string, password_hash:string, role:string, employment_type:?string, must_change_password:bool, status:?string, failed_attempts:int, locked_until:?DateTimeImmutable}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, username, email, password_hash, role, employment_type, must_change_password, status, failed_attempts, locked_until
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'username' => (string)$row['username'],
            'email' => (string)$row['email'],
            'password_hash' => (string)$row['password_hash'],
            'role' => (string)$row['role'],
            'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
            'must_change_password' => (bool)$row['must_change_password'],
            'status' => $row['status'] !== null ? (string)$row['status'] : null,
            'failed_attempts' => (int)$row['failed_attempts'],
            'locked_until' => AppTime::fromStorage($row['locked_until']),
        ];
    }

    /**
     * @return array{id:int, tenant_id:int|null, username:string, email:string, password_hash:string, role:string, employment_type:?string, must_change_password:bool, status:?string, failed_attempts:int, locked_until:?DateTimeImmutable}|null
     */
    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, username, email, password_hash, role, employment_type, must_change_password, status, failed_attempts, locked_until
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'username' => (string)$row['username'],
            'email' => (string)$row['email'],
            'password_hash' => (string)$row['password_hash'],
            'role' => (string)$row['role'],
            'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
            'must_change_password' => (bool)$row['must_change_password'],
            'status' => $row['status'] !== null ? (string)$row['status'] : null,
            'failed_attempts' => (int)$row['failed_attempts'],
            'locked_until' => AppTime::fromStorage($row['locked_until']),
        ];
    }

    /**
     * @return array{id:int,email:string,first_name:?string,last_name:?string,tenant_id:?int}|null
     */
    public function findUserProfile(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, tenant_id FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
            'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
        ];
    }

    public function recordLoginFailure(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * 従業員ユーザーを作成する（ロールやステータスは呼び出し元で決定）。
     *
     * @param array{
     *   tenant_id:?int,
     *   username:string,
     *   email:string,
     *   password_hash:string,
     *   role:string,
     *   employment_type?:?string,
     *   status:string,
     *   must_change_password?:bool,
     *   first_name?:string,
     *   last_name?:string,
     *   phone_number?:?string
     * } $data
     * @return array{id:int,tenant_id:?int,username:string,email:string,role:string,status:string}
     */
    public function createUser(array $data): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, username, email, password_hash, role, employment_type, must_change_password, first_name, last_name, phone_number, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['tenant_id'] ?? null,
            $data['username'],
            $data['email'],
            $data['password_hash'],
            $data['role'],
            $data['employment_type'] ?? null,
            !empty($data['must_change_password']) ? 1 : 0,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['phone_number'] ?? null,
            $data['status'],
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();

        return [
            'id' => $id,
            'tenant_id' => $data['tenant_id'] ?? null,
            'username' => $data['username'],
            'email' => $data['email'],
            'role' => $data['role'],
            'status' => $data['status'],
        ];
    }

    public function updateUserProfile(int $userId, ?string $firstName, ?string $lastName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET first_name = ?, last_name = ? WHERE id = ?'
        );
        $stmt->execute([$firstName, $lastName, $userId]);
    }

    public function updateUserStatus(int $userId, string $status): void
    {
        $now = AppTime::now();
        if ($status === 'active') {
            $stmt = $this->pdo->prepare('UPDATE users SET status = ?, deactivated_at = NULL WHERE id = ?');
            $stmt->execute([$status, $userId]);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE users SET status = ?, deactivated_at = ? WHERE id = ?');
        $stmt->execute([$status, $this->formatDateTime($now), $userId]);
    }

    public function updateUserEmploymentType(int $userId, ?string $employmentType): void
    {
        $value = $employmentType !== null ? strtolower(trim($employmentType)) : null;
        if ($value === '') {
            $value = null;
        }
        $allowed = [null, 'part_time', 'full_time'];
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException('雇用区分が不正です。');
        }
        $stmt = $this->pdo->prepare('UPDATE users SET employment_type = ? WHERE id = ?');
        $stmt->execute([$value, $userId]);
    }

    public function resetLoginFailures(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * ログイン失敗回数を加算し、しきい値到達時にロック（locked_until）を設定する。
     *
     * @return array{failed_attempts:int,locked_until:?DateTimeImmutable}
     */
    public function registerLoginFailureAndMaybeLock(int $userId, int $maxAttempts, int $lockSeconds): array
    {
        $maxAttempts = max(1, min(50, $maxAttempts));
        $lockSeconds = max(0, min(3600, $lockSeconds));

        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT failed_attempts, locked_until FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if ($started) {
                    $this->pdo->rollBack();
                }
                return ['failed_attempts' => 0, 'locked_until' => null];
            }

            $now = AppTime::now();
            $lockedUntil = AppTime::fromStorage($row['locked_until']);
            $attempts = (int)$row['failed_attempts'];
            if ($lockedUntil !== null && $lockedUntil <= $now) {
                $lockedUntil = null;
                $attempts = 0;
            }

            $attempts++;
            if ($lockSeconds > 0 && $attempts >= $maxAttempts) {
                $lockedUntil = $now->modify('+' . $lockSeconds . ' seconds');
                $attempts = 0;
            }

            $update = $this->pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
            $update->execute([
                $attempts,
                $lockedUntil ? $this->formatDateTime($lockedUntil) : null,
                $userId,
            ]);

            if ($started) {
                $this->pdo->commit();
            }

            return [
                'failed_attempts' => $attempts,
                'locked_until' => $lockedUntil,
            ];
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array{id:int,user_id:int,type:string,secret:?string,config:?array,verified_at:?DateTimeImmutable,last_used_at:?DateTimeImmutable}>
     */
    public function listVerifiedMfaMethods(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, type, secret, config_json, verified_at, last_used_at
             FROM user_mfa_methods
             WHERE user_id = ?
               AND is_verified = 1
             ORDER BY type ASC, id ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $methods = [];
        foreach ($rows as $row) {
            $methods[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'type' => (string)$row['type'],
                'secret' => $row['secret'] !== null ? (string)$row['secret'] : null,
                'config' => $this->decodeJsonConfig($row['config_json']),
                'verified_at' => AppTime::fromStorage($row['verified_at']),
                'last_used_at' => AppTime::fromStorage($row['last_used_at']),
            ];
        }
        return $methods;
    }

    public function touchMfaMethodUsed(int $methodId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_mfa_methods SET last_used_at = ?, updated_at = ? WHERE id = ?'
        );
        $now = AppTime::now();
        $formatted = $this->formatDateTime($now);
        $stmt->execute([$formatted, $formatted, $methodId]);
    }

    /**
     * @return array{id:int,user_id:int,type:string,secret:?string,config:?array,verified_at:?DateTimeImmutable,last_used_at:?DateTimeImmutable}|null
     */
    public function findVerifiedMfaMethodById(int $userId, int $methodId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, type, secret, config_json, verified_at, last_used_at
             FROM user_mfa_methods
             WHERE id = ? AND user_id = ? AND is_verified = 1
             LIMIT 1'
        );
        $stmt->execute([$methodId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'type' => (string)$row['type'],
            'secret' => $row['secret'] !== null ? (string)$row['secret'] : null,
            'config' => $this->decodeJsonConfig($row['config_json']),
            'verified_at' => AppTime::fromStorage($row['verified_at']),
            'last_used_at' => AppTime::fromStorage($row['last_used_at']),
        ];
    }

    /**
     * @return array{id:int,user_id:int,type:string,secret:?string,config:?array,verified_at:?DateTimeImmutable,last_used_at:?DateTimeImmutable}|null
     */
    public function findVerifiedMfaMethodByType(int $userId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, type, secret, config_json, verified_at, last_used_at
             FROM user_mfa_methods
             WHERE user_id = ? AND type = ? AND is_verified = 1
             LIMIT 1'
        );
        $stmt->execute([$userId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'type' => (string)$row['type'],
            'secret' => $row['secret'] !== null ? (string)$row['secret'] : null,
            'config' => $this->decodeJsonConfig($row['config_json']),
            'verified_at' => AppTime::fromStorage($row['verified_at']),
            'last_used_at' => AppTime::fromStorage($row['last_used_at']),
        ];
    }

    public function deleteMfaMethodsByUserAndType(int $userId, string $type): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_mfa_methods WHERE user_id = ? AND type = ?');
        $stmt->execute([$userId, $type]);
    }

    /**
     * @return array{id:int,user_id:int,type:string,secret:?string,config:?array,verified_at:?DateTimeImmutable,last_used_at:?DateTimeImmutable}
     */
    public function upsertTotpMethod(int $userId, string $secret, array $config = []): array
    {
        $now = AppTime::now();
        $existing = $this->findVerifiedMfaMethodByType($userId, 'totp');
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE user_mfa_methods
                 SET secret = ?, config_json = ?, is_verified = 1, verified_at = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $secret,
                $this->encodeMetadata($config),
                $this->formatDateTime($now),
                $this->formatDateTime($now),
                $existing['id'],
            ]);
            return $this->findVerifiedMfaMethodById($userId, (int)$existing['id']) ?? $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_mfa_methods (user_id, type, secret, config_json, is_verified, verified_at, last_used_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, NULL, ?, ?)'
        );
        $stmt->execute([
            $userId,
            'totp',
            $secret,
            $this->encodeMetadata($config),
            $this->formatDateTime($now),
            $this->formatDateTime($now),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $found = $this->findVerifiedMfaMethodById($userId, $id);
        if ($found === null) {
            throw new RuntimeException('TOTPメソッドの作成に失敗しました。');
        }
        return $found;
    }

    /**
     * @return array{id:int,user_id:int,type:string,secret:?string,config:?array,verified_at:?DateTimeImmutable,last_used_at:?DateTimeImmutable}|null
     */
    public function updateMfaFailureState(int $methodId, bool $reset, int $maxFailures, int $lockSeconds): ?array
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, user_id, type, secret, config_json, verified_at, last_used_at
                 FROM user_mfa_methods
                 WHERE id = ? AND is_verified = 1
                 FOR UPDATE'
            );
            $stmt->execute([$methodId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if ($started) {
                    $this->pdo->rollBack();
                }
                return null;
            }
            $config = $this->decodeJsonConfig($row['config_json']) ?? [];
            if ($reset) {
                $config['failedAttempts'] = 0;
                $config['lockUntil'] = null;
            } else {
                $attempts = isset($config['failedAttempts']) ? (int)$config['failedAttempts'] + 1 : 1;
                $config['failedAttempts'] = $attempts;
                if ($attempts >= $maxFailures && $lockSeconds > 0) {
                    $config['lockUntil'] = AppTime::now()->modify("+{$lockSeconds} seconds")->format('c');
                }
            }
            $update = $this->pdo->prepare(
                'UPDATE user_mfa_methods SET config_json = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([
                $this->encodeMetadata($config),
                $this->formatDateTime(AppTime::now()),
                $methodId,
            ]);
            if ($started) {
                $this->pdo->commit();
            }
            return [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'type' => (string)$row['type'],
                'secret' => $row['secret'] !== null ? (string)$row['secret'] : null,
                'config' => $config,
                'verified_at' => AppTime::fromStorage($row['verified_at']),
                'last_used_at' => AppTime::fromStorage($row['last_used_at']),
            ];
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id:int,user_id:int,code_hash:string,used_at:?DateTimeImmutable}|null
     */
    public function findUsableRecoveryCode(int $userId, string $codeHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, code_hash, used_at
             FROM user_mfa_recovery_codes
             WHERE user_id = ? AND code_hash = ? AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$userId, $codeHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'code_hash' => (string)$row['code_hash'],
            'used_at' => AppTime::fromStorage($row['used_at']),
        ];
    }

    public function markRecoveryCodeUsed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_mfa_recovery_codes SET used_at = ? WHERE id = ?'
        );
        $stmt->execute([$this->formatDateTime(AppTime::now()), $id]);
    }

    public function hasActiveRecoveryCodes(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM user_mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   user_id:int,
     *   name:?string,
     *   credential_id:string,
     *   user_handle:string,
     *   transports:?array,
     *   sign_count:int,
     *   last_used_at:?DateTimeImmutable,
     *   created_at:DateTimeImmutable
     * }>
     */
    public function listPasskeysByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, credential_id, user_handle, transports_json, sign_count, last_used_at, created_at
             FROM user_passkeys
             WHERE user_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapPasskeyRow($row);
        }
        return $result;
    }

    public function countPasskeysByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM user_passkeys WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['c'])) {
            return 0;
        }
        return (int)$row['c'];
    }

    /**
     * @return array{id:int,user_id:int,name:?string,credential_id:string,user_handle:string,transports:?array,public_key:string,sign_count:int,last_used_at:?DateTimeImmutable,created_at:DateTimeImmutable}|null
     */
    public function findPasskeyByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, credential_id, user_handle, transports_json, public_key, sign_count, last_used_at, created_at
             FROM user_passkeys
             WHERE credential_id = ?
             LIMIT 1'
        );
        $stmt->execute([$credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $mapped = $this->mapPasskeyRow($row);
        return array_merge($mapped, [
            'public_key' => (string)$row['public_key'],
        ]);
    }

    /**
     * @return array{id:int,user_id:int,name:?string,credential_id:string,user_handle:string,transports:?array,sign_count:int,last_used_at:?DateTimeImmutable,created_at:DateTimeImmutable}
     */
    public function createPasskey(
        int $userId,
        string $credentialId,
        string $publicKey,
        int $signCount,
        string $userHandle,
        ?string $name,
        ?array $transports
    ): array {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_passkeys (user_id, name, credential_id, public_key, user_handle, transports_json, sign_count, last_used_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([
            $userId,
            $name,
            $credentialId,
            $publicKey,
            $userHandle,
            $this->encodeMetadata($transports),
            max(0, $signCount),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $row = $this->findPasskeyById($id);
        if ($row === null) {
            throw new RuntimeException('パスキーの作成に失敗しました。');
        }
        return $row;
    }

    /**
     * @return array{id:int,user_id:int,name:?string,credential_id:string,user_handle:string,transports:?array,sign_count:int,last_used_at:?DateTimeImmutable,created_at:DateTimeImmutable}|null
     */
    public function findPasskeyById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, credential_id, user_handle, transports_json, sign_count, last_used_at, created_at
             FROM user_passkeys
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->mapPasskeyRow($row);
    }

    public function touchPasskeyUsed(int $id, ?int $signCount = null): void
    {
        $now = $this->formatDateTime(AppTime::now());
        $fields = ['last_used_at = ?', 'updated_at = ?'];
        $params = [$now, $now];
        if ($signCount !== null) {
            $fields[] = 'sign_count = ?';
            $params[] = max(0, $signCount);
        }
        $params[] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE user_passkeys SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->execute($params);
    }

    public function deletePasskeyById(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_passkeys WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function findTrustedDeviceByHash(int $userId, string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, device_info, expires_at, last_used_at
             FROM user_mfa_trusted_devices
             WHERE user_id = ? AND token_hash = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'token_hash' => (string)$row['token_hash'],
            'device_info' => $row['device_info'] !== null ? (string)$row['device_info'] : null,
            'expires_at' => AppTime::fromStorage($row['expires_at']) ?? AppTime::now(),
            'last_used_at' => AppTime::fromStorage($row['last_used_at']),
        ];
    }

    /**
     * @return array{id:int,user_id:int,token_hash:string,device_info:?string,expires_at:DateTimeImmutable,last_used_at:?DateTimeImmutable}
     */
    public function createTrustedDevice(int $userId, string $tokenHash, ?string $deviceInfo, \DateTimeImmutable $expiresAt): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_mfa_trusted_devices (user_id, token_hash, device_info, expires_at, last_used_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $tokenHash,
            $deviceInfo,
            $this->formatDateTime($expiresAt),
            $this->formatDateTime($now),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $found = $this->findTrustedDeviceById($id);
        if ($found === null) {
            throw new RuntimeException('トラストデバイスの作成に失敗しました。');
        }
        return $found;
    }

    /**
     * @return array{id:int,user_id:int,token_hash:string,device_info:?string,expires_at:DateTimeImmutable,last_used_at:?DateTimeImmutable}|null
     */
    public function findTrustedDeviceById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, device_info, expires_at, last_used_at
             FROM user_mfa_trusted_devices
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'token_hash' => (string)$row['token_hash'],
            'device_info' => $row['device_info'] !== null ? (string)$row['device_info'] : null,
            'expires_at' => AppTime::fromStorage($row['expires_at']) ?? AppTime::now(),
            'last_used_at' => AppTime::fromStorage($row['last_used_at']),
        ];
    }

    public function touchTrustedDevice(int $id): void
    {
        $now = $this->formatDateTime(AppTime::now());
        $stmt = $this->pdo->prepare(
            'UPDATE user_mfa_trusted_devices SET last_used_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $now, $id]);
    }

    public function deleteTrustedDevicesByUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_mfa_trusted_devices WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   username:string,
     *   email:string,
     *   phone_number:?string,
     *   tenant_id:?int,
     *   tenant_name:?string,
     *   tenant_uid:?string,
     *   tenant_contact_email:?string,
     *   tenant_contact_phone:?string,
     *   tenant_created_at:?DateTimeImmutable,
     *   tenant_status:?string,
     *   status:string
     * }>
     */
    public function listTenantAdminsForPlatform(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.email, u.phone_number, u.tenant_id, u.status,
                    t.name AS tenant_name,
                    t.tenant_uid AS tenant_uid,
                    t.contact_email AS tenant_contact_email,
                    t.contact_phone AS tenant_contact_phone,
                    t.created_at AS tenant_created_at,
                    t.status AS tenant_status
             FROM users u
             LEFT JOIN tenants t ON u.tenant_id = t.id
             WHERE (u.role = "tenant_admin" OR (u.role = "admin" AND u.tenant_id IS NOT NULL))
              ORDER BY u.id ASC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $tenantName = TenantDataCipher::decrypt($row['tenant_name'] ?? null);
            $tenantContactEmail = TenantDataCipher::decrypt($row['tenant_contact_email'] ?? null);
            $tenantContactPhone = TenantDataCipher::decrypt($row['tenant_contact_phone'] ?? null);
            $result[] = [
                'id' => (int)$row['id'],
                'username' => (string)$row['username'],
                'email' => (string)$row['email'],
                'phone_number' => $row['phone_number'] !== null ? (string)$row['phone_number'] : null,
                'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
                'tenant_name' => $tenantName !== null ? (string)$tenantName : null,
                'tenant_uid' => $row['tenant_uid'] !== null ? (string)$row['tenant_uid'] : null,
                'tenant_contact_email' => $tenantContactEmail !== null ? (string)$tenantContactEmail : null,
                'tenant_contact_phone' => $tenantContactPhone !== null ? (string)$tenantContactPhone : null,
                'tenant_created_at' => AppTime::fromStorage($row['tenant_created_at']),
                'tenant_status' => $row['tenant_status'] !== null ? (string)$row['tenant_status'] : null,
                'status' => (string)$row['status'],
            ];
        }
        return $result;
    }

    /**
     * @return array{
     *   id:int,
     *   tenant_id:?int,
     *   username:string,
     *   email:string,
     *   phone_number:?string,
     *   role:string,
     *   status:string,
     *   tenant_name:?string,
     *   tenant_uid:?string,
     *   tenant_contact_email:?string,
     *   tenant_contact_phone:?string,
     *   tenant_created_at:?DateTimeImmutable
     * }|null
     */
    public function findTenantAdminById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.tenant_id, u.username, u.email, u.phone_number, u.role, u.status,
                    t.name AS tenant_name,
                    t.tenant_uid AS tenant_uid,
                    t.contact_email AS tenant_contact_email,
                    t.contact_phone AS tenant_contact_phone,
                    t.created_at AS tenant_created_at
             FROM users u
             LEFT JOIN tenants t ON u.tenant_id = t.id
             WHERE u.id = ? AND (u.role = "tenant_admin" OR (u.role = "admin" AND u.tenant_id IS NOT NULL))
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $tenantName = TenantDataCipher::decrypt($row['tenant_name'] ?? null);
        $tenantContactEmail = TenantDataCipher::decrypt($row['tenant_contact_email'] ?? null);
        $tenantContactPhone = TenantDataCipher::decrypt($row['tenant_contact_phone'] ?? null);
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'username' => (string)$row['username'],
            'email' => (string)$row['email'],
            'phone_number' => $row['phone_number'] !== null ? (string)$row['phone_number'] : null,
            'role' => (string)$row['role'],
            'status' => (string)$row['status'],
            'tenant_name' => $tenantName !== null ? (string)$tenantName : null,
            'tenant_uid' => $row['tenant_uid'] !== null ? (string)$row['tenant_uid'] : null,
            'tenant_contact_email' => $tenantContactEmail !== null ? (string)$tenantContactEmail : null,
            'tenant_contact_phone' => $tenantContactPhone !== null ? (string)$tenantContactPhone : null,
            'tenant_created_at' => AppTime::fromStorage($row['tenant_created_at']),
        ];
    }

    /**
     * @return array{
     *   secret:?string,
     *   config_json:?string,
     *   verified_at:?string,
     *   last_used_at:?string,
     *   created_at:?string,
     *   updated_at:?string
     * }|null
     */
    public function findVerifiedMfaMethodRawByType(int $userId, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT secret, config_json, verified_at, last_used_at, created_at, updated_at
             FROM user_mfa_methods
             WHERE user_id = ? AND type = ? AND is_verified = 1
             LIMIT 1'
        );
        $stmt->execute([$userId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'secret' => $row['secret'] !== null ? (string)$row['secret'] : null,
            'config_json' => $row['config_json'] !== null ? (string)$row['config_json'] : null,
            'verified_at' => $row['verified_at'] !== null ? (string)$row['verified_at'] : null,
            'last_used_at' => $row['last_used_at'] !== null ? (string)$row['last_used_at'] : null,
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
        ];
    }

    public function restoreTotpMethodFromSnapshot(int $userId, array $snapshot): void
    {
        $secret = isset($snapshot['secret']) ? (string)$snapshot['secret'] : '';
        if ($secret === '') {
            return;
        }
        $configJson = $snapshot['config_json'] ?? null;
        $configJson = is_string($configJson) ? $configJson : null;
        $verifiedAt = $snapshot['verified_at'] ?? null;
        $verifiedAt = is_string($verifiedAt) ? $verifiedAt : null;
        $lastUsedAt = $snapshot['last_used_at'] ?? null;
        $lastUsedAt = is_string($lastUsedAt) ? $lastUsedAt : null;
        $createdAt = $snapshot['created_at'] ?? null;
        $createdAt = is_string($createdAt) ? $createdAt : null;
        $updatedAt = $snapshot['updated_at'] ?? null;
        $updatedAt = is_string($updatedAt) ? $updatedAt : null;

        $now = $this->formatDateTime(AppTime::now());
        $stmt = $this->pdo->prepare('DELETE FROM user_mfa_methods WHERE user_id = ? AND type = "totp"');
        $stmt->execute([$userId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO user_mfa_methods (user_id, type, secret, config_json, is_verified, verified_at, last_used_at, created_at, updated_at)
             VALUES (?, "totp", ?, ?, 1, ?, ?, ?, ?)'
        );
        $insert->execute([
            $userId,
            $secret,
            $configJson,
            $verifiedAt ?? $now,
            $lastUsedAt,
            $createdAt ?? $now,
            $updatedAt ?? $now,
        ]);
    }

    /**
     * @return array<int, array{code_hash:string,used_at:?string,created_at:?string}>
     */
    public function listRecoveryCodesRawByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT code_hash, used_at, created_at
             FROM user_mfa_recovery_codes
             WHERE user_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'code_hash' => (string)$row['code_hash'],
                'used_at' => $row['used_at'] !== null ? (string)$row['used_at'] : null,
                'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
            ];
        }
        return $result;
    }

    /**
     * @return array{session_hash:string,last_login_at:DateTimeImmutable,last_login_ip:?string,last_login_ua:?string}|null
     */
    public function findUserActiveSession(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT session_hash, last_login_at, last_login_ip, last_login_ua
             FROM user_active_sessions
             WHERE user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'session_hash' => (string)$row['session_hash'],
            'last_login_at' => AppTime::fromStorage((string)$row['last_login_at']) ?? AppTime::now(),
            'last_login_ip' => $row['last_login_ip'] !== null ? (string)$row['last_login_ip'] : null,
            'last_login_ua' => $row['last_login_ua'] !== null ? (string)$row['last_login_ua'] : null,
        ];
    }

    public function upsertUserActiveSession(int $userId, string $sessionHash, ?string $loginIp, ?string $loginUa): void
    {
        $sessionHash = trim($sessionHash);
        if ($sessionHash === '' || strlen($sessionHash) !== 64) {
            throw new \InvalidArgumentException('無効なセッションハッシュです。');
        }
        $ip = $loginIp !== null ? trim($loginIp) : null;
        if ($ip !== null && mb_strlen($ip, 'UTF-8') > 64) {
            $ip = mb_substr($ip, 0, 64, 'UTF-8');
        }
        $ua = $loginUa !== null ? trim($loginUa) : null;
        if ($ua !== null) {
            $ua = preg_replace('/[\r\n]/', ' ', $ua) ?? '';
            $ua = trim($ua);
            if ($ua !== '' && mb_strlen($ua, 'UTF-8') > 512) {
                $ua = mb_substr($ua, 0, 512, 'UTF-8');
            }
            if ($ua === '') {
                $ua = null;
            }
        }

        $now = $this->formatDateTime(AppTime::now());
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_active_sessions (user_id, session_hash, last_login_at, last_login_ip, last_login_ua, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               session_hash = VALUES(session_hash),
               last_login_at = VALUES(last_login_at),
               last_login_ip = VALUES(last_login_ip),
               last_login_ua = VALUES(last_login_ua),
               updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            $userId,
            $sessionHash,
            $now,
            $ip,
            $ua,
            $now,
            $now,
        ]);
    }

    public function deleteUserActiveSession(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_active_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * @return array{id:int}
     */
    public function createLoginSession(
        int $userId,
        string $sessionHash,
        DateTimeImmutable $loginAt,
        ?string $loginIp,
        ?string $userAgent
    ): array {
        $sessionHash = trim($sessionHash);
        if ($sessionHash === '' || strlen($sessionHash) !== 64) {
            throw new \InvalidArgumentException('無効なセッションハッシュです。');
        }
        $ip = $loginIp !== null ? trim($loginIp) : null;
        if ($ip !== null && mb_strlen($ip, 'UTF-8') > 64) {
            $ip = mb_substr($ip, 0, 64, 'UTF-8');
        }
        $ua = $userAgent !== null ? trim($userAgent) : null;
        if ($ua !== null) {
            $ua = preg_replace('/[\r\n]/', ' ', $ua) ?? '';
            $ua = trim($ua);
            if ($ua !== '' && mb_strlen($ua, 'UTF-8') > 512) {
                $ua = mb_substr($ua, 0, 512, 'UTF-8');
            }
            if ($ua === '') {
                $ua = null;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_login_sessions (user_id, session_hash, login_at, login_ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $now = AppTime::now();
        $stmt->execute([
            $userId,
            $sessionHash,
            $this->formatDateTime($loginAt),
            $ip,
            $ua,
            $this->formatDateTime($now),
        ]);
        return ['id' => (int)$this->pdo->lastInsertId()];
    }

    public function revokeOtherLoginSessions(int $userId, string $currentHash, DateTimeImmutable $revokedAt): void
    {
        $currentHash = trim($currentHash);
        if ($currentHash === '' || strlen($currentHash) !== 64) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE user_login_sessions
             SET revoked_at = ?
             WHERE user_id = ? AND session_hash <> ? AND revoked_at IS NULL'
        );
        $stmt->execute([
            $this->formatDateTime($revokedAt),
            $userId,
            $currentHash,
        ]);
    }

    /**
     * @return array{id:int,user_id:int,session_hash:string,login_at:DateTimeImmutable,revoked_at:?DateTimeImmutable}|null
     */
    public function findLoginSessionByHash(string $sessionHash): ?array
    {
        $sessionHash = trim($sessionHash);
        if ($sessionHash === '' || strlen($sessionHash) !== 64) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, session_hash, login_at, revoked_at
             FROM user_login_sessions
             WHERE session_hash = ?
             LIMIT 1'
        );
        $stmt->execute([$sessionHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'session_hash' => (string)$row['session_hash'],
            'login_at' => AppTime::fromStorage((string)$row['login_at']) ?? AppTime::now(),
            'revoked_at' => AppTime::fromStorage($row['revoked_at']),
        ];
    }

    /**
     * @return array<int, array{id:int,login_at:DateTimeImmutable,user_agent:?string,revoked_at:?DateTimeImmutable,last_seen_at:?DateTimeImmutable}>
     */
    public function listLoginSessionsByUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, login_at, user_agent, revoked_at, last_seen_at
             FROM user_login_sessions
             WHERE user_id = ?
             ORDER BY login_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'login_at' => AppTime::fromStorage((string)$row['login_at']) ?? AppTime::now(),
                'user_agent' => $row['user_agent'] !== null ? (string)$row['user_agent'] : null,
                'revoked_at' => AppTime::fromStorage($row['revoked_at']),
                'last_seen_at' => AppTime::fromStorage($row['last_seen_at']),
            ];
        }
        return $result;
    }

    public function revokeLoginSessionById(int $userId, int $sessionId, DateTimeImmutable $revokedAt): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_login_sessions
             SET revoked_at = ?
             WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([
            $this->formatDateTime($revokedAt),
            $sessionId,
            $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array{
     *   token_hash:string,
     *   target_type:string,
     *   source_id:?int,
     *   file_path:string,
     *   file_name:string,
     *   content_type:?string,
     *   created_by:?int,
     *   expires_at:DateTimeImmutable
     * } $data
     * @return array{id:int,token_hash:string,expires_at:DateTimeImmutable}
     */
    public function createSignedDownload(array $data): array
    {
        $tokenHash = trim((string)$data['token_hash']);
        if ($tokenHash === '' || strlen($tokenHash) !== 64) {
            throw new \InvalidArgumentException('無効なトークンです。');
        }
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO signed_downloads (token_hash, target_type, source_id, file_path, file_name, content_type, created_by, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tokenHash,
            (string)$data['target_type'],
            $data['source_id'] ?? null,
            (string)$data['file_path'],
            (string)$data['file_name'],
            $data['content_type'] ?? null,
            $data['created_by'] ?? null,
            $this->formatDateTime($data['expires_at']),
            $this->formatDateTime($now),
        ]);
        return [
            'id' => (int)$this->pdo->lastInsertId(),
            'token_hash' => $tokenHash,
            'expires_at' => $data['expires_at'],
        ];
    }

    /**
     * @return array{id:int,token_hash:string,target_type:string,source_id:?int,file_path:string,file_name:string,content_type:?string,expires_at:DateTimeImmutable,revoked_at:?DateTimeImmutable}|null
     */
    public function findSignedDownloadByHash(string $tokenHash): ?array
    {
        $tokenHash = trim($tokenHash);
        if ($tokenHash === '' || strlen($tokenHash) !== 64) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, token_hash, target_type, source_id, file_path, file_name, content_type, expires_at, revoked_at
             FROM signed_downloads
             WHERE token_hash = ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'token_hash' => (string)$row['token_hash'],
            'target_type' => (string)$row['target_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'file_path' => (string)$row['file_path'],
            'file_name' => (string)$row['file_name'],
            'content_type' => $row['content_type'] !== null ? (string)$row['content_type'] : null,
            'expires_at' => AppTime::fromStorage((string)$row['expires_at']) ?? AppTime::now(),
            'revoked_at' => AppTime::fromStorage($row['revoked_at']),
        ];
    }

    public function touchSignedDownload(int $id, DateTimeImmutable $accessedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE signed_downloads SET last_accessed_at = ? WHERE id = ?'
        );
        $stmt->execute([$this->formatDateTime($accessedAt), $id]);
    }

    public function deleteExpiredSignedDownloads(DateTimeImmutable $now, int $limit = 5000): int
    {
        $limit = max(1, min(10000, $limit));
        $stmt = $this->pdo->prepare(
            'DELETE FROM signed_downloads WHERE expires_at < ? OR revoked_at IS NOT NULL LIMIT ?'
        );
        $stmt->bindValue(1, $this->formatDateTime($now), PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function beginTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        $this->pdo->rollBack();
    }

    /**
     * @param int[] $userIds
     * @return array<int, true> userId => true
     */
    public function mapUserIdsWithVerifiedMfaType(array $userIds, string $type): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $intId = (int)$id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT user_id FROM user_mfa_methods WHERE user_id IN ({$placeholders}) AND type = ? AND is_verified = 1";
        $stmt = $this->pdo->prepare($sql);
        $params = array_keys($ids);
        $params[] = $type;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (isset($row['user_id'])) {
                $result[(int)$row['user_id']] = true;
            }
        }
        return $result;
    }

    /**
     * @param int[] $userIds
     * @return array<int, array{id:int,reason:string,previous_method_json:?string,previous_recovery_codes_json:?string,created_at:DateTimeImmutable,rolled_back_at:?DateTimeImmutable,rollback_reason:?string}>
     */
    public function mapLatestTenantAdminMfaResetLogsByUser(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $intId = (int)$id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT l.target_user_id, l.id, l.reason, l.previous_method_json, l.previous_recovery_codes_json, l.created_at, l.rolled_back_at, l.rollback_reason
                FROM tenant_admin_mfa_reset_logs l
                INNER JOIN (
                  SELECT target_user_id, MAX(id) AS max_id
                  FROM tenant_admin_mfa_reset_logs
                  WHERE target_user_id IN ({$placeholders})
                  GROUP BY target_user_id
                ) latest ON latest.target_user_id = l.target_user_id AND latest.max_id = l.id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_keys($ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $userId = isset($row['target_user_id']) ? (int)$row['target_user_id'] : 0;
            if ($userId <= 0) {
                continue;
            }
            $result[$userId] = [
                'id' => (int)$row['id'],
                'reason' => (string)$row['reason'],
                'previous_method_json' => $row['previous_method_json'] !== null ? (string)$row['previous_method_json'] : null,
                'previous_recovery_codes_json' => $row['previous_recovery_codes_json'] !== null ? (string)$row['previous_recovery_codes_json'] : null,
                'created_at' => AppTime::fromStorage((string)$row['created_at']) ?? AppTime::now(),
                'rolled_back_at' => AppTime::fromStorage($row['rolled_back_at']),
                'rollback_reason' => $row['rollback_reason'] !== null ? (string)$row['rollback_reason'] : null,
            ];
        }
        return $result;
    }

    public function countTenantAdminsForPlatform(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users WHERE role = "tenant_admin" OR (role = "admin" AND tenant_id IS NOT NULL)');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['c'])) {
            return 0;
        }
        return (int)$row['c'];
    }

    /**
     * @param array<int, array{code_hash:string,used_at:?string,created_at:?string}> $snapshot
     */
    public function restoreRecoveryCodesFromSnapshot(int $userId, array $snapshot): void
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $delete = $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id = ?');
            $delete->execute([$userId]);
            if ($snapshot !== []) {
                $now = $this->formatDateTime(AppTime::now());
                $insert = $this->pdo->prepare(
                    'INSERT INTO user_mfa_recovery_codes (user_id, code_hash, used_at, created_at) VALUES (?, ?, ?, ?)'
                );
                foreach ($snapshot as $row) {
                    if (!is_array($row) || empty($row['code_hash'])) {
                        continue;
                    }
                    $createdAt = isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : $now;
                    $usedAt = isset($row['used_at']) && is_string($row['used_at']) ? $row['used_at'] : null;
                    $insert->execute([$userId, (string)$row['code_hash'], $usedAt, $createdAt]);
                }
            }
            if ($started) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id:int,reason:string,previous_method_json:?string,previous_recovery_codes_json:?string,created_at:DateTimeImmutable,rolled_back_at:?DateTimeImmutable,rollback_reason:?string}|null
     */
    public function getLatestTenantAdminMfaResetLog(int $targetUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reason, previous_method_json, previous_recovery_codes_json, created_at, rolled_back_at, rollback_reason
             FROM tenant_admin_mfa_reset_logs
             WHERE target_user_id = ?
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute([$targetUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'reason' => (string)$row['reason'],
            'previous_method_json' => $row['previous_method_json'] !== null ? (string)$row['previous_method_json'] : null,
            'previous_recovery_codes_json' => $row['previous_recovery_codes_json'] !== null ? (string)$row['previous_recovery_codes_json'] : null,
            'created_at' => AppTime::fromStorage((string)$row['created_at']) ?? AppTime::now(),
            'rolled_back_at' => AppTime::fromStorage($row['rolled_back_at']),
            'rollback_reason' => $row['rollback_reason'] !== null ? (string)$row['rollback_reason'] : null,
        ];
    }

    public function createTenantAdminMfaResetLog(
        int $targetUserId,
        int $performedByUserId,
        string $reason,
        ?string $previousMethodPayload,
        ?string $previousRecoveryPayload
    ): int {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('理由が必要です。');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_admin_mfa_reset_logs (target_user_id, performed_by_user_id, reason, previous_method_json, previous_recovery_codes_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $targetUserId,
            $performedByUserId,
            $reason,
            $previousMethodPayload,
            $previousRecoveryPayload,
            $this->formatDateTime(AppTime::now()),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function markTenantAdminMfaResetRolledBack(int $logId, string $rollbackReason, int $rolledBackByUserId): void
    {
        $rollbackReason = trim($rollbackReason);
        if ($rollbackReason === '') {
            throw new \InvalidArgumentException('取消理由が必要です。');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tenant_admin_mfa_reset_logs
             SET rolled_back_at = ?, rolled_back_by_user_id = ?, rollback_reason = ?
             WHERE id = ? AND rolled_back_at IS NULL'
        );
        $stmt->execute([
            $this->formatDateTime(AppTime::now()),
            $rolledBackByUserId,
            $rollbackReason,
            $logId,
        ]);
    }

    /**
     * @param string[] $hashes
     */
    public function replaceRecoveryCodes(int $userId, array $hashes): void
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $delete = $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id = ?');
            $delete->execute([$userId]);
            if ($hashes !== []) {
                $now = $this->formatDateTime(AppTime::now());
                $insert = $this->pdo->prepare(
                    'INSERT INTO user_mfa_recovery_codes (user_id, code_hash, used_at, created_at) VALUES (?, ?, NULL, ?)'
                );
                foreach ($hashes as $hash) {
                    $insert->execute([$userId, $hash, $now]);
                }
            }
            if ($started) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteRecoveryCodesByUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    public function updateUserPassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET password_hash = ?, must_change_password = 0, failed_attempts = 0, locked_until = NULL
             WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $userId]);
    }

    public function updateUserPasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $userId]);
    }

    public function updateUserEmail(int $userId, string $email): void
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 320 || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('無効なメールアドレスです。');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE users SET email = ? WHERE id = ?'
        );
        $stmt->execute([$normalized, $userId]);
    }

    /**
     * @return array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}|null
     */
    public function findRoleCodeByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, employment_type, expires_at, max_uses, usage_count, is_disabled
             FROM role_codes WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'code' => (string)$row['code'],
            'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
            'expires_at' => AppTime::fromStorage($row['expires_at']),
            'max_uses' => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'usage_count' => (int)$row['usage_count'],
            'is_disabled' => (bool)$row['is_disabled'],
        ];
    }

    public function incrementRoleCodeUsage(int $roleCodeId): void
    {
        $stmt = $this->pdo->prepare('UPDATE role_codes SET usage_count = usage_count + 1 WHERE id = ?');
        $stmt->execute([$roleCodeId]);
    }

    /**
     * @return array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}|null
     */
    public function findRoleCodeById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, employment_type, expires_at, max_uses, usage_count, is_disabled
             FROM role_codes WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'code' => (string)$row['code'],
            'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
            'expires_at' => AppTime::fromStorage($row['expires_at']),
            'max_uses' => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'usage_count' => (int)$row['usage_count'],
            'is_disabled' => (bool)$row['is_disabled'],
        ];
    }

    /**
     * @param array{tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,created_by:int} $data
     * @return array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}
     */
    public function createRoleCode(array $data): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_codes (tenant_id, code, employment_type, expires_at, max_uses, usage_count, is_disabled, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([
            $data['tenant_id'],
            $data['code'],
            $data['employment_type'] ?? null,
            $data['expires_at'] !== null ? $this->formatDateTime($data['expires_at']) : null,
            $data['max_uses'] ?? null,
            $data['created_by'],
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        return [
            'id' => $id,
            'tenant_id' => $data['tenant_id'],
            'code' => $data['code'],
            'employment_type' => $data['employment_type'] ?? null,
            'expires_at' => $data['expires_at'],
            'max_uses' => $data['max_uses'] ?? null,
            'usage_count' => 0,
            'is_disabled' => false,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,code:string,employment_type:?string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}>
     */
    public function listRoleCodes(int $tenantId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, employment_type, expires_at, max_uses, usage_count, is_disabled, created_at
             FROM role_codes
             WHERE tenant_id = ?
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'tenant_id' => (int)$row['tenant_id'],
                'code' => (string)$row['code'],
                'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
                'expires_at' => AppTime::fromStorage($row['expires_at']),
                'max_uses' => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
                'usage_count' => (int)$row['usage_count'],
                'is_disabled' => (bool)$row['is_disabled'],
                'created_at' => AppTime::fromStorage((string)$row['created_at']) ?? AppTime::now(),
            ];
        }
        return $result;
    }

    /**
     * role_codes を排他ロックして usage_count を 1 増加し、上限を超えた場合は is_disabled を自動で有効化する。
     *
     * @return array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}
     */
    public function incrementRoleCodeWithLimit(int $roleCodeId): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('incrementRoleCodeWithLimit はトランザクション内で呼び出してください。');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, employment_type, expires_at, max_uses, usage_count, is_disabled
             FROM role_codes
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$roleCodeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('ロールコードが見つかりません。');
        }
        $newUsage = ((int)$row['usage_count']) + 1;
        $maxUses = $row['max_uses'] !== null ? (int)$row['max_uses'] : null;
        $shouldDisable = (bool)$row['is_disabled'];
        if ($maxUses !== null && $newUsage >= $maxUses) {
            $shouldDisable = true;
        }
        $update = $this->pdo->prepare(
            'UPDATE role_codes SET usage_count = ?, is_disabled = ? WHERE id = ?'
        );
        $update->execute([$newUsage, $shouldDisable ? 1 : 0, $roleCodeId]);

        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'code' => (string)$row['code'],
            'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
            'expires_at' => AppTime::fromStorage($row['expires_at']),
            'max_uses' => $maxUses,
            'usage_count' => $newUsage,
            'is_disabled' => $shouldDisable,
        ];
    }

    /**
     * @return array<int, array{id:int,user_id:int,email:string,first_name:?string,last_name:?string,start_time:DateTimeImmutable,end_time:?DateTimeImmutable}>
     */
    public function fetchWorkSessions(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $userId = null): array
    {
        $sql = 'SELECT ws.id, ws.user_id, ws.start_time, ws.end_time, u.email, u.first_name, u.last_name
                FROM work_sessions ws
                INNER JOIN users u ON ws.user_id = u.id
                WHERE u.tenant_id = :tenantId
                  AND ws.archived_at IS NULL
                  AND ws.start_time >= :start
                  AND ws.start_time <= :end';
        $params = [
            ':tenantId' => $tenantId,
            ':start' => $this->formatDateTime($start),
            ':end' => $this->formatDateTime($end),
        ];
        if ($userId !== null) {
            $sql .= ' AND ws.user_id = :userId';
            $params[':userId'] = $userId;
        }
        $sql .= ' ORDER BY ws.start_time ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'email' => (string)$row['email'],
                'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}|null
     */
    public function findOpenWorkSession(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, start_time, end_time, archived_at
             FROM work_sessions
             WHERE user_id = ? AND end_time IS NULL AND archived_at IS NULL
             ORDER BY start_time DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
            'end_time' => null,
            'archived_at' => AppTime::fromStorage($row['archived_at']),
        ];
    }

    /**
     * @return array{status:'opened'|'closed',session:array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}}
     */
    public function toggleWorkSessionAtomic(int $userId, DateTimeImmutable $now): array
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $autoClosedBreak = false;
            $stmt = $this->pdo->prepare(
                'SELECT id FROM work_sessions WHERE user_id = ? AND end_time IS NULL AND archived_at IS NULL ORDER BY start_time DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $sessionId = (int)$row['id'];
                $update = $this->pdo->prepare('UPDATE work_sessions SET end_time = ? WHERE id = ?');
                $update->execute([$this->formatDateTime($now), $sessionId]);
                try {
                    $breakUpdate = $this->pdo->prepare(
                        'UPDATE work_session_breaks SET end_time = ? WHERE work_session_id = ? AND end_time IS NULL'
                    );
                    $breakUpdate->execute([$this->formatDateTime($now), $sessionId]);
                    $autoClosedBreak = $breakUpdate->rowCount() > 0;
                } catch (\PDOException $e) {
                    // 移行途中でテーブル未作成の場合に全体を落とさない（要: DB適用）
                    if ($e->getCode() !== '42S02') {
                        throw $e;
                    }
                }
                $session = $this->findWorkSessionById($sessionId);
                $status = 'closed';
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO work_sessions (user_id, start_time, end_time, archived_at, created_at) VALUES (?, ?, NULL, NULL, ?)'
                );
                $insert->execute([
                    $userId,
                    $this->formatDateTime($now),
                    $this->formatDateTime($now),
                ]);
                $sessionId = (int)$this->pdo->lastInsertId();
                $session = $this->findWorkSessionById($sessionId);
                $status = 'opened';
            }
            if ($session === null) {
                throw new RuntimeException('勤務セッションの状態を取得できませんでした。');
            }
            if ($started) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['status' => $status, 'session' => $session, 'break_auto_closed' => $autoClosedBreak];
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   work_session_id:int,
     *   break_type:string,
     *   is_compensated:bool,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable,
     *   note:?string
     * }>
     */
    public function listWorkSessionBreaksBySessionIds(array $workSessionIds): array
    {
        $ids = array_values(array_unique(array_map(static fn($id): int => (int)$id, $workSessionIds)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, work_session_id, break_type, is_compensated, start_time, end_time, note
             FROM work_session_breaks
             WHERE work_session_id IN ({$placeholders})
             ORDER BY work_session_id ASC, start_time ASC, id ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'work_session_id' => (int)$row['work_session_id'],
                'break_type' => (string)$row['break_type'],
                'is_compensated' => (bool)$row['is_compensated'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'note' => $row['note'] !== null ? (string)$row['note'] : null,
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   work_session_id:int,
     *   break_type:string,
     *   is_compensated:bool,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable,
     *   note:?string
     * }>
     */
    public function listWorkSessionBreaksBySessionId(int $workSessionId): array
    {
        return $this->listWorkSessionBreaksBySessionIds([$workSessionId]);
    }

    /**
     * @return array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string}|null
     */
    public function findOpenWorkSessionBreak(int $workSessionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, work_session_id, break_type, is_compensated, start_time, end_time, note
             FROM work_session_breaks
             WHERE work_session_id = ? AND end_time IS NULL
             ORDER BY start_time DESC
             LIMIT 1'
        );
        $stmt->execute([$workSessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'work_session_id' => (int)$row['work_session_id'],
            'break_type' => (string)$row['break_type'],
            'is_compensated' => (bool)$row['is_compensated'],
            'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
            'end_time' => AppTime::fromStorage($row['end_time']),
            'note' => $row['note'] !== null ? (string)$row['note'] : null,
        ];
    }

    /**
     * 従業員の休憩開始（勤務中セッションが必要、すでに休憩中ならエラー）。
     *
     * @return array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string}
     */
    public function startWorkSessionBreakAtomic(int $userId, DateTimeImmutable $now, string $breakType = 'rest'): array
    {
        $breakType = strtolower(trim($breakType));
        if (!in_array($breakType, ['rest', 'other'], true)) {
            throw new RuntimeException('休憩種別が不正です。');
        }

        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $sessionStmt = $this->pdo->prepare(
                'SELECT id, start_time FROM work_sessions
                 WHERE user_id = ? AND end_time IS NULL AND archived_at IS NULL
                 ORDER BY start_time DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $sessionStmt->execute([$userId]);
            $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if ($sessionRow === false) {
                throw new RuntimeException('勤務中のセッションが見つかりません。先に勤務開始を記録してください。');
            }
            $sessionId = (int)$sessionRow['id'];
            $sessionStart = AppTime::fromStorage((string)$sessionRow['start_time']) ?? AppTime::now();
            if ($now < $sessionStart) {
                throw new RuntimeException('休憩開始時刻が不正です。');
            }

            $openStmt = $this->pdo->prepare(
                'SELECT id FROM work_session_breaks WHERE work_session_id = ? AND end_time IS NULL LIMIT 1 FOR UPDATE'
            );
            $openStmt->execute([$sessionId]);
            if ($openStmt->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new RuntimeException('すでに休憩中です。休憩終了を記録してください。');
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO work_session_breaks (work_session_id, break_type, is_compensated, start_time, end_time, note, created_at)
                 VALUES (?, ?, 0, ?, NULL, NULL, ?)'
            );
            $insert->execute([$sessionId, $breakType, $this->formatDateTime($now), $this->formatDateTime($now)]);
            $breakId = (int)$this->pdo->lastInsertId();

            $created = $this->pdo->prepare(
                'SELECT id, work_session_id, break_type, is_compensated, start_time, end_time, note
                 FROM work_session_breaks WHERE id = ? LIMIT 1'
            );
            $created->execute([$breakId]);
            $row = $created->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('休憩レコードを取得できませんでした。');
            }

            if ($started) {
                $this->pdo->commit();
            }

            return [
                'id' => (int)$row['id'],
                'work_session_id' => (int)$row['work_session_id'],
                'break_type' => (string)$row['break_type'],
                'is_compensated' => (bool)$row['is_compensated'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'note' => $row['note'] !== null ? (string)$row['note'] : null,
            ];
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 従業員の休憩終了（休憩中レコードが必要）。
     *
     * @return array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string}
     */
    public function endWorkSessionBreakAtomic(int $userId, DateTimeImmutable $now): array
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $sessionStmt = $this->pdo->prepare(
                'SELECT id FROM work_sessions
                 WHERE user_id = ? AND end_time IS NULL AND archived_at IS NULL
                 ORDER BY start_time DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $sessionStmt->execute([$userId]);
            $sessionRow = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if ($sessionRow === false) {
                throw new RuntimeException('勤務中のセッションが見つかりません。');
            }
            $sessionId = (int)$sessionRow['id'];

            $breakStmt = $this->pdo->prepare(
                'SELECT id, start_time FROM work_session_breaks
                 WHERE work_session_id = ? AND end_time IS NULL
                 ORDER BY start_time DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $breakStmt->execute([$sessionId]);
            $breakRow = $breakStmt->fetch(PDO::FETCH_ASSOC);
            if ($breakRow === false) {
                throw new RuntimeException('休憩中ではありません。');
            }
            $breakId = (int)$breakRow['id'];
            $breakStart = AppTime::fromStorage((string)$breakRow['start_time']) ?? AppTime::now();
            if ($now <= $breakStart) {
                throw new RuntimeException('休憩終了時刻が不正です。');
            }

            $update = $this->pdo->prepare(
                'UPDATE work_session_breaks SET end_time = ? WHERE id = ?'
            );
            $update->execute([$this->formatDateTime($now), $breakId]);

            $created = $this->pdo->prepare(
                'SELECT id, work_session_id, break_type, is_compensated, start_time, end_time, note
                 FROM work_session_breaks WHERE id = ? LIMIT 1'
            );
            $created->execute([$breakId]);
            $row = $created->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('休憩レコードを取得できませんでした。');
            }

            if ($started) {
                $this->pdo->commit();
            }

            return [
                'id' => (int)$row['id'],
                'work_session_id' => (int)$row['work_session_id'],
                'break_type' => (string)$row['break_type'],
                'is_compensated' => (bool)$row['is_compensated'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'note' => $row['note'] !== null ? (string)$row['note'] : null,
            ];
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string}|null
     */
    public function findWorkSessionBreakById(int $breakId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, work_session_id, break_type, is_compensated, start_time, end_time, note
             FROM work_session_breaks
             WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$breakId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'work_session_id' => (int)$row['work_session_id'],
            'break_type' => (string)$row['break_type'],
            'is_compensated' => (bool)$row['is_compensated'],
            'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
            'end_time' => AppTime::fromStorage($row['end_time']),
            'note' => $row['note'] !== null ? (string)$row['note'] : null,
        ];
    }

    /**
     * @param array{
     *   work_session_id:int,
     *   break_type:string,
     *   is_compensated:bool,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable,
     *   note:?string
     * } $data
     * @return array{id:int,work_session_id:int,break_type:string,is_compensated:bool,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,note:?string}
     */
    public function createWorkSessionBreak(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO work_session_breaks (work_session_id, break_type, is_compensated, start_time, end_time, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $now = AppTime::now();
        $stmt->execute([
            (int)$data['work_session_id'],
            (string)$data['break_type'],
            !empty($data['is_compensated']) ? 1 : 0,
            $this->formatDateTime($data['start_time']),
            $data['end_time'] !== null ? $this->formatDateTime($data['end_time']) : null,
            $data['note'] !== null ? (string)$data['note'] : null,
            $this->formatDateTime($now),
        ]);
        $breakId = (int)$this->pdo->lastInsertId();
        $created = $this->findWorkSessionBreakById($breakId);
        if ($created === null) {
            throw new RuntimeException('休憩レコードを取得できませんでした。');
        }
        return $created;
    }

    /**
     * @param array{
     *   break_type:string,
     *   is_compensated:bool,
     *   start_time:DateTimeImmutable,
     *   end_time:?DateTimeImmutable,
     *   note:?string
     * } $data
     */
    public function updateWorkSessionBreak(int $breakId, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE work_session_breaks
             SET break_type = ?, is_compensated = ?, start_time = ?, end_time = ?, note = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (string)$data['break_type'],
            !empty($data['is_compensated']) ? 1 : 0,
            $this->formatDateTime($data['start_time']),
            $data['end_time'] !== null ? $this->formatDateTime($data['end_time']) : null,
            $data['note'] !== null ? (string)$data['note'] : null,
            $breakId,
        ]);
    }

    public function deleteWorkSessionBreak(int $breakId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM work_session_breaks WHERE id = ?');
        $stmt->execute([$breakId]);
    }

    /**
     * @return array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}
     */
    public function createWorkSession(int $userId, DateTimeImmutable $start): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO work_sessions (user_id, start_time, end_time, archived_at, created_at)
             VALUES (?, ?, NULL, NULL, ?)'
        );
        $stmt->execute([
            $userId,
            $this->formatDateTime($start),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $found = $this->findWorkSessionById($id);
        if ($found === null) {
            throw new RuntimeException('作成した打刻レコードを取得できませんでした。');
        }
        return $found;
    }

    /**
     * 管理者が開始/終了を指定して勤務記録を追加する。
     *
     * @return array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}
     */
    public function createWorkSessionWithEnd(int $userId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO work_sessions (user_id, start_time, end_time, archived_at, created_at)
             VALUES (?, ?, ?, NULL, ?)'
        );
        $stmt->execute([
            $userId,
            $this->formatDateTime($start),
            $this->formatDateTime($end),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $found = $this->findWorkSessionById($id);
        if ($found === null) {
            throw new RuntimeException('作成した打刻レコードを取得できませんでした。');
        }
        return $found;
    }

    /**
     * @return array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}|null
     */
    public function closeWorkSession(int $sessionId, DateTimeImmutable $end): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE work_sessions SET end_time = ? WHERE id = ?'
        );
        $stmt->execute([$this->formatDateTime($end), $sessionId]);
        return $this->findWorkSessionById($sessionId);
    }

    public function updateWorkSessionTimes(int $sessionId, DateTimeImmutable $start, ?DateTimeImmutable $end): void
    {
        $stmt = $this->pdo->prepare('UPDATE work_sessions SET start_time = ?, end_time = ? WHERE id = ?');
        $stmt->execute([
            $this->formatDateTime($start),
            $end ? $this->formatDateTime($end) : null,
            $sessionId,
        ]);
    }

    public function deleteWorkSession(int $sessionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM work_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
    }

    /**
     * 勤務記録の重複判定（管理者編集用）。
     */
    public function hasOverlappingWorkSessions(int $userId, DateTimeImmutable $start, ?DateTimeImmutable $end, ?int $excludeSessionId = null): bool
    {
        $sql = 'SELECT 1 FROM work_sessions WHERE user_id = :userId AND archived_at IS NULL';
        $params = [
            ':userId' => $userId,
            ':start' => $this->formatDateTime($start),
        ];
        if ($excludeSessionId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params[':excludeId'] = $excludeSessionId;
        }
        if ($end !== null) {
            $sql .= ' AND start_time < :end AND (end_time IS NULL OR end_time > :start)';
            $params[':end'] = $this->formatDateTime($end);
        } else {
            $sql .= ' AND (end_time IS NULL OR end_time > :start)';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * 指定期間と重なる勤務記録を取得する（集計/重複判定向け）。
     *
     * @return array<int, array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}>
     */
    public function listWorkSessionsByUserOverlapping(int $userId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, start_time, end_time, archived_at
             FROM work_sessions
             WHERE user_id = :user_id
               AND archived_at IS NULL
               AND start_time < :end
               AND (end_time IS NULL OR end_time > :start)
             ORDER BY start_time ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':start' => $this->formatDateTime($start),
            ':end' => $this->formatDateTime($end),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'archived_at' => AppTime::fromStorage($row['archived_at']),
            ];
        }
        return $result;
    }

    /**
     * 指定期間と重なる勤務記録をテナント単位で取得する（集計向け / N+1対策）。
     *
     * @return array<int, array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable}>
     */
    public function listWorkSessionsByTenantOverlapping(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ws.id, ws.user_id, ws.start_time, ws.end_time
             FROM work_sessions ws
             INNER JOIN users u ON ws.user_id = u.id
             WHERE u.tenant_id = :tenant_id
               AND u.role = :role
               AND u.status = :status
               AND ws.archived_at IS NULL
               AND ws.start_time < :end
               AND (ws.end_time IS NULL OR ws.end_time > :start)
             ORDER BY ws.user_id ASC, ws.start_time ASC'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':role' => 'employee',
            ':status' => 'active',
            ':start' => $this->formatDateTime($start),
            ':end' => $this->formatDateTime($end),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}|null
     */
    public function findWorkSessionById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, start_time, end_time, archived_at FROM work_sessions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
            'end_time' => AppTime::fromStorage($row['end_time']),
            'archived_at' => AppTime::fromStorage($row['archived_at']),
        ];
    }

    /**
     * @return array<int, array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}>
     */
    public function listRecentWorkSessionsByUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, start_time, end_time, archived_at
             FROM work_sessions
             WHERE user_id = ? AND archived_at IS NULL
             ORDER BY start_time DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'archived_at' => AppTime::fromStorage($row['archived_at']),
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{id:int,user_id:int,start_time:DateTimeImmutable,end_time:?DateTimeImmutable,archived_at:?DateTimeImmutable}>
     */
    public function listWorkSessionsByUserBetween(int $userId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, start_time, end_time, archived_at
             FROM work_sessions
             WHERE user_id = :user_id
               AND archived_at IS NULL
               AND start_time >= :start
               AND start_time <= :end
             ORDER BY start_time ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':start' => $this->formatDateTime($start),
            ':end' => $this->formatDateTime($end),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
                'archived_at' => AppTime::fromStorage($row['archived_at']),
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{id:int,user_id:int,email:string,start_time:DateTimeImmutable,end_time:?DateTimeImmutable}>
     */
    public function listRecentWorkSessionsByTenant(int $tenantId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT ws.id, ws.user_id, ws.start_time, ws.end_time, u.email, u.first_name, u.last_name
             FROM work_sessions ws
             INNER JOIN users u ON ws.user_id = u.id
             WHERE u.tenant_id = ? AND ws.archived_at IS NULL
             ORDER BY ws.start_time DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'email' => (string)$row['email'],
                'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
                'start_time' => AppTime::fromStorage((string)$row['start_time']) ?? AppTime::now(),
                'end_time' => AppTime::fromStorage($row['end_time']),
            ];
        }
        return $result;
    }

    public function countOpenWorkSessionsByTenant(int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt
             FROM work_sessions ws
             INNER JOIN users u ON ws.user_id = u.id
             WHERE u.tenant_id = ? AND ws.archived_at IS NULL AND ws.end_time IS NULL'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    public function countActiveEmployees(int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM users WHERE tenant_id = ? AND role = "employee" AND status = "active"'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @param array{
     *   tenant_id:int,
     *   employee_id:int,
     *   uploaded_by:int,
     *   original_file_name:string,
     *   stored_file_path:string,
     *   mime_type:?string,
     *   file_size:?int,
     *   sent_on:DateTimeImmutable,
     *   sent_at:DateTimeImmutable
     * } $data
     * @return array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable}
     */
    public function createPayrollRecord(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payroll_records (tenant_id, employee_id, uploaded_by, original_file_name, stored_file_path, mime_type, file_size, sent_on, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = AppTime::now();
        $stmt->execute([
            $data['tenant_id'],
            $data['employee_id'],
            $data['uploaded_by'],
            $data['original_file_name'],
            $data['stored_file_path'],
            $data['mime_type'] ?? null,
            $data['file_size'] ?? null,
            AppTime::formatDateOnly($data['sent_on']),
            $this->formatDateTime($data['sent_at']),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        return [
            'id' => $id,
            'tenant_id' => $data['tenant_id'],
            'employee_id' => $data['employee_id'],
            'original_file_name' => $data['original_file_name'],
            'stored_file_path' => $data['stored_file_path'],
            'sent_on' => $data['sent_on'],
            'sent_at' => $data['sent_at'],
        ];
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable,downloaded_at:?DateTimeImmutable}>
     */
    public function listPayrollRecordsByEmployee(int $employeeId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $hasDownloadedAt = $this->payrollDownloadedAtAvailable();
        $downloadedColumn = $hasDownloadedAt ? ', downloaded_at' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, sent_on, sent_at' . $downloadedColumn . '
             FROM payroll_records
             WHERE employee_id = ? AND archived_at IS NULL
             ORDER BY sent_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'tenant_id' => (int)$row['tenant_id'],
                'employee_id' => (int)$row['employee_id'],
                'original_file_name' => (string)$row['original_file_name'],
                'stored_file_path' => (string)$row['stored_file_path'],
                'sent_on' => AppTime::fromStorage((string)$row['sent_on']) ?? AppTime::now(),
                'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
                'downloaded_at' => AppTime::fromStorage($row['downloaded_at'] ?? null),
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable,downloaded_at:?DateTimeImmutable}>
     */
    public function listPayrollRecordsByTenant(int $tenantId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $hasDownloadedAt = $this->payrollDownloadedAtAvailable();
        $downloadedColumn = $hasDownloadedAt ? ', downloaded_at' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, sent_on, sent_at' . $downloadedColumn . '
             FROM payroll_records
             WHERE tenant_id = ? AND archived_at IS NULL
             ORDER BY sent_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'tenant_id' => (int)$row['tenant_id'],
                'employee_id' => (int)$row['employee_id'],
                'original_file_name' => (string)$row['original_file_name'],
                'stored_file_path' => (string)$row['stored_file_path'],
                'sent_on' => AppTime::fromStorage((string)$row['sent_on']) ?? AppTime::now(),
                'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
                'downloaded_at' => AppTime::fromStorage($row['downloaded_at'] ?? null),
            ];
        }
        return $result;
    }

    public function countPayrollRecordsByTenantForAdmin(int $tenantId, ?int $employeeId = null): int
    {
        if ($employeeId !== null && $employeeId <= 0) {
            $employeeId = null;
        }
        if ($employeeId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS cnt
                 FROM payroll_records
                 WHERE tenant_id = ? AND employee_id = ? AND archived_at IS NULL'
            );
            $stmt->execute([$tenantId, $employeeId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS cnt
                 FROM payroll_records
                 WHERE tenant_id = ? AND archived_at IS NULL'
            );
            $stmt->execute([$tenantId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   tenant_id:int,
     *   employee_id:int,
     *   employee_email:?string,
     *   employee_first_name:?string,
     *   employee_last_name:?string,
     *   employee_status:?string,
     *   original_file_name:string,
     *   stored_file_path:string,
     *   mime_type:?string,
     *   file_size:?int,
     *   sent_on:DateTimeImmutable,
     *   sent_at:DateTimeImmutable,
     *   downloaded_at:?DateTimeImmutable
     * }>
     */
    public function listPayrollRecordsByTenantForAdmin(int $tenantId, int $limit = 50, int $offset = 0, ?int $employeeId = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        if ($employeeId !== null && $employeeId <= 0) {
            $employeeId = null;
        }

        $hasDownloadedAt = $this->payrollDownloadedAtAvailable();
        $downloadedColumn = $hasDownloadedAt ? ', pr.downloaded_at' : '';
        $whereEmployee = $employeeId !== null ? ' AND pr.employee_id = ?' : '';
        $sql = 'SELECT
                    pr.id,
                    pr.tenant_id,
                    pr.employee_id,
                    pr.original_file_name,
                    pr.stored_file_path,
                    pr.mime_type,
                    pr.file_size,
                    pr.sent_on,
                    pr.sent_at' . $downloadedColumn . ',
                    u.email AS employee_email,
                    u.first_name AS employee_first_name,
                    u.last_name AS employee_last_name,
                    u.status AS employee_status
                FROM payroll_records pr
                LEFT JOIN users u ON pr.employee_id = u.id
                WHERE pr.tenant_id = ? AND pr.archived_at IS NULL' . $whereEmployee . '
                ORDER BY pr.sent_at DESC
                LIMIT ? OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        $index = 1;
        $stmt->bindValue($index++, $tenantId, PDO::PARAM_INT);
        if ($employeeId !== null) {
            $stmt->bindValue($index++, $employeeId, PDO::PARAM_INT);
        }
        $stmt->bindValue($index++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $fileSize = null;
            if ($row['file_size'] !== null && $row['file_size'] !== '') {
                $fileSize = (int)$row['file_size'];
            }
            $result[] = [
                'id' => (int)$row['id'],
                'tenant_id' => (int)$row['tenant_id'],
                'employee_id' => (int)$row['employee_id'],
                'employee_email' => $row['employee_email'] !== null ? (string)$row['employee_email'] : null,
                'employee_first_name' => $row['employee_first_name'] !== null ? (string)$row['employee_first_name'] : null,
                'employee_last_name' => $row['employee_last_name'] !== null ? (string)$row['employee_last_name'] : null,
                'employee_status' => $row['employee_status'] !== null ? (string)$row['employee_status'] : null,
                'original_file_name' => (string)$row['original_file_name'],
                'stored_file_path' => (string)$row['stored_file_path'],
                'mime_type' => $row['mime_type'] !== null ? (string)$row['mime_type'] : null,
                'file_size' => $fileSize,
                'sent_on' => AppTime::fromStorage((string)$row['sent_on']) ?? AppTime::now(),
                'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
                'downloaded_at' => AppTime::fromStorage($row['downloaded_at'] ?? null),
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,mime_type:?string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable,downloaded_at:?DateTimeImmutable}|null
     */
    public function findPayrollRecordById(int $id): ?array
    {
        $hasDownloadedAt = $this->payrollDownloadedAtAvailable();
        $downloadedColumn = $hasDownloadedAt ? ', downloaded_at' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, mime_type, sent_on, sent_at' . $downloadedColumn . '
             FROM payroll_records
             WHERE id = ? AND archived_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'employee_id' => (int)$row['employee_id'],
            'original_file_name' => (string)$row['original_file_name'],
            'stored_file_path' => (string)$row['stored_file_path'],
            'mime_type' => $row['mime_type'] !== null ? (string)$row['mime_type'] : null,
            'sent_on' => AppTime::fromStorage((string)$row['sent_on']) ?? AppTime::now(),
            'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
            'downloaded_at' => AppTime::fromStorage($row['downloaded_at'] ?? null),
        ];
    }

    public function markPayrollRecordDownloaded(int $id, DateTimeImmutable $downloadedAt): void
    {
        if (!$this->payrollDownloadedAtAvailable()) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE payroll_records
             SET downloaded_at = COALESCE(downloaded_at, ?)
             WHERE id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->formatDateTime($downloadedAt), $id]);
    }

    /**
     * @return array<int, array{id:int,stored_file_path:string,sent_at:DateTimeImmutable}>
     */
    public function listPayrollRecordsForCleanup(DateTimeImmutable $sentBefore, int $limit = 200): array
    {
        $limit = max(1, min(5000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, stored_file_path, sent_at
             FROM payroll_records
             WHERE archived_at IS NULL AND sent_at < ?
             ORDER BY sent_at ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $this->formatDateTime($sentBefore), PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'stored_file_path' => (string)$row['stored_file_path'],
                'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
            ];
        }
        return $result;
    }

    public function archivePayrollRecord(int $id, DateTimeImmutable $archivedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payroll_records
             SET archived_at = ?
             WHERE id = ? AND archived_at IS NULL'
        );
        $stmt->execute([$this->formatDateTime($archivedAt), $id]);
    }

    public function deletePayrollRecord(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM payroll_records WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function disableRoleCode(int $roleCodeId): void
    {
        $stmt = $this->pdo->prepare('UPDATE role_codes SET is_disabled = 1 WHERE id = ?');
        $stmt->execute([$roleCodeId]);
    }

    /**
     * @return array<int, array{id:int,email:string,first_name:?string,last_name:?string}>
     */
    public function listEmployeesForTenant(int $tenantId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name
             FROM users
             WHERE tenant_id = ? AND role = "employee" AND status = "active"
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'email' => (string)$row['email'],
                'first_name' => $row['first_name'] !== null ? (string)$row['first_name'] : null,
                'last_name' => $row['last_name'] !== null ? (string)$row['last_name'] : null,
            ];
        }
        return $result;
    }

    /**
     * 有効/無効を含む従業員一覧を取得する。
     *
     * @return array<int, array{id:int,username:string,email:string,employment_type:?string,status:string,deactivated_at:?DateTimeImmutable}>
     */
    public function listEmployeesByTenantIncludingInactive(int $tenantId, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, username, email, employment_type, status, deactivated_at
             FROM users
             WHERE tenant_id = ? AND role = "employee"
             ORDER BY id ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'username' => (string)$row['username'],
                'email' => (string)$row['email'],
                'employment_type' => $row['employment_type'] !== null ? (string)$row['employment_type'] : null,
                'status' => (string)$row['status'],
                'deactivated_at' => AppTime::fromStorage($row['deactivated_at']),
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,name:?string,contact_email:?string,contact_phone:?string,status:string,require_employee_email_verification:bool,created_at:?DateTimeImmutable}|null
     */
    public function findTenantById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, contact_email, contact_phone, status, require_employee_email_verification, created_at
             FROM tenants
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $name = TenantDataCipher::decrypt($row['name'] ?? null);
        $contactEmail = TenantDataCipher::decrypt($row['contact_email'] ?? null);
        $contactPhone = TenantDataCipher::decrypt($row['contact_phone'] ?? null);
        return [
            'id' => (int)$row['id'],
            'name' => $name !== null ? (string)$name : null,
            'contact_email' => $contactEmail !== null ? (string)$contactEmail : null,
            'contact_phone' => $contactPhone !== null ? (string)$contactPhone : null,
            'status' => (string)$row['status'],
            'require_employee_email_verification' => (bool)$row['require_employee_email_verification'],
            'created_at' => AppTime::fromStorage($row['created_at']),
        ];
    }

    public function updateTenantRegistrationSettings(int $tenantId, bool $requireEmailVerification): void
    {
        $stmt = $this->pdo->prepare('UPDATE tenants SET require_employee_email_verification = ? WHERE id = ?');
        $stmt->execute([$requireEmailVerification ? 1 : 0, $tenantId]);
    }

    public function countTenantsForPlatform(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM tenants');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['c'])) {
            return 0;
        }
        return (int)$row['c'];
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   tenant_uid:string,
     *   name:?string,
     *   contact_email:?string,
     *   contact_phone:?string,
     *   status:string,
     *   deactivated_at:?DateTimeImmutable,
     *   require_employee_email_verification:bool,
     *   created_at:DateTimeImmutable
     * }>
     */
    public function listTenantsForPlatform(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_uid, name, contact_email, contact_phone, status, deactivated_at, require_employee_email_verification, created_at
             FROM tenants
             ORDER BY id ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $name = TenantDataCipher::decrypt($row['name'] ?? null);
            $contactEmail = TenantDataCipher::decrypt($row['contact_email'] ?? null);
            $contactPhone = TenantDataCipher::decrypt($row['contact_phone'] ?? null);
            $result[] = [
                'id' => (int)$row['id'],
                'tenant_uid' => (string)$row['tenant_uid'],
                'name' => $name !== null ? (string)$name : null,
                'contact_email' => $contactEmail !== null ? (string)$contactEmail : null,
                'contact_phone' => $contactPhone !== null ? (string)$contactPhone : null,
                'status' => (string)$row['status'],
                'deactivated_at' => AppTime::fromStorage($row['deactivated_at']),
                'require_employee_email_verification' => (bool)$row['require_employee_email_verification'],
                'created_at' => AppTime::fromStorage((string)$row['created_at']) ?? AppTime::now(),
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,tenant_uid:string,name:?string,contact_email:?string,contact_phone:?string,status:string}
     */
    public function createTenant(string $name, string $contactEmail, ?string $contactPhone = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('テナント名が不正です。');
        }
        if (preg_match('/[\r\n]/', $name)) {
            throw new \InvalidArgumentException('テナント名に改行を含めることはできません。');
        }
        $email = strtolower(trim($contactEmail));
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            throw new \InvalidArgumentException('連絡先メールアドレスが不正です。');
        }
        $phone = $contactPhone !== null ? trim($contactPhone) : null;
        if ($phone !== null) {
            $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
            if ($phone === '') {
                $phone = null;
            }
            if ($phone !== null && mb_strlen($phone, 'UTF-8') > 32) {
                throw new \InvalidArgumentException('連絡先電話番号が不正です。');
            }
        }

        $encName = TenantDataCipher::encrypt($name);
        $encEmail = TenantDataCipher::encrypt($email);
        $encPhone = TenantDataCipher::encrypt($phone);

        $now = $this->formatDateTime(AppTime::now());
        $attempts = 0;
        while (true) {
            $attempts++;
            if ($attempts > 5) {
                throw new RuntimeException('テナントUIDの生成に失敗しました。');
            }
            $tenantUid = bin2hex(random_bytes(16));
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO tenants (tenant_uid, name, contact_email, contact_phone, status, deactivated_at, require_employee_email_verification, created_at)
                     VALUES (?, ?, ?, ?, "active", NULL, 0, ?)'
                );
                $stmt->execute([$tenantUid, $encName, $encEmail, $encPhone, $now]);
                $id = (int)$this->pdo->lastInsertId();
                return [
                    'id' => $id,
                    'tenant_uid' => $tenantUid,
                    'name' => $name,
                    'contact_email' => $email,
                    'contact_phone' => $phone,
                    'status' => 'active',
                ];
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    continue;
                }
                throw $e;
            }
        }
    }

    public function updateTenantStatus(int $tenantId, string $status): void
    {
        $status = trim($status);
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('無効なステータスです。');
        }
        if ($status === 'active') {
            $stmt = $this->pdo->prepare('UPDATE tenants SET status = "active", deactivated_at = NULL WHERE id = ?');
            $stmt->execute([$tenantId]);
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE tenants SET status = "inactive", deactivated_at = ? WHERE id = ?');
        $stmt->execute([$this->formatDateTime(AppTime::now()), $tenantId]);
    }

    public function createPasswordResetToken(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $tokenHash,
            $this->formatDateTime($expiresAt),
            $this->formatDateTime($now),
        ]);
    }

    /**
     * @return array{id:int,user_id:int,expires_at:DateTimeImmutable,used_at:?DateTimeImmutable}|null
     */
    public function findPasswordResetForUpdate(string $tokenHash): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException('findPasswordResetForUpdate must be called within a transaction');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, expires_at, used_at
             FROM password_resets
             WHERE token = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'expires_at' => AppTime::fromStorage((string)$row['expires_at']) ?? AppTime::now(),
            'used_at' => AppTime::fromStorage($row['used_at']),
        ];
    }

    public function markPasswordResetUsed(int $resetId, DateTimeImmutable $usedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = ? WHERE id = ?'
        );
        $stmt->execute([$this->formatDateTime($usedAt), $resetId]);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array{
     *   id?:int,
     *   user_id?:int,
     *   purpose?:string,
     *   target_email?:string,
     *   only_active?:bool,
     *   active_at?:DateTimeImmutable
     * } $filters
     * @return array{id:int,user_id:int,tenant_id:?int,role_code_id:?int,purpose:string,target_email:string,code_hash:string,expires_at:DateTimeImmutable,consumed_at:?DateTimeImmutable,failed_attempts:int,max_attempts:int,lock_until:?DateTimeImmutable,last_sent_at:DateTimeImmutable,metadata:?array}|null
     */
    public function findEmailOtpRequest(array $filters): ?array
    {
        $sql = 'SELECT id, user_id, tenant_id, role_code_id, purpose, target_email, code_hash, metadata_json, expires_at, consumed_at, failed_attempts, max_attempts, lock_until, last_sent_at FROM email_otp_requests WHERE 1=1';
        $params = [];
        if (isset($filters['id'])) {
            $sql .= ' AND id = ?';
            $params[] = $filters['id'];
        }
        if (isset($filters['user_id'])) {
            $sql .= ' AND user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (isset($filters['purpose'])) {
            $sql .= ' AND purpose = ?';
            $params[] = $filters['purpose'];
        }
        if (isset($filters['target_email'])) {
            $sql .= ' AND target_email = ?';
            $params[] = $filters['target_email'];
        }
        if (!empty($filters['only_active'])) {
            $sql .= ' AND consumed_at IS NULL';
            if (isset($filters['active_at']) && $filters['active_at'] instanceof DateTimeImmutable) {
                $sql .= ' AND expires_at > ?';
                $params[] = $this->formatDateTime($filters['active_at']);
            }
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->mapEmailOtpRow($row);
    }

    /**
     * @param array{
     *   user_id:int,
     *   tenant_id?:?int,
     *   role_code_id?:?int,
     *   purpose:string,
     *   target_email:string,
     *   code_hash:string,
     *   expires_at:DateTimeImmutable,
     *   max_attempts?:int,
     *   metadata?:?array,
     *   last_sent_at?:DateTimeImmutable
     * } $payload
     * @return array{id:int,user_id:int,tenant_id:?int,role_code_id:?int,purpose:string,target_email:string,code_hash:string,expires_at:DateTimeImmutable,consumed_at:?DateTimeImmutable,failed_attempts:int,max_attempts:int,lock_until:?DateTimeImmutable,last_sent_at:DateTimeImmutable,metadata:?array}
     */
    public function createEmailOtpRequest(array $payload): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_otp_requests (user_id, tenant_id, role_code_id, purpose, target_email, code_hash, metadata_json, expires_at, consumed_at, failed_attempts, max_attempts, lock_until, last_sent_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, NULL, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['user_id'],
            $payload['tenant_id'] ?? null,
            $payload['role_code_id'] ?? null,
            $payload['purpose'],
            $payload['target_email'],
            $payload['code_hash'],
            $this->encodeMetadata($payload['metadata'] ?? null),
            $this->formatDateTime($payload['expires_at']),
            $payload['max_attempts'] ?? 5,
            $this->formatDateTime($payload['last_sent_at'] ?? $now),
            $this->formatDateTime($now),
            $this->formatDateTime($now),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $found = $this->findEmailOtpRequest(['id' => $id]);
        if ($found === null) {
            throw new RuntimeException('email_otp_requests の作成結果を取得できませんでした。');
        }
        return $found;
    }

    /**
     * @param array{
     *   code_hash?:string,
     *   expires_at?:DateTimeImmutable,
     *   consumed_at?:?DateTimeImmutable,
     *   failed_attempts?:int,
     *   max_attempts?:int,
     *   lock_until?:?DateTimeImmutable,
     *   last_sent_at?:DateTimeImmutable,
     *   metadata?:?array
     * } $patch
     */
    public function updateEmailOtpRequest(int $id, array $patch): ?array
    {
        $fields = [];
        $params = [];
        if (array_key_exists('code_hash', $patch)) {
            $fields[] = 'code_hash = ?';
            $params[] = $patch['code_hash'];
        }
        if (array_key_exists('expires_at', $patch) && $patch['expires_at'] instanceof DateTimeImmutable) {
            $fields[] = 'expires_at = ?';
            $params[] = $this->formatDateTime($patch['expires_at']);
        }
        if (array_key_exists('consumed_at', $patch)) {
            $fields[] = 'consumed_at = ?';
            $params[] = $patch['consumed_at'] instanceof DateTimeImmutable ? $this->formatDateTime($patch['consumed_at']) : null;
        }
        if (array_key_exists('failed_attempts', $patch)) {
            $fields[] = 'failed_attempts = ?';
            $params[] = (int)$patch['failed_attempts'];
        }
        if (array_key_exists('max_attempts', $patch)) {
            $fields[] = 'max_attempts = ?';
            $params[] = (int)$patch['max_attempts'];
        }
        if (array_key_exists('lock_until', $patch)) {
            $fields[] = 'lock_until = ?';
            $params[] = $patch['lock_until'] instanceof DateTimeImmutable ? $this->formatDateTime($patch['lock_until']) : null;
        }
        if (array_key_exists('last_sent_at', $patch) && $patch['last_sent_at'] instanceof DateTimeImmutable) {
            $fields[] = 'last_sent_at = ?';
            $params[] = $this->formatDateTime($patch['last_sent_at']);
        }
        if (array_key_exists('metadata', $patch)) {
            $fields[] = 'metadata_json = ?';
            $params[] = $this->encodeMetadata($patch['metadata']);
        }
        if ($fields === []) {
            return $this->findEmailOtpRequest(['id' => $id]);
        }
        $fields[] = 'updated_at = ?';
        $params[] = $this->formatDateTime(AppTime::now());
        $params[] = $id;
        $sql = 'UPDATE email_otp_requests SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->findEmailOtpRequest(['id' => $id]);
    }

    /**
     * 失敗回数を 1 増加し、閾値を超えた場合は lock_until を設定する。
     *
     * @return array{id:int,user_id:int,tenant_id:?int,role_code_id:?int,purpose:string,target_email:string,code_hash:string,expires_at:DateTimeImmutable,consumed_at:?DateTimeImmutable,failed_attempts:int,max_attempts:int,lock_until:?DateTimeImmutable,last_sent_at:DateTimeImmutable,metadata:?array}|null
     */
    public function incrementEmailOtpFailure(int $id, int $maxAttempts, int $lockSeconds): ?array
    {
        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, failed_attempts, max_attempts FROM email_otp_requests WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if ($started) {
                    $this->pdo->rollBack();
                }
                return null;
            }
            $attempts = (int)$row['failed_attempts'] + 1;
            $threshold = (int)$row['max_attempts'] ?: $maxAttempts;
            $lockUntil = null;
            if ($attempts >= $threshold && $lockSeconds > 0) {
                $lockUntil = AppTime::now()->modify("+{$lockSeconds} seconds");
            }
            $update = $this->pdo->prepare(
                'UPDATE email_otp_requests SET failed_attempts = ?, lock_until = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([
                $attempts,
                $lockUntil ? $this->formatDateTime($lockUntil) : null,
                $this->formatDateTime(AppTime::now()),
                $id,
            ]);
            if ($started) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $this->findEmailOtpRequest(['id' => $id]);
    }

    /**
     * @param array{id?:int,user_id?:int,purpose?:string,target_email?:string} $filters
     */
    public function deleteEmailOtpRequests(array $filters): void
    {
        $sql = 'DELETE FROM email_otp_requests WHERE 1=1';
        $params = [];
        if (isset($filters['id'])) {
            $sql .= ' AND id = ?';
            $params[] = $filters['id'];
        }
        if (isset($filters['user_id'])) {
            $sql .= ' AND user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (isset($filters['purpose'])) {
            $sql .= ' AND purpose = ?';
            $params[] = $filters['purpose'];
        }
        if (isset($filters['target_email'])) {
            $sql .= ' AND target_email = ?';
            $params[] = $filters['target_email'];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function formatDateTime(DateTimeImmutable $dt): string
    {
        return AppTime::formatForStorage($dt);
    }

    private function payrollDownloadedAtAvailable(): bool
    {
        if ($this->payrollDownloadedAtAvailable !== null) {
            return $this->payrollDownloadedAtAvailable;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "payroll_records"
                   AND COLUMN_NAME = "downloaded_at"
                 LIMIT 1'
            );
            $stmt->execute();
            $this->payrollDownloadedAtAvailable = (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            $this->payrollDownloadedAtAvailable = false;
        }
        return $this->payrollDownloadedAtAvailable;
    }

    /**
     * @param array<string,mixed>|null $metadata
     */
    private function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }
        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param string|null $json
     * @return array<string,mixed>|null
     */
    private function decodeJsonConfig(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $decoded = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,
     *   user_id:int,
     *   name:?string,
     *   credential_id:string,
     *   user_handle:string,
     *   transports:?array,
     *   sign_count:int,
     *   last_used_at:?DateTimeImmutable,
     *   created_at:DateTimeImmutable
     * }
     */
    private function mapPasskeyRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'name' => $row['name'] !== null ? (string)$row['name'] : null,
            'credential_id' => (string)$row['credential_id'],
            'user_handle' => $row['user_handle'] !== null ? (string)$row['user_handle'] : '',
            'transports' => $this->decodeJsonConfig($row['transports_json'] ?? null),
            'sign_count' => (int)($row['sign_count'] ?? 0),
            'last_used_at' => AppTime::fromStorage($row['last_used_at']),
            'created_at' => AppTime::fromStorage((string)$row['created_at']) ?? AppTime::now(),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,user_id:int,tenant_id:?int,role_code_id:?int,purpose:string,target_email:string,code_hash:string,expires_at:DateTimeImmutable,consumed_at:?DateTimeImmutable,failed_attempts:int,max_attempts:int,lock_until:?DateTimeImmutable,last_sent_at:DateTimeImmutable,metadata:?array}
     */
    private function mapEmailOtpRow(array $row): array
    {
        $metadata = null;
        if ($row['metadata_json'] !== null) {
            try {
                $metadata = json_decode((string)$row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $metadata = null;
            }
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'role_code_id' => $row['role_code_id'] !== null ? (int)$row['role_code_id'] : null,
            'purpose' => (string)$row['purpose'],
            'target_email' => (string)$row['target_email'],
            'code_hash' => (string)$row['code_hash'],
            'metadata' => $metadata,
            'expires_at' => AppTime::fromStorage((string)$row['expires_at']) ?? AppTime::now(),
            'consumed_at' => AppTime::fromStorage($row['consumed_at']),
            'failed_attempts' => (int)$row['failed_attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'lock_until' => AppTime::fromStorage($row['lock_until']),
            'last_sent_at' => AppTime::fromStorage((string)$row['last_sent_at']) ?? AppTime::now(),
        ];
    }
}
