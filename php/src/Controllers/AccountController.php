<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Services\EmailOtpService;
use Attendly\Support\AppTime;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\Flash;
use Attendly\Support\PasswordHasher;
use Attendly\Support\PasswordPolicy;
use Attendly\Support\RateLimiter;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AccountController
{
    private Repository $repository;
    private EmailOtpService $emailOtpService;
    private PasswordPolicy $passwordPolicy;
    private PasswordHasher $passwordHasher;
    private int $minPasswordLength;
    private int $maxPasswordLength;
    private int $reauthTtl;
    private int $pendingEmailTtl;
    private int $otpLength;

    public function __construct(private View $view, ?Repository $repository = null, ?EmailOtpService $emailOtpService = null)
    {
        $rawMin = $_ENV['MIN_PASSWORD_LENGTH'] ?? 8;
        $this->minPasswordLength = filter_var($rawMin, FILTER_VALIDATE_INT, ['options' => ['min_range' => 8]]) ?: 8;
        if ($this->minPasswordLength < 8) {
            $this->minPasswordLength = 8;
        }
        $rawMax = $_ENV['MAX_PASSWORD_LENGTH'] ?? 256;
        $this->maxPasswordLength = filter_var($rawMax, FILTER_VALIDATE_INT, ['options' => ['min_range' => 32]]) ?: 256;
        if ($this->maxPasswordLength <= $this->minPasswordLength) {
            $this->maxPasswordLength = max(256, $this->minPasswordLength + 1);
        }
        $this->passwordPolicy = new PasswordPolicy($this->minPasswordLength, $this->maxPasswordLength);
        $this->passwordHasher = new PasswordHasher();
        $this->repository = $repository ?? new Repository();
        $this->emailOtpService = $emailOtpService ?? new EmailOtpService($this->repository);
        $this->reauthTtl = $this->sanitizeInt($_ENV['ACCOUNT_REAUTH_SECONDS'] ?? 900, 900, 60, 3600);
        $this->pendingEmailTtl = $this->sanitizeInt($_ENV['ACCOUNT_EMAIL_PENDING_SECONDS'] ?? 1800, 1800, 300, 7200);
        $this->otpLength = $this->sanitizeInt($_ENV['EMAIL_OTP_LENGTH'] ?? 6, 6, 4, 10);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $profile = $this->repository->findUserProfile($user['id']);
        if ($profile === null) {
            Flash::add('error', 'アカウント情報を取得できませんでした。再度ログインしてください。');
            SessionAuth::clear();
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $pendingEmail = SessionAuth::getPendingEmailChange();
        $pendingChallenge = null;
        if ($pendingEmail !== null) {
            $pendingChallenge = $this->repository->findEmailOtpRequest([
                'user_id' => $user['id'],
                'purpose' => 'email_change',
                'target_email' => $pendingEmail['email'],
                'only_active' => true,
                'active_at' => AppTime::now(),
            ]);
            if ($pendingChallenge === null) {
                SessionAuth::clearPendingEmailChange();
            }
        }
        $emailState = $this->buildEmailState($pendingChallenge, $pendingEmail);
        $passkeys = [];
        try {
            foreach ($this->repository->listPasskeysByUser($user['id']) as $passkey) {
                $createdAt = $passkey['created_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i');
                $lastUsed = $passkey['last_used_at'] instanceof \DateTimeImmutable
                    ? $passkey['last_used_at']->setTimezone(AppTime::timezone())->format('Y-m-d H:i')
                    : null;
                $passkeys[] = [
                    'id' => $passkey['id'],
                    'name' => $passkey['name'],
                    'created_at' => $createdAt,
                    'last_used_at' => $lastUsed,
                    'transports' => $passkey['transports'],
                ];
            }
        } catch (\Throwable) {
            Flash::add('error', 'パスキー情報を取得できませんでした。');
        }

        $html = $this->view->renderWithLayout('account_settings', [
            'title' => 'アカウント設定',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'profile' => $profile,
            'minPasswordLength' => $this->minPasswordLength,
            'maxPasswordLength' => $this->maxPasswordLength,
            'otpLength' => $this->otpLength,
            'pendingEmail' => $emailState['email'],
            'pendingEmailExpiresAt' => $emailState['expiresAtDisplay'],
            'pendingEmailLocked' => $emailState['isLocked'],
            'passkeys' => $passkeys,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function updateProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $data = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($data['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $lastName = trim((string)($data['lastName'] ?? ''));
        $firstName = trim((string)($data['firstName'] ?? ''));
        $errors = [];
        if ($lastName === '' || $firstName === '') {
            $errors[] = '姓と名を入力してください。';
        }
        if (mb_strlen($lastName, 'UTF-8') > 64 || mb_strlen($firstName, 'UTF-8') > 64) {
            $errors[] = '氏名は64文字以内で入力してください。';
        }
        if (preg_match('/[\\r\\n]/', $lastName . $firstName)) {
            $errors[] = '改行を含む名前は使用できません。';
        }
        if (!empty($errors)) {
            foreach ($errors as $err) {
                Flash::add('error', $err);
            }
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        try {
            $this->repository->updateUserProfile($user['id'], $firstName, $lastName);
        } catch (\Throwable $e) {
            Flash::add('error', 'プロフィールの更新に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        Flash::add('success', 'プロフィールを更新しました。');
        return $response->withStatus(303)->withHeader('Location', '/account');
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $data = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($data['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }

        $current = (string)($data['currentPassword'] ?? '');
        $newPassword = (string)($data['newPassword'] ?? '');
        $confirm = (string)($data['newPasswordConfirmation'] ?? '');

        $errors = [];
        if ($current === '') {
            $errors[] = '現在のパスワードを入力してください。';
        }
        if ($newPassword !== $confirm) {
            $errors[] = '新しいパスワードが確認用と一致しません。';
        }
        $policyResult = $this->passwordPolicy->validate($newPassword);
        if (!$policyResult['ok']) {
            $errors = array_merge($errors, $policyResult['errors']);
        }
        if (!empty($errors)) {
            foreach ($errors as $err) {
                Flash::add('error', $err);
            }
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        $record = $this->repository->findUserById($user['id']);
        if ($record === null) {
            Flash::add('error', 'アカウント情報を取得できませんでした。再度ログインしてください。');
            SessionAuth::clear();
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $verifyCurrent = $this->passwordHasher->verify($current, $record['password_hash']);
        if (!$verifyCurrent['ok']) {
            Flash::add('error', '現在のパスワードが一致しません。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $verifyNew = $this->passwordHasher->verify($newPassword, $record['password_hash']);
        if ($verifyNew['ok']) {
            Flash::add('error', '新しいパスワードは現在のパスワードと異なるものを設定してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        try {
            $hash = $this->passwordHasher->hash($newPassword);
        } catch (\Throwable) {
            Flash::add('error', 'パスワードの更新に失敗しました。別のパスワードをお試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        try {
            $this->repository->updateUserPassword($user['id'], $hash);
            $this->repository->deleteTrustedDevicesByUser($user['id']);
            $this->emailOtpService->deleteByUserAndPurpose($user['id'], 'email_change');
            SessionAuth::clearPendingEmailChange();
        } catch (\Throwable $e) {
            Flash::add('error', 'パスワードの更新に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        SessionAuth::setUser([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'tenant_id' => $user['tenant_id'],
        ]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Flash::add('success', 'パスワードを変更しました。再度ログインを要求される場合があります。');
        return $response->withStatus(303)->withHeader('Location', '/account');
    }

    public function requestEmailChange(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $data = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($data['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }

        $newEmail = strtolower(trim((string)($data['email'] ?? '')));
        if ($newEmail === '' || mb_strlen($newEmail, 'UTF-8') > 254 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', '有効なメールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        if (preg_match('/[\\r\\n]/', $newEmail)) {
            Flash::add('error', 'メールアドレスに改行を含めることはできません。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        if ($newEmail === strtolower((string)$user['email'])) {
            Flash::add('error', '現在のメールアドレスとは異なるアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        $existing = $this->repository->findUserByEmail($newEmail);
        if ($existing !== null && (int)$existing['id'] !== $user['id']) {
            Flash::add('error', 'このメールアドレスは既に使用されています。別のアドレスを指定してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        $rateKeys = [
            ["account_email_change_user:{$user['id']}", 5, 3600],
        ];
        try {
            $ip = ClientIpResolver::resolve($request);
            $rateKeys[] = ["account_email_change_ip:{$ip}", 15, 3600];
            $rateKeys[] = ["account_email_change_ip_email:{$ip}:{$newEmail}", 5, 3600];
        } catch (\RuntimeException) {
            // IPが特定できなくてもユーザー単位のレートリミットで継続
        }
        foreach ($rateKeys as [$key, $max, $window]) {
            if (!RateLimiter::allow($key, $max, $window)) {
                Flash::add('error', 'リクエストが多すぎます。しばらく待ってから再試行してください。');
                return $response->withStatus(303)->withHeader('Location', '/account');
            }
        }

        try {
            $profile = $this->repository->findUserProfile($user['id']);
            $tenantId = is_array($profile) ? ($profile['tenant_id'] ?? null) : null;
            $this->emailOtpService->issue('email_change', $user['id'], $newEmail, $tenantId);
            SessionAuth::setPendingEmailChange($newEmail, $this->pendingEmailTtl);
        } catch (\Throwable $e) {
            Flash::add('error', '確認コードの送信に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        Flash::add('success', '確認コードを新しいメールアドレスへ送信しました。メールを確認してコードを入力してください。');
        return $response->withStatus(303)->withHeader('Location', '/account');
    }

    public function verifyEmailChange(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->getCurrentUser($request);
        if ($user === null) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $data = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($data['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }

        $pending = SessionAuth::getPendingEmailChange();
        if ($pending === null) {
            Flash::add('error', 'メール変更のリクエストが見つかりません。再度コードを送信してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }
        $token = preg_replace('/[^0-9]/', '', (string)($data['token'] ?? '')) ?? '';
        if ($token === '' || strlen($token) !== $this->otpLength) {
            Flash::add('error', '確認コードを正しく入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        $result = $this->emailOtpService->verify('email_change', $user['id'], $pending['email'], $token);
        if (!$result['ok']) {
            $reason = $result['reason'] ?? 'invalid_code';
            if ($reason === 'locked') {
                $retryAt = $result['retry_at'] ?? null;
                $retryMessage = $retryAt instanceof \DateTimeImmutable
                    ? $retryAt->setTimezone(AppTime::timezone())->format('H:i:s') . ' までお待ちください。'
                    : 'しばらく待ってから再試行してください。';
                Flash::add('error', '試行回数の上限に達しました。' . $retryMessage);
            } elseif ($reason === 'expired') {
                Flash::add('error', '確認コードの有効期限が切れています。再度コードを送信してください。');
            } else {
                Flash::add('error', '確認コードが一致しません。');
            }
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        try {
            $this->repository->updateUserEmail($user['id'], $pending['email']);
            $this->emailOtpService->deleteByUserAndPurpose($user['id'], 'email_change');
            SessionAuth::clearPendingEmailChange();
            $this->repository->deleteTrustedDevicesByUser($user['id']);
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                Flash::add('error', 'このメールアドレスは既に使用されています。別のアドレスを指定してください。');
            } else {
                Flash::add('error', 'メールアドレスの更新に失敗しました。時間をおいて再試しください。');
            }
            return $response->withStatus(303)->withHeader('Location', '/account');
        }

        SessionAuth::setUser([
            'id' => $user['id'],
            'email' => $pending['email'],
            'role' => $user['role'],
            'tenant_id' => $user['tenant_id'],
        ]);

        Flash::add('success', 'メールアドレスを更新しました。新しいメールアドレスでログイン通知が送信されます。');
        return $response->withStatus(303)->withHeader('Location', '/account');
    }

    private function getCurrentUser(ServerRequestInterface $request): ?array
    {
        $current = $request->getAttribute('currentUser');
        if (!is_array($current) || empty($current['id'])) {
            return null;
        }
        return [
            'id' => (int)$current['id'],
            'email' => (string)($current['email'] ?? ''),
            'role' => $current['role'] ?? null,
            'tenant_id' => isset($current['tenant_id']) ? (int)$current['tenant_id'] : null,
        ];
    }

    private function enforceRecentAuthentication(ResponseInterface $response): ?ResponseInterface
    {
        if (SessionAuth::hasRecentAuthentication($this->reauthTtl)) {
            return null;
        }
        SessionAuth::clear();
        Flash::add('error', 'セキュリティ保護のため、再度ログインしてから操作を実行してください。');
        return $response->withStatus(303)->withHeader('Location', '/login');
    }

    /**
     * @param array{id:int,user_id:int,tenant_id:?int,role_code_id:?int,purpose:string,target_email:string,code_hash:string,expires_at:\DateTimeImmutable,consumed_at:?DateTimeImmutable,failed_attempts:int,max_attempts:int,lock_until:?DateTimeImmutable,last_sent_at:\DateTimeImmutable,metadata:?array}|null $challenge
     * @param array{email:string,expires_at:?int}|null $pending
     * @return array{email:?string,expiresAtDisplay:?string,isLocked:bool}
     */
    private function buildEmailState(?array $challenge, ?array $pending): array
    {
        if ($pending === null) {
            return ['email' => null, 'expiresAtDisplay' => null, 'isLocked' => false];
        }
        $expires = $pending['expires_at'] !== null
            ? (new \DateTimeImmutable('@' . $pending['expires_at']))->setTimezone(AppTime::timezone())
            : null;
        $display = $expires?->format('Y-m-d H:i:s');
        $locked = false;
        if ($challenge !== null && $challenge['lock_until'] !== null) {
            $locked = $challenge['lock_until'] > AppTime::now();
        }
        return [
            'email' => $pending['email'],
            'expiresAtDisplay' => $display,
            'isLocked' => $locked,
        ];
    }

    private function sanitizeInt(int|string $value, int $default, int $min, int $max): int
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return $default;
        }
        return max($min, min($max, (int)$intVal));
    }
}
