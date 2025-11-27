<?php

declare(strict_types=1);

namespace Attendly\Database;

use Attendly\Database;
use DateTimeImmutable;
use DateTimeZone;
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
            'locked_until' => $row['locked_until'] !== null
                ? new DateTimeImmutable((string)$row['locked_until'], new DateTimeZone('UTC'))
                : null,
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
            'expires_at' => $row['expires_at'] !== null
                ? new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC'))
                : null,
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
            'expires_at' => $row['expires_at'] !== null
                ? new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC'))
                : null,
            'max_uses' => $row['max_uses'] !== null ? (int)$row['max_uses'] : null,
            'usage_count' => (int)$row['usage_count'],
            'is_disabled' => (bool)$row['is_disabled'],
        ];
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
            'expires_at' => $row['expires_at'] !== null
                ? new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC'))
                : null,
            'max_uses' => $maxUses,
            'usage_count' => $newUsage,
            'is_disabled' => $shouldDisable,
        ];
    }

    public function disableRoleCode(int $roleCodeId): void
    {
        $stmt = $this->pdo->prepare('UPDATE role_codes SET is_disabled = 1 WHERE id = ?');
        $stmt->execute([$roleCodeId]);
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
            'expires_at' => new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC')),
            'used_at' => $row['used_at'] !== null
                ? new DateTimeImmutable((string)$row['used_at'], new DateTimeZone('UTC'))
                : null,
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
        $params[] = $this->formatDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC')));
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
                $lockUntil = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$lockSeconds} seconds");
            }
            $update = $this->pdo->prepare(
                'UPDATE email_otp_requests SET failed_attempts = ?, lock_until = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([
                $attempts,
                $lockUntil ? $this->formatDateTime($lockUntil) : null,
                $this->formatDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
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
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
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
            'expires_at' => new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC')),
            'consumed_at' => $row['consumed_at'] !== null
                ? new DateTimeImmutable((string)$row['consumed_at'], new DateTimeZone('UTC'))
                : null,
            'failed_attempts' => (int)$row['failed_attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'lock_until' => $row['lock_until'] !== null
                ? new DateTimeImmutable((string)$row['lock_until'], new DateTimeZone('UTC'))
                : null,
            'last_sent_at' => new DateTimeImmutable((string)$row['last_sent_at'], new DateTimeZone('UTC')),
        ];
    }
}
