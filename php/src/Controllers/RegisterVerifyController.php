<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\Flash;
use Attendly\Support\RateLimiter;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RegisterVerifyController
{
    public function __construct(private View $view)
    {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $query = $request->getQueryParams();
        $email = isset($query['email']) ? trim((string)$query['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', 'メール確認をもう一度やり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        $brand = $_ENV['APP_BRAND_NAME'] ?? 'Attendly';

        $html = $this->view->renderWithLayout('register_verify', [
            'title' => 'メール認証',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $brand,
            'email' => $email,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $token = trim((string)($data['token'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
            return $response->withStatus(303)->withHeader('Location', $location);
        }

        if (!preg_match('/^[0-9]{6}$/', $token)) {
            Flash::add('error', '確認コードは6桁の数字で入力してください。');
            $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
            return $response->withStatus(303)->withHeader('Location', $location);
        }
        Flash::add('info', 'メール認証は移行中です。後続実装でトークン検証を追加してください。');
        $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
        return $response->withStatus(303)->withHeader('Location', $location);
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $email = trim((string)($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
            return $response->withStatus(303)->withHeader('Location', $location);
        }

        if ($email === '') {
            Flash::add('error', '有効なメールアドレスが必要です。');
            $location = '/register/verify';
            return $response->withStatus(303)->withHeader('Location', $location);
        }

        // rate limit: 3 per 5 minutes per IP/email
        try {
            $ip = ClientIpResolver::resolve($request);
        } catch (\RuntimeException $e) {
            Flash::add('error', 'クライアントIPアドレスを特定できませんでした。時間をおいて再度お試しください。');
            $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
            return $response->withStatus(303)->withHeader('Location', $location);
        }
        $key = "register_verify_resend:{$ip}:{$email}";
        $allowed = RateLimiter::allow($key, 3, 60 * 5);
        if (!$allowed) {
            Flash::add('error', '再送回数の上限に達しました。暫くしてからお試しください。');
            $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
            return $response->withStatus(303)->withHeader('Location', $location);
        }

        Flash::add('info', '確認コード再送は移行中です。');
        $location = '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
        return $response->withStatus(303)->withHeader('Location', $location);
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            $location = '/register/verify';
            return $response->withStatus(303)->withHeader('Location', $location);
        }
        Flash::add('info', '登録をやり直してください。');
        return $response->withStatus(303)->withHeader('Location', '/register');
    }
}
