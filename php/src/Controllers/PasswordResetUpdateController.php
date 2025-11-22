<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\PasswordPolicy;
use Attendly\Support\SessionAuth;
use Attendly\Services\PasswordResetService;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordResetUpdateController
{
    private PasswordPolicy $policy;

    public function __construct(private View $view, private ?PasswordResetService $resetService = null)
    {
        $rawMin = $_ENV['MIN_PASSWORD_LENGTH'] ?? 12;
        $minLength = filter_var($rawMin, FILTER_VALIDATE_INT, ['options' => ['min_range' => 8]]) ?: 12;
        $this->policy = new PasswordPolicy($minLength);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $token = $this->sanitizeToken($args['token'] ?? '');
        if ($token === null) {
            Flash::add('error', '無効なトークンです。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        $status = $this->getResetService()->validateTokenUsable($token);
        if (!$status['ok']) {
            Flash::add('error', 'このリンクは無効または期限切れです。再度パスワードリセットをリクエストしてください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        $html = $this->view->renderWithLayout('password_reset_update', [
            'title' => 'パスワード再設定',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'brandName' => $_ENV['APP_BRAND_NAME'] ?? 'Attendly',
            'token' => $token,
            'minPasswordLength' => $this->policy->getMinLength(),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->sanitizeToken($args['token'] ?? '');
        if ($token === null) {
            Flash::add('error', '無効なトークンです。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }
        $data = (array)$request->getParsedBody();
        $password = (string)($data['password'] ?? '');

        $result = $this->policy->validate($password);
        if (!$result['ok']) {
            foreach ($result['errors'] as $err) {
                Flash::add('error', $err);
            }
            $safeLocation = '/password/reset/' . rawurlencode($token);
            return $response->withStatus(303)->withHeader('Location', $safeLocation);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            Flash::add('error', 'パスワードの処理中にエラーが発生しました。');
            $safeLocation = '/password/reset/' . rawurlencode($token);
            return $response->withStatus(303)->withHeader('Location', $safeLocation);
        }
        try {
            $result = $this->getResetService()->consumeAndResetPassword($token, $hash);
        } catch (\Throwable $e) {
            Flash::add('error', 'パスワード更新中にエラーが発生しました。再度お試しください。');
            $safeLocation = '/password/reset/' . rawurlencode($token);
            return $response->withStatus(303)->withHeader('Location', $safeLocation);
        }

        if (!$result['ok']) {
            Flash::add('error', 'このリンクは無効または期限切れです。再度パスワードリセットをリクエストしてください。');
            return $response->withStatus(303)->withHeader('Location', '/password/reset');
        }

        Flash::add('success', 'パスワードを更新しました。新しいパスワードでログインしてください。');
        return $response->withStatus(303)->withHeader('Location', '/login');
    }

    private function sanitizeToken(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $token = trim($token);
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            return null;
        }
        // Allow hex/uuid-like tokens; adjust pattern as needed for actual implementation.
        if (!preg_match('/^[A-Za-z0-9-_]+$/', $token)) {
            return null;
        }
        return $token;
    }

    private function getResetService(): PasswordResetService
    {
        if ($this->resetService === null) {
            $this->resetService = new PasswordResetService();
        }
        return $this->resetService;
    }
}
