<?php

declare(strict_types=1);

namespace Attendly\Database;

use Attendly\Database;
use Attendly\Support\AppTime;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class Repository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array{id:int, tenant_id:int|null, username:string, email:string, password_hash:string, role:string, must_change_password:bool, status:?string}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, username, email, password_hash, role, must_change_password, status
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
            'must_change_password' => (bool)$row['must_change_password'],
            'status' => $row['status'] !== null ? (string)$row['status'] : null,
        ];
    }

    /**
     * @return array{id:int, tenant_id:int|null, username:string, email:string, password_hash:string, role:string, must_change_password:bool, status:?string, failed_attempts:int, locked_until:?DateTimeImmutable}|null
     */
    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, username, email, password_hash, role, must_change_password, status, failed_attempts, locked_until
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
            'must_change_password' => (bool)$row['must_change_password'],
            'status' => $row['status'] !== null ? (string)$row['status'] : null,
            'failed_attempts' => (int)$row['failed_attempts'],
            'locked_until' => AppTime::fromStorage($row['locked_until']),
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
     *   status:string,
     *   must_change_password?:bool,
     *   first_name?:string,
     *   last_name?:string
     * } $data
     * @return array{id:int,tenant_id:?int,username:string,email:string,role:string,status:string}
     */
    public function createUser(array $data): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, username, email, password_hash, role, must_change_password, first_name, last_name, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['tenant_id'] ?? null,
            $data['username'],
            $data['email'],
            $data['password_hash'],
            $data['role'],
            !empty($data['must_change_password']) ? 1 : 0,
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
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
        $stmt = $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $userId]);
    }

    public function resetLoginFailures(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?');
        $stmt->execute([$userId]);
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

    /**
     * @return array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}|null
     */
    public function findRoleCodeByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, expires_at, max_uses, usage_count, is_disabled
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
     * @return array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool}|null
     */
    public function findRoleCodeById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, expires_at, max_uses, usage_count, is_disabled
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
            'expires_at' => AppTime::fromStorage($row['expires_at']),
            'max_uses' => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'usage_count' => (int)$row['usage_count'],
            'is_disabled' => (bool)$row['is_disabled'],
        ];
    }

    /**
     * @param array{tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,created_by:int} $data
     * @return array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}
     */
    public function createRoleCode(array $data): array
    {
        $now = AppTime::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_codes (tenant_id, code, expires_at, max_uses, usage_count, is_disabled, created_by, created_at)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?)'
        );
        $stmt->execute([
            $data['tenant_id'],
            $data['code'],
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
            'expires_at' => $data['expires_at'],
            'max_uses' => $data['max_uses'] ?? null,
            'usage_count' => 0,
            'is_disabled' => false,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,code:string,expires_at:?DateTimeImmutable,max_uses:?int,usage_count:int,is_disabled:bool,created_at:DateTimeImmutable}>
     */
    public function listRoleCodes(int $tenantId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, code, expires_at, max_uses, usage_count, is_disabled, created_at
             FROM role_codes
             WHERE tenant_id = ?
             ORDER BY created_at DESC
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
            'SELECT id, tenant_id, code, expires_at, max_uses, usage_count, is_disabled
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
            $stmt = $this->pdo->prepare(
                'SELECT id FROM work_sessions WHERE user_id = ? AND end_time IS NULL AND archived_at IS NULL ORDER BY start_time DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $sessionId = (int)$row['id'];
                $update = $this->pdo->prepare('UPDATE work_sessions SET end_time = ? WHERE id = ?');
                $update->execute([$this->formatDateTime($now), $sessionId]);
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
        return ['status' => $status, 'session' => $session];
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
     * @return array<int, array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable}>
     */
    public function listPayrollRecordsByEmployee(int $employeeId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, sent_on, sent_at
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
            ];
        }
        return $result;
    }

    /**
     * @return array<int, array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable}>
     */
    public function listPayrollRecordsByTenant(int $tenantId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, sent_on, sent_at
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
            ];
        }
        return $result;
    }

    /**
     * @return array{id:int,tenant_id:int,employee_id:int,original_file_name:string,stored_file_path:string,sent_on:DateTimeImmutable,sent_at:DateTimeImmutable}|null
     */
    public function findPayrollRecordById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, employee_id, original_file_name, stored_file_path, sent_on, sent_at
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
            'sent_on' => AppTime::fromStorage((string)$row['sent_on']) ?? AppTime::now(),
            'sent_at' => AppTime::fromStorage((string)$row['sent_at']) ?? AppTime::now(),
        ];
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
     * @return array{id:int,name:?string,status:string,require_employee_email_verification:bool}|null
     */
    public function findTenantById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, status, require_employee_email_verification
             FROM tenants
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
            'name' => $row['name'] !== null ? (string)$row['name'] : null,
            'status' => (string)$row['status'],
            'require_employee_email_verification' => (bool)$row['require_employee_email_verification'],
        ];
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
