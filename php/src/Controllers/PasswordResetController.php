<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\RateLimiter;
use Attendly\Support\SessionAuth;
use Attendly\Services\PasswordResetService;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordResetController
{
    public function __construct(private View $view, private ?PasswordResetService $resetService = null)
    {
    }

    public function showRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $html = $this->view->renderWithLayout('password_reset_request', [
            'title' => 'パスワードリセット',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function request(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $email = trim((string)($data['email'] ?? ''));
        $emailLower = strtolower($email);
        $csrf = $data['csrf_token'] ?? '';
        if (!is_string($csrf) || $csrf === '' || !hash_equals(CsrfToken::getToken(), $csrf)) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        try {
            $ip = ClientIpResolver::resolve($request);
        } catch (\RuntimeException $e) {
            Flash::add('error', 'クライアントIPアドレスを特定できませんでした。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }
        // global rate limit per IP
        $globalKey = "pwd_reset_ip:{$ip}";
        if (!RateLimiter::allow($globalKey, 20, 60 * 5)) { // 20 per 5 minutes
            Flash::add('error', 'しばらく待ってから再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', '有効なメールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        // per IP + email limit to avoid targeted email DoS while keeping enumeration resistance
        $ipEmailKey = "pwd_reset_ip_email:{$ip}:{$emailLower}";
        if (!RateLimiter::allow($ipEmailKey, 3, 60 * 5)) { // 3 per 5 minutes per IP/email
            Flash::add('error', 'しばらく待ってから再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        try {
            $result = $this->getResetService()->requestReset($emailLower);
        } catch (\Throwable $e) {
            Flash::add('error', 'リセット処理中にエラーが発生しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        Flash::add('info', 'パスワードリセット手順をメールに送信しました。届かない場合は時間をおいて再度お試しください。');
        return $response->withStatus(303)->withHeader('Location', '/password/reset');
    }

    private function getResetService(): PasswordResetService
    {
        if ($this->resetService === null) {
            $this->resetService = new PasswordResetService();
        }
        return $this->resetService;
    }
}
