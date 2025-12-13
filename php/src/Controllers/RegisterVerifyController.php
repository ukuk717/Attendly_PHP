<?php

declare(strict_types=1);

namespace Attendly\Controllers;

use Attendly\Security\CsrfToken;
use Attendly\Support\ClientIpResolver;
use Attendly\Support\SessionAuth;
use Attendly\Support\Flash;
use Attendly\Support\RateLimiter;
use Attendly\View;
use Attendly\Database\Repository;
use Attendly\Services\EmailOtpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RegisterVerifyController
{
    private int $emailOtpLength;

    public function __construct(private View $view, private ?Repository $repository = null, private ?EmailOtpService $emailOtpService = null)
    {
        $this->repository = $this->repository ?? new Repository();
        $this->emailOtpService = $this->emailOtpService ?? new EmailOtpService($this->repository);
        $rawOtpLength = $_ENV['EMAIL_OTP_LENGTH'] ?? 6;
        $this->emailOtpLength = filter_var($rawOtpLength, FILTER_VALIDATE_INT) ?: 6;
        $this->emailOtpLength = max(4, min(10, $this->emailOtpLength));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (SessionAuth::getUser() !== null) {
            return $response->withStatus(303)->withHeader('Location', '/dashboard');
        }

        $query = $request->getQueryParams();
        $email = $this->normalizeEmail($query['email'] ?? null);
        if ($email === '') {
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
            'emailOtpLength' => $this->emailOtpLength,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $token = trim((string)($data['token'] ?? ''));
        $email = $this->normalizeEmail($data['email'] ?? null);
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        try {
            $ip = ClientIpResolver::resolve($request);
        } catch (\RuntimeException $e) {
            Flash::add('error', 'クライアントIPアドレスを特定できませんでした。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }
        if (!RateLimiter::allow("register_verify_ip:{$ip}", 30, 300)) {
            Flash::add('error', '試行回数が多すぎます。しばらく待ってから再度お試しください。');
            return $response->withStatus(429)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        if ($email === '') {
            Flash::add('error', 'メール確認をもう一度やり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        if (!RateLimiter::allow("register_verify_ip_email:{$ip}:{$email}", 10, 300)) {
            Flash::add('error', '試行回数が多すぎます。しばらく待ってから再度お試しください。');
            return $response->withStatus(429)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        if (!preg_match('/^[0-9]{' . $this->emailOtpLength . '}$/', $token)) {
            Flash::add('error', "確認コードは{$this->emailOtpLength}桁の数字で入力してください。");
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        $user = $this->repository->findUserByEmail($email);
        if ($user === null) {
            Flash::add('error', '登録情報が見つかりません。最初からやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        if ($user['status'] === 'active') {
            Flash::add('info', '既に認証済みです。ログインしてください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $result = $this->emailOtpService->verify('employee_register', (int)$user['id'], $email, $token);
        if (!$result['ok']) {
            $reason = $result['reason'] ?? 'invalid';
            if ($reason === 'locked' && isset($result['retry_at']) && $result['retry_at'] instanceof \DateTimeImmutable) {
                Flash::add('error', '試行回数の上限に達しました。時間をおいて再度お試しください。');
                return $response->withStatus(429)->withHeader('Location', $this->buildVerifyLocation($email));
            }
            if ($reason === 'expired') {
                Flash::add('error', '確認コードの有効期限が切れています。再送してください。');
            } else {
                Flash::add('error', '確認コードが一致しません。再度入力してください。');
            }
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        $pdo = $this->repository->getPdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $this->repository->updateUserStatus((int)$user['id'], 'active');
            $latestChallenge = $this->repository->findEmailOtpRequest([
                'user_id' => (int)$user['id'],
                'purpose' => 'employee_register',
                'target_email' => $email,
            ]);
            if ($latestChallenge !== null && $latestChallenge['role_code_id'] !== null) {
                $roleCodeId = (int)$latestChallenge['role_code_id'];
                $this->repository->incrementRoleCodeWithLimit($roleCodeId);
            }
            $this->repository->deleteEmailOtpRequests(['user_id' => (int)$user['id'], 'purpose' => 'employee_register']);
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::add('error', '確認処理中にエラーが発生しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }
        unset($_SESSION['pending_registration']);

        Flash::add('success', 'メール確認が完了しました。ログインしてください。');
        return $response->withStatus(303)->withHeader('Location', '/login');
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        $email = $this->normalizeEmail($data['email'] ?? null);
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        if ($email === '') {
            Flash::add('error', '有効なメールアドレスが必要です。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }

        try {
            $ip = ClientIpResolver::resolve($request);
        } catch (\RuntimeException $e) {
            Flash::add('error', 'クライアントIPアドレスを特定できませんでした。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }
        if (!RateLimiter::allow("register_verify_resend_ip:{$ip}", 10, 300)) {
            Flash::add('error', '再送回数の上限に達しました。暫くしてからお試しください。');
            return $response->withStatus(429)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        $key = "register_verify_resend:{$ip}:{$email}";
        if (!RateLimiter::allow($key, 3, 300)) {
            Flash::add('error', '再送回数の上限に達しました。暫くしてからお試しください。');
            return $response->withStatus(429)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        $user = $this->repository->findUserByEmail($email);
        if ($user === null) {
            Flash::add('error', '登録情報が見つかりません。最初からやり直してください。');
            return $response->withStatus(303)->withHeader('Location', '/register');
        }
        if ($user['status'] === 'active') {
            Flash::add('info', '既に認証済みです。ログインしてください。');
            return $response->withStatus(303)->withHeader('Location', '/login');
        }

        $latestChallenge = $this->repository->findEmailOtpRequest([
            'user_id' => (int)$user['id'],
            'purpose' => 'employee_register',
            'target_email' => $email,
        ]);
        $roleCodeId = $latestChallenge['role_code_id'] ?? ($_SESSION['pending_registration']['role_code_id'] ?? null);
        $tenantId = $latestChallenge['tenant_id'] ?? null;

        try {
            $this->emailOtpService->issue('employee_register', (int)$user['id'], $email, $tenantId, $roleCodeId ? (int)$roleCodeId : null);
        } catch (\Throwable $e) {
            Flash::add('error', '確認コードの再送に失敗しました。時間をおいて再度お試しください。');
            return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
        }

        $_SESSION['pending_registration'] = [
            'user_id' => (int)$user['id'],
            'email' => $email,
            'role_code_id' => $roleCodeId,
        ];
        Flash::add('info', '確認コードを再送しました。メールをご確認ください。');
        return $response->withStatus(303)->withHeader('Location', $this->buildVerifyLocation($email));
    }

    public function cancel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array)$request->getParsedBody();
        if (empty($data['csrf_token']) || !hash_equals(CsrfToken::getToken(), (string)$data['csrf_token'])) {
            Flash::add('error', 'CSRFトークンが無効です。');
            $location = '/register/verify';
            return $response->withStatus(303)->withHeader('Location', $location);
        }
        if (!empty($_SESSION['pending_registration']['user_id'])) {
            $this->repository->deleteEmailOtpRequests([
                'user_id' => (int)$_SESSION['pending_registration']['user_id'],
                'purpose' => 'employee_register',
            ]);
        }
        unset($_SESSION['pending_registration']);
        Flash::add('info', '登録をやり直してください。');
        return $response->withStatus(303)->withHeader('Location', '/register');
    }

    private function normalizeEmail(mixed $value): string
    {
        $email = trim((string)$value);
        $email = strtolower($email);
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }

    private function buildVerifyLocation(string $email): string
    {
        return '/register/verify' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
    }
}
