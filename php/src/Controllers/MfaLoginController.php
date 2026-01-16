<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\ClientIpResolver;
use Attendly\Services\EmailOtpService;
use Attendly\Support\AppTime;
use Attendly\Support\Flash;
use Attendly\Support\Mfa;
use Attendly\Support\RateLimiter;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MfaLoginController
{
    private Repository $repository;
    private EmailOtpService $emailOtpService;
    private int $resendInterval;
    private int $otpLength;
    private int $totpPeriod;
    private int $totpDigits;
    private int $totpWindow;
    private int $totpMaxFailures;
    private int $totpLockSeconds;
    private string $trustCookieName;
    private int $trustTtlDays;

    public function __construct(private View $view, ?Repository $repository = null, ?EmailOtpService $emailOtpService = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->emailOtpService = $emailOtpService ?? new EmailOtpService($this->repository);
        $this->resendInterval = $this->sanitizeInt($_ENV['EMAIL_OTP_RESEND_INTERVAL_SECONDS'] ?? 60, 60, 10, 600);
        $this->otpLength = $this->sanitizeInt($_ENV['EMAIL_OTP_LENGTH'] ?? 6, 6, 4, 10);
        $this->totpPeriod = $this->sanitizeInt($_ENV['MFA_TOTP_PERIOD'] ?? 30, 30, 10, 120);
        $this->totpDigits = $this->sanitizeInt($_ENV['MFA_TOTP_DIGITS'] ?? 6, 6, 6, 8);
        $this->totpWindow = $this->sanitizeInt($_ENV['MFA_TOTP_WINDOW'] ?? 1, 1, 0, 4);
        $this->totpMaxFailures = $this->sanitizeInt($_ENV['MFA_TOTP_MAX_FAILURES'] ?? 5, 5, 1, 20);
        $this->totpLockSeconds = $this->sanitizeInt($_ENV['MFA_TOTP_LOCK_SECONDS'] ?? 600, 600, 0, 3600);
        $this->trustCookieName = trim((string)($_ENV['MFA_TRUST_COOKIE_NAME'] ?? 'mfa_trust'));
        $this->trustTtlDays = max(1, (int)($_ENV['MFA_TRUST_TTL_DAYS'] ?? 30));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = SessionAuth::getPendingMfa();
        if ($pending === null) {
            if (SessionAuth::getUser() !== null) {
                return $response->withStatus(303)->withHeader('Location', '/dashboard');
            }
            Flash::add('info', 'ログインからやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $totpMethod = $this->getTotpMethod($pending);
        $emailMethod = $this->getEmailMethod($pending);

        if ($totpMethod === null && $emailMethod === null && !$this->repository->hasActiveRecoveryCodes((int)$pending['user']['id'])) {
            Flash::add('error', '利用可能な二段階認証の方法がありません。');
            SessionAuth::clearPendingMfa();
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $challenge = $emailMethod !== null
            ? $this->getActiveChallenge($pending['user']['id'], (string)$emailMethod['target_email'])
            : null;
        $emailState = $emailMethod !== null ? $this->buildEmailState($challenge) : null;
        $totpState = $this->buildTotpState($totpMethod);
        $hasRecovery = $this->repository->hasActiveRecoveryCodes((int)$pending['user']['id']);

        $html = $this->view->renderWithLayout('login_mfa', [
            'title' => '二段階認証',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => null,
            'email' => $emailMethod['target_email'] ?? null,
            'emailState' => $emailState,
            'totpAvailable' => $totpMethod !== null,
            'totpState' => $totpState,
            'otpLength' => $this->otpLength,
            'totpDigits' => $this->totpDigits,
            'hasRecovery' => $hasRecovery,
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'trustTtlDays' => $this->trustTtlDays,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function sendEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = SessionAuth::getPendingMfa();
        if ($pending === null) {
            if (SessionAuth::getUser() !== null) {
                return $response->withStatus(303)->withHeader('Location', '/dashboard');
            }
            Flash::add('info', 'ログインからやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $emailMethod = $this->getEmailMethod($pending);
        if ($emailMethod === null) {
            Flash::add('error', '利用可能な二段階認証の方法がありません。');
            SessionAuth::clearPendingMfa();
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        try {
            $ip = ClientIpResolver::resolve($request);
        } catch (\RuntimeException $e) {
            Flash::add('error', 'クライアントIPアドレスを特定できません。時間をおいて再試行してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $normalizedEmail = strtolower(trim((string)$emailMethod['target_email']));
        $rateLimits = [
            ["mfa_login_email_send_ip:{$ip}", 10, 600],
            ["mfa_login_email_send_user:{$pending['user']['id']}", 5, 600],
        ];
        if ($normalizedEmail !== '') {
            $rateLimits[] = ["mfa_login_email_send_ip_email:{$ip}:{$normalizedEmail}", 5, 600];
        }
        foreach ($rateLimits as [$key, $maxAttempts, $window]) {
            if (!RateLimiter::allow($key, $maxAttempts, $window)) {
                Flash::add('error', 'リクエストが多すぎます。しばらく待ってから再試行してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
        }
        $challenge = $this->getActiveChallenge((int)$pending['user']['id'], (string)$emailMethod['target_email']);
        if ($challenge !== null) {
            $now = AppTime::now();
            if ($challenge['lock_until'] !== null && $challenge['lock_until'] > $now) {
                Flash::add('error', 'メールコードが一時的にロックされています。しばらく待ってから再試行してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $elapsed = max(0, $now->getTimestamp() - $challenge['last_sent_at']->getTimestamp());
            $wait = $this->resendInterval - $elapsed;
            if ($wait > 0) {
                Flash::add('error', sprintf('再送する前に %d 秒お待ちください。', $wait));
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
        }

        try {
            $this->emailOtpService->issue(
                'mfa_login',
                (int)$pending['user']['id'],
                (string)$emailMethod['target_email'],
                $pending['user']['tenant_id'] ?? null
            );
        } catch (\Throwable $e) {
            Flash::add('error', 'コードの送信に失敗しました。時間をおいて再試行してください。');
            return $response->withStatus(303)->withHeader('Location', '/login/mfa');
        }

        Flash::add('success', '確認コードを送信しました。メールを確認してください。');
        return $response->withStatus(303)->withHeader('Location', '/login/mfa');
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $pending = SessionAuth::getPendingMfa();
        if ($pending === null) {
            if (SessionAuth::getUser() !== null) {
                return $response->withStatus(303)->withHeader('Location', '/dashboard');
            }
            Flash::add('info', 'ログインからやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $body = (array)$request->getParsedBody();
        $authMode = strtolower(trim((string)($body['authMode'] ?? 'email')));

        if ($authMode === 'totp') {
            $totpMethod = $this->getTotpMethod($pending);
            if ($totpMethod === null) {
                Flash::add('error', '認証アプリが登録されていません。メールコードまたはバックアップコードを使用してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            if ($this->isTotpLocked($totpMethod)) {
                Flash::add('error', '認証アプリはロック中です。時間をおいて再試行してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $token = preg_replace('/[^0-9]/', '', (string)($body['token'] ?? '')) ?? '';
            if ($token === '' || strlen($token) !== $this->totpDigits) {
                Flash::add('error', '認証コードを正しく入力してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $method = $this->repository->findVerifiedMfaMethodById((int)$pending['user']['id'], $totpMethod['id']);
            if ($method === null || empty($method['secret'])) {
                Flash::add('error', '認証アプリ情報を取得できませんでした。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $verified = Mfa::verifyTotp(
                $method['secret'],
                $token,
                $this->totpDigits,
                $this->totpPeriod,
                $this->totpWindow
            );
            if (!$verified) {
                $updated = $this->repository->updateMfaFailureState($method['id'], false, $this->totpMaxFailures, $this->totpLockSeconds);
                if ($updated !== null && $this->isTotpLocked($updated)) {
                    Flash::add('error', '試行回数が上限に達しました。時間をおいて再試行してください。');
                } else {
                    Flash::add('error', '認証コードが無効です。再度入力してください。');
                }
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $this->repository->updateMfaFailureState($method['id'], true, $this->totpMaxFailures, $this->totpLockSeconds);
            $this->repository->touchMfaMethodUsed($method['id']);
            $this->emailOtpService->deleteByUserAndPurpose((int)$pending['user']['id'], 'mfa_login');
            $remember = $this->boolFromInput($body['remember_device'] ?? null);
            return $this->completeLogin($pending, $response, $remember, $request);
        }

        if ($authMode === 'backup') {
            $code = (string)($body['backupCode'] ?? '');
            $normalized = Mfa::normalizeRecoveryCode($code);
            if ($normalized === '') {
                Flash::add('error', 'バックアップコードを入力してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $hash = Mfa::hashRecoveryCode($normalized)['code_hash'];
            $record = $this->repository->findUsableRecoveryCode((int)$pending['user']['id'], $hash);
            if ($record === null) {
                Flash::add('error', 'バックアップコードが無効か、すでに使用されています。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }
            $this->repository->markRecoveryCodeUsed((int)$record['id']);
            $this->emailOtpService->deleteByUserAndPurpose((int)$pending['user']['id'], 'mfa_login');
            $remember = $this->boolFromInput($body['remember_device'] ?? null);
            return $this->completeLogin($pending, $response, $remember, $request);
        }

        $emailMethod = $this->getEmailMethod($pending);
        if ($emailMethod === null) {
            Flash::add('error', '利用可能な二段階認証の方法がありません。');
            SessionAuth::clearPendingMfa();
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $token = preg_replace('/[^0-9]/', '', (string)($body['token'] ?? '')) ?? '';
        if ($token === '' || mb_strlen($token, 'UTF-8') !== $this->otpLength) {
            Flash::add('error', '確認コードを正しく入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/login/mfa');
        }

        $result = $this->emailOtpService->verify(
            'mfa_login',
            (int)$pending['user']['id'],
            (string)$emailMethod['target_email'],
            $token
        );
        if (!$result['ok']) {
            $reason = $result['reason'] ?? 'invalid_code';
            if ($reason === 'locked') {
                $retryAt = $result['retry_at'] ?? null;
                $retryMessage = $retryAt instanceof \DateTimeImmutable
                    ? $retryAt->setTimezone(AppTime::timezone())->format('H:i:s') . ' までお待ちください。'
                    : 'しばらく待ってから再試行してください。';
                Flash::add('error', '試行回数が上限に達しました。' . $retryMessage);
            } elseif ($reason === 'expired') {
                Flash::add('error', 'コードの有効期限が切れています。再送信してください。');
            } else {
                Flash::add('error', '確認コードが無効です。再度入力してください。');
            }
            return $response->withStatus(303)->withHeader('Location', '/login/mfa');
        }

        $this->repository->touchMfaMethodUsed($emailMethod['id']);
        $this->emailOtpService->deleteByUserAndPurpose((int)$pending['user']['id'], 'mfa_login');

        $remember = $this->boolFromInput($body['remember_device'] ?? null);
        return $this->completeLogin($pending, $response, $remember, $request);
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getPendingMfa() === null) {
            if (SessionAuth::getUser() !== null) {
                return $response->withStatus(303)->withHeader('Location', '/dashboard');
            }
            Flash::add('info', 'ログインからやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $data = (array)$request->getParsedBody();
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/login/mfa');
        }
        SessionAuth::clearPendingMfa();
        Flash::add('info', 'ログインを中断しました。再度ログインしてください。');
        return $response->withStatus(303)->withHeader('Location', '/login');
    }

    /**
     * @param array{
     *   user:array{id:int,email:string,role:?string,tenant_id:?int},
     *   methods:array<int,array{id:int,type:string,target_email:?string}>
     * } $pending
     * @return array{id:int,type:string,target_email:string}|null
     */
    private function getEmailMethod(array $pending): ?array
    {
        foreach ($pending['methods'] as $method) {
            if ($method['type'] === 'email_otp' && !empty($method['target_email'])) {
                return [
                    'id' => (int)$method['id'],
                    'type' => 'email_otp',
                    'target_email' => (string)$method['target_email'],
                ];
            }
        }
        return null;
    }

    /**
     * @param array{
     *   user:array{id:int,email:string,role:?string,tenant_id:?int},
     *   methods:array<int,array{id:int,type:string,target_email:?string}>
     * } $pending
     * @return array{id:int,type:string,config:?array}|null
     */
    private function getTotpMethod(array $pending): ?array
    {
        foreach ($pending['methods'] as $method) {
            if ($method['type'] === 'totp') {
                $record = $this->repository->findVerifiedMfaMethodById((int)$pending['user']['id'], (int)$method['id']);
                if ($record !== null) {
                    return [
                        'id' => (int)$record['id'],
                        'type' => 'totp',
                        'config' => $record['config'] ?? null,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * @return array{
     *   hasChallenge:bool,
     *   expiresAtDisplay:?string,
     *   isLocked:bool,
     *   resendWaitSeconds:int
     * }
     */
    private function buildEmailState(?array $challenge): array
    {
        $state = [
            'hasChallenge' => $challenge !== null,
            'expiresAtDisplay' => null,
            'isLocked' => false,
            'resendWaitSeconds' => 0,
        ];
        if ($challenge === null) {
            return $state;
        }
        $now = AppTime::now();
        $state['expiresAtDisplay'] = $challenge['expires_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i');
        if ($challenge['lock_until'] !== null && $challenge['lock_until'] > $now) {
            $state['isLocked'] = true;
        }
        $elapsed = max(0, $now->getTimestamp() - $challenge['last_sent_at']->getTimestamp());
        $state['resendWaitSeconds'] = max(0, $this->resendInterval - $elapsed);
        return $state;
    }

    /**
     * @param array{id:int,type:string,config:?array}|null $totpMethod
     * @return array{isLocked:bool,lockUntilDisplay:?string}
     */
    private function buildTotpState(?array $totpMethod): array
    {
        $state = [
            'isLocked' => false,
            'lockUntilDisplay' => null,
        ];
        if ($totpMethod === null || empty($totpMethod['config']['lockUntil'])) {
            return $state;
        }
        try {
            $lock = new \DateTimeImmutable((string)$totpMethod['config']['lockUntil']);
            $now = AppTime::now();
            if ($lock > $now) {
                $state['isLocked'] = true;
                $state['lockUntilDisplay'] = $lock->setTimezone(AppTime::timezone())->format('Y-m-d H:i:s');
            }
        } catch (\Throwable) {
            // ignore parse errors
        }
        return $state;
    }

    private function getActiveChallenge(int $userId, string $targetEmail): ?array
    {
        $email = strtolower(trim($targetEmail));
        if ($email === '') {
            return null;
        }
        return $this->repository->findEmailOtpRequest([
            'user_id' => $userId,
            'purpose' => 'mfa_login',
            'target_email' => $email,
            'only_active' => true,
            'active_at' => AppTime::now(),
        ]);
    }

    private function isTotpLocked(array $method): bool
    {
        $config = $method['config'] ?? [];
        if (empty($config['lockUntil'])) {
            return false;
        }
        try {
            $lock = new \DateTimeImmutable((string)$config['lockUntil']);
            return $lock > AppTime::now();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{user:array{id:int,email:string,role:?string,tenant_id:?int}} $pending
     */
    private function completeLogin(array $pending, ResponseInterface $response, bool $rememberDevice = false, ?ServerRequestInterface $request = null): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $sessionKey = bin2hex(random_bytes(32));
        SessionAuth::setSessionKey($sessionKey);
        SessionAuth::setUser([
            'id' => $pending['user']['id'],
            'email' => $pending['user']['email'],
            'role' => $pending['user']['role'],
            'tenant_id' => $pending['user']['tenant_id'],
        ]);
        SessionAuth::clearPendingMfa();
        $_SESSION['show_login_announcements'] = true;

        $loginIp = null;
        if ($request !== null) {
            try {
                $loginIp = ClientIpResolver::resolve($request);
            } catch (\Throwable) {
                $loginIp = null;
            }
        }
        $loginUa = null;
        if ($request !== null) {
            $loginUa = $this->sanitizeUserAgentForAudit((string)$request->getHeaderLine('User-Agent'));
        }
        $sessionHash = hash('sha256', $sessionKey);
        try {
            $this->repository->upsertUserActiveSession((int)$pending['user']['id'], $sessionHash, $loginIp, $loginUa);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('[auth] user_active_sessions table missing; concurrent login control is disabled until schema is applied');
            } else {
                error_log('[auth] failed to upsert user_active_sessions on login');
            }
        } catch (\Throwable) {
            error_log('[auth] failed to upsert user_active_sessions on login');
        }
        try {
            $now = AppTime::now();
            $this->repository->createLoginSession((int)$pending['user']['id'], $sessionHash, $now, $loginIp, $loginUa);
            $this->repository->revokeOtherLoginSessions((int)$pending['user']['id'], $sessionHash, $now);
            $userRef = hash('sha256', (string)$pending['user']['id']);
            $ipLabel = $loginIp !== null ? $loginIp : 'unknown';
            error_log(sprintf('[auth] login session created user_ref=%s ip=%s', $userRef, $ipLabel));
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('[auth] user_login_sessions table missing; session history is disabled until schema is applied');
            } else {
                error_log('[auth] failed to create user_login_sessions on login');
            }
        } catch (\Throwable) {
            error_log('[auth] failed to create user_login_sessions on login');
        }

        Flash::add('success', 'ログインしました。');
        $forcePasswordChange = !empty($pending['force_password_change']);
        SessionAuth::setForcePasswordChange($forcePasswordChange);
        if ($rememberDevice) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = AppTime::now()->modify('+' . $this->trustTtlDays . ' days');
            $deviceInfo = null;
            if ($request !== null) {
                $ua = $this->sanitizeUserAgentForTrustedDevice((string)$request->getHeaderLine('User-Agent'));
                $deviceInfo = $ua !== null ? $ua : null;
            }
            $record = $this->repository->findTrustedDeviceByHash((int)$pending['user']['id'], $hash);
            if ($record === null) {
                $this->repository->createTrustedDevice((int)$pending['user']['id'], $hash, $deviceInfo, $expires);
            }
            $cookie = $this->buildTrustCookie($token, $expires);
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }
        if ($forcePasswordChange) {
            Flash::add('error', 'プラットフォーム管理者のパスワードを更新してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    private function sanitizeUserAgentForAudit(string $ua): ?string
    {
        return $this->sanitizeUserAgentWithMaxLen($ua, 512);
    }

    private function sanitizeUserAgentForTrustedDevice(string $ua): ?string
    {
        return $this->sanitizeUserAgentWithMaxLen($ua, 250);
    }

    private function sanitizeUserAgentWithMaxLen(string $ua, int $maxLen): ?string
    {
        $ua = preg_replace('/[\r\n]/', ' ', $ua) ?? '';
        $ua = trim($ua);
        if ($ua === '') {
            return null;
        }
        if ($maxLen > 0 && mb_strlen($ua, 'UTF-8') > $maxLen) {
            $ua = mb_substr($ua, 0, $maxLen, 'UTF-8');
        }
        return $ua;
    }

    private function sanitizeInt(int|string $value, int $default, int $min, int $max): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return $default;
        }
        return max($min, min($max, (int)$intVal));
    }

    private function boolFromInput(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function buildTrustCookie(string $token, \DateTimeImmutable $expiresAt): string
    {
        $attrs = [
            "{$this->trustCookieName}={$token}",
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Expires=' . $expiresAt->setTimezone(new \DateTimeZone('GMT'))->format('D, d M Y H:i:s T'),
        ];
        $secure = filter_var($_ENV['APP_COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOL);
        if ($secure) {
            $attrs[] = 'Secure';
        }
        return implode('; ', $attrs);
    }
}
