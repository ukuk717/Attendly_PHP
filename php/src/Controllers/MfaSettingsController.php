<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Database\Repository;
use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\Mfa;
use Attendly\Support\SessionAuth;
use Attendly\View;
use chillerlan\QRCode\QRCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MfaSettingsController
{
    public function __construct(private View $view, private ?Repository $repository = null)
    {
        $this->repository = $this->repository ?? new Repository();
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $userId = (int)$user['id'];
        $verifiedTotp = $this->repository->findVerifiedMfaMethodByType($userId, 'totp');
        $pendingSecret = null;
        $totpUri = null;
        if ($verifiedTotp === null) {
            $pendingSecret = SessionAuth::getPendingTotpSecret();
            if ($pendingSecret === null) {
                $pendingSecret = Mfa::generateTotpSecret();
                SessionAuth::setPendingTotpSecret($pendingSecret);
            }
            $alreadyShown = SessionAuth::hasShownPendingTotpSecret();
            if (!$alreadyShown) {
                $issuer = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';
                $totpUri = Mfa::buildTotpUri($pendingSecret, (string)($user['email'] ?? 'user'), $issuer);
                SessionAuth::markPendingTotpSecretShown();
            } else {
                $pendingSecret = null;
                $totpUri = null;
            }
        }

        $newRecoveryCodes = SessionAuth::consumeRecoveryCodesForDisplay();
        $hasRecoveryCodes = $this->repository->hasActiveRecoveryCodes($userId);
        $totpDigits = $this->getTotpDigits();
        $totpQrSrc = null;
        if ($totpUri !== null && $totpUri !== '') {
            try {
                $totpQrSrc = (new QRCode())->render($totpUri);
            } catch (\Throwable) {
                $totpQrSrc = null;
            }
        }

        $html = $this->view->renderWithLayout('settings_mfa', [
            'title' => '多要素認証の設定',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'totpVerified' => $verifiedTotp !== null,
            'pendingSecret' => $pendingSecret,
            'totpUri' => $totpUri,
            'totpQrSrc' => $totpQrSrc,
            'totpSetupHidden' => $verifiedTotp === null && SessionAuth::hasShownPendingTotpSecret() && ($pendingSecret === null),
            'newRecoveryCodes' => $newRecoveryCodes,
            'hasRecoveryCodes' => $hasRecoveryCodes,
            'totpDigits' => $totpDigits,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function verifyTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $secret = SessionAuth::getPendingTotpSecret();
        if ($secret === null) {
            Flash::add('error', 'セットアップ用のシークレットが見つかりません。やり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }

        $token = preg_replace('/[^0-9]/', '', (string)($body['token'] ?? '')) ?? '';
        $digits = $this->getTotpDigits();
        $period = max(10, min(120, (int)($_ENV['MFA_TOTP_PERIOD'] ?? 30)));
        $window = max(0, min(4, (int)($_ENV['MFA_TOTP_WINDOW'] ?? 1)));
        if ($token === '' || strlen($token) !== $digits) {
            Flash::add('error', '認証コードを正しく入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $verified = Mfa::verifyTotp($secret, $token, $digits, $period, $window);
        if (!$verified) {
            Flash::add('error', '認証コードが無効です。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }

        $this->repository->upsertTotpMethod((int)$user['id'], $secret, ['failedAttempts' => 0, 'lockUntil' => null]);
        SessionAuth::clearPendingTotpSecret();

        $codes = Mfa::generateRecoveryCodes(10);
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = Mfa::hashRecoveryCode($code)['code_hash'];
        }
        $this->repository->replaceRecoveryCodes((int)$user['id'], $hashes);
        SessionAuth::setRecoveryCodesForDisplay($codes);

        Flash::add('success', '認証アプリを有効化しました。バックアップコードを安全な場所に保管してください。');
        return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
    }

    public function resetTotpSetup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $verified = $this->repository->findVerifiedMfaMethodByType((int)$user['id'], 'totp');
        if ($verified !== null) {
            Flash::add('info', '認証アプリはすでに有効です。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        SessionAuth::resetPendingTotpSetup();
        Flash::add('success', '認証アプリのセットアップをやり直します。');
        return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
    }

    public function disableTotp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }

        $userId = (int)$user['id'];
        $verified = $this->repository->findVerifiedMfaMethodByType($userId, 'totp');
        if ($verified === null) {
            Flash::add('info', '認証アプリはまだ有効化されていません。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }

        $confirmed = strtolower(trim((string)($body['confirmed'] ?? '')));
        if ($confirmed !== 'yes') {
            Flash::add('error', '無効化を実行するには確認にチェックしてください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }

        try {
            $this->repository->beginTransaction();
            $this->repository->deleteMfaMethodsByUserAndType($userId, 'totp');
            $this->repository->deleteRecoveryCodesByUser($userId);
            $this->repository->deleteTrustedDevicesByUser($userId);
            $this->repository->commit();
        } catch (\Throwable) {
            $this->repository->rollback();
            Flash::add('error', '認証アプリの無効化に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }

        SessionAuth::clearPendingTotpSecret();
        Flash::add('success', '認証アプリを無効化しました。');
        return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
    }

    public function regenerateRecoveryCodes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }
        $verified = $this->repository->findVerifiedMfaMethodByType((int)$user['id'], 'totp');
        if ($verified === null) {
            Flash::add('error', '認証アプリを有効化した後にバックアップコードを発行できます。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $codes = Mfa::generateRecoveryCodes(10);
        $hashes = [];
        foreach ($codes as $code) {
            $hashes[] = Mfa::hashRecoveryCode($code)['code_hash'];
        }
        $this->repository->replaceRecoveryCodes((int)$user['id'], $hashes);
        SessionAuth::setRecoveryCodesForDisplay($codes);
        Flash::add('success', 'バックアップコードを再発行しました。新しいコードのみ有効です。');
        return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
    }

    public function revokeTrustedDevices(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || empty($user['id'])) {
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        $body = (array)$request->getParsedBody();
        if (!CsrfToken::verify((string)($body['csrf_token'] ?? ''))) {
            Flash::add('error', '無効なリクエストです。もう一度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
        }
        $reauthRedirect = $this->enforceRecentAuthentication($response);
        if ($reauthRedirect !== null) {
            return $reauthRedirect;
        }
        $this->repository->deleteTrustedDevicesByUser((int)$user['id']);
        Flash::add('success', '信頼済みデバイスをすべて無効化しました。');
        return $response->withStatus(303)->withHeader('Location', '/settings/mfa');
    }

    private function getTotpDigits(): int
    {
        $digits = filter_var(
            $_ENV['MFA_TOTP_DIGITS'] ?? 6,
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 6, 'min_range' => 6, 'max_range' => 8]]
        );
        return $digits === false ? 6 : (int)$digits;
    }

    private function enforceRecentAuthentication(ResponseInterface $response): ?ResponseInterface
    {
        $ttl = $this->getSensitiveActionReauthTtl();
        if (SessionAuth::hasRecentAuthentication($ttl)) {
            return null;
        }
        SessionAuth::clear();
        Flash::add('error', 'セキュリティ保護のため、再度ログインしてからこの操作を実行してください。');
        return $response->withStatus(303)->withHeader('Location', '/login');
    }

    private function getSensitiveActionReauthTtl(): int
    {
        $value = filter_var($_ENV['MFA_SENSITIVE_REAUTH_SECONDS'] ?? 900, FILTER_VALIDATE_INT);
        if ($value === false) {
            return 900;
        }
        return max(60, min(3600, (int)$value));
    }
}
