<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\AppTime;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\Database\Repository;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Attendly\Services\AuthService;
use RuntimeException;

final class AuthController
{
    private Repository $repository;
    private string $trustCookieName;
    private int $trustTtlDays;

    public function __construct(private View $view, private ?AuthService $authService = null, ?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->trustCookieName = trim((string)($_ENV['MFA_TRUST_COOKIE_NAME'] ?? 'mfa_trust'));
        $this->trustTtlDays = max(1, (int)($_ENV['MFA_TRUST_TTL_DAYS'] ?? 30));
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }
        if (SessionAuth::getPendingMfa() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/login/mfa');
        }

        $html = $this->view->renderWithLayout('login', [
            'title' => 'ログイン',
            'csrf' => CsrfToken::getToken(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'flashes' => Flash::consume(),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', '有効なメールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        if ($password === '') {
            Flash::add('error', 'パスワードを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        if (mb_strlen($password, 'UTF-8') > 128) {
            Flash::add('error', 'パスワードが長すぎます。128文字以内で入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        try {
            $service = $this->getAuthService();
            $result = $service->authenticate($email, $password);
        } catch (RuntimeException $e) {
            Flash::add('error', 'DB接続に失敗しました: ' . $e->getMessage());
            return $response->withStatus(303)->withHeader('Location', '/login');
        } catch (\Throwable $e) {
            Flash::add('error', '認証処理中にエラーが発生しました。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        if ($result['user']) {
            $allowedRoles = ['admin', 'tenant_admin', 'employee'];
            $role = $result['user']['role'] ?? null;
            if ($role !== null && !in_array($role, $allowedRoles, true)) {
                Flash::add('error', '無効なロールが検出されました。');
                return $response->withStatus(303)->withHeader('Location', '/login');
            }
            $tenantId = $result['user']['tenant_id'] ?? null;
            if ($tenantId !== null && !is_int($tenantId)) {
                Flash::add('error', 'テナント情報が不正です。');
                return $response->withStatus(303)->withHeader('Location', '/login');
            }
            $trustedResponse = $this->maybeLoginWithTrustedDevice($request, $response, $result['user']);
            if ($trustedResponse !== null) {
                return $trustedResponse;
            }
            $methods = $this->repository->listVerifiedMfaMethods((int)$result['user']['id']);
            $pendingMethods = [];
            foreach ($methods as $method) {
                if ($method['type'] === 'email_otp') {
                    $targetEmail = '';
                    if (isset($method['config']['email']) && is_string($method['config']['email'])) {
                        $targetEmail = trim($method['config']['email']);
                    }
                    if ($targetEmail === '') {
                        $targetEmail = $result['user']['email'];
                    }
                    $pendingMethods[] = [
                        'id' => $method['id'],
                        'type' => 'email_otp',
                        'target_email' => $targetEmail,
                    ];
                } elseif ($method['type'] === 'totp') {
                    $pendingMethods[] = [
                        'id' => $method['id'],
                        'type' => 'totp',
                    ];
                }
            }
            if ($pendingMethods !== []) {
                SessionAuth::setPendingMfa([
                    'user' => [
                        'id' => $result['user']['id'],
                        'email' => $result['user']['email'],
                        'role' => $result['user']['role'] ?? null,
                        'tenant_id' => $result['user']['tenant_id'] ?? null,
                    ],
                    'methods' => $pendingMethods,
                ]);
                Flash::add('info', '多要素認証を完了してください。');
                return $response->withStatus(303)->withHeader('Location', '/login/mfa');
            }

            $response = $this->completeLogin($request, $response, $result['user'], 'ログインしました。');
            $response = $this->clearTrustCookie($response);
            return $response;
        }

        $error = $result['error'] ?? 'unknown';
        if ($error === 'inactive') {
            Flash::add('error', 'アカウントが無効化されています。');
        } elseif ($error === 'invalid_password' || $error === 'not_found') {
            Flash::add('error', 'メールアドレスまたはパスワードが違います。');
        } else {
            Flash::add('error', '認証に失敗しました。');
        }
        return $response
            ->withStatus(303)
            ->withHeader('Location', '/login');
    }

    private function getAuthService(): AuthService
    {
        if ($this->authService === null) {
            $this->authService = new AuthService();
        }
        return $this->authService;
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = SessionAuth::getUser();
        if (is_array($user) && !empty($user['id'])) {
            try {
                $this->repository->deleteUserActiveSession((int)$user['id']);
            } catch (\PDOException $e) {
                // 移行途中でテーブル未作成の場合に全体を落とさない（要: DB適用）
                if ($e->getCode() !== '42S02') {
                    error_log('[auth] failed to delete user_active_sessions on logout');
                }
            } catch (\Throwable) {
                error_log('[auth] failed to delete user_active_sessions on logout');
            }
        }
        // セッションを全破棄（将来セッションハンドラ導入後に置き換え）
        SessionAuth::clear();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Flash::add('success', 'ログアウトしました。');
        $response = $this->clearTrustCookie($response);
        return $response
            ->withStatus(303)
            ->withHeader('Location', '/login');
    }

    /**
     * @param array{id:int,email:string,role:?string,tenant_id:?int} $user
     */
    private function maybeLoginWithTrustedDevice(ServerRequestInterface $request, ResponseInterface $response, array $user): ?ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token = (string)($cookies[$this->trustCookieName] ?? '');
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $record = $this->repository->findTrustedDeviceByHash((int)$user['id'], $hash);
        if ($record === null || $record['expires_at'] <= AppTime::now()) {
            return null;
        }
        $this->repository->touchTrustedDevice($record['id']);
        $response = $this->completeLogin($request, $response, $user, '信頼済みデバイスでログインしました。');
        $newCookie = $this->buildTrustCookie($token, AppTime::now()->modify('+' . $this->trustTtlDays . ' days'));
        return $response->withAddedHeader('Set-Cookie', $newCookie);
    }

    /**
     * @param array{id:int,email:string,role:?string,tenant_id:?int} $user
     */
    private function completeLogin(ServerRequestInterface $request, ResponseInterface $response, array $user, string $flashMessage): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $sessionKey = bin2hex(random_bytes(32));
        SessionAuth::setSessionKey($sessionKey);
        SessionAuth::setUser([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'] ?? null,
            'tenant_id' => $user['tenant_id'] ?? null,
        ]);
        SessionAuth::clearPendingMfa();

        $loginIp = null;
        try {
            $loginIp = ClientIpResolver::resolve($request);
        } catch (\Throwable) {
            $loginIp = null;
        }
        $loginUa = $this->sanitizeUserAgent((string)$request->getHeaderLine('User-Agent'));

        $sessionHash = hash('sha256', $sessionKey);
        try {
            $this->repository->upsertUserActiveSession((int)$user['id'], $sessionHash, $loginIp, $loginUa);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('[auth] user_active_sessions table missing; concurrent login control is disabled until schema is applied');
            } else {
                error_log('[auth] failed to upsert user_active_sessions on login');
            }
        } catch (\Throwable) {
            error_log('[auth] failed to upsert user_active_sessions on login');
        }

        Flash::add('success', $flashMessage);
        return $response->withStatus(303)->withHeader('Location', '/dashboard');
    }

    private function sanitizeUserAgent(string $ua): ?string
    {
        $ua = preg_replace('/[\r\n]/', ' ', $ua) ?? '';
        $ua = trim($ua);
        if ($ua === '') {
            return null;
        }
        if (mb_strlen($ua, 'UTF-8') > 512) {
            $ua = mb_substr($ua, 0, 512, 'UTF-8');
        }
        return $ua;
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

    private function clearTrustCookie(ResponseInterface $response): ResponseInterface
    {
        $attrs = [
            "{$this->trustCookieName}=deleted",
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
        ];
        $secure = filter_var($_ENV['APP_COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOL);
        if ($secure) {
            $attrs[] = 'Secure';
        }
        return $response->withAddedHeader('Set-Cookie', implode('; ', $attrs));
    }
}
