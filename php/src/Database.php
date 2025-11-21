<?php

declare(strict_types=1);

namespace Attendly;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
    * Create a PDO connection to MySQL using environment variables.
    */
    public static function connect(): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql 拡張が有効ではありません。PHPに MySQL 用の PDO ドライバをインストール/有効化してください。');
        }

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = (int)($_ENV['DB_PORT'] ?? 3306);
        // デフォルトをローカル用サンプルに合わせる
        $dbname = $_ENV['DB_NAME'] ?? 'attendly_local';
        $user = $_ENV['DB_USER'] ?? 'attendly_local';
        $password = $_ENV['DB_PASSWORD'] ?? 'local-password';
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        if ($user === '' || $password === '') {
            throw new RuntimeException('.env の DB_USER / DB_PASSWORD が設定されていません。ローカル用の資格情報を設定してください。');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf('DB接続に失敗しました（host=%s, port=%d, db=%s）: %s', $host, $port, $dbname, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
    * Lightweight connectivity check; returns true if a simple query succeeds.
    */
    public static function ping(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false;
        } catch (PDOException) {
            return false;
        }
    }
}
