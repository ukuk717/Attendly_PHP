<?php

declare(strict_types=1);

namespace Attendly\Database;

use Attendly\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

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

    private function formatDateTime(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
