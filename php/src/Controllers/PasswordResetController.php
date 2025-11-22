<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordResetController
{
    public function __construct(private View $view)
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

        $ip = $this->resolveClientIp($request);
        // global rate limit per IP
        $globalKey = "pwd_reset_ip:{$ip}";
        if (!\Attendly\Support\RateLimiter::allow($globalKey, 20, 60 * 5)) { // 20 per 5 minutes
            Flash::add('error', 'しばらく待ってから再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', '有効なメールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        // per-email rate limit
        $emailKey = "pwd_reset_email:{$emailLower}";
        if (!\Attendly\Support\RateLimiter::allow($emailKey, 5, 60 * 5)) { // 5 per 5 minutes per email
            Flash::add('error', 'しばらく待ってから再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        Flash::add('info', 'パスワードリセットはまだ移行中です。後続実装でメール送信を追加します。');
        return $response->withStatus(303)->withHeader('Location', '/password/reset');
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $trusted = filter_var($_ENV['TRUST_PROXY'], FILTER_VALIDATE_BOOL);
        if ($trusted && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', (string)$server['HTTP_X_FORWARDED_FOR']));
            if (!empty($parts[0])) {
                return $parts[0];
            }
        }
        if (!empty($server['REMOTE_ADDR'])) {
            return $server['REMOTE_ADDR'];
        }
        throw new \RuntimeException('Unable to resolve client IP address');
    }
}
