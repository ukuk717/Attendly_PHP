<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\Mailer;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class EmailOtpService
{
    private Repository $repository;
    private Mailer $mailer;
    private int $ttlSeconds;
    private int $maxAttempts;
    private int $lockSeconds;
    private string $brand;

    public function __construct(?Repository $repository = null, ?Mailer $mailer = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->mailer = $mailer ?? new Mailer();
        $this->ttlSeconds = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_TTL_SECONDS'] ?? 600, 600, 60, 3600);
        $this->maxAttempts = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_MAX_ATTEMPTS'] ?? 5, 5, 1, 20);
        $this->lockSeconds = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_LOCK_SECONDS'] ?? 600, 600, 60, 3600);
        $this->brand = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';
    }

    /**
     * @return array{code:?string, expires_at:DateTimeImmutable, challenge:array{id:int}}
     */
    public function issue(string $purpose, int $userId, string $email, ?int $tenantId = null, ?int $roleCodeId = null): array
    {
        $targetEmail = $this->normalizeEmail($email);
        if ($targetEmail === '') {
            throw new \InvalidArgumentException('無効なメールアドレスです。');
        }
        $expiresAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $code = $this->generateCode();
        $codeHash = hash('sha256', $code);

        $existing = $this->repository->findEmailOtpRequest([
            'user_id' => $userId,
            'purpose' => $purpose,
            'target_email' => $targetEmail,
        ]);

        if ($existing !== null) {
            $challenge = $this->repository->updateEmailOtpRequest((int)$existing['id'], [
                'code_hash' => $codeHash,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'failed_attempts' => 0,
                'max_attempts' => $this->maxAttempts,
                'lock_until' => null,
                'last_sent_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ]);
        } else {
            $challenge = $this->repository->createEmailOtpRequest([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role_code_id' => $roleCodeId,
                'purpose' => $purpose,
                'target_email' => $targetEmail,
                'code_hash' => $codeHash,
                'expires_at' => $expiresAt,
                'max_attempts' => $this->maxAttempts,
            ]);
        }

        $this->sendVerificationMail($targetEmail, $code, $expiresAt);

        return [
            'code' => $this->isProduction() ? null : $code,
            'expires_at' => $expiresAt,
            'challenge' => ['id' => (int)$challenge['id']],
        ];
    }

    /**
     * @return array{ok:bool, reason?:string, retry_at?:DateTimeImmutable}
     */
    public function verify(string $purpose, int $userId, string $email, string $token): array
    {
        $targetEmail = $this->normalizeEmail($email);
        if ($targetEmail === '') {
            return ['ok' => false, 'reason' => 'invalid_email'];
        }
        $challenge = $this->repository->findEmailOtpRequest([
            'user_id' => $userId,
            'purpose' => $purpose,
            'target_email' => $targetEmail,
            'only_active' => true,
            'active_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ]);
        if ($challenge === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($challenge['lock_until'] !== null && $challenge['lock_until'] > $now) {
            return ['ok' => false, 'reason' => 'locked', 'retry_at' => $challenge['lock_until']];
        }
        if ($challenge['expires_at'] <= $now) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        $normalizedToken = $this->normalizeToken($token);
        $hashedInput = hash('sha256', $normalizedToken);
        if ($normalizedToken === '' || !hash_equals($challenge['code_hash'], $hashedInput)) {
            $updated = $this->repository->incrementEmailOtpFailure((int)$challenge['id'], $this->maxAttempts, $this->lockSeconds);
            if ($updated !== null && $updated['lock_until'] !== null && $updated['lock_until'] > $now) {
                return ['ok' => false, 'reason' => 'locked', 'retry_at' => $updated['lock_until']];
            }
            return ['ok' => false, 'reason' => 'invalid_code'];
        }

        $this->repository->updateEmailOtpRequest((int)$challenge['id'], [
            'consumed_at' => $now,
            'failed_attempts' => 0,
            'lock_until' => null,
        ]);

        return ['ok' => true];
    }

    public function deleteByUserAndPurpose(int $userId, string $purpose): void
    {
        $this->repository->deleteEmailOtpRequests(['user_id' => $userId, 'purpose' => $purpose]);
    }

    private function sendVerificationMail(string $to, string $code, DateTimeImmutable $expiresAt): void
    {
        $brand = $this->brand !== '' ? $this->brand : 'Attendly';
        $subject = "【{$brand}】確認コードのご案内";
        $body = <<<TXT
{$brand} への登録を完了するには、以下の確認コードを入力してください。

確認コード: {$code}
有効期限: {$expiresAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s T')}

このメールに心当たりがない場合は破棄してください。
TXT;
        $this->mailer->send($to, $subject, $body);
    }

    private function generateCode(): string
    {
        $digits = '';
        while (strlen($digits) < 6) {
            $digits .= (string)random_int(0, 9);
        }
        return substr($digits, 0, 6);
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 320 || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $normalized;
    }

    private function normalizeToken(string $token): string
    {
        return preg_replace('/[^0-9]/', '', trim($token)) ?? '';
    }

    private function sanitizePositiveInt(int|string $value, int $default, int $min, int $max): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return $default;
        }
        return min($max, max($min, (int)$intVal));
    }

    private function isProduction(): bool
    {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'local'));
        return $env === 'production' || $env === 'prod';
    }
}
