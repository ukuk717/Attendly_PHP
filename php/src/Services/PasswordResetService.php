<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\AppTime;
use Attendly\Support\RateLimiter;
use Attendly\Support\Mailer;
use DateTimeImmutable;

final class PasswordResetService
{
    public function __construct(private Repository $repository = new Repository(), private ?Mailer $mailer = null)
    {
    }

    /**
     * パスワードリセットトークンを作成する。
     * ユーザーが存在しない場合も成功扱いで応答し、利用有無を隠蔽する。
     *
     * @return array{delivered:bool, debug_token?:string, expires_at?:DateTimeImmutable}
     */
    public function requestReset(string $email): array
    {
        $emailNormalized = strtolower(trim($email));
        // service側でもメール単位のリミットを追加（15分で3件）
        $emailKey = "pwd_reset_email_service:{$emailNormalized}";
        if (!RateLimiter::allow($emailKey, 3, 60 * 15)) {
            return ['delivered' => true];
        }

        $user = $this->repository->findUserByEmail($email);
        if (!$user || ($user['status'] !== null && $user['status'] !== 'active')) {
            return ['delivered' => true];
        }

        $token = bin2hex(random_bytes(32)); // 64 chars, URL-safe
        $tokenHash = hash('sha256', $token);
        $expiresAt = AppTime::now()->add(new \DateInterval('PT15M'));

        $this->repository->createPasswordResetToken((int)$user['id'], $tokenHash, $expiresAt);

        $result = ['delivered' => true];
        if (!$this->isProduction()) {
            $result['debug_token'] = $token;
            $result['expires_at'] = $expiresAt;
            $this->logDebugToken($email, $token, $expiresAt);
        }
        $this->sendEmail($email, $token, $expiresAt);
        return $result;
    }

    /**
     * トークンが使用可能か（未使用・未期限切れ・ユーザー有効）を判定する。
     *
     * @return array{ok:bool, reason?:string}
     */
    public function validateTokenUsable(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $pdo = $this->repository->getPdo();
        $now = AppTime::now();

        $stmt = $pdo->prepare(
            'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.status
             FROM password_resets pr
             JOIN users u ON pr.user_id = u.id
             WHERE pr.token = ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($row['used_at'] !== null) {
            return ['ok' => false, 'reason' => 'used'];
        }
        $expiresAt = AppTime::fromStorage((string)$row['expires_at']) ?? $now;
        if ($expiresAt <= $now) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        $status = $row['status'] ?? 'active';
        if ($status !== null && $status !== 'active') {
            return ['ok' => false, 'reason' => 'inactive'];
        }

        return ['ok' => true];
    }

    /**
     * トークンを消費してパスワードを更新する（トランザクションで実施）。
     *
     * @return array{ok:bool, reason?:string}
     */
    public function consumeAndResetPassword(string $token, string $passwordHash): array
    {
        $pdo = $this->repository->getPdo();
        $tokenHash = hash('sha256', $token);
        $now = AppTime::now();

        $pdo->beginTransaction();
        try {
            $reset = $this->repository->findPasswordResetForUpdate($tokenHash);
            if ($reset === null) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'not_found'];
            }
            if ($reset['used_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'used'];
            }
            $expiresAt = AppTime::fromStorage((string)$reset['expires_at']) ?? $now;
            if ($expiresAt <= $now) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'expired'];
            }

            $user = $this->repository->findUserById($reset['user_id']);
            if ($user === null || ($user['status'] !== null && $user['status'] !== 'active')) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'inactive'];
            }

            $this->repository->updateUserPassword($reset['user_id'], $passwordHash);
            $this->repository->markPasswordResetUsed($reset['id'], $now);

            $pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isProduction(): bool
    {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
        return $env === 'production' || $env === 'prod';
    }

    private function logDebugToken(string $email, string $token, DateTimeImmutable $expiresAt): void
    {
        $base = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created', $base));
        }
        $tokenHash = hash('sha256', $token);
        $line = sprintf(
            "[%s] password reset for %s token_hash=%s expires=%s\n",
            AppTime::now()->format(DateTimeImmutable::ATOM),
            $email,
            substr($tokenHash, 0, 16),
            $expiresAt->format(DateTimeImmutable::ATOM)
        );
        if (file_put_contents($base . '/password_reset_debug.log', $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write debug log');
        }
    }

    private function sendEmail(string $email, string $token, DateTimeImmutable $expiresAt): void
    {
        $mailer = $this->getMailer();
        $baseUrl = rtrim((string)($_ENV['APP_BASE_URL'] ?? ''), '/');
        $resetUrl = $baseUrl !== '' ? "{$baseUrl}/password/reset/{$token}" : "/password/reset/{$token}";
        $brand = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';
        $subject = "【{$brand}】パスワード再設定のご案内";
        $body = <<<TXT
{$brand} へのログインで利用しているアカウントについて、パスワード再設定のリクエストを受け付けました。

以下のリンクを開き、新しいパスワードを設定してください。
{$resetUrl}

有効期限: {$expiresAt->setTimezone(AppTime::timezone())->format('Y-m-d H:i:s T')}
※このメールに心当たりがない場合はリンクを開かず破棄してください。
TXT;
        $mailer->send($email, $subject, $body);
    }

    private function getMailer(): Mailer
    {
        if ($this->mailer === null) {
            $this->mailer = new Mailer();
        }
        return $this->mailer;
    }
}
