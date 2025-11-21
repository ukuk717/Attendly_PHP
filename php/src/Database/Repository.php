<?php

declare(strict_types=1);

namespace Attendly\Database;

use Attendly\Database;
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
}
