<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Attendly\Services\AuthService;
use RuntimeException;

final class AuthController
{
    public function __construct(private View $view, private ?AuthService $authService = null)
    {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $html = $this->view->renderWithLayout('login', [
            'title' => 'ログイン',
            'csrf' => CsrfToken::getToken(),
            'currentUser' => $request->getAttribute('currentUser'),
            'flashes' => Flash::consume(),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('error', '有効なメールアドレスを入力してください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }
        if ($password === '') {
            Flash::add('error', 'パスワードを入力してください。');
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
            SessionAuth::setUser([
                'id' => $result['user']['id'],
                'email' => $result['user']['email'],
            ]);
            Flash::add('success', 'ログインしました。');
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
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
        // セッションを全破棄（将来セッションハンドラ導入後に置き換え）
        SessionAuth::clear();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Flash::add('success', 'ログアウトしました。');
        return $response
            ->withStatus(303)
            ->withHeader('Location', '/login');
    }
}
