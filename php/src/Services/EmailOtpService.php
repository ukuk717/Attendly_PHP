<?php

declare(strict_types=1);

namespace Attendly\Services;

use Attendly\Database\Repository;
use Attendly\Support\Mailer;
use Attendly\Support\AppTime;
use DateInterval;
use DateTimeImmutable;

final class EmailOtpService
{
    private Repository $repository;
    private Mailer $mailer;
    private int $ttlSeconds;
    private int $maxAttempts;
    private int $lockSeconds;
    private int $length;
    private string $brand;

    public function __construct(?Repository $repository = null, ?Mailer $mailer = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->mailer = $mailer ?? new Mailer();
        $this->ttlSeconds = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_TTL_SECONDS'] ?? 600, 600, 60, 3600);
        $this->maxAttempts = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_MAX_ATTEMPTS'] ?? 5, 5, 1, 20);
        $this->lockSeconds = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_LOCK_SECONDS'] ?? 600, 600, 60, 3600);
        $this->length = $this->sanitizePositiveInt($_ENV['EMAIL_OTP_LENGTH'] ?? 6, 6, 4, 10);
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
        $expiresAt = AppTime::now()->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
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
                'last_sent_at' => AppTime::now(),
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

        if (!$this->isProduction() && $this->shouldLogDebugOtp()) {
            $this->logDebugOtp($purpose, $targetEmail, $code, $expiresAt);
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
            'active_at' => AppTime::now(),
        ]);
        if ($challenge === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        $now = AppTime::now();
        if ($challenge['lock_until'] !== null && $challenge['lock_until'] > $now) {
            return ['ok' => false, 'reason' => 'locked', 'retry_at' => $challenge['lock_until']];
        }
        if ($challenge['expires_at'] <= $now) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        $normalizedToken = $this->normalizeToken($token);
        $hashedInput = '';
        if ($normalizedToken !== '' && strlen($normalizedToken) === $this->length) {
            $hashedInput = hash('sha256', $normalizedToken);
        }
        if ($hashedInput === '' || !hash_equals($challenge['code_hash'], $hashedInput)) {
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
有効期限: {$expiresAt->setTimezone(AppTime::timezone())->format('Y-m-d H:i:s T')}

このメールに心当たりがない場合は破棄してください。
TXT;
        $this->mailer->send($to, $subject, $body);
    }

    private function generateCode(): string
    {
        $digits = '';
        while (strlen($digits) < $this->length) {
            $digits .= (string)random_int(0, 9);
        }
        return substr($digits, 0, $this->length);
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

    private function shouldLogDebugOtp(): bool
    {
        return filter_var($_ENV['EMAIL_OTP_DEBUG_LOG'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function logDebugOtp(string $purpose, string $email, string $code, DateTimeImmutable $expiresAt): void
    {
        $base = dirname(__DIR__, 2) . '/storage';
        $safePurpose = preg_replace('/[\r\n]/', '', $purpose) ?? '';
        $safeEmail = preg_replace('/[\r\n]/', '', $email) ?? '';
        $emailRef = $safeEmail !== '' ? substr(hash('sha256', $safeEmail), 0, 12) : 'unknown';
        $safeCode = preg_replace('/[^0-9]/', '', $code) ?? '';
        $line = sprintf(
            "[%s] email_otp purpose=%s email_ref=%s code=%s expires=%s\n",
            AppTime::now()->format(DateTimeImmutable::ATOM),
            $safePurpose,
            $emailRef,
            $safeCode,
            $expiresAt->format(DateTimeImmutable::ATOM)
        );

        // ローカル開発での視認性のため、サーバーログ（error_log）にも出す。
        // ※ 非本番かつ EMAIL_OTP_DEBUG_LOG=true のときのみ呼ばれる。
        error_log(rtrim($line));

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            error_log('email_otp_debug.log write skipped: storage directory not available');
            return;
        }

        $result = file_put_contents($base . '/email_otp_debug.log', $line, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log('email_otp_debug.log write failed');
        }
    }
}
