<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\Flash;
use Attendly\Support\SessionAuth;
use Attendly\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RegisterController
{
    private int $minPasswordLength;

    public function __construct(private View $view)
    {
        $this->minPasswordLength = (int)($_ENV['MIN_PASSWORD_LENGTH'] ?? 12);
        if ($this->minPasswordLength < 8) {
            $this->minPasswordLength = 8; // セキュリティ下限
        }
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $query = $request->getQueryParams();
        $roleCode = isset($query['roleCode']) ? (string)$query['roleCode'] : '';

        $html = $this->view->renderWithLayout('register', [
            'title' => '新規アカウント登録',
            'csrf' => CsrfToken::getToken(),
            'flashes' => Flash::consume(),
            'currentUser' => $request->getAttribute('currentUser'),
            'roleCodeValue' => $roleCode,
            'minPasswordLength' => $this->minPasswordLength,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $roleCode = trim((string)($data['roleCode'] ?? ''));
        $lastName = trim((string)($data['lastName'] ?? ''));
        $firstName = trim((string)($data['firstName'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $verificationCode = trim((string)($data['verificationCode'] ?? ''));
        $password = (string)($data['password'] ?? '');

        $errors = [];
        if ($roleCode === '' || !preg_match('/^[A-Za-z0-9]+$/', $roleCode)) {
            $errors[] = 'ロールコードを英数字で入力してください。';
        }
        if ($lastName === '' || $firstName === '') {
            $errors[] = '姓と名を入力してください。';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '有効なメールアドレスを入力してください。';
        }
        if ($verificationCode !== '' && !preg_match('/^[0-9]{6}$/', $verificationCode)) {
            $errors[] = '確認コードは6桁の数字で入力してください。';
        }
        if (strlen($password) < $this->minPasswordLength) {
            $errors[] = "パスワードは {$this->minPasswordLength} 文字以上で入力してください。";
        }

        if (!empty($errors)) {
            foreach ($errors as $err) {
                Flash::add('error', $err);
            }
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        Flash::add('info', '登録処理はまだ移植されていません。後続の実装で DB/MFA 連携を追加してください。');
        return $response->withStatus(303)->withHeader('Location', '/register');
    }
}
